<?php
session_start();
require "banco.php";

header('Content-Type: application/json');

// Verificar se usuário está logado
if (!isset($_SESSION["usuario_id"])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$id_usuario = $_SESSION["usuario_id"];
$tipo = $_SESSION["usuario_tipo"] ?? null;

try {
    if ($tipo === "instituicao") {
        // Para instituições: marcar todas as coletas como visualizadas
        $sql = "INSERT INTO coletas_visualizadas (id_doacao, id_ong, visualizada, visualizada_em)
                SELECT d.id_doacao, ?, TRUE, NOW()
                FROM doacoes d
                JOIN coletas c ON d.id_doacao = c.id_doacao
                LEFT JOIN coletas_visualizadas cv ON d.id_doacao = cv.id_doacao AND cv.id_ong = ?
                WHERE d.id_ong = ? 
                AND d.status = 'AGENDADA'
                AND (cv.visualizada IS NULL OR cv.visualizada = FALSE)
                ON CONFLICT (id_doacao, id_ong) DO UPDATE 
                SET visualizada = TRUE, visualizada_em = NOW()";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_usuario, $id_usuario, $id_usuario]);
        
        echo json_encode(['success' => true, 'message' => 'Todas as coletas marcadas como visualizadas']);
    } else {
        // Para doadores: marcar todas as notificações como lidas
        $sql = "UPDATE notificacoes SET lida = TRUE WHERE id_usuario = ? AND lida = FALSE";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_usuario]);
        
        $total_afetadas = $stmt->rowCount();
        echo json_encode(['success' => true, 'message' => "$total_afetadas notificações marcadas como lidas"]);
    }
} catch (PDOException $e) {
    error_log("Erro ao marcar todas as notificações: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>