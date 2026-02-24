<?php
/**
 * Classe para gerenciar localizações autorizadas para registro de ponto
 *
 * @package SISTUR
 */

class SISTUR_Authorized_Locations {

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
        add_action('wp_ajax_sistur_save_location', array($this, 'ajax_save_location'));
        add_action('wp_ajax_sistur_delete_location', array($this, 'ajax_delete_location'));
        add_action('wp_ajax_sistur_get_locations', array($this, 'ajax_get_locations'));
        add_action('wp_ajax_sistur_validate_location', array($this, 'ajax_validate_location'));

        // Hooks AJAX públicos (para funcionários registrarem ponto)
        add_action('wp_ajax_nopriv_sistur_validate_location', array($this, 'ajax_validate_location'));
    }

    /**
     * Verifica se a tabela existe e cria se necessário
     */
    private function ensure_table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_authorized_locations';

        // Verificar se a tabela existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;

        if (!$table_exists) {
            $this->create_table();
        }
    }

    /**
     * Cria a tabela de localizações autorizadas
     */
    private function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_authorized_locations';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            location_name varchar(255) NOT NULL,
            latitude decimal(10,8) NOT NULL,
            longitude decimal(11,8) NOT NULL,
            radius_meters int DEFAULT 100,
            address varchar(500) DEFAULT NULL,
            description text DEFAULT NULL,
            status tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY coordinates (latitude, longitude)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        error_log('SISTUR: Tabela de localizações autorizadas criada automaticamente');
    }

    /**
     * Salva ou atualiza uma localização
     */
    public function ajax_save_location() {
        check_ajax_referer('sistur_location_nonce', 'nonce');

        // Verificar permissão
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada'));
            return;
        }

        $location_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $location_name = isset($_POST['location_name']) ? sanitize_text_field($_POST['location_name']) : '';
        $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : null;
        $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;
        $radius_meters = isset($_POST['radius_meters']) ? intval($_POST['radius_meters']) : 100;
        $address = isset($_POST['address']) ? sanitize_text_field($_POST['address']) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
        $status = isset($_POST['status']) ? intval($_POST['status']) : 1;

        if (empty($location_name) || $latitude === null || $longitude === null) {
            wp_send_json_error(array('message' => 'Nome e coordenadas são obrigatórios'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_authorized_locations';

        $data = array(
            'location_name' => $location_name,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius_meters' => $radius_meters,
            'address' => $address,
            'description' => $description,
            'status' => $status
        );

        $format = array('%s', '%f', '%f', '%d', '%s', '%s', '%d');

        if ($location_id > 0) {
            // Atualizar
            $result = $wpdb->update($table, $data, array('id' => $location_id), $format, array('%d'));

            if ($result !== false) {
                wp_send_json_success(array(
                    'message' => 'Localização atualizada com sucesso',
                    'id' => $location_id
                ));
            } else {
                wp_send_json_error(array('message' => 'Erro ao atualizar localização'));
            }
        } else {
            // Inserir
            $result = $wpdb->insert($table, $data, $format);

            if ($result) {
                wp_send_json_success(array(
                    'message' => 'Localização adicionada com sucesso',
                    'id' => $wpdb->insert_id
                ));
            } else {
                wp_send_json_error(array('message' => 'Erro ao adicionar localização'));
            }
        }
    }

    /**
     * Deleta uma localização
     */
    public function ajax_delete_location() {
        check_ajax_referer('sistur_location_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada'));
            return;
        }

        $location_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($location_id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_authorized_locations';

        $result = $wpdb->delete($table, array('id' => $location_id), array('%d'));

        if ($result) {
            wp_send_json_success(array('message' => 'Localização deletada com sucesso'));
        } else {
            wp_send_json_error(array('message' => 'Erro ao deletar localização'));
        }
    }

    /**
     * Obtém lista de localizações
     */
    public function ajax_get_locations() {
        check_ajax_referer('sistur_location_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_authorized_locations';

        $locations = $wpdb->get_results("SELECT * FROM $table ORDER BY location_name ASC", ARRAY_A);

        wp_send_json_success(array('locations' => $locations));
    }

    /**
     * Valida se a localização está dentro de uma área autorizada
     */
    public function ajax_validate_location() {
        $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : null;
        $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;

        if ($latitude === null || $longitude === null) {
            wp_send_json_error(array('message' => 'Localização não fornecida'));
            return;
        }

        $is_authorized = $this->is_location_authorized($latitude, $longitude);

        if ($is_authorized['authorized']) {
            wp_send_json_success(array(
                'authorized' => true,
                'message' => 'Localização autorizada',
                'location' => $is_authorized['location']
            ));
        } else {
            wp_send_json_error(array(
                'authorized' => false,
                'message' => 'Você não está em uma localização autorizada para registrar o ponto.'
            ));
        }
    }

    /**
     * Verifica se uma localização está dentro de uma área autorizada
     *
     * @param float $latitude Latitude do usuário
     * @param float $longitude Longitude do usuário
     * @return array ['authorized' => bool, 'location' => array|null]
     */
    public function is_location_authorized($latitude, $longitude) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_authorized_locations';

        // Buscar todas as localizações ativas
        $locations = $wpdb->get_results(
            "SELECT * FROM $table WHERE status = 1 ORDER BY location_name ASC",
            ARRAY_A
        );

        foreach ($locations as $location) {
            $distance = $this->calculate_distance(
                $latitude,
                $longitude,
                floatval($location['latitude']),
                floatval($location['longitude'])
            );

            $radius_meters = intval($location['radius_meters']);

            // Se a distância é menor que o raio permitido, está autorizado
            if ($distance <= $radius_meters) {
                return array(
                    'authorized' => true,
                    'location' => $location
                );
            }
        }

        return array(
            'authorized' => false,
            'location' => null
        );
    }

    /**
     * Calcula a distância entre dois pontos geográficos usando a fórmula de Haversine
     *
     * @param float $lat1 Latitude do ponto 1
     * @param float $lon1 Longitude do ponto 1
     * @param float $lat2 Latitude do ponto 2
     * @param float $lon2 Longitude do ponto 2
     * @return float Distância em metros
     */
    private function calculate_distance($lat1, $lon1, $lat2, $lon2) {
        $earth_radius = 6371000; // Raio da Terra em metros

        $lat1_rad = deg2rad($lat1);
        $lat2_rad = deg2rad($lat2);
        $delta_lat = deg2rad($lat2 - $lat1);
        $delta_lon = deg2rad($lon2 - $lon1);

        $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
             cos($lat1_rad) * cos($lat2_rad) *
             sin($delta_lon / 2) * sin($delta_lon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earth_radius * $c;
    }

    /**
     * Obtém todas as localizações autorizadas ativas
     *
     * @return array
     */
    public function get_authorized_locations() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_authorized_locations';

        return $wpdb->get_results(
            "SELECT * FROM $table WHERE status = 1 ORDER BY location_name ASC",
            ARRAY_A
        );
    }

    /**
     * Verifica se há alguma localização autorizada cadastrada
     *
     * @return bool
     */
    public function has_authorized_locations() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_authorized_locations';

        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 1");

        return $count > 0;
    }

    /**
     * Obtém configuração de validação por localização
     *
     * @return bool Se a validação por localização está habilitada
     */
    public function is_location_validation_enabled() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_settings';

        $enabled = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT setting_value FROM $table WHERE setting_key = %s",
                'location_validation_enabled'
            )
        );

        // Se não existe a configuração, assume como habilitado
        return $enabled === null || $enabled === '1';
    }
}
