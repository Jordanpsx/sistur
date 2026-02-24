<?php
/**
 * Classe de gerenciamento de aprovações
 *
 * @package SISTUR
 * @since 2.0.0
 */

class SISTUR_Approvals {

    /**
     * Instância única da classe (Singleton)
     */
    private static $instance = null;

    /**
     * Construtor privado (Singleton)
     */
    private function __construct() {
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
        // AJAX handlers para aprovações (wp_ajax_ + wp_ajax_nopriv_ para sessão SISTUR)
        $ajax_actions = array(
            'sistur_create_approval_request' => 'ajax_create_request',
            'sistur_process_approval'        => 'ajax_process_approval',
            'sistur_get_pending_approvals'   => 'ajax_get_pending',
            'sistur_get_my_requests'         => 'ajax_get_my_requests',
            'sistur_cancel_request'          => 'ajax_cancel_request',
        );

        foreach ($ajax_actions as $action => $method) {
            add_action('wp_ajax_' . $action, array($this, $method));
            add_action('wp_ajax_nopriv_' . $action, array($this, $method));
        }
    }

    /**
     * Cria uma nova solicitação de aprovação
     *
     * @param string $module Módulo (ex: 'inventory')
     * @param string $action Ação (ex: 'stock_loss')
     * @param int $entity_id ID da entidade relacionada
     * @param array $data Dados da solicitação
     * @param int $employee_id ID do funcionário solicitante
     * @return int|false ID da solicitação ou false em caso de erro
     */
    public function create_request($module, $action, $entity_id, $data, $employee_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_approval_requests';

        // Determinar role necessário para aprovar
        $required_role_id = $this->get_required_approver_role($module, $action, $employee_id);

        $result = $wpdb->insert($table, array(
            'module' => $module,
            'action' => $action,
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => $entity_id,
            'request_data' => json_encode($data),
            'requested_by' => $employee_id,
            'status' => 'pending',
            'priority' => $data['priority'] ?? 'normal',
            'required_role_id' => $required_role_id
        ), array('%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d'));

        if ($result === false) {
            return false;
        }

        $request_id = $wpdb->insert_id;

        // Disparar ação para notificações
        do_action('sistur_approval_request_created', $request_id, $module, $action, $employee_id);

        return $request_id;
    }

    /**
     * Processa uma aprovação (aprovar ou rejeitar)
     *
     * @param int $request_id ID da solicitação
     * @param string $decision 'approved' ou 'rejected'
     * @param int $approver_id ID do funcionário aprovador
     * @param string $notes Notas do aprovador
     * @return bool
     */
    public function process_approval($request_id, $decision, $approver_id, $notes = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_approval_requests';

        error_log("SISTUR APPROVAL: process_approval called. Request: $request_id, Decision: $decision, Approver: $approver_id");

        // Verificar se a solicitação existe e está pendente
        $request = $this->get_request($request_id);
        if (!$request || $request['status'] !== 'pending') {
            error_log("SISTUR APPROVAL ERROR: Request not found or not pending. ID: $request_id, Status: " . ($request['status'] ?? 'NULL'));
            return false;
        }

        // Verificar se o aprovador tem permissão
        if (!$this->can_approve($approver_id, $request)) {
            error_log("SISTUR APPROVAL ERROR: can_approve returned false for approver $approver_id");
            return false;
        }

        // Atualizar status
        $result = $wpdb->update($table, array(
            'status' => $decision,
            'approved_by' => $approver_id,
            'approved_at' => current_time('mysql'),
            'approval_notes' => $notes
        ), array('id' => $request_id), array('%s', '%d', '%s', '%s'), array('%d'));

        if ($result === false) {
            error_log("SISTUR APPROVAL ERROR: DB update failed. Error: " . $wpdb->last_error);
            return false;
        }

        error_log("SISTUR APPROVAL: Status updated to $decision for request $request_id");

        // Disparar ação para processamento (se aprovado) ou notificação
        do_action('sistur_approval_processed', $request_id, $decision, $approver_id);

        // Se aprovado, executar a ação original
        if ($decision === 'approved') {
            error_log("SISTUR APPROVAL: Calling execute_approved_action for request $request_id");
            $this->execute_approved_action($request);
        }

        return true;
    }

