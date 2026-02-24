<?php
/**
 * Script: Criar Escalas Semanais (44h)
 *
 * Cria escalas com horas diferentes por dia da semana
 * usando o novo pattern_type: weekly
 *
 * Exemplos:
 * - 5 dias de 8h + 1 dia de 4h = 44h semanais
 * - Segunda a Sexta 8h, Sábado 4h, Domingo folga
 *
 * Para executar: /wp-admin/admin.php?page=sistur-create-weekly-patterns
 *
 * @package SISTUR
 * @since 2.0.0
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Criar escalas semanais (44h)
 */
function sistur_create_weekly_shift_patterns() {
    global $wpdb;
    $table_patterns = $wpdb->prefix . 'sistur_shift_patterns';

    error_log('========== SISTUR: Criando escalas semanais (44h) ==========');

    $patterns_created = 0;
    $patterns_updated = 0;

    // =========================================================================
    // ESCALA 1: 44h Semanais (Segunda a Sexta 8h + Sábado 4h)
    // =========================================================================
    $pattern_44h_config = array(
        'pattern_type' => 'weekly',
        'weekly' => array(
            'Monday' => array(
                'type' => 'work',
                'expected_minutes' => 480,  // 8 horas
                'lunch_minutes' => 60
            ),
            'Tuesday' => array(
                'type' => 'work',
                'expected_minutes' => 480,
                'lunch_minutes' => 60
            ),
            'Wednesday' => array(
                'type' => 'work',
                'expected_minutes' => 480,
                'lunch_minutes' => 60
            ),
            'Thursday' => array(
                'type' => 'work',
                'expected_minutes' => 480,
                'lunch_minutes' => 60
            ),
            'Friday' => array(
                'type' => 'work',
                'expected_minutes' => 480,
                'lunch_minutes' => 60
            ),
            'Saturday' => array(
                'type' => 'work',
                'expected_minutes' => 240,  // 4 horas
                'lunch_minutes' => 0
            ),
            'Sunday' => array(
                'type' => 'rest',
                'expected_minutes' => 0,
                'lunch_minutes' => 0
            )
        ),
        'description' => 'Segunda a Sexta 8h + Sábado 4h = 44h semanais'
    );

    // Verificar se já existe
    $existing = $wpdb->get_var("SELECT id FROM $table_patterns WHERE name = 'Escala 44h Semanais (5x8h + 1x4h)'");

    if ($existing) {
        // Atualizar pattern_config
        $wpdb->update(
            $table_patterns,
            array('pattern_config' => wp_json_encode($pattern_44h_config)),
            array('id' => $existing),
            array('%s'),
            array('%d')
        );
        $patterns_updated++;
        error_log("SISTUR: ✓ Escala 44h atualizada (ID: $existing)");
    } else {
        // Criar nova
        $wpdb->insert($table_patterns, array(
            'name' => 'Escala 44h Semanais (5x8h + 1x4h)',
            'description' => 'Segunda a Sexta 8h + Sábado 4h = 44h semanais. Domingo folga.',
            'pattern_type' => 'weekly_rotation',
            'work_days_count' => 6,
            'rest_days_count' => 1,
            'weekly_hours_minutes' => 2640,  // 44 horas
            'daily_hours_minutes' => 0,  // Variável por dia
            'lunch_break_minutes' => 60,
            'pattern_config' => wp_json_encode($pattern_44h_config),
            'status' => 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ));
        $patterns_created++;
        error_log("SISTUR: ✓ Escala 44h criada (ID: " . $wpdb->insert_id . ")");
    }

    // =========================================================================
    // ESCALA 2: 40h Semanais (Segunda a Sexta 8h)
    // =========================================================================
    $pattern_40h_config = array(
        'pattern_type' => 'weekly',
        'weekly' => array(
            'Monday' => array('type' => 'work', 'expected_minutes' => 480, 'lunch_minutes' => 60),
            'Tuesday' => array('type' => 'work', 'expected_minutes' => 480, 'lunch_minutes' => 60),
            'Wednesday' => array('type' => 'work', 'expected_minutes' => 480, 'lunch_minutes' => 60),
            'Thursday' => array('type' => 'work', 'expected_minutes' => 480, 'lunch_minutes' => 60),
            'Friday' => array('type' => 'work', 'expected_minutes' => 480, 'lunch_minutes' => 60),
            'Saturday' => array('type' => 'rest', 'expected_minutes' => 0, 'lunch_minutes' => 0),
            'Sunday' => array('type' => 'rest', 'expected_minutes' => 0, 'lunch_minutes' => 0)
        ),
        'description' => 'Segunda a Sexta 8h = 40h semanais, finais de semana folga'
    );

    $existing_40h = $wpdb->get_var("SELECT id FROM $table_patterns WHERE name = 'Escala 40h Semanais (5x8h)'");

    if ($existing_40h) {
        $wpdb->update(
            $table_patterns,
            array('pattern_config' => wp_json_encode($pattern_40h_config)),
            array('id' => $existing_40h),
            array('%s'),
            array('%d')
        );
        $patterns_updated++;
        error_log("SISTUR: ✓ Escala 40h atualizada (ID: $existing_40h)");
    } else {
        $wpdb->insert($table_patterns, array(
            'name' => 'Escala 40h Semanais (5x8h)',
            'description' => 'Segunda a Sexta 8h = 40h semanais. Finais de semana folga.',
            'pattern_type' => 'weekly_rotation',
            'work_days_count' => 5,
            'rest_days_count' => 2,
            'weekly_hours_minutes' => 2400,  // 40 horas
            'daily_hours_minutes' => 0,  // Variável por dia
            'lunch_break_minutes' => 60,
            'pattern_config' => wp_json_encode($pattern_40h_config),
            'status' => 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ));
        $patterns_created++;
        error_log("SISTUR: ✓ Escala 40h criada (ID: " . $wpdb->insert_id . ")");
    }

    // =========================================================================
    // ESCALA 3: 36h Semanais (Segunda a Sexta 6h + Sábado 6h)
    // =========================================================================
    $pattern_36h_config = array(
        'pattern_type' => 'weekly',
        'weekly' => array(
            'Monday' => array('type' => 'work', 'expected_minutes' => 360, 'lunch_minutes' => 15),
            'Tuesday' => array('type' => 'work', 'expected_minutes' => 360, 'lunch_minutes' => 15),
            'Wednesday' => array('type' => 'work', 'expected_minutes' => 360, 'lunch_minutes' => 15),
            'Thursday' => array('type' => 'work', 'expected_minutes' => 360, 'lunch_minutes' => 15),
            'Friday' => array('type' => 'work', 'expected_minutes' => 360, 'lunch_minutes' => 15),
            'Saturday' => array('type' => 'work', 'expected_minutes' => 360, 'lunch_minutes' => 15),
            'Sunday' => array('type' => 'rest', 'expected_minutes' => 0, 'lunch_minutes' => 0)
        ),
        'description' => 'Segunda a Sábado 6h = 36h semanais'
    );

    $existing_36h = $wpdb->get_var("SELECT id FROM $table_patterns WHERE name = 'Escala 36h Semanais (6x6h)'");

    if ($existing_36h) {
        $wpdb->update(
            $table_patterns,
            array('pattern_config' => wp_json_encode($pattern_36h_config)),
            array('id' => $existing_36h),
            array('%s'),
            array('%d')
        );
        $patterns_updated++;
        error_log("SISTUR: ✓ Escala 36h atualizada (ID: $existing_36h)");
    } else {
        $wpdb->insert($table_patterns, array(
            'name' => 'Escala 36h Semanais (6x6h)',
            'description' => 'Segunda a Sábado 6h = 36h semanais. Domingo folga.',
            'pattern_type' => 'weekly_rotation',
            'work_days_count' => 6,
            'rest_days_count' => 1,
            'weekly_hours_minutes' => 2160,  // 36 horas
            'daily_hours_minutes' => 0,  // Variável por dia
            'lunch_break_minutes' => 15,
            'pattern_config' => wp_json_encode($pattern_36h_config),
            'status' => 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ));
        $patterns_created++;
        error_log("SISTUR: ✓ Escala 36h criada (ID: " . $wpdb->insert_id . ")");
    }

    error_log("========== SISTUR: Criação concluída ==========");
    error_log("SISTUR: Escalas criadas: $patterns_created");
    error_log("SISTUR: Escalas atualizadas: $patterns_updated");

    return array(
        'created' => $patterns_created,
        'updated' => $patterns_updated
    );
}

