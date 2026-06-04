<?php
session_start();
require "banco.php";

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

$tipo = $_SESSION["usuario_tipo"] ?? null;
if ($tipo !== "instituicao") {
    header("Location: perfil.php");
    exit;
}

$id_ong = $_SESSION["usuario_id"];

// ===== CAPTURAR MENSAGEM DE SUCESSO DA URL =====
$mensagem_flash = '';
$tipo_flash = '';
if (isset($_GET['msg']) && isset($_GET['tipo'])) {
    $mensagem_flash = urldecode($_GET['msg']);
    $tipo_flash = $_GET['tipo'];
}

// Buscar chave PIX e WhatsApp da ONG
$chave_pix = null;
$whatsapp = null;
try {
    $stmt = $pdo->prepare("SELECT chave_pix, whatsapp FROM ongs WHERE id_ong = ?");
    $stmt->execute([$id_ong]);
    $result    = $stmt->fetch(PDO::FETCH_ASSOC);
    $chave_pix = $result['chave_pix'] ?? null;
    $whatsapp  = $result['whatsapp'] ?? null;
} catch (PDOException $e) {
    error_log("Erro ao buscar chave PIX/WhatsApp: " . $e->getMessage());
}

// ===== BUSCAR DADOS DO USUÁRIO =====
try {
    $stmt_ong = $pdo->prepare("SELECT nome, email, tipo_usuario, cpf_cnpj, verificada, verificacao_status
                               FROM usuarios WHERE id_usuario = ?");
    $stmt_ong->execute([$id_ong]);
    $ong = $stmt_ong->fetch(PDO::FETCH_ASSOC);

    if (!$ong) throw new Exception("ONG não encontrada");

    $nome               = $ong['nome']               ?? "Instituição";
    $email              = $ong['email']              ?? "";
    $tipo_usuario       = $ong['tipo_usuario']       ?? "instituicao";
    $cnpj               = $ong['cpf_cnpj']           ?? "";
    $verificada         = $ong['verificada']         ?? false;
    $verificacao_status = $ong['verificacao_status'] ?? 'pendente';

} catch (Exception $e) {
    $nome = $email = "Erro";
    $verificada = false;
    $verificacao_status = 'pendente';
    error_log("Erro ao buscar usuário: " . $e->getMessage());
}

// ===== BUSCAR POSTS =====
$posts = [];
try {
    $stmt_posts = $pdo->prepare("SELECT p.*, u.nome, u.id_usuario as id_ong
                                 FROM posts p JOIN usuarios u ON p.id_usuario = u.id_usuario
                                 WHERE p.id_usuario = ? ORDER BY p.data_post DESC");
    $stmt_posts->execute([$id_ong]);
    $posts = $stmt_posts->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log("Erro posts: " . $e->getMessage()); }

// ===== BUSCAR COLETAS =====
$coletas = [];
try {
    $stmt_coletas = $pdo->prepare("
        SELECT d.id_doacao, d.tipo, d.status, d.descricao_item, d.valor,
               u.nome as nome_doador, u.email as email_doador,
               c.data_agendada, c.endereco as local_coleta,
               CASE WHEN d.tipo='ITEM' THEN 'Doação de Itens' WHEN d.tipo='DINHEIRO' THEN 'Doação em Dinheiro' ELSE d.tipo END as tipo_formatado,
               CASE WHEN d.status='AGENDADA' THEN 'Coleta Agendada'
                    WHEN d.status='RECEBIDA' THEN 'Coleta Recebida'
                    WHEN d.status='PENDENTE_PIX' THEN 'PIX Pendente'
                    ELSE d.status END as status_formatado
        FROM doacoes d
        JOIN usuarios u ON d.id_doador = u.id_usuario
        JOIN coletas c ON d.id_doacao = c.id_doacao
        WHERE d.id_ong = ?
        ORDER BY CASE WHEN d.status='AGENDADA' THEN 1
                      WHEN d.status='PENDENTE_PIX' THEN 2
                      ELSE 3 END,
                 c.data_agendada ASC");
    $stmt_coletas->execute([$id_ong]);
    $coletas = $stmt_coletas->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log("Erro coletas: " . $e->getMessage()); }

// ===== BUSCAR ITENS =====
$itens_aceitos = $itens_recusados = [];
try {
    $stmt_itens = $pdo->prepare("SELECT id_item, id_ong, nome, tipo FROM itens_ong WHERE id_ong = ? ORDER BY tipo, nome ASC");
    $stmt_itens->execute([$id_ong]);
    $itens           = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);
    $itens_aceitos   = array_filter($itens, fn($i) => $i['tipo'] === 'ACEITO');
    $itens_recusados = array_filter($itens, fn($i) => $i['tipo'] === 'RECUSADO');
} catch (PDOException $e) { error_log("Erro itens: " . $e->getMessage()); }

// ===== BUSCAR DESTINOS =====
$destinos = [];
try {
    $stmt_destinos = $pdo->prepare("SELECT * FROM destino_doacoes WHERE id_ong = ? ORDER BY criado_em DESC");
    $stmt_destinos->execute([$id_ong]);
    $destinos = $stmt_destinos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log("Erro destinos: " . $e->getMessage()); }

// ===== BUSCAR NOTIFICAÇÕES =====
$total_notificacoes = 0;
try {
    $stmt_notif = $pdo->prepare("SELECT COUNT(*) as total FROM notificacoes WHERE id_usuario = ? AND lida = FALSE");
    $stmt_notif->execute([$id_ong]);
    $total_notificacoes = $stmt_notif->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
} catch (PDOException $e) { error_log("Erro notif: " . $e->getMessage()); }

$rotaPlus   = "criar_post.php";
$rotaPerfil = "perfil-ong.php";
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Perfil da ONG - Volunteer Community</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/estilo_global.css">
<link rel="stylesheet" href="css/estilo_perfil_ong.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
  .phone { position: relative; overflow: hidden; }

  .swal2-container.swal-inside-phone {
    position: absolute !important; top: 0 !important; left: 0 !important;
    width: 100% !important; height: 100% !important; z-index: 9999;
  }
  .swal2-container.swal-inside-phone .swal2-popup {
    width: 88% !important; max-width: 340px !important;
    border-radius: 16px !important; font-family: 'Poppins', sans-serif !important;
  }

  .verified-section {
    margin: 10px 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
  }

  .verified-badge-ong {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    padding: 6px 16px;
    border-radius: 20px;
    cursor: pointer;
    transition: transform 0.2s;
  }
  .verified-badge-ong:hover { transform: scale(1.03); }
  .verified-badge-ong.aprovada  { background: linear-gradient(135deg, #d4edda, #b8e0c4); color: #155724; border: 1.5px solid #a8d5b0; }
  .verified-badge-ong.pendente  { background: #fff3cd; color: #856404; border: 1.5px solid #ffd877; }
  .verified-badge-ong.rejeitada { background: #f8d7da; color: #721c24; border: 1.5px solid #f5b8b3; }

  .check-icon {
    width: 18px; height: 18px;
    background: #28a745;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 11px;
    font-weight: 700;
  }

  .verificar-link {
    font-size: 11px;
    color: #f4822f;
    text-decoration: underline;
    cursor: pointer;
    background: none;
    border: none;
    font-family: 'Poppins', sans-serif;
  }

  .btn-pix {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white; border: none; border-radius: 12px;
    padding: 12px 20px; margin-top: 15px; cursor: pointer;
    font-weight: 600; font-size: 14px;
    display: flex; align-items: center; justify-content: center;
    gap: 10px; width: 100%;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
  }
  .btn-pix:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(102,126,234,0.3); }

  .btn-whatsapp {
    background: #25D366; color: white; border: none; border-radius: 12px;
    padding: 12px 20px; margin-top: 10px; cursor: pointer;
    font-weight: 600; font-size: 14px;
    display: flex; align-items: center; justify-content: center;
    gap: 10px; width: 100%;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    text-decoration: none;
  }
  .btn-whatsapp:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(37,211,102,0.3); }

  .pix-status { font-size: 12px; margin-top: 10px; padding: 8px 12px; border-radius: 8px; text-align: center; }
  .pix-status.cadastrada     { background: #d4edda; color: #155724; }
  .pix-status.nao-cadastrada { background: #fff3cd; color: #856404; }
  .pix-key-preview { font-family: monospace; word-break: break-all; font-size: 11px; margin-top: 4px; color: #666; }

  .btn-confirmar-pix {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 8px;
    width: 100%;
    font-family: 'Poppins', sans-serif;
    transition: all 0.3s;
  }
  .btn-confirmar-pix:hover { opacity: 0.9; transform: translateY(-1px); }

  .status-pix-pendente {
    background: #ede7f6;
    color: #5e35b1;
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    margin-top: 8px;
  }
  
  .info-item a {
    color: #25D366;
    text-decoration: none;
    font-weight: 600;
  }

  /* Estilos do carrossel de destino */
  .destino-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 20px 0 10px;
  }
  
  .destino-carousel {
    flex: 1;
    overflow: hidden;
    border-radius: 20px;
  }
  
  .destino-slide {
    display: none;
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    overflow: hidden;
  }
  
  .destino-slide.active {
    display: block;
  }
  
  .destino-img {
    width: 100%;
    height: 180px;
    object-fit: cover;
  }
  
  .destino-body {
    padding: 16px;
  }
  
  .destino-titulo {
    font-weight: 700;
    font-size: 16px;
    color: #2b2b2b;
    margin-bottom: 6px;
  }
  
  .destino-data {
    font-size: 11px;
    color: #999;
    margin-bottom: 10px;
  }
  
  .destino-descricao {
    font-size: 13px;
    color: #555;
    line-height: 1.5;
  }
  
  .destino-nav {
    background: #fff;
    border: 1.5px solid #e0e0e0;
    border-radius: 50%;
    width: 36px;
    height: 36px;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    flex-shrink: 0;
  }
  
  .destino-nav:hover {
    background: #f4822f;
    border-color: #f4822f;
    color: white;
  }
  
  .destino-nav:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }
  
  .destino-dots {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin: 12px 0;
  }
  
  .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #ccc;
    cursor: pointer;
    transition: all 0.2s;
  }
  
  .dot.active {
    background: #f4822f;
    width: 20px;
    border-radius: 10px;
  }
  
  .destino-counter {
    text-align: center;
    font-size: 12px;
    color: #999;
    margin-top: 5px;
  }
  
  .destino-acoes {
    display: flex;
    gap: 10px;
    margin-top: 16px;
    justify-content: center;
  }
  
  .btn-editar-destino {
    flex: 1;
    text-align: center;
    padding: 7px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    background: #e8f4fd;
    color: #2980b9;
    font-family: 'Poppins', sans-serif;
  }
  
  .btn-excluir-destino {
    flex: 1;
    padding: 7px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    background: #fdecea;
    color: #c0392b;
    font-family: 'Poppins', sans-serif;
  }
  
  .btn-editar-destino:hover {
    background: #d4eaf7;
    transform: translateY(-1px);
  }
  
  .btn-excluir-destino:hover {
    background: #fce4e1;
    transform: translateY(-1px);
  }
</style>
</head>
<body>

<div class="phone" id="phoneWrapper">

  <div class="header">
    <span onclick="history.back()" style="cursor:pointer;">⬅</span>
    <div class="header-title">Perfil da ONG</div>
    <span style="cursor:pointer;" onclick="window.location='logout.php'">🚪</span>
  </div>

  <div class="main-content">

    <!-- CARD PERFIL -->
    <div class="profile-card">
      <div class="avatar">🏢</div>
      <div class="name"><?= htmlspecialchars($nome) ?></div>
      <div class="info-item"><strong>Email:</strong> <?= htmlspecialchars($email) ?></div>
      <?php if (!empty($cnpj)): ?>
        <div class="info-item"><strong>CNPJ:</strong> <?= htmlspecialchars($cnpj) ?></div>
      <?php endif; ?>
      <?php if (!empty($whatsapp)): ?>
        <div class="info-item">
          <strong>WhatsApp:</strong> 
          <a href="https://wa.me/55<?= preg_replace('/\D/', '', $whatsapp) ?>" target="_blank">
            <?= htmlspecialchars($whatsapp) ?> 💬
          </a>
        </div>
      <?php endif; ?>
      <div class="info-item"><strong>Tipo:</strong> Instituição</div>

      <div class="verified-section">
        <?php if ($verificada && $verificacao_status === 'aprovada'): ?>
          <div class="verified-badge-ong aprovada" onclick="mostrarInfoVerificacao()">
            <span class="check-icon">✓</span>ONG Verificada
          </div>
        <?php elseif ($verificacao_status === 'rejeitada'): ?>
          <div class="verified-badge-ong rejeitada">❌ Verificação não aprovada</div>
          <button class="verificar-link" onclick="mostrarInfoVerificacao()">Saiba mais</button>
        <?php else: ?>
          <div class="verified-badge-ong pendente">⏳ Verificação em análise</div>
          <button class="verificar-link" onclick="mostrarInfoVerificacao()">O que é isso?</button>
        <?php endif; ?>
      </div>

      <div class="pix-status <?= !empty($chave_pix) ? 'cadastrada' : 'nao-cadastrada' ?>">
        <?php if (!empty($chave_pix)): ?>
          💜 Chave PIX cadastrada
          <div class="pix-key-preview"><?= htmlspecialchars(substr($chave_pix, 0, 20)) . (strlen($chave_pix) > 20 ? '...' : '') ?></div>
        <?php else: ?>
          ⚠️ Nenhuma chave PIX cadastrada
        <?php endif; ?>
      </div>

      <button class="btn-pix" onclick="window.location.href='gerenciar_pix.php'">
        💜 Gerenciar Chave PIX
      </button>
      
      <?php if (empty($whatsapp)): ?>
        <button class="btn-whatsapp" onclick="window.location.href='editar_whatsapp.php'">
          💬 Cadastrar WhatsApp
        </button>
      <?php else: ?>
        <a href="https://wa.me/55<?= preg_replace('/\D/', '', $whatsapp) ?>?text=Olá! Sou doador do Volunteer Community e gostaria de mais informações." 
           target="_blank" 
           class="btn-whatsapp" 
           style="display: block; text-align: center;">
          💬 Testar Meu WhatsApp
        </a>
      <?php endif; ?>
    </div>

    <!-- MENU DE ABAS -->
    <div class="tab-menu">
      <div class="tab active" data-tab="posts">Conexão Solidária</div>
      <div class="tab" data-tab="itens">Itens Aceitos e Não aceitos</div>
      <div class="tab" data-tab="destino">Sua Doação Importa!</div>
      <div class="tab" data-tab="coletas">Coletas Agendadas</div>
    </div>

    <!-- ========== ABA POSTS ========== -->
    <div class="tab-content active" id="posts-tab">
      <div class="section">
        <span>Meus Posts</span>
        <span class="section-count blue"><?= count($posts) ?></span>
      </div>
      <?php if (!empty($posts)): ?>
        <div class="coletas-list">
          <?php foreach ($posts as $post): ?>
            <div class="post-card">
              <h3><?= htmlspecialchars($post['titulo']) ?></h3>
              <div class="post-meta">
                Publicado por <strong><?= htmlspecialchars($post['nome']) ?></strong> •
                <?= date("d/m/Y \à\s H:i", strtotime($post['data_post'])) ?>
              </div>
              <?php if (!empty($post['categoria'])): ?>
                <span class="categoria-badge"><?= htmlspecialchars($post['categoria']) ?></span>
              <?php endif; ?>
              <div class="post-content"><?= nl2br(htmlspecialchars($post['descricao'])) ?></div>
              <?php if (!empty($post['imagem'])): ?>
                <img src="uploads/<?= $post['imagem'] ?>" class="post-image"
                     alt="<?= htmlspecialchars($post['titulo']) ?>" onerror="this.style.display='none'">
              <?php endif; ?>
              <div class="post-acoes">
                <a href="criar_post.php?id=<?= $post['id_post'] ?>" class="btn-editar-post">✏️ Editar</a>
                <button class="btn-excluir-post" onclick="excluirPost(<?= $post['id_post'] ?>)">🗑️ Excluir</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty">
          <strong>📝 Nenhum post ainda</strong>
          <small>Use o botão "+" para criar seu primeiro post</small>
        </div>
      <?php endif; ?>
    </div>

    <!-- ========== ABA ITENS ========== -->
    <div class="tab-content" id="itens-tab">
      <div class="section">
        <span>✅ Itens Aceitos</span>
        <button class="btn-add-item" onclick="abrirModal('ACEITO')">+ Adicionar</button>
      </div>
      <div class="itens-grid" id="lista-aceitos">
        <?php if (empty($itens_aceitos)): ?>
          <p class="empty-itens" id="empty-aceitos">Nenhum item cadastrado ainda.</p>
        <?php else: ?>
          <?php foreach ($itens_aceitos as $item): ?>
            <?php if (isset($item['id_item']) && isset($item['nome'])): ?>
              <div class="item-tag aceito" id="item-<?= $item['id_item'] ?>">
                <?= htmlspecialchars($item['nome']) ?>
                <span class="remove-item" onclick="removerItem(<?= $item['id_item'] ?>, 'ACEITO')">✕</span>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="section" style="margin-top:16px;">
        <span>❌ Itens Não Aceitos</span>
        <button class="btn-add-item recusado" onclick="abrirModal('RECUSADO')">+ Adicionar</button>
      </div>
      <div class="itens-grid" id="lista-recusados">
        <?php if (empty($itens_recusados)): ?>
          <p class="empty-itens" id="empty-recusados">Nenhum item cadastrado ainda.</p>
        <?php else: ?>
          <?php foreach ($itens_recusados as $item): ?>
            <?php if (isset($item['id_item']) && isset($item['nome'])): ?>
              <div class="item-tag recusado" id="item-<?= $item['id_item'] ?>">
                <?= htmlspecialchars($item['nome']) ?>
                <span class="remove-item" onclick="removerItem(<?= $item['id_item'] ?>, 'RECUSADO')">✕</span>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ========== ABA SUA DOAÇÃO IMPORTA ========== -->
    <div class="tab-content" id="destino-tab">
      <div class="section">
        <span>📢 Para onde vai sua doação</span>
        <button class="btn-add-item" onclick="window.location.href='criar_destino.php'">+ Publicar</button>
      </div>
      
      <?php if (empty($destinos)): ?>
        <div class="empty">
          <strong>💛 Nenhuma publicação ainda</strong>
          <small>Conte aos doadores o impacto das contribuições deles!</small>
        </div>
      <?php else: ?>
        <div class="destino-wrapper">
          <button class="destino-nav" id="btnPrevDestino" onclick="navegarDestino(-1)">&#8592;</button>
          <div class="destino-carousel" id="destinoCarousel">
            <?php foreach ($destinos as $indice => $destino_atual): ?>
              <div class="destino-slide <?= $indice === 0 ? 'active' : '' ?>" data-indice="<?= $indice ?>">
                <?php if (!empty($destino_atual['imagem'])): ?>
                  <img src="uploads/<?= htmlspecialchars($destino_atual['imagem']) ?>" class="destino-img" alt="<?= htmlspecialchars($destino_atual['titulo']) ?>">
                <?php endif; ?>
                <div class="destino-body">
                  <div class="destino-titulo"><?= htmlspecialchars($destino_atual['titulo']) ?></div>
                  <div class="destino-data"><?= date('d/m/Y', strtotime($destino_atual['criado_em'])) ?></div>
                  <div class="destino-descricao"><?= nl2br(htmlspecialchars($destino_atual['descricao'])) ?></div>
                  <div class="destino-acoes">
                    <a href="criar_destino.php?id=<?= $destino_atual['id_destino'] ?>" class="btn-editar-destino">✏️ Editar</a>
                    <button class="btn-excluir-destino" onclick="excluirDestino(<?= $destino_atual['id_destino'] ?>)">🗑️ Excluir</button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <button class="destino-nav" id="btnNextDestino" onclick="navegarDestino(1)">&#8594;</button>
        </div>
        <div class="destino-dots" id="destinoDots">
          <?php foreach ($destinos as $indice => $destino_atual): ?>
            <span class="dot <?= $indice === 0 ? 'active' : '' ?>" onclick="irParaDestino(<?= $indice ?>)"></span>
          <?php endforeach; ?>
        </div>
        <div class="destino-counter">
          <span id="destinoAtual">1</span> / <span id="destinoTotal"><?= count($destinos) ?></span>
        </div>
      <?php endif; ?>
    </div>

    <!-- ========== ABA COLETAS ========== -->
    <div class="tab-content" id="coletas-tab">
      <div class="section">
        <span>Coletas Agendadas</span>
        <span class="section-count"><?= count($coletas) ?></span>
      </div>
      <?php if (empty($coletas)): ?>
        <div class="empty">
          <strong>📭 Nenhuma coleta agendada</strong>
          <small>As coletas agendadas pelos doadores aparecerão aqui</small>
        </div>
      <?php else: ?>
        <div class="coletas-list">
          <?php foreach ($coletas as $coleta):
            $is_pix      = $coleta['status'] === 'PENDENTE_PIX';
            $is_recebida = $coleta['status'] === 'RECEBIDA';
          ?>
            <div class="coleta-card <?= $is_recebida ? 'recebida' : '' ?>">
              <div class="coleta-header">
                <div class="coleta-tipo"><?= htmlspecialchars($coleta['tipo_formatado']) ?></div>
                <div class="coleta-data"><?= date('d/m H:i', strtotime($coleta['data_agendada'])) ?></div>
              </div>
              <div class="coleta-doador">👤 Doador: <?= htmlspecialchars($coleta['nome_doador']) ?></div>

              <?php if (!$is_pix): ?>
                <div class="coleta-local">📍 Local: <?= htmlspecialchars($coleta['local_coleta']) ?></div>
              <?php endif; ?>

              <?php if (!empty($coleta['descricao_item'])): ?>
                <div class="coleta-descricao"><strong>📦 Itens:</strong> <?= htmlspecialchars($coleta['descricao_item']) ?></div>
              <?php endif; ?>
              <?php if (!empty($coleta['valor'])): ?>
                <div class="coleta-descricao"><strong>💰 Valor:</strong> R$ <?= number_format($coleta['valor'], 2, ',', '.') ?></div>
              <?php endif; ?>

              <?php if ($is_pix): ?>
                <div class="status-pix-pendente">💜 Aguardando confirmação do PIX</div>
                <button class="btn-confirmar-pix" onclick="confirmarRecebimento(<?= $coleta['id_doacao'] ?>)">
                  💜 Confirmar Recebimento do PIX
                </button>
              <?php elseif ($is_recebida): ?>
                <div class="coleta-status status-recebida">✅ Coleta Recebida</div>
              <?php else: ?>
                <div class="coleta-status status-agendada">📅 Coleta Agendada</div>
                <button class="btn-confirmar" onclick="confirmarRecebimento(<?= $coleta['id_doacao'] ?>)">
                  ✅ Confirmar Recebimento
                </button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- MODAL ADICIONAR ITEM -->
  <div class="modal-overlay" id="modalItem" style="display:none;">
    <div class="modal-box">
      <h3 id="modal-titulo">Adicionar Item</h3>
      <input type="hidden" id="modal-tipo">
      <input type="text" id="modal-input" placeholder="Ex: Roupas limpas" maxlength="100" autocomplete="off">
      <div class="modal-actions">
        <button class="btn-cancelar" onclick="fecharModal()">Cancelar</button>
        <button class="btn-salvar" id="btn-salvar-item" onclick="salvarItem()">Salvar</button>
      </div>
    </div>
  </div>

  <!-- MENU INFERIOR -->
  <div class="bottom">
    <a href="feed.php" class="menu-item">🏠<span>Feed</span></a>
    <a href="campanhas.php" class="menu-item">📢<span>Campanhas</span></a>
    <button class="plus-btn" onclick="window.location='<?= $rotaPlus ?>'">+</button>
    <a href="notificacoes.php" class="menu-item">
      🔔<span>Notificações</span>
      <?php if ($total_notificacoes > 0): ?>
        <span class="notification-badge" id="notificationBadge"><?= $total_notificacoes ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= $rotaPerfil ?>" class="menu-item" style="color: var(--orange);">👤<span>Perfil</span></a>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const phoneEl = document.getElementById('phoneWrapper');

const swalONG = Swal.mixin({
    target: phoneEl,
    confirmButtonColor: '#f4822f',
    cancelButtonColor: '#aaa',
    customClass: { container: 'swal-inside-phone', popup: 'swal-popup-ong' }
});

(function() {
    const p = new URLSearchParams(window.location.search);
    const msg = p.get('msg'), tipo = p.get('tipo');
    if (msg && tipo) {
        setTimeout(() => {
            swalONG.fire({
                title: tipo === 'success' ? 'Sucesso!' : 'Atenção',
                text: decodeURIComponent(msg),
                icon: tipo, timer: 3000, timerProgressBar: true,
                showConfirmButton: true, confirmButtonText: 'Ok'
            });
        }, 500);
        window.history.replaceState({}, document.title, window.location.pathname);
    }
})();

function mostrarInfoVerificacao() {
    const status = '<?= $verificacao_status ?>';
    const configs = {
        aprovada: {
            title: '✅ ONG Verificada', icon: 'success',
            html: `<div style="text-align:left;font-size:13px;line-height:1.8;">
                <p>Sua ONG foi verificada com sucesso!</p><br>
                <p>🪪 <strong>CNPJ validado</strong> na Receita Federal</p>
                <p>🛡️ <strong>Dados conferidos</strong> pela equipe</p>
                <p>✅ Seu perfil exibe o <strong>selo de verificação</strong> para os doadores</p>
            </div>`
        },
        pendente: {
            title: '⏳ Verificação em Análise', icon: 'info',
            html: `<div style="text-align:left;font-size:13px;line-height:1.8;">
                <p>Sua solicitação de verificação está sendo analisada.</p><br>
                <p>📋 Certifique-se de que seu <strong>CNPJ está cadastrado</strong> corretamente</p>
                <p>⏳ A análise pode levar até <strong>48 horas úteis</strong></p>
                <p>📧 Você será notificado quando houver uma decisão</p>
            </div>`
        },
        rejeitada: {
            title: '❌ Verificação não Aprovada', icon: 'error',
            html: `<div style="text-align:left;font-size:13px;line-height:1.8;">
                <p>Sua solicitação de verificação não foi aprovada.</p><br>
                <p>🔍 Verifique se o <strong>CNPJ está correto</strong> e ativo</p>
                <p>📧 Entre em contato com o suporte para mais informações</p>
            </div>`
        }
    };
    const c = configs[status] || configs.pendente;
    swalONG.fire({ title: c.title, html: c.html, icon: c.icon, confirmButtonText: 'Entendi' });
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            this.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            const target = document.getElementById(this.getAttribute('data-tab') + '-tab');
            if (target) target.classList.add('active');
        });
    });
    
    // Inicializar carrossel de destino
    const slides = document.querySelectorAll('.destino-slide');
    if (slides.length > 0) {
        const prevBtn = document.getElementById('btnPrevDestino');
        const nextBtn = document.getElementById('btnNextDestino');
        if (prevBtn) prevBtn.disabled = true;
        if (nextBtn) nextBtn.disabled = slides.length <= 1;
        document.getElementById('destinoTotal').textContent = slides.length;
    }
});

