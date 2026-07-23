<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once 'config.php';

/* =======================
   INITIAL LOAD / LOAD MORE
======================= */
if (isset($_GET['ajax']) && !isset($_GET['poll'])) {
    header('Content-Type: application/json');

    try {
        $pdo = getDBConnection();

        $limit = 200;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

        $sql = "SELECT 
                    b.GUID,
                    b.Number,
                    b.Cust_Name,
                    b.Date,
                    b.PayType,
                    b.CreateDate,
                    cur.Name AS CurrencyName,
                    s.Name AS StoreName,
                    cc.Name AS CostCenterName
                FROM bu000 b
                LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
                LEFT JOIN st000 s ON b.StoreGUID = s.GUID
                LEFT JOIN co000 cc ON b.CostGUID = cc.GUID
                ORDER BY b.CreateDate DESC
                OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* =======================
   POLLING FOR NEW BILLS
======================= */
if (isset($_GET['poll']) && isset($_GET['lastDate'])) {
    header('Content-Type: application/json');

    try {
        $pdo = getDBConnection();
        $lastDate = $_GET['lastDate'];
        $dt = new DateTime($lastDate);
        $sqlDate = $dt->format('Y-m-d H:i:s');

        $sql = "SELECT 
                    b.GUID,
                    b.Number,
                    b.Cust_Name,
                    b.Date,
                    b.PayType,
                    b.CreateDate,
                    cur.Name AS CurrencyName,
                    s.Name AS StoreName,
                    cc.Name AS CostCenterName
                FROM bu000 b
                LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
                LEFT JOIN st000 s ON b.StoreGUID = s.GUID
                LEFT JOIN co000 cc ON b.CostGUID = cc.GUID
                WHERE b.CreateDate > :lastDate
                ORDER BY b.CreateDate ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':lastDate' => $sqlDate]);

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* =======================
   DETAILS - Discount as value, not percentage
======================= */
if (isset($_GET['details']) && isset($_GET['guid'])) {
    header('Content-Type: application/json');

    try {
        $pdo = getDBConnection();

        $sql = "SELECT 
                    m.Name AS ItemName,
                    d.Qty,
                    d.Price,
                    d.BonusQnt AS BonusQty,
                    d.Discount AS DiscountValue,
                    CASE 
                        WHEN d.Unity = 1 OR d.Unity IS NULL THEN m.Unity
                        WHEN d.Unity = 2 THEN m.Unit2
                        WHEN d.Unity = 3 THEN m.Unit3
                        ELSE m.Unity
                    END AS UnitName,
                    CASE 
                        WHEN d.Unity = 1 OR d.Unity IS NULL THEN 1
                        WHEN d.Unity = 2 THEN ISNULL(m.Unit2Fact, 1)
                        WHEN d.Unity = 3 THEN ISNULL(m.Unit3Fact, 1)
                        ELSE 1
                    END AS UnitFactor
                FROM bi000 d
                LEFT JOIN mt000 m ON d.MatGUID = m.GUID
                WHERE d.ParentGUID = :guid
                ORDER BY d.Number ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':guid' => $_GET['guid']]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($details as &$row) {
            $qty = (float)$row['Qty'];
            $bonusQty = (float)$row['BonusQty'];
            $factor = (float)$row['UnitFactor'];
            $displayQty = ($factor > 0) ? $qty / $factor : $qty;
            $displayBonusQty = ($factor > 0 && $bonusQty > 0) ? $bonusQty / $factor : $bonusQty;
            $price = (float)$row['Price'];
            $total = $displayQty * $price;
            $discountValue = (float)$row['DiscountValue'];
            
            $row['DisplayQty'] = $displayQty;
            $row['DisplayBonusQty'] = $displayBonusQty;
            $row['DisplayUnit'] = $row['UnitName'] ?? '';
            $row['Price'] = $price;
            $row['Total'] = $total;
            $row['DiscountValue'] = $discountValue;
        }
        
        echo json_encode($details);

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* =======================
   ANALYTICS (SINGLE BILL) - Discount is stored as value
======================= */
if (isset($_GET['analytics']) && isset($_GET['guid'])) {
    header('Content-Type: application/json');

    try {
        $pdo = getDBConnection();

        $sql = "
        SELECT 
            d.Qty,
            d.Price,
            d.Discount,
            CASE 
                WHEN d.Unity = 1 OR d.Unity IS NULL THEN 1
                WHEN d.Unity = 2 THEN ISNULL(m.Unit2Fact, 1)
                WHEN d.Unity = 3 THEN ISNULL(m.Unit3Fact, 1)
                ELSE 1
            END AS UnitFactor
        FROM bi000 d
        LEFT JOIN mt000 m ON d.MatGUID = m.GUID
        WHERE d.ParentGUID = :guid
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':guid' => $_GET['guid']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totalItems = count($rows);
        $totalQty = 0;
        $totalGrossSales = 0;
        $totalDiscount = 0;
        
        foreach ($rows as $row) {
            $qty = (float)$row['Qty'];
            $factor = (float)$row['UnitFactor'];
            $displayQty = ($factor > 0) ? $qty / $factor : $qty;
            $price = (float)$row['Price'];
            $discount = (float)$row['Discount'];
            
            $lineTotal = $displayQty * $price;
            
            $totalQty += $displayQty;
            $totalGrossSales += $lineTotal;
            $totalDiscount += $discount;
        }
        
        $finalSales = $totalGrossSales - $totalDiscount;
        
        echo json_encode([
            'totalItems' => $totalItems,
            'totalQty' => round($totalQty, 2),
            'totalSales' => round($finalSales, 2),
            'totalDiscount' => round($totalDiscount, 2)
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>Sales Dashboard - Real Time</title>

<style>
body {
    margin:0;
    font-family:"Segoe UI", Tahoma;
    background:#f4f6fb;
}

.dashboard {
    height:100vh;
    display:flex;
    flex-direction:column;
    padding:12px;
    gap:10px;
}

#analytics {
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.analytics-card {
    flex:1;
    min-width:160px;
    background:#fff;
    padding:12px;
    border-radius:12px;
    box-shadow:0 2px 6px rgba(0,0,0,0.05);
}

.analytics-title {
    font-size:12px;
    color:#64748b;
}

.analytics-value {
    font-size:18px;
    font-weight:700;
    color:#0f172a;
}

.top, .bottom {
    background:white;
    border-radius:12px;
    box-shadow:0 2px 8px rgba(0,0,0,0.06);
    overflow:hidden;
    display:flex;
    flex-direction:column;
}

.top { height:45%; }
.bottom { flex:1; }

.section-header {
    padding:10px;
    background:#2563eb;
    color:white;
    font-weight:600;
}

#bills {
    overflow-y: auto;
    flex: 1;
}

.load-more-container {
    text-align: center;
    padding: 12px;
    border-top: 1px solid #e2e8f0;
    background: #f8fafc;
    flex-shrink: 0;
}

.load-more-btn {
    background: #3b82f6;
    color: white;
    border: none;
    padding: 8px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    transition: background 0.2s;
}

.load-more-btn:hover {
    background: #2563eb;
}

.load-more-btn:disabled {
    background: #94a3b8;
    cursor: not-allowed;
}

.bill {
    padding:10px;
    border-bottom:1px solid #eee;
    cursor:pointer;
    transition:0.15s;
}

.bill:hover {
    background:#f8fbff;
}

.bill.active {
    background:#eaf1ff;
    border-right:4px solid #3b82f6;
}

.bill-title {
    display:flex;
    justify-content:space-between;
    font-weight:600;
}

.bill-number {
    background:#3b82f6;
    color:#fff;
    padding:2px 8px;
    border-radius:10px;
    font-size:11px;
}

.meta {
    font-size:11px;
    color:#666;
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.table-wrapper {
    overflow-x: auto;
    flex: 1;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

th {
    background: #f1f5f9;
    color: #0f172a;
    font-weight: 600;
    padding: 10px 8px;
    border-bottom: 2px solid #e2e8f0;
    text-align: center;
    white-space: nowrap;
}

td {
    padding: 8px;
    border-bottom: 1px solid #eef2f6;
    text-align: center;
}

td:first-child, th:first-child {
    text-align: right;
    padding-right: 12px;
}

tr:hover td {
    background: #fafcff;
}

td:nth-child(2), td:nth-child(3), td:nth-child(4), td:nth-child(5), td:nth-child(7) {
    font-family: 'Courier New', 'Segoe UI', monospace;
    font-weight: 500;
}

#splitter {
    height:6px;
    background:#cbd5e1;
    cursor:row-resize;
    border-radius:4px;
}

#splitter:hover {
    background:#3b82f6;
}
</style>
</head>

<body>

<div class="dashboard">

    <div id="analytics"></div>

    <div id="topSection" class="top">
        <div class="section-header">فواتير المبيعات (تحديث تلقائي)</div>
        <div id="bills"></div>
        <div class="load-more-container" id="loadMoreContainer" style="display: none;">
            <button id="loadMoreBtn" class="load-more-btn">➕ تحميل المزيد</button>
        </div>
    </div>

    <div id="splitter"></div>

    <div id="bottomSection" class="bottom">
        <div class="section-header">تفاصيل الفاتورة</div>
        <div class="table-wrapper" id="details"></div>
    </div>

</div>

<script>
let offset = 0;
let lastMaxDate = null;
let displayedGuids = new Set();
let isLoadingMore = false;
let hasMore = true;

function fmt(v) {
    let n = parseFloat(v);
    if (isNaN(n)) return "0.00";
    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(n);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function createBillElement(bill) {
    let div = document.createElement('div');
    div.className = 'bill';
    div.dataset.guid = bill.GUID;

    div.innerHTML = `
        <div class="bill-title">
            <span>${escapeHtml(bill.Cust_Name || 'بدون اسم')}</span>
            <span class="bill-number">#${bill.Number}</span>
        </div>
        <div class="meta">
            <span>💰 ${escapeHtml(bill.CurrencyName || '-')}</span>
            <span>🏬 ${escapeHtml(bill.StoreName || '-')}</span>
            <span>💳 ${escapeHtml(bill.PayType || '-')}</span>
            <span>📅 ${new Date(bill.Date).toLocaleDateString('ar-EG')}</span>
            <span>🕒 ${new Date(bill.CreateDate).toLocaleTimeString('ar-EG')}</span>
        </div>
    `;

    div.onclick = () => showDetails(bill.GUID, div);
    return div;
}

function loadBills(isLoadMore = false) {
    if (isLoadMore && isLoadingMore) return;
    if (isLoadMore) isLoadingMore = true;
    
    const url = `?ajax=1&offset=${offset}`;
    
    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (!Array.isArray(data)) return;
            
            const container = document.getElementById('bills');
            
            if (!isLoadMore) {
                container.innerHTML = '';
                displayedGuids.clear();
                for (let bill of data) {
                    displayedGuids.add(bill.GUID);
                    const div = createBillElement(bill);
                    container.appendChild(div);
                }
                if (data.length > 0) {
                    lastMaxDate = data[0].CreateDate;
                }
                offset = 200;
            } else {
                for (let bill of data) {
                    if (displayedGuids.has(bill.GUID)) continue;
                    displayedGuids.add(bill.GUID);
                    const div = createBillElement(bill);
                    container.appendChild(div);
                }
                offset += 200;
            }
            
            if (data.length < 200) {
                hasMore = false;
                const loadMoreBtn = document.getElementById('loadMoreBtn');
                if (loadMoreBtn) {
                    loadMoreBtn.disabled = true;
                    loadMoreBtn.textContent = '✅ لا يوجد المزيد';
                }
            } else {
                hasMore = true;
                const loadMoreContainer = document.getElementById('loadMoreContainer');
                if (loadMoreContainer) loadMoreContainer.style.display = 'block';
            }
            
            isLoadingMore = false;
        })
        .catch(err => {
            console.error('Load bills error:', err);
            isLoadingMore = false;
        });
}

function pollNewBills() {
    if (!lastMaxDate) return;
    fetch(`?poll=1&lastDate=${encodeURIComponent(lastMaxDate)}`)
        .then(r => r.json())
        .then(data => {
            if (!Array.isArray(data) || data.length === 0) return;

            const container = document.getElementById('bills');
            let newMaxDate = lastMaxDate;
            
            for (let i = data.length - 1; i >= 0; i--) {
                const bill = data[i];
                if (displayedGuids.has(bill.GUID)) continue;
                displayedGuids.add(bill.GUID);
                const div = createBillElement(bill);
                container.prepend(div);
                if (bill.CreateDate > newMaxDate) newMaxDate = bill.CreateDate;
            }
            lastMaxDate = newMaxDate;
        })
        .catch(console.error);
}

function showDetails(guid, el) {
    document.querySelectorAll('.bill').forEach(b => b.classList.remove('active'));
    el.classList.add('active');

    const detailsDiv = document.getElementById('details');
    detailsDiv.innerHTML = '<div style="padding:20px;text-align:center;">جاري التحميل...</div>';

    fetch(`?details=1&guid=${guid}`)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                detailsDiv.innerHTML = `<div style="padding:20px;color:red;">${data.error}</div>`;
                return;
            }
            
            if (!data.length) {
                detailsDiv.innerHTML = '<div style="padding:20px;text-align:center;">لا توجد أصناف في هذه الفاتورة</div>';
                return;
            }

            let html = `
                <table>
                    <thead>
                        <tr>
                            <th>اسم الصنف</th>
                            <th>الكمية</th>
                            <th>سعر الوحدة</th>
                            <th>الإجمالي</th>
                            <th>إضافي</th>
                            <th>الوحدة</th>
                            <th>الخصم</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            data.forEach(row => {
                html += `
                    <tr>
                        <td>${escapeHtml(row.ItemName || '-')}</td>
                        <td>${fmt(row.DisplayQty)}</td>
                        <td>${fmt(row.Price)}</td>
                        <td>${fmt(row.Total)}</td>
                        <td>${fmt(row.DisplayBonusQty)}</td>
                        <td>${escapeHtml(row.DisplayUnit || '-')}</td>
                        <td>${fmt(row.DiscountValue)}</td>
                    </tr>
                `;
            });

            html += `</tbody>
                </table>`;
            detailsDiv.innerHTML = html;
            loadBillAnalytics(guid);
        })
        .catch(err => {
            detailsDiv.innerHTML = `<div style="padding:20px;color:red;">خطأ في تحميل التفاصيل: ${err.message}</div>`;
            console.error(err);
        });
}

function loadBillAnalytics(guid) {
    fetch(`?analytics=1&guid=${guid}`)
        .then(r => r.json())
        .then(d => {
            const el = document.getElementById('analytics');
            if (!el || d.error) return;
            el.innerHTML = `
                <div class="analytics-card">
                    <div class="analytics-title">أصناف الفاتورة</div>
                    <div class="analytics-value">${d.totalItems || 0}</div>
                </div>
                <div class="analytics-card">
                    <div class="analytics-title">إجمالي الكمية</div>
                    <div class="analytics-value">${fmt(d.totalQty)}</div>
                </div>
                <div class="analytics-card">
                    <div class="analytics-title">قيمة الفاتورة</div>
                    <div class="analytics-value">${fmt(d.totalSales)}</div>
                </div>
                <div class="analytics-card">
                    <div class="analytics-title">الخصومات</div>
                    <div class="analytics-value">${fmt(d.totalDiscount)}</div>
                </div>
            `;
        })
        .catch(console.error);
}

document.getElementById('loadMoreBtn').addEventListener('click', function() {
    if (!hasMore || isLoadingMore) return;
    loadBills(true);
});

/* Splitter */
const topSection = document.getElementById('topSection');
const splitter = document.getElementById('splitter');
const dashboard = document.querySelector('.dashboard');

let drag = false;
splitter.addEventListener('mousedown', () => {
    drag = true;
    document.body.style.userSelect = 'none';
});
document.addEventListener('mousemove', (e) => {
    if (!drag) return;
    const rect = dashboard.getBoundingClientRect();
    let h = e.clientY - rect.top;
    if (h < 120) h = 120;
    if (h > rect.height - 150) h = rect.height - 150;
    topSection.style.height = h + 'px';
});
document.addEventListener('mouseup', () => {
    drag = false;
    document.body.style.userSelect = '';
});

splitter.addEventListener('touchstart', (e) => {
    drag = true;
    document.body.style.userSelect = 'none';
    e.preventDefault();
});
document.addEventListener('touchmove', (e) => {
    if (!drag) return;
    const rect = dashboard.getBoundingClientRect();
    let h = e.touches[0].clientY - rect.top;
    if (h < 120) h = 120;
    if (h > rect.height - 150) h = rect.height - 150;
    topSection.style.height = h + 'px';
});
document.addEventListener('touchend', () => {
    drag = false;
    document.body.style.userSelect = '';
});

/* INIT */
loadBills(false);
setInterval(pollNewBills, 5000);
</script>

</body>
</html>