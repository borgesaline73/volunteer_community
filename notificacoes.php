<?php
session_start();
require "banco.php";

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

$tipo = $_SESSION["usuario_tipo"] ?? null;
$id_usuario = $_SESSION["usuario_id"];

try {
    $teste = $pdo->prepare("SELECT COUNT(*) as total FROM notificacoes WHERE id_usuario = ?");
    $teste->execute([$id_usuario]);
    $total_notif = $teste->fetch(PDO::FETCH_ASSOC);
    error_log("Total na tabela notificacoes para usuário $id_usuario: " . ($total_notif['total'] ?? 0));
} catch (PDOException $e) {
    error_log("Erro no debug: " . $e->getMessage());
}

if ($tipo === "instituicao") {
    $rotaPlus = "criar_post.php";
    $rotaPerfil = "perfil-ong.php";
} else {
    $rotaPlus = "agendar_coleta.php";
    $rotaPerfil = "perfil.php";
}

$notificacoes = [];

try {
    $sql = "SELECT * FROM notificacoes 
            WHERE id_usuario = ? 
            ORDER BY data_envio DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_usuario]);
    $notificacoes_tabela = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($notificacoes_tabela) && $tipo === "instituicao") {
        $sql_coletas = "SELECT d.*, u.nome as nome_doador, u.email as email_doador,
                               c.data_agendada, c.endereco as local_coleta,
                               COALESCE(cv.visualizada, FALSE) as lida
                        FROM doacoes d 
                        JOIN usuarios u ON d.id_doador = u.id_usuario 
                        JOIN coletas c ON d.id_doacao = c.id_doacao
                        LEFT JOIN coletas_visualizadas cv ON d.id_doacao = cv.id_doacao AND cv.id_ong = ?
                        WHERE d.id_ong = ? 
                        AND d.status = 'AGENDADA'
                        ORDER BY c.data_agendada DESC";
        
        $stmt_coletas = $pdo->prepare($sql_coletas);
        $stmt_coletas->execute([$id_usuario, $id_usuario]);
        $coletas = $stmt_coletas->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($coletas as $coleta) {
            $notificacoes[] = [
                'id_notificacao' => 'coleta_' . $coleta['id_doacao'],
                'id_doacao' => $coleta['id_doacao'],
                'mensagem' => $coleta['nome_doador'] . ' agendou uma coleta de ' . $coleta['tipo'] . 
                             ' para ' . date('d/m/Y H:i', strtotime($coleta['data_agendada'])) . 
                             ' no local: ' . $coleta['local_coleta'],
                'data_envio' => $coleta['data_agendada'],
                'lida' => $coleta['lida'],
                'tipo' => 'COLETA_AGENDADA',
                'dados_coleta' => $coleta
            ];
        }
    } else {
        foreach ($notificacoes_tabela as $notif) {
            $notificacoes[] = [
                'id_notificacao' => $notif['id_notificacao'],
                'mensagem' => $notif['mensagem'],
                'data_envio' => $notif['data_envio'],
                'lida' => $notif['lida'],
                'tipo' => $notif['tipo'] ?? null
            ];
        }
    }
    
    $notificacoes_hoje = [];
    $notificacoes_semana = [];
    $notificacoes_anteriores = [];
    
    $hoje = new DateTime();
    $hoje->setTime(0, 0, 0);
    
    $semana_atras = clone $hoje;
    $semana_atras->modify('-7 days');
    
    foreach ($notificacoes as $notificacao) {
        $data = new DateTime($notificacao['data_envio']);
        $data->setTime(0, 0, 0);
        
        if ($data == $hoje) {
            $notificacoes_hoje[] = $notificacao;
        } elseif ($data >= $semana_atras && $data < $hoje) {
            $notificacoes_semana[] = $notificacao;
        } else {
            $notificacoes_anteriores[] = $notificacao;
        }
    }
    
} catch (PDOException $e) {
    $error_db = "Erro ao carregar notificações: " . $e->getMessage();
    error_log("Erro: " . $e->getMessage());
}

