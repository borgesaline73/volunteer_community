<?php
// Lê a connection string do Neon a partir da variável de ambiente DATABASE_URL.
// Formato esperado (copie direto do painel do Neon, "Connection string"):
// postgresql://usuario:senha@ep-xxxxx.sa-east-1.aws.neon.tech/nome_do_banco?sslmode=require
$database_url = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? null);

if ($database_url) {
    // ===== Produção (Render + Neon) =====
    $parts = parse_url($database_url);

    $host   = $parts['host'] ?? '';
    $port   = $parts['port'] ?? 5432;
    $dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
    $user   = $parts['user'] ?? '';
    $pass   = $parts['pass'] ?? '';

    // Query string original (ex: sslmode=require) — o Neon exige SSL.
    parse_str($parts['query'] ?? '', $query_params);
    $sslmode = $query_params['sslmode'] ?? 'require';

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode";
} else {
    // ===== Desenvolvimento local =====
    $host   = "localhost";
    $dbname = "projeto_volunteer_community";
    $user   = "postgres";
    $pass   = "1234";

    $dsn = "pgsql:host=$host;dbname=$dbname";
}

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    error_log("Erro na conexão com o banco: " . $e->getMessage());
    die("Erro na conexão com o banco de dados. Por favor, tente novamente mais tarde.");
}
?>