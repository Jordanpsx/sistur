<?php
/**
 * Classe de gerenciamento do Portal do Colaborador
 *
 * @package SISTUR
 * @since 2.0.0
 */

class SISTUR_Portal
{

    /**
     * Instância única da classe (Singleton)
     */
    private static $instance = null;

    /**
     * Construtor privado (Singleton)
     */
    private function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Retorna instância única da classe
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa os hooks
     */
    private function init_hooks()
    {
        // AJAX handlers
        add_action('wp_ajax_sistur_get_portal_modules', array($this, 'ajax_get_modules'));
        add_action('wp_ajax_nopriv_sistur_get_portal_modules', array($this, 'ajax_get_modules'));
    }

    /**
     * Obtém módulos disponíveis para um funcionário
     *
     * @param int $employee_id ID do funcionário
     * @return array Módulos que o funcionário tem permissão para acessar
     */
    public function get_modules_for_employee($employee_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_module_config';

        // Obter todos os módulos ativos
        $modules = $wpdb->get_results(
            "SELECT * FROM $table WHERE is_active = 1 ORDER BY sort_order ASC",
            ARRAY_A
        );

        if (empty($modules)) {
            return array();
        }

        $permissions = SISTUR_Permissions::get_instance();
        $available_modules = array();

        foreach ($modules as $module) {
            // Verificar se tem a permissão necessária
            $required = $module['required_permission'];

            if (empty($required)) {
                // Módulo sem permissão específica - disponível para todos
                $available_modules[] = $module;
                continue;
            }

            // Parsear permissão (formato: module.action)
            $parts = explode('.', $required);
            if (count($parts) === 2) {
                if ($permissions->can($employee_id, $parts[0], $parts[1])) {
                    $available_modules[] = $module;
                }
            }
        }

        return $available_modules;
    }

    /**
     * Obtém todos os módulos configurados
     *
     * @return array
     */
    public function get_all_modules()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_module_config';

