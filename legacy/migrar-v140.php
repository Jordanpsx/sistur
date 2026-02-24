<?php
/**
 * Script de Migração Manual para SISTUR v1.4.0
 * Adiciona colunas do sistema de ponto e QR codes
 *
 * IMPORTANTE: Execute este script UMA ÚNICA VEZ
 * Acesso: http://seu-site.com/wp-content/plugins/sistur/migrar-v140.php
 */

// Carregar WordPress
require_once('../../../wp-load.php');

// Verificar permissões
if (!current_user_can('manage_options')) {
    wp_die('Você não tem permissão para acessar esta página.');
}

global $wpdb;
$errors = array();
$success = array();

// Prevenir execução múltipla
$migration_done = get_option('sistur_migration_140_executed', false);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migração SISTUR v1.4.0</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f0f1;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1d2327;
            margin-bottom: 10px;
        }
        h2 {
            color: #2271b1;
            margin-top: 30px;
            border-bottom: 2px solid #2271b1;
            padding-bottom: 10px;
        }
        .success-box {
            background: #f0f6f0;
            border-left: 4px solid #00a32a;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .error-box {
            background: #fcf0f1;
            border-left: 4px solid #d63638;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .warning-box {
            background: #fef8f0;
            border-left: 4px solid #dba617;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .info-box {
            background: #f0f6fc;
            border-left: 4px solid #2271b1;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .success { color: #00a32a; font-weight: bold; }
        .error { color: #d63638; font-weight: bold; }
        .warning { color: #dba617; font-weight: bold; }
        code {
            background: #f6f7f7;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        .step {
            margin: 20px 0;
            padding: 15px;
            background: #f6f7f7;
            border-radius: 4px;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #2271b1;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 600;
            margin: 10px 5px;
        }
        .btn:hover {
            background: #135e96;
        }
        .btn-danger {
            background: #d63638;
        }
        .btn-danger:hover {
            background: #b32d2e;
        }
        ul {
            margin: 10px 0;
            padding-left: 25px;
        }
        li {
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Migração SISTUR v1.4.0</h1>
        <p style="color: #646970;">Sistema de Ponto Eletrônico e QR Codes</p>

        <?php if ($migration_done && !isset($_GET['force'])): ?>
            <div class="warning-box">
                <strong class="warning">⚠️ Migração já foi executada!</strong><br>
                Esta migração já foi executada anteriormente em <strong><?php echo get_option('sistur_migration_140_date'); ?></strong>.<br><br>
                Se você realmente precisa executar novamente, <a href="?force=1">clique aqui</a> (use com cautela!).
            </div>

            <div style="margin-top: 30px; text-align: center;">
                <a href="<?php echo admin_url('admin.php?page=sistur-qrcodes'); ?>" class="btn">
                    ← Voltar para QR Codes
                </a>
            </div>
        <?php else: ?>

            <?php if (isset($_GET['execute']) && $_GET['execute'] === 'yes'): ?>
                <h2>📝 Executando Migração...</h2>

                <?php
                // Preparar variáveis necessárias
                $charset_collate = $wpdb->get_charset_collate();
                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

                // PASSO 1: Adicionar colunas na tabela employees
                $table_employees = $wpdb->prefix . 'sistur_employees';

                echo '<div class="step">';
                echo '<strong>Passo 1:</strong> Adicionar colunas na tabela <code>' . $table_employees . '</code><br>';

                // Verificar e adicionar token_qr
                $token_qr_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_employees LIKE 'token_qr'");
                if (empty($token_qr_exists)) {
                    $result = $wpdb->query("ALTER TABLE $table_employees
                        ADD COLUMN token_qr varchar(36) DEFAULT NULL UNIQUE");

                    if ($result !== false) {
                        echo '<span class="success">✅ Coluna token_qr adicionada</span><br>';
                        $success[] = 'Coluna token_qr criada';
                    } else {
                        echo '<span class="error">❌ Erro ao adicionar token_qr: ' . $wpdb->last_error . '</span><br>';
                        $errors[] = 'token_qr: ' . $wpdb->last_error;
                    }
                } else {
                    echo '<span class="warning">⚠️ Coluna token_qr já existe</span><br>';
                }

                // Verificar e adicionar contract_type_id
                $contract_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_employees LIKE 'contract_type_id'");
                if (empty($contract_exists)) {
                    $result = $wpdb->query("ALTER TABLE $table_employees
                        ADD COLUMN contract_type_id mediumint(9) DEFAULT NULL,
                        ADD KEY contract_type_id (contract_type_id)");

                    if ($result !== false) {
                        echo '<span class="success">✅ Coluna contract_type_id adicionada</span><br>';
                        $success[] = 'Coluna contract_type_id criada';
                    } else {
                        echo '<span class="error">❌ Erro ao adicionar contract_type_id: ' . $wpdb->last_error . '</span><br>';
                        $errors[] = 'contract_type_id: ' . $wpdb->last_error;
                    }
                } else {
                    echo '<span class="warning">⚠️ Coluna contract_type_id já existe</span><br>';
                }

                // Verificar e adicionar perfil_localizacao
                $perfil_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_employees LIKE 'perfil_localizacao'");
                if (empty($perfil_exists)) {
                    $result = $wpdb->query("ALTER TABLE $table_employees
                        ADD COLUMN perfil_localizacao enum('INTERNO','EXTERNO') DEFAULT 'INTERNO'");

                    if ($result !== false) {
                        echo '<span class="success">✅ Coluna perfil_localizacao adicionada</span><br>';
                        $success[] = 'Coluna perfil_localizacao criada';
                    } else {
                        echo '<span class="error">❌ Erro ao adicionar perfil_localizacao: ' . $wpdb->last_error . '</span><br>';
                        $errors[] = 'perfil_localizacao: ' . $wpdb->last_error;
                    }
                } else {
                    echo '<span class="warning">⚠️ Coluna perfil_localizacao já existe</span><br>';
                }

                echo '</div>';

                // PASSO 2: Criar tabela contract_types
                echo '<div class="step">';
                echo '<strong>Passo 2:</strong> Criar tabela de tipos de contrato<br>';

                $table_contract_types = $wpdb->prefix . 'sistur_contract_types';
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_contract_types'");

                if (!$table_exists) {
                    $sql = "CREATE TABLE $table_contract_types (
                        id mediumint(9) NOT NULL AUTO_INCREMENT,
                        descricao varchar(255) NOT NULL,
                        carga_horaria_diaria_minutos int NOT NULL,
                        carga_horaria_semanal_minutos int NOT NULL,
                        intervalo_minimo_almoco_minutos int DEFAULT 0,
                        created_at datetime DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id)
                    ) $charset_collate;";

                    dbDelta($sql);

                    echo '<span class="success">✅ Tabela sistur_contract_types criada</span><br>';
                    $success[] = 'Tabela contract_types criada';

                    // Inserir tipos padrão
                    $wpdb->insert($table_contract_types, array(
                        'descricao' => 'Mensalista 8h/dia (44h semanais)',
                        'carga_horaria_diaria_minutos' => 480,
                        'carga_horaria_semanal_minutos' => 2640,
                        'intervalo_minimo_almoco_minutos' => 60
                    ));

                    $wpdb->insert($table_contract_types, array(
                        'descricao' => 'Mensalista 6h/dia (36h semanais)',
                        'carga_horaria_diaria_minutos' => 360,
                        'carga_horaria_semanal_minutos' => 2160,
                        'intervalo_minimo_almoco_minutos' => 15
                    ));

                    $wpdb->insert($table_contract_types, array(
                        'descricao' => 'Mensalista 4h/dia (24h semanais)',
                        'carga_horaria_diaria_minutos' => 240,
                        'carga_horaria_semanal_minutos' => 1440,
                        'intervalo_minimo_almoco_minutos' => 0
                    ));

                    echo '<span class="success">✅ 3 tipos de contrato padrão inseridos</span><br>';
                } else {
                    echo '<span class="warning">⚠️ Tabela contract_types já existe</span><br>';
                }

                echo '</div>';

                // PASSO 3: Criar tabela settings
                echo '<div class="step">';
                echo '<strong>Passo 3:</strong> Criar tabela de configurações<br>';

                $table_settings = $wpdb->prefix . 'sistur_settings';
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_settings'");

                if (!$table_exists) {
                    $sql = "CREATE TABLE $table_settings (
                        id mediumint(9) NOT NULL AUTO_INCREMENT,
                        setting_key varchar(100) NOT NULL UNIQUE,
                        setting_value text NOT NULL,
                        setting_type varchar(20) DEFAULT 'string',
                        description text DEFAULT NULL,
                        created_at datetime DEFAULT CURRENT_TIMESTAMP,
                        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        KEY setting_key (setting_key)
                    ) $charset_collate;";

                    dbDelta($sql);

                    echo '<span class="success">✅ Tabela sistur_settings criada</span><br>';
                    $success[] = 'Tabela settings criada';

                    // Inserir configurações padrão
                    $settings = array(
                        array('tolerance_minutes_per_punch', '5', 'integer', 'Tolerância em minutos por batida'),
                        array('tolerance_type', 'PER_PUNCH', 'string', 'Tipo de tolerância'),
                        array('cron_secret_key', wp_generate_password(32, false), 'string', 'Chave secreta do cron'),
                        array('auto_processing_enabled', '1', 'boolean', 'Processamento automático habilitado'),
                        array('processing_batch_size', '50', 'integer', 'Tamanho do lote de processamento'),
                        array('processing_time', '01:00', 'string', 'Horário do processamento')
                    );

                    foreach ($settings as $setting) {
                        $wpdb->insert($table_settings, array(
                            'setting_key' => $setting[0],
                            'setting_value' => $setting[1],
                            'setting_type' => $setting[2],
                            'description' => $setting[3]
                        ));
                    }

                    echo '<span class="success">✅ 6 configurações padrão inseridas</span><br>';
                } else {
                    echo '<span class="warning">⚠️ Tabela settings já existe</span><br>';
                }

                echo '</div>';

                // PASSO 4: Gerar tokens para funcionários existentes
                echo '<div class="step">';
                echo '<strong>Passo 4:</strong> Gerar tokens UUID para funcionários<br>';

                if (class_exists('SISTUR_QRCode')) {
                    $qrcode = SISTUR_QRCode::get_instance();
                    $result = $qrcode->generate_tokens_for_all_employees();

                    if ($result['total'] > 0) {
                        echo '<span class="success">✅ Tokens gerados: ' . $result['generated'] . ' de ' . $result['total'] . '</span><br>';
                        $success[] = 'Tokens UUID gerados para ' . $result['generated'] . ' funcionários';

                        if ($result['failed'] > 0) {
                            echo '<span class="error">❌ Falhas: ' . $result['failed'] . '</span><br>';
                        }
                    } else {
                        echo '<span class="warning">⚠️ Nenhum funcionário sem token encontrado</span><br>';
                    }
                } else {
                    echo '<span class="error">❌ Classe SISTUR_QRCode não encontrada</span><br>';
                    $errors[] = 'Classe SISTUR_QRCode não disponível';
                }

                echo '</div>';

                // Marcar migração como executada
                update_option('sistur_migration_140_executed', true);
                update_option('sistur_migration_140_date', current_time('mysql'));
                update_option('sistur_db_version', '1.4.0');

                // Resumo final
                echo '<h2>📊 Resumo da Migração</h2>';

                if (!empty($success)) {
                    echo '<div class="success-box">';
                    echo '<strong class="success">✅ Operações bem-sucedidas (' . count($success) . '):</strong><br><ul>';
                    foreach ($success as $msg) {
                        echo '<li>' . $msg . '</li>';
                    }
                    echo '</ul></div>';
                }

                if (!empty($errors)) {
                    echo '<div class="error-box">';
                    echo '<strong class="error">❌ Erros encontrados (' . count($errors) . '):</strong><br><ul>';
                    foreach ($errors as $msg) {
                        echo '<li>' . $msg . '</li>';
                    }
                    echo '</ul></div>';
                }

                if (empty($errors)) {
                    echo '<div class="success-box">';
                    echo '<strong class="success">🎉 Migração concluída com sucesso!</strong><br>';
                    echo 'Todas as colunas e tabelas necessárias foram criadas.<br>';
                    echo 'Você já pode acessar a página de QR Codes.';
                    echo '</div>';
                }

                ?>

                <div style="margin-top: 30px; text-align: center;">
                    <a href="<?php echo admin_url('admin.php?page=sistur-qrcodes'); ?>" class="btn">
                        Ver QR Codes →
                    </a>
                    <a href="<?php echo plugin_dir_url(__FILE__) . 'diagnostico-bd.php'; ?>" class="btn" style="background: #646970;">
                        Executar Diagnóstico
                    </a>
                </div>

            <?php else: ?>
                <!-- Tela de confirmação -->
                <div class="warning-box">
                    <strong class="warning">⚠️ IMPORTANTE: Leia antes de continuar</strong><br><br>
                    Este script irá modificar o banco de dados do seu WordPress. <strong>Faça um backup antes de prosseguir!</strong>
                </div>

                <h2>🔍 O que será feito?</h2>

                <div class="info-box">
                    <strong>Esta migração irá:</strong>
                    <ol>
                        <li>Adicionar coluna <code>token_qr</code> na tabela de funcionários</li>
                        <li>Adicionar coluna <code>contract_type_id</code> na tabela de funcionários</li>
                        <li>Adicionar coluna <code>perfil_localizacao</code> na tabela de funcionários</li>
                        <li>Criar tabela <code>sistur_contract_types</code> com 3 tipos padrão</li>
                        <li>Criar tabela <code>sistur_settings</code> com configurações do sistema</li>
                        <li>Gerar tokens UUID para todos os funcionários existentes</li>
                    </ol>
                </div>

                <h2>📋 Pré-requisitos</h2>

                <div class="step">
                    <input type="checkbox" id="backup-check" style="margin-right: 10px;">
                    <label for="backup-check">Fiz backup do banco de dados</label>
                </div>

                <div class="step">
                    <input type="checkbox" id="understand-check" style="margin-right: 10px;">
                    <label for="understand-check">Entendo que esta operação modificará o banco de dados</label>
                </div>

                <div style="margin-top: 30px; text-align: center;">
                    <a href="?execute=yes" class="btn btn-danger" id="execute-btn" onclick="return confirm('Tem certeza que deseja executar a migração?');" style="opacity: 0.5; pointer-events: none;">
                        Executar Migração
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=sistur-qrcodes'); ?>" class="btn" style="background: #646970;">
                        Cancelar
                    </a>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const backupCheck = document.getElementById('backup-check');
                        const understandCheck = document.getElementById('understand-check');
                        const executeBtn = document.getElementById('execute-btn');

                        function updateButton() {
                            if (backupCheck.checked && understandCheck.checked) {
                                executeBtn.style.opacity = '1';
                                executeBtn.style.pointerEvents = 'auto';
                            } else {
                                executeBtn.style.opacity = '0.5';
                                executeBtn.style.pointerEvents = 'none';
                            }
                        }

                        backupCheck.addEventListener('change', updateButton);
                        understandCheck.addEventListener('change', updateButton);
                    });
                </script>

            <?php endif; ?>

        <?php endif; ?>
    </div>
</body>
</html>
