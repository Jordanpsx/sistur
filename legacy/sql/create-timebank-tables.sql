-- ==========================================
-- SCRIPT DE CRIAÇÃO DAS TABELAS DO SISTEMA
-- DE ABATIMENTO DE BANCO DE HORAS
-- ==========================================
-- Versão: 1.0.0
-- Data: 2025-11-28
-- ==========================================

-- 1. Tabela de Feriados
-- Armazena os feriados nacionais, estaduais e municipais
-- com seus respectivos multiplicadores de adicional
CREATE TABLE IF NOT EXISTS wp_sistur_holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL COMMENT 'Data do feriado',
    description VARCHAR(255) NOT NULL COMMENT 'Nome/descrição do feriado',
    holiday_type ENUM('nacional', 'estadual', 'municipal', 'ponto_facultativo') DEFAULT 'nacional' COMMENT 'Tipo do feriado',
    multiplicador_adicional DECIMAL(4,2) DEFAULT 1.00 COMMENT 'Multiplicador para horas trabalhadas (1.00 = 100%, 1.50 = 150%)',
    status ENUM('active', 'inactive') DEFAULT 'active' COMMENT 'Status do feriado',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INT COMMENT 'WordPress user ID que criou',
    UNIQUE KEY unique_holiday_date (holiday_date),
    INDEX idx_holiday_date (holiday_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cadastro de feriados e adicionais';

-- 2. Tabela de Abatimentos de Banco de Horas
-- Registra todos os abatimentos (folga e pagamento) com seus detalhes
CREATE TABLE IF NOT EXISTS wp_sistur_timebank_deductions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL COMMENT 'ID do funcionário',
    deduction_type ENUM('folga', 'pagamento') NOT NULL COMMENT 'Tipo: folga ou pagamento',
    minutes_deducted INT NOT NULL COMMENT 'Quantidade de minutos abatidos',
    balance_before_minutes INT NOT NULL COMMENT 'Saldo ANTES do abatimento',
    balance_after_minutes INT NOT NULL COMMENT 'Saldo APÓS o abatimento',

    -- Campos específicos para FOLGA
    time_off_start_date DATE NULL COMMENT 'Data de início da folga',
    time_off_end_date DATE NULL COMMENT 'Data de fim da folga',
    time_off_description TEXT NULL COMMENT 'Descrição/observação da folga',

    -- Campos específicos para PAGAMENTO
    payment_amount DECIMAL(10,2) NULL COMMENT 'Valor em reais a ser pago',
    payment_record_id INT NULL COMMENT 'Referência ao registro de pagamento',
    calculation_details JSON NULL COMMENT 'JSON com breakdown do cálculo detalhado',

    -- Campos de APROVAÇÃO
    approval_status ENUM('pendente', 'aprovado', 'rejeitado') DEFAULT 'pendente' COMMENT 'Status de aprovação',
    approved_by INT NULL COMMENT 'WordPress user ID que aprovou/rejeitou',
    approved_at DATETIME NULL COMMENT 'Data/hora da aprovação',
    approval_notes TEXT NULL COMMENT 'Observações sobre aprovação/rejeição',

    -- Campos de CONTROLE
    is_partial BOOLEAN DEFAULT FALSE COMMENT 'Se foi abatimento parcial',
    notes TEXT NULL COMMENT 'Observações gerais',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL COMMENT 'WordPress user ID que criou',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_employee_id (employee_id),
    INDEX idx_deduction_type (deduction_type),
    INDEX idx_approval_status (approval_status),
    INDEX idx_time_off_dates (time_off_start_date, time_off_end_date),
    INDEX idx_created_at (created_at),
    INDEX idx_payment_record_id (payment_record_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registros de abatimentos de banco de horas';

-- 3. Tabela de Configuração de Pagamento por Funcionário
-- Armazena valores e multiplicadores para cálculo de horas extras
CREATE TABLE IF NOT EXISTS wp_sistur_employee_payment_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL COMMENT 'ID do funcionário',

    -- Valores base
    salario_base DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Salário mensal base',
    valor_hora_base DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor da hora normal',

    -- Multiplicadores por tipo de dia
    multiplicador_dia_util DECIMAL(4,2) DEFAULT 1.50 COMMENT 'Multiplicador para dias úteis (padrão CLT: 50%)',
    multiplicador_fim_semana DECIMAL(4,2) DEFAULT 2.00 COMMENT 'Multiplicador para finais de semana (padrão: 100%)',
    multiplicador_feriado DECIMAL(4,2) DEFAULT 2.50 COMMENT 'Multiplicador para feriados (padrão: 150%)',

    -- Controle
    calculation_method ENUM('automatic', 'manual') DEFAULT 'automatic' COMMENT 'Método de cálculo do valor hora',
    last_calculated_at DATETIME NULL COMMENT 'Data da última atualização automática',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_employee (employee_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configuração de pagamento de horas extras por funcionário';

-- 4. Adicionar colunas na tabela de pagamentos existente
-- Permite vincular pagamentos com abatimentos de banco de horas
ALTER TABLE wp_sistur_payment_records
ADD COLUMN IF NOT EXISTS timebank_deduction_id INT NULL COMMENT 'ID do abatimento de banco de horas' AFTER notes,
ADD COLUMN IF NOT EXISTS is_timebank_payment BOOLEAN DEFAULT FALSE COMMENT 'Se é pagamento originado de banco de horas' AFTER timebank_deduction_id;

-- Adicionar índice
ALTER TABLE wp_sistur_payment_records
ADD INDEX IF NOT EXISTS idx_timebank_deduction_id (timebank_deduction_id);

-- 5. Inserir novas permissões no sistema
INSERT IGNORE INTO wp_sistur_permissions (name, module, description) VALUES
('dar_folga', 'time_tracking', 'Permitir registrar abatimento de banco de horas como folga/compensação'),
('pagar_horas_extra', 'payments', 'Permitir pagar horas extras do banco de horas em dinheiro'),
('aprovar_abatimento_banco', 'time_tracking', 'Aprovar ou rejeitar abatimentos de banco de horas'),
('gerenciar_feriados', 'settings', 'Gerenciar cadastro de feriados e adicionais');

-- 6. Atribuir permissões ao papel "Gerente de RH"
-- Gerente de RH tem todas as permissões relacionadas
INSERT IGNORE INTO wp_sistur_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM wp_sistur_roles r
CROSS JOIN wp_sistur_permissions p
WHERE r.name = 'Gerente de RH'
AND p.name IN ('dar_folga', 'pagar_horas_extra', 'aprovar_abatimento_banco', 'gerenciar_feriados');

-- 7. Atribuir permissões ao papel "Supervisor"
-- Supervisor pode dar folga e aprovar abatimentos
INSERT IGNORE INTO wp_sistur_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM wp_sistur_roles r
CROSS JOIN wp_sistur_permissions p
WHERE r.name = 'Supervisor'
AND p.name IN ('dar_folga', 'aprovar_abatimento_banco');

-- 8. Inserir feriados nacionais do Brasil (2025 e 2026)
INSERT IGNORE INTO wp_sistur_holidays (holiday_date, description, holiday_type, multiplicador_adicional) VALUES
-- 2025
('2025-01-01', 'Confraternização Universal', 'nacional', 2.00),
('2025-02-13', 'Carnaval', 'ponto_facultativo', 1.50),
('2025-04-18', 'Sexta-feira Santa', 'nacional', 2.00),
('2025-04-21', 'Tiradentes', 'nacional', 2.00),
('2025-05-01', 'Dia do Trabalho', 'nacional', 2.00),
('2025-06-19', 'Corpus Christi', 'ponto_facultativo', 1.50),
('2025-09-07', 'Independência do Brasil', 'nacional', 2.00),
('2025-10-12', 'Nossa Senhora Aparecida', 'nacional', 2.00),
('2025-11-02', 'Finados', 'nacional', 2.00),
('2025-11-15', 'Proclamação da República', 'nacional', 2.00),
('2025-11-20', 'Consciência Negra', 'nacional', 2.00),
('2025-12-25', 'Natal', 'nacional', 2.00),
-- 2026
('2026-01-01', 'Confraternização Universal', 'nacional', 2.00),
('2026-02-16', 'Carnaval', 'ponto_facultativo', 1.50),
('2026-04-03', 'Sexta-feira Santa', 'nacional', 2.00),
('2026-04-21', 'Tiradentes', 'nacional', 2.00),
('2026-05-01', 'Dia do Trabalho', 'nacional', 2.00),
('2026-06-04', 'Corpus Christi', 'ponto_facultativo', 1.50),
('2026-09-07', 'Independência do Brasil', 'nacional', 2.00),
('2026-10-12', 'Nossa Senhora Aparecida', 'nacional', 2.00),
('2026-11-02', 'Finados', 'nacional', 2.00),
('2026-11-15', 'Proclamação da República', 'nacional', 2.00),
('2026-11-20', 'Consciência Negra', 'nacional', 2.00),
('2026-12-25', 'Natal', 'nacional', 2.00);

-- ==========================================
-- FIM DO SCRIPT
-- ==========================================

-- NOTAS DE INSTALAÇÃO:
-- 1. Substitua 'wp_' pelo seu prefixo do WordPress se for diferente
-- 2. Execute este script via phpMyAdmin ou linha de comando MySQL
-- 3. Verifique se todas as tabelas foram criadas com: SHOW TABLES LIKE '%sistur%';
-- 4. Verifique as permissões: SELECT * FROM wp_sistur_permissions WHERE name LIKE '%folga%' OR name LIKE '%horas_extra%';
-- 5. Após executar o SQL, ative o módulo no sistur.php

-- VERIFICAÇÃO PÓS-INSTALAÇÃO:
-- SELECT COUNT(*) FROM wp_sistur_holidays; -- Deve retornar 24 (feriados 2025 e 2026)
-- SELECT COUNT(*) FROM wp_sistur_permissions WHERE module IN ('time_tracking', 'payments', 'settings'); -- Deve incluir as 4 novas permissões
-- DESCRIBE wp_sistur_timebank_deductions; -- Verificar estrutura da tabela
-- DESCRIBE wp_sistur_employee_payment_config; -- Verificar estrutura da tabela
