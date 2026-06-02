<?php
/**
 * reverificar_ong.php
 */

session_start();
require "banco.php";

header('Content-Type: application/json');

if (!isset($_SESSION["usuario_id"])) {
    echo json_encode(['status' => 'erro', 'mensagem' => 'Não autorizado.']);
    exit;
}

$id_ong = (int)($_GET['id']  ?? 0);
$cnpj   = trim($_GET['cnpj'] ?? '');

if ($id_ong <= 0 || empty($cnpj)) {
    echo json_encode(['status' => 'erro', 'mensagem' => 'Parâmetros inválidos.']);
    exit;
}

// ── Confirma que a ONG existe ─────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id_usuario, verificacao_status FROM usuarios WHERE id_usuario = ? AND tipo_usuario = 'instituicao'");
$stmt->execute([$id_ong]);
$ong = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ong) {
    echo json_encode(['status' => 'erro', 'mensagem' => 'ONG não encontrada.']);
    exit;
}

$cnpj_limpo = preg_replace('/\D/', '', $cnpj);

// ── Cache simples por CNPJ (evita rate limit) ─────────────────────────────────
$cache_file = sys_get_temp_dir() . "/cnpj_{$cnpj_limpo}.json";
$cache_ttl  = 3600; // 1 hora

if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    $response  = file_get_contents($cache_file);
    $http_code = 200;
    $curl_erro = '';
} else {

    // ── Tenta BrasilAPI primeiro ──────────────────────────────────────────────
    $ch = curl_init("https://brasilapi.com.br/api/cnpj/v1/{$cnpj_limpo}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_erro = curl_error($ch);
    curl_close($ch);

    // ── Se rate limit ou falha, tenta ReceitaWS como fallback ────────────────
    if ($http_code === 429 || $http_code === 0 || $curl_erro) {
        sleep(1);
        $ch2 = curl_init("https://receitaws.com.br/v1/cnpj/{$cnpj_limpo}");
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response  = curl_exec($ch2);
        $http_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $curl_erro = curl_error($ch2);
        curl_close($ch2);

        // Normaliza resposta ReceitaWS para o mesmo formato da BrasilAPI
        if ($http_code === 200 && $response) {
            $data_rws = json_decode($response, true);
            if (($data_rws['status'] ?? '') === 'OK') {
                $response = json_encode([
                    'razao_social'                 => $data_rws['nome']     ?? '',
                    'descricao_situacao_cadastral' => $data_rws['situacao'] ?? '',
                ]);
            } elseif (($data_rws['status'] ?? '') === 'ERROR') {
                // CNPJ não encontrado na ReceitaWS
                $http_code = 404;
                $response  = json_encode(['message' => $data_rws['message'] ?? 'CNPJ não encontrado.']);
            }
        }
    }

    // Salva no cache só se a resposta foi válida
    if ($http_code === 200 && $response) {
        file_put_contents($cache_file, $response);
    }
}

// ── Falha total de rede ───────────────────────────────────────────────────────
if ($curl_erro || $response === false) {
    echo json_encode([
        'status'   => 'pendente',
        'mensagem' => 'Não foi possível conectar à Receita Federal. Tente novamente mais tarde.'
    ]);
    exit;
}

$data = json_decode($response, true);

// ── Ainda com rate limit após fallback ───────────────────────────────────────
if ($http_code === 429 || $http_code >= 500) {
    echo json_encode([
        'status'   => 'pendente',
        'mensagem' => 'Serviço da Receita Federal indisponível no momento. Tente novamente em alguns minutos.'
    ]);
    exit;
}

// ── CNPJ encontrado → verifica situação cadastral ────────────────────────────
if ($http_code === 200 && !empty($data['razao_social'])) {
    $situacao = strtoupper(trim($data['descricao_situacao_cadastral'] ?? ''));

    if ($situacao === 'ATIVA') {
        $pdo->prepare("UPDATE usuarios SET verificada = true, verificacao_status = 'aprovada' WHERE id_usuario = ?")
            ->execute([$id_ong]);
        echo json_encode([
            'status'   => 'aprovada',
            'mensagem' => 'CNPJ verificado! ONG aprovada automaticamente.'
        ]);
    } else {
        $pdo->prepare("UPDATE usuarios SET verificada = false, verificacao_status = 'rejeitada' WHERE id_usuario = ?")
            ->execute([$id_ong]);
        echo json_encode([
            'status'   => 'rejeitada',
            'mensagem' => "CNPJ encontrado, mas com situação: {$situacao}. Apenas CNPJs com situação ATIVA são aceitos."
        ]);
    }
    exit;
}

// ── CNPJ não encontrado (404) → rejeita ──────────────────────────────────────
$pdo->prepare("UPDATE usuarios SET verificada = false, verificacao_status = 'rejeitada' WHERE id_usuario = ?")
    ->execute([$id_ong]);

echo json_encode([
    'status'   => 'rejeitada',
    'mensagem' => $data['message'] ?? 'CNPJ não encontrado na Receita Federal.'
]);