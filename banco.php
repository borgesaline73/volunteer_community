<?php
// Pegando as variáveis do Railway
$host = getenv('PGHOST') ?: 'localhost';
$port = getenv('PGPORT') ?: '5432';
$dbname = getenv('PGDATABASE') ?: 'projeto_volunteer_community';
$user = getenv('PGUSER') ?: 'postgres';
$password = getenv('PGPASSWORD') ?: '1234';

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