<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Simulate what saveProfile does
define('DB_HOST', 'localhost');
define('DB_NAME', 'student_rental_hub');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    
    // Check profiles table columns
    $cols = $pdo->query("DESCRIBE profiles")->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Profiles table columns:</h3>";
    foreach($cols as $col) {
        echo "• " . $col['Field'] . " (" . $col['Type'] . ")<br>";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>