/**
 * Página admin para criar escalas semanais
 */
function sistur_create_weekly_patterns_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Permissão negada');
    }

    // Executar criação se solicitado
    if (isset($_POST['create_patterns']) && check_admin_referer('sistur_create_weekly_nonce')) {
        $result = sistur_create_weekly_shift_patterns();

        echo '<div class="notice notice-success"><p><strong>Escalas semanais criadas/atualizadas!</strong></p>';
        echo '<ul>';
        echo '<li>Escalas criadas: ' . $result['created'] . '</li>';
        echo '<li>Escalas atualizadas: ' . $result['updated'] . '</li>';
        echo '</ul></div>';
    }

    ?>
    <div class="wrap">
        <h1>SISTUR - Criar Escalas Semanais (44h, 40h, 36h)</h1>

        <div class="card">
            <h2>Escalas Semanais com Horas Variáveis</h2>
            <p>Este script cria escalas com <strong>horas diferentes por dia da semana</strong>:</p>

            <h3>Escalas que serão criadas:</h3>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Escala</th>
                        <th>Descrição</th>
                        <th>Horas Semanais</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>44h Semanais</strong></td>
                        <td>Segunda a Sexta 8h + Sábado 4h</td>
                        <td>44h</td>
                    </tr>
                    <tr>
                        <td><strong>40h Semanais</strong></td>
                        <td>Segunda a Sexta 8h (fim de semana folga)</td>
                        <td>40h</td>
                    </tr>
                    <tr>
                        <td><strong>36h Semanais</strong></td>
                        <td>Segunda a Sábado 6h</td>
                        <td>36h</td>
                    </tr>
                </tbody>
            </table>

            <h3>Como funciona:</h3>
            <ul>
                <li>✅ Cada dia da semana pode ter horas diferentes</li>
                <li>✅ Dias de folga são automaticamente reconhecidos (0h esperadas)</li>
                <li>✅ Banco de horas calculado dinamicamente por dia</li>
                <li>✅ Suporte a exceções (feriados sobrescrevem a escala)</li>
            </ul>

            <form method="post">
                <?php wp_nonce_field('sistur_create_weekly_nonce'); ?>
                <p>
                    <button type="submit" name="create_patterns" class="button button-primary button-large">
                        🔧 Criar/Atualizar Escalas Semanais
                    </button>
                </p>
            </form>
        </div>

        <div class="card">
            <h2>Exemplo de Configuração (44h)</h2>
            <pre style="background: #f5f5f5; padding: 15px; border-radius: 5px;">
{
  "pattern_type": "weekly",
  "weekly": {
    "Monday":    {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
    "Tuesday":   {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
    "Wednesday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
    "Thursday":  {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
    "Friday":    {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
    "Saturday":  {"type": "work", "expected_minutes": 240, "lunch_minutes": 0},
    "Sunday":    {"type": "rest", "expected_minutes": 0}
  }
}
</pre>
        </div>
    </div>
    <?php
}

/**
 * Adicionar menu no admin
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'sistur',
        'Criar Escalas Semanais',
        'Escalas Semanais',
        'manage_options',
        'sistur-create-weekly-patterns',
        'sistur_create_weekly_patterns_page'
    );
}, 21);
