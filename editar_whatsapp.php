<?php
session_start();
require "banco.php";

// Verificar se usuário está logado e é instituição
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
$mensagem = '';
$tipo_mensagem = '';

// Buscar WhatsApp atual
$whatsapp_atual = '';
try {
    $stmt = $pdo->prepare("SELECT whatsapp FROM ongs WHERE id_ong = ?");
    $stmt->execute([$id_ong]);
    $whatsapp_atual = $stmt->fetchColumn() ?? '';
} catch (PDOException $e) {
    $mensagem = "Erro ao buscar dados: " . $e->getMessage();
    $tipo_mensagem = "error";
}

// Processar formulário
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $whatsapp = trim($_POST["whatsapp"] ?? "");
    
    // Remover tudo que não é número
    $whatsapp_limpo = preg_replace('/\D/', '', $whatsapp);
    
    // Validação: deve ter entre 10 e 11 dígitos (DDD + número)
    if (!empty($whatsapp_limpo) && (strlen($whatsapp_limpo) < 10 || strlen($whatsapp_limpo) > 11)) {
        $mensagem = "⚠️ WhatsApp inválido! Digite um número com DDD (ex: 11999999999)";
        $tipo_mensagem = "error";
    } else {
        try {
            if (empty($whatsapp_limpo)) {
                $stmt = $pdo->prepare("UPDATE ongs SET whatsapp = NULL WHERE id_ong = ?");
                $stmt->execute([$id_ong]);
                $mensagem = "✅ WhatsApp removido com sucesso!";
                $tipo_mensagem = "success";
                $whatsapp_atual = '';
            } else {
                $stmt = $pdo->prepare("UPDATE ongs SET whatsapp = ? WHERE id_ong = ?");
                $stmt->execute([$whatsapp_limpo, $id_ong]);
                $mensagem = "✅ WhatsApp cadastrado com sucesso!";
                $tipo_mensagem = "success";
                $whatsapp_atual = $whatsapp_limpo;
            }
        } catch (PDOException $e) {
            $mensagem = "❌ Erro ao salvar: " . $e->getMessage();
            $tipo_mensagem = "error";
        }
    }
}

function formatarWhatsapp($numero) {
    if (empty($numero)) return '';
    $numero = preg_replace('/\D/', '', $numero);
    if (strlen($numero) == 11) {
        return '(' . substr($numero, 0, 2) . ') ' . substr($numero, 2, 5) . '-' . substr($numero, 7, 4);
    } elseif (strlen($numero) == 10) {
        return '(' . substr($numero, 0, 2) . ') ' . substr($numero, 2, 4) . '-' . substr($numero, 6, 4);
    }
    return $numero;
}

