<?php
session_start();
require "banco.php";

header('Content-Type: application/json');

// Verificar se usuário está logado
if (!isset($_SESSION["usuario_id"])) {
    echo json_encode(['novas' => [], 'timestamp_atual' => time()]);
    exit;
}

$id_usuario = $_SESSION["usuario_id"];
$tipo = $_SESSION["usuario_tipo"] ?? null;
$ultima_verificacao = isset($_GET['ultima_verificacao']) ? (int)$_GET['ultima_verificacao'] : time();

$novas_notificacoes = [];

try {
    if ($tipo === "instituicao") {
        // Para INSTITUIÇÕES: Buscar coletas agendadas não visualizadas
        $sql = "SELECT d.*, u.nome as nome_doador, u.email as email_doador,
                       c.data_agendada, c.endereco as local_coleta,
                       cv.visualizada as ja_visualizada
                FROM doacoes d 
                JOIN usuarios u ON d.id_doador = u.id_usuario 
                JOIN coletas c ON d.id_doacao = c.id_doacao
                LEFT JOIN coletas_visualizadas cv ON d.id_doacao = cv.id_doacao AND cv.id_ong = ?
                WHERE d.id_ong = ? 
                AND d.status = 'AGENDADA'
                AND (cv.visualizada IS NULL OR cv.visualizada = FALSE)
                ORDER BY c.data_agendada ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_usuario, $id_usuario]);
        $coletas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($coletas as $coleta) {
            $novas_notificacoes[] = [
                'id' => 'coleta_' . $coleta['id_doacao'],
                'mensagem' => $coleta['nome_doador'] . ' agendou uma coleta de ' . $coleta['tipo'] . 
                             ' para ' . date('d/m H:i', strtotime($coleta['data_agendada'])) . 
                             ' no local: ' . $coleta['local_coleta'],
                'data_envio' => $coleta['data_agendada'],
                'tipo' => 'COLETA_AGENDADA'
            ];
        }
    } else {
        // Para DOADORES: Buscar notificações não lidas
        $sql = "SELECT * FROM notificacoes 
                WHERE id_usuario = ? AND lida = FALSE
                ORDER BY data_envio DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_usuario]);
        $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($notificacoes as $notif) {
            $novas_notificacoes[] = [
                'id' => $notif['id_notificacao'],
                'mensagem' => $notif['mensagem'],
                'data_envio' => $notif['data_envio'],
                'tipo' => $notif['tipo'] ?? null
            ];
        }
    }
    
    echo json_encode([
        'novas' => $novas_notificacoes,
        'timestamp_atual' => time()
    ]);
    
} catch (PDOException $e) {
    error_log("Erro ao verificar notificações: " . $e->getMessage());
    echo json_encode([
        'novas' => [],
        'timestamp_atual' => time(),
        'error' => $e->getMessage()
    ]);
}
?>