async function atualizarNotificacoes() {
    try {
        const res   = await fetch('contar_notificacoes.php');
        const data  = await res.json();
        const badge = document.getElementById('notificationBadge');
        if (data.total > 0) {
            if (badge) {
                badge.textContent = data.total;
            } else {
                const notifLink = document.querySelector('a[href="notificacoes.php"]');
                if (notifLink) {
                    const span = document.createElement('span');
                    span.className = 'notification-badge';
                    span.id = 'notificationBadge';
                    span.textContent = data.total;
                    notifLink.appendChild(span);
                }
            }
        } else if (badge) {
            badge.style.display = 'none';
        }
    } catch (e) {}
}
setInterval(atualizarNotificacoes, 30000);
document.addEventListener('DOMContentLoaded', atualizarNotificacoes);
document.body.style.overflow = 'hidden';

async function confirmarRecebimento(idDoacao) {
    const result = await swalONG.fire({
        title: 'Confirmar recebimento?',
        text: 'O doador será notificado e o status mudará para "RECEBIDA".',
        icon: 'question', showCancelButton: true,
        confirmButtonText: '✅ Confirmar', cancelButtonText: 'Cancelar',
    });
    if (result.isConfirmed) window.location.href = 'confirmar_recebimento.php?id=' + idDoacao;
}

