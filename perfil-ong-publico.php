<?php
session_start();
require "banco.php";

// NÃO REDIRECIONA PARA LOGIN - APENAS MOSTRA ERRO SE NÃO ESTIVER LOGADO
$id_visitante = $_SESSION["usuario_id"] ?? null;
$tipo_visitante = $_SESSION["usuario_tipo"] ?? "doador";

// PEGA O ID DA URL
$id_ong = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// SE NÃO TIVER ID VÁLIDO, MOSTRA ERRO
if ($id_ong <= 0) {
    die("Erro: ID da ONG não informado ou inválido.");
}

$rotaPerfil = $tipo_visitante === "instituicao" ? "perfil-ong.php" : "perfil.php";
$rotaPlus = $tipo_visitante === "instituicao" ? "criar_post.php" : "agendar_coleta.php";

try {
    // BUSCA A ONG PELO ID
    $stmt = $pdo->prepare("SELECT id_usuario, nome, email, cpf_cnpj, verificada, verificacao_status
                           FROM usuarios
                           WHERE id_usuario = ? AND tipo_usuario = 'instituicao'");
    $stmt->execute([$id_ong]);
    $ong = $stmt->fetch(PDO::FETCH_ASSOC);

    // SE NÃO ENCONTROU A ONG, MOSTRA ERRO
    if (!$ong) {
        die("Erro: ONG não encontrada. ID: " . $id_ong);
    }

    $nome_ong = $ong['nome'];
    $email_ong = $ong['email'] ?? '';
    $cnpj_ong = $ong['cpf_cnpj'] ?? '';
    $verificada = $ong['verificada'] ?? false;
    $status_ver = $ong['verificacao_status'] ?? 'pendente';

    // BUSCA POSTS DA ONG
    $stmt_posts = $pdo->prepare("SELECT * FROM posts WHERE id_usuario = ? ORDER BY data_post DESC");
    $stmt_posts->execute([$id_ong]);
    $posts = $stmt_posts->fetchAll(PDO::FETCH_ASSOC);

    // BUSCA ITENS ACEITOS/RECUSADOS
    $stmt_itens = $pdo->prepare("SELECT id_item, nome, tipo FROM itens_ong WHERE id_ong = ? ORDER BY tipo, nome ASC");
    $stmt_itens->execute([$id_ong]);
    $itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);
    $itens_aceitos = array_filter($itens, fn($i) => $i['tipo'] === 'ACEITO');
    $itens_recusados = array_filter($itens, fn($i) => $i['tipo'] === 'RECUSADO');

    // BUSCA DESTINOS
    $stmt_destinos = $pdo->prepare("SELECT * FROM destino_doacoes WHERE id_ong = ? ORDER BY criado_em DESC");
    $stmt_destinos->execute([$id_ong]);
    $destinos = $stmt_destinos->fetchAll(PDO::FETCH_ASSOC);

    // BUSCA NOTIFICAÇÕES (SOMENTE SE USUÁRIO ESTIVER LOGADO)
    $total_notificacoes = 0;
    if ($id_visitante) {
        $stmt_notif = $pdo->prepare("SELECT COUNT(*) as total FROM notificacoes WHERE id_usuario = ? AND lida = FALSE");
        $stmt_notif->execute([$id_visitante]);
        $total_notificacoes = $stmt_notif->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    }

} catch (PDOException $e) {
    die("Erro no banco de dados: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($nome_ong) ?> - Volunteer Community</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/estilo_global.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --orange: #f4822f;
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

/* ESTILO PARA SWEETALERT DENTRO DO PHONE */
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

.header {
    padding: 18px 20px 10px;
    background: #fff;
    position: sticky;
    top: 0;
    z-index: 10;
    border-bottom: 1px solid #f0f0f0;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.header-title {
    flex: 1;
    text-align: center;
    font-weight: 600;
    font-size: 16px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding: 0 10px;
}

.back-btn {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--orange);
    width: 30px;
}

.main-content {
    flex: 1;
    overflow-y: auto;
    padding-bottom: 80px;
}

.profile-card {
    margin: 20px;
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.05);
    padding: 20px;
    text-align: center;
}

.avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    margin: 0 auto 10px;
    display: flex;
    justify-content: center;
    align-items: center;
    font-weight: 700;
    font-size: 32px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.name {
    font-weight: 700;
    margin-bottom: 5px;
    font-size: 18px;
    color: #2b2b2b;
}

.info-item {
    font-size: 14px;
    color: #666;
    margin: 8px 0;
    text-align: left;
    padding: 4px 0;
}

