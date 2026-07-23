<?php
// fetch_new_bills.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once 'config.php';

$lastNumber = isset($_GET['lastNumber']) ? (int)$_GET['lastNumber'] : null;

try {
    $pdo = getDBConnection();
    
    if ($lastNumber !== null) {
        $sql = "SELECT 
                    b.GUID,
                    b.Number AS number,
                    b.Cust_Name,
                    b.Date,
                    b.PayType,
                    cur.Name AS CurrencyName,
                    s.Name AS StoreName,
                    cc.Name AS CostCenterName
                FROM bu000 b
                LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
                LEFT JOIN st000 s ON b.StoreGUID = s.GUID
                LEFT JOIN co000 cc ON b.CostGUID = cc.GUID
                WHERE b.Number > :lastNumber
                ORDER BY b.Number ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':lastNumber' => $lastNumber]);
    } else {
        $sql = "SELECT TOP 50
                    b.GUID,
                    b.Number AS number,
                    b.Cust_Name,
                    b.Date,
                    b.PayType,
                    cur.Name AS CurrencyName,
                    s.Name AS StoreName,
                    cc.Name AS CostCenterName
                FROM bu000 b
                LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
                LEFT JOIN st000 s ON b.StoreGUID = s.GUID
                LEFT JOIN co000 cc ON b.CostGUID = cc.GUID
                ORDER BY b.Number DESC";
        $stmt = $pdo->query($sql);
    }
    
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($bills as &$bill) {
        if ($bill['Date'] instanceof DateTime) {
            $bill['Date'] = $bill['Date']->format('c');
        } else {
            $dt = new DateTime($bill['Date']);
            $bill['Date'] = $dt->format('c');
        }
    }
    
    echo json_encode($bills);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>