// Buscar total de notificações
$total_notificacoes = 0;
try {
    $stmt_notif = $pdo->prepare("SELECT COUNT(*) as total FROM notificacoes WHERE id_usuario = ? AND lida = FALSE");
    $stmt_notif->execute([$id_ong]);
    $total_notificacoes = $stmt_notif->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Meu WhatsApp - Volunteer Community</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/estilo_global.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
    /* APENAS PARA ESTA PÁGINA - CENTRALIZAÇÃO */
    body {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        padding: 20px;
        margin: 0;
        background: var(--bg);
    }
    
    .phone {
        width: 100%;
        max-width: 430px;
        margin: 0 auto;
        position: relative;
        overflow: hidden;
    }
    
    .whatsapp-card {
        background: #fff;
        border-radius: 24px;
        padding: 28px 20px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        text-align: center;
        margin: 20px;
    }
    
    .whatsapp-icon {
        font-size: 64px;
        margin-bottom: 16px;
    }
    
    .whatsapp-title {
        font-size: 22px;
        font-weight: 700;
        margin-bottom: 8px;
        color: #2b2b2b;
    }
    
    .whatsapp-sub {
        color: #888;
        font-size: 13px;
        margin-bottom: 24px;
    }
    
    .whatsapp-preview {
        background: #e8f5e9;
        border-radius: 16px;
        padding: 16px;
        margin: 20px 0;
        text-align: center;
    }
    
    .whatsapp-preview .label {
        font-size: 12px;
        color: #2e7d32;
        font-weight: 600;
        margin-bottom: 8px;
    }
    
    .whatsapp-preview .numero {
        font-size: 20px;
        font-weight: 700;
        color: #25D366;
        font-family: monospace;
    }
    
    .field {
        margin-bottom: 20px;
        text-align: left;
    }
    
    .field label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #555;
        margin-bottom: 8px;
    }
    
    .field input {
        width: 100%;
        padding: 14px 16px;
        border: 1.5px solid #e0e0e0;
        border-radius: 16px;
        font-size: 16px;
        font-family: 'Poppins', sans-serif;
        transition: all 0.2s;
        box-sizing: border-box;
    }
    
    .field input:focus {
        outline: none;
        border-color: #25D366;
        box-shadow: 0 0 0 3px rgba(37,211,102,0.1);
    }
    
    .field small {
        display: block;
        font-size: 11px;
        color: #888;
        margin-top: 6px;
    }
    
    .btn-salvar {
        background: linear-gradient(135deg, #25D366, #128C7E);
        color: white;
        border: none;
        border-radius: 50px;
        padding: 14px 24px;
        font-weight: 700;
        font-size: 16px;
        cursor: pointer;
        width: 100%;
        margin-top: 10px;
        transition: transform 0.2s;
    }
    
    .btn-salvar:hover {
        transform: translateY(-2px);
    }
    
    .btn-remover {
        background: #f8d7da;
        color: #721c24;
        border: 1.5px solid #f5c6cb;
        border-radius: 50px;
        padding: 12px 24px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        width: 100%;
        margin-top: 12px;
        transition: all 0.2s;
    }
    
    .btn-remover:hover {
        background: #f5c6cb;
    }
    
    .btn-voltar {
        background: transparent;
        color: #f4822f;
        border: 1.5px solid #f4822f;
        border-radius: 50px;
        padding: 12px 24px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        width: 100%;
        margin-top: 12px;
        transition: all 0.2s;
    }
    
    .btn-voltar:hover {
        background: #f4822f10;
    }
    
    .success-message {
        background: #d4edda;
        color: #155724;
        border-radius: 12px;
        padding: 12px 16px;
        margin-bottom: 20px;
        font-size: 13px;
        text-align: center;
    }
    
    .error-message {
        background: #f8d7da;
        color: #721c24;
        border-radius: 12px;
        padding: 12px 16px;
        margin-bottom: 20px;
        font-size: 13px;
        text-align: center;
    }
    
    .dica-box {
        text-align: center;
        margin: 20px;
        padding: 16px;
        background: #f0f8ff;
        border-radius: 16px;
        font-size: 12px;
        color: #4a5568;
    }
    
    .dica-box p {
        margin: 0;
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
    }
    
    .main-content {
        flex: 1;
        overflow-y: auto;
        padding-bottom: 80px;
    }
</style>
</head>
<body>
<div class="phone" id="phoneWrapper">
    <div class="header">
        <span onclick="history.back()" style="cursor:pointer;">⬅</span>
        <div class="header-title">Meu WhatsApp</div>
        <span style="visibility:hidden;">⚙️</span>
    </div>

    <div class="main-content">
        <div class="whatsapp-card">
            <div class="whatsapp-icon">💬</div>
            <div class="whatsapp-title">WhatsApp da ONG</div>
            <div class="whatsapp-sub">
                Os doadores poderão entrar em contato diretamente com você pelo WhatsApp
            </div>

            <?php if (!empty($mensagem)): ?>
                <div class="<?= $tipo_mensagem === 'success' ? 'success-message' : 'error-message' ?>">
                    <?= htmlspecialchars($mensagem) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($whatsapp_atual)): ?>
                <div class="whatsapp-preview">
                    <div class="label">📱 Número atual cadastrado</div>
                    <div class="numero"><?= htmlspecialchars(formatarWhatsapp($whatsapp_atual)) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="whatsappForm">
                <div class="field">
                    <label>WhatsApp (com DDD)</label>
                    <input type="tel" name="whatsapp" id="whatsapp" 
                           placeholder="Ex: 11999999999"
                           value="<?= htmlspecialchars($whatsapp_atual) ?>"
                           maxlength="15">
                    <small>📌 Digite apenas números com DDD (10 ou 11 dígitos)</small>
                </div>

                <button type="submit" class="btn-salvar" id="btnSalvar">
                    💾 Salvar WhatsApp
                </button>
            </form>

            <?php if (!empty($whatsapp_atual)): ?>
                <button class="btn-remover" onclick="confirmarRemocao()">
                    🗑️ Remover WhatsApp
                </button>
            <?php endif; ?>

            <button class="btn-voltar" onclick="window.location.href='perfil-ong.php'">
                ← Voltar ao Perfil
            </button>
        </div>

        <div class="dica-box">
            <p>💡 <strong>Dica:</strong> Ao cadastrar seu WhatsApp, um botão aparecerá no seu perfil público<br>para que os doadores possam falar diretamente com você.</p>
        </div>
    </div>

    <div class="bottom">
        <a href="feed.php" class="menu-item">🏠<span>Feed</span></a>
        <a href="campanhas.php" class="menu-item">📢<span>Campanhas</span></a>
        <button class="plus-btn" onclick="window.location.href='criar_post.php'">+</button>
        <a href="notificacoes.php" class="menu-item">
            🔔<span>Notificações</span>
            <?php if ($total_notificacoes > 0): ?>
                <span class="notification-badge"><?= $total_notificacoes ?></span>
            <?php endif; ?>
        </a>
        <a href="perfil-ong.php" class="menu-item" style="color: var(--orange);">👤<span>Perfil</span></a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.body.style.overflow = 'hidden';

const phoneEl = document.getElementById('phoneWrapper');

const swal = Swal.mixin({
    target: phoneEl,
    confirmButtonColor: '#f4822f',
    cancelButtonColor: '#aaa',
    customClass: { container: 'swal-inside-phone' }
});

async function confirmarRemocao() {
    const result = await swal.fire({
        title: 'Remover WhatsApp?',
        text: 'Seu número será removido e os doadores não poderão mais te contatar pelo WhatsApp.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '🗑️ Sim, remover',
        cancelButtonText: 'Cancelar'
    });
    
    if (result.isConfirmed) {
        const form = document.getElementById('whatsappForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'whatsapp';
        input.value = '';
        form.appendChild(input);
        
        document.getElementById('btnSalvar').disabled = true;
        document.getElementById('btnSalvar').textContent = '⏳ Removendo...';
        form.submit();
    }
}

// Máscara para WhatsApp
document.getElementById('whatsapp').addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '');
    if (v.length > 11) v = v.slice(0, 11);
    
    if (v.length <= 2) {
        
    } else if (v.length <= 7) {
        v = '(' + v.slice(0, 2) + ') ' + v.slice(2);
    } else if (v.length <= 11) {
        v = '(' + v.slice(0, 2) + ') ' + v.slice(2, 7) + '-' + v.slice(7);
    }
    e.target.value = v;
});

// Fechar mensagens após 5 segundos
setTimeout(() => {
    const msg = document.querySelector('.success-message, .error-message');
    if (msg) {
        msg.style.transition = 'opacity 0.5s';
        msg.style.opacity = '0';
        setTimeout(() => msg.style.display = 'none', 500);
    }
}, 5000);
</script>
</body>
</html>