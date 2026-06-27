-- ===========================================
-- SCRIPT COMPLETO DO BANCO DE DADOS
-- Sistema de Doações - Volunteer Community
-- ===========================================

-- ===========================================
-- 1. CRIAÇÃO DAS TABELAS 
-- ===========================================

-- TABELA: usuarios
CREATE TABLE usuarios (
    id_usuario          SERIAL PRIMARY KEY,
    nome                VARCHAR(100) NOT NULL,
    email               VARCHAR(150) UNIQUE NOT NULL,
    senha               VARCHAR(200) NOT NULL,
    cpf_cnpj            VARCHAR(20) UNIQUE NOT NULL,
    tipo_usuario        VARCHAR(50) NOT NULL, -- 'doador' ou 'instituicao'
    data_cadastro       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ativo               BOOLEAN DEFAULT TRUE,
    verificada          BOOLEAN NOT NULL DEFAULT FALSE,
    verificacao_status  VARCHAR(20) DEFAULT 'pendente'
);

-- TABELA: ongs 
CREATE TABLE ongs (
    id_ong      INT PRIMARY KEY REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    autenticada BOOLEAN DEFAULT FALSE,
    descricao   TEXT,
    endereco    VARCHAR(255),
    categoria   VARCHAR(100),
    latitude    NUMERIC(9,6),
    longitude   NUMERIC(9,6),
    chave_pix   VARCHAR(255) DEFAULT NULL,
    whatsapp    VARCHAR(20) DEFAULT NULL
);

COMMENT ON COLUMN ongs.chave_pix IS 'Chave PIX para recebimento de doações (CPF, CNPJ, Email, Telefone ou UUID)';

-- TABELA: doadores 
CREATE TABLE doadores (
    id_doador     INT PRIMARY KEY REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    preferencias  TEXT,
    data_cadastro DATE DEFAULT CURRENT_DATE
);

