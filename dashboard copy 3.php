<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once 'config.php';

/* =======================
   BILLS
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
   DETAILS
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
        $stmt->execute([':guid' => $_GET['guid']]);

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* =======================
   ANALYTICS (GLOBAL FIXED)
======================= */
/* =======================
   ANALYTICS (GLOBAL)
======================= */
if (isset($_GET['analytics']) && !isset($_GET['guid'])) {
    header('Content-Type: application/json');

    try {
        $pdo = getDBConnection();

        $sql = "
        SELECT 
            COUNT(DISTINCT b.GUID) AS totalBills,
            SUM(d.Qty) AS totalQty,
            SUM(d.Qty * d.Price) AS totalSales,
            SUM(d.Qty * d.Price * d.Discount / 100) AS totalDiscount
        FROM bu000 b
        LEFT JOIN bi000 d ON b.GUID = d.ParentGUID
        ";

        $stmt = $pdo->query($sql);

        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

/* =======================
   ANALYTICS (SINGLE BILL)
======================= */
if (isset($_GET['analytics']) && isset($_GET['guid'])) {
    header('Content-Type: application/json');

    try {
        $pdo = getDBConnection();

        $sql = "
        SELECT 
            COUNT(d.GUID) AS totalItems,
            SUM(d.Qty) AS totalQty,
            SUM(d.Qty * d.Price) AS totalSales,
            SUM(d.Qty * d.Price * d.Discount / 100) AS totalDiscount
        FROM bi000 d
        WHERE d.ParentGUID = :guid
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':guid' => $_GET['guid']]);

        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
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
<title>Sales Dashboard</title>

<style>
body {
    margin:0;
    font-family:"Segoe UI", Tahoma;
    background:#f4f6fb;
}

/* Layout */
.dashboard {
    height:100vh;
    display:flex;
    flex-direction:column;
    padding:12px;
    gap:10px;
}

/* Analytics */
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

/* Sections */
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

/* Header */
.section-header {
    padding:10px;
    background:#2563eb;
    color:white;
    font-weight:600;
}

/* Bills */
#bills {
    overflow:auto;
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

/* Table */
table {
    width:100%;
    border-collapse:collapse;
    font-size:12px;
}

th {
    background:#f1f5f9;
    padding:8px;
    text-align:center;
}

td {
    padding:8px;
    border-bottom:1px solid #eee;
    text-align:center;
}

/* Splitter */
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

    <!-- ANALYTICS -->
    <div id="analytics"></div>

    <!-- TOP -->
    <div id="topSection" class="top">
        <div class="section-header">فواتير المبيعات</div>
        <div id="bills"></div>
    </div>

    <!-- SPLITTER -->
    <div id="splitter"></div>

    <!-- DETAILS -->
    <div id="bottomSection" class="bottom">
        <div class="section-header">تفاصيل الفاتورة</div>
        <div id="details"></div>
    </div>

</div>

<script>
let offset = 0;

/* FORMAT SAFE NUMBER */
function fmt(v) {
    let n = parseFloat(v);
    if (isNaN(n)) return "0.00";

    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(n);
}

/* =======================
   LOAD BILLS
======================= */
function loadBills(){
    fetch(`?ajax=1&offset=${offset}`)
    .then(r=>r.json())
    .then(data=>{
        if(!Array.isArray(data)) return;

        let container = document.getElementById('bills');

        data.forEach(b=>{
            let div = document.createElement('div');
            div.className = 'bill';

            div.innerHTML = `
                <div class="bill-title">
                    <span>${b.Cust_Name || 'بدون اسم'}</span>
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
            container.appendChild(div);
        });

        offset += 200;
    });
}

/* =======================
   DETAILS
======================= */
function showDetails(guid, el){

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
            <th>السعر</th>
            <th>الإجمالي</th>
            <th>إضافي</th>
            <th>الوحدة</th>
            <th>خصم %</th>
            <th>قيمة الخصم</th>
        </tr>`;

        data.forEach(d=>{
            html += `<tr>
                <td>${d.ItemName || '-'}</td>
                <td>${fmt(d.Qty)}</td>
                <td>${fmt(d.Price)}</td>
                <td>${fmt(d.Total)}</td>
                <td>${fmt(d.Extra)}</td>
                <td>${d.Unit || '-'}</td>
                <td>${fmt(d.Discount)}</td>
                <td>${fmt((d.Qty*d.Price*d.Discount)/100)}</td>
            </tr>`;
        });

        html += `</table>`;
        document.getElementById('details').innerHTML = html;
        loadBillAnalytics(guid);
    });
}

/* =======================
   ANALYTICS
======================= */
/* function loadAnalytics(){
    fetch('?analytics=1')
        .then(async r => {
            const text = await r.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error("Invalid JSON:", text);
                return null;
            }
        })
    .then(d=>{

        const el = document.getElementById('analytics');
        if(!el || !d) return;

        el.innerHTML = `
        <div class="analytics-card">
            <div class="analytics-title">المبيعات</div>
            <div class="analytics-value">${fmt(d.totalSales)}</div>
        </div>

        <div class="analytics-card">
            <div class="analytics-title">الفواتير</div>
            <div class="analytics-value">${d.totalBills || 0}</div>
        </div>

        <div class="analytics-card">
            <div class="analytics-title">الكمية</div>
            <div class="analytics-value">${fmt(d.totalQty)}</div>
        </div>

        <div class="analytics-card">
            <div class="analytics-title">الخصومات</div>
            <div class="analytics-value">${fmt(d.totalDiscount)}</div>
        </div>`;
    });
}
 */
/* =======================
   SPLITTER
======================= */
const topSection = document.getElementById('topSection');
const splitter = document.getElementById('splitter');
const dashboard = document.querySelector('.dashboard');

let drag = false;

splitter.addEventListener('mousedown',()=>{
    drag = true;
    document.body.style.userSelect='none';
});

document.addEventListener('mousemove',(e)=>{
    if(!drag) return;

    const rect = dashboard.getBoundingClientRect();
    let h = e.clientY - rect.top;

    if(h<120) h=120;
    if(h>rect.height-150) h=rect.height-150;

    topSection.style.height = h+'px';
});

document.addEventListener('mouseup',()=>{
    drag=false;
    document.body.style.userSelect='';
});

function loadBillAnalytics(guid) {
    fetch(`?analytics=1&guid=${guid}`)
    .then(r => r.json())
    .then(d => {

        const el = document.getElementById('analytics');
        if (!el || d.error) return;

        el.innerHTML = `
        <div class="analytics-card">
            <div class="analytics-title">إجمالي الأصناف</div>
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
    });
}

/* INIT */
loadBills();
loadAnalytics();
setInterval(loadAnalytics,5000);
</script>

</body>
</html>