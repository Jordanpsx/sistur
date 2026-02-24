<?php
/**
 * SISTUR - Script de Restauração de Tabelas do Banco de Horas
 *
 * Este script cria as tabelas que estão faltando para o funcionamento
 * completo do sistema de banco de horas (feriados, abatimentos, config de pagamento).
 *
 * COMO USAR:
 * 1. Faça login como administrador no WordPress
 * 2. Acesse: https://seu-site.com/wp-content/plugins/sistur2/sistur-db-restore.php
 * 3. Verifique o resultado
 * 4. Delete este arquivo após executar!
 *
 * @package SISTUR
 * @version 1.0.0
 */

// Carregar WordPress
require_once('../../../wp-load.php');

// Verificar permissões
if (!current_user_can('manage_options')) {
    wp_die('Você não tem permissão para executar este script.');
}

global $wpdb;

echo "<h1>🔧 SISTUR - Restauração de Tabelas do Banco de Horas</h1>";
echo "<pre>";

$charset_collate = $wpdb->get_charset_collate();
$errors = array();
$success = array();

// ==============================================
// 1. TABELA DE FERIADOS
// ==============================================
echo "\n=== VERIFICANDO TABELA DE FERIADOS ===\n";

$table_holidays = $wpdb->prefix . 'sistur_holidays';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_holidays'") === $table_holidays;

if ($table_exists) {
    echo "✓ Tabela $table_holidays já existe.\n";
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_holidays");
    echo "  → Contém $count feriados cadastrados.\n";
} else {
    echo "! Tabela $table_holidays não existe. Criando...\n";

    $sql = "CREATE TABLE $table_holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        holiday_date DATE NOT NULL COMMENT 'Data do feriado',
        description VARCHAR(255) NOT NULL COMMENT 'Nome/descrição do feriado',
        holiday_type ENUM('nacional', 'estadual', 'municipal', 'ponto_facultativo') DEFAULT 'nacional' COMMENT 'Tipo do feriado',
        multiplicador_adicional DECIMAL(4,2) DEFAULT 1.00 COMMENT 'Multiplicador para horas trabalhadas',
        status ENUM('active', 'inactive') DEFAULT 'active' COMMENT 'Status do feriado',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by INT COMMENT 'WordPress user ID que criou',
        UNIQUE KEY unique_holiday_date (holiday_date),
        INDEX idx_holiday_date (holiday_date),
        INDEX idx_status (status)
    ) $charset_collate COMMENT='Cadastro de feriados e adicionais';";

    $result = $wpdb->query($sql);

    if ($result === false) {
        $errors[] = "Erro ao criar tabela $table_holidays: " . $wpdb->last_error;
    } else {
        $success[] = "Tabela $table_holidays criada com sucesso!";
        echo "✓ Tabela criada. Inserindo feriados padrão...\n";

        // Inserir feriados nacionais 2025 e 2026
        $feriados = array(
            // 2025
            array('2025-01-01', 'Confraternização Universal', 'nacional', 2.00),
            array('2025-02-13', 'Carnaval', 'ponto_facultativo', 1.50),
            array('2025-04-18', 'Sexta-feira Santa', 'nacional', 2.00),
            array('2025-04-21', 'Tiradentes', 'nacional', 2.00),
            array('2025-05-01', 'Dia do Trabalho', 'nacional', 2.00),
            array('2025-06-19', 'Corpus Christi', 'ponto_facultativo', 1.50),
            array('2025-09-07', 'Independência do Brasil', 'nacional', 2.00),
            array('2025-10-12', 'Nossa Senhora Aparecida', 'nacional', 2.00),
            array('2025-11-02', 'Finados', 'nacional', 2.00),
            array('2025-11-15', 'Proclamação da República', 'nacional', 2.00),
            array('2025-11-20', 'Consciência Negra', 'nacional', 2.00),
            array('2025-12-25', 'Natal', 'nacional', 2.00),
            // 2026
            array('2026-01-01', 'Confraternização Universal', 'nacional', 2.00),
            array('2026-02-16', 'Carnaval', 'ponto_facultativo', 1.50),
            array('2026-04-03', 'Sexta-feira Santa', 'nacional', 2.00),
            array('2026-04-21', 'Tiradentes', 'nacional', 2.00),
            array('2026-05-01', 'Dia do Trabalho', 'nacional', 2.00),
            array('2026-06-04', 'Corpus Christi', 'ponto_facultativo', 1.50),
            array('2026-09-07', 'Independência do Brasil', 'nacional', 2.00),
            array('2026-10-12', 'Nossa Senhora Aparecida', 'nacional', 2.00),
            array('2026-11-02', 'Finados', 'nacional', 2.00),
            array('2026-11-15', 'Proclamação da República', 'nacional', 2.00),
            array('2026-11-20', 'Consciência Negra', 'nacional', 2.00),
            array('2026-12-25', 'Natal', 'nacional', 2.00),
        );

        $inserted = 0;
        foreach ($feriados as $f) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_holidays WHERE holiday_date = %s",
                $f[0]
            ));
            if (!$exists) {
                $wpdb->insert($table_holidays, array(
                    'holiday_date' => $f[0],
                    'description' => $f[1],
                    'holiday_type' => $f[2],
                    'multiplicador_adicional' => $f[3],
                    'status' => 'active',
                    'created_by' => get_current_user_id()
                ));
                $inserted++;
            }
        }
        echo "✓ $inserted feriados inseridos.\n";
    }
}

