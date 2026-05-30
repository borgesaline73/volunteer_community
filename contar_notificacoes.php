<?php
session_start();
require "banco.php";

header('Content-Type: application/json');

if (!isset($_SESSION["usuario_id"])) {
    echo json_encode(['total' => 0]);
    exit;
}

$id_usuario = $_SESSION["usuario_id"];
$tipo = $_SESSION["usuario_tipo"] ?? null;

try {
    if ($tipo === "instituicao") {
        // Para ONGs: contar coletas não visualizadas
        $sql = "SELECT COUNT(DISTINCT d.id_doacao) as total 
                FROM doacoes d 
                JOIN coletas c ON d.id_doacao = c.id_doacao
                LEFT JOIN coletas_visualizadas cv ON d.id_doacao = cv.id_doacao AND cv.id_ong = ?
                WHERE d.id_ong = ? 
                AND d.status = 'AGENDADA'
                AND (cv.visualizada IS NULL OR cv.visualizada = FALSE)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_usuario, $id_usuario]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = (int)($result['total'] ?? 0);
    } else {
        // Para doadores: contar notificações não lidas
        $sql = "SELECT COUNT(*) as total FROM notificacoes 
                WHERE id_usuario = ? AND lida = FALSE";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_usuario]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = (int)($result['total'] ?? 0);
    }
    
    echo json_encode(['total' => $total]);
} catch (PDOException $e) {
    error_log("Erro ao contar notificações: " . $e->getMessage());
    echo json_encode(['total' => 0]);
}
?>