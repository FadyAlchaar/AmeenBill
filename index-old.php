<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Bills Dashboard - Real Time</title>
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { margin: 0; padding: 20px; background: #f0f2f5; }
        .dashboard { max-width: 1400px; margin: 0 auto; background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
        .bills-header { background: #1e293b; color: white; padding: 12px 20px; font-weight: bold; font-size: 1.2rem; }
        .bills-container { max-height: 400px; overflow-y: auto; padding: 10px; background: #f8fafc; }
        .bill-card { background: white; border-left: 5px solid #3b82f6; border-radius: 8px; margin-bottom: 10px; padding: 12px 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); cursor: pointer; transition: all 0.2s; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; }
        .bill-card:hover { background: #eef2ff; transform: translateX(4px); }
        .bill-card.active { background: #dbeafe; border-left-color: #2563eb; box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
        .bill-info { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; font-size: 0.9rem; }
        .bill-info span { background: #e2e8f0; padding: 4px 10px; border-radius: 20px; color: #1e293b; }
        .bill-date { font-weight: bold; color: #0f172a; }
        .bill-number { background: #3b82f6 !important; color: white !important; font-weight: bold; }
        .details-header { background: #334155; color: white; padding: 12px 20px; font-weight: bold; font-size: 1.1rem; }
        .details-container { padding: 20px; overflow-x: auto; min-height: 200px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th, td { border: 1px solid #cbd5e1; padding: 10px 8px; text-align: left; }
        th { background: #f1f5f9; font-weight: 600; position: sticky; top: 0; }
        tr:nth-child(even) { background: #f9fafb; }
        .loading { text-align: center; padding: 20px; color: #64748b; }
        .error { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 6px; margin: 10px; }
        @media (max-width: 768px) { .bill-info { gap: 8px; } .bill-card { flex-direction: column; align-items: flex-start; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="bills-header">📋 Recent & Live Sales Bills (click any bill to see details)</div>
    <div class="bills-container" id="billsList">
        <div class="loading">Loading bills...</div>
    </div>
    <div class="details-header">📄 Bill Details</div>
    <div class="details-container" id="detailsContainer">
        <div class="loading">Select a bill to view line items</div>
    </div>
</div>

<script>
    let lastMaxNumber = null;
    let displayedBillIds = new Set();
    let activeBillGuid = null;
    let isInitialLoadDone = false;

    const billsContainer = document.getElementById('billsList');
    const detailsContainer = document.getElementById('detailsContainer');

    function formatDate(isoString) {
        try {
            const d = new Date(isoString);
            return d.toLocaleString();
        } catch(e) { return isoString; }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    async function fetchNewBills() {
        let url = 'fetch_new_bills.php';
        if (lastMaxNumber !== null) {
            url += '?lastNumber=' + encodeURIComponent(lastMaxNumber);
        }
        try {
            const response = await fetch(url);
            const data = await response.json();
            if (data.error) {
                console.error('Server error:', data.error);
                if (!isInitialLoadDone) {
                    billsContainer.innerHTML = `<div class="error">❌ ${escapeHtml(data.error)}</div>`;
                }
                return;
            }
            const bills = data;
            if (!Array.isArray(bills)) return;
            if (!isInitialLoadDone) {
                isInitialLoadDone = true;
                billsContainer.innerHTML = '';
                if (bills.length === 0) {
                    billsContainer.innerHTML = '<div class="loading">No bills found.</div>';
                }
            }
            if (bills.length === 0) return;
            let newMaxNumber = lastMaxNumber;
            for (const bill of bills) {
                if (newMaxNumber === null || bill.number > newMaxNumber) newMaxNumber = bill.number;
                if (!displayedBillIds.has(bill.GUID)) {
                    displayedBillIds.add(bill.GUID);
                    prependBillCard(bill);
                }
            }
            lastMaxNumber = newMaxNumber;
            if (!activeBillGuid && billsContainer.children.length > 0) {
                const firstCard = billsContainer.querySelector('.bill-card');
                if (firstCard) firstCard.click();
            }
        } catch (err) {
            console.error('Fetch error:', err);
            if (!isInitialLoadDone) {
                billsContainer.innerHTML = `<div class="error">❌ Network error: ${err.message}</div>`;
            }
        }
    }
    
    function prependBillCard(bill) {
        const card = document.createElement('div');
        card.className = 'bill-card';
        card.dataset.guid = bill.GUID;
        card.innerHTML = `
            <div class="bill-info">
                <strong>🧾 ${escapeHtml(bill.Cust_Name || 'No name')}</strong>
                <span>💰 ${escapeHtml(bill.CurrencyName || '-')}</span>
                <span>💳 ${escapeHtml(bill.PayType || '-')}</span>
                <span>🏬 ${escapeHtml(bill.StoreName || '-')}</span>
                <span>📊 ${escapeHtml(bill.CostCenterName || '-')}</span>
                <span class="bill-date">📅 ${formatDate(bill.Date)}</span>
                <span class="bill-number">🔢 #${bill.number}</span>
            </div>
        `;
        card.addEventListener('click', (e) => {
            e.stopPropagation();
            setActiveBill(card, bill.GUID);
        });
        billsContainer.prepend(card);
    }
    
    async function setActiveBill(cardElement, guid) {
        document.querySelectorAll('.bill-card').forEach(c => c.classList.remove('active'));
        cardElement.classList.add('active');
        activeBillGuid = guid;
        detailsContainer.innerHTML = '<div class="loading">Loading details...</div>';
        try {
            const response = await fetch(`fetch_bill_details.php?guid=${encodeURIComponent(guid)}`);
            const data = await response.json();
            if (data.error) {
                detailsContainer.innerHTML = `<div class="error">❌ ${escapeHtml(data.error)}</div>`;
                return;
            }
            renderDetailsTable(data);
        } catch (err) {
            detailsContainer.innerHTML = `<div class="error">❌ Network error: ${err.message}</div>`;
        }
    }
    
    function renderDetailsTable(details) {
        if (!details || details.length === 0) {
            detailsContainer.innerHTML = '<div class="loading">No line items for this bill.</div>';
            return;
        }
        const table = document.createElement('table');
        table.innerHTML = `
            <thead>
                <tr><th>Item Name</th><th>Qty</th><th>Unit Price</th><th>Total</th><th>Extra</th><th>Unit</th><th>Discount %</th><th>Discount Value</th></tr>
            </thead>
            <tbody>
                ${details.map(d => `
                    <tr>
                        <td>${escapeHtml(d.ItemName || '-')}</td>
                        <td>${parseFloat(d.Qty).toFixed(2)}</td>
                        <td>${parseFloat(d.UnitPrice).toFixed(2)}</td>
                        <td>${parseFloat(d.Total).toFixed(2)}</td>
                        <td>${parseFloat(d.Extra).toFixed(2)}</td>
                        <td>${escapeHtml(d.Unit) || '-'}</td>
                        <td>${parseFloat(d.DiscountPercent).toFixed(2)}%</td>
                        <td>${parseFloat(d.DiscountValue).toFixed(2)}</td>
                    </tr>
                `).join('')}
            </tbody>
        `;
        detailsContainer.innerHTML = '';
        detailsContainer.appendChild(table);
    }
    
    async function init() {
        await fetchNewBills();
        setInterval(fetchNewBills, 3000);
    }
    init();
</script>
</body>
</html>