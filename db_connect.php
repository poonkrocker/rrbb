<?php
$host = 'localhost';
$dbname = 'c2810459_rrbb';
$username = 'c2810459_rrbb';
$password = 'nelo18MAfu';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>