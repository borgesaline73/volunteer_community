<?php
/**
 * Script de migração ÚNICA: criptografa o cpf_cnpj já existente no banco.
 *
 * COMO USAR:
 * 1. Rode antes o script_Banco/migracao_cpf_cnpj.sql (ETAPA 1) no seu banco.
 * 2. Configure a variável de ambiente CPF_CNPJ_KEY (mesma usada em produção).
 * 3. Acesse este arquivo UMA VEZ (via navegador ou CLI: php migrar_cpf_cnpj.php).
 * 4. Confira o resultado. Se "restantes" ficar em 0, pode rodar a ETAPA 2 do SQL.
 * 5. APAGUE este arquivo do servidor e do repositório logo depois de usar —
 *    ele não deve ficar acessível publicamente.
 */

require "banco.php";
require "funcoes_cripto.php";

header('Content-Type: text/plain; charset=utf-8');

try {
    $stmt = $pdo->query("
        SELECT id_usuario, cpf_cnpj
        FROM usuarios
        WHERE cpf_cnpj IS NOT NULL
          AND cpf_cnpj_enc IS NULL
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $update = $pdo->prepare("
        UPDATE usuarios
        SET cpf_cnpj_enc = :enc, cpf_cnpj_hash = :hash
        WHERE id_usuario = :id
    ");

    $total = 0;
    $falhas = 0;

    foreach ($rows as $row) {
        try {
            $enc  = criptografarCpfCnpj($row['cpf_cnpj']);
            $hash = hashCpfCnpj($row['cpf_cnpj']);

            $update->execute([
                ':enc'  => $enc,
                ':hash' => $hash,
                ':id'   => $row['id_usuario'],
            ]);
            $total++;
        } catch (Throwable $e) {
            $falhas++;
            echo "FALHA no usuário {$row['id_usuario']}: " . $e->getMessage() . "\n";
        }
    }

    echo "Migração concluída.\n";
    echo "Registros migrados agora: $total\n";
    echo "Falhas: $falhas\n";

    // Checagem final: quantos ainda faltam
    $restantes = $pdo->query("
        SELECT COUNT(*) FROM usuarios
        WHERE cpf_cnpj IS NOT NULL AND cpf_cnpj_enc IS NULL
    ")->fetchColumn();

    echo "Restantes sem migrar: $restantes\n";

    if ($restantes == 0) {
        echo "\nTudo migrado! Agora você pode rodar a ETAPA 2 do SQL (DROP COLUMN cpf_cnpj)\n";
        echo "e depois apagar este arquivo (migrar_cpf_cnpj.php) do servidor.\n";
    }

} catch (PDOException $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}