<?php
// dashboard_full.php - with draggable splitter between bills and details
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once 'config.php';

// AJAX handler to populate filter dropdowns (Customer, Salesman)
if (isset($_GET['filters']) && $_GET['filters'] == 1) {
    header('Content-Type: application/json');
    try {
        $pdo = getDBConnection();

        $custStmt = $pdo->query("
            SELECT DISTINCT Cust_Name
            FROM bu000
            WHERE Cust_Name IS NOT NULL AND LTRIM(RTRIM(Cust_Name)) <> ''
            ORDER BY Cust_Name
        ");
        $customers = $custStmt->fetchAll(PDO::FETCH_COLUMN);

        $salesStmt = $pdo->query("
            SELECT DISTINCT
                ds.stGuid AS GUID,
                ds.Name AS DisplayName
            FROM bu000 b
            JOIN DistDeviceST000 ds ON b.StoreGUID = ds.stGuid
            WHERE ds.Name IS NOT NULL AND LTRIM(RTRIM(ds.Name)) <> ''
            ORDER BY DisplayName
        ");
        $salesmen = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['customers' => $customers, 'salesmen' => $salesmen]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// AJAX handler for bills list
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    $lastDate = isset($_GET['lastDate']) ? $_GET['lastDate'] : null;
    $loadMore = isset($_GET['loadMore']) ? (int)$_GET['loadMore'] : 0;

    // ---- Filter inputs ----
    $fCustomer = isset($_GET['customer']) && $_GET['customer'] !== '' ? $_GET['customer'] : null;
    $fSalesman = isset($_GET['salesman']) && $_GET['salesman'] !== '' ? $_GET['salesman'] : null;
    $fDateFrom = isset($_GET['dateFrom']) && $_GET['dateFrom'] !== '' ? $_GET['dateFrom'] : null;
    $fDateTo   = isset($_GET['dateTo']) && $_GET['dateTo'] !== '' ? $_GET['dateTo'] : null;

    // Build reusable WHERE fragments + params for the filters
    $filterSql = [];
    $filterParams = [];
    if ($fCustomer !== null) {
        $filterSql[] = "b.Cust_Name = :fCustomer";
        $filterParams[':fCustomer'] = $fCustomer;
    }
    if ($fSalesman !== null) {
        $filterSql[] = "b.StoreGUID = :fSalesman";
        $filterParams[':fSalesman'] = $fSalesman;
    }
    if ($fDateFrom !== null) {
        $filterSql[] = "b.Date >= :fDateFrom";
        $filterParams[':fDateFrom'] = $fDateFrom . ' 00:00:00';
    }
    if ($fDateTo !== null) {
        $filterSql[] = "b.Date < DATEADD(day, 1, :fDateTo)";
        $filterParams[':fDateTo'] = $fDateTo . ' 00:00:00';
    }

    try {
        $pdo = getDBConnection();
        if ($lastDate && !$loadMore) {
            $dt = new DateTime($lastDate);
            $sqlDate = $dt->format('Y-m-d H:i:s');

            $where = ["b.CreateDate > :lastDate"];
            $where = array_merge($where, $filterSql);
            $whereSql = implode(' AND ', $where);

            $sql = "SELECT 
                        b.GUID,
                        b.Number,
                        b.Cust_Name,
                        b.Date,
                        b.PayType,
                        cur.Name AS CurrencyName,
                        s.Name AS StoreName,
                        cc.Name AS CostCenterName,
                        b.CreateDate
                    FROM bu000 b
                    LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
                    LEFT JOIN st000 s ON b.StoreGUID = s.GUID
                    LEFT JOIN co000 cc ON b.CostGUID = cc.GUID
                    WHERE $whereSql
                    ORDER BY b.CreateDate ASC";
            $stmt = $pdo->prepare($sql);
            $params = array_merge([':lastDate' => $sqlDate], $filterParams);
            $stmt->execute($params);
        } else {
            $limit = 200;
            $offset = $loadMore * $limit;

            $whereSql = count($filterSql) ? ('WHERE ' . implode(' AND ', $filterSql)) : '';

            $sql = "SELECT 
                        b.GUID,
                        b.Number,
                        b.Cust_Name,
                        b.Date,
                        b.PayType,
                        cur.Name AS CurrencyName,
                        s.Name AS StoreName,
                        cc.Name AS CostCenterName,
                        b.CreateDate
                    FROM bu000 b
                    LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
                    LEFT JOIN st000 s ON b.StoreGUID = s.GUID
                    LEFT JOIN co000 cc ON b.CostGUID = cc.GUID
                    $whereSql
                    ORDER BY b.CreateDate DESC
                    OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";
            $stmt = $pdo->prepare($sql);
            foreach ($filterParams as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        }
        $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($bills as &$bill) {
            $bill['Date'] = (new DateTime($bill['Date']))->format('c');
            $bill['CreateDate'] = (new DateTime($bill['CreateDate']))->format('c');
        }
        echo json_encode($bills);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// AJAX handler for bill details
if (isset($_GET['details']) && $_GET['details'] == 1 && isset($_GET['guid'])) {
    header('Content-Type: application/json');
    $guid = $_GET['guid'];
    try {
        $pdo = getDBConnection();
        $sql = "SELECT 
                    m.Name AS ItemName,
                    d.Qty,
                    d.Price AS UnitPrice,
                    (d.Qty * d.Price) AS Total,
                    d.Extra,
                    d.Unity AS Unit,
                    d.Discount AS DiscountPercent,
                    (d.Qty * d.Price * d.Discount / 100) AS DiscountValue
                FROM bi000 d
                LEFT JOIN mt000 m ON d.MatGUID = m.GUID
                WHERE d.ParentGUID = :guid
                ORDER BY d.GUID";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':guid' => $guid]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($details);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>لوحة المبيعات - تحديث مباشر</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --accent: #06b6d4;
            --success: #10b981;
            --danger: #ef4444;
            --bg: #f4f5fa;
            --surface: #ffffff;
            --text: #1e1b2e;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --radius: 14px;
            --shadow-sm: 0 1px 2px rgba(16,24,40,0.06), 0 1px 3px rgba(16,24,40,0.08);
            --shadow-md: 0 4px 10px rgba(16,24,40,0.06), 0 2px 4px rgba(16,24,40,0.06);
        }
        html, body {
            height: 100%;
        }
        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            margin: 0;
            padding: 16px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #c7cbe0; border-radius: 8px; }
        ::-webkit-scrollbar-thumb:hover { background: #a5abcf; }
        .dashboard {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            height: 100%;
            width: 100%;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        h1 {
            color: var(--text);
            font-size: 1.4rem;
            font-weight: 900;
            margin: 0;
            text-align: right;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .live-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
        }
        .live-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 0 0 rgba(16,185,129,0.6);
            animation: livePulse 1.8s ease-out infinite;
        }
        @keyframes livePulse {
            0%   { box-shadow: 0 0 0 0 rgba(16,185,129,0.55); }
            70%  { box-shadow: 0 0 0 7px rgba(16,185,129,0); }
            100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
        }
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filters-toggle-btn {
            display: none; /* shown only on phones, via media query */
            align-items: center;
            gap: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--primary);
            font-family: inherit;
            cursor: pointer;
        }
        .filters-toggle-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .filters-toggle-badge {
            background: var(--primary);
            color: white;
            font-size: 0.65rem;
            font-weight: 800;
            border-radius: 999px;
            padding: 1px 6px;
            display: none;
        }
        .filters-toggle-btn.active .filters-toggle-badge { background: rgba(255,255,255,0.25); }
        /* Filter bar */
        .filters-bar {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            padding: 12px 16px;
            margin-bottom: 14px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            flex-shrink: 0;
        }
        .filters-bar label {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 700;
            margin-left: 2px;
        }
        .filters-bar input[type="date"],
        .ss-input {
            padding: 7px 10px;
            border: 1px solid var(--border);
            border-radius: 9px;
            font-size: 0.8rem;
            font-family: inherit;
            background: var(--bg);
            color: var(--text);
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .filters-bar input[type="date"]:focus,
        .ss-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .filter-divider {
            width: 1px;
            align-self: stretch;
            background: var(--border);
            margin: 2px 0;
        }
        /* Searchable dropdown */
        .ss-wrap {
            position: relative;
            width: 170px;
        }
        .ss-input {
            width: 100%;
            cursor: pointer;
        }
        .ss-input::placeholder { color: var(--text-muted); opacity: 1; }
        .ss-panel {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            left: 0;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: var(--shadow-md);
            max-height: 260px;
            overflow-y: auto;
            z-index: 50;
            padding: 4px;
        }
        .ss-wrap.open .ss-panel { display: block; }
        .ss-option {
            padding: 8px 10px;
            border-radius: 7px;
            font-size: 0.8rem;
            cursor: pointer;
            text-align: right;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ss-option:hover { background: var(--primary-light); }
        .ss-option.selected { background: var(--primary); color: white; font-weight: 700; }
        .ss-option-all { color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--border); border-radius: 0; margin-bottom: 3px; padding-bottom: 8px; }
        .ss-option-all.selected { background: var(--primary); color: white; border-radius: 7px; }
        .ss-empty { padding: 10px; text-align: center; font-size: 0.75rem; color: var(--text-muted); }
        .preset-btn {
            padding: 7px 14px;
            border: 1px solid var(--border);
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            background: var(--bg);
            cursor: pointer;
            color: var(--text-muted);
            font-family: inherit;
            transition: all 0.15s;
        }
        .preset-btn:hover { border-color: var(--primary); color: var(--primary); }
        .preset-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .filters-bar .clear-btn {
            padding: 7px 14px;
            border-radius: 999px;
            font-size: 0.75rem;
            background: #fef2f2;
            color: var(--danger);
            border: 1px solid #fecaca;
            cursor: pointer;
            font-weight: 700;
            font-family: inherit;
            transition: all 0.15s;
            margin-right: auto;
        }
        .filters-bar .clear-btn:hover { background: #fee2e2; }
        /* Splitter container */
        .split-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        /* Bills section (upper) */
        .bills-container {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 100px;
        }
        .bills-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 12px 18px;
            font-weight: 700;
            font-size: 0.95rem;
            text-align: right;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .bill-count-pill {
            background: rgba(255,255,255,0.2);
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        #bills {
            overflow-y: auto;
            flex: 1;
        }
        .bill-card {
            background: var(--surface);
            border-right: 3px solid var(--primary);
            padding: 10px 16px;
            cursor: pointer;
            transition: background 0.15s, transform 0.1s;
            border-bottom: 1px solid #f1f2f7;
            text-align: right;
        }
        .bill-card:hover { background: #f8f8fd; }
        .bill-card:active { transform: scale(0.997); }
        .bill-card.active { background: var(--primary-light); border-right-color: var(--primary-dark); }
        .bill-card.new-flash {
            animation: newBillFlash 2.2s ease-out;
        }
        @keyframes newBillFlash {
            0%   { background: #d1fae5; }
            100% { background: var(--surface); }
        }
        .bill-title {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 8px 12px;
            margin-bottom: 5px;
            justify-content: flex-start;
        }
        .bill-number {
            background: var(--primary);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            font-family: inherit;
        }
        .bill-customer { font-weight: 700; font-size: 0.9rem; color: var(--text); }
        .bill-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            font-size: 0.7rem;
            color: var(--text-muted);
            justify-content: flex-start;
        }
        .bill-meta span {
            background: #f3f4f9;
            padding: 3px 8px;
            border-radius: 999px;
        }
        .load-more-btn {
            text-align: center;
            padding: 11px;
            background: #f8f8fd;
            cursor: pointer;
            color: var(--primary);
            font-weight: 700;
            font-size: 0.85rem;
            border-top: 1px solid var(--border);
            flex-shrink: 0;
            transition: background 0.15s;
        }
        .load-more-btn:hover {
            background: var(--primary-light);
        }

        /* Splitter handle */
        .splitter {
            background: var(--border);
            height: 6px;
            margin: 10px 0;
            cursor: row-resize;
            border-radius: 999px;
            transition: background 0.2s;
            flex-shrink: 0;
            position: relative;
        }
        .splitter::after {
            content: '';
            position: absolute;
            top: -6px; bottom: -6px; left: 0; right: 0;
        }
        .splitter:hover, .splitter.active {
            background: var(--primary);
        }

        /* Details section (lower) */
        .details-container {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 100px;
            flex: 1;
        }
        .details-header {
            background: linear-gradient(135deg, #334155 0%, #1e293b 100%);
            color: white;
            padding: 12px 18px;
            font-weight: 700;
            font-size: 0.95rem;
            text-align: right;
            flex-shrink: 0;
        }
        .table-wrapper {
            overflow-x: auto;
            flex: 1;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }
        th {
            background: #f6f7fb;
            color: #0f172a;
            font-weight: 700;
            padding: 10px 10px;
            border-bottom: 1px solid var(--border);
            text-align: right;
            white-space: nowrap;
            position: sticky;
            top: 0;
        }
        td {
            padding: 8px 10px;
            border-bottom: 1px solid #f1f2f7;
            text-align: right;
            color: var(--text);
        }
        tr:hover td { background: #fafaff; }
        td:first-child { font-weight: 600; }
        .loading, .error { padding: 24px; text-align: center; font-size: 0.85rem; }
        .error { color: var(--danger); background: #fef2f2; border-radius: 10px; margin: 10px; }

        /* ---------- Responsive ---------- */
        @media (max-width: 900px) {
            body { padding: 10px; }
            h1 { font-size: 1.15rem; }
            .filters-bar { padding: 10px; gap: 8px; }
            .filters-bar .clear-btn { margin-right: 0; }
        }
        @media (max-width: 600px) {
            body { padding: 8px; }
            .topbar { flex-wrap: wrap; gap: 8px; }
            h1 { font-size: 1rem; }
            .live-badge { font-size: 0.7rem; padding: 5px 10px; }
            .filters-toggle-btn { display: inline-flex; }
            .filters-bar {
                display: none;
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            .filters-bar.filters-open {
                display: flex;
                animation: filtersSlideDown 0.18s ease-out;
            }
            @keyframes filtersSlideDown {
                from { opacity: 0; transform: translateY(-6px); }
                to   { opacity: 1; transform: translateY(0); }
            }
            .filter-group { flex-wrap: wrap; }
            .filter-divider { display: none; }
            .filters-bar input[type="date"] {
                flex: 1;
                min-width: 0;
            }
            .ss-wrap { width: 100%; flex: 1; min-width: 0; }
            .filters-bar .clear-btn { width: 100%; margin-right: 0; text-align: center; }
            .bills-header, .details-header { padding: 10px 12px; font-size: 0.85rem; }
            .bill-card { padding: 9px 12px; }
            .bill-customer { font-size: 0.85rem; }
            .bill-meta span { font-size: 0.68rem; }
            th, td { padding: 7px 4px; font-size: 0.7rem; }
        }
        @media (max-width: 400px) {
            .filter-group { flex-direction: column; align-items: stretch; }
            .filters-bar label { margin: 0; }
        }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="topbar">
        <h1>📊 لوحة المبيعات</h1>
        <div class="topbar-right">
            <div class="live-badge"><span class="live-dot"></span> تحديث مباشر</div>
            <button type="button" class="filters-toggle-btn" id="filtersToggleBtn">
                ☰ الفلاتر <span class="filters-toggle-badge" id="filtersToggleBadge"></span>
            </button>
        </div>
    </div>

    <div class="filters-bar" id="filtersBar">
        <div class="filter-group">
            <label>الزبون</label>
            <div class="ss-wrap" id="customerWrap">
                <input type="text" class="ss-input" id="customerInput" placeholder="الكل" autocomplete="off">
                <div class="ss-panel" id="customerPanel"></div>
            </div>
        </div>
        <div class="filter-divider"></div>
        <div class="filter-group">
            <label>البائع</label>
            <div class="ss-wrap" id="salesmanWrap">
                <input type="text" class="ss-input" id="salesmanInput" placeholder="الكل" autocomplete="off">
                <div class="ss-panel" id="salesmanPanel"></div>
            </div>
        </div>
        <div class="filter-divider"></div>
        <div class="filter-group">
            <button type="button" class="preset-btn" data-preset="today">اليوم</button>
            <button type="button" class="preset-btn" data-preset="week">هذا الأسبوع</button>
            <button type="button" class="preset-btn" data-preset="month">هذا الشهر</button>
        </div>
        <div class="filter-divider"></div>
        <div class="filter-group">
            <label>من</label>
            <input type="date" id="filterDateFrom">
            <label>إلى</label>
            <input type="date" id="filterDateTo">
        </div>
        <button type="button" class="clear-btn" id="clearFiltersBtn">✖ مسح الفلاتر</button>
    </div>

    <div class="split-container">
        <!-- Bills section (top) -->
        <div class="bills-container" id="topSection">
            <div class="bills-header">
                <span>📄 الفواتير (اضغط لعرض التفاصيل)</span>
                <span class="bill-count-pill" id="billCountPill">0</span>
            </div>
            <div id="bills">جاري التحميل...</div>
            <div id="loadMoreBtn" class="load-more-btn" style="display: none;">➕ تحميل المزيد</div>
        </div>
        <!-- Splitter handle -->
        <div id="splitter" class="splitter"></div>
        <!-- Details section (bottom) -->
        <div class="details-container" id="bottomSection">
            <div class="details-header">🧾 تفاصيل الفاتورة</div>
            <div class="table-wrapper" id="detailsContainer">
                <div class="loading">اختر فاتورة لعرض الأصناف</div>
            </div>
        </div>
    </div>
</div>

<script>
    // ---------- Splitter logic (draggable) ----------
    const topSection = document.getElementById('topSection');
    const splitter = document.getElementById('splitter');
    const splitContainer = document.querySelector('.split-container');
    let isDragging = false;
    let startY = 0;
    let startTopHeight = 0;

    function minBottomSpace() {
        // On short/mobile viewports, reserve less room for the details panel
        return splitContainer.clientHeight < 500 ? 90 : 150;
    }
    function minTopHeight() {
        return splitContainer.clientHeight < 400 ? 60 : 80;
    }

    // Load saved height from localStorage
    const savedHeight = localStorage.getItem('dashboardTopHeight');
    const containerHeight = splitContainer.clientHeight;
    if (savedHeight && containerHeight > 0) {
        const clamped = Math.min(Math.max(parseFloat(savedHeight), minTopHeight()), containerHeight - minBottomSpace());
        topSection.style.height = clamped + 'px';
    } else {
        // Default: 50% of available space (minus splitter margin)
        topSection.style.height = Math.floor(containerHeight * 0.5) + 'px';
    }

    function onMouseMove(e) {
        if (!isDragging) return;
        const containerRect = splitContainer.getBoundingClientRect();
        const mouseY = e.clientY;
        let newTopHeight = mouseY - containerRect.top - (splitter.offsetHeight / 2);
        newTopHeight = Math.min(Math.max(newTopHeight, minTopHeight()), containerRect.height - minBottomSpace());
        topSection.style.height = newTopHeight + 'px';
        localStorage.setItem('dashboardTopHeight', newTopHeight);
    }

    function onMouseUp() {
        isDragging = false;
        document.body.style.userSelect = '';
        splitter.classList.remove('active');
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
    }

    splitter.addEventListener('mousedown', (e) => {
        isDragging = true;
        startY = e.clientY;
        startTopHeight = topSection.clientHeight;
        document.body.style.userSelect = 'none';
        splitter.classList.add('active');
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
        e.preventDefault();
    });

    // On window resize, re-apply saved percentage logic? We'll just keep absolute height.
    // But if the container shrinks too much, we reclamp on next drag.
    // Also allow touch events for tablets
    splitter.addEventListener('touchstart', (e) => {
        isDragging = true;
        startTopHeight = topSection.clientHeight;
        document.body.style.userSelect = 'none';
        splitter.classList.add('active');
        document.addEventListener('touchmove', onTouchMove, { passive: false });
        document.addEventListener('touchend', onTouchEnd);
        e.preventDefault();
    }, { passive: false });

    function onTouchMove(e) {
        if (!isDragging) return;
        e.preventDefault();
        const containerRect = splitContainer.getBoundingClientRect();
        const touchY = e.touches[0].clientY;
        let newTopHeight = touchY - containerRect.top - (splitter.offsetHeight / 2);
        newTopHeight = Math.min(Math.max(newTopHeight, minTopHeight()), containerRect.height - minBottomSpace());
        topSection.style.height = newTopHeight + 'px';
        localStorage.setItem('dashboardTopHeight', newTopHeight);
    }

    function onTouchEnd() {
        isDragging = false;
        document.body.style.userSelect = '';
        splitter.classList.remove('active');
        document.removeEventListener('touchmove', onTouchMove);
        document.removeEventListener('touchend', onTouchEnd);
    }

    // ---------- Filters ----------
    let activeFilters = { customer: '', salesman: '', dateFrom: '', dateTo: '' };

    function buildFilterQuery() {
        let qs = '';
        if (activeFilters.customer) qs += '&customer=' + encodeURIComponent(activeFilters.customer);
        if (activeFilters.salesman) qs += '&salesman=' + encodeURIComponent(activeFilters.salesman);
        if (activeFilters.dateFrom) qs += '&dateFrom=' + encodeURIComponent(activeFilters.dateFrom);
        if (activeFilters.dateTo) qs += '&dateTo=' + encodeURIComponent(activeFilters.dateTo);
        return qs;
    }

    // ---------- Searchable dropdown component ----------
    function makeSearchSelect(wrapId, inputId, panelId) {
        const wrap = document.getElementById(wrapId);
        const input = document.getElementById(inputId);
        const panel = document.getElementById(panelId);
        let items = [];       // [{value, label}]
        let selectedValue = '';
        let selectedLabel = '';
        let onChangeCb = null;

        function render(filterText) {
            panel.innerHTML = '';

            const allOpt = document.createElement('div');
            allOpt.className = 'ss-option ss-option-all' + (selectedValue === '' ? ' selected' : '');
            allOpt.textContent = 'الكل';
            allOpt.addEventListener('mousedown', (e) => { e.preventDefault(); select('', ''); });
            panel.appendChild(allOpt);

            const ft = (filterText || '').trim().toLowerCase();
            const filtered = ft ? items.filter(it => it.label.toLowerCase().includes(ft)) : items;

            if (filtered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'ss-empty';
                empty.textContent = 'لا توجد نتائج';
                panel.appendChild(empty);
            } else {
                filtered.forEach(it => {
                    const opt = document.createElement('div');
                    opt.className = 'ss-option' + (it.value === selectedValue ? ' selected' : '');
                    opt.textContent = it.label;
                    opt.addEventListener('mousedown', (e) => { e.preventDefault(); select(it.value, it.label); });
                    panel.appendChild(opt);
                });
            }
        }

        function select(value, label) {
            selectedValue = value;
            selectedLabel = label;
            input.value = label;
            wrap.classList.remove('open');
            if (onChangeCb) onChangeCb(value);
        }

        function openPanel() {
            wrap.classList.add('open');
            render('');
        }

        input.addEventListener('focus', openPanel);
        input.addEventListener('click', openPanel);
        input.addEventListener('input', () => {
            wrap.classList.add('open');
            render(input.value);
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { input.blur(); wrap.classList.remove('open'); }
        });
        input.addEventListener('blur', () => {
            // slight delay so a click on an option registers before the panel closes
            setTimeout(() => {
                wrap.classList.remove('open');
                if (input.value !== selectedLabel) input.value = selectedLabel;
            }, 150);
        });

        return {
            setItems(newItems) { items = newItems; },
            getValue() { return selectedValue; },
            onChange(cb) { onChangeCb = cb; },
            clear() { selectedValue = ''; selectedLabel = ''; input.value = ''; }
        };
    }

    const customerSelect = makeSearchSelect('customerWrap', 'customerInput', 'customerPanel');
    const salesmanSelect = makeSearchSelect('salesmanWrap', 'salesmanInput', 'salesmanPanel');
    customerSelect.onChange(onFiltersChanged);
    salesmanSelect.onChange(onFiltersChanged);

    async function loadFilterOptions() {
        try {
            let resp = await fetch('?filters=1');
            let data = await resp.json();
            if (data.error) { console.error(data.error); return; }

            customerSelect.setItems(data.customers.map(name => ({ value: name, label: name })));
            salesmanSelect.setItems(data.salesmen.map(s => ({ value: s.GUID, label: s.DisplayName })));
        } catch (e) {
            console.error('Failed to load filter options:', e);
        }
    }

    function fmtDateInput(d) {
        return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    }

    function applyPreset(preset) {
        document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
        const today = new Date();
        let from = new Date(today);
        let to = new Date(today);

        if (preset === 'today') {
            document.querySelector('[data-preset="today"]').classList.add('active');
        } else if (preset === 'week') {
            const day = (today.getDay() + 6) % 7; // Monday-start week
            from.setDate(today.getDate() - day);
            document.querySelector('[data-preset="week"]').classList.add('active');
        } else if (preset === 'month') {
            from = new Date(today.getFullYear(), today.getMonth(), 1);
            document.querySelector('[data-preset="month"]').classList.add('active');
        }

        document.getElementById('filterDateFrom').value = fmtDateInput(from);
        document.getElementById('filterDateTo').value = fmtDateInput(to);
        onFiltersChanged();
    }

    function onFiltersChanged() {
        activeFilters.customer = customerSelect.getValue();
        activeFilters.salesman = salesmanSelect.getValue();
        activeFilters.dateFrom = document.getElementById('filterDateFrom').value;
        activeFilters.dateTo = document.getElementById('filterDateTo').value;

        updateFiltersToggleBadge();

        // Reset pagination/state and reload fresh
        lastMaxDate = null;
        displayed.clear();
        loadMoreOffset = 1;
        hasMore = true;
        document.getElementById('bills').innerHTML = 'جاري التحميل...';
        loadBills(false);
    }

    function updateFiltersToggleBadge() {
        const badge = document.getElementById('filtersToggleBadge');
        if (!badge) return;
        const count = Object.values(activeFilters).filter(v => v).length;
        if (count > 0) {
            badge.textContent = count;
            badge.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
        }
    }

    document.getElementById('filterDateFrom').addEventListener('change', () => {
        document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
        onFiltersChanged();
    });
    document.getElementById('filterDateTo').addEventListener('change', () => {
        document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
        onFiltersChanged();
    });
    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.addEventListener('click', () => applyPreset(btn.dataset.preset));
    });
    document.getElementById('clearFiltersBtn').addEventListener('click', () => {
        customerSelect.clear();
        salesmanSelect.clear();
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
        onFiltersChanged();
    });

    // Mobile hamburger toggle for the filters bar (desktop keeps filters always visible)
    const filtersToggleBtn = document.getElementById('filtersToggleBtn');
    const filtersBarEl = document.getElementById('filtersBar');
    filtersToggleBtn.addEventListener('click', () => {
        const isOpen = filtersBarEl.classList.toggle('filters-open');
        filtersToggleBtn.classList.toggle('active', isOpen);
    });

    // Close any open dropdown panel when clicking elsewhere on the page
    document.addEventListener('mousedown', (e) => {
        document.querySelectorAll('.ss-wrap.open').forEach(w => {
            if (!w.contains(e.target)) w.classList.remove('open');
        });
        // On phones, tapping outside the filters bar (and not on the toggle button) closes it
        if (window.innerWidth <= 600 &&
            filtersBarEl.classList.contains('filters-open') &&
            !filtersBarEl.contains(e.target) &&
            !filtersToggleBtn.contains(e.target)) {
            filtersBarEl.classList.remove('filters-open');
            filtersToggleBtn.classList.remove('active');
        }
    });

    loadFilterOptions();

    // ---------- Dashboard functionality (unchanged from last working version) ----------
    let lastMaxDate = null;
    let displayed = new Map();
    let isLoadingMore = false;
    let loadMoreOffset = 1;
    let hasMore = true;

    async function loadBills(isLoadMore = false) {
        let url = '?ajax=1';
        if (isLoadMore) {
            url += '&loadMore=' + loadMoreOffset;
        } else if (lastMaxDate !== null) {
            url += '&lastDate=' + encodeURIComponent(lastMaxDate);
        }
        url += buildFilterQuery();
        try {
            let resp = await fetch(url);
            let text = await resp.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch(e) {
                console.error('JSON parse error:', e, 'Response:', text.substring(0,200));
                document.getElementById('bills').innerHTML = '<div class="error">خطأ في استجابة الخادم. تحقق من وحدة التحكم.</div>';
                return;
            }
            if (data.error) {
                document.getElementById('bills').innerHTML = `<div class="error">خطأ: ${data.error}</div>`;
                return;
            }
            if (!Array.isArray(data)) return;

            if (!isLoadMore && lastMaxDate === null) {
                document.getElementById('bills').innerHTML = '';
                displayed.clear();
                for (let bill of data) {
                    displayed.set(bill.GUID, bill);
                    addBillCard(bill, false);
                }
                if (data.length > 0) lastMaxDate = data[0].CreateDate;
                if (data.length < 200) hasMore = false;
                document.getElementById('loadMoreBtn').style.display = hasMore ? 'block' : 'none';
                updateBillCount();
            } else if (isLoadMore) {
                for (let bill of data) {
                    if (!displayed.has(bill.GUID)) {
                        displayed.set(bill.GUID, bill);
                        addBillCard(bill, false);
                    }
                }
                if (data.length < 200) hasMore = false;
                loadMoreOffset++;
                document.getElementById('loadMoreBtn').style.display = hasMore ? 'block' : 'none';
                isLoadingMore = false;
                updateBillCount();
            } else {
                let newMaxDate = lastMaxDate;
                for (let bill of data) {
                    if (bill.CreateDate > newMaxDate) newMaxDate = bill.CreateDate;
                    if (!displayed.has(bill.GUID)) {
                        displayed.set(bill.GUID, bill);
                        addBillCard(bill, true);
                    }
                }
                lastMaxDate = newMaxDate;
                if (data.length > 0) updateBillCount();
            }
        } catch(e) {
            console.error('Fetch error:', e);
            if (!isLoadMore) {
                document.getElementById('bills').innerHTML = `<div class="error">خطأ في الشبكة: ${e.message}</div>`;
            }
        } finally {
            if (isLoadMore) isLoadingMore = false;
        }
    }

    function updateBillCount() {
        const pill = document.getElementById('billCountPill');
        if (pill) pill.textContent = displayed.size;
    }

    function addBillCard(bill, prependFlag) {
        let div = document.createElement('div');
        div.className = 'bill-card' + (prependFlag ? ' new-flash' : '');
        div.dataset.guid = bill.GUID;
        div.innerHTML = `
            <div class="bill-title">
                <span class="bill-number">#${bill.Number}</span>
                <span class="bill-customer">${escapeHtml(bill.Cust_Name || 'بدون اسم')}</span>
            </div>
            <div class="bill-meta">
                <span>💰 ${escapeHtml(bill.CurrencyName || '-')}</span>
                <span>💳 ${escapeHtml(bill.PayType || '-')}</span>
                <span>🏬 ${escapeHtml(bill.StoreName || '-')}</span>
                <span>📊 ${escapeHtml(bill.CostCenterName || '-')}</span>
                <span>📅 ${new Date(bill.Date).toLocaleDateString('ar-EG-u-nu-latn')}</span>
                <span>🕒 ${new Date(bill.CreateDate).toLocaleTimeString('ar-EG-u-nu-latn')}</span>
            </div>
        `;
        div.onclick = () => showDetails(bill.GUID, div);
        const container = document.getElementById('bills');
        if (prependFlag && container.firstChild) {
            container.insertBefore(div, container.firstChild);
        } else {
            container.appendChild(div);
        }
    }

    async function showDetails(guid, element) {
        document.querySelectorAll('.bill-card').forEach(c => c.classList.remove('active'));
        element.classList.add('active');
        const detailsDiv = document.getElementById('detailsContainer');
        detailsDiv.innerHTML = '<div class="loading">جاري تحميل التفاصيل...</div>';
        try {
            let resp = await fetch(`?details=1&guid=${guid}`);
            let data = await resp.json();
            if (data.error) throw new Error(data.error);
            if (!data.length) {
                detailsDiv.innerHTML = '<div class="loading">لا توجد أصناف في هذه الفاتورة.</div>';
                return;
            }
            let html = `<div class="table-wrapper"> <table> <thead> <tr>
                <th>اسم الصنف</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th>
                <th>إضافي</th><th>الوحدة</th><th>نسبة الخصم</th><th>قيمة الخصم</th>
             </tr> </thead> <tbody>`;
            for (let row of data) {
                html += `<tr>
                    <td>${escapeHtml(row.ItemName)}</td>
                    <td>${parseFloat(row.Qty).toFixed(2)}</td>
                    <td>${parseFloat(row.UnitPrice).toFixed(2)}</td>
                    <td>${parseFloat(row.Total).toFixed(2)}</td>
                    <td>${parseFloat(row.Extra).toFixed(2)}</td>
                    <td>${escapeHtml(row.Unit) || '-'}</td>
                    <td>${parseFloat(row.DiscountPercent).toFixed(2)}%</td>
                    <td>${parseFloat(row.DiscountValue).toFixed(2)}</td>
                </tr>`;
            }
            html += `</tbody> </table> </div>`;
            detailsDiv.innerHTML = html;
        } catch(e) {
            detailsDiv.innerHTML = `<div class="error">${e.message}</div>`;
        }
    }

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    document.getElementById('loadMoreBtn').addEventListener('click', function() {
        if (isLoadingMore || !hasMore) return;
        isLoadingMore = true;
        loadBills(true);
    });

    loadBills();
    setInterval(() => loadBills(false), 5000);
</script>
</body>
</html>