-- TABELA: posts 
CREATE TABLE posts (
    id_post    SERIAL PRIMARY KEY,
    id_usuario INT NOT NULL REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    titulo     VARCHAR(255) NOT NULL,
    categoria  VARCHAR(50) NOT NULL,
    descricao  TEXT NOT NULL,
    imagem     VARCHAR(255),
    data_post  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- TABELA: doacoes 
CREATE TABLE doacoes (
    id_doacao        SERIAL PRIMARY KEY,
    id_doador        INT NOT NULL REFERENCES doadores(id_doador) ON DELETE CASCADE,
    id_ong           INT NOT NULL REFERENCES ongs(id_ong) ON DELETE CASCADE,
    tipo             VARCHAR(20) NOT NULL CHECK (tipo IN ('ITEM', 'DINHEIRO')),
    status           VARCHAR(20) DEFAULT 'AGENDADA' CHECK (status IN ('AGENDADA', 'RECEBIDA', 'CANCELADA', 'PENDENTE_PIX')),
    data_doacao      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    valor            NUMERIC(10,2),
    descricao_item   TEXT,
    metodo_pagamento VARCHAR(50) DEFAULT 'DIRETO' CHECK (metodo_pagamento IN ('PIX', 'DIRETO', 'DEPOSITO', 'OUTRO')),
    status_pagamento VARCHAR(50) DEFAULT 'PENDENTE' CHECK (status_pagamento IN ('PENDENTE', 'CONFIRMADO', 'CANCELADO', 'REEMBOLSADO')),
    data_criacao     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON COLUMN doacoes.metodo_pagamento IS 'Método de pagamento: PIX, DIRETO, DEPOSITO, OUTRO';
COMMENT ON COLUMN doacoes.status_pagamento IS 'Status do pagamento: PENDENTE, CONFIRMADO, CANCELADO, REEMBOLSADO';

-- TABELA: coletas 
CREATE TABLE coletas (
    id_coleta      SERIAL PRIMARY KEY,
    id_doacao      INT NOT NULL REFERENCES doacoes(id_doacao) ON DELETE CASCADE,
    tipo           VARCHAR(20) NOT NULL DEFAULT 'COLETA',
    endereco       VARCHAR(255) NOT NULL,
    data_agendada  TIMESTAMP NOT NULL,
    confirmado     BOOLEAN DEFAULT FALSE
);

-- TABELA: avaliacoes 
CREATE TABLE avaliacoes (
    id_avaliacao   SERIAL PRIMARY KEY,
    id_doador      INT NOT NULL REFERENCES doadores(id_doador) ON DELETE CASCADE,
    id_ong         INT NOT NULL REFERENCES ongs(id_ong) ON DELETE CASCADE,
    nota           INT CHECK (nota >= 1 AND nota <= 5),
    comentario     TEXT,
    data_avaliacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(id_doador, id_ong)
);

-- TABELA: notificacoes 
CREATE TABLE notificacoes (
    id_notificacao SERIAL PRIMARY KEY,
    id_usuario     INT NOT NULL REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    mensagem       TEXT NOT NULL,
    tipo           VARCHAR(50) DEFAULT 'GERAL',
    lida           BOOLEAN DEFAULT FALSE,
    data_envio     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- TABELA: coletas_visualizadas 
CREATE TABLE coletas_visualizadas (
    id_visualizacao  SERIAL PRIMARY KEY,
    id_ong           INT NOT NULL REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    id_doacao        INT NOT NULL REFERENCES doacoes(id_doacao) ON DELETE CASCADE,
    visualizada      BOOLEAN DEFAULT FALSE,
    data_visualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (id_ong, id_doacao)
);

-- TABELA: itens_ong 
CREATE TABLE itens_ong (
    id_item   SERIAL PRIMARY KEY,
    id_ong    INT NOT NULL REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    nome      VARCHAR(100) NOT NULL,
    tipo      VARCHAR(10) NOT NULL CHECK (tipo IN ('ACEITO', 'RECUSADO')),
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- TABELA: destino_doacoes 
CREATE TABLE destino_doacoes (
    id_destino SERIAL PRIMARY KEY,
    id_ong     INT NOT NULL REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    titulo     VARCHAR(255) NOT NULL,
    descricao  TEXT,
    imagem     VARCHAR(255),
    criado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- TABELA: recuperacao_senha 
CREATE TABLE recuperacao_senha (
    id         SERIAL PRIMARY KEY,
    id_usuario INTEGER NOT NULL REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    token      VARCHAR(64) NOT NULL UNIQUE,
    expira_em  TIMESTAMP NOT NULL,
    usado      BOOLEAN NOT NULL DEFAULT FALSE,
    criado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===========================================
-- 2. ÍNDICES 
-- ===========================================

-- Usuários
CREATE INDEX idx_usuarios_email ON usuarios(email);
CREATE INDEX idx_usuarios_tipo ON usuarios(tipo_usuario);

-- Posts
CREATE INDEX idx_posts_usuario ON posts(id_usuario);
CREATE INDEX idx_posts_categoria ON posts(categoria);
CREATE INDEX idx_posts_data ON posts(data_post DESC);

-- Doações
CREATE INDEX idx_doacoes_doador ON doacoes(id_doador);
CREATE INDEX idx_doacoes_ong ON doacoes(id_ong);
CREATE INDEX idx_doacoes_status ON doacoes(status);
CREATE INDEX idx_doacoes_data ON doacoes(data_doacao DESC);
CREATE INDEX idx_doacoes_metodo_pagamento ON doacoes(metodo_pagamento);
CREATE INDEX idx_doacoes_status_pagamento ON doacoes(status_pagamento);
CREATE INDEX idx_doacoes_ong_tipo ON doacoes(id_ong, tipo);
CREATE INDEX idx_doacoes_data_criacao ON doacoes(data_criacao);

-- Coletas
CREATE INDEX idx_coletas_doacao ON coletas(id_doacao);
CREATE INDEX idx_coletas_data ON coletas(data_agendada);

-- Notificações
CREATE INDEX idx_notificacoes_usuario ON notificacoes(id_usuario, data_envio DESC);
CREATE INDEX idx_notificacoes_nao_lidas ON notificacoes(id_usuario) WHERE lida = FALSE;

-- Coletas visualizadas
CREATE INDEX idx_coletas_visualizadas_ong ON coletas_visualizadas(id_ong);
CREATE INDEX idx_coletas_visualizadas_doacao ON coletas_visualizadas(id_doacao);

-- Itens e destinos
CREATE INDEX idx_itens_ong ON itens_ong(id_ong);
CREATE INDEX idx_destino_doacoes ON destino_doacoes(id_ong);

-- ONGs
CREATE INDEX idx_ongs_chave_pix ON ongs(chave_pix);

-- Recuperação de senha
CREATE INDEX idx_recuperacao_token ON recuperacao_senha(token);

-- ===========================================
-- 3. FUNÇÕES 
-- ===========================================

-- Contar notificações não lidas de um usuário
CREATE OR REPLACE FUNCTION contar_notificacoes_nao_lidas(p_id_usuario INTEGER)
RETURNS INTEGER AS $$
DECLARE
    total INTEGER;
BEGIN
    SELECT COUNT(*) INTO total
    FROM notificacoes
    WHERE id_usuario = p_id_usuario AND lida = FALSE;
    RETURN total;
END;
$$ LANGUAGE plpgsql;

-- Contar coletas não visualizadas de uma ONG
CREATE OR REPLACE FUNCTION contar_coletas_nao_visualizadas(p_id_ong INTEGER)
RETURNS INTEGER AS $$
DECLARE
    total INTEGER;
BEGIN
    SELECT COUNT(*) INTO total
    FROM doacoes d
    JOIN coletas c ON d.id_doacao = c.id_doacao
    LEFT JOIN coletas_visualizadas cv ON d.id_doacao = cv.id_doacao AND cv.id_ong = p_id_ong
    WHERE d.id_ong = p_id_ong
    AND d.status = 'AGENDADA'
    AND c.data_agendada >= CURRENT_DATE
    AND (cv.visualizada IS NULL OR cv.visualizada = FALSE);
    RETURN total;
END;
$$ LANGUAGE plpgsql;

-- ===========================================
-- 4. TRIGGERS 
-- ===========================================

-- Notificar doador quando doação for recebida
CREATE OR REPLACE FUNCTION notificar_doacao_recebida()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.status = 'RECEBIDA' AND OLD.status != 'RECEBIDA' THEN
        INSERT INTO notificacoes (id_usuario, mensagem, tipo)
        SELECT NEW.id_doador,
               'Sua doação foi recebida e confirmada pela ONG!',
               'DOACAO_RECEBIDA'
        FROM usuarios
        WHERE id_usuario = NEW.id_doador;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_notificar_doacao_recebida
AFTER UPDATE ON doacoes
FOR EACH ROW
EXECUTE FUNCTION notificar_doacao_recebida();

-- Notificar ONG quando coleta for agendada
CREATE OR REPLACE FUNCTION notificar_coleta_agendada()
RETURNS TRIGGER AS $$
DECLARE
    v_id_ong       INTEGER;
    v_nome_doador  VARCHAR(100);
    v_tipo_doacao  VARCHAR(20);
BEGIN
    SELECT d.id_ong, d.tipo, u.nome
    INTO v_id_ong, v_tipo_doacao, v_nome_doador
    FROM doacoes d
    JOIN usuarios u ON d.id_doador = u.id_usuario
    WHERE d.id_doacao = NEW.id_doacao;

    INSERT INTO notificacoes (id_usuario, mensagem, tipo)
    VALUES (
        v_id_ong,
        v_nome_doador || ' agendou uma coleta de ' || v_tipo_doacao ||
        ' para ' || TO_CHAR(NEW.data_agendada, 'DD/MM/YYYY HH24:MI') ||
        ' no local: ' || NEW.endereco,
        'COLETA_AGENDADA'
    );

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_notificar_coleta_agendada
AFTER INSERT ON coletas
FOR EACH ROW
EXECUTE FUNCTION notificar_coleta_agendada();

-- ===========================================
-- 5. VIEWS 
-- ===========================================

CREATE OR REPLACE VIEW vw_resumo_doacoes_pix AS
SELECT
    d.id_ong,
    u.nome AS nome_ong,
    COUNT(*) FILTER (WHERE d.tipo = 'DINHEIRO') AS total_doacoes_dinheiro,
    COUNT(*) FILTER (WHERE d.tipo = 'DINHEIRO' AND d.status_pagamento = 'CONFIRMADO') AS doacoes_confirmadas,
    COUNT(*) FILTER (WHERE d.tipo = 'DINHEIRO' AND d.status_pagamento = 'PENDENTE') AS doacoes_pendentes,
    COUNT(*) FILTER (WHERE d.tipo = 'DINHEIRO' AND d.status_pagamento = 'CANCELADO') AS doacoes_canceladas,
    COALESCE(SUM(d.valor) FILTER (WHERE d.tipo = 'DINHEIRO'), 0) AS valor_total,
    COALESCE(SUM(d.valor) FILTER (WHERE d.tipo = 'DINHEIRO' AND d.status_pagamento = 'CONFIRMADO'), 0) AS valor_confirmado,
    COALESCE(SUM(d.valor) FILTER (WHERE d.tipo = 'DINHEIRO' AND d.status_pagamento = 'PENDENTE'), 0) AS valor_pendente,
    CASE
        WHEN COUNT(*) FILTER (WHERE d.tipo = 'DINHEIRO') > 0
        THEN ROUND(
            100.0 * COUNT(*) FILTER (WHERE d.tipo = 'DINHEIRO' AND d.status_pagamento = 'CONFIRMADO')
            / COUNT(*) FILTER (WHERE d.tipo = 'DINHEIRO'), 2)
        ELSE 0
    END AS taxa_confirmacao_percentual
FROM doacoes d
LEFT JOIN usuarios u ON d.id_ong = u.id_usuario
WHERE d.tipo = 'DINHEIRO'
GROUP BY d.id_ong, u.nome
ORDER BY valor_confirmado DESC;


-- ===========================================
-- 6 . CONSULTAS DE VERIFICAÇÃO
-- ===========================================

-- Verificar estrutura das tabelas
SELECT table_name, column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_name IN (
    'usuarios', 'ongs', 'doadores', 'posts', 'doacoes',
    'coletas', 'avaliacoes', 'notificacoes', 'coletas_visualizadas',
    'itens_ong', 'destino_doacoes', 'recuperacao_senha'
)
ORDER BY table_name, ordinal_position;

-- Verificar triggers
SELECT tgname, tgrelid::regclass, tgtype
FROM pg_trigger
WHERE tgname LIKE 'trigger_%'
ORDER BY tgname;