    /**
     * Verifica se um funcionário pode aprovar uma solicitação
     *
     * @param int $employee_id ID do funcionário
     * @param array $request Dados da solicitação
     * @return bool
     */
    public function can_approve($employee_id, $request) {
        $permissions = SISTUR_Permissions::get_instance();

        // Verificar permissão geral de aprovar
        if (!$permissions->can($employee_id, 'approvals', 'approve')) {
            return false;
        }

        // Não pode aprovar próprias solicitações
        if ($request['requested_by'] == $employee_id) {
            return false;
        }

        // Verificar nível hierárquico
        global $wpdb;
        $roles_table = $wpdb->prefix . 'sistur_roles';
        $employees_table = $wpdb->prefix . 'sistur_employees';

        // Obter role do aprovador
        $approver_role = $wpdb->get_row($wpdb->prepare(
            "SELECT r.* FROM $roles_table r
             INNER JOIN $employees_table e ON e.role_id = r.id
             WHERE e.id = %d",
            $employee_id
        ), ARRAY_A);

        if (!$approver_role) {
            return false;
        }

        // Admin pode aprovar tudo
        if ($approver_role['is_admin']) {
            return true;
        }

        // Verificar se tem nível suficiente
        $requester_role = $wpdb->get_row($wpdb->prepare(
            "SELECT r.* FROM $roles_table r
             INNER JOIN $employees_table e ON e.role_id = r.id
             WHERE e.id = %d",
            $request['requested_by']
        ), ARRAY_A);

        if (!$requester_role) {
            return true; // Se solicitante não tem role, qualquer aprovador pode aprovar
        }

        return $approver_role['approval_level'] > $requester_role['approval_level'];
    }