// ==============================================
// 2. TABELA DE ABATIMENTOS DE BANCO DE HORAS
// ==============================================
echo "\n=== VERIFICANDO TABELA DE ABATIMENTOS ===\n";

$table_deductions = $wpdb->prefix . 'sistur_timebank_deductions';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_deductions'") === $table_deductions;

if ($table_exists) {
    echo "✓ Tabela $table_deductions já existe.\n";
} else {
    echo "! Tabela $table_deductions não existe. Criando...\n";

    $sql = "CREATE TABLE $table_deductions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL COMMENT 'ID do funcionário',
        deduction_type ENUM('folga', 'pagamento') NOT NULL COMMENT 'Tipo: folga ou pagamento',
        minutes_deducted INT NOT NULL COMMENT 'Quantidade de minutos abatidos',
        balance_before_minutes INT NOT NULL COMMENT 'Saldo ANTES do abatimento',
        balance_after_minutes INT NOT NULL COMMENT 'Saldo APÓS o abatimento',
        time_off_start_date DATE NULL COMMENT 'Data de início da folga',
        time_off_end_date DATE NULL COMMENT 'Data de fim da folga',
        time_off_description TEXT NULL COMMENT 'Descrição/observação da folga',
        payment_amount DECIMAL(10,2) NULL COMMENT 'Valor em reais a ser pago',
        payment_record_id INT NULL COMMENT 'Referência ao registro de pagamento',
        calculation_details JSON NULL COMMENT 'JSON com breakdown do cálculo detalhado',
        approval_status ENUM('pendente', 'aprovado', 'rejeitado') DEFAULT 'pendente' COMMENT 'Status de aprovação',
        approved_by INT NULL COMMENT 'WordPress user ID que aprovou/rejeitou',
        approved_at DATETIME NULL COMMENT 'Data/hora da aprovação',
        approval_notes TEXT NULL COMMENT 'Observações sobre aprovação/rejeição',
        is_partial BOOLEAN DEFAULT FALSE COMMENT 'Se foi abatimento parcial',
        notes TEXT NULL COMMENT 'Observações gerais',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by INT NOT NULL COMMENT 'WordPress user ID que criou',
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_employee_id (employee_id),
        INDEX idx_deduction_type (deduction_type),
        INDEX idx_approval_status (approval_status),
        INDEX idx_time_off_dates (time_off_start_date, time_off_end_date),
        INDEX idx_created_at (created_at),
        INDEX idx_payment_record_id (payment_record_id)
    ) $charset_collate COMMENT='Registros de abatimentos de banco de horas';";

    $result = $wpdb->query($sql);

    if ($result === false) {
        $errors[] = "Erro ao criar tabela $table_deductions: " . $wpdb->last_error;
    } else {
        $success[] = "Tabela $table_deductions criada com sucesso!";
    }
}

