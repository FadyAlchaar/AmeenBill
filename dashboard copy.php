<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once 'config.php';

/* =======================
   AJAX: Bills
======================= */
if (isset($_GET['ajax'])) {
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
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

/* =======================
   AJAX: Details
======================= */
if (isset($_GET['details']) && isset($_GET['guid'])) {
    header('Content-Type: application/json');

    try {
        $pdo = getDBConnection();

        $sql = "SELECT 
                    m.Name AS ItemName,
                    d.Qty,
                    d.Price,
                    (d.Qty * d.Price) AS Total,
                    d.Extra,
                    d.Unity AS Unit,
                    d.Discount
                FROM bi000 d
                LEFT JOIN mt000 m ON d.MatGUID = m.GUID
                WHERE d.ParentGUID = :guid";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':guid'=>$_GET['guid']]);

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

/* =======================
   AJAX: Analytics
======================= */
if (isset($_GET['analytics'])) {
    header('Content-Type: application/json');

    try {
        $pdo = getDBConnection();

        $sql = "
        SELECT 
            COUNT(DISTINCT b.GUID) AS totalBills,
            SUM(d.Qty * d.Price) AS totalSales,
            SUM(d.Qty) AS totalQty,
            SUM(d.Qty * d.Price * d.Discount / 100) AS totalDiscount
        FROM bu000 b
        JOIN bi000 d ON b.GUID = d.ParentGUID
        WHERE CAST(b.Date AS DATE) = CAST(GETDATE() AS DATE)
        ";

        $stmt = $pdo->query($sql);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode($data);

    } catch (Exception $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>Sales Dashboard</title>

<style>
body {
    margin:0;
    font-family:"Cairo", "Segoe UI", sans-serif;
    background:#f3f6fb;
    direction:rtl;
}

/* Layout */
.dashboard {
    display:flex;
    flex-direction:column;
    height:100vh;
    padding:15px;
    gap:12px;
}

/* Sections */
.top, .bottom {
    background:white;
    border-radius:14px;
    box-shadow:0 4px 12px rgba(0,0,0,0.06);
    overflow:hidden;
    display:flex;
    flex-direction:column;
}

.top { height:45%; }
.bottom { flex:1; }

/* Headers */
.section-header {
    padding:12px 16px;
    font-weight:700;
    font-size:14px;
    background:linear-gradient(90deg,#3b82f6,#2563eb);
    color:white;
}

/* Bills */
#bills {
    overflow:auto;
}

/* Bill Card */
.bill {
    padding:12px;
    border-bottom:1px solid #eef2f7;
    cursor:pointer;
    transition:all 0.2s ease;
}

.bill:hover {
    background:#f8fbff;
    transform:translateX(-3px);
}

.bill.active {
    background:#eef4ff;
    border-right:5px solid #3b82f6;
}

/* Top line */
.bill-title {
    display:flex;
    justify-content:space-between;
    font-size:14px;
    margin-bottom:5px;
}

.bill-customer {
    font-weight:700;
    color:#1e293b;
}

.bill-number {
    background:#3b82f6;
    color:white;
    font-size:11px;
    padding:2px 8px;
    border-radius:20px;
}

/* Meta */
.meta {
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    font-size:11px;
    color:#64748b;
}

.meta span {
    background:#eef2f7;
    padding:3px 6px;
    border-radius:8px;
}

/* Table */
table {
    width:100%;
    border-collapse:collapse;
    font-size:12px;
}

th {
    background:#f1f5f9;
    padding:10px;
    text-align:center;
    font-weight:700;
}

td {
    padding:8px;
    border-bottom:1px solid #eef2f7;
    text-align:center;
}

td:first-child {
    text-align:right;
    font-weight:600;
}

/* Hover rows */
tr:hover td {
    background:#f8fbff;
}

/* Load more */
.load {
    text-align:center;
    padding:12px;
    cursor:pointer;
    font-weight:600;
    background:#f1f5f9;
    transition:0.2s;
}

.load:hover {
    background:#e2e8f0;
}
.analytics-card {
    flex:1;
    min-width:150px;
    background:white;
    border-radius:12px;
    padding:12px;
    box-shadow:0 2px 6px rgba(0,0,0,0.05);
}

.analytics-title {
    font-size:12px;
    color:#64748b;
}

.analytics-value {
    font-size:18px;
    font-weight:700;
    margin-top:5px;
}
#splitter {
    height:6px;
    background:#cbd5e1;
    cursor:row-resize;
    border-radius:3px;
    margin:5px 0;
}

#splitter:hover {
    background:#3b82f6;
}
</style>
</head>

<body>

<div class="dashboard">

    <!-- ANALYTICS -->
    <div id="analytics" style="display:flex; gap:10px; flex-wrap:wrap;"></div>

    <div id="topSection" class="top">
        <div class="section-header">فواتير المبيعات</div>
        <div id="bills"></div>
    </div>

    <div id="splitter"></div>

    <div id="bottomSection" class="bottom">
        <div class="section-header">تفاصيل الفاتورة</div>
        <div id="details"></div>
    </div>

</div>

<script>
let offset = 0;

function loadBills() {
    fetch(`?ajax=1&offset=${offset}`)
    .then(r=>r.json())
    .then(data=>{
        if(!Array.isArray(data)) return;

        data.forEach(b=>{
            let div = document.createElement('div');
            div.className = 'bill';

        div.innerHTML = `
            <div class="bill-title">
                <span class="bill-customer">${b.Cust_Name || 'بدون اسم'}</span>
                <span class="bill-number">#${b.Number}</span>
            </div>

            <div class="meta">
                <span>💰 ${b.CurrencyName || '-'}</span>
                <span>🏬 ${b.StoreName || '-'}</span>
                <span>💳 ${b.PayType || '-'}</span>
                <span>📅 ${new Date(b.Date).toLocaleDateString('ar-EG')}</span>
                <span>🕒 ${new Date(b.CreateDate).toLocaleTimeString('ar-EG')}</span>
            </div>
        `;

            div.onclick = ()=>showDetails(b.GUID, div);

            document.getElementById('bills').appendChild(div);
        });

        offset += 200;
    });
}

function loadMore(){
    loadBills();
}

function showDetails(guid, el) {
    document.querySelectorAll('.bill').forEach(b=>b.classList.remove('active'));
    el.classList.add('active');

    document.getElementById('details').innerHTML = 'Loading...';

    fetch(`?details=1&guid=${guid}`)
    .then(r=>r.json())
    .then(data=>{
        let html = `
        <table>
        <tr>
            <th>اسم الصنف</th>
            <th>الكمية</th>
            <th>سعر الوحدة</th>
            <th>الإجمالي</th>
            <th>إضافي</th>
            <th>الوحدة</th>
            <th>نسبة الخصم %</th>
            <th>قيمة الخصم</th>
        </tr>
        `;

        data.forEach(d=>{
            html += `<tr>
                <td>${d.ItemName || '-'}</td>
                <td>${parseFloat(d.Qty).toFixed(2)}</td>
                <td>${parseFloat(d.Price).toFixed(2)}</td>
                <td>${parseFloat(d.Total).toFixed(2)}</td>
                <td>${parseFloat(d.Extra || 0).toFixed(2)}</td>
                <td>${d.Unit || '-'}</td>
                <td>${parseFloat(d.Discount || 0).toFixed(2)}%</td>
                <td>${((d.Qty * d.Price * d.Discount) / 100 || 0).toFixed(2)}</td>
            </tr>`;
        });

        html += '</table>';
        document.getElementById('details').innerHTML = html;
    });
}
function loadAnalytics() {
    fetch('?analytics=1')
    .then(r=>r.json())
    .then(d=>{

        if(d.error) return;

        const el = document.getElementById('analytics');
            if (!el) return;

            el.innerHTML = `
            <div class="analytics-card">
                <div class="analytics-title">إجمالي المبيعات</div>
                <div class="analytics-value">${parseFloat(d.totalSales || 0).toFixed(2)}</div>
            </div>

            <div class="analytics-card">
                <div class="analytics-title">عدد الفواتير</div>
                <div class="analytics-value">${d.totalBills || 0}</div>
            </div>

            <div class="analytics-card">
                <div class="analytics-title">إجمالي الكمية</div>
                <div class="analytics-value">${parseFloat(d.totalQty || 0).toFixed(2)}</div>
            </div>

            <div class="analytics-card">
                <div class="analytics-title">إجمالي الخصومات</div>
                <div class="analytics-value">${parseFloat(d.totalDiscount || 0).toFixed(2)}</div>
            </div>
        `;
    });
}
const topSection = document.getElementById('topSection');
const splitter = document.getElementById('splitter');
const dashboard = document.querySelector('.dashboard');

let isDragging = false;

splitter.addEventListener('mousedown', () => {
    isDragging = true;
    document.body.style.userSelect = 'none';
});

document.addEventListener('mousemove', (e) => {
    if (!isDragging) return;

    const rect = dashboard.getBoundingClientRect();
    let newHeight = e.clientY - rect.top;

    // limits
    if (newHeight < 100) newHeight = 100;
    if (newHeight > rect.height - 150) newHeight = rect.height - 150;

    topSection.style.height = newHeight + 'px';
});

document.addEventListener('mouseup', () => {
    isDragging = false;
    document.body.style.userSelect = '';
});

loadBills();
loadAnalytics();
setInterval(loadAnalytics, 5000);
</script>

</body>
</html>