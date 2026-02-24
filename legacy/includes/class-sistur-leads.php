<?php
/**
 * Classe de gerenciamento de leads
 *
 * @package SISTUR
 */

class SISTUR_Leads {

    /**
     * Instância única da classe (Singleton)
     */
    private static $instance = null;

    /**
     * Instância do banco de dados
     */
    private $db;

    /**
     * Construtor privado (Singleton)
     */
    private function __construct() {
        $this->db = new SISTUR_Leads_DB();
        $this->init_hooks();
    }

    /**
     * Retorna instância única da classe
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa os hooks
     */
    private function init_hooks() {
        // Integração com Elementor Forms
        add_action('elementor_pro/forms/new_record', array($this, 'handle_elementor_form_submission'), 10, 2);

        // AJAX handlers
        add_action('wp_ajax_sistur_get_leads', array($this, 'ajax_get_leads'));
        add_action('wp_ajax_sistur_update_lead_status', array($this, 'ajax_update_lead_status'));
        add_action('wp_ajax_sistur_delete_lead', array($this, 'ajax_delete_lead'));
        add_action('wp_ajax_sistur_get_leads_stats', array($this, 'ajax_get_leads_stats'));
    }

    /**
     * Handler para submissão de formulário Elementor
     */
    public function handle_elementor_form_submission($record, $handler) {
        // Obter campos do formulário
        $raw_fields = $record->get('fields');

        $fields = array();
        foreach ($raw_fields as $id => $field) {
            $fields[$id] = $field['value'];
        }

        // Mapear campos comuns
        $name = '';
        $email = '';
        $phone = '';

        // Tentar encontrar nome
        foreach ($fields as $key => $value) {
            $key_lower = strtolower($key);
            if (strpos($key_lower, 'name') !== false || strpos($key_lower, 'nome') !== false) {
                $name = $value;
                break;
            }
        }

        // Tentar encontrar email
        foreach ($fields as $key => $value) {
            $key_lower = strtolower($key);
            if (strpos($key_lower, 'email') !== false || strpos($key_lower, 'e-mail') !== false) {
                $email = $value;
                break;
            }
        }

        // Tentar encontrar telefone
        foreach ($fields as $key => $value) {
            $key_lower = strtolower($key);
            if (strpos($key_lower, 'phone') !== false || strpos($key_lower, 'telefone') !== false || strpos($key_lower, 'whatsapp') !== false) {
                $phone = $value;
                break;
            }
        }

        // Se não encontrou nome, usar o primeiro campo
        if (empty($name) && !empty($fields)) {
            $name = reset($fields);
        }

        // Inserir lead
        if (!empty($name) || !empty($email)) {
            $lead_data = array(
                'name' => sanitize_text_field($name),
                'email' => sanitize_email($email),
                'phone' => sanitize_text_field($phone),
                'source' => 'form_elementor',
                'status' => 'new',
                'notes' => 'Capturado via formulário Elementor'
            );

            $this->db->insert($lead_data);
        }
    }

    /**
     * AJAX: Obter leads
     */
    public function ajax_get_leads() {
        check_ajax_referer('sistur_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : null;
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
        $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

        $args = array(
            'status' => $status,
            'search' => $search,
            'limit' => $limit,
            'offset' => $offset
        );

        $leads = $this->db->get_leads($args);

        wp_send_json_success(array('leads' => $leads));
    }

    /**
     * AJAX: Atualizar status do lead
     */
    public function ajax_update_lead_status() {
        check_ajax_referer('sistur_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';

        if ($id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        if (!in_array($status, array('new', 'contacted'))) {
            wp_send_json_error(array('message' => 'Status inválido.'));
        }

        $result = $this->db->update_status($id, $status);

        if ($result === false) {
            wp_send_json_error(array('message' => 'Erro ao atualizar status.'));
        }

        wp_send_json_success(array('message' => 'Status atualizado com sucesso!'));
    }

    /**
     * AJAX: Deletar lead
     */
    public function ajax_delete_lead() {
        check_ajax_referer('sistur_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        $result = $this->db->delete($id);

        if ($result === false) {
            wp_send_json_error(array('message' => 'Erro ao excluir lead.'));
        }

        wp_send_json_success(array('message' => 'Lead excluído com sucesso!'));
    }

    /**
     * AJAX: Obter estatísticas de leads
     */
    public function ajax_get_leads_stats() {
        check_ajax_referer('sistur_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $stats = $this->db->get_count_by_status();

        // Adicionar leads de hoje
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_leads';
        $leads_today = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE DATE(created_at) = %s",
            current_time('Y-m-d')
        ));

        $stats['today'] = intval($leads_today);

        wp_send_json_success(array('stats' => $stats));
    }

    /**
     * Renderiza a página de leads
     */
    public static function render_leads_page() {
        include SISTUR_PLUGIN_DIR . 'admin/views/leads.php';
    }
}
