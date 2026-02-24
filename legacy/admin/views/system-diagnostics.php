<?php
/**
 * Página de Diagnóstico e Limpeza do Sistema SISTUR
 *
 * @package SISTUR
 */

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}

global $wpdb;

// Processar ações de limpeza
$message = '';
$message_type = '';

if (isset($_POST['action']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'sistur_diagnostics')) {
    switch ($_POST['action']) {
        case 'clean_duplicate_options':
            $cleaned = 0;
            $options = $wpdb->get_results("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'sistur%'");
            foreach ($options as $option) {
                // Não deletar opções críticas
                if (!in_array($option->option_name, ['sistur_version', 'sistur_db_version'])) {
                    delete_option($option->option_name);
                    $cleaned++;
                }
            }
            $message = "Limpeza concluída: {$cleaned} opções removidas.";
            $message_type = 'success';
            break;

        case 'clean_orphan_hooks':
            // Remover hooks AJAX órfãos do banco de dados
            $removed = 0;

            // Lista completa de hooks órfãos RCG conhecidos
            $hooks_to_remove = array(
                'wp_ajax_rcg_ajax_check_database',
                'wp_ajax_nopriv_rcg_ajax_check_database',
                'wp_ajax_rcg_update_status',
                'wp_ajax_nopriv_rcg_update_status',
                'wp_ajax_rcg_delete_reservation',
                'wp_ajax_nopriv_rcg_delete_reservation',
                'wp_ajax_rcg_check_database',
                'wp_ajax_nopriv_rcg_check_database',
                'wp_ajax_rcg_recreate_table',
                'wp_ajax_nopriv_rcg_recreate_table',
            );

            foreach ($hooks_to_remove as $hook) {
                // Remover das options
                delete_option($hook);

                // Remover hooks registrados manualmente
                global $wp_filter;
                if (isset($wp_filter[$hook])) {
                    unset($wp_filter[$hook]);
                    $removed++;
                }

                // Remover todas as ações
                remove_all_actions($hook);
            }

            // Limpar cache de objetos
            wp_cache_flush();

            $message = "Hooks órfãos removidos: {$removed}. Total verificado: " . count($hooks_to_remove) . ". Recarregue a página para verificar.";
            $message_type = 'success';
            break;

        case 'recreate_tables':
            // Recriar tabelas faltantes
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $charset_collate = $wpdb->get_charset_collate();
            $created = array();

            // Tabela de Registro de Ponto (Attendance)
            $table_attendance = $wpdb->prefix . 'sistur_attendance';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table_attendance}'") != $table_attendance) {
                $sql_attendance = "CREATE TABLE {$table_attendance} (
                    id bigint(20) NOT NULL AUTO_INCREMENT,
                    employee_id bigint(20) NOT NULL,
                    check_in datetime DEFAULT NULL,
                    check_out datetime DEFAULT NULL,
                    check_in_method varchar(50) DEFAULT NULL,
                    check_out_method varchar(50) DEFAULT NULL,
                    check_in_location varchar(255) DEFAULT NULL,
                    check_out_location varchar(255) DEFAULT NULL,
                    check_in_ip varchar(45) DEFAULT NULL,
                    check_out_ip varchar(45) DEFAULT NULL,
                    check_in_device varchar(255) DEFAULT NULL,
                    check_out_device varchar(255) DEFAULT NULL,
                    notes text,
                    status varchar(20) DEFAULT 'complete',
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY  (id),
                    KEY employee_id (employee_id),
                    KEY check_in (check_in),
                    KEY status (status)
                ) {$charset_collate};";

                dbDelta($sql_attendance);
                $created[] = 'wp_sistur_attendance';
            }

            // Tabela de Log de Auditoria
            $table_audit = $wpdb->prefix . 'sistur_audit_log';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table_audit}'") != $table_audit) {
                $sql_audit = "CREATE TABLE {$table_audit} (
                    id bigint(20) NOT NULL AUTO_INCREMENT,
                    user_id bigint(20) NOT NULL,
                    action_type varchar(50) NOT NULL,
                    entity_type varchar(50) DEFAULT NULL,
                    entity_id bigint(20) DEFAULT NULL,
                    description text,
                    old_values text,
                    new_values text,
                    ip_address varchar(45) DEFAULT NULL,
                    user_agent varchar(255) DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY  (id),
                    KEY user_id (user_id),
                    KEY action_type (action_type),
                    KEY entity_type (entity_type),
                    KEY created_at (created_at)
                ) {$charset_collate};";

                dbDelta($sql_audit);
                $created[] = 'wp_sistur_audit_log';
            }

            if (count($created) > 0) {
                $message = "Tabelas recriadas com sucesso: " . implode(', ', $created);
                $message_type = 'success';
            } else {
                $message = "Todas as tabelas já existem.";
                $message_type = 'info';
            }
            break;

        case 'verify_tables':
            $message = "Verificação de tabelas concluída. Veja os resultados abaixo.";
            $message_type = 'info';
            break;

        case 'clean_transients':
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sistur%' OR option_name LIKE '_transient_timeout_sistur%'");
            $message = "Transients SISTUR limpos com sucesso.";
            $message_type = 'success';
            break;
    }
}