$total_nao_lidas = 0;
foreach ($notificacoes_hoje as $notif) {
    if (!$notif['lida']) $total_nao_lidas++;
}
foreach ($notificacoes_semana as $notif) {
    if (!$notif['lida']) $total_nao_lidas++;
}

function formatarDataRelativa($data) {
    $agora = new DateTime();
    $data_notificacao = new DateTime($data);
    $diferenca = $agora->diff($data_notificacao);
    
    if ($diferenca->days == 0) {
        if ($diferenca->h == 0) {
            return $diferenca->i . ' min atrás';
        }
        return $diferenca->h . ' h atrás';
    } elseif ($diferenca->days == 1) {
        return 'Ontem';
    } elseif ($diferenca->days < 7) {
        return $diferenca->days . ' dias atrás';
    } else {
        return $data_notificacao->format('d/m/Y');
    }
}

function getIconeMensagem($mensagem, $tipo = null) {
    if ($tipo === 'COLETA_AGENDADA') return '📦';
    elseif (strpos($mensagem, 'agendou') !== false) return '📦';
    elseif (strpos($mensagem, 'publicou') !== false) return '📢';
    elseif (strpos($mensagem, 'curtiu') !== false) return '❤️';
    elseif (strpos($mensagem, 'comentou') !== false) return '💬';
    else return '🔔';
}

