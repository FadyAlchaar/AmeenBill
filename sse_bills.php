<?php
// sse_bills.php — Server-Sent Events for live bill inserts AND edits.
//
// Watermarks:
//   - CreateDate      → detects new bills
//   - LastUpdateDate  → detects edits (Alameen writes this on save)
//
// Probe is two MAX() aggregates (one index seek when CreateDate is indexed;
// a table scan otherwise, which we've measured at ~9 ms). The full join is
// only executed when a watermark actually moved.

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

while (ob_get_level() > 0) ob_end_clean();
ob_implicit_flush(true);

ignore_user_abort(true);
set_time_limit(0);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

require_once 'config.php';

// ─── Constants ───────────────────────────────────────────────────
const SQL_SENTINEL = '1980-01-01 00:00:00.000';

// ─── Read params ─────────────────────────────────────────────────
$lastCreate = isset($_GET['lastCreate']) ? $_GET['lastCreate'] : null;
$lastUpdate = isset($_GET['lastUpdate']) ? $_GET['lastUpdate'] : null;

$fCustomer = (isset($_GET['customer']) && $_GET['customer'] !== '') ? $_GET['customer'] : null;
$fSalesman = (isset($_GET['salesman']) && $_GET['salesman'] !== '') ? $_GET['salesman'] : null;
$fDateFrom = (isset($_GET['dateFrom']) && $_GET['dateFrom'] !== '') ? $_GET['dateFrom'] : null;
$fDateTo   = (isset($_GET['dateTo'])   && $_GET['dateTo']   !== '') ? $_GET['dateTo']   : null;

// ─── Filter fragment ─────────────────────────────────────────────
$filterSql    = [];
$filterParams = [];
if ($fCustomer !== null) { $filterSql[] = "b.Cust_Name = :fCustomer";  $filterParams[':fCustomer'] = $fCustomer; }
if ($fSalesman !== null) { $filterSql[] = "b.StoreGUID = :fSalesman";  $filterParams[':fSalesman'] = $fSalesman; }
if ($fDateFrom !== null) { $filterSql[] = "b.Date >= :fDateFrom";      $filterParams[':fDateFrom'] = $fDateFrom . ' 00:00:00'; }
if ($fDateTo   !== null) { $filterSql[] = "b.Date < DATEADD(day, 1, :fDateTo)"; $filterParams[':fDateTo'] = $fDateTo . ' 00:00:00'; }
$filterWhere = count($filterSql) ? (' AND ' . implode(' AND ', $filterSql)) : '';

// ─── Normalize incoming watermarks ───────────────────────────────
function normalizeWatermark($iso, $fallback) {
    if (!$iso) return $fallback;
    try {
        return (new DateTime($iso))->format('Y-m-d H:i:s.v');
    } catch (Exception $e) {
        return $fallback;
    }
}

$nowSql = (new DateTime())->format('Y-m-d H:i:s.v');
$lastCreateSql = normalizeWatermark($lastCreate, $nowSql);
$lastUpdateSql = normalizeWatermark($lastUpdate, $nowSql);

// ─── Connect ─────────────────────────────────────────────────────
try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    echo "event: error\ndata: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
    flush();
    exit;
}

// ─── Probe: both watermarks in one round-trip ────────────────────
$probeStmt = $pdo->prepare(
    "SELECT
        MAX(CreateDate)     AS maxCreate,
        MAX(LastUpdateDate) AS maxUpdate
     FROM bu000"
);

// ─── Fetch: bills matching either watermark ──────────────────────
$fetchStmt = $pdo->prepare(
    "SELECT TOP 500
        b.GUID,
        b.Number,
        b.Cust_Name,
        b.Date,
        b.PayType,
        b.Total,
        b.TotalDisc,
        b.TotalExtra,
        b.CurrencyVal,
        cur.Name AS CurrencyName,
        s.Name   AS StoreName,
        cc.Name  AS CostCenterName,
        b.CreateDate,
        b.LastUpdateDate
     FROM bu000 b
     LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
     LEFT JOIN st000 s   ON b.StoreGUID   = s.GUID
     LEFT JOIN co000 cc  ON b.CostGUID    = cc.GUID
     WHERE (b.CreateDate > :lastCreate OR b.LastUpdateDate > :lastUpdate)"
     . $filterWhere . "
     ORDER BY b.CreateDate ASC"
);

echo ": connected\n\n";
flush();

$lastHeartbeat = time();

