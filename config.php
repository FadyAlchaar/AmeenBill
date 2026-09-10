<?php
// config.php
define('DB_SERVER', 'localhost\SQLEXPRESS'); // or just 'localhost'
define('DB_NAME', 'AmnDb006');
define('DB_USER', 'sa');
define('DB_PASS', 'P@ssw0rd');

function getDBConnection() {
    $connectionString = "sqlsrv:Server=" . DB_SERVER . ";Database=" . DB_NAME;
    try {
        $pdo = new PDO($connectionString, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}
?>