function getTituloMensagem($mensagem, $tipo = null) {
    if ($tipo === 'COLETA_AGENDADA') return 'Nova Coleta Agendada';
    elseif (strpos($mensagem, 'agendou') !== false) return 'Nova Coleta';
    elseif (strpos($mensagem, 'publicou') !== false) return 'Nova Publicação';
    elseif (strpos($mensagem, 'curtiu') !== false) return 'Nova Curtida';
    elseif (strpos($mensagem, 'comentou') !== false) return 'Novo Comentário';
    else return 'Notificação';
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notificações - Volunteer Community</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/estilo_global.css">
<link rel="stylesheet" href="css/estilo_notificacoes.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
.swal2-container.swal-inside-notif {
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    z-index: 9999;
}

.swal2-container.swal-inside-notif .swal2-popup {
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

.phone {
    position: relative;
}
</style>
</head>
<body>

<div class="phone">

  <div class="header">
    <h1>Notificações</h1>
    <button class="clear-btn" id="clear-btn" onclick="marcarTodasComoLidas()"
            <?php if ($total_nao_lidas === 0): ?>disabled style="opacity: 0.5; cursor: not-allowed;"<?php endif; ?>>
      Limpar <?php if ($total_nao_lidas > 0): ?>(<?= $total_nao_lidas ?>)<?php endif; ?>
    </button>
  </div>

  <div class="content">
    <?php if (!empty($notificacoes_hoje)): ?>
    <div class="section">
      <div class="section-title">Hoje</div>
      <div class="list">
        <?php foreach ($notificacoes_hoje as $notificacao): ?>
          <div class="notification-item <?= (!$notificacao['lida']) ? 'unread' : '' ?>" 
               data-id="<?= htmlspecialchars($notificacao['id_notificacao']) ?>"
               data-doacao="<?= htmlspecialchars($notificacao['id_doacao'] ?? '') ?>">
            <div class="notification-header">
              <div class="notification-icon">
                <?= getIconeMensagem($notificacao['mensagem'], $notificacao['tipo'] ?? null) ?>
              </div>
              <div class="notification-content">
                <div class="notification-title">
                  <?= getTituloMensagem($notificacao['mensagem'], $notificacao['tipo'] ?? null) ?>
                </div>
                <div class="notification-message">
                  <?= htmlspecialchars($notificacao['mensagem']) ?>
                </div>
                <?php if (isset($notificacao['dados_coleta'])): ?>
                  <div class="notification-details">
                    <div><strong>Doador:</strong> <?= htmlspecialchars($notificacao['dados_coleta']['nome_doador']) ?></div>
                    <div><strong>Email:</strong> <?= htmlspecialchars($notificacao['dados_coleta']['email_doador']) ?></div>
                    <div><strong>Tipo:</strong> <?= htmlspecialchars($notificacao['dados_coleta']['tipo']) ?></div>
                  </div>
                <?php endif; ?>
                <div class="notification-time">
                  <?= formatarDataRelativa($notificacao['data_envio']) ?>
                  <?php if ($notificacao['lida']): ?>
                    <span class="visualizada-badge">✓ Visualizada</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($notificacoes_semana)): ?>
    <div class="section">
      <div class="section-title">Últimos 7 dias</div>
      <div class="list">
        <?php foreach ($notificacoes_semana as $notificacao): ?>
          <div class="notification-item <?= (!$notificacao['lida']) ? 'unread' : '' ?>"
               data-id="<?= htmlspecialchars($notificacao['id_notificacao']) ?>"
               data-doacao="<?= htmlspecialchars($notificacao['id_doacao'] ?? '') ?>">
            <div class="notification-header">
              <div class="notification-icon">
                <?= getIconeMensagem($notificacao['mensagem'], $notificacao['tipo'] ?? null) ?>
              </div>
              <div class="notification-content">
                <div class="notification-title">
                  <?= getTituloMensagem($notificacao['mensagem'], $notificacao['tipo'] ?? null) ?>
                </div>
                <div class="notification-message">
                  <?= htmlspecialchars($notificacao['mensagem']) ?>
                </div>
                <?php if (isset($notificacao['dados_coleta'])): ?>
                  <div class="notification-details">
                    <div><strong>Doador:</strong> <?= htmlspecialchars($notificacao['dados_coleta']['nome_doador']) ?></div>
                    <div><strong>Email:</strong> <?= htmlspecialchars($notificacao['dados_coleta']['email_doador']) ?></div>
                    <div><strong>Tipo:</strong> <?= htmlspecialchars($notificacao['dados_coleta']['tipo']) ?></div>
                  </div>
                <?php endif; ?>
                <div class="notification-time">
                  <?= formatarDataRelativa($notificacao['data_envio']) ?>
                  <?php if ($notificacao['lida']): ?>
                    <span class="visualizada-badge">✓ Visualizada</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($notificacoes_anteriores)): ?>
    <div class="section">
      <div class="section-title">Mais antigas</div>
      <div class="list">
        <?php foreach ($notificacoes_anteriores as $notificacao): ?>
          <div class="notification-item"
               data-id="<?= htmlspecialchars($notificacao['id_notificacao']) ?>"
               data-doacao="<?= htmlspecialchars($notificacao['id_doacao'] ?? '') ?>">
            <div class="notification-header">
              <div class="notification-icon">
                <?= getIconeMensagem($notificacao['mensagem'], $notificacao['tipo'] ?? null) ?>
              </div>
              <div class="notification-content">
                <div class="notification-title">
                  <?= getTituloMensagem($notificacao['mensagem'], $notificacao['tipo'] ?? null) ?>
                </div>
                <div class="notification-message">
                  <?= htmlspecialchars($notificacao['mensagem']) ?>
                </div>
                <?php if (isset($notificacao['dados_coleta'])): ?>
                  <div class="notification-details">
                    <div><strong>Doador:</strong> <?= htmlspecialchars($notificacao['dados_coleta']['nome_doador']) ?></div>
                    <div><strong>Email:</strong> <?= htmlspecialchars($notificacao['dados_coleta']['email_doador']) ?></div>
                    <div><strong>Tipo:</strong> <?= htmlspecialchars($notificacao['dados_coleta']['tipo']) ?></div>
                  </div>
                <?php endif; ?>
                <div class="notification-time">
                  <?= formatarDataRelativa($notificacao['data_envio']) ?>
                  <?php if ($notificacao['lida']): ?>
                    <span class="visualizada-badge">✓ Visualizada</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (empty($notificacoes_hoje) && empty($notificacoes_semana) && empty($notificacoes_anteriores)): ?>
    <div class="empty-box">
      📭<br>
      <strong>Nenhuma notificação</strong><br>
      <small>Suas notificações aparecerão aqui</small>
    </div>
    <?php endif; ?>
  </div>

  <div class="bottom">
    <a href="feed.php" class="menu-item">🏠<span>Feed</span></a>
    <a href="campanhas.php" class="menu-item">📢<span>Campanhas</span></a>
    <button class="plus-btn" onclick="window.location.href='<?= $rotaPlus ?>'">+</button>
    <a href="notificacoes.php" class="menu-item active">
      🔔<span>Notificações</span>
      <?php if ($total_nao_lidas > 0): ?>
        <span class="badge" id="badge-count"><?= $total_nao_lidas ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= $rotaPerfil ?>" class="menu-item">👤<span>Perfil</span></a>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const tipoUsuario = "<?= $tipo ?>";
