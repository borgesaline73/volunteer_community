<?php
echo "Usuário do processo PHP: " . get_current_user() . "<br>";
echo "POSIX UID: " . (function_exists('posix_getuid') ? posix_getuid() : 'posix não disponível') . "<br>";
echo "Diretório de trabalho: " . getcwd() . "<br>";
echo "Pasta uploads existe? " . (is_dir('uploads') ? 'SIM' : 'NÃO') . "<br>";
echo "Pasta uploads é gravável (is_writable)? " . (is_writable('uploads') ? 'SIM' : 'NÃO') . "<br>";

$testFile = 'uploads/teste_php_' . time() . '.txt';
$resultado = @file_put_contents($testFile, 'teste via PHP');

if ($resultado === false) {
    $erro = error_get_last();
    echo "FALHOU ao escrever via PHP. Erro: " . ($erro['message'] ?? 'desconhecido');
} else {
    echo "SUCESSO! Arquivo criado: $testFile ($resultado bytes)";
}
?>