<?php
// sse_bills.php — Server-Sent Events endpoint for live bill updates
//
// Keeps an HTTP connection open and pushes new bills to the client
// as soon as they appear in bu000. Replaces 5-second AJAX polling.

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');   // For nginx in front of Apache; harmless otherwise
header('Connection: keep-alive');

// Kill every output buffer — SSE requires immediate flushing
while (ob_get_level() > 0) ob_end_clean();
ob_implicit_flush(true);

// Let the script run forever; we detect disconnects manually
ignore_user_abort(true);
set_time_limit(0);

// Prevent PHP from holding a session lock for the whole connection
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

require_once 'config.php';

// ─── Read and validate params ─────────────────────────────────────
$lastDate   = $_GET['lastDate']  ?? null;
$fCustomer  = (isset($_GET['customer']) && $_GET['customer'] !== '') ? $_GET['customer'] : null;
$fSalesman  = (isset($_GET['salesman']) && $_GET['salesman'] !== '') ? $_GET['salesman'] : null;
$fDateFrom  = (isset($_GET['dateFrom']) && $_GET['dateFrom'] !== '') ? $_GET['dateFrom'] : null;
$fDateTo    = (isset($_GET['dateTo'])   && $_GET['dateTo']   !== '') ? $_GET['dateTo']   : null;

// ─── Build filter clause once (it never changes during the connection) ──
$filterSql    = [];
$filterParams = [];
if ($fCustomer !== null) { $filterSql[] = "b.Cust_Name = :fCustomer";   $filterParams[':fCustomer'] = $fCustomer; }
if ($fSalesman !== null) { $filterSql[] = "b.StoreGUID = :fSalesman";   $filterParams[':fSalesman'] = $fSalesman; }
if ($fDateFrom !== null) { $filterSql[] = "b.Date >= :fDateFrom";       $filterParams[':fDateFrom'] = $fDateFrom . ' 00:00:00'; }
if ($fDateTo   !== null) { $filterSql[] = "b.Date < DATEADD(day, 1, :fDateTo)"; $filterParams[':fDateTo'] = $fDateTo . ' 00:00:00'; }
$filterWhere = count($filterSql) ? (' AND ' . implode(' AND ', $filterSql)) : '';

// ─── Normalize the starting timestamp ─────────────────────────────
// We use >= (not >) so bills sharing the same millisecond as the last
// known one are not lost. The client dedupes by GUID, so no duplicates
// will be shown.
if ($lastDate) {
    try {
        $lastDateSql = (new DateTime($lastDate))->format('Y-m-d H:i:s.v');
    } catch (Exception $e) {
        $lastDateSql = null;
    }
} else {
    $lastDateSql = null;
}

if ($lastDateSql === null) {
    // First connection: start from "now" so we don't dump the whole history
    $lastDateSql = (new DateTime())->format('Y-m-d H:i:s.v');
}

// ─── Connect ──────────────────────────────────────────────────────
try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    echo "event: error\n";
    echo 'data: ' . json_encode(['error' => $e->getMessage()]) . "\n\n";
    flush();
    exit;
}

$sql = "SELECT
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
            b.CreateDate
        FROM bu000 b
        LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
        LEFT JOIN st000 s   ON b.StoreGUID   = s.GUID
        LEFT JOIN co000 cc  ON b.CostGUID    = cc.GUID
        WHERE b.CreateDate >= :lastDate" . $filterWhere . "
        ORDER BY b.CreateDate ASC";

$stmt = $pdo->prepare($sql);

// Tell the client we're live
echo ": connected\n\n";
flush();

$lastHeartbeat = time();

while (true) {
    if (connection_aborted()) break;

    try {
        $params = array_merge([':lastDate' => $lastDateSql], $filterParams);
        $stmt->execute($params);
        $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($bills) > 0) {
            $newMax = $lastDateSql;
            foreach ($bills as &$b) {
                $b['Date']       = (new DateTime($b['Date']))->format('c');
                $b['CreateDate'] = (new DateTime($b['CreateDate']))->format('c');
                if ($b['CreateDate'] > $newMax) $newMax = $b['CreateDate'];
            }
            unset($b);

            // Advance our watermark
            $lastDateSql = (new DateTime($newMax))->format('Y-m-d H:i:s.v');

            echo "event: bills\n";
            echo 'data: ' . json_encode(
                $bills,
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ) . "\n\n";
            flush();
            $lastHeartbeat = time();
        } else {
            // Keep the connection alive through proxies (every 15 s)
            if (time() - $lastHeartbeat >= 15) {
                echo ": heartbeat\n\n";
                flush();
                $lastHeartbeat = time();
            }
        }
    } catch (Exception $e) {
        echo "event: error\n";
        echo 'data: ' . json_encode(['error' => $e->getMessage()]) . "\n\n";
        flush();
        break;
    }

    // Sleep 2 seconds, but wake immediately if the client disconnects
    for ($i = 0; $i < 20; $i++) {
        usleep(100000); // 100 ms
        if (connection_aborted()) break 2;
    }
}