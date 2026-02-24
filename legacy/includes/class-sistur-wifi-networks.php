<?php
/**
 * Classe para gerenciar redes Wi-Fi autorizadas
 *
 * @package SISTUR
 */

class SISTUR_WiFi_Networks {

    /**
     * Instância singleton
     */
    private static $instance = null;

    /**
     * Obtém a instância singleton
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Construtor
     */
    private function __construct() {
        // Verificar e criar tabela se necessário
        $this->ensure_table_exists();

        // Registrar hooks AJAX
        add_action('wp_ajax_sistur_save_wifi_network', array($this, 'ajax_save_wifi_network'));
        add_action('wp_ajax_sistur_delete_wifi_network', array($this, 'ajax_delete_wifi_network'));
        add_action('wp_ajax_sistur_get_wifi_networks', array($this, 'ajax_get_wifi_networks'));
        add_action('wp_ajax_sistur_validate_wifi_network', array($this, 'ajax_validate_wifi_network'));

        // Hook AJAX público (para funcionários registrarem ponto)
        add_action('wp_ajax_nopriv_sistur_validate_wifi_network', array($this, 'ajax_validate_wifi_network'));
    }

    /**
     * Verifica se a tabela existe e cria se necessário
     */
    private function ensure_table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_wifi_networks';

        // Verificar se a tabela existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;

        if (!$table_exists) {
            $this->create_table();
        }
    }

    /**
     * Cria a tabela de redes Wi-Fi autorizadas
     */
    private function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_wifi_networks';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            network_name varchar(255) NOT NULL,
            network_ssid varchar(255) NOT NULL,
            network_bssid varchar(17) DEFAULT NULL,
            description text DEFAULT NULL,
            status tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY network_ssid (network_ssid),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        error_log('SISTUR: Tabela de redes Wi-Fi autorizadas criada automaticamente');
    }

    /**
     * Salva ou atualiza uma rede Wi-Fi
     */
    public function ajax_save_wifi_network() {
        check_ajax_referer('sistur_wifi_nonce', 'nonce');

        // Verificar permissão
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada'));
            return;
        }

        $network_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $network_name = isset($_POST['network_name']) ? sanitize_text_field($_POST['network_name']) : '';
        $network_ssid = isset($_POST['network_ssid']) ? sanitize_text_field($_POST['network_ssid']) : '';
        $network_bssid = isset($_POST['network_bssid']) ? sanitize_text_field($_POST['network_bssid']) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
        $status = isset($_POST['status']) ? intval($_POST['status']) : 1;

        if (empty($network_name) || empty($network_ssid)) {
            wp_send_json_error(array('message' => 'Nome e SSID são obrigatórios'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_wifi_networks';

        $data = array(
            'network_name' => $network_name,
            'network_ssid' => $network_ssid,
            'network_bssid' => $network_bssid,
            'description' => $description,
            'status' => $status
        );

        $format = array('%s', '%s', '%s', '%s', '%d');

        if ($network_id > 0) {
            // Atualizar
            $result = $wpdb->update($table, $data, array('id' => $network_id), $format, array('%d'));

            if ($result !== false) {
                wp_send_json_success(array(
                    'message' => 'Rede Wi-Fi atualizada com sucesso',
                    'id' => $network_id
                ));
            } else {
                wp_send_json_error(array('message' => 'Erro ao atualizar rede Wi-Fi'));
            }
        } else {
            // Inserir
            $result = $wpdb->insert($table, $data, $format);

            if ($result) {
                wp_send_json_success(array(
                    'message' => 'Rede Wi-Fi adicionada com sucesso',
                    'id' => $wpdb->insert_id
                ));
            } else {
                wp_send_json_error(array('message' => 'Erro ao adicionar rede Wi-Fi'));
            }
        }
    }

    /**
     * Deleta uma rede Wi-Fi
     */
    public function ajax_delete_wifi_network() {
        check_ajax_referer('sistur_wifi_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada'));
            return;
        }

        $network_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($network_id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_wifi_networks';

        $result = $wpdb->delete($table, array('id' => $network_id), array('%d'));

        if ($result) {
            wp_send_json_success(array('message' => 'Rede Wi-Fi deletada com sucesso'));
        } else {
            wp_send_json_error(array('message' => 'Erro ao deletar rede Wi-Fi'));
        }
    }

    /**
     * Obtém lista de redes Wi-Fi
     */
    public function ajax_get_wifi_networks() {
        check_ajax_referer('sistur_wifi_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_wifi_networks';

        $networks = $wpdb->get_results("SELECT * FROM $table ORDER BY network_name ASC", ARRAY_A);

        wp_send_json_success(array('networks' => $networks));
    }

    /**
     * Valida se uma rede Wi-Fi está autorizada
     */
    public function ajax_validate_wifi_network() {
        // Para funcionários, não precisa de nonce pois é uma validação pública
        $ssid = isset($_POST['ssid']) ? sanitize_text_field($_POST['ssid']) : '';
        $bssid = isset($_POST['bssid']) ? sanitize_text_field($_POST['bssid']) : '';

        if (empty($ssid)) {
            wp_send_json_error(array('message' => 'SSID não fornecido'));
            return;
        }

        $is_authorized = $this->is_network_authorized($ssid, $bssid);

        if ($is_authorized) {
            wp_send_json_success(array(
                'authorized' => true,
                'message' => 'Rede autorizada'
            ));
        } else {
            wp_send_json_error(array(
                'authorized' => false,
                'message' => 'Rede não autorizada. Você precisa estar conectado a uma rede Wi-Fi autorizada para registrar o ponto.'
            ));
        }
    }

    /**
     * Verifica se uma rede está autorizada
     *
     * @param string $ssid SSID da rede
     * @param string $bssid BSSID da rede (opcional)
     * @return bool
     */
    public function is_network_authorized($ssid, $bssid = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_wifi_networks';

        // Buscar por SSID e status ativo
        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE network_ssid = %s AND status = 1",
            $ssid
        );

        $count = $wpdb->get_var($query);

        return $count > 0;
    }

    /**
     * Obtém todas as redes autorizadas ativas
     *
     * @return array
     */
    public function get_authorized_networks() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_wifi_networks';

        return $wpdb->get_results(
            "SELECT * FROM $table WHERE status = 1 ORDER BY network_name ASC",
            ARRAY_A
        );
    }

    /**
     * Verifica se há alguma rede autorizada cadastrada
     *
     * @return bool
     */
    public function has_authorized_networks() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_wifi_networks';

        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 1");

        return $count > 0;
    }

    /**
     * Obtém configuração de validação Wi-Fi
     *
     * @return bool Se a validação Wi-Fi está habilitada
     */
    public function is_wifi_validation_enabled() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_settings';

        $enabled = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT setting_value FROM $table WHERE setting_key = %s",
                'wifi_validation_enabled'
            )
        );

        // Se não existe a configuração, assume como habilitado
        return $enabled === null || $enabled === '1';
    }
}
