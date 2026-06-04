<?php
session_start();
require "banco.php";

if (!isset($_SESSION["usuario_id"]) || $_SESSION["usuario_tipo"] !== "instituicao") {
    header("Location: login.php");
    exit;
}

$id_ong = $_SESSION["usuario_id"];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header("Location: perfil-ong.php?msg=" . urlencode("ID inválido") . "&tipo=error");
    exit;
}

try {
    // Buscar imagem para excluir do servidor
    $stmt = $pdo->prepare("SELECT imagem FROM destino_doacoes WHERE id_destino = ? AND id_ong = ?");
    $stmt->execute([$id, $id_ong]);
    $destino = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$destino) {
        header("Location: perfil-ong.php?msg=" . urlencode("Publicação não encontrada") . "&tipo=error");
        exit;
    }
    
    // Excluir imagem se existir
    if (!empty($destino['imagem']) && file_exists("uploads/" . $destino['imagem'])) {
        unlink("uploads/" . $destino['imagem']);
    }
    
    // Excluir do banco
    $stmt = $pdo->prepare("DELETE FROM destino_doacoes WHERE id_destino = ? AND id_ong = ?");
    $stmt->execute([$id, $id_ong]);
    
    header("Location: perfil-ong.php?msg=" . urlencode("✅ Publicação excluída com sucesso!") . "&tipo=success");
    exit;
    
} catch (PDOException $e) {
    header("Location: perfil-ong.php?msg=" . urlencode("Erro ao excluir: " . $e->getMessage()) . "&tipo=error");
    exit;
}
?>