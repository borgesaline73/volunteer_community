<?php
session_start();
require "banco.php";

// Bloqueio para usuários não logados
if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

// Tipo do usuário
$tipo = $_SESSION["usuario_tipo"] ?? null;
$id_usuario = $_SESSION["usuario_id"];

// DEBUG: Verificar se há notificações no banco (CORRIGIDO)
try {
    $teste = $pdo->prepare("SELECT COUNT(*) as total FROM notificacoes WHERE id_usuario = ?");
    $teste->execute([$id_usuario]);
    $total_notif = $teste->fetch(PDO::FETCH_ASSOC);
    error_log("Total na tabela notificacoes para usuário $id_usuario: " . ($total_notif['total'] ?? 0));
} catch (PDOException $e) {
    error_log("Erro no debug: " . $e->getMessage());
}

// Rota do botão + e perfil
if ($tipo === "instituicao") {
    $rotaPlus = "criar_post.php";
    $rotaPerfil = "perfil-ong.php";
} else {
    $rotaPlus = "agendar_coleta.php";
    $rotaPerfil = "perfil.php";
}

// Buscar TODAS as notificações
$notificacoes = [];

try {
    // Buscar notificações da tabela notificacoes
    $sql = "SELECT * FROM notificacoes 
            WHERE id_usuario = ? 
            ORDER BY data_envio DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_usuario]);
    $notificacoes_tabela = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Se for instituição e não encontrou notificações, buscar coletas
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
    
    // Separar por período
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

// Calcular total de não lidas
$total_nao_lidas = 0;
foreach ($notificacoes_hoje as $notif) {
    if (!$notif['lida']) $total_nao_lidas++;
}
foreach ($notificacoes_semana as $notif) {
    if (!$notif['lida']) $total_nao_lidas++;
}

// Funções auxiliares
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
    if ($tipo === 'COLETA_AGENDADA') {
        return '📦';
    } elseif (strpos($mensagem, 'agendou') !== false) {
        return '📦';
    } elseif (strpos($mensagem, 'publicou') !== false) {
        return '📢';
    } elseif (strpos($mensagem, 'curtiu') !== false) {
        return '❤️';
    } elseif (strpos($mensagem, 'comentou') !== false) {
        return '💬';
    } else {
        return '🔔';
    }
}

function getTituloMensagem($mensagem, $tipo = null) {
    if ($tipo === 'COLETA_AGENDADA') {
        return 'Nova Coleta Agendada';
    } elseif (strpos($mensagem, 'agendou') !== false) {
        return 'Nova Coleta';
    } elseif (strpos($mensagem, 'publicou') !== false) {
        return 'Nova Publicação';
    } elseif (strpos($mensagem, 'curtiu') !== false) {
        return 'Nova Curtida';
    } elseif (strpos($mensagem, 'comentou') !== false) {
        return 'Novo Comentário';
    } else {
        return 'Notificação';
    }
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

  <!-- MENU INFERIOR -->
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

<script>
const tipoUsuario = "<?= $tipo ?>";

// Marcar notificação como lida ao clicar
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
    
    if (!confirm(confirmMessage)) return;
    
    try {
        let url = 'marcar_todas_notificacoes.php';
        
        const response = await fetch(url, { method: 'POST' });
        const data = await response.json();
        
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
            
            alert(data.message || 'Todas as notificações foram marcadas como lidas!');
        } else {
            alert('Erro: ' + (data.error || 'Tente novamente'));
        }
    } catch (error) {
        alert('Erro ao conectar com o servidor');
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
    atualizarContadorNotificacoes();
    atualizarBadgeMenu();
    document.body.style.overflow = 'hidden';
});
</script>

</body>
</html>