// ==============================================
// 3. TABELA DE CONFIGURAÇÃO DE PAGAMENTO
// ==============================================
echo "\n=== VERIFICANDO TABELA DE CONFIGURAÇÃO DE PAGAMENTO ===\n";

$table_payment_config = $wpdb->prefix . 'sistur_employee_payment_config';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_payment_config'") === $table_payment_config;

if ($table_exists) {
    echo "✓ Tabela $table_payment_config já existe.\n";
} else {
    echo "! Tabela $table_payment_config não existe. Criando...\n";

    $sql = "CREATE TABLE $table_payment_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL COMMENT 'ID do funcionário',
        salario_base DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Salário mensal base',
        valor_hora_base DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor da hora normal',
        multiplicador_dia_util DECIMAL(4,2) DEFAULT 1.50 COMMENT 'Multiplicador para dias úteis (padrão CLT: 50%)',
        multiplicador_fim_semana DECIMAL(4,2) DEFAULT 2.00 COMMENT 'Multiplicador para finais de semana (padrão: 100%)',
        multiplicador_feriado DECIMAL(4,2) DEFAULT 2.50 COMMENT 'Multiplicador para feriados (padrão: 150%)',
        calculation_method ENUM('automatic', 'manual') DEFAULT 'automatic' COMMENT 'Método de cálculo do valor hora',
        last_calculated_at DATETIME NULL COMMENT 'Data da última atualização automática',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_employee (employee_id)
    ) $charset_collate COMMENT='Configuração de pagamento de horas extras por funcionário';";

    $result = $wpdb->query($sql);

    if ($result === false) {
        $errors[] = "Erro ao criar tabela $table_payment_config: " . $wpdb->last_error;
    } else {
        $success[] = "Tabela $table_payment_config criada com sucesso!";
    }
}

// ==============================================
// 4. VERIFICAR E ATUALIZAR TABELA PAYMENT_RECORDS
// ==============================================
echo "\n=== VERIFICANDO COLUNAS EM PAYMENT_RECORDS ===\n";

$table_payment_records = $wpdb->prefix . 'sistur_payment_records';
$col_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_payment_records LIKE 'timebank_deduction_id'");

if (!empty($col_exists)) {
    echo "✓ Coluna timebank_deduction_id já existe.\n";
} else {
    echo "! Adicionando colunas para vínculo com banco de horas...\n";
    $wpdb->query("ALTER TABLE $table_payment_records 
        ADD COLUMN timebank_deduction_id INT NULL COMMENT 'ID do abatimento de banco de horas' AFTER notes,
        ADD COLUMN is_timebank_payment BOOLEAN DEFAULT FALSE COMMENT 'Se é pagamento originado de banco de horas' AFTER timebank_deduction_id,
        ADD INDEX idx_timebank_deduction_id (timebank_deduction_id)");
    echo "✓ Colunas adicionadas.\n";
}

// ==============================================
// RESUMO
// ==============================================
echo "\n=== RESUMO ===\n";

if (!empty($success)) {
    echo "\n✓ SUCESSOS:\n";
    foreach ($success as $msg) {
        echo "  - $msg\n";
    }
}

if (!empty($errors)) {
    echo "\n✗ ERROS:\n";
    foreach ($errors as $msg) {
        echo "  - $msg\n";
    }
}

if (empty($errors)) {
    echo "\n✓✓✓ RESTAURAÇÃO CONCLUÍDA COM SUCESSO! ✓✓✓\n";
    echo "\nPróximos passos:\n";
    echo "1. Teste o módulo de RH novamente\n";
    echo "2. Delete este arquivo (sistur-db-restore.php) por segurança\n";
} else {
    echo "\n✗✗✗ RESTAURAÇÃO CONCLUÍDA COM ERROS ✗✗✗\n";
    echo "\nPor favor, verifique os erros acima e tente novamente.\n";
}

echo "</pre>";
?>