async function excluirPost(idPost) {
    const result = await swalONG.fire({
        title: 'Excluir post?', text: 'Esta ação não pode ser desfeita.',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: '🗑️ Excluir', cancelButtonText: 'Cancelar',
    });
    if (result.isConfirmed) window.location.href = 'excluir_post.php?id=' + idPost;
}

function abrirModal(tipo) {
    document.getElementById('modal-tipo').value = tipo;
    document.getElementById('modal-titulo').textContent =
        tipo === 'ACEITO' ? '✅ Adicionar Item Aceito' : '❌ Adicionar Item Não Aceito';
    document.getElementById('modal-input').value = '';
    document.getElementById('modalItem').style.display = 'flex';
    setTimeout(() => document.getElementById('modal-input').focus(), 100);
}
function fecharModal() { document.getElementById('modalItem').style.display = 'none'; }
document.getElementById('modalItem').addEventListener('click', function (e) { if (e.target === this) fecharModal(); });

async function salvarItem() {
    const nome = document.getElementById('modal-input').value.trim();
    const tipo = document.getElementById('modal-tipo').value;
    const btn  = document.getElementById('btn-salvar-item');
    if (!nome) { document.getElementById('modal-input').focus(); return; }
    btn.disabled = true; btn.textContent = 'Salvando...';

    const form = new FormData();
    form.append('acao', 'adicionar'); form.append('nome', nome); form.append('tipo', tipo);

    try {
        const res  = await fetch('gerenciar_item_ong.php', { method: 'POST', body: form });
        const data = await res.json();
        if (data.sucesso) {
            const lista = document.getElementById(tipo === 'ACEITO' ? 'lista-aceitos' : 'lista-recusados');
            const empty = document.getElementById(tipo === 'ACEITO' ? 'empty-aceitos' : 'empty-recusados');
            if (empty) empty.remove();
            const tag = document.createElement('div');
            tag.className = `item-tag ${tipo === 'ACEITO' ? 'aceito' : 'recusado'}`;
            tag.id = `item-${data.id_item}`;
            tag.innerHTML = `${nome} <span class="remove-item" onclick="removerItem(${data.id_item},'${tipo}')">✕</span>`;
            lista.appendChild(tag);
            fecharModal();
            await swalONG.fire({ title: 'Item adicionado!', icon: 'success', timer: 1500, showConfirmButton: false, toast: true, position: 'top-end' });
        } else {
            await swalONG.fire({ title: 'Erro ao salvar', text: data.erro || 'Tente novamente.', icon: 'error', confirmButtonText: 'Ok' });
        }
    } catch (e) {
        await swalONG.fire({ title: 'Erro de conexão', icon: 'error', confirmButtonText: 'Ok' });
    }
    btn.disabled = false; btn.textContent = 'Salvar';
}

