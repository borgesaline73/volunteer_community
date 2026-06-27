<?php
session_start();
require "banco.php";

$mensagem_flash = '';
$tipo_flash = '';
if (isset($_GET['msg']) && isset($_GET['tipo'])) {
    $mensagem_flash = urldecode($_GET['msg']);
    $tipo_flash = $_GET['tipo'];
}

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

$tipoUsuario = $_SESSION["usuario_tipo"] ?? null;

if ($tipoUsuario === "instituicao") {
    $acaoPlus = "criar_post.php";
    $rotaPerfil = "perfil-ong.php";
} else {
    $acaoPlus = "agendar_coleta.php";
    $rotaPerfil = "perfil.php";
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Conexão Solidária - Volunteer Community</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="css/estilo_global.css">
<link rel="stylesheet" href="css/estilo_feed.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
    .phone {
        position: relative;
        overflow: hidden;
    }

    .swal2-container.swal-inside-feed {
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 100% !important;
        z-index: 9999;
    }

    .swal2-container.swal-inside-feed .swal2-popup {
        width: 88% !important;
        max-width: 320px !important;
        border-radius: 20px !important;
        font-family: 'Poppins', sans-serif !important;
    }

    .swal2-confirm {
        background-color: #f4822f !important;
        border-radius: 50px !important;
        padding: 8px 20px !important;
        font-weight: 600 !important;
        font-size: 13px !important;
    }

    .swal2-cancel {
        border-radius: 50px !important;
        padding: 8px 20px !important;
        font-weight: 600 !important;
        font-size: 13px !important;
    }

    .notification-badge {
        position: absolute;
        top: -5px;
        right: -8px;
        background-color: #ff4444;
        color: white;
        border-radius: 50%;
        width: 18px;
        height: 18px;
        font-size: 10px;
        font-weight: bold;
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 10;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.1); }
        100% { transform: scale(1); }
    }

    .menu-item {
        position: relative;
    }
</style>
</head>

<body>