.info-item strong {
    color: #333;
    display: inline-block;
    min-width: 100px;
}

.verified-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    padding: 5px 14px;
    border-radius: 20px;
    margin-bottom: 14px;
    cursor: pointer;
}

.verified-badge.aprovada {
    background: linear-gradient(135deg, #d4edda, #b8e0c4);
    color: #155724;
    border: 1.5px solid #a8d5b0;
}

.verified-badge.pendente {
    background: #fff3cd;
    color: #856404;
    border: 1.5px solid #ffd877;
}

.btn-doar {
    background: linear-gradient(135deg, #f4822f, #ff6b2c);
    color: white;
    border: none;
    border-radius: 50px;
    padding: 12px 24px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    width: 100%;
    margin-top: 10px;
    transition: transform 0.2s, box-shadow 0.2s;
}

.btn-doar:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(244,130,47,0.3);
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

.tab {
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
}

.tab.active {
    background: var(--orange);
    border-color: var(--orange);
    color: #fff;
}

.tab-content {
    display: none;
    padding: 0 20px 20px;
}

.tab-content.active {
    display: block;
}

.section {
    margin: 20px 0 10px;
    font-weight: 700;
    font-size: 16px;
    color: #333;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.section-count {
    background: var(--orange);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
}

.post-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 16px;
    margin-bottom: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    border-left: 4px solid var(--orange);
}

.post-card h3 {
    margin: 0 0 8px 0;
    font-size: 16px;
    font-weight: 700;
    color: #2b2b2b;
}

.post-meta {
    font-size: 11px;
    margin: 4px 0 8px 0;
    color: #888;
}

.post-content {
    font-size: 13px;
    color: #444;
    margin: 10px 0;
    line-height: 1.4;
}

.post-image {
    width: 100%;
    border-radius: 16px;
    margin-top: 10px;
    display: block;
}

.categoria-badge {
    display: inline-block;
    background: var(--orange);
    color: white;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    margin-bottom: 8px;
}

.btn-doar-post {
    background: #f4822f20;
    color: #f4822f;
    border: 1.5px solid #f4822f;
    border-radius: 50px;
    padding: 8px 16px;
    font-weight: 600;
    font-size: 12px;
    cursor: pointer;
    margin-top: 12px;
    width: 100%;
    transition: all 0.2s;
}

.btn-doar-post:hover {
    background: #f4822f;
    color: white;
}

.itens-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 6px 0;
}

.item-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}

.item-tag.aceito {
    background: #e6f4ea;
    color: #2d7a3a;
    border: 1px solid #a8d5b0;
}

.item-tag.recusado {
    background: #fdecea;
    color: #c0392b;
    border: 1px solid #f5b8b3;
}

.empty-itens {
    font-size: 13px;
    color: #aaa;
    font-style: italic;
}

.empty {
    text-align: center;
    padding: 40px 20px;
    background: #fafafa;
    border-radius: 20px;
    color: #888;
}

/* Destino carrossel */
.destino-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    gap: 6px;
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
    box-shadow: 0 4px 16px rgba(0,0,0,0.08);
    overflow: hidden;
}

.destino-slide.active {
    display: block;
}

.destino-img {
    width: 100%;
    height: 160px;
    object-fit: cover;
}

.destino-body {
    padding: 14px 16px;
}

.destino-titulo {
    font-weight: 700;
    font-size: 15px;
    margin-bottom: 4px;
}

.destino-data {
    font-size: 11px;
    color: #aaa;
    margin-bottom: 8px;
}

.destino-descricao {
    font-size: 13px;
    color: #555;
    line-height: 1.5;
}

.destino-nav {
    background: #fff;
    border: 1.5px solid #eee;
    border-radius: 50%;
    width: 34px;
    height: 34px;
    font-size: 16px;
    cursor: pointer;
}

.destino-nav:hover {
    background: var(--orange);
    color: #fff;
    border-color: var(--orange);
}

.destino-dots {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin-top: 12px;
}

.dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #ddd;
    cursor: pointer;
}

.dot.active {
    background: var(--orange);
    transform: scale(1.3);
}

.destino-counter {
    text-align: center;
    font-size: 12px;
    color: #aaa;
    margin-top: 6px;
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
    position: relative;
}

.menu-item:hover {
    color: var(--orange);
}