        return $wpdb->get_results(
            "SELECT * FROM $table ORDER BY sort_order ASC",
            ARRAY_A
        ) ?: array();
    }

    /**
     * Obtém um módulo específico
     *
     * @param string $module_key
     * @return array|null
     */
    public function get_module($module_key)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_module_config';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE module_key = %s",
            $module_key
        ), ARRAY_A);
    }

    /**
     * Adiciona ou atualiza um módulo
     *
     * @param array $data Dados do módulo
     * @return int|false ID do módulo ou false em erro
     */
    public function save_module($data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_module_config';

        $module_key = sanitize_key($data['module_key']);
        $existing = $this->get_module($module_key);

        $module_data = array(
            'module_key' => $module_key,
            'module_name' => sanitize_text_field($data['module_name']),
            'module_icon' => sanitize_text_field($data['module_icon']),
            'module_color' => sanitize_hex_color($data['module_color']) ?: '#0d9488',
            'module_description' => sanitize_textarea_field($data['module_description'] ?? ''),
            'required_permission' => sanitize_text_field($data['required_permission']),
            'target_url' => esc_url_raw($data['target_url']),
            'target_type' => in_array($data['target_type'], array('tab', 'page', 'external')) ? $data['target_type'] : 'tab',
            'sort_order' => intval($data['sort_order'] ?? 0),
            'is_active' => intval($data['is_active'] ?? 1)
        );

        if ($existing) {
            $wpdb->update($table, $module_data, array('module_key' => $module_key));
            return $existing['id'];
        } else {
            $wpdb->insert($table, $module_data);
            return $wpdb->insert_id;
        }
    }

    /**
     * Remove um módulo
     *
     * @param string $module_key
     * @return bool
     */
    public function delete_module($module_key)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_module_config';

        return $wpdb->delete($table, array('module_key' => $module_key), array('%s')) !== false;
    }

    /**
     * Obtém dados do funcionário logado com role
     *
     * @return array|null
     */
    public function get_current_employee_with_role()
    {
        $session = SISTUR_Session::get_instance();

        if (!$session->is_employee_logged_in()) {
            return null;
        }

        $employee_data = $session->get_employee_data();
        $employee_id = $employee_data['id'];

        global $wpdb;
        $employees_table = $wpdb->prefix . 'sistur_employees';
        $roles_table = $wpdb->prefix . 'sistur_roles';

        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, r.name as role_name, r.is_admin, r.approval_level
             FROM $employees_table e
             LEFT JOIN $roles_table r ON e.role_id = r.id
             WHERE e.id = %d",
            $employee_id
        ), ARRAY_A);

        return $employee;
    }

    /**
     * Obtém estatísticas do dashboard para um funcionário
     *
     * @param int $employee_id
     * @return array
     */
    public function get_dashboard_stats($employee_id)
    {
        $approvals = SISTUR_Approvals::get_instance();
        $permissions = SISTUR_Permissions::get_instance();

        $stats = array(
            'pending_approvals' => 0,
            'my_pending_requests' => 0,
            'today_punches' => 0,
            'timebank_balance' => null
        );

        // Contagem de aprovações pendentes (se tiver permissão)
        if ($permissions->can($employee_id, 'approvals', 'approve')) {
            $stats['pending_approvals'] = $approvals->count_pending_for_approver($employee_id);
        }

        // Minhas solicitações pendentes
        $my_requests = $approvals->get_requests_by_employee($employee_id, 'pending');
        $stats['my_pending_requests'] = count($my_requests);

        // Registros de ponto de hoje
        global $wpdb;
        $time_entries_table = $wpdb->prefix . 'sistur_time_entries';
        $today = date('Y-m-d');

        $stats['today_punches'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $time_entries_table 
             WHERE employee_id = %d AND shift_date = %s",
            $employee_id,
            $today
        ));

        return $stats;
    }

    /**
     * Renderiza o HTML de um card de módulo
     *
     * @param array $module Dados do módulo
     * @param array $stats Estatísticas opcionais
     * @return string HTML do card
     */
    public function render_module_card($module, $stats = array())
    {
        $badge = '';

        // Adicionar badges para módulos específicos
        if ($module['module_key'] === 'aprovacoes' && !empty($stats['pending_approvals'])) {
            $badge = '<span class="module-badge">' . intval($stats['pending_approvals']) . '</span>';
        }

        $target_attr = $module['target_type'] === 'external' ? 'target="_blank"' : '';
        $data_tab = $module['target_type'] === 'tab' ? 'data-tab="' . esc_attr(ltrim($module['target_url'], '#')) . '"' : '';

        ob_start();
        ?>
        <a href="<?php echo esc_url($module['target_url']); ?>" class="portal-module-card"
            style="--module-color: <?php echo esc_attr($module['module_color']); ?>" <?php echo $target_attr; ?>         <?php echo $data_tab; ?>>
            <div class="module-card-icon">
                <span class="dashicons <?php echo esc_attr($module['module_icon']); ?>"></span>
                <?php echo $badge; ?>
            </div>
            <div class="module-card-content">
                <h3><?php echo esc_html($module['module_name']); ?></h3>
                <p><?php echo esc_html($module['module_description']); ?></p>
            </div>
            <div class="module-card-arrow">
                <span class="dashicons dashicons-arrow-right-alt2"></span>
            </div>
        </a>
        <?php
        return ob_get_clean();
    }

    // ==================== AJAX Handlers ====================

    /**
     * AJAX: Obter módulos disponíveis
     */
    public function ajax_get_modules()
    {
        $session = SISTUR_Session::get_instance();

        if (!$session->is_employee_logged_in()) {
            wp_send_json_error(array('message' => 'Não autenticado.'));
            return;
        }

        $employee_id = $session->get_employee_id();
        $modules = $this->get_modules_for_employee($employee_id);
        $stats = $this->get_dashboard_stats($employee_id);

        wp_send_json_success(array(
            'modules' => $modules,
            'stats' => $stats
        ));
    }
}
