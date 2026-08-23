<?php
session_start();
require "banco.php";
require "cloudinary.php";

if (!isset($_SESSION["usuario_id"]) || $_SESSION["usuario_tipo"] !== "instituicao") {
    header("Location: login.php");
    exit;
}

$id_ong = $_SESSION["usuario_id"];
$id_destino = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$modo_edicao = ($id_destino > 0);
$titulo_pagina = $modo_edicao ? "Editar Publicação" : "Nova Publicação";
$botao_texto = $modo_edicao ? "💾 Salvar Alterações" : "📢 Publicar";

$dados = ['titulo' => '', 'descricao' => '', 'imagem' => ''];

if ($modo_edicao) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM destino_doacoes WHERE id_destino = ? AND id_ong = ?");
        $stmt->execute([$id_destino, $id_ong]);
        $dados = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dados) {
            header("Location: perfil-ong.php?msg=" . urlencode("Publicação não encontrada") . "&tipo=error");
            exit;
        }
    } catch (PDOException $e) {
        header("Location: perfil-ong.php?msg=" . urlencode("Erro ao carregar dados") . "&tipo=error");
        exit;
    }
}

$erro = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $titulo = trim($_POST["titulo"] ?? "");
    $descricao = trim($_POST["descricao"] ?? "");
    $imagem_atual = $dados['imagem'] ?? '';
    $imagem_nova = $imagem_atual;

    if (empty($titulo) || empty($descricao)) {
        $erro = "Preencha todos os campos obrigatórios.";
    } else {
        // Upload de nova imagem (Cloudinary)
        if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
            $extensoes_validas = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (!in_array($ext, $extensoes_validas)) {
                $erro = "Formato de imagem inválido. Use JPG, PNG, GIF ou WEBP.";
            } else {
                $urlImagem = uploadCloudinary($_FILES['imagem']['tmp_name']);
                if ($urlImagem) {
                    $imagem_nova = $urlImagem;
                } else {
                    $erro = "Não foi possível enviar a imagem. Tente novamente.";
                }
            }
        }

        if (empty($erro)) {
            try {
                if ($modo_edicao) {
                    $stmt = $pdo->prepare("UPDATE destino_doacoes SET titulo = ?, descricao = ?, imagem = ? WHERE id_destino = ? AND id_ong = ?");
                    $stmt->execute([$titulo, $descricao, $imagem_nova, $id_destino, $id_ong]);
                    $msg_sucesso = "✅ Publicação atualizada com sucesso!";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO destino_doacoes (id_ong, titulo, descricao, imagem, criado_em) VALUES (?, ?, ?, ?, NOW())");
                    $stmt->execute([$id_ong, $titulo, $descricao, $imagem_nova]);
                    $msg_sucesso = "✅ Publicação criada com sucesso!";
                }

                header("Location: perfil-ong.php?msg=" . urlencode($msg_sucesso) . "&tipo=success");
                exit;
            } catch (PDOException $e) {
                $erro = "Erro ao salvar: " . $e->getMessage();
            }
        }
    }
}

