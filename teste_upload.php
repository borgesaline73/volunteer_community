<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['imagem'])) {
    echo "<h3>Resultado do upload:</h3>";
    echo "<pre>";
    print_r($_FILES['imagem']);
    echo "</pre>";

    if ($_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
        echo "ERRO no upload, código: " . $_FILES['imagem']['error'] . "<br>";
    } else {
        $destino = 'uploads/teste_real_' . time() . '.png';
        $sucesso = move_uploaded_file($_FILES['imagem']['tmp_name'], $destino);
        if ($sucesso) {
            echo "SUCESSO: arquivo movido para $destino";
        } else {
            $erro = error_get_last();
            echo "FALHOU o move_uploaded_file. Erro PHP: " . ($erro['message'] ?? 'nenhum erro reportado');
        }
    }
    exit;
}
?>
<form method="POST" enctype="multipart/form-data">
    <input type="file" name="imagem" required>
    <button type="submit">Testar upload</button>
</form>