<?php
/**
 * Script de Migração: Vincular Escalas a Funcionários
 *
 * Este script automaticamente:
 * 1. Vincula funcionários sem escala a um padrão baseado em time_expected_minutes
 * 2. Cria período de banco de horas ativo para cada funcionário
 * 3. Migra dados legados de contract_type para escalas
 *
 * Para executar: /wp-admin/admin.php?page=sistur-migrate-shifts
 *
 * @package SISTUR
 * @since 2.0.0
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Executa migração de escalas
 */
function sistur_migrate_shift_patterns() {
    global $wpdb;

    error_log('========== SISTUR MIGRATION: Iniciando migração de escalas ==========');

    $table_employees = $wpdb->prefix . 'sistur_employees';
    $table_schedules = $wpdb->prefix . 'sistur_employee_schedules';
    $table_patterns = $wpdb->prefix . 'sistur_shift_patterns';
    $table_time_bank = $wpdb->prefix . 'sistur_time_bank_periods';

    $results = array(
        'employees_processed' => 0,
        'schedules_created' => 0,
        'time_bank_periods_created' => 0,
        'errors' => array()
    );

    // Buscar funcionários sem escala vinculada
    $employees = $wpdb->get_results("
        SELECT e.id, e.name, e.time_expected_minutes, e.hire_date, e.contract_type_id
        FROM $table_employees e
        LEFT JOIN $table_schedules s ON e.id = s.employee_id AND s.is_active = 1
        WHERE s.id IS NULL AND e.status = 1
    ");

    error_log("SISTUR MIGRATION: Encontrados " . count($employees) . " funcionários sem escala vinculada");

    if (empty($employees)) {
        error_log("SISTUR MIGRATION: Nenhum funcionário precisa de migração");
        return $results;
    }

    // Mapear time_expected_minutes para shift_pattern_id
    $pattern_mapping = array(
        720 => 4,  // 12h/dia -> Escala 12x36
        480 => 1,  // 8h/dia -> Escala 6x1 (8h/dia)
        360 => 3,  // 6h/dia -> Escala 5x2 (6h/dia)
        240 => 6,  // 4h/dia -> Horas Flexíveis (36h semanais) ajustado
        0 => 5     // Flexível -> Horas Flexíveis (44h semanais)
    );

    foreach ($employees as $emp) {
        $results['employees_processed']++;

        try {
            // Determinar shift_pattern_id baseado em time_expected_minutes
            $shift_id = isset($pattern_mapping[$emp->time_expected_minutes])
                ? $pattern_mapping[$emp->time_expected_minutes]
                : 1; // Default: 6x1 (8h/dia)

            // Verificar se o padrão existe
            $pattern_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_patterns WHERE id = %d",
                $shift_id
            ));

            if (!$pattern_exists) {
                error_log("SISTUR MIGRATION WARNING: Padrão ID $shift_id não existe, usando padrão 1");
                $shift_id = 1;
            }

            // Criar vinculação de escala
            $schedule_data = array(
                'employee_id' => $emp->id,
                'shift_pattern_id' => $shift_id,
                'start_date' => $emp->hire_date ?: current_time('Y-m-d'),
                'is_active' => 1,
                'notes' => 'Migração automática - vinculação baseada em time_expected_minutes (' . $emp->time_expected_minutes . ' min)'
            );

            $schedule_result = $wpdb->insert($table_schedules, $schedule_data, array('%d', '%d', '%s', '%d', '%s'));

            if ($schedule_result) {
                $results['schedules_created']++;
                error_log("SISTUR MIGRATION: ✓ Escala ID $shift_id vinculada ao funcionário '{$emp->name}' (ID {$emp->id})");
            } else {
                $error = "Falha ao vincular escala ao funcionário '{$emp->name}' (ID {$emp->id}): " . $wpdb->last_error;
                $results['errors'][] = $error;
                error_log("SISTUR MIGRATION ERROR: " . $error);
            }

            // Criar período de banco de horas ativo
            $current_year = date('Y');
            $period_name = "Banco de Horas {$current_year}";
            $start_date = "{$current_year}-01-01";
            $end_date = "{$current_year}-12-31";

            // Verificar se já existe período ativo
            $existing_period = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_time_bank WHERE employee_id = %d AND status = 'active' AND YEAR(start_date) = %d",
                $emp->id,
                $current_year
            ));

            if (!$existing_period) {
                $period_data = array(
                    'employee_id' => $emp->id,
                    'period_name' => $period_name,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'balance_minutes' => 0,
                    'expiration_policy' => '1_year',
                    'expires_at' => date('Y-m-d', strtotime('+1 year', strtotime($end_date))),
                    'expiration_action' => 'require_use',
                    'status' => 'active',
                    'notes' => 'Período criado automaticamente na migração'
                );

                $period_result = $wpdb->insert($table_time_bank, $period_data);

                if ($period_result) {
                    $results['time_bank_periods_created']++;
                    error_log("SISTUR MIGRATION: ✓ Período de banco de horas criado para funcionário '{$emp->name}'");
                } else {
                    error_log("SISTUR MIGRATION WARNING: Falha ao criar período de banco de horas para '{$emp->name}': " . $wpdb->last_error);
                }
            } else {
                error_log("SISTUR MIGRATION: Período de banco de horas já existe para funcionário '{$emp->name}'");
            }

        } catch (Exception $e) {
            $error = "Exceção ao processar funcionário '{$emp->name}' (ID {$emp->id}): " . $e->getMessage();
            $results['errors'][] = $error;
            error_log("SISTUR MIGRATION EXCEPTION: " . $error);
        }
    }

    error_log("========== SISTUR MIGRATION: Migração concluída ==========");
    error_log("SISTUR MIGRATION: Funcionários processados: {$results['employees_processed']}");
    error_log("SISTUR MIGRATION: Escalas criadas: {$results['schedules_created']}");
    error_log("SISTUR MIGRATION: Períodos de banco de horas criados: {$results['time_bank_periods_created']}");
    error_log("SISTUR MIGRATION: Erros: " . count($results['errors']));

    return $results;
}

