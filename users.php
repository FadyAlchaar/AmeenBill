<?php
// users.php - local login credentials for the dashboard.
// This has NOTHING to do with the SQL Server database — it's just a basic
// access gate for the dashboard page itself.
//
// To add/change a user: open generate_hash.php in the browser, enter the
// desired password, and paste the hash it gives you below. Then delete
// generate_hash.php from the server (don't leave it publicly accessible).

$DASHBOARD_USERS = [
    'admin' => '$2y$10$qNhdfdU1D5DPzYYdKGycOOh0xAJgXiHtPrg5kew.MksQ1/aPDP/xC',
];
?>