// Track (GUID => LastUpdateDate-or-'') for every bill we've pushed.
// This makes re-emitting the same bill impossible, regardless of what
// happens with timestamp string comparisons.
$sentState = [];
const SENT_STATE_MAX = 5000;

while (true) {
    if (connection_aborted()) break;

    try {
        // 1. Cheap probe
                $probeStmt->execute();
        $row = $probeStmt->fetch(PDO::FETCH_ASSOC);

        $maxCreate = ($row && $row['maxCreate'])
            ? (new DateTime($row['maxCreate']))->format('Y-m-d H:i:s.v')
            : SQL_SENTINEL;
        $maxUpdate = ($row && $row['maxUpdate'])
            ? (new DateTime($row['maxUpdate']))->format('Y-m-d H:i:s.v')
            : SQL_SENTINEL;

        $hasNew    = strcmp($maxCreate, $lastCreateSql) > 0;
        $hasUpdate = strcmp($maxUpdate, $lastUpdateSql) > 0
                     && strcmp($maxUpdate, SQL_SENTINEL) > 0;

        // 2. Full fetch only when a watermark moved
        if ($hasNew || $hasUpdate) {
            $params = array_merge([
                ':lastCreate' => $lastCreateSql,
                ':lastUpdate' => $lastUpdateSql,
            ], $filterParams);

            $fetchStmt->execute($params);
            $bills = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($bills) > 0) {
                $newCreateMax = $lastCreateSql;
                $newUpdateMax = $lastUpdateSql;
                $toSend = [];

                foreach ($bills as $b) {
                    $rawCreate = $b['CreateDate']
                        ? (new DateTime($b['CreateDate']))->format('Y-m-d H:i:s.v')
                        : null;
                    $rawUpdate = $b['LastUpdateDate']
                        ? (new DateTime($b['LastUpdateDate']))->format('Y-m-d H:i:s.v')
                        : null;

                    // Advance watermarks
                    if ($rawCreate && strcmp($rawCreate, $newCreateMax) > 0) {
                        $newCreateMax = $rawCreate;
                    }
                    $hasRealUpdate = $rawUpdate && strcmp($rawUpdate, SQL_SENTINEL) > 0;
                    if ($hasRealUpdate && strcmp($rawUpdate, $newUpdateMax) > 0) {
                        $newUpdateMax = $rawUpdate;
                    }

                    // Dedup: state key = LastUpdateDate string (or '' if never edited)
                    $stateKey = $hasRealUpdate ? $rawUpdate : '';
                    $guid     = $b['GUID'];

                    $isDuplicate = isset($sentState[$guid]) && $sentState[$guid] === $stateKey;

                    // Record the new state regardless, so the next tick sees it as duplicate
                    $sentState[$guid] = $stateKey;

                    if ($isDuplicate) {
                        continue;   // already pushed — don't re-emit
                    }

                    // Convert to ISO for the wire
                    $b['Date']       = (new DateTime($b['Date']))->format('c');
                    $b['CreateDate'] = $rawCreate
                        ? (new DateTime($rawCreate))->format('c')
                        : null;
                    $b['LastUpdateDate'] = $hasRealUpdate
                        ? (new DateTime($rawUpdate))->format('c')
                        : null;

                    $toSend[] = $b;
                }

                // Cap memory: drop oldest entries if needed
                if (count($sentState) > SENT_STATE_MAX) {
                    $sentState = array_slice($sentState, -SENT_STATE_MAX, null, true);
                }

                if (!empty($toSend)) {
                    echo "event: bills\n";
                    echo 'data: ' . json_encode(
                        $toSend,
                        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                    ) . "\n\n";
                    flush();
                    $lastHeartbeat = time();
                }

                // Always advance watermarks, even if everything was a duplicate
                $lastCreateSql = $newCreateMax;
                $lastUpdateSql = $newUpdateMax;
            } else {
                // Filter matched nothing — jump past the global max so we don't loop
                $lastCreateSql = $maxCreate;
                if (strcmp($maxUpdate, SQL_SENTINEL) > 0) {
                    $lastUpdateSql = $maxUpdate;
                }
            }
        }

        // 3. Heartbeat every 15 s
        if (time() - $lastHeartbeat >= 15) {
            echo ": heartbeat\n\n";
            flush();
            $lastHeartbeat = time();
        }
    } catch (Exception $e) {
        echo "event: error\ndata: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
        flush();
        break;
    }

    // 4. Abort-aware sleep (2 s)
    for ($i = 0; $i < 20; $i++) {
        usleep(100000);
        if (connection_aborted()) break 2;
    }
}