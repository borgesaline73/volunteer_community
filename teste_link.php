<?php //Página para teste lembrar de tirar após finalizar todo o projeto
session_start();
require "banco.php";

// Buscar o ID de uma ONG qualquer para testar
$query = $pdo->query("SELECT id_usuario, nome FROM usuarios WHERE tipo_usuario = 'instituicao' LIMIT 1");
$ong = $query->fetch(PDO::FETCH_ASSOC);

if ($ong) {
    echo "ID da ONG: " . $ong['id_usuario'] . "<br>";
    echo "Nome: " . $ong['nome'] . "<br>";
    echo "<a href='perfil-ong-publico.php?id=" . $ong['id_usuario'] . "'>Clique aqui para testar o perfil público</a>";
} else {
    echo "Nenhuma ONG encontrada no banco de dados.";
}
?>