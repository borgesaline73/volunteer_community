<?php
$senha = "npg_u2JI7gESlYOX"; // Substitua pela sua senha

try {
    $pdo = new PDO(
        "pgsql:host=ep-orange-waterfall-acxdtu4f.sa-east-1.aws.neon.tech;dbname=neondb;sslmode=require;options=endpoint=ep-orange-waterfall-acxdtu4f",
        "neondb_owner",
        $senha
    );
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT NOW() as hora, version() as versao");
    $row = $stmt->fetch();
    
    echo "✅ Conectado ao Neon!<br>";
    echo "🕐 Hora: " . $row['hora'] . "<br>";
    echo "📦 Versão: " . $row['versao'];
    
} catch (PDOException $e) {
    echo "❌ Erro: " . $e->getMessage();
}
?>