.plus-btn {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: var(--orange);
    color: #fff;
    font-size: 28px;
    border: none;
    margin-top: -30px;
    cursor: pointer;
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
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>
</head>
<body>

<div class="phone" id="phoneWrapper">

    <div class="header">
        <button class="back-btn" onclick="history.back()">←</button>
        <div class="header-title"><?= htmlspecialchars($nome_ong) ?></div>
        <div style="width: 30px;"></div>
    </div>

    <div class="main-content">

        <div class="profile-card">
            <div class="avatar">🏢</div>
            <div class="name"><?= htmlspecialchars($nome_ong) ?></div>
            
            <?php if (!empty($email_ong)): ?>
                <div class="info-item"><strong>Email:</strong> <?= htmlspecialchars($email_ong) ?></div>
            <?php endif; ?>
            
            <?php if (!empty($cnpj_ong)): ?>
                <div class="info-item"><strong>CNPJ:</strong> <?= htmlspecialchars($cnpj_ong) ?></div>
            <?php endif; ?>
            
            <div class="info-item"><strong>Tipo:</strong> Instituição</div>

            <?php if ($verificada && $status_ver === 'aprovada'): ?>
                <div class="verified-badge aprovada" onclick="mostrarInfoVerificacao()">✓ ONG Verificada</div>
            <?php elseif ($status_ver === 'pendente'): ?>
                <div class="verified-badge pendente">⏳ Verificação em análise</div>
            <?php endif; ?>

            <?php if ($tipo_visitante === 'doador'): ?>
                <button class="btn-doar" onclick="confirmarDoacao(<?= $id_ong ?>, '<?= htmlspecialchars(addslashes($nome_ong)) ?>')">
                    💛 Quero Doar
                </button>
            <?php endif; ?>
        </div>

        <div class="tab-menu">
            <div class="tab active" data-tab="posts">Publicações</div>
            <div class="tab" data-tab="itens">Itens Aceitos</div>
            <div class="tab" data-tab="destino">Sua Doação Importa!</div>
        </div>

        <div class="tab-content active" id="posts-tab">
            <div class="section">
                <span>Publicações</span>
                <span class="section-count"><?= count($posts) ?></span>
            </div>
            <?php if (!empty($posts)): ?>
                <?php foreach ($posts as $post): ?>
                    <div class="post-card">
                        <h3><?= htmlspecialchars($post['titulo']) ?></h3>
                        <div class="post-meta"><?= date("d/m/Y \à\s H:i", strtotime($post['data_post'])) ?></div>
                        <?php if (!empty($post['categoria'])): ?>
                            <span class="categoria-badge"><?= htmlspecialchars($post['categoria']) ?></span>
                        <?php endif; ?>
                        <div class="post-content"><?= nl2br(htmlspecialchars($post['descricao'])) ?></div>
                        <?php if (!empty($post['imagem'])): ?>
                            <img src="uploads/<?= htmlspecialchars($post['imagem']) ?>" class="post-image" alt="Imagem do post">
                        <?php endif; ?>
                        <?php if ($tipo_visitante === 'doador'): ?>
                            <button class="btn-doar-post" onclick="confirmarDoacao(<?= $id_ong ?>, '<?= htmlspecialchars(addslashes($nome_ong)) ?>')">
                                💝 Efetuar Doação
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty"><strong>📝 Nenhuma publicação ainda</strong></div>
            <?php endif; ?>
        </div>

        <div class="tab-content" id="itens-tab">
            <div class="section"><span>✅ Itens Aceitos</span></div>
            <div class="itens-grid">
                <?php if (empty($itens_aceitos)): ?>
                    <p class="empty-itens">Nenhum item cadastrado ainda.</p>
                <?php else: ?>
                    <?php foreach ($itens_aceitos as $item): ?>
                        <div class="item-tag aceito"><?= htmlspecialchars($item['nome']) ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="section" style="margin-top:16px;"><span>❌ Itens Não Aceitos</span></div>
            <div class="itens-grid">
                <?php if (empty($itens_recusados)): ?>
                    <p class="empty-itens">Nenhum item cadastrado ainda.</p>
                <?php else: ?>
                    <?php foreach ($itens_recusados as $item): ?>
                        <div class="item-tag recusado"><?= htmlspecialchars($item['nome']) ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="tab-content" id="destino-tab">
            <div class="section"><span>📢 Para onde vai sua doação</span></div>
            <?php if (empty($destinos)): ?>
                <div class="empty"><strong>💛 Nenhuma publicação ainda</strong></div>
            <?php else: ?>
                <div class="destino-wrapper">
                    <button class="destino-nav" id="btnPrev" onclick="navegarDestino(-1)">←</button>
                    <div class="destino-carousel" id="destinoCarousel">
                        <?php foreach ($destinos as $i => $d): ?>
                            <div class="destino-slide <?= $i === 0 ? 'active' : '' ?>">
                                <?php if (!empty($d['imagem'])): ?>
                                    <img src="uploads/<?= htmlspecialchars($d['imagem']) ?>" class="destino-img" alt="Imagem">
                                <?php endif; ?>
                                <div class="destino-body">
                                    <div class="destino-titulo"><?= htmlspecialchars($d['titulo']) ?></div>
                                    <div class="destino-data"><?= date('d/m/Y', strtotime($d['criado_em'])) ?></div>
                                    <div class="destino-descricao"><?= nl2br(htmlspecialchars($d['descricao'])) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="destino-nav" id="btnNext" onclick="navegarDestino(1)">→</button>
                </div>
                <div class="destino-dots" id="destinoDots">
                    <?php foreach ($destinos as $i => $d): ?>
                        <span class="dot <?= $i === 0 ? 'active' : '' ?>" onclick="irParaDestino(<?= $i ?>)"></span>
                    <?php endforeach; ?>
                </div>
                <div class="destino-counter">
                    <span id="destinoAtual">1</span> / <?= count($destinos) ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <div class="bottom">
        <a href="feed.php" class="menu-item">🏠<span>Feed</span></a>
        <a href="campanhas.php" class="menu-item">📢<span>Campanhas</span></a>
        <button class="plus-btn" onclick="window.location='<?= $rotaPlus ?>'">+</button>
        <a href="notificacoes.php" class="menu-item">
            🔔<span>Notificações</span>
            <?php if ($total_notificacoes > 0): ?>
                <span class="notification-badge"><?= $total_notificacoes ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= $rotaPerfil ?>" class="menu-item">👤<span>Perfil</span></a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.body.style.overflow = 'hidden';

// Referência ao elemento .phone para confinar os modais
const phoneEl = document.getElementById('phoneWrapper');

const swalOng = Swal.mixin({
    target: phoneEl,
    confirmButtonColor: '#f4822f',
    cancelButtonColor: '#aaa',
    customClass: {
        container: 'swal-inside-ong'
    }
});

// Função para mostrar info de verificação
function mostrarInfoVerificacao() {
    swalOng.fire({
        title: '✅ ONG Verificada',
        html: `
            <div style="text-align:left; font-size:13px; line-height:1.8;">
                <p>Esta ONG passou pelo processo de verificação do <strong>Volunteer Community</strong>:</p>
                <br>
                <p>🪪 <strong>CNPJ validado</strong> na Receita Federal</p>
                <p>🛡️ <strong>Dados conferidos</strong> pela equipe</p>
                <p>✅ <strong>Aprovada</strong> para receber doações</p>
                <br>
                <p style="font-size:11px; color:#aaa;">
                    A verificação garante que esta é uma instituição legítima.
                </p>
            </div>
        `,
        icon: 'success',
        confirmButtonText: 'Entendi',
    });
}

// Função para confirmar doação
async function confirmarDoacao(idOng, nomeOng) {
    const result = await swalOng.fire({
        title: '💛 Fazer uma doação?',
        html: `<p style="font-size:14px; color:#555; margin:0;">Você será direcionado para agendar uma doação para <strong>${nomeOng}</strong>.</p>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '💝 Sim, quero doar!',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#f4822f',
        cancelButtonColor: '#aaa'
    });
    
    if (result.isConfirmed) {
        window.location.href = `agendar_coleta.php?ong=${idOng}&titulo=${encodeURIComponent(nomeOng)}`;
    }
}

// Abas
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        const target = document.getElementById(this.getAttribute('data-tab') + '-tab');
        if (target) target.classList.add('active');
    });
});

// Carrossel
let destinoAtual = 0;
const slides = document.querySelectorAll('.destino-slide');
const dots = document.querySelectorAll('.dot');
const total = slides.length;

function atualizarCarrossel() {
    slides.forEach((s, i) => s.classList.toggle('active', i === destinoAtual));
    dots.forEach((d, i) => d.classList.toggle('active', i === destinoAtual));
    document.getElementById('destinoAtual').textContent = destinoAtual + 1;
    document.getElementById('btnPrev').disabled = destinoAtual === 0;
    document.getElementById('btnNext').disabled = destinoAtual === total - 1;
}

function navegarDestino(dir) {
    if (total === 0) return;
    destinoAtual = Math.max(0, Math.min(destinoAtual + dir, total - 1));
    atualizarCarrossel();
}

function irParaDestino(i) {
    destinoAtual = Math.max(0, Math.min(i, total - 1));
    atualizarCarrossel();
}

if (total > 0) {
    document.getElementById('btnPrev').disabled = true;
}
</script>

</body>
</html>