<?php
// Usando a URL pública do Railway
$host = 'reseau.proxy.rlwy.net';
$port = '52631';
$dbname = 'railway';
$user = 'postgres';
$password = 'VkAcDmSLFrIVucYNadgxPezOYaaFZIZr';

// Se as variáveis do Railway estiverem disponíveis, use-as
if (getenv('PGHOST')) {
    $host = getenv('PGHOST');
    $port = getenv('PGPORT');
    $dbname = getenv('PGDATABASE');
    $user = getenv('PGUSER');
    $password = getenv('PGPASSWORD');
}

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    echo "Conectado com sucesso!";
} catch (PDOException $e) {
    error_log("Erro na conexão com o banco: " . $e->getMessage());
    die("Erro na conexão com o banco de dados. Por favor, tente novamente mais tarde.");
}
?>