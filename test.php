<?php
$host = 'localhost';
$db   = 'student_rental_hub';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    echo "✅ Database connected successfully!";
    
    // Check tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<br><br>Tables found: ";
    echo implode(', ', $tables);
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>