async function removerItem(idItem, tipo) {
    const result = await swalONG.fire({
        title: 'Remover item?', text: 'Esta ação não pode ser desfeita.',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Remover', cancelButtonText: 'Cancelar',
    });
    if (!result.isConfirmed) return;
    const form = new FormData();
    form.append('acao', 'remover'); form.append('id_item', idItem);
    try {
        const res  = await fetch('gerenciar_item_ong.php', { method: 'POST', body: form });
        const data = await res.json();
        if (data.sucesso) {
            const el = document.getElementById(`item-${idItem}`);
            if (el) el.remove();
            const lista = document.getElementById(tipo === 'ACEITO' ? 'lista-aceitos' : 'lista-recusados');
            if (lista && lista.querySelectorAll('.item-tag').length === 0) {
                const p = document.createElement('p');
                p.className = 'empty-itens';
                p.id = tipo === 'ACEITO' ? 'empty-aceitos' : 'empty-recusados';
                p.textContent = 'Nenhum item cadastrado ainda.';
                lista.appendChild(p);
            }
            await swalONG.fire({ title: 'Item removido!', icon: 'success', timer: 1500, showConfirmButton: false, toast: true, position: 'top-end' });
        } else {
            await swalONG.fire({ title: 'Erro ao remover', text: data.erro || 'Tente novamente.', icon: 'error', confirmButtonText: 'Ok' });
        }
    } catch (e) {
        await swalONG.fire({ title: 'Erro de conexão', icon: 'error', confirmButtonText: 'Ok' });
    }
}