// Buscar notificações para o menu (esta tela é exclusiva de instituições)
$total_notificacoes = 0;
try {
    $stmt_notif = $pdo->prepare("
        SELECT COUNT(DISTINCT d.id_doacao) as total
        FROM doacoes d
        JOIN coletas c ON d.id_doacao = c.id_doacao
        LEFT JOIN coletas_visualizadas cv
            ON d.id_doacao = cv.id_doacao AND cv.id_ong = ?
        WHERE d.id_ong = ?
        AND d.status IN ('AGENDADA', 'PENDENTE_PIX')
        AND (cv.id_doacao IS NULL OR cv.visualizada = FALSE)
    ");
    $stmt_notif->execute([$id_ong, $id_ong]);
    $total_notificacoes = (int)($stmt_notif->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $titulo_pagina ?> - Volunteer Community</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/estilo_global.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<?php include 'google_analytics.php'; ?>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: #f6f4f2; display: flex; justify-content: center; align-items: center; min-height: 100vh; min-height: 100dvh; padding: 20px; font-family: 'Poppins', sans-serif; }
.phone { width: 100%; max-width: 430px; background: #fff; height: 90vh; height: 90dvh; max-height: 800px; border-radius: 32px; box-shadow: 0 10px 40px rgba(0,0,0,0.06); display: flex; flex-direction: column; overflow: hidden; }
.header { padding: 18px 20px 10px; display: flex; align-items: center; justify-content: space-between; background: #fff; border-bottom: 1px solid #f0f0f0; }
.header-title { flex: 1; text-align: center; font-weight: 600; font-size: 16px; }
.main-content { flex: 1; overflow-y: auto; padding: 20px; }
.form-card { background: #fff; border-radius: 24px; padding: 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); margin-bottom: 20px; }
.form-card h2 { font-size: 20px; margin-bottom: 20px; color: #2b2b2b; }
.field { margin-bottom: 20px; }
.field label { display: block; font-size: 13px; font-weight: 600; color: #555; margin-bottom: 8px; }
.field input, .field textarea { width: 100%; padding: 12px 16px; border: 1.5px solid #e0e0e0; border-radius: 16px; font-family: 'Poppins', sans-serif; font-size: 14px; box-sizing: border-box; }
.field textarea { min-height: 120px; resize: vertical; }
.field input:focus, .field textarea:focus { outline: none; border-color: #f4822f; }
.imagem-preview { margin: 15px 0; text-align: center; }
.imagem-preview img { max-width: 100%; max-height: 200px; border-radius: 16px; }
.btn-salvar { background: linear-gradient(135deg, #f4822f, #ff6b2c); color: white; border: none; border-radius: 50px; padding: 14px 24px; font-weight: 700; font-size: 16px; cursor: pointer; width: 100%; }
.btn-cancelar { background: transparent; color: #666; border: 1.5px solid #ddd; border-radius: 50px; padding: 12px 24px; font-weight: 600; cursor: pointer; width: 100%; margin-top: 12px; }
.erro { background: #f8d7da; color: #721c24; border-radius: 12px; padding: 12px; margin-bottom: 20px; font-size: 13px; }
.file-label { display: block; border: 1px dashed #ddd; border-radius: 12px; padding: 12px 14px; font-size: 13px; color: #888; cursor: pointer; text-align: center; }
input[type=file] { display: none; }
#nome-arquivo { font-size: 12px; color: #f4822f; margin-top: 4px; }
.bottom { min-height: 74px; border-top: 1px solid #eee; display: flex; align-items: center; justify-content: center; gap: 40px; background: #fff; position: absolute; bottom: 0; left: 0; right: 0; padding-bottom: env(safe-area-inset-bottom); }
.menu-item { text-decoration: none; font-size: 11px; color: #aaa; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 2px; position: relative; }
.plus-btn { width: 52px; height: 52px; border-radius: 50%; background: #f4822f; color: #fff; font-size: 28px; border: none; margin-top: -30px; cursor: pointer; }
.notification-badge { position: absolute; top: -5px; right: -8px; background: #ff4444; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; display: flex; align-items: center; justify-content: center; }

/* ===== FIX iOS SAFARI: usa a altura real do viewport (position:fixed)
   ao invés de vh/dvh, evitando o bug em que a barra de endereço some
   e empurra o menu inferior pra fora da área visível ===== */
@media (max-width: 480px) {
  body {
    padding: 0 !important;
    align-items: stretch !important;
    overflow: hidden !important;
  }

  .phone {
    position: fixed !important;
    inset: 0 !important;
    width: 100% !important;
    height: auto !important;
    max-height: none !important;
    border-radius: 0 !important;
    box-shadow: none !important;
  }

  .bottom {
    padding-bottom: env(safe-area-inset-bottom) !important;
    gap: 30px !important;
  }

  .menu-item {
    font-size: 11px !important;
  }
}
</style>
</head>
<body>
<div class="phone">
  <div class="header">
    <span onclick="history.back()" style="cursor:pointer;">←</span>
    <div class="header-title"><?= $titulo_pagina ?></div>
    <span onclick="location.href='logout.php'" style="cursor:pointer;">🚪</span>
  </div>
  
  <div class="main-content">
    <div class="form-card">
      <h2><?= $modo_edicao ? '✏️ Editar Publicação' : '💛 Nova Publicação' ?></h2>
      
      <?php if ($erro): ?>
        <div class="erro"><?= htmlspecialchars($erro) ?></div>
      <?php endif; ?>
      
      <form method="POST" enctype="multipart/form-data">
        <div class="field">
          <label>Título *</label>
          <input type="text" name="titulo" required value="<?= htmlspecialchars($dados['titulo']) ?>" placeholder="Ex: Nossa campanha de inverno">
        </div>
        
        <div class="field">
          <label>Descrição *</label>
          <textarea name="descricao" required placeholder="Conte aos doadores como as doações estão sendo utilizadas..."><?= htmlspecialchars($dados['descricao']) ?></textarea>
        </div>
        
        <div class="field">
          <label>Imagem (opcional)</label>
          <label class="file-label" for="imagem-input">📷 Clique para adicionar uma foto</label>
          <input type="file" id="imagem-input" name="imagem" accept="image/*" onchange="mostrarNome(this)">
          <div id="nome-arquivo"></div>
        </div>
        
        <?php if (!empty($dados['imagem'])): ?>
          <div class="imagem-preview">
            <img src="<?= htmlspecialchars($dados['imagem']) ?>" alt="Imagem atual">
            <small>Imagem atual</small>
          </div>
        <?php endif; ?>
        
        <button type="submit" class="btn-salvar"><?= $botao_texto ?></button>
        <button type="button" class="btn-cancelar" onclick="window.location.href='perfil-ong.php'">Cancelar</button>
      </form>
    </div>
  </div>
  
  <div class="bottom">
    <a href="feed.php" class="menu-item">🏠<span>Feed</span></a>
    <a href="campanhas.php" class="menu-item">📢<span>Campanhas</span></a>
    <button class="plus-btn" onclick="window.location.href='criar_post.php'">+</button>
    <a href="notificacoes.php" class="menu-item">🔔<span>Notificações</span><?php if ($total_notificacoes > 0): ?><span class="notification-badge"><?= $total_notificacoes ?></span><?php endif; ?></a>
    <a href="perfil-ong.php" class="menu-item" style="color:#f4822f;">👤<span>Perfil</span></a>
  </div>
</div>
<script>
function mostrarNome(input) {
  document.getElementById('nome-arquivo').textContent = input.files[0]?.name || '';
}
</script>
</body>
</html>