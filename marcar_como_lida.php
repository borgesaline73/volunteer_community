<?php
session_start();
require "banco.php";

header('Content-Type: application/json');

if (!isset($_SESSION["usuario_id"])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$id_usuario = $_SESSION["usuario_id"];
$id_doacao = $_POST['id_doacao'] ?? null;

if (!$id_doacao) {
    echo json_encode(['success' => false, 'error' => 'ID da doação não fornecido']);
    exit;
}

try {
    // MySQL usa INSERT ... ON DUPLICATE KEY UPDATE
    $sql = "INSERT INTO coletas_visualizadas (id_doacao, id_ong, visualizada, visualizada_em)
            VALUES (?, ?, TRUE, NOW())
            ON DUPLICATE KEY UPDATE visualizada = TRUE, visualizada_em = NOW()";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_doacao, $id_usuario]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Erro ao marcar coleta como visualizada: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>