<div class="phone" id="phoneWrapper">

  <div class="header">
    <h1>Conexão Solidária</h1>
  </div>

  <div class="feed-container">
    <?php
    try {
        $query = $pdo->query("SELECT p.*, u.nome, u.id_usuario as id_ong FROM posts p 
                              JOIN usuarios u ON p.id_usuario = u.id_usuario
                              WHERE u.tipo_usuario = 'instituicao'
                              ORDER BY p.data_post DESC");

        $posts = $query->fetchAll(PDO::FETCH_ASSOC);

        if (!$posts || count($posts) === 0): ?>
            <div class="empty-feed">
              <div>
                <p style="font-size:16px; margin-bottom:8px;">📭</p>
                <p><strong>Nenhuma publicação encontrada</strong></p>
                <p style="font-size:12px; margin-top:8px;">No momento não há publicações</p>
              </div>
            </div>

        <?php else: ?>
          <?php foreach ($posts as $post): 
            $descricao = $post['descricao'];
            $temTextoLongo = strlen($descricao) > 200;
            $id_ong_perfil = (int)$post['id_ong'];
            if ($id_ong_perfil <= 0) continue;
          ?>
            <div class="post-card-solidario">
              <div class="post-header">
                  <a href="perfil-ong-publico.php?id=<?= $id_ong_perfil ?>" class="post-avatar-link">
                      <div class="post-avatar">🤝</div>
                  </a>
                  <div class="post-org-info">
                      <h3><?= htmlspecialchars($post['titulo']) ?></h3>
                      <div class="post-meta">
                          Publicado por 
                          <a href="perfil-ong-publico.php?id=<?= $id_ong_perfil ?>" class="link-ong">
                              <strong><?= htmlspecialchars($post['nome']) ?></strong>
                          </a> • 
                          <?= date("d/m/Y \à\s H:i", strtotime($post['data_post'])) ?>
                      </div>
                  </div>
              </div>

              <?php if (!empty($post['categoria'])): ?>
                <div class="post-categories">
                  <span class="category-tag"><?= htmlspecialchars($post['categoria']) ?></span>
                </div>
              <?php endif; ?>

              <div class="post-content" id="content-<?= $post['id_post'] ?>">
                <?= nl2br(htmlspecialchars($descricao)) ?>
              </div>
              
              <?php if ($temTextoLongo): ?>
                <button class="read-more" onclick="toggleContent(<?= $post['id_post'] ?>, this)">
                  Ler mais
                </button>
              <?php endif; ?>

              <?php if (!empty($post['imagem'])): ?>
                <img src="/uploads/<?= $post['imagem'] ?>"
                     class="post-image" 
                     alt="<?= htmlspecialchars($post['titulo']) ?>"
                     onerror="this.style.display='none'">
              <?php endif; ?>

              <?php if ($tipoUsuario === "doador"): ?>
                <div class="post-acoes">
                    <a href="perfil-ong-publico.php?id=<?= $id_ong_perfil ?>" class="btn-ver-ong">
                        <span style="font-size:14px; line-height:1;">🏢</span> Ver ONG
                    </a>
                    <button class="doacao-btn" onclick="efetuarDoacao(<?= $id_ong_perfil ?>, '<?= htmlspecialchars(addslashes($post['titulo'])) ?>')">
                        💝 Efetuar Doação
                    </button>
                </div>
              <?php elseif ($tipoUsuario === "instituicao"): ?>
                <div class="post-acoes">
                    <a href="perfil-ong-publico.php?id=<?= $id_ong_perfil ?>" class="btn-ver-ong">
                        🏢 Ver ONG
                    </a>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

        <?php endif; ?>

    <?php } catch (PDOException $e) { ?>
        <div class="empty-feed">
          <div>
            <p style="font-size:16px; margin-bottom:8px;">⚠️</p>
            <p><strong>Erro ao carregar publicações</strong></p>
            <p style="font-size:12px; margin-top:8px;">Tente recarregar a página</p>
          </div>
        </div>
    <?php } ?>
  </div>

  <div class="bottom">
    <a href="feed.php" class="menu-item active">
      🏠
      <span>Feed</span>
    </a>
    
    <a href="campanhas.php" class="menu-item">
      📢
      <span>Campanhas</span>
    </a>

    <button class="plus-btn" onclick="window.location.href='<?= $acaoPlus ?>'">+</button>

    <a href="notificacoes.php" class="menu-item">
      🔔
      <span>Notificações</span>
      <span class="notification-badge" id="notificationBadge"></span>
    </a>

    <a href="<?= $rotaPerfil ?>" class="menu-item">
      👤
      <span>Perfil</span>
    </a>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const phoneEl = document.getElementById('phoneWrapper');

const swalFeed = Swal.mixin({
    target: phoneEl,
    confirmButtonColor: '#f4822f',
    cancelButtonColor: '#aaa',
    customClass: {
        container: 'swal-inside-feed',
        popup: 'swal-popup-feed'
    }
});

async function efetuarDoacao(idOng, tituloOng) {
    const result = await swalFeed.fire({
        title: '💝 Fazer Doação',
        html: `
            <div style="text-align: left;">
                <p>Você está prestes a doar para:</p>
                <p><strong>🏢 ${tituloOng}</strong></p>
                <hr style="margin: 12px 0;">
                <p style="font-size: 13px; color: #666;">Clique em "Continuar" para agendar sua coleta.</p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '✅ Continuar',
        cancelButtonText: '❌ Cancelar',
        confirmButtonColor: '#f4822f',
        cancelButtonColor: '#aaa'
    });

    if (result.isConfirmed) {
        window.location.href = `agendar_coleta.php?ong=${idOng}&titulo=${encodeURIComponent(tituloOng)}`;
    }
}

function toggleContent(postId, button) {
    const content = document.getElementById('content-' + postId);
    const isExpanded = content.classList.contains('expanded');
    
    if (isExpanded) {
        content.classList.remove('expanded');
        button.textContent = 'Ler mais';
    } else {
        content.classList.add('expanded');
        button.textContent = 'Ler menos';
    }
}

async function atualizarBadgeNotificacoes() {
    try {
        const response = await fetch('contar_notificacoes.php');
        const data = await response.json();
        
        const badge = document.getElementById('notificationBadge');
        
        if (data.total > 0) {
            badge.textContent = data.total;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    } catch (error) {
        console.error('Erro ao buscar notificações:', error);
    }
}

<?php if (!empty($mensagem_flash) && !empty($tipo_flash)): ?>
document.addEventListener('DOMContentLoaded', function() {
    swalFeed.fire({
        title: '<?= $tipo_flash === 'success' ? '✅ Sucesso!' : '⚠️ Atenção' ?>',
        text: '<?= htmlspecialchars($mensagem_flash) ?>',
        icon: '<?= $tipo_flash ?>',
        confirmButtonText: 'Ok',
        timer: 4000,
        timerProgressBar: true
    }).then(() => {
        const url = new URL(window.location.href);
        url.searchParams.delete('msg');
        url.searchParams.delete('tipo');
        window.history.replaceState({}, document.title, url.toString());
    });
});
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function() {
    const lastVisit = localStorage.getItem('lastVisit');
    const today = new Date().toDateString();
    
    if (lastVisit !== today && <?= $tipoUsuario === 'doador' ? 'true' : 'false' ?>) {
        setTimeout(() => {
            swalFeed.fire({
                title: '🙏 Bem-vindo(a)!',
                html: `
                    <div style="text-align: center;">
                        <p>Que tal fazer uma doação hoje?</p>
                        <p style="font-size: 12px; color: #f4822f;">Cada gesto de solidariedade transforma vidas!</p>
                    </div>
                `,
                icon: 'info',
                confirmButtonText: 'Ver posts',
                timer: 5000,
                timerProgressBar: true
            });
            localStorage.setItem('lastVisit', today);
        }, 1000);
    }
    
    // Badge controlado 100% pelo JS — sem valor PHP
    atualizarBadgeNotificacoes();
});

// Atualizar badge a cada 30 segundos
setInterval(atualizarBadgeNotificacoes, 30000);

document.body.style.overflow = 'hidden';
</script>

</body>
</html>