// Diagnóstico do Sistema
$diagnostics = array();

// 1. Verificar plugins instalados
$all_plugins = get_plugins();
$sistur_plugins = array();
foreach ($all_plugins as $plugin_path => $plugin_data) {
    if (stripos($plugin_data['Name'], 'sistur') !== false || stripos($plugin_path, 'sistur') !== false) {
        $sistur_plugins[$plugin_path] = array(
            'name' => $plugin_data['Name'],
            'version' => $plugin_data['Version'],
            'active' => is_plugin_active($plugin_path),
            'path' => WP_PLUGIN_DIR . '/' . dirname($plugin_path)
        );
    }
}
$diagnostics['plugins'] = $sistur_plugins;

// 2. Verificar tabelas do banco de dados
$sistur_tables = array(
    'sistur_employees' => 'Funcionários',
    'sistur_departments' => 'Departamentos',
    'sistur_attendance' => 'Registro de Ponto',
    'sistur_payment_records' => 'Registros de Pagamento',
    'sistur_permissions' => 'Permissões',
    'sistur_roles' => 'Papéis/Funções',
    'sistur_audit_log' => 'Log de Auditoria'
);

$table_status = array();
foreach ($sistur_tables as $table_suffix => $description) {
    $table_name = $wpdb->prefix . $table_suffix;
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

    $table_status[$table_suffix] = array(
        'description' => $description,
        'exists' => $exists,
        'count' => 0,
        'size' => 0
    );

    if ($exists) {
        $table_status[$table_suffix]['count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        $size_result = $wpdb->get_row("
            SELECT
                ROUND((data_length + index_length) / 1024, 2) as size_kb
            FROM information_schema.TABLES
            WHERE table_schema = DATABASE()
            AND table_name = '{$table_name}'
        ");
        $table_status[$table_suffix]['size'] = $size_result ? $size_result->size_kb : 0;
    }
}
$diagnostics['tables'] = $table_status;

// 3. Verificar opções do WordPress
$sistur_options = $wpdb->get_results("
    SELECT option_name, LENGTH(option_value) as size
    FROM {$wpdb->options}
    WHERE option_name LIKE 'sistur%'
    OR option_name LIKE '_transient%sistur%'
    ORDER BY option_name
");
$diagnostics['options'] = $sistur_options;

// 4. Verificar constantes e configurações
$diagnostics['constants'] = array(
    'SISTUR_VERSION' => defined('SISTUR_VERSION') ? SISTUR_VERSION : 'Não definida',
    'SISTUR_PLUGIN_DIR' => defined('SISTUR_PLUGIN_DIR') ? SISTUR_PLUGIN_DIR : 'Não definida',
    'SISTUR_PLUGIN_URL' => defined('SISTUR_PLUGIN_URL') ? SISTUR_PLUGIN_URL : 'Não definida',
);

// 5. Verificar classes carregadas
$diagnostics['classes'] = array(
    'SISTUR_Employees' => class_exists('SISTUR_Employees'),
    'SISTUR_QRCode' => class_exists('SISTUR_QRCode'),
    'SISTUR_Permissions' => class_exists('SISTUR_Permissions'),
    'SISTUR_Audit' => class_exists('SISTUR_Audit'),
);

// 6. Verificar arquivos críticos
$critical_files = array(
    'sistur.php',
    'includes/class-sistur-employees.php',
    'includes/class-sistur-qrcode.php',
    'includes/class-sistur-permissions.php',
    'includes/login-funcionario-new.php',
);

$file_status = array();
foreach ($critical_files as $file) {
    $full_path = SISTUR_PLUGIN_DIR . $file;
    $file_status[$file] = array(
        'exists' => file_exists($full_path),
        'readable' => file_exists($full_path) && is_readable($full_path),
        'size' => file_exists($full_path) ? filesize($full_path) : 0,
    );
}
$diagnostics['files'] = $file_status;

// 7. Verificar hooks AJAX potencialmente problemáticos
global $wp_filter;
$ajax_hooks = array();
$problematic_hooks = array();

if (isset($wp_filter['wp_ajax_nopriv_rcg_ajax_check_database']) || isset($wp_filter['wp_ajax_rcg_ajax_check_database'])) {
    $problematic_hooks[] = array(
        'hook' => 'rcg_ajax_check_database',
        'type' => 'AJAX',
        'issue' => 'Função não encontrada - possível resíduo de plugin RCG desativado/removido',
        'severity' => 'high'
    );
}

// Verificar outros hooks órfãos comuns
$common_orphan_hooks = array(
    'rcg_' => 'Plugin RCG (cache/debug)',
    'wp_rocket_' => 'WP Rocket (cache)',
    'autoptimize_' => 'Autoptimize (otimização)',
    'w3tc_' => 'W3 Total Cache',
);

foreach ($wp_filter as $hook_name => $hook_obj) {
    if (strpos($hook_name, 'wp_ajax_') === 0) {
        $action_name = str_replace('wp_ajax_', '', $hook_name);
        $action_name = str_replace('nopriv_', '', $action_name);

        // Verificar se é de algum plugin conhecido por causar problemas
        foreach ($common_orphan_hooks as $prefix => $plugin_name) {
            if (strpos($action_name, $prefix) === 0) {
                // Verificar se a função existe
                foreach ($hook_obj->callbacks as $priority => $callbacks) {
                    foreach ($callbacks as $callback) {
                        $function_name = '';
                        if (is_string($callback['function'])) {
                            $function_name = $callback['function'];
                        } elseif (is_array($callback['function']) && count($callback['function']) === 2) {
                            if (is_string($callback['function'][0])) {
                                $function_name = $callback['function'][0] . '::' . $callback['function'][1];
                            } elseif (is_object($callback['function'][0])) {
                                $function_name = get_class($callback['function'][0]) . '->' . $callback['function'][1];
                            }
                        }

                        // Verificar se função existe
                        $exists = false;
                        if (is_string($callback['function'])) {
                            $exists = function_exists($callback['function']);
                        } elseif (is_array($callback['function'])) {
                            $exists = is_callable($callback['function']);
                        }

                        if (!$exists && $function_name) {
                            $ajax_hooks[] = array(
                                'hook' => $hook_name,
                                'action' => $action_name,
                                'function' => $function_name,
                                'plugin' => $plugin_name,
                                'exists' => false
                            );
                        }
                    }
                }
            }
        }
    }
}
$diagnostics['ajax_hooks'] = $ajax_hooks;
$diagnostics['problematic_hooks'] = $problematic_hooks;

?>

<div class="wrap">
    <h1>
        <span class="dashicons dashicons-admin-tools" style="font-size: 32px;"></span>
        <?php _e('Diagnóstico e Limpeza do Sistema SISTUR', 'sistur'); ?>
    </h1>

    <?php if ($message): ?>
        <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
    <?php endif; ?>

    <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 4px; border-left: 4px solid #2271b1;">
        <h2>ℹ️ Sobre este Diagnóstico</h2>
        <p>Esta página verifica a integridade do sistema SISTUR e identifica possíveis conflitos ou resíduos de instalações antigas.</p>
    </div>

    <!-- Plugins Instalados -->
    <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 4px;">
        <h2>🔌 Plugins SISTUR Instalados</h2>
        <?php if (count($sistur_plugins) > 1): ?>
            <div class="notice notice-warning inline">
                <p><strong>⚠️ ATENÇÃO:</strong> Foram encontrados múltiplos plugins SISTUR. Isso pode causar conflitos!</p>
            </div>
        <?php endif; ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Plugin</th>
                    <th>Versão</th>
                    <th>Status</th>
                    <th>Caminho</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sistur_plugins as $path => $plugin): ?>
                    <tr>
                        <td><strong><?php echo esc_html($plugin['name']); ?></strong></td>
                        <td><?php echo esc_html($plugin['version']); ?></td>
                        <td>
                            <?php if ($plugin['active']): ?>
                                <span style="color: green;">✓ Ativo</span>
                            <?php else: ?>
                                <span style="color: orange;">○ Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html($plugin['path']); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Tabelas do Banco de Dados -->
    <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 4px;">
        <h2>🗄️ Tabelas do Banco de Dados</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Tabela</th>
                    <th>Descrição</th>
                    <th>Status</th>
                    <th>Registros</th>
                    <th>Tamanho</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($table_status as $table => $info): ?>
                    <tr>
                        <td><code><?php echo esc_html($wpdb->prefix . $table); ?></code></td>
                        <td><?php echo esc_html($info['description']); ?></td>
                        <td>
                            <?php if ($info['exists']): ?>
                                <span style="color: green;">✓ Existe</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Não encontrada</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $info['exists'] ? number_format($info['count']) : '-'; ?></td>
                        <td><?php echo $info['exists'] ? $info['size'] . ' KB' : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Opções do WordPress -->
    <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 4px;">
        <h2>⚙️ Opções e Configurações</h2>
        <p>Total de opções SISTUR: <strong><?php echo count($sistur_options); ?></strong></p>

        <?php if (count($sistur_options) > 50): ?>
            <div class="notice notice-warning inline">
                <p>Muitas opções encontradas. Pode haver resíduos de instalações antigas.</p>
            </div>
        <?php endif; ?>

        <details style="margin-top: 15px;">
            <summary style="cursor: pointer; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                <strong>Ver todas as opções (<?php echo count($sistur_options); ?>)</strong>
            </summary>
            <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th>Nome da Opção</th>
                        <th>Tamanho</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sistur_options as $option): ?>
                        <tr>
                            <td><code><?php echo esc_html($option->option_name); ?></code></td>
                            <td><?php echo number_format($option->size); ?> bytes</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    </div>

    <!-- Constantes e Classes -->
    <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 4px;">
        <h2>🔧 Constantes e Classes Carregadas</h2>

        <h3>Constantes:</h3>
        <table class="wp-list-table widefat fixed striped">
            <tbody>
                <?php foreach ($diagnostics['constants'] as $constant => $value): ?>
                    <tr>
                        <td><code><?php echo esc_html($constant); ?></code></td>
                        <td><?php echo esc_html($value); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top: 20px;">Classes:</h3>
        <table class="wp-list-table widefat fixed striped">
            <tbody>
                <?php foreach ($diagnostics['classes'] as $class => $loaded): ?>
                    <tr>
                        <td><code><?php echo esc_html($class); ?></code></td>
                        <td>
                            <?php if ($loaded): ?>
                                <span style="color: green;">✓ Carregada</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Não carregada</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Arquivos Críticos -->
    <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 4px;">
        <h2>📁 Arquivos Críticos</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Arquivo</th>
                    <th>Status</th>
                    <th>Tamanho</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($file_status as $file => $info): ?>
                    <tr>
                        <td><code><?php echo esc_html($file); ?></code></td>
                        <td>
                            <?php if ($info['exists'] && $info['readable']): ?>
                                <span style="color: green;">✓ OK</span>
                            <?php elseif ($info['exists']): ?>
                                <span style="color: orange;">⚠ Existe mas não é legível</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Não encontrado</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $info['exists'] ? number_format($info['size']) . ' bytes' : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Hooks AJAX Órfãos/Problemáticos -->
    <?php if (!empty($ajax_hooks) || !empty($problematic_hooks)): ?>
    <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 4px; border-left: 4px solid #d63638;">
        <h2>⚠️ Hooks AJAX Órfãos Detectados</h2>

        <div class="notice notice-error inline">
            <p><strong>PROBLEMA DETECTADO:</strong> Foram encontrados hooks AJAX registrados mas sem funções correspondentes.
            Isso causa os erros "function not found" que você está vendo.</p>
        </div>

        <?php if (!empty($problematic_hooks)): ?>
            <h3 style="margin-top: 20px;">Hooks Específicos Conhecidos:</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Hook AJAX</th>
                        <th>Problema</th>
                        <th>Gravidade</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($problematic_hooks as $hook): ?>
                        <tr>
                            <td><code><?php echo esc_html($hook['hook']); ?></code></td>
                            <td><?php echo esc_html($hook['issue']); ?></td>
                            <td>
                                <?php if ($hook['severity'] === 'high'): ?>
                                    <span style="color: red; font-weight: bold;">🔴 Alta</span>
                                <?php else: ?>
                                    <span style="color: orange;">⚠️ Média</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($ajax_hooks)): ?>
            <h3 style="margin-top: 20px;">Outros Hooks Órfãos (<?php echo count($ajax_hooks); ?>):</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Hook</th>
                        <th>Ação</th>
                        <th>Função Ausente</th>
                        <th>Plugin Provável</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ajax_hooks as $hook): ?>
                        <tr>
                            <td><code><?php echo esc_html($hook['hook']); ?></code></td>
                            <td><code><?php echo esc_html($hook['action']); ?></code></td>
                            <td><code><?php echo esc_html($hook['function']); ?></code></td>
                            <td><?php echo esc_html($hook['plugin']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-top: 20px;">
            <h4 style="margin-top: 0;">💡 Como Resolver:</h4>
            <ol>
                <li><strong>Identifique o plugin:</strong> Verifique se há algum plugin com nome "RCG" ou similar nos seus plugins</li>
                <li><strong>Opções de resolução:</strong>
                    <ul>
                        <li>Se o plugin ainda existe: Reative-o ou reinstale-o</li>
                        <li>Se o plugin foi removido: Limpe o banco de dados para remover os hooks órfãos</li>
                        <li>Procure por arquivos residuais em <code>wp-content/plugins/</code></li>
                        <li>Verifique o arquivo <code>wp-content/mu-plugins/</code> por código customizado</li>
                    </ul>
                </li>
                <li><strong>Limpeza manual:</strong> Use um plugin como "WP Reset" ou execute SQL para remover opções do plugin</li>
            </ol>
        </div>
    </div>
    <?php endif; ?>

    <!-- Ações de Limpeza -->
    <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 4px; border-left: 4px solid #d63638;">
        <h2>🧹 Ações de Limpeza e Reparação</h2>
        <p><strong>⚠️ Use com cuidado!</strong> Estas ações modificam o banco de dados.</p>

        <form method="post" style="margin: 20px 0;">
            <?php wp_nonce_field('sistur_diagnostics'); ?>

            <?php if (!empty($ajax_hooks) || !empty($problematic_hooks)): ?>
            <div style="margin: 15px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #d63638;">
                <h3 style="margin-top: 0; color: #d63638;">🚨 Ação Crítica Necessária</h3>
                <button type="submit" name="action" value="clean_orphan_hooks" class="button button-primary"
                        onclick="return confirm('⚠️ IMPORTANTE: Isso vai remover hooks AJAX órfãos do banco de dados.\n\nISSO VAI RESOLVER O ERRO: rcg_ajax_check_database\n\nTem certeza que deseja continuar?');">
                    🔧 LIMPAR HOOKS ÓRFÃOS (RESOLVE ERRO RCG)
                </button>
                <p class="description" style="margin-top: 10px;">
                    <strong>Resolve o erro:</strong> "function rcg_ajax_check_database not found"<br>
                    Remove hooks AJAX registrados mas sem funções correspondentes.
                </p>
            </div>
            <?php endif; ?>

            <?php
            $missing_tables = array_filter($table_status, function($t) { return !$t['exists']; });
            if (count($missing_tables) > 0):
            ?>
            <div style="margin: 15px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #d63638;">
                <h3 style="margin-top: 0; color: #d63638;">🚨 Tabelas Faltando</h3>
                <button type="submit" name="action" value="recreate_tables" class="button button-primary"
                        onclick="return confirm('Isso vai recriar as tabelas faltantes:\n\n<?php echo implode(', ', array_keys($missing_tables)); ?>\n\nTem certeza?');">
                    🔨 RECRIAR TABELAS FALTANTES
                </button>
                <p class="description" style="margin-top: 10px;">
                    <strong>Tabelas faltando:</strong> <?php echo implode(', ', array_keys($missing_tables)); ?><br>
                    Recria automaticamente as tabelas necessárias para o sistema funcionar.
                </p>
            </div>
            <?php endif; ?>

            <hr style="margin: 20px 0;">

            <h3>Outras Ações de Manutenção:</h3>

            <div style="margin: 15px 0;">
                <button type="submit" name="action" value="clean_transients" class="button"
                        onclick="return confirm('Tem certeza que deseja limpar os transients SISTUR?');">
                    🗑️ Limpar Transients SISTUR
                </button>
                <p class="description">Remove dados temporários em cache do SISTUR.</p>
            </div>

            <div id="sistur-tables-check">
                <button type="button" class="button" onclick="sisturCheckTables()">
                    🔍 Verificar Tabelas
                </button>
                <button type="button" class="button button-primary" onclick="sisturTestDbIntegrity()">
                    📝 Teste de Integridade de Escrita
                </button>
            </div>
            <p class="description">Atualiza o diagnóstico das tabelas do banco.</p>
            </div>
        </form>
    </div>

    <!-- Recomendações -->
    <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 4px; border-left: 4px solid #00a32a;">
        <h2>✅ Recomendações</h2>
        <ul style="line-height: 2;">
            <?php if (count($sistur_plugins) > 1): ?>
                <li><strong style="color: #d63638;">❌ CRÍTICO:</strong> Desinstale os plugins SISTUR duplicados e mantenha apenas o plugin atual (sistur2-main).</li>
            <?php else: ?>
                <li><strong style="color: #00a32a;">✓</strong> Apenas um plugin SISTUR está instalado.</li>
            <?php endif; ?>

            <?php
            $missing_tables = array_filter($table_status, function($t) { return !$t['exists']; });
            if (count($missing_tables) > 0):
            ?>
                <li><strong style="color: #d63638;">❌ ATENÇÃO:</strong> Algumas tabelas estão faltando. Desative e reative o plugin para recriar as tabelas.</li>
            <?php else: ?>
                <li><strong style="color: #00a32a;">✓</strong> Todas as tabelas necessárias estão presentes.</li>
            <?php endif; ?>

            <?php
            $missing_files = array_filter($file_status, function($f) { return !$f['exists']; });
            if (count($missing_files) > 0):
            ?>
                <li><strong style="color: #d63638;">❌ ATENÇÃO:</strong> Alguns arquivos críticos estão faltando. Reinstale o plugin.</li>
            <?php else: ?>
                <li><strong style="color: #00a32a;">✓</strong> Todos os arquivos críticos estão presentes.</li>
            <?php endif; ?>

            <?php if (!empty($ajax_hooks) || !empty($problematic_hooks)): ?>
                <li><strong style="color: #d63638;">❌ CRÍTICO:</strong> Hooks AJAX órfãos detectados! Isso está causando os erros "function not found". Verifique a seção de Hooks AJAX acima para resolver.</li>
            <?php else: ?>
                <li><strong style="color: #00a32a;">✓</strong> Nenhum hook AJAX órfão detectado.</li>
            <?php endif; ?>

            <li>💡 <strong>Dica:</strong> Faça backup do banco de dados antes de realizar qualquer ação de limpeza.</li>
        </ul>
    </div>

    <!-- Informações do Sistema -->
    <div style="background: #f0f0f1; padding: 15px; margin: 20px 0; border-radius: 4px; font-size: 12px;">
        <strong>Informações do Sistema:</strong><br>
        WordPress: <?php echo get_bloginfo('version'); ?> |
        PHP: <?php echo PHP_VERSION; ?> |
        MySQL: <?php echo $wpdb->db_version(); ?> |
        Servidor: <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Desconhecido'; ?>
    </div>
</div>

<style>
    details summary:hover {
        background: #e0e0e1;
    }
    .notice.inline {
        margin: 10px 0;
        padding: 10px;
    }
</style>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Definir funções no escopo global para os onclicks
    window.sisturCheckTables = function() {
        var output = $('#sistur-tables-output');
        // Criar container de saída se não existir
        if (output.length === 0) {
             $('#sistur-tables-check').after('<div id="sistur-tables-output" style="margin-top:15px;"></div>');
             output = $('#sistur-tables-output');
        }
        
        output.html('<p>Verificando tabelas... <span class="spinner is-active" style="float:none;"></span></p>');

        $.post(ajaxurl, {
            action: 'sistur_check_tables',
            nonce: '<?php echo wp_create_nonce('sistur_check_tables'); ?>'
        }, function(response) {
            if (response.success) {
                var data = response.data;
                var html = '<table class="widefat">';
                html += '<thead><tr><th>Tabela</th><th>Status</th></tr></thead>';
                html += '<tbody>';

                var hasMissing = false;
                data.tables.forEach(function(table) {
                    html += '<tr>';
                    html += '<td><code>' + table.name + '</code></td>';
                    if (table.exists) {
                        html += '<td><span style="color:green">✓ Existe</span></td>';
                    } else {
                        html += '<td><span style="color:red">✗ Não existe</span></td>';
                        hasMissing = true;
                    }
                    html += '</tr>';
                });

                html += '</tbody></table>';

                if (hasMissing) {
                    html += '<p style="color:red;font-weight:bold;">⚠️ Existem tabelas faltantes! Clique no botão abaixo para recriar.</p>';
                } else {
                    html += '<p style="color:green;font-weight:bold;">✓ Todas as tabelas estão criadas!</p>';
                }

                output.html(html);
            } else {
                output.html('<p style="color:red;">Erro: ' + response.data + '</p>');
            }
        }).fail(function() {
            output.html('<p style="color:red;">Erro ao verificar tabelas!</p>');
        });
    };

    window.sisturTestDbIntegrity = function() {
        var output = $('#sistur-tables-output');
        if (output.length === 0) {
             $('#sistur-tables-check').after('<div id="sistur-tables-output" style="margin-top:15px;"></div>');
             output = $('#sistur-tables-output');
        }

        output.html('<p>Executando teste de integridade... <span class="spinner is-active" style="float:none;"></span></p>');

        $.post(ajaxurl, {
            action: 'sistur_test_db_integrity',
            nonce: '<?php echo wp_create_nonce('sistur_check_tables'); ?>'
        }, function(response) {
            var html = '';
            if (response.success) {
                html += '<div class="notice notice-success inline"><p><strong>' + response.data.message + '</strong></p></div>';
            } else {
                html += '<div class="notice notice-error inline"><p><strong>' + response.data.message + '</strong></p></div>';
            }

            if (response.data.details && response.data.details.length > 0) {
                html += '<ul style="background:#f9f9f9;padding:15px;border:1px solid #ddd;margin-top:10px;">';
                response.data.details.forEach(function(detail) {
                    html += '<li>' + detail + '</li>';
                });
                html += '</ul>';
            }

            output.html(html);
        }).fail(function() {
            output.html('<p style="color:red;">Erro de comunicação ao testar integridade!</p>');
        });
    };
});
</script>