// Variáveis do carrossel de destino
let destinoAtual = 0;

function navegarDestino(direcao) {
    const slides = document.querySelectorAll('.destino-slide');
    if (slides.length === 0) return;
    
    slides[destinoAtual].classList.remove('active');
    const dots = document.querySelectorAll('#destinoDots .dot');
    if (dots[destinoAtual]) dots[destinoAtual].classList.remove('active');
    
    destinoAtual += direcao;
    if (destinoAtual < 0) destinoAtual = 0;
    if (destinoAtual >= slides.length) destinoAtual = slides.length - 1;
    
    slides[destinoAtual].classList.add('active');
    if (dots[destinoAtual]) dots[destinoAtual].classList.add('active');
    
    document.getElementById('destinoAtual').textContent = destinoAtual + 1;
    document.getElementById('destinoTotal').textContent = slides.length;
    
    const prevBtn = document.getElementById('btnPrevDestino');
    const nextBtn = document.getElementById('btnNextDestino');
    if (prevBtn) prevBtn.disabled = destinoAtual === 0;
    if (nextBtn) nextBtn.disabled = destinoAtual === slides.length - 1;
}

function irParaDestino(indice) {
    const slides = document.querySelectorAll('.destino-slide');
    if (slides.length === 0) return;
    
    slides[destinoAtual].classList.remove('active');
    const dots = document.querySelectorAll('#destinoDots .dot');
    if (dots[destinoAtual]) dots[destinoAtual].classList.remove('active');
    
    destinoAtual = indice;
    
    slides[destinoAtual].classList.add('active');
    if (dots[destinoAtual]) dots[destinoAtual].classList.add('active');
    
    document.getElementById('destinoAtual').textContent = destinoAtual + 1;
    document.getElementById('destinoTotal').textContent = slides.length;
    
    const prevBtn = document.getElementById('btnPrevDestino');
    const nextBtn = document.getElementById('btnNextDestino');
    if (prevBtn) prevBtn.disabled = destinoAtual === 0;
    if (nextBtn) nextBtn.disabled = destinoAtual === slides.length - 1;
}

async function excluirDestino(id) {
    const result = await swalONG.fire({
        title: '🗑️ Excluir publicação?',
        text: 'Esta ação não pode ser desfeita.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '🗑️ Excluir',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#dc3545'
    });
    
    if (result.isConfirmed) {
        window.location.href = 'excluir_destino.php?id=' + id;
    }
}
</script>
</body>
</html>