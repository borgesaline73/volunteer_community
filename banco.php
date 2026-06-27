<?php
// Usando a URL pública do Railway
$host = 'reseau.proxy.rlwy.net';
$port = '52631';
$dbname = 'railway';
$user = 'postgres';
$password = 'VkAcDmSLFrIVucYNadgxPezOYaaFZIZr';

// NÃO USAR AS VARIÁVEIS INTERNAS - usar apenas o host público fixo
// Removido o if que sobrescrevia as variáveis

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    error_log("Erro na conexão com o banco: " . $e->getMessage());
    die("Erro na conexão com o banco de dados. Por favor, tente novamente mais tarde.");
}
?>