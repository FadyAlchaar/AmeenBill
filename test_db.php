<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';

try {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM bu000");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total bills in bu000: " . $row['total'] . "<br>";
    
    $stmt = $pdo->query("SELECT TOP 5 Number, Cust_Name FROM bu000 ORDER BY Number DESC");
    echo "<pre>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }
    echo "</pre>";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>