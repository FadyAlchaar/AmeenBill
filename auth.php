<?php
// auth.php - simple session-based login guard.
// This is completely separate from the SQL Server database — credentials
// live only in users.php on this machine, for basic access control on the
// dashboard itself (not meant to replace real network/DB security).

session_start();

define('SESSION_TIMEOUT_SECONDS', 8 * 60 * 60); // auto-logout after 8 hours idle

function isLoggedIn() {
    if (empty($_SESSION['authenticated'])) {
        return false;
    }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

// Call this at the very top of any protected page/endpoint.
// $isAjax = true for JSON endpoints (returns 401 JSON instead of redirecting),
// false for full page loads (redirects to login.php).
function requireLogin($isAjax = false) {
    if (isLoggedIn()) {
        return;
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['error' => 'unauthenticated']);
    } else {
        header('Location: login.php');
    }
    exit;
}
?>
