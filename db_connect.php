<?php
$host = 'localhost';
$dbname = 'c2652217_rrbb';
$username = 'c2652217_rrbb';
$password = 'ne14/0/fuTEwiru';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