/**
 * Migrar escalas 12x36 para pattern_config rico
 */
function sistur_migrate_12x36_pattern() {
    global $wpdb;

    error_log('SISTUR MIGRATION: Migrando padrão 12x36 para pattern_config rico');

    $table_patterns = $wpdb->prefix . 'sistur_shift_patterns';

    // Buscar padrão 12x36
    $pattern_12x36 = $wpdb->get_row("SELECT * FROM $table_patterns WHERE name LIKE '%12x36%' LIMIT 1");

    if (!$pattern_12x36) {
        error_log('SISTUR MIGRATION: Padrão 12x36 não encontrado');
        return false;
    }

    // Criar pattern_config rico para 12x36
    $new_config = array(
        'cycle' => array(
            'length_days' => 2,
            'sequence' => array(
                array(
                    'day' => 1,
                    'type' => 'work',
                    'expected_minutes' => 720, // 12 horas
                    'shifts' => array(
                        array(
                            'start' => '07:00',
                            'end' => '19:00',
                            'lunch_minutes' => 60,
                            'expected_minutes' => 660 // 12h - 1h almoço = 11h
                        )
                    )
                ),
                array(
                    'day' => 2,
                    'type' => 'rest',
                    'expected_minutes' => 0
                )
            )
        ),
        'tolerances' => array(
            'late_entry_minutes' => 15,
            'early_exit_minutes' => 10
        )
    );

    $result = $wpdb->update(
        $table_patterns,
        array('pattern_config' => wp_json_encode($new_config)),
        array('id' => $pattern_12x36->id),
        array('%s'),
        array('%d')
    );

    if ($result !== false) {
        error_log("SISTUR MIGRATION: ✓ Padrão 12x36 migrado com sucesso para pattern_config rico");
        return true;
    } else {
        error_log("SISTUR MIGRATION ERROR: Falha ao migrar padrão 12x36: " . $wpdb->last_error);
        return false;
    }
}

/**
 * Página de administração da migração
 */
function sistur_migration_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Permissão negada');
    }

    // Executar migração se solicitado
    if (isset($_POST['run_migration']) && check_admin_referer('sistur_migration_nonce')) {
        $results = sistur_migrate_shift_patterns();
        $results_12x36 = sistur_migrate_12x36_pattern();

        echo '<div class="notice notice-success"><p><strong>Migração concluída!</strong></p>';
        echo '<ul>';
        echo '<li>Funcionários processados: ' . $results['employees_processed'] . '</li>';
        echo '<li>Escalas criadas: ' . $results['schedules_created'] . '</li>';
        echo '<li>Períodos de banco de horas criados: ' . $results['time_bank_periods_created'] . '</li>';
        echo '<li>Padrão 12x36 migrado: ' . ($results_12x36 ? 'Sim' : 'Não') . '</li>';
        if (!empty($results['errors'])) {
            echo '<li style="color: red;">Erros: ' . count($results['errors']) . '</li>';
        }
        echo '</ul></div>';

        if (!empty($results['errors'])) {
            echo '<div class="notice notice-error"><p><strong>Erros durante a migração:</strong></p><ul>';
            foreach ($results['errors'] as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul></div>';
        }
    }

    ?>
    <div class="wrap">
        <h1>SISTUR - Migração de Escalas</h1>

        <div class="card">
            <h2>Migração Automática de Escalas</h2>
            <p>Este script irá automaticamente:</p>
            <ul>
                <li>✓ Vincular funcionários sem escala a um padrão baseado em <code>time_expected_minutes</code></li>
                <li>✓ Criar período de banco de horas ativo para cada funcionário</li>
                <li>✓ Migrar padrão 12x36 para <code>pattern_config</code> rico</li>
            </ul>

            <form method="post">
                <?php wp_nonce_field('sistur_migration_nonce'); ?>
                <p>
                    <button type="submit" name="run_migration" class="button button-primary button-large">
                        Executar Migração
                    </button>
                </p>
            </form>
        </div>

        <div class="card">
            <h2>Estatísticas</h2>
            <?php
            global $wpdb;
            $table_employees = $wpdb->prefix . 'sistur_employees';
            $table_schedules = $wpdb->prefix . 'sistur_employee_schedules';

            $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_employees WHERE status = 1");
            $with_schedule = $wpdb->get_var("SELECT COUNT(DISTINCT employee_id) FROM $table_schedules WHERE is_active = 1");
            $without_schedule = $total - $with_schedule;
            ?>
            <ul>
                <li>Total de funcionários ativos: <strong><?php echo $total; ?></strong></li>
                <li>Com escala vinculada: <strong><?php echo $with_schedule; ?></strong></li>
                <li>Sem escala vinculada: <strong style="color: <?php echo $without_schedule > 0 ? 'red' : 'green'; ?>;"><?php echo $without_schedule; ?></strong></li>
            </ul>
        </div>
    </div>
    <?php
}

/**
 * Adicionar página de migração ao menu admin (se chamado manualmente)
 */
add_action('admin_menu', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'sistur-migrate-shifts') {
        add_submenu_page(
            null, // Página oculta (sem menu)
            'Migração de Escalas',
            'Migração de Escalas',
            'manage_options',
            'sistur-migrate-shifts',
            'sistur_migration_admin_page'
        );
    }
});