const phoneEl = document.querySelector('.phone');

const swalNotif = Swal.mixin({
    target: phoneEl,
    confirmButtonColor: '#f4822f',
    cancelButtonColor: '#aaa',
    customClass: {
        container: 'swal-inside-notif',
        popup: 'swal-popup-notif'
    }
});

document.querySelectorAll('.notification-item').forEach(item => {
    item.addEventListener('click', function(e) {
        if (e.target.classList.contains('notification-details')) return;
        const idNotificacao = this.dataset.id;
        const idDoacao = this.dataset.doacao;
        if (this.classList.contains('unread')) {
            marcarComoLida(idNotificacao, idDoacao, this);
        }
    });
});

async function marcarComoLida(idNotificacao, idDoacao, elemento) {
    elemento.classList.remove('unread');
    
    const timeElement = elemento.querySelector('.notification-time');
    if (timeElement && !timeElement.querySelector('.visualizada-badge')) {
        const badge = document.createElement('span');
        badge.className = 'visualizada-badge';
        badge.innerHTML = '✓ Visualizada';
        timeElement.appendChild(badge);
    }
    
    try {
        let url, body;
        if (tipoUsuario === "instituicao" && idDoacao) {
            url = 'marcar_coleta_visualizada.php';
            body = new FormData();
            body.append('id_doacao', idDoacao);
        } else {
            url = 'marcar_como_lida.php';
            body = new FormData();
            body.append('id_notificacao', idNotificacao);
        }
        
        const response = await fetch(url, { method: 'POST', body });
        const data = await response.json();
        
        if (!data.success) {
            elemento.classList.add('unread');
            if (timeElement.querySelector('.visualizada-badge')) {
                timeElement.querySelector('.visualizada-badge').remove();
            }
        } else {
            atualizarContadorNotificacoes();
            atualizarBadgeMenu();
        }
    } catch (error) {
        elemento.classList.add('unread');
        if (timeElement.querySelector('.visualizada-badge')) {
            timeElement.querySelector('.visualizada-badge').remove();
        }
    }
}

async function marcarTodasComoLidas() {
    const confirmMessage = tipoUsuario === "instituicao" 
        ? 'Marcar TODAS as coletas como visualizadas?' 
        : 'Marcar TODAS as notificações como lidas?';

    const result = await swalNotif.fire({
        title: '🔔 Limpar notificações',
        text: confirmMessage,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '✅ Confirmar',
        cancelButtonText: '❌ Cancelar'
    });

    if (result.isConfirmed) {
        await _marcarTodasNoBanco();
    }
}

async function marcarTodasAoAbrir() {
    const unreadItems = document.querySelectorAll('.notification-item.unread');
    if (unreadItems.length === 0) return;

    try {
    
        const response = await fetch('marcar_todas_lidas.php', { method: 'POST' });

        if (!response.ok) return;

        const text = await response.text();
        if (!text) return;

        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Resposta inválida:', text);
            return;
        }

        if (data.success) {
            unreadItems.forEach(item => {
                item.classList.remove('unread');
                const timeElement = item.querySelector('.notification-time');
                if (timeElement && !timeElement.querySelector('.visualizada-badge')) {
                    const badge = document.createElement('span');
                    badge.className = 'visualizada-badge';
                    badge.innerHTML = '✓ Visualizada';
                    timeElement.appendChild(badge);
                }
            });

            const clearBtn = document.getElementById('clear-btn');
            if (clearBtn) {
                clearBtn.innerHTML = 'Limpar';
                clearBtn.disabled = true;
                clearBtn.style.opacity = '0.5';
            }

            const badge = document.getElementById('badge-count');
            if (badge) badge.style.display = 'none';

            atualizarBadgeMenu();
        }
    } catch (error) {
        console.error('Erro ao marcar notificações ao abrir:', error);
    }
}

