<?php
// config.php
define('APP_ENV', 'dev');   // ← change to 'prod' on the production server

if (APP_ENV === 'prod') {
    define('DB_SERVER', 'localhost');
    define('DB_NAME',   'AlbassaDB2026');
    define('DB_USER',   'alameenbill_reader');
    define('DB_PASS',   'P@ssw0rd@2026');
} else {
    // Local XAMPP + SQL Express
    define('DB_SERVER', 'localhost\SQLEXPRESS');
    define('DB_NAME',   'AmnDb006');
    define('DB_USER',   'alameenbill_reader');
    define('DB_PASS',   'P@ssw0rd@2026');
}

function getDBConnection() {
    $dsn = "sqlsrv:Server=" . DB_SERVER . ";Database=" . DB_NAME
         . ";Driver=ODBC Driver 18 for SQL Server"
         . ";TrustServerCertificate=yes;Encrypt=no";

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLSRV_ATTR_ENCODING    => PDO::SQLSRV_ENCODING_UTF8,
    ];

    try {
        return new PDO($dsn, DB_USER, DB_PASS, $opts);
    } catch (PDOException $e) {
        error_log("DB Connection Error: " . $e->getMessage());
        throw new Exception("A database connection error occurred.");
    }
}
?>