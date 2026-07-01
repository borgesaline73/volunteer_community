<?php
session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

if (($_SESSION["usuario_tipo"] ?? "") !== "doador") {
    header("Location: feed.php");
    exit;
}

require "banco.php";

$nome      = $_SESSION["usuario_nome"] ?? "Usuário";
$id_doador = $_SESSION["usuario_id"];

$id_ong     = $_GET['ong']    ?? null;
$titulo_ong = $_GET['titulo'] ?? '';

$mensagem_sucesso = '';
if (isset($_SESSION['agendamento_sucesso'])) {
    $mensagem_sucesso = $_SESSION['agendamento_sucesso'];
    unset($_SESSION['agendamento_sucesso']);
}

// Buscar todas as ONGs
$ongs = [];
try {
    $stmt_check = $pdo->query("SELECT column_name FROM information_schema.columns 
                               WHERE table_name = 'ongs' AND column_name = 'categoria'");
    $categoria_exists = $stmt_check->fetch(PDO::FETCH_ASSOC);

    $sql_ongs = $categoria_exists
        ? "SELECT u.id_usuario, u.nome, u.email, u.cpf_cnpj, o.endereco, o.descricao, o.categoria, o.chave_pix, o.whatsapp 
           FROM usuarios u LEFT JOIN ongs o ON u.id_usuario = o.id_ong 
           WHERE u.tipo_usuario = 'instituicao' ORDER BY u.nome ASC"
        : "SELECT u.id_usuario, u.nome, u.email, u.cpf_cnpj, o.endereco, o.descricao, o.chave_pix, o.whatsapp 
           FROM usuarios u LEFT JOIN ongs o ON u.id_usuario = o.id_ong 
           WHERE u.tipo_usuario = 'instituicao' ORDER BY u.nome ASC";

    $stmt = $pdo->prepare($sql_ongs);
    $stmt->execute();
    $ongs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("ERRO ao buscar ONGs: " . $e->getMessage());
    try {
        $stmt_simple = $pdo->prepare("SELECT u.id_usuario, u.nome, u.email, u.cpf_cnpj 
                                      FROM usuarios u WHERE u.tipo_usuario = 'instituicao' ORDER BY u.nome ASC");
        $stmt_simple->execute();
        $ongs = $stmt_simple->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        error_log("ERRO na consulta simples: " . $e2->getMessage());
    }
}

function buscarOng($pdo, $id) {
    $stmt = $pdo->prepare("SELECT u.nome, u.email, u.cpf_cnpj, o.endereco, o.id_ong, o.descricao, o.chave_pix, o.whatsapp
                           FROM usuarios u LEFT JOIN ongs o ON u.id_usuario = o.id_ong 
                           WHERE u.id_usuario = ? AND u.tipo_usuario = 'instituicao'");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Buscar/criar doador
$id_doador_table = null;
try {
    $stmt = $pdo->prepare("SELECT id_doador FROM doadores WHERE id_doador = ?");
    $stmt->execute([$id_doador]);
    $doador_info     = $stmt->fetch(PDO::FETCH_ASSOC);
    $id_doador_table = $doador_info['id_doador'] ?? null;
    if (!$id_doador_table) {
        $stmt = $pdo->prepare("INSERT INTO doadores (id_doador, data_cadastro) VALUES (?, CURRENT_DATE)");
        $stmt->execute([$id_doador]);
        $id_doador_table = $id_doador;
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar/criar doador: " . $e->getMessage());
}

$data_selecionada    = $_POST['data_coleta']   ?? '';
$horario_selecionado = $_POST['horario']        ?? '';
$local_selecionado   = $_POST['local_coleta']   ?? '';
$tipo_doacao         = $_POST['tipo_doacao']    ?? 'ITEM';
$descricao_item      = $_POST['descricao_item'] ?? '';
$valor_doacao        = $_POST['valor_doacao']   ?? '';
$ong_escolhida       = $_POST['ong_escolhida']  ?? $id_ong ?? '';
$buscar_ong          = $_POST['buscar_ong']     ?? '';
$mensagem            = '';
$ong_selecionada     = null;

// Processar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['escolher_ong'])) {
        $ong_escolhida = $_POST['ong_escolhida'] ?? '';
        if ($ong_escolhida) {
            try {
                $ong_selecionada = buscarOng($pdo, $ong_escolhida);
            } catch (PDOException $e) {
                $mensagem = '<div class="error-message">❌ Erro ao buscar informações da ONG.</div>';
            }
        }

    } elseif (isset($_POST['agendar'])) {

        $is_pix = ($tipo_doacao === 'DINHEIRO' && !empty($_POST['chave_pix_ong']));

        if (empty($ong_escolhida)) {
            $mensagem = '<div class="error-message">Selecione uma ONG para doação!</div>';
        } elseif (!$is_pix && (empty($data_selecionada) || empty($horario_selecionado) || empty($local_selecionado))) {
            $mensagem = '<div class="error-message">Preencha todos os campos!</div>';
        } elseif (!$id_doador_table) {
            $mensagem = '<div class="error-message">❌ Erro: Doador não encontrado.</div>';
        } else {
            try {
                $pdo->beginTransaction();

                if ($is_pix) {
                    // Doação PIX: data hoje, local "PIX", status PENDENTE_PIX
                    $data_hora_agendada = date('Y-m-d H:i:s');
                    $local_coleta_pix   = 'PIX';
                    $valor              = !empty($valor_doacao) ? floatval($valor_doacao) : null;

                    $stmt_doacao = $pdo->prepare("INSERT INTO doacoes (id_doador, id_ong, tipo, status, descricao_item, valor, metodo_pagamento, status_pagamento, data_criacao) 
                                                  VALUES (?, ?, 'DINHEIRO', 'PENDENTE_PIX', NULL, ?, 'PIX', 'PENDENTE', CURRENT_TIMESTAMP)");
                    $stmt_doacao->execute([$id_doador_table, $ong_escolhida, $valor]);
                    $id_doacao = $pdo->lastInsertId();

                    $stmt_coleta = $pdo->prepare("INSERT INTO coletas (id_doacao, tipo, endereco, data_agendada) 
                                                  VALUES (?, 'COLETA', ?, ?)");
                    $stmt_coleta->execute([$id_doacao, $local_coleta_pix, $data_hora_agendada]);

                } else {
                    // Doação normal (ITEM ou DINHEIRO sem PIX)
                    $data_hora_agendada  = $data_selecionada . ' ' . $horario_selecionado . ':00';
                    $valor               = ($tipo_doacao === 'DINHEIRO' && !empty($valor_doacao)) ? floatval($valor_doacao) : null;
                    $descricao           = ($tipo_doacao === 'ITEM') ? $descricao_item : null;
                    $metodo_pagamento    = ($tipo_doacao === 'DINHEIRO') ? 'PIX' : 'DIRETO';
                    $status_pagamento    = ($tipo_doacao === 'DINHEIRO') ? 'PENDENTE' : null;

                    $stmt_doacao = $pdo->prepare("INSERT INTO doacoes (id_doador, id_ong, tipo, status, descricao_item, valor, metodo_pagamento, status_pagamento, data_criacao) 
                                                  VALUES (?, ?, ?, 'AGENDADA', ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                    $stmt_doacao->execute([$id_doador_table, $ong_escolhida, $tipo_doacao, $descricao, $valor, $metodo_pagamento, $status_pagamento]);
                    $id_doacao = $pdo->lastInsertId();

                    $stmt_coleta = $pdo->prepare("INSERT INTO coletas (id_doacao, tipo, endereco, data_agendada) 
                                                  VALUES (?, 'COLETA', ?, ?)");
                    $stmt_coleta->execute([$id_doacao, $local_selecionado, $data_hora_agendada]);
                }

                // Notificação para a ONG
                $nome_doador          = $_SESSION["usuario_nome"] ?? 'Doador';
                $msg_notif            = $is_pix
                    ? "{$nome_doador} registrou uma doação via PIX" . (!empty($valor_doacao) ? " de R$ " . number_format($valor_doacao, 2, ',', '.') : "") . ". Confirme quando o valor chegar."
                    : "{$nome_doador} agendou uma coleta de {$tipo_doacao} para " . date('d/m/Y H:i', strtotime($data_hora_agendada)) . " no local: {$local_selecionado}";

                $stmt_notif = $pdo->prepare("INSERT INTO notificacoes (id_usuario, mensagem, tipo) 
                                             VALUES (?, ?, 'COLETA_AGENDADA')");
                $stmt_notif->execute([$ong_escolhida, $msg_notif]);

                $pdo->commit();

                // Redirecionar com parâmetro de sucesso
                $tipo_sucesso = $is_pix ? 'pix' : 'normal';
                header("Location: agendar_coleta.php?sucesso=1&ong=" . $ong_escolhida . "&tipo=" . $tipo_sucesso);
                exit;

            } catch (PDOException $e) {
                $pdo->rollBack();
                $mensagem = '<div class="error-message">❌ Erro ao agendar: ' . $e->getMessage() . '</div>';
                error_log("Erro no agendamento: " . $e->getMessage());
            }
        }
    }
}

// Buscar ONG via GET
if ($id_ong && $_SERVER['REQUEST_METHOD'] !== 'POST' && !$ong_selecionada) {
    try { $ong_selecionada = buscarOng($pdo, $id_ong); } catch (PDOException $e) {}
}
if (!$ong_selecionada && !empty($ong_escolhida)) {
    try { $ong_selecionada = buscarOng($pdo, $ong_escolhida); } catch (PDOException $e) {}
}

$mostrar_formulario = !empty($ong_escolhida);
$ong_tem_pix        = !empty($ong_selecionada['chave_pix']);
$whatsapp_ong       = $ong_selecionada['whatsapp'] ?? '';

$meses = [
    1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',
    5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',
    9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'
];
$ano_atual = date('Y');
$mes_atual = (int)date('m');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Agendar Coleta - Volunteer Community</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/estilo_global.css">
<link rel="stylesheet" href="css/estilo_agendar_coleta.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
  .phone { position: relative; overflow: hidden; }

  .swal2-container.swal-inside-agendar {
    position: absolute !important; top: 0 !important; left: 0 !important;
    width: 100% !important; height: 100% !important; z-index: 9999;
  }
  .swal2-container.swal-inside-agendar .swal2-popup {
    width: 88% !important; max-width: 320px !important;
    border-radius: 20px !important; font-family: 'Poppins', sans-serif !important;
  }

  /* PIX */
  .pix-panel {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 20px;
    padding: 24px 20px;
    margin: 16px 0;
    color: white;
    text-align: center;
    display: none;
  }
  .pix-panel.visible { display: block; }
  .pix-panel h3 { margin: 0 0 6px; font-size: 18px; }
  .pix-panel p  { font-size: 12px; margin: 0 0 14px; opacity: 0.9; }

  .pix-key-box {
    background: rgba(255,255,255,0.2);
    border-radius: 12px;
    padding: 14px;
    font-weight: 700;
    font-size: 14px;
    word-break: break-all;
    letter-spacing: 0.5px;
    margin-bottom: 12px;
  }

  .pix-copy-btn {
    background: white;
    color: #667eea;
    border: none;
    border-radius: 10px;
    padding: 10px 20px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
    width: 100%;
    font-family: 'Poppins', sans-serif;
  }
  .pix-copy-btn:hover  { transform: scale(1.02); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
  .pix-copy-btn.copied { background: #4caf50; color: white; }

  .pix-steps {
    background: rgba(255,255,255,0.15);
    border-radius: 10px;
    padding: 12px 14px;
    margin-top: 14px;
    font-size: 11px;
    text-align: left;
    line-height: 1.8;
  }

  .pix-valor-input {
    background: rgba(255,255,255,0.2);
    border: 1.5px solid rgba(255,255,255,0.4);
    border-radius: 10px;
    padding: 10px 14px;
    color: white;
    font-size: 15px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    width: 100%;
    text-align: center;
    margin-bottom: 14px;
    box-sizing: border-box;
  }
  .pix-valor-input::placeholder { color: rgba(255,255,255,0.6); }

  .pix-confirm-btn {
    background: #4caf50;
    color: white;
    border: none;
    border-radius: 10px;
    padding: 12px 20px;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    width: 100%;
    margin-top: 10px;
    font-family: 'Poppins', sans-serif;
    transition: background 0.2s;
  }
  .pix-confirm-btn:hover    { background: #43a047; }
  .pix-confirm-btn:disabled { background: rgba(255,255,255,0.3); cursor: default; }

  /* Esconde campos desnecessários no modo PIX */
  .hide-on-pix { transition: opacity 0.2s; }
  .pix-mode .hide-on-pix { display: none; }

  /* Tela de sucesso */
  .success-screen {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    border-radius: 20px;
    padding: 24px 20px;
    margin-bottom: 20px;
    text-align: center;
    animation: fadeIn 0.5s ease;
  }
  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
  }
  
  /* WhatsApp link nos cards */
  .whatsapp-link {
    margin-top: 8px;
    font-size: 11px;
    color: #25D366;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-decoration: none;
  }
</style>
</head>
<body>
<div class="phone" id="phoneWrapper">

  <div class="header">
    <span onclick="history.back()" style="cursor:pointer;">⬅</span>
    <div class="header-title"><?= $mostrar_formulario ? 'Agendar Coleta' : 'Escolher ONG' ?></div>
    <span style="visibility:hidden;">⚙️</span>
  </div>

  <div class="main-content">

    <!-- TELA DE SUCESSO COM WHATSAPP -->
    <?php if (isset($_GET['sucesso']) && $_GET['sucesso'] == 1 && !empty($_GET['ong'])): 
        $ong_id_sucesso = (int)$_GET['ong'];
        $tipo_doacao_sucesso = $_GET['tipo'] ?? 'normal';
        
        $whatsapp_sucesso = '';
        $nome_ong_sucesso = '';
        try {
            $stmt_sucesso = $pdo->prepare("
                SELECT u.nome, o.whatsapp 
                FROM usuarios u 
                LEFT JOIN ongs o ON u.id_usuario = o.id_ong 
                WHERE u.id_usuario = ? AND u.tipo_usuario = 'instituicao'
            ");
            $stmt_sucesso->execute([$ong_id_sucesso]);
            $ong_data = $stmt_sucesso->fetch(PDO::FETCH_ASSOC);
            $nome_ong_sucesso = $ong_data['nome'] ?? 'ONG';
            $whatsapp_sucesso = $ong_data['whatsapp'] ?? '';
        } catch (PDOException $e) {}
        
        $mensagem_whats = urlencode("Olá! Acabei de agendar uma doação " . ($tipo_doacao_sucesso == 'pix' ? 'via PIX' : 'de itens') . " para sua ONG pelo Volunteer Community. Gostaria de confirmar os detalhes.");
    ?>
    <div class="success-screen">
        <div style="font-size: 48px; margin-bottom: 12px;">🎉</div>
        <h3 style="color: #155724; margin-bottom: 8px;">Agendamento Realizado!</h3>
        <p style="color: #155724; font-size: 13px; margin-bottom: 20px;">
            <?= $tipo_doacao_sucesso == 'pix' 
                ? '💜 Sua doação PIX foi registrada! A ONG será notificada assim que confirmar o recebimento.' 
                : '✅ Sua coleta foi agendada com sucesso! A ONG já foi notificada.' 
            ?>
        </p>
        
        <?php if (!empty($whatsapp_sucesso)): ?>
            <a href="https://wa.me/55<?= preg_replace('/\D/', '', $whatsapp_sucesso) ?>?text=<?= $mensagem_whats ?>" 
               target="_blank" 
               style="text-decoration: none; display: inline-block; margin-top: 8px;">
                <button style="background: #25D366; color: white; border: none; border-radius: 50px; padding: 12px 28px; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                    💬 Falar com <?= htmlspecialchars($nome_ong_sucesso) ?> no WhatsApp
                </button>
            </a>
            <button onclick="window.location.href='feed.php'" style="background: transparent; border: 1.5px solid #155724; color: #155724; border-radius: 50px; padding: 10px 24px; font-size: 12px; margin-top: 12px; cursor: pointer; display: inline-block; margin-left: 10px;">
                Voltar ao Feed
            </button>
        <?php else: ?>
            <button onclick="window.location.href='feed.php'" style="background: #f4822f; color: white; border: none; border-radius: 50px; padding: 12px 28px; font-weight: 700; font-size: 14px; cursor: pointer;">
                ✅ Voltar ao Feed
            </button>
        <?php endif; ?>
    </div>
    <?php 
        // Remover parâmetros da URL após exibir
        echo '<script>setTimeout(() => { window.history.replaceState({}, document.title, window.location.pathname); }, 100);</script>';
        endif; 
    ?>

    <?php if (!empty($mensagem_sucesso)): ?>
      <div class="success-message" id="successMessage"><?= $mensagem_sucesso ?></div>
    <?php endif; ?>
    <?= $mensagem ?>

    <?php if (!$mostrar_formulario): ?>
    <!-- ===== ESCOLHA DE ONG ===== -->
    <div class="busca-ong">
      <div class="search-box">
        <form method="POST" action="" id="searchForm">
          <input type="text" name="buscar_ong" id="searchInput"
                 placeholder="Buscar ONG por nome ou categoria..."
                 value="<?= htmlspecialchars($buscar_ong) ?>" onkeyup="filterOngs()">
          <button type="button" onclick="filterOngs()">🔍</button>
        </form>
      </div>

      <div class="section">Selecione uma ONG para doação</div>

      <?php if (empty($ongs)): ?>
        <div class="no-ongs"><p>⚠️ Nenhuma ONG cadastrada no momento.</p></div>
      <?php else: ?>
        <form method="POST" action="" id="formEscolherOng">
          <div class="ongs-list" id="ongsList">
            <?php
            $ongs_filtradas = $ongs;
            if (!empty($buscar_ong)) {
                $busca = strtolower(trim($buscar_ong));
                $ongs_filtradas = array_filter($ongs, fn($o) =>
                    stripos($o['nome'] ?? '', $busca) !== false ||
                    stripos($o['descricao'] ?? '', $busca) !== false ||
                    stripos($o['email'] ?? '', $busca) !== false
                );
            }
            if (empty($ongs_filtradas)): ?>
              <div class="no-ongs"><p>Nenhuma ONG encontrada para "<?= htmlspecialchars($buscar_ong) ?>"</p></div>
            <?php else: ?>
              <?php foreach ($ongs_filtradas as $ong): ?>
                <div class="ong-card"
                     data-id="<?= $ong['id_usuario'] ?>"
                     data-nome="<?= htmlspecialchars($ong['nome'] ?? '') ?>"
                     data-descricao="<?= htmlspecialchars($ong['descricao'] ?? '') ?>"
                     onclick="selecionarOng(this)">
                  <h3><?= htmlspecialchars($ong['nome'] ?? 'Instituição sem nome') ?></h3>
                  <?php if (!empty($ong['email'])): ?>
                    <p>📧 <?= htmlspecialchars($ong['email']) ?></p>
                  <?php endif; ?>
                  <?php if (!empty($ong['descricao'])): ?>
                    <p><?= htmlspecialchars(mb_substr($ong['descricao'], 0, 100)) ?><?= mb_strlen($ong['descricao']) > 100 ? '...' : '' ?></p>
                  <?php endif; ?>
                  <?php if (!empty($ong['endereco'])): ?>
                    <div class="endereco">📍 <?= htmlspecialchars(mb_substr($ong['endereco'], 0, 60)) ?><?= mb_strlen($ong['endereco']) > 60 ? '...' : '' ?></div>
                  <?php endif; ?>
                  <?php if (!empty($ong['cpf_cnpj'])): ?>
                    <div class="cnpj">CNPJ: <?= htmlspecialchars($ong['cpf_cnpj']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($ong['chave_pix'])): ?>
                    <div style="font-size:10px;color:#667eea;margin-top:5px;">💜 Aceita PIX</div>
                  <?php endif; ?>
                  <?php if (!empty($ong['whatsapp'])): ?>
                    <a href="https://wa.me/55<?= preg_replace('/\D/', '', $ong['whatsapp']) ?>?text=Olá! Vi sua ONG no Volunteer Community e gostaria de saber mais sobre como doar." 
                       target="_blank" 
                       class="whatsapp-link">
                        💬 WhatsApp
                    </a>
                  <?php endif; ?>
                  <input type="radio" name="ong_escolhida" value="<?= $ong['id_usuario'] ?>"
                         <?= $ong_escolhida == $ong['id_usuario'] ? 'checked' : '' ?>
                         style="display:none;">
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="action-buttons-vertical">
            <button type="button" class="btn-secondary" onclick="window.location.href='feed.php'">Cancelar</button>
            <button type="submit" name="escolher_ong" class="btn-primary" id="btnEscolherOng" disabled>Continuar</button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- ===== TELA DE AGENDAMENTO ===== -->

    <?php if ($ong_selecionada): ?>
    <div class="ong-info">
      <div><strong>ONG selecionada:</strong> <?= htmlspecialchars($ong_selecionada['nome']) ?></div>
      <?php if (!empty($ong_selecionada['email'])): ?>
        <div><strong>Email:</strong> <?= htmlspecialchars($ong_selecionada['email']) ?></div>
      <?php endif; ?>
      <?php if (!empty($whatsapp_ong)): ?>
        <div><strong>WhatsApp:</strong> 
          <a href="https://wa.me/55<?= preg_replace('/\D/', '', $whatsapp_ong) ?>?text=Olá! Gostaria de informações sobre como doar para <?= urlencode($ong_selecionada['nome']) ?>" 
             target="_blank" 
             style="color: #25D366; text-decoration: none; font-weight: 600;">
            <?= htmlspecialchars($whatsapp_ong) ?> 💬
          </a>
        </div>
      <?php endif; ?>
      <?php if (!empty($titulo_ong)): ?>
        <div><strong>Campanha:</strong> <?= htmlspecialchars($titulo_ong) ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" id="formAgendamento">
      <input type="hidden" name="ong_escolhida"  value="<?= $ong_escolhida ?>">
      <input type="hidden" name="chave_pix_ong"  value="<?= htmlspecialchars($ong_selecionada['chave_pix'] ?? '') ?>">

      <!-- TIPO DE DOAÇÃO -->
      <div class="section">Tipo de Doação</div>
      <div class="tipo-doacao">
        <button type="button" class="tipo-btn <?= $tipo_doacao == 'ITEM' ? 'selected' : '' ?>" onclick="selectTipo('ITEM')">📦 Itens</button>
        <button type="button" class="tipo-btn <?= $tipo_doacao == 'DINHEIRO' ? 'selected' : '' ?>" onclick="selectTipo('DINHEIRO')">💰 Dinheiro</button>
      </div>
      <input type="hidden" name="tipo_doacao" id="tipo_doacao" value="<?= htmlspecialchars($tipo_doacao) ?>">

      <!-- PAINEL PIX (só aparece quando DINHEIRO + ONG tem PIX) -->
      <?php if ($ong_tem_pix): ?>
      <div class="pix-panel" id="pixPanel">
        <h3>💜 Doação via PIX</h3>
        <p>Informe o valor e copie a chave para transferir pelo seu banco</p>

        <input type="number" class="pix-valor-input" id="pixValorInput"
               name="valor_doacao" placeholder="R$ 0,00" step="0.01" min="0.01"
               oninput="checkPixReady()">

        <div class="pix-key-box" id="pixKeyBox"><?= htmlspecialchars($ong_selecionada['chave_pix']) ?></div>
        <button type="button" class="pix-copy-btn" id="pixCopyBtn" onclick="copyPixKey()">📋 Copiar Chave PIX</button>

        <div class="pix-steps">
          <strong>📌 Como fazer:</strong><br>
          1. Informe o valor acima<br>
          2. Copie a chave PIX<br>
          3. Abra seu banco e faça a transferência<br>
          4. Clique em "Confirmar Doação PIX" abaixo
        </div>

        <button type="button" class="pix-confirm-btn" id="pixConfirmBtn"
                onclick="confirmarPix()" disabled>
          ✅ Confirmar Doação PIX
        </button>
      </div>
      <?php endif; ?>

      <!-- CAMPOS NORMAIS (escondidos no modo PIX) -->
      <div id="camposNormais">

        <div class="descricao-item hide-on-pix" id="descricaoItemContainer">
          <div class="section">Descrição dos Itens</div>
          <textarea name="descricao_item" id="descricao_item"
                    placeholder="Descreva os itens que serão doados..."><?= htmlspecialchars($descricao_item) ?></textarea>
        </div>

        <?php if (!$ong_tem_pix): ?>
        <div class="valor-doacao" id="valorDoacaoContainer" style="display:none;">
          <div class="section">Valor da Doação (R$)</div>
          <input type="number" name="valor_doacao" id="valor_doacao_normal"
                 placeholder="0,00" step="0.01" min="0" value="<?= htmlspecialchars($valor_doacao) ?>">
        </div>
        <?php endif; ?>

        <div class="calendar hide-on-pix" id="calendar">
          <div class="month-selector">
            <button type="button" onclick="changeMonth(-1)">◀</button>
            <select id="monthSelect" onchange="updateCalendar()">
              <?php foreach ($meses as $num => $nome): ?>
                <option value="<?= $num ?>" <?= $num == $mes_atual ? 'selected' : '' ?>><?= $nome ?></option>
              <?php endforeach; ?>
            </select>
            <div class="year-display">
              <span id="yearDisplay"><?= $ano_atual ?></span>
              <button type="button" onclick="changeYear(1)" style="margin-left:5px;">▲</button>
              <button type="button" onclick="changeYear(-1)">▼</button>
            </div>
            <button type="button" onclick="changeMonth(1)">▶</button>
          </div>
          <div class="weekdays">
            <span>Dom</span><span>Seg</span><span>Ter</span><span>Qua</span>
            <span>Qui</span><span>Sex</span><span>Sáb</span>
          </div>
          <div class="days" id="daysContainer"></div>
        </div>
        <input type="hidden" name="data_coleta" id="data_coleta" value="<?= htmlspecialchars($data_selecionada) ?>">

        <div class="section hide-on-pix">Horários disponíveis</div>
        <div class="times hide-on-pix">
          <?php foreach (['08:00','09:00','10:00','11:00','12:00','14:00','15:00','16:00'] as $h): ?>
            <button type="button" class="time-btn <?= $horario_selecionado == $h ? 'selected' : '' ?>"
                    onclick="selectTime('<?= $h ?>')"><?= $h ?></button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="horario" id="horario" value="<?= htmlspecialchars($horario_selecionado) ?>">

        <div class="section hide-on-pix">Local de coleta</div>
        <div class="select-wrapper hide-on-pix">
          <select name="local_coleta" id="localColeta">
            <option value="">Selecione um local</option>
            <option value="Fort Atacadista"          <?= $local_selecionado=='Fort Atacadista'?'selected':'' ?>>Fort Atacadista</option>
            <option value="ONG Reviver - Centro"     <?= $local_selecionado=='ONG Reviver - Centro'?'selected':'' ?>>ONG Reviver - Centro</option>
            <option value="Ponto de coleta Bairro A" <?= $local_selecionado=='Ponto de coleta Bairro A'?'selected':'' ?>>Ponto de coleta Bairro A</option>
            <option value="Ponto de coleta Bairro B" <?= $local_selecionado=='Ponto de coleta Bairro B'?'selected':'' ?>>Ponto de coleta Bairro B</option>
            <?php if ($ong_selecionada && !empty($ong_selecionada['endereco'])): ?>
              <option value="<?= htmlspecialchars($ong_selecionada['endereco']) ?>"
                      <?= $local_selecionado==$ong_selecionada['endereco']?'selected':'' ?>>
                Endereço da ONG: <?= htmlspecialchars($ong_selecionada['endereco']) ?>
              </option>
            <?php endif; ?>
          </select>
        </div>

        <div class="form-buttons-container hide-on-pix">
          <button type="button" class="btn-primary" id="btnAgendar" disabled onclick="confirmarAgendamento()">✅ Agendar</button>
          <button type="button" class="btn-secondary" onclick="window.location.href='agendar_coleta.php'">🔄 Trocar ONG</button>
        </div>
      </div>

    </form>
    <?php endif; ?>
  </div>

  <div class="bottom">
    <a href="feed.php" class="menu-item">🏠<span>Feed</span></a>
    <a href="campanhas.php" class="menu-item">📢<span>Campanhas</span></a>
    <button class="plus-btn" onclick="window.location.href='agendar_coleta.php'">+</button>
    <a href="notificacoes.php" class="menu-item">🔔<span>Notificações</span></a>
    <a href="perfil.php" class="menu-item">👤<span>Perfil</span></a>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const phoneEl = document.getElementById('phoneWrapper');
const swalAgendar = Swal.mixin({
    target: phoneEl,
    confirmButtonColor: '#f4822f',
    cancelButtonColor: '#aaa',
    customClass: { container: 'swal-inside-agendar' }
});

const ONG_TEM_PIX = <?= $ong_tem_pix ? 'true' : 'false' ?>;

let currentYear   = <?= $ano_atual ?>;
let currentMonth  = <?= $mes_atual ?> - 1;
let selectedDate  = '<?= $data_selecionada ?>';
let selectedTime  = '<?= $horario_selecionado ?>';
let selectedTipo  = '<?= $tipo_doacao ?>';

// ── PIX ──────────────────────────────────────────────────────────────────────
function copyPixKey() {
    const key = document.getElementById('pixKeyBox').textContent.trim();
    const btn = document.getElementById('pixCopyBtn');
    navigator.clipboard.writeText(key).then(() => {
        btn.textContent = '✅ Copiado!';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = '📋 Copiar Chave PIX'; btn.classList.remove('copied'); }, 2000);
    });
}

function checkPixReady() {
    const val = parseFloat(document.getElementById('pixValorInput')?.value || 0);
    const btn = document.getElementById('pixConfirmBtn');
    if (btn) btn.disabled = !(val > 0);
}

async function confirmarPix() {
    const valor = document.getElementById('pixValorInput').value;
    const chave = document.getElementById('pixKeyBox').textContent.trim();

    const result = await swalAgendar.fire({
        title: '💜 Confirmar Doação PIX',
        html: `<div style="text-align:left;font-size:13px;line-height:1.8;">
                 <strong>Valor:</strong> R$ ${parseFloat(valor).toFixed(2).replace('.',',')}<br>
                 <strong>Chave PIX:</strong> <code style="word-break:break-all">${chave}</code><br><br>
                 <p style="color:#856404;background:#fff3cd;padding:8px;border-radius:8px;font-size:12px;">
                   ⚠️ Certifique-se de ter realizado a transferência antes de confirmar.
                   A ONG irá verificar o recebimento.
                 </p>
               </div>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '✅ Sim, já transferi',
        cancelButtonText: 'Cancelar'
    });

    if (result.isConfirmed) {
        const form = document.getElementById('formAgendamento');
        const btn  = document.getElementById('pixConfirmBtn');
        btn.disabled = true;
        btn.textContent = '⏳ Registrando...';

        document.getElementById('tipo_doacao').value = 'DINHEIRO';

        const s = document.createElement('input');
        s.type = 'hidden'; s.name = 'agendar'; s.value = 'agendar';
        form.appendChild(s);
        form.submit();
    }
}

// ── Tipo de doação ────────────────────────────────────────────────────────────
function selectTipo(tipo) {
    selectedTipo = tipo;
    document.getElementById('tipo_doacao').value = tipo;
    document.querySelectorAll('.tipo-btn').forEach(b => b.classList.remove('selected'));
    event.target.classList.add('selected');
    updateTipoDisplay();
}

function updateTipoDisplay() {
    const pixPanel      = document.getElementById('pixPanel');
    const camposNormais = document.getElementById('camposNormais');
    const descCont      = document.getElementById('descricaoItemContainer');
    const valCont       = document.getElementById('valorDoacaoContainer');

    if (selectedTipo === 'DINHEIRO' && ONG_TEM_PIX) {
        if (pixPanel) pixPanel.classList.add('visible');
        document.querySelectorAll('.hide-on-pix').forEach(el => el.style.display = 'none');
    } else {
        if (pixPanel) pixPanel.classList.remove('visible');
        document.querySelectorAll('.hide-on-pix').forEach(el => el.style.display = '');
        if (descCont) descCont.style.display = selectedTipo === 'ITEM'     ? 'block' : 'none';
        if (valCont)  valCont.style.display  = selectedTipo === 'DINHEIRO' ? 'block' : 'none';
    }
    checkFormCompletion();
}

// ── Calendário ────────────────────────────────────────────────────────────────
function updateCalendar() {
    const monthSelect = document.getElementById('monthSelect');
    currentMonth = parseInt(monthSelect.value) - 1;
    document.getElementById('yearDisplay').textContent = currentYear;

    const firstDay    = new Date(currentYear, currentMonth, 1);
    const lastDay     = new Date(currentYear, currentMonth + 1, 0);
    const startDay    = firstDay.getDay();
    const daysInMonth = lastDay.getDate();
    const container   = document.getElementById('daysContainer');
    container.innerHTML = '';

    for (let i = 0; i < startDay; i++) {
        const e = document.createElement('span');
        e.className = 'day empty';
        container.appendChild(e);
    }

    const today = new Date(); today.setHours(0,0,0,0);
    for (let d = 1; d <= daysInMonth; d++) {
        const el      = document.createElement('span');
        el.className  = 'day';
        el.textContent = d;
        const dateStr = `${currentYear}-${String(currentMonth+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        el.onclick    = () => selectDate(dateStr);
        if (new Date(currentYear, currentMonth, d).toDateString() === today.toDateString()) el.classList.add('highlight');
        if (selectedDate === dateStr) el.classList.add('selected');
        container.appendChild(el);
    }
}

function changeMonth(delta) {
    let m = currentMonth + delta, y = currentYear;
    if (m < 0)  { m = 11; y--; }
    if (m > 11) { m = 0;  y++; }
    currentYear = y; currentMonth = m;
    document.getElementById('monthSelect').value = m + 1;
    document.getElementById('yearDisplay').textContent = y;
    updateCalendar();
}

function changeYear(delta) {
    currentYear += delta;
    document.getElementById('yearDisplay').textContent = currentYear;
    updateCalendar();
}

function selectDate(date) {
    selectedDate = date;
    document.getElementById('data_coleta').value = date;
    document.querySelectorAll('.day').forEach(el => {
        el.classList.remove('selected');
        const d = parseInt(el.textContent);
        if (!isNaN(d)) {
            const ds = `${currentYear}-${String(currentMonth+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
            if (ds === date) el.classList.add('selected');
        }
    });
    checkFormCompletion();
}

function selectTime(time) {
    selectedTime = time;
    document.getElementById('horario').value = time;
    document.querySelectorAll('.time-btn').forEach(b => b.classList.remove('selected'));
    event.target.classList.add('selected');
    checkFormCompletion();
}

function checkFormCompletion() {
    const btn = document.getElementById('btnAgendar');
    if (!btn) return;
    if (selectedTipo === 'DINHEIRO' && ONG_TEM_PIX) { btn.disabled = true; return; }

    const data   = document.getElementById('data_coleta')?.value;
    const hora   = document.getElementById('horario')?.value;
    const local  = document.querySelector('select[name="local_coleta"]')?.value;
    let valid    = !!(data && hora && local);

    if (selectedTipo === 'ITEM') {
        valid = valid && !!(document.querySelector('textarea[name="descricao_item"]')?.value.trim());
    } else {
        const val = document.querySelector('input[name="valor_doacao"]')?.value;
        valid = valid && !!(val && parseFloat(val) > 0);
    }
    btn.disabled = !valid;
}

// ── Agendamento normal ────────────────────────────────────────────────────────
async function confirmarAgendamento() {
    const tipo    = document.getElementById('tipo_doacao').value;
    const desc    = document.getElementById('descricao_item')?.value.trim() || '';
    const valor   = document.querySelector('input[name="valor_doacao"]')?.value.trim() || '';
    const data    = document.getElementById('data_coleta').value;
    const horario = document.getElementById('horario').value;
    const local   = document.getElementById('localColeta').value;
    const nomeOng = '<?= addslashes($ong_selecionada["nome"] ?? "") ?>';

    if (!data || !horario || !local) {
        await swalAgendar.fire({ title: 'Campos incompletos', text: 'Preencha todos os campos!', icon: 'warning', confirmButtonText: 'Ok' });
        return;
    }

    let resumo = tipo === 'ITEM'
        ? `<strong>📦 Itens:</strong> ${desc.substring(0,100)}${desc.length>100?'...':''}<br>`
        : `<strong>💰 Valor:</strong> R$ ${parseFloat(valor).toFixed(2).replace('.',',')}<br>`;

    const result = await swalAgendar.fire({
        title: 'Confirmar Agendamento',
        html: `<div style="text-align:left;font-size:13px;line-height:1.8;">
                 ${resumo}
                 <strong>🏢 ONG:</strong> ${nomeOng}<br>
                 <strong>📅 Data:</strong> ${data}<br>
                 <strong>⏰ Horário:</strong> ${horario}<br>
                 <strong>📍 Local:</strong> ${local}
               </div>`,
        icon: 'question', showCancelButton: true,
        confirmButtonText: '✅ Sim, agendar', cancelButtonText: '❌ Cancelar'
    });

    if (result.isConfirmed) {
        const form = document.getElementById('formAgendamento');
        document.getElementById('btnAgendar').disabled = true;

        const s = document.createElement('input');
        s.type = 'hidden'; s.name = 'agendar'; s.value = 'agendar';
        form.appendChild(s);
        form.submit();
    }
}

// ── ONG list ──────────────────────────────────────────────────────────────────
function filterOngs() {
    const term = document.getElementById('searchInput').value.toLowerCase().trim();
    document.querySelectorAll('.ong-card').forEach(card => {
        const n = card.dataset.nome.toLowerCase();
        const d = card.dataset.descricao.toLowerCase();
        card.style.display = (!term || n.includes(term) || d.includes(term)) ? 'block' : 'none';
    });
}

function selecionarOng(card) {
    document.querySelectorAll('.ong-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    const radio = card.querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
    const btn = document.getElementById('btnEscolherOng');
    if (btn) btn.disabled = false;
}


document.addEventListener('DOMContentLoaded', function () {
    updateTipoDisplay();
    updateCalendar();

    document.querySelector('select[name="local_coleta"]')?.addEventListener('change', checkFormCompletion);
    document.querySelector('textarea[name="descricao_item"]')?.addEventListener('input', checkFormCompletion);
    document.getElementById('searchInput')?.addEventListener('input', filterOngs);

    const successMsg = document.getElementById('successMessage');
    if (successMsg) {
        setTimeout(() => {
            successMsg.style.transition = 'opacity 0.5s';
            successMsg.style.opacity   = '0';
            setTimeout(() => successMsg.style.display = 'none', 500);
        }, 5000);
    }
});

document.body.style.overflow = 'hidden';
</script>
</body>
</html>