async function _marcarTodasNoBanco() {
    try {
      
        const response = await fetch('marcar_todas_lidas.php', { method: 'POST' });

        if (!response.ok) {
            await swalNotif.fire({
                title: '⚠️ Erro',
                text: 'Erro ao conectar com o servidor',
                icon: 'error',
                confirmButtonText: 'Ok'
            });
            return;
        }

        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            await swalNotif.fire({
                title: '⚠️ Erro',
                text: 'Resposta inválida do servidor',
                icon: 'error',
                confirmButtonText: 'Ok'
            });
            return;
        }

        if (data.success) {
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
                const timeElement = item.querySelector('.notification-time');
                if (timeElement && !timeElement.querySelector('.visualizada-badge')) {
                    const badge = document.createElement('span');
                    badge.className = 'visualizada-badge';
                    badge.innerHTML = '✓ Visualizada';
                    timeElement.appendChild(badge);
                }
            });
            
            atualizarContadorNotificacoes();
            atualizarBadgeMenu();
            
            const clearBtn = document.getElementById('clear-btn');
            if (clearBtn) {
                clearBtn.innerHTML = 'Limpar';
                clearBtn.disabled = true;
                clearBtn.style.opacity = '0.5';
            }

            await swalNotif.fire({
                title: '✅ Pronto!',
                text: data.message || 'Todas as notificações foram marcadas como lidas!',
                icon: 'success',
                confirmButtonText: 'Ok',
                timer: 2500,
                timerProgressBar: true
            });
        } else {
            await swalNotif.fire({
                title: '⚠️ Erro',
                text: data.error || 'Tente novamente',
                icon: 'error',
                confirmButtonText: 'Ok'
            });
        }
    } catch (error) {
        await swalNotif.fire({
            title: '⚠️ Erro',
            text: 'Erro ao conectar com o servidor',
            icon: 'error',
            confirmButtonText: 'Ok'
        });
    }
}

function atualizarContadorNotificacoes() {
    const unreadCount = document.querySelectorAll('.notification-item.unread').length;
    const badge = document.getElementById('badge-count');
    const clearBtn = document.getElementById('clear-btn');
    
    if (unreadCount > 0) {
        if (badge) {
            badge.textContent = unreadCount;
            badge.style.display = 'inline-flex';
        }
        if (clearBtn) {
            clearBtn.innerHTML = `Limpar (${unreadCount})`;
            clearBtn.disabled = false;
            clearBtn.style.opacity = '1';
        }
    } else {
        if (badge) badge.style.display = 'none';
        if (clearBtn) {
            clearBtn.innerHTML = 'Limpar';
            clearBtn.disabled = true;
            clearBtn.style.opacity = '0.5';
        }
    }
}

async function atualizarBadgeMenu() {
    try {
        const response = await fetch('contar_notificacoes.php');
        const data = await response.json();
        
        const menuBadge = document.querySelector('.bottom .menu-item[href="notificacoes.php"] .badge');
        const menuLink = document.querySelector('.bottom .menu-item[href="notificacoes.php"]');
        
        if (data.total > 0) {
            if (menuBadge) {
                menuBadge.textContent = data.total;
                menuBadge.style.display = 'inline-flex';
            } else if (menuLink) {
                const span = document.createElement('span');
                span.className = 'badge';
                span.textContent = data.total;
                menuLink.appendChild(span);
            }
        } else {
            if (menuBadge) menuBadge.style.display = 'none';
        }
    } catch (error) {
        console.error('Erro ao atualizar badge:', error);
    }
}

document.addEventListener('DOMContentLoaded', function() {
   marcarTodasAoAbrir();
    atualizarContadorNotificacoes();
    document.body.style.overflow = 'hidden';
});
//  Marca como lidas quando o usuário SAIR da página
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'hidden') {
        navigator.sendBeacon('marcar_todas_lidas.php');
    }
});

//  Também marca ao navegar para outra página
window.addEventListener('beforeunload', function() {
    navigator.sendBeacon('marcar_todas_lidas.php');
});
</script>

</body>
</html>