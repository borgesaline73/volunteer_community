<?php
/**
 * Funções de criptografia e mascaramento para CPF/CNPJ (dado pessoal sensível - LGPD).
 *
 * Requer a variável de ambiente CPF_CNPJ_KEY: uma chave de 32 bytes em base64.
 * Gere uma vez com:  php -r "echo base64_encode(random_bytes(32));"
 * e configure no painel de deploy (Render/serv00), nunca no código.
 */

function obterChaveCpfCnpj(): string {
    $chave_b64 = getenv('CPF_CNPJ_KEY') ?: ($_ENV['CPF_CNPJ_KEY'] ?? null);

    if (!$chave_b64) {
        throw new RuntimeException('CPF_CNPJ_KEY não configurada nas variáveis de ambiente.');
    }

    $chave = base64_decode($chave_b64, true);

    if ($chave === false || strlen($chave) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('CPF_CNPJ_KEY inválida — esperado 32 bytes em base64.');
    }

    return $chave;
}

/**
 * Criptografa um CPF/CNPJ para armazenamento (não determinístico: mesmo valor
 * gera cifrados diferentes a cada chamada). Guardar em cpf_cnpj_enc.
 */
function criptografarCpfCnpj(string $valor): string {
    $chave   = obterChaveCpfCnpj();
    $nonce   = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cifrado = sodium_crypto_secretbox($valor, $nonce, $chave);

    return base64_encode($nonce . $cifrado);
}

/**
 * Reverte um valor gerado por criptografarCpfCnpj(). Retorna null se o valor
 * estiver vazio ou não puder ser decifrado (chave errada / dado corrompido).
 */
function descriptografarCpfCnpj(?string $armazenado): ?string {
    if (empty($armazenado)) {
        return null;
    }

    try {
        $chave   = obterChaveCpfCnpj();
        $dados   = base64_decode($armazenado, true);

        if ($dados === false || strlen($dados) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce   = substr($dados, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cifrado = substr($dados, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $original = sodium_crypto_secretbox_open($cifrado, $nonce, $chave);

        return $original === false ? null : $original;
    } catch (Throwable $e) {
        error_log("Erro ao descriptografar CPF/CNPJ: " . $e->getMessage());
        return null;
    }
}

/**
 * Hash determinístico (HMAC-SHA256) usado só para checar duplicidade
 * (constraint UNIQUE), já que o valor criptografado muda a cada chamada.
 * Guardar em cpf_cnpj_hash.
 */
function hashCpfCnpj(string $valor): string {
    $chave  = obterChaveCpfCnpj();
    $limpo  = preg_replace('/\D/', '', $valor);

    return hash_hmac('sha256', $limpo, $chave);
}

/**
 * Mascara um CPF/CNPJ para exibição, mantendo só os últimos 4 dígitos.
 * Ex: "12345678901" -> "•••••••8901"
 */
function mascararCpfCnpj(?string $valor): string {
    if (empty($valor)) {
        return '';
    }

    $limpo = preg_replace('/\D/', '', $valor);
    $len   = strlen($limpo);

    if ($len <= 4) {
        return $valor;
    }

    return str_repeat('•', $len - 4) . substr($limpo, -4);
}