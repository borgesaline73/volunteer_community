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

$categoria = $_GET["categoria"] ?? "todos";
$mapCategorias = [
  'educacao'  => 'Educação',
  'saude'     => 'Saúde',
  'alimentos' => 'Alimentos',
  'campanhas' => 'Campanhas'
];
$categoria_banco = null;
if ($categoria !== "todos" && isset($mapCategorias[$categoria])) {
    $categoria_banco = $mapCategorias[$categoria];
}
$tipoUsuario = $_SESSION["usuario_tipo"] ?? null;
if ($tipoUsuario === "instituicao") {
    $acaoPlus   = "criar_post.php";
    $rotaPerfil = "perfil-ong.php";
} else {
    $acaoPlus   = "agendar_coleta.php";
    $rotaPerfil = "perfil.php";
}

// ===== BUSCAR NOTIFICAÇÕES =====
$total_notificacoes = 0;
try {
    $stmt_notif = $pdo->prepare("SELECT COUNT(*) as total FROM notificacoes WHERE id_usuario = ? AND lida = FALSE");
    $stmt_notif->execute([$_SESSION["usuario_id"]]);
    $total_notificacoes = $stmt_notif->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
} catch (PDOException $e) {
    error_log("Erro notif: " . $e->getMessage());
}

// ===== FUNÇÃO PARA PEGAR POSTS RANDÔMICOS =====
function getRandomPosts($pdo, $categoria_banco, $categoria, $limit = 10) {
    try {
        if ($categoria == "todos") {
            $countQuery = $pdo->query("SELECT COUNT(*) as total FROM posts");
            $total = $countQuery->fetch(PDO::FETCH_ASSOC)['total'];

            if ($total <= $limit) {
                $query = $pdo->query("SELECT p.*, u.nome, u.id_usuario as id_ong 
                                      FROM posts p 
                                      JOIN usuarios u ON p.id_usuario = u.id_usuario 
                                      ORDER BY p.data_post DESC");
                return $query->fetchAll(PDO::FETCH_ASSOC);
            }

            
            $query = $pdo->query("SELECT p.*, u.nome, u.id_usuario as id_ong 
                                  FROM posts p 
                                  JOIN usuarios u ON p.id_usuario = u.id_usuario 
                                  ORDER BY RANDOM() 
                                  LIMIT $limit");
            return $query->fetchAll(PDO::FETCH_ASSOC);

        } else {
            $countQuery = $pdo->prepare("SELECT COUNT(*) as total FROM posts WHERE categoria = ?");
            $countQuery->execute([$categoria_banco]);
            $total = $countQuery->fetch(PDO::FETCH_ASSOC)['total'];

            if ($total <= $limit) {
                $query = $pdo->prepare("SELECT p.*, u.nome, u.id_usuario as id_ong 
                                        FROM posts p 
                                        JOIN usuarios u ON p.id_usuario = u.id_usuario 
                                        WHERE p.categoria = ? 
                                        ORDER BY p.data_post DESC");
                $query->execute([$categoria_banco]);
                return $query->fetchAll(PDO::FETCH_ASSOC);
            }

            $query = $pdo->prepare("SELECT p.*, u.nome, u.id_usuario as id_ong 
                                    FROM posts p 
                                    JOIN usuarios u ON p.id_usuario = u.id_usuario 
                                    WHERE p.categoria = ? 
                                    ORDER BY RANDOM() 
                                    LIMIT $limit");
            $query->execute([$categoria_banco]);
            return $query->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Erro ao buscar posts: " . $e->getMessage());
        return [];
    }
}

$MAX_POSTS = 8;
$posts = getRandomPosts($pdo, $categoria_banco, $categoria, $MAX_POSTS);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Campanhas - Conexão Solidária</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

:root {
  --orange: #f4822f;
  --oh: #e67329;
  --bg: #f6f4f2;
  --w: #fff;
  --t: #2b2b2b;
  --m: #888;
  --l: #f0f0f0;
}

body {
  margin: 0;
  background: var(--bg);
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  padding: 20px;
  font-family: "Poppins", sans-serif;
}

.phone {
  width: 100%;
  max-width: 430px;
  background: #fff;
  height: 90vh;
  max-height: 800px;
  border-radius: 32px;
  box-shadow: 0 10px 40px rgba(0,0,0,0.06);
  display: flex;
  flex-direction: column;
  position: relative;
  overflow: hidden;
}

.header {
  padding: 18px 20px 10px;
  background: #fff;
  position: sticky;
  top: 0;
  z-index: 10;
  border-bottom: 1px solid #f0f0f0;
  flex-shrink: 0;
}

.header h1 {
  font-size: 22px;
  font-weight: 800;
  color: var(--t);
  letter-spacing: -.5px;
}

.tab-menu {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding: 12px 20px 4px;
  scrollbar-width: none;
  margin: 0;
  background: transparent;
  flex-shrink: 0;
}

.tab-menu::-webkit-scrollbar {
  display: none;
}

.tab-categoria {
  flex-shrink: 0;
  padding: 8px 16px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  border: 1.5px solid #ddd;
  background: #fff;
  color: #666;
  transition: all 0.2s;
  white-space: nowrap;
  font-family: 'Poppins', sans-serif;
  text-decoration: none;
}

.tab-categoria:hover:not(.active) {
  background: #f5f5f5;
  color: #333;
}

.tab-categoria.active {
  background: var(--orange);
  border-color: var(--orange);
  color: #fff;
  font-weight: 600;
}

.feed-container {
  flex: 1;
  overflow-y: auto;
  padding: 12px 16px calc(74px + 20px);
}

.carousel-wrapper {
  position: relative;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 16px;
}

.carousel-container {
  flex: 1;
  overflow: hidden;
  border-radius: 20px;
}

.carousel-slide {
  display: none;
  flex-direction: column;
  background: #fff;
  border-radius: 20px;
  box-shadow: 0 4px 16px rgba(0,0,0,0.08);
  overflow: hidden;
  animation: fadeSlide 0.3s ease;
  cursor: pointer;
}

.carousel-slide.active {
  display: flex;
}

@keyframes fadeSlide {
  from { opacity: 0; transform: translateX(20px); }
  to   { opacity: 1; transform: translateX(0); }
}

.carousel-img-wrapper {
  width: 100%;
  background: linear-gradient(135deg, #667eea, #764ba2);
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 200px;
  max-height: 300px;
}

.carousel-img {
  width: 100%;
  height: auto;
  max-height: 300px;
  object-fit: contain;
  display: block;
  background: #f5f5f5;
}

.carousel-img-placeholder {
  width: 100%;
  height: 200px;
  background: linear-gradient(135deg, #667eea, #764ba2);
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-size: 64px;
}

.carousel-titulo {
  padding: 12px 16px;
  font-weight: 700;
  font-size: 14px;
  color: #2b2b2b;
  background: #fff;
  text-align: center;
  border-top: 1px solid #f0f0f0;
}

.carousel-nav {
  background: #fff;
  border: 1.5px solid #eee;
  border-radius: 50%;
  width: 34px;
  height: 34px;
  min-width: 34px;
  font-size: 16px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  color: #555;
  transition: all 0.2s;
  flex-shrink: 0;
}

.carousel-nav:hover {
  background: var(--orange);
  color: #fff;
  border-color: var(--orange);
}

.carousel-nav:disabled {
  opacity: 0.3;
  cursor: default;
  pointer-events: none;
}

.carousel-dots {
  display: flex;
  justify-content: center;
  gap: 6px;
  margin-top: 12px;
  margin-bottom: 16px;
}

.dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #ddd;
  cursor: pointer;
  transition: background 0.2s, transform 0.2s;
}

.dot.active {
  background: var(--orange);
  transform: scale(1.3);
}

.carousel-counter {
  text-align: center;
  font-size: 12px;
  color: #aaa;
  margin-bottom: 20px;
}

.refresh-btn {
  display: block;
  width: 100%;
  padding: 12px;
  margin-top: 10px;
  background: var(--orange);
  color: white;
  border: none;
  border-radius: 30px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  font-family: "Poppins", sans-serif;
  transition: background 0.2s;
}

.refresh-btn:hover {
  background: var(--oh);
}

.empty-message {
  text-align: center;
  padding: 60px 20px;
  background: #fafafa;
  border-radius: 20px;
  margin: 20px 0;
  color: var(--m);
  font-size: 13px;
}

.empty-message p:first-child {
  font-size: 48px;
  margin-bottom: 16px;
}

.bottom {
  height: 74px;
  border-top: 1px solid #eee;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 40px;
  background: #fff;
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 100;
}

.menu-item {
  text-decoration: none;
  font-size: 11px;
  color: #aaa;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  transition: color 0.3s;
  position: relative;
}

.menu-item:hover { color: var(--orange); }
.menu-item.active { color: var(--orange); }

.plus-btn {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: var(--orange);
  color: #fff;
  font-size: 28px;
  border: none;
  margin-top: -30px;
  box-shadow: 0 6px 20px rgba(0,0,0,0.15);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
}

.plus-btn:hover {
  background: #e67329;
  transform: scale(1.05);
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
  display: flex;
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

.phone .swal2-container.swal2-center {
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    z-index: 9999;
}

.phone .swal2-popup {
    width: 88% !important;
    max-width: 320px !important;
    border-radius: 20px !important;
    font-family: 'Poppins', sans-serif !important;
}

.phone .swal2-confirm {
    background-color: #f4822f !important;
    border-radius: 50px !important;
    padding: 8px 20px !important;
    font-weight: 600 !important;
    font-size: 13px !important;
}

.phone .swal2-cancel {
    border-radius: 50px !important;
    padding: 8px 20px !important;
    font-weight: 600 !important;
    font-size: 13px !important;
}
</style>
</head>
<body>
<div class="phone" id="phone">

  <div class="header">
    <h1>ONGs do Mês</h1>
  </div>

  <div class="tab-menu" id="tabMenu">
    <a href="campanhas.php?categoria=todos"     class="tab-categoria <?php echo $categoria=='todos'     ? 'active' : ''; ?>">Todos</a>
    <a href="campanhas.php?categoria=educacao"  class="tab-categoria <?php echo $categoria=='educacao'  ? 'active' : ''; ?>">Educação</a>
    <a href="campanhas.php?categoria=saude"     class="tab-categoria <?php echo $categoria=='saude'     ? 'active' : ''; ?>">Saúde</a>
    <a href="campanhas.php?categoria=alimentos" class="tab-categoria <?php echo $categoria=='alimentos' ? 'active' : ''; ?>">Alimentos</a>
    <a href="campanhas.php?categoria=campanhas" class="tab-categoria <?php echo $categoria=='campanhas' ? 'active' : ''; ?>">Campanhas</a>
  </div>

  <div class="feed-container" id="feed">
    <?php if (!$posts || count($posts) === 0): ?>
        <div class="empty-message">
            <p>📭</p>
            <p><strong>Nenhuma publicação encontrada</strong></p>
            <p style="font-size:12px; margin-top:8px;"><?= $categoria != 'todos' ? 'na categoria "' . htmlspecialchars($categoria) . '"' : 'no momento' ?></p>
        </div>
    <?php else:
        $posts_com_imagem = array_filter($posts, function($post) {
            return !empty($post['imagem']);
        });

        if (empty($posts_com_imagem)) {
            $posts_com_imagem = $posts;
        }

        $posts_array = [];
        foreach ($posts_com_imagem as $post) {
            $posts_array[] = [
                'id'     => $post["id_post"],
                'id_ong' => $post["id_ong"],
                'titulo' => htmlspecialchars($post["titulo"]),
                'imagem' => $post["imagem"] ?? null
            ];
        }

        $total_posts = count($posts_array);
    ?>
        <div class="carousel-wrapper">
            <button class="carousel-nav" id="carouselPrev" onclick="navegarCarrossel(-1)">&#8592;</button>
            <div class="carousel-container" id="carouselContainer">
                <?php foreach ($posts_array as $index => $post): ?>
                    <div class="carousel-slide <?= $index === 0 ? 'active' : '' ?>"
                         data-index="<?= $index ?>"
                         onclick="window.location.href='perfil-ong-publico.php?id=<?= $post['id_ong'] ?>'">

                        <div class="carousel-img-wrapper">
                            <?php if (!empty($post['imagem'])): ?>
                                <img src="uploads/<?= htmlspecialchars($post['imagem']) ?>"
                                     class="carousel-img"
                                     alt="<?= $post['titulo'] ?>"
                                     onerror="this.onerror=null; this.style.display='none'; this.parentElement.innerHTML='<div class=\'carousel-img-placeholder\'>📢</div>'">
                            <?php else: ?>
                                <div class="carousel-img-placeholder">📢</div>
                            <?php endif; ?>
                        </div>

                        <div class="carousel-titulo">
                            <?= $post['titulo'] ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="carousel-nav" id="carouselNext" onclick="navegarCarrossel(1)">&#8594;</button>
        </div>

        <div class="carousel-dots" id="carouselDots">
            <?php for ($i = 0; $i < $total_posts; $i++): ?>
                <span class="dot <?= $i === 0 ? 'active' : '' ?>" onclick="irParaSlide(<?= $i ?>)"></span>
            <?php endfor; ?>
        </div>

        <div class="carousel-counter">
            <span id="slideAtual">1</span> / <span id="slideTotal"><?= $total_posts ?></span>
        </div>

        <button class="refresh-btn" onclick="window.location.reload()">
            🔄 Ver novas campanhas
        </button>
    <?php endif; ?>
  </div>

  <div class="bottom">
    <a href="feed.php" class="menu-item">
      🏠<span>Feed</span>
    </a>
    <a href="campanhas.php" class="menu-item active">
      📢<span>Campanhas</span>
    </a>
    <button class="plus-btn" onclick="window.location.href='<?php echo $acaoPlus; ?>'">+</button>
    <a href="notificacoes.php" class="menu-item">
      🔔<span>Notificações</span>
      <?php if ($total_notificacoes > 0): ?>
        <span class="notification-badge" id="notificationBadge"><?= $total_notificacoes ?></span>
      <?php endif; ?>
    </a>
    <a href="<?php echo $rotaPerfil; ?>" class="menu-item">
      👤<span>Perfil</span>
    </a>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let slideAtual = 0;
let totalSlides = <?= isset($posts_array) ? count($posts_array) : 0 ?>;

const phoneEl = document.getElementById('phone');

const swalCampanhas = Swal.mixin({
    target: phoneEl,
    confirmButtonColor: '#f4822f',
    cancelButtonColor: '#aaa'
});

function atualizarCarrossel() {
    const slides = document.querySelectorAll('.carousel-slide');
    const dots = document.querySelectorAll('.dot');
    const prevBtn = document.getElementById('carouselPrev');
    const nextBtn = document.getElementById('carouselNext');
    const slideAtualSpan = document.getElementById('slideAtual');

    slides.forEach((slide, index) => {
        slide.classList.toggle('active', index === slideAtual);
    });

    dots.forEach((dot, index) => {
        dot.classList.toggle('active', index === slideAtual);
    });

    if (slideAtualSpan) slideAtualSpan.textContent = slideAtual + 1;
    if (prevBtn) prevBtn.disabled = slideAtual === 0;
    if (nextBtn) nextBtn.disabled = slideAtual === totalSlides - 1;
}

function navegarCarrossel(direcao) {
    if (totalSlides === 0) return;
    slideAtual = Math.max(0, Math.min(slideAtual + direcao, totalSlides - 1));
    atualizarCarrossel();
}

function irParaSlide(index) {
    if (totalSlides === 0) return;
    slideAtual = Math.max(0, Math.min(index, totalSlides - 1));
    atualizarCarrossel();
}

function centralizarAba(aba) {
    const menu = document.getElementById('tabMenu');
    if (!menu || !aba) return;
    const abaRect = aba.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    const scrollLeft = menu.scrollLeft;
    const targetScroll = scrollLeft + (abaRect.left - menuRect.left) - (menuRect.width / 2) + (abaRect.width / 2);
    menu.scrollTo({ left: targetScroll, behavior: 'smooth' });
}

<?php if (!empty($mensagem_flash) && !empty($tipo_flash)): ?>
document.addEventListener('DOMContentLoaded', function() {
    swalCampanhas.fire({
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
    const activeTab = document.querySelector('.tab-categoria.active');
    if (activeTab) {
        setTimeout(() => centralizarAba(activeTab), 100);
    }

    document.querySelectorAll('.tab-categoria').forEach(tab => {
        tab.addEventListener('click', function() {
            setTimeout(() => centralizarAba(this), 50);
        });
    });
});

async function atualizarNotificacoes() {
    try {
        const res  = await fetch('contar_notificacoes.php');
        const data = await res.json();
        const badge = document.getElementById('notificationBadge');
        if (data.total > 0) {
            if (badge) badge.textContent = data.total;
            else {
                const notifLink = document.querySelector('a[href="notificacoes.php"]');
                if (notifLink) {
                    const span = document.createElement('span');
                    span.className = 'notification-badge';
                    span.id = 'notificationBadge';
                    span.textContent = data.total;
                    notifLink.appendChild(span);
                }
            }
        } else if (badge) badge.remove();
    } catch (e) {}
}

setInterval(atualizarNotificacoes, 30000);
document.addEventListener('DOMContentLoaded', atualizarNotificacoes);

document.body.style.overflow = 'hidden';
</script>

</body>
</html>