<?php
/**
 * Classe de gerenciamento de permissões
 *
 * @package SISTUR
 * @version 1.2.0
 */

class SISTUR_Permissions {

    /**
     * Instância única (Singleton)
     */
    private static $instance = null;

    /**
     * Cache de permissões
     */
    private $cached_permissions = array();

    /**
     * Cache de roles
     */
    private $cached_roles = array();

    /**
     * Construtor privado (Singleton)
     */
    private function __construct() {
        // Registrar handlers AJAX para gerenciamento de roles
        add_action('wp_ajax_sistur_get_roles', array($this, 'ajax_get_roles'));
        add_action('wp_ajax_sistur_get_role', array($this, 'ajax_get_role'));
        add_action('wp_ajax_sistur_save_role', array($this, 'ajax_save_role'));
        add_action('wp_ajax_sistur_delete_role', array($this, 'ajax_delete_role'));
        add_action('wp_ajax_sistur_get_permissions', array($this, 'ajax_get_permissions'));
        add_action('wp_ajax_sistur_get_role_permissions', array($this, 'ajax_get_role_permissions'));
    }

    /**
     * Retorna instância única
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Verifica se um funcionário tem determinada permissão
     *
     * @param int $employee_id ID do funcionário
     * @param string $module Módulo (ex: 'employees')
     * @param string $action Ação (ex: 'edit')
     * @return bool
     */
    public function can($employee_id, $module, $action) {
        global $wpdb;

        // Cache para evitar queries repetidas
        $cache_key = "{$employee_id}_{$module}_{$action}";
        if (isset($this->cached_permissions[$cache_key])) {
            return $this->cached_permissions[$cache_key];
        }

        // Buscar role do funcionário
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT role_id FROM {$wpdb->prefix}sistur_employees WHERE id = %d AND status = 1",
            $employee_id
        ));

        if (!$employee || !$employee->role_id) {
            $this->cached_permissions[$cache_key] = false;
            return false;
        }

        // Verificar se é admin (tem todas as permissões)
        $is_admin = $wpdb->get_var($wpdb->prepare(
            "SELECT is_admin FROM {$wpdb->prefix}sistur_roles WHERE id = %d",
            $employee->role_id
        ));

        if ($is_admin) {
            $this->cached_permissions[$cache_key] = true;
            return true;
        }

        // Verificar permissão específica
        $has_permission = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}sistur_role_permissions rp
            INNER JOIN {$wpdb->prefix}sistur_permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = %d
            AND p.module = %s
            AND p.action = %s
        ", $employee->role_id, $module, $action));

        $result = ($has_permission > 0);
        $this->cached_permissions[$cache_key] = $result;

        return $result;
    }

    /**
     * Verifica múltiplas permissões (OR logic)
     *
     * @param int $employee_id ID do funcionário
     * @param array $permissions Array de permissões no formato ['module.action', ...]
     * @return bool
     */
    public function can_any($employee_id, array $permissions) {
        foreach ($permissions as $perm) {
            $parts = explode('.', $perm);
            if (count($parts) !== 2) {
                continue;
            }

            list($module, $action) = $parts;
            if ($this->can($employee_id, $module, $action)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica múltiplas permissões (AND logic)
     *
     * @param int $employee_id ID do funcionário
     * @param array $permissions Array de permissões no formato ['module.action', ...]
     * @return bool
     */
    public function can_all($employee_id, array $permissions) {
        foreach ($permissions as $perm) {
            $parts = explode('.', $perm);
            if (count($parts) !== 2) {
                return false;
            }

            list($module, $action) = $parts;
            if (!$this->can($employee_id, $module, $action)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Retorna todas as permissões de um funcionário
     *
     * @param int $employee_id ID do funcionário
     * @return array
     */
    public function get_employee_permissions($employee_id) {
        global $wpdb;

        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT role_id FROM {$wpdb->prefix}sistur_employees WHERE id = %d",
            $employee_id
        ));

        if (!$employee || !$employee->role_id) {
            return array();
        }

        // Se for admin, retornar todas as permissões
        $is_admin = $wpdb->get_var($wpdb->prepare(
            "SELECT is_admin FROM {$wpdb->prefix}sistur_roles WHERE id = %d",
            $employee->role_id
        ));

        if ($is_admin) {
            return $wpdb->get_results(
                "SELECT module, action, label, description FROM {$wpdb->prefix}sistur_permissions ORDER BY module, action",
                ARRAY_A
            );
        }

        return $wpdb->get_results($wpdb->prepare("
            SELECT p.module, p.action, p.label, p.description
            FROM {$wpdb->prefix}sistur_role_permissions rp
            INNER JOIN {$wpdb->prefix}sistur_permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = %d
            ORDER BY p.module, p.action
        ", $employee->role_id), ARRAY_A);
    }

    /**
     * Retorna todos os papéis disponíveis
     *
     * @return array
     */
    public function get_all_roles() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}sistur_roles ORDER BY name",
            ARRAY_A
        );
    }

    /**
     * Retorna um papel específico
     *
     * @param int $role_id ID do papel
     * @return array|null
     */
    public function get_role($role_id) {
        global $wpdb;

        $cache_key = "role_{$role_id}";
        if (isset($this->cached_roles[$cache_key])) {
            return $this->cached_roles[$cache_key];
        }

        $role = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sistur_roles WHERE id = %d",
            $role_id
        ), ARRAY_A);

        $this->cached_roles[$cache_key] = $role;
        return $role;
    }

    /**
     * Retorna todas as permissões disponíveis
     *
     * @return array Agrupa permissões por módulo
     */
    public function get_all_permissions_grouped() {
        global $wpdb;

        $permissions = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}sistur_permissions ORDER BY module, action",
            ARRAY_A
        );

        $grouped = array();
        foreach ($permissions as $perm) {
            $module = $perm['module'];
            if (!isset($grouped[$module])) {
                $grouped[$module] = array(
                    'module_name' => ucfirst(str_replace('_', ' ', $module)),
                    'permissions' => array()
                );
            }
            $grouped[$module]['permissions'][] = $perm;
        }

        return $grouped;
    }

    /**
     * Retorna permissões de um papel
     *
     * @param int $role_id ID do papel
     * @return array
     */
    public function get_role_permissions($role_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT p.*
            FROM {$wpdb->prefix}sistur_role_permissions rp
            INNER JOIN {$wpdb->prefix}sistur_permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = %d
            ORDER BY p.module, p.action
        ", $role_id), ARRAY_A);
    }

    /**
     * Atribuir permissões a um papel
     *
     * @param int $role_id ID do papel
     * @param array $permission_ids Array de IDs de permissões
     * @return bool
     */
    public function assign_permissions_to_role($role_id, array $permission_ids) {
        global $wpdb;

        $table = $wpdb->prefix . 'sistur_role_permissions';

        // Remover permissões existentes
        $wpdb->delete($table, array('role_id' => $role_id));

        // Adicionar novas permissões
        foreach ($permission_ids as $permission_id) {
            $wpdb->insert($table, array(
                'role_id' => $role_id,
                'permission_id' => intval($permission_id)
            ), array('%d', '%d'));
        }

        // Limpar cache
        $this->clear_cache();

        return true;
    }

    /**
     * Criar um novo papel
     *
     * @param string $name Nome do papel
     * @param string $description Descrição
     * @param bool $is_admin Se é administrador
     * @return int|false ID do papel criado ou false em caso de erro
     */
    public function create_role($name, $description = '', $is_admin = false) {
        global $wpdb;

        $result = $wpdb->insert(
            $wpdb->prefix . 'sistur_roles',
            array(
                'name' => $name,
                'description' => $description,
                'is_admin' => $is_admin ? 1 : 0
            ),
            array('%s', '%s', '%d')
        );

        if ($result) {
            $this->clear_cache();
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Atualizar um papel
     *
     * @param int $role_id ID do papel
     * @param array $data Dados para atualizar
     * @return bool
     */
    public function update_role($role_id, array $data) {
        global $wpdb;

        $allowed_fields = array('name', 'description', 'is_admin');
        $update_data = array();
        $format = array();

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed_fields)) {
                $update_data[$key] = $value;
                $format[] = ($key === 'is_admin') ? '%d' : '%s';
            }
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'sistur_roles',
            $update_data,
            array('id' => $role_id),
            $format,
            array('%d')
        );

        if ($result !== false) {
            $this->clear_cache();
            return true;
        }

        return false;
    }

    /**
     * Excluir um papel
     *
     * @param int $role_id ID do papel
     * @return bool
     */
    public function delete_role($role_id) {
        global $wpdb;

        // Verificar se algum funcionário usa este papel
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sistur_employees WHERE role_id = %d",
            $role_id
        ));

        if ($count > 0) {
            return false; // Não pode excluir papel em uso
        }

        // Excluir permissões do papel
        $wpdb->delete(
            $wpdb->prefix . 'sistur_role_permissions',
            array('role_id' => $role_id),
            array('%d')
        );

        // Excluir papel
        $result = $wpdb->delete(
            $wpdb->prefix . 'sistur_roles',
            array('id' => $role_id),
            array('%d')
        );

        if ($result) {
            $this->clear_cache();
            return true;
        }

        return false;
    }

    /**
     * Atribuir papel a um funcionário
     *
     * @param int $employee_id ID do funcionário
     * @param int $role_id ID do papel
     * @return bool
     */
    public function assign_role_to_employee($employee_id, $role_id) {
        global $wpdb;

        $result = $wpdb->update(
            $wpdb->prefix . 'sistur_employees',
            array('role_id' => $role_id),
            array('id' => $employee_id),
            array('%d'),
            array('%d')
        );

        if ($result !== false) {
            $this->clear_cache();
            return true;
        }

        return false;
    }

    // ========== AJAX Handlers para gerenciamento de roles ==========

    /**
     * Verifica nonce aceitando múltiplos tipos para compatibilidade
     */
    private function verify_nonce() {
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_GET['nonce']) ? $_GET['nonce'] : '');
        $nonce_valid = wp_verify_nonce($nonce, 'sistur_nonce') || wp_verify_nonce($nonce, 'sistur_employees_nonce');

        if (!$nonce_valid) {
            wp_send_json_error('Falha na verificação de segurança');
            exit;
        }
    }

    /**
     * AJAX: Obter todos os roles
     */
    public function ajax_get_roles() {
        $this->verify_nonce();

        $roles = $this->get_all_roles();
        wp_send_json_success($roles);
    }

    /**
     * AJAX: Obter um role específico
     */
    public function ajax_get_role() {
        $this->verify_nonce();

        if (!isset($_POST['id'])) {
            wp_send_json_error('ID não fornecido');
        }

        $role = $this->get_role(intval($_POST['id']));

        if ($role) {
            wp_send_json_success($role);
        } else {
            wp_send_json_error('Role não encontrado');
        }
    }

    /**
     * AJAX: Salvar role (criar ou atualizar)
     */
    public function ajax_save_role() {
        $this->verify_nonce();
        
        error_log('SISTUR DEBUG ajax_save_role: POST data: ' . print_r($_POST, true));

        if (!isset($_POST['name']) || empty($_POST['name'])) {
            wp_send_json_error('Nome é obrigatório');
        }

        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : null,
            'department_id' => !empty($_POST['department_id']) ? intval($_POST['department_id']) : null,
            'is_admin' => isset($_POST['is_admin']) ? 1 : 0
        );

        if (isset($_POST['id']) && !empty($_POST['id'])) {
            $data['id'] = intval($_POST['id']);
        }

        $role_id = $this->save_role($data);

        if ($role_id) {
            // Salvar permissões se não for admin
            if (!$data['is_admin']) {
                $permissions = isset($_POST['permissions']) ? array_map('intval', $_POST['permissions']) : array();
                error_log('SISTUR DEBUG: Processando permissoes para role ID ' . $role_id . ': ' . print_r($permissions, true));
                $this->assign_permissions_to_role($role_id, $permissions);
            } else {
                 error_log('SISTUR DEBUG: Role é admin (ou is_admin setado), pulando permissoes.');
            }

            wp_send_json_success(array('id' => $role_id));
        } else {
            wp_send_json_error('Erro ao salvar role');
        }
    }

    /**
     * AJAX: Excluir role
     */
    public function ajax_delete_role() {
        $this->verify_nonce();

        if (!isset($_POST['id'])) {
            wp_send_json_error('ID não fornecido');
        }

        $result = $this->delete_role(intval($_POST['id']));

        if ($result === true) {
            wp_send_json_success('Role excluído com sucesso');
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Obter todas as permissões agrupadas por módulo
     */
    public function ajax_get_permissions() {
        $this->verify_nonce();

        $permissions = $this->get_all_permissions_grouped();
        wp_send_json_success($permissions);
    }

    /**
     * AJAX: Obter permissões de um role específico
     */
    public function ajax_get_role_permissions() {
        $this->verify_nonce();

        if (!isset($_POST['role_id'])) {
            wp_send_json_error('Role ID não fornecido');
        }

        $permissions = $this->get_role_permissions(intval($_POST['role_id']));
        wp_send_json_success($permissions);
    }

    /**
     * Salvar role (criar ou atualizar)
     */
    private function save_role($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_roles';

        $role_data = array(
            'name' => $data['name'],
            'description' => $data['description'],
            'department_id' => $data['department_id'],
            'is_admin' => $data['is_admin']
        );

        if (isset($data['id']) && !empty($data['id'])) {
            // Atualizar
            $wpdb->update(
                $table,
                $role_data,
                array('id' => $data['id']),
                array('%s', '%s', '%d', '%d'),
                array('%d')
            );
            $this->clear_cache();
            return $data['id'];
        } else {
            // Criar novo
            $wpdb->insert(
                $table,
                $role_data,
                array('%s', '%s', '%d', '%d')
            );
            $this->clear_cache();
            return $wpdb->insert_id;
        }
    }

    /**
     * Limpar cache de permissões
     */
    public function clear_cache() {
        $this->cached_permissions = array();
        $this->cached_roles = array();
    }

    /**
     * Verificar se funcionário é admin (helper)
     *
     * @param int $employee_id ID do funcionário
     * @return bool
     */
    public function is_admin($employee_id) {
        global $wpdb;

        $is_admin = $wpdb->get_var($wpdb->prepare("
            SELECT r.is_admin
            FROM {$wpdb->prefix}sistur_employees e
            INNER JOIN {$wpdb->prefix}sistur_roles r ON e.role_id = r.id
            WHERE e.id = %d AND e.status = 1
        ", $employee_id));

        return ($is_admin == 1);
    }
}

// Inicializar a classe
SISTUR_Permissions::get_instance();