    /**
     * Obtém uma solicitação pelo ID
     *
     * @param int $request_id
     * @return array|null
     */
    public function get_request($request_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_approval_requests';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $request_id
        ), ARRAY_A);
    }

    /**
     * Obtém solicitações pendentes para um aprovador
     *
     * @param int $approver_id ID do funcionário aprovador
     * @return array
     */
    public function get_pending_for_approver($approver_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_approval_requests';
        $employees_table = $wpdb->prefix . 'sistur_employees';

        $permissions = SISTUR_Permissions::get_instance();

        // Verificar se tem permissão de aprovar
        if (!$permissions->can($approver_id, 'approvals', 'approve')) {
            return array();
        }

        // Verificar se é admin
        $is_admin = $permissions->is_admin($approver_id);

        if ($is_admin) {
            // Admins veem TUDO (exceto as próprias)
            $requests = $wpdb->get_results($wpdb->prepare(
                "SELECT ar.*, e.name as requester_name, r.name as requester_role_name, r.approval_level as requester_level
                 FROM $table ar
                 INNER JOIN $employees_table e ON ar.requested_by = e.id
                 LEFT JOIN {$wpdb->prefix}sistur_roles r ON e.role_id = r.id
                 WHERE ar.status = 'pending'
                 AND ar.requested_by != %d
                 ORDER BY ar.priority DESC, ar.requested_at ASC",
                $approver_id
            ), ARRAY_A) ?: array();
        } else {
            // Não-admins veem apenas de nível inferior
            $approver_level = $this->get_employee_approval_level($approver_id);

            $requests = $wpdb->get_results($wpdb->prepare(
                "SELECT ar.*, e.name as requester_name, r.name as requester_role_name, r.approval_level as requester_level
                 FROM $table ar
                 INNER JOIN $employees_table e ON ar.requested_by = e.id
                 LEFT JOIN {$wpdb->prefix}sistur_roles r ON e.role_id = r.id
                 WHERE ar.status = 'pending'
                 AND ar.requested_by != %d
                 AND (r.approval_level IS NULL OR r.approval_level < %d)
                 ORDER BY ar.priority DESC, ar.requested_at ASC",
                $approver_id,
                $approver_level
            ), ARRAY_A) ?: array();
        }

        // Parsear request_data para facilitar exibição
        foreach ($requests as &$row) {
            $row['parsed_data'] = json_decode($row['request_data'] ?? '{}', true) ?: array();
        }

        return $requests;
    }

    /**
     * Obtém solicitações feitas por um funcionário
     *
     * @param int $employee_id
     * @param string|null $status Filtrar por status (opcional)
     * @param int $limit Limite de resultados (padrão 50)
     * @return array
     */
    public function get_requests_by_employee($employee_id, $status = null, $limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_approval_requests';
        $employees_table = $wpdb->prefix . 'sistur_employees';

        $where = "ar.requested_by = %d";
        $params = array($employee_id);

        if ($status && is_string($status)) {
            $where .= " AND ar.status = %s";
            $params[] = $status;
        }

        $limit = intval($limit);
        if ($limit <= 0) $limit = 50;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT ar.*, e.name as approver_name
             FROM $table ar
             LEFT JOIN $employees_table e ON ar.approved_by = e.id
             WHERE $where
             ORDER BY ar.requested_at DESC
             LIMIT $limit",
            $params
        ), ARRAY_A) ?: array();

        // Parsear request_data para facilitar exibição
        foreach ($results as &$row) {
            $row['parsed_data'] = json_decode($row['request_data'] ?? '{}', true) ?: array();
        }

        return $results;
    }

    /**
     * Obtém o nível de aprovação de um funcionário
     *
     * @param int $employee_id
     * @return int
     */
    private function get_employee_approval_level($employee_id) {
        global $wpdb;
        $employees_table = $wpdb->prefix . 'sistur_employees';
        $roles_table = $wpdb->prefix . 'sistur_roles';

        $level = $wpdb->get_var($wpdb->prepare(
            "SELECT r.approval_level FROM $roles_table r
             INNER JOIN $employees_table e ON e.role_id = r.id
             WHERE e.id = %d",
            $employee_id
        ));

        return $level !== null ? (int) $level : 0;
    }

    /**
     * Determina o role necessário para aprovar uma solicitação
     *
     * @param string $module
     * @param string $action
     * @param int $employee_id ID do solicitante
     * @return int|null
     */
    private function get_required_approver_role($module, $action, $employee_id) {
        global $wpdb;
        $roles_table = $wpdb->prefix . 'sistur_roles';

        // Por padrão, precisa de um nível acima do solicitante
        $requester_level = $this->get_employee_approval_level($employee_id);

        // Buscar role com nível imediatamente superior
        $role_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $roles_table 
             WHERE approval_level > %d AND is_admin = 0
             ORDER BY approval_level ASC
             LIMIT 1",
            $requester_level
        ));

        return $role_id ? (int) $role_id : null;
    }

    /**
     * Executa a ação original após aprovação
     *
     * @param array $request Dados da solicitação
     */
    private function execute_approved_action($request) {
        $data = json_decode($request['request_data'], true);

        switch ($request['module']) {
            case 'inventory':
                $this->execute_inventory_action($request['action'], $data, $request);
                break;
            
            // Adicionar outros módulos conforme necessário
            default:
                do_action("sistur_execute_approved_{$request['module']}_{$request['action']}", $data, $request);
                break;
        }
    }

    /**
     * Executa ações de inventário aprovadas
     *
     * Usa o sistema de lotes (FIFO) do SISTUR_Stock_API para garantir
     * consistência com o restante do sistema de estoque.
     *
     * @param string $action
     * @param array $data
     * @param array $request
     */
    private function execute_inventory_action($action, $data, $request) {
        global $wpdb;

        error_log("SISTUR APPROVAL: execute_inventory_action called. Action: $action");
        error_log("SISTUR APPROVAL: Data: " . print_r($data, true));

        if ($action === 'stock_loss') {
            $prefix = $wpdb->prefix . 'sistur_';
            $product_id = intval($data['product_id']);
            $quantity = floatval($data['quantity']);
            $reason = $data['reason'] ?? 'loss';
            $location_id = isset($data['location_id']) ? intval($data['location_id']) : null;

            // Buscar lotes disponíveis via FIFO (mais antigos primeiro)
            $where_location = $location_id ? $wpdb->prepare(" AND location_id = %d", $location_id) : "";

            $batches = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$prefix}inventory_batches 
                 WHERE product_id = %d 
                 AND status = 'active' 
                 AND quantity > 0
                 {$where_location}
                 ORDER BY created_at ASC",
                $product_id
            ));

            // Calcular saldo disponível
            $available = 0;
            foreach ($batches as $batch) {
                $available += floatval($batch->quantity);
            }

            if ($available < $quantity) {
                error_log("SISTUR APPROVAL ERROR: Insufficient stock. Available: $available, Requested: $quantity");
                // Mesmo com estoque insuficiente, registramos o que temos
                $quantity = $available;
                if ($quantity <= 0) {
                    error_log("SISTUR APPROVAL ERROR: No stock available at all. Aborting.");
                    return;
                }
            }

            // Iniciar transação
            $wpdb->query('START TRANSACTION');

            try {
                $remaining = $quantity;

                foreach ($batches as $batch) {
                    if ($remaining <= 0) break;

                    $to_consume = min(floatval($batch->quantity), $remaining);
                    $new_quantity = floatval($batch->quantity) - $to_consume;

                    // Atualizar lote
                    $update_data = array('quantity' => $new_quantity);
                    if ($new_quantity <= 0) {
                        $update_data['status'] = 'depleted';
                    }

                    $wpdb->update(
                        $prefix . 'inventory_batches',
                        $update_data,
                        array('id' => $batch->id)
                    );

                    // Registrar transação no livro razão (append-only)
                    $wpdb->insert(
                        $prefix . 'inventory_transactions',
                        array(
                            'product_id'      => $product_id,
                            'batch_id'        => $batch->id,
                            'user_id'         => get_current_user_id() ?: null,
                            'type'            => 'LOSS',
                            'quantity'        => -$to_consume,
                            'unit_cost'       => $batch->cost_price,
                            'total_cost'      => $to_consume * floatval($batch->cost_price),
                            'reason'          => 'Aprovação #' . $request['id'] . ' - ' . ($data['notes'] ?? $reason),
                            'to_location_id'  => $location_id,
                            'ip_address'      => $_SERVER['REMOTE_ADDR'] ?? null,
                        ),
                        array('%d', '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%d', '%s')
                    );

                    error_log("SISTUR APPROVAL: Consumed $to_consume from batch {$batch->id}. Remaining in batch: $new_quantity");

                    $remaining -= $to_consume;
                }

                // Atualizar cached_stock no produto (soma de lotes ativos)
                $total_stock = $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(quantity), 0) 
                     FROM {$prefix}inventory_batches 
                     WHERE product_id = %d AND status = 'active'",
                    $product_id
                ));

                $wpdb->update(
                    $prefix . 'products',
                    array('cached_stock' => $total_stock),
                    array('id' => $product_id)
                );

                // Registrar perda na tabela de losses para CMV
                $wpdb->insert(
                    $prefix . 'inventory_losses',
                    array(
                        'product_id'       => $product_id,
                        'transaction_id'   => $wpdb->insert_id,
                        'quantity'         => $quantity,
                        'reason'           => $this->map_reason_to_loss_type($reason),
                        'reason_details'   => 'Aprovação #' . $request['id'] . ' - ' . ($data['notes'] ?? ''),
                        'cost_at_time'     => $data['unit_price'] ?? 0,
                        'total_loss_value' => $quantity * ($data['unit_price'] ?? 0),
                        'user_id'          => get_current_user_id() ?: null,
                        'ip_address'       => $_SERVER['REMOTE_ADDR'] ?? null,
                    ),
                    array('%d', '%d', '%f', '%s', '%s', '%f', '%f', '%d', '%s')
                );

                $wpdb->query('COMMIT');

                error_log("SISTUR APPROVAL: Stock loss executed successfully. Product: $product_id, Qty: $quantity, New cached_stock: $total_stock");

            } catch (\Exception $e) {
                $wpdb->query('ROLLBACK');
                error_log("SISTUR APPROVAL ERROR: Exception during stock loss: " . $e->getMessage());
            }
        }
    }

    /**
     * Mapeia o motivo da perda para o tipo de perda do CMV
     *
     * @param string $reason
     * @return string
     */
    private function map_reason_to_loss_type($reason) {
        // Mapeia motivos do formulário para categorias da tabela inventory_losses
        // enum: 'expired','production_error','damaged','inventory_divergence','other'
        $map = array(
            'loss'     => 'other',
            'damage'   => 'damaged',
            'theft'    => 'other',
            'donation' => 'other',
            'sample'   => 'other',
            'expired'  => 'expired',
        );
        return $map[$reason] ?? 'other';
    }

    /**
     * Conta solicitações pendentes para um aprovador
     *
     * @param int $approver_id
     * @return int
     */
    public function count_pending_for_approver($approver_id) {
        return count($this->get_pending_for_approver($approver_id));
    }

    // ==================== AJAX Handlers ====================

    /**
     * AJAX: Criar nova solicitação
     */
    public function ajax_create_request() {
        check_ajax_referer('sistur_portal_nonce', 'nonce');

        $session = SISTUR_Session::get_instance();
        if (!$session->is_employee_logged_in()) {
            wp_send_json_error(array('message' => 'Não autenticado.'));
            return;
        }

        $employee_id = $session->get_employee_id();
        $module = sanitize_text_field($_POST['module'] ?? '');
        $action = sanitize_text_field($_POST['action'] ?? '');
        $entity_id = intval($_POST['entity_id'] ?? 0);
        $data = $_POST['data'] ?? array();

        if (empty($module) || empty($action)) {
            wp_send_json_error(array('message' => 'Módulo e ação são obrigatórios.'));
            return;
        }

        $request_id = $this->create_request($module, $action, $entity_id, $data, $employee_id);

        if ($request_id) {
            wp_send_json_success(array(
                'message' => 'Solicitação enviada para aprovação!',
                'request_id' => $request_id
            ));
        } else {
            wp_send_json_error(array('message' => 'Erro ao criar solicitação.'));
        }
    }

    /**
     * AJAX: Processar aprovação
     */
    public function ajax_process_approval() {
        check_ajax_referer('sistur_portal_nonce', 'nonce');

        $session = SISTUR_Session::get_instance();
        if (!$session->is_employee_logged_in()) {
            wp_send_json_error(array('message' => 'Não autenticado.'));
            return;
        }

        $approver_id = $session->get_employee_id();
        $request_id = intval($_POST['request_id'] ?? 0);
        $decision = sanitize_text_field($_POST['decision'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        if (!in_array($decision, array('approved', 'rejected'))) {
            wp_send_json_error(array('message' => 'Decisão inválida.'));
            return;
        }

        $result = $this->process_approval($request_id, $decision, $approver_id, $notes);

        if ($result) {
            wp_send_json_success(array(
                'message' => $decision === 'approved' ? 'Solicitação aprovada!' : 'Solicitação rejeitada.'
            ));
        } else {
            wp_send_json_error(array('message' => 'Erro ao processar aprovação.'));
        }
    }

    /**
     * AJAX: Obter solicitações pendentes
     */
    public function ajax_get_pending() {
        check_ajax_referer('sistur_portal_nonce', 'nonce');

        $session = SISTUR_Session::get_instance();
        if (!$session->is_employee_logged_in()) {
            wp_send_json_error(array('message' => 'Não autenticado.'));
            return;
        }

        $employee_id = $session->get_employee_id();
        $requests = $this->get_pending_for_approver($employee_id);

        wp_send_json_success(array('requests' => $requests));
    }

    /**
     * AJAX: Obter minhas solicitações
     */
    public function ajax_get_my_requests() {
        check_ajax_referer('sistur_portal_nonce', 'nonce');

        $session = SISTUR_Session::get_instance();
        if (!$session->is_employee_logged_in()) {
            wp_send_json_error(array('message' => 'Não autenticado.'));
            return;
        }

        $employee_id = $session->get_employee_id();
        $status = sanitize_text_field($_GET['status'] ?? null);
        $requests = $this->get_requests_by_employee($employee_id, $status);

        wp_send_json_success(array('requests' => $requests));
    }

    /**
     * AJAX: Cancelar solicitação
     */
    public function ajax_cancel_request() {
        check_ajax_referer('sistur_portal_nonce', 'nonce');

        $session = SISTUR_Session::get_instance();
        if (!$session->is_employee_logged_in()) {
            wp_send_json_error(array('message' => 'Não autenticado.'));
            return;
        }

        $employee_id = $session->get_employee_id();
        $request_id = intval($_POST['request_id'] ?? 0);

        $request = $this->get_request($request_id);

        if (!$request) {
            wp_send_json_error(array('message' => 'Solicitação não encontrada.'));
            return;
        }

        // Só pode cancelar próprias solicitações pendentes
        if ($request['requested_by'] != $employee_id || $request['status'] !== 'pending') {
            wp_send_json_error(array('message' => 'Não é possível cancelar esta solicitação.'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_approval_requests';

        $wpdb->update($table, 
            array('status' => 'cancelled'),
            array('id' => $request_id),
            array('%s'),
            array('%d')
        );

        wp_send_json_success(array('message' => 'Solicitação cancelada.'));
    }
}
