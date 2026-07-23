<?php
// fetch_bill_details.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once 'config.php';

if (!isset($_GET['guid'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing bill GUID']);
    exit;
}

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
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>