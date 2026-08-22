<?php
/**
 * Envia um arquivo de imagem para o Cloudinary e retorna a URL pública.
 * Usa upload "unsigned" (via upload preset), então não precisa da API secret aqui.
 *
 * Variáveis de ambiente necessárias (configuradas no Render):
 *   CLOUDINARY_CLOUD_NAME
 *   CLOUDINARY_UPLOAD_PRESET
 */
function uploadCloudinary(string $tmpFilePath): ?string {
    $cloudName = getenv('CLOUDINARY_CLOUD_NAME');
    $preset    = getenv('CLOUDINARY_UPLOAD_PRESET');

    if (!$cloudName || !$preset) {
        error_log("Cloudinary: variáveis de ambiente CLOUDINARY_CLOUD_NAME/CLOUDINARY_UPLOAD_PRESET não configuradas.");
        return null;
    }

    $url = "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'file'          => new CURLFile($tmpFilePath),
        'upload_preset' => $preset,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("Cloudinary: erro de cURL - " . $curlErr);
        return null;
    }

    if ($httpCode !== 200) {
        error_log("Cloudinary: upload falhou (HTTP $httpCode) - " . $response);
        return null;
    }

    $data = json_decode($response, true);
    return $data['secure_url'] ?? null;
}