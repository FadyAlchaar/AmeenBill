<?php
// dashboard_full.php - with draggable splitter between bills and details
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once 'config.php';

// AJAX handler for bills list
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    $lastDate = isset($_GET['lastDate']) ? $_GET['lastDate'] : null;
    $loadMore = isset($_GET['loadMore']) ? (int)$_GET['loadMore'] : 0;
    try {
        $pdo = getDBConnection();
        if ($lastDate && !$loadMore) {
            $dt = new DateTime($lastDate);
            $sqlDate = $dt->format('Y-m-d H:i:s');
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
                    WHERE b.CreateDate > :lastDate
                    ORDER BY b.CreateDate ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':lastDate' => $sqlDate]);
        } else {
            $limit = 200;
            $offset = $loadMore * $limit;
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
                    ORDER BY b.CreateDate DESC
                    OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
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
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            margin: 0;
            padding: 20px;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .dashboard {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            height: 100%;
            width: 100%;
        }
        h1 {
            color: #1e293b;
            font-size: 1.5rem;
            margin: 0 0 15px 0;
            text-align: right;
            flex-shrink: 0;
        }
        /* Splitter container */
        .split-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        /* Bills section (upper) */
        .bills-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 100px;
        }
        .bills-header {
            background: #1e293b;
            color: white;
            padding: 10px 16px;
            font-weight: 600;
            font-size: 0.95rem;
            text-align: right;
            flex-shrink: 0;
        }
        #bills {
            overflow-y: auto;
            flex: 1;
        }
        .bill-card {
            background: white;
            border-right: 3px solid #3b82f6;
            padding: 8px 12px;
            cursor: pointer;
            transition: all 0.15s;
            border-bottom: 1px solid #eef2f6;
            text-align: right;
        }
        .bill-card:hover { background: #f8fafc; }
        .bill-card.active { background: #eef2ff; border-right-color: #2563eb; }
        .bill-title {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 8px 12px;
            margin-bottom: 4px;
            justify-content: flex-start;
        }
        .bill-number {
            background: #3b82f6;
            color: white;
            font-size: 0.7rem;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 12px;
        }
        .bill-customer { font-weight: 600; font-size: 0.9rem; }
        .bill-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 0.7rem;
            color: #475569;
            justify-content: flex-start;
        }
        .bill-meta span {
            background: #eef2f6;
            padding: 2px 6px;
            border-radius: 10px;
        }
        .load-more-btn {
            text-align: center;
            padding: 10px;
            background: #f1f5f9;
            cursor: pointer;
            color: #2563eb;
            font-weight: 600;
            border-top: 1px solid #e2e8f0;
            flex-shrink: 0;
        }
        .load-more-btn:hover {
            background: #e2e8f0;
        }

        /* Splitter handle */
        .splitter {
            background: #cbd5e1;
            height: 6px;
            margin: 8px 0;
            cursor: row-resize;
            border-radius: 3px;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        .splitter:hover, .splitter.active {
            background: #3b82f6;
        }

        /* Details section (lower) */
        .details-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 100px;
            flex: 1;
        }
        .details-header {
            background: #334155;
            color: white;
            padding: 10px 16px;
            font-weight: 600;
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
            background: #f1f5f9;
            color: #0f172a;
            font-weight: 600;
            padding: 8px 6px;
            border-bottom: 1px solid #cbd5e1;
            text-align: center;
            white-space: nowrap;
        }
        td {
            padding: 6px 4px;
            border-bottom: 1px solid #eef2f6;
            text-align: center;
        }
        tr:hover td { background: #fafcff; }
        td:nth-child(2), td:nth-child(3), td:nth-child(4), td:nth-child(5), td:nth-child(7), td:nth-child(8) {
            text-align: left;
        }
        td:first-child { text-align: right; font-weight: 500; }
        th:first-child { text-align: right; }
        .loading, .error { padding: 20px; text-align: center; font-size: 0.85rem; }
        .error { color: #dc2626; background: #fee2e2; }
    </style>
</head>
<body>
<div class="dashboard">
    <h1>📊 لوحة المبيعات - التحديث المباشر</h1>
    <div class="split-container">
        <!-- Bills section (top) -->
        <div class="bills-container" id="topSection">
            <div class="bills-header">📄 الفواتير (اضغط لعرض التفاصيل)</div>
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

    // Load saved height from localStorage
    const savedHeight = localStorage.getItem('dashboardTopHeight');
    if (savedHeight) {
        topSection.style.height = savedHeight + 'px';
    } else {
        // Default: 50% of available space (minus splitter margin)
        const containerHeight = splitContainer.clientHeight;
        topSection.style.height = Math.floor(containerHeight * 0.5) + 'px';
    }

    function onMouseMove(e) {
        if (!isDragging) return;
        const containerRect = splitContainer.getBoundingClientRect();
        const mouseY = e.clientY;
        let newTopHeight = mouseY - containerRect.top - (splitter.offsetHeight / 2);
        // Clamp values: min 80px, max container height - 150px (to keep details visible)
        newTopHeight = Math.min(Math.max(newTopHeight, 80), containerRect.height - 150);
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
        document.addEventListener('touchmove', onTouchMove);
        document.addEventListener('touchend', onTouchEnd);
        e.preventDefault();
    });

    function onTouchMove(e) {
        if (!isDragging) return;
        const containerRect = splitContainer.getBoundingClientRect();
        const touchY = e.touches[0].clientY;
        let newTopHeight = touchY - containerRect.top - (splitter.offsetHeight / 2);
        newTopHeight = Math.min(Math.max(newTopHeight, 80), containerRect.height - 150);
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

    function addBillCard(bill, prependFlag) {
        let div = document.createElement('div');
        div.className = 'bill-card';
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
                <span>📅 ${new Date(bill.Date).toLocaleDateString('ar-EG')}</span>
                <span>🕒 ${new Date(bill.CreateDate).toLocaleTimeString('ar-EG')}</span>
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
                    <td style="text-align: left;">${parseFloat(row.Qty).toFixed(2)}</td>
                    <td style="text-align: left;">${parseFloat(row.UnitPrice).toFixed(2)}</td>
                    <td style="text-align: left;">${parseFloat(row.Total).toFixed(2)}</td>
                    <td style="text-align: left;">${parseFloat(row.Extra).toFixed(2)}</td>
                    <td>${escapeHtml(row.Unit) || '-'}</td>
                    <td style="text-align: left;">${parseFloat(row.DiscountPercent).toFixed(2)}%</td>
                    <td style="text-align: left;">${parseFloat(row.DiscountValue).toFixed(2)}</td>
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