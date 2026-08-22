<?php
session_start();
require "banco.php";
require "cloudinary.php";

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION["usuario_tipo"] !== "instituicao") {
    header("Location: feed.php");
    exit;
}

$id_usuario = $_SESSION["usuario_id"];
$titulo     = trim($_POST["titulo"] ?? "");
$categoria  = trim($_POST["categoria"] ?? "");
$descricao  = trim($_POST["descricao"] ?? "");
$id_post    = (int)($_POST["id_post"] ?? 0);

// ─── Validação dos campos obrigatórios ──────────────────────────────────────
if (empty($titulo) || empty($categoria) || empty($descricao)) {
    $error_msg = urlencode("Preencha todos os campos obrigatórios.");
    header("Location: criar_post.php" . ($id_post > 0 ? "?id=$id_post&error=1&msg=$error_msg" : "?error=1&msg=$error_msg"));
    exit;
}

$permitidos = ["jpg", "jpeg", "png", "gif", "webp"];

// ─── EDIÇÃO de post existente ───────────────────────────────────────────────
if ($id_post > 0) {
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE id_post = ? AND id_usuario = ?");
    $stmt->execute([$id_post, $id_usuario]);
    $post_atual = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post_atual) {
        header("Location: perfil-ong.php");
        exit;
    }

    $imagemUrl = $post_atual['imagem'];

    if (!empty($_POST["remover_imagem"])) {
        $imagemUrl = null;
    }

    if (!empty($_FILES["imagem"]["name"])) {
        $ext = strtolower(pathinfo($_FILES["imagem"]["name"], PATHINFO_EXTENSION));
        if (in_array($ext, $permitidos)) {
            $novaUrl = uploadCloudinary($_FILES["imagem"]["tmp_name"]);
            if ($novaUrl) {
                $imagemUrl = $novaUrl;
            }
        }
    }

    $stmt = $pdo->prepare("UPDATE posts SET titulo = ?, categoria = ?, descricao = ?, imagem = ? WHERE id_post = ? AND id_usuario = ?");
    $stmt->execute([$titulo, $categoria, $descricao, $imagemUrl, $id_post, $id_usuario]);

    $success_msg = urlencode("Publicação atualizada com sucesso!");
    header("Location: perfil-ong.php?msg=$success_msg&tipo=success");
    exit;
}

// ─── CRIAÇÃO de novo post ────────────────────────────────────────────────────
$imagemUrl = null;

if (!empty($_FILES["imagem"]["name"])) {
    $ext = strtolower(pathinfo($_FILES["imagem"]["name"], PATHINFO_EXTENSION));
    if (in_array($ext, $permitidos)) {
        $imagemUrl = uploadCloudinary($_FILES["imagem"]["tmp_name"]);
    }
}

$stmt = $pdo->prepare("INSERT INTO posts (id_usuario, titulo, categoria, descricao, imagem) VALUES (:id, :titulo, :categoria, :descricao, :imagem)");
$stmt->execute([":id" => $id_usuario, ":titulo" => $titulo, ":categoria" => $categoria, ":descricao" => $descricao, ":imagem" => $imagemUrl]);

$success_msg = urlencode("Publicação criada com sucesso!");
header("Location: feed.php?msg=$success_msg&tipo=success");
exit;