<?php
session_start();
require "banco.php";

header('Content-Type: application/json');

if (!isset($_SESSION["usuario_id"])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$id_usuario = $_SESSION["usuario_id"];
$tipo = $_SESSION["usuario_tipo"] ?? null;

try {
    if ($tipo === "instituicao") {
        // CORREÇÃO: incluir 'PENDENTE_PIX' junto com 'AGENDADA'.
        // Antes só marcava coletas com status = 'AGENDADA', então doações
        // de DINHEIRO (que ficam como PENDENTE_PIX) nunca eram gravadas em
        // coletas_visualizadas pelo "marcar todas" — e por isso continuavam
        // sendo contadas para sempre pelo contar_notificacoes.php, mesmo
        // com a página de notificações já mostrando "Visualizada".
        $sql = "INSERT INTO coletas_visualizadas (id_doacao, id_ong, visualizada, data_visualizacao)
                SELECT d.id_doacao, ?, TRUE, NOW()
                FROM doacoes d
                JOIN coletas c ON d.id_doacao = c.id_doacao
                LEFT JOIN coletas_visualizadas cv
                    ON d.id_doacao = cv.id_doacao AND cv.id_ong = ?
                WHERE d.id_ong = ?
                  AND d.status IN ('AGENDADA', 'PENDENTE_PIX')
                  AND (cv.id_doacao IS NULL OR cv.visualizada = FALSE)
                ON CONFLICT (id_doacao, id_ong)
                DO UPDATE SET visualizada = TRUE, data_visualizacao = NOW()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_usuario, $id_usuario, $id_usuario]);

        echo json_encode(['success' => true, 'message' => 'Todas as coletas marcadas como visualizadas']);
    } else {
        $sql = "UPDATE notificacoes SET lida = TRUE WHERE id_usuario = ? AND lida = FALSE";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_usuario]);
        $total = $stmt->rowCount();

        echo json_encode(['success' => true, 'message' => "$total notificações marcadas como lidas"]);
    }
} catch (PDOException $e) {
    error_log("Erro ao marcar todas: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>