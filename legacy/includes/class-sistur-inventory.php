<?php
/**
 * Classe de gerenciamento de inventário
 *
 * @package SISTUR
 */

class SISTUR_Inventory
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
        error_log('🚀 [DEBUG] SISTUR_Inventory::init_hooks() called');

        // AJAX handlers para produtos
        add_action('wp_ajax_sistur_save_product', array($this, 'ajax_save_product'));
        add_action('wp_ajax_sistur_delete_product', array($this, 'ajax_delete_product'));
        add_action('wp_ajax_sistur_get_product', array($this, 'ajax_get_product'));
        add_action('wp_ajax_sistur_get_products', array($this, 'ajax_get_products'));

        // AJAX handlers para categorias
        add_action('wp_ajax_sistur_save_category', array($this, 'ajax_save_category'));
        add_action('wp_ajax_sistur_delete_category', array($this, 'ajax_delete_category'));
        add_action('wp_ajax_sistur_get_categories', array($this, 'ajax_get_categories'));

        // AJAX handlers para movimentações
        add_action('wp_ajax_sistur_save_movement', array($this, 'ajax_save_movement'));
        add_action('wp_ajax_nopriv_sistur_save_movement', array($this, 'ajax_save_movement')); // Portal do Colaborador (SISTUR_Session)
        error_log('✅ [DEBUG] Registered wp_ajax_sistur_save_movement');
        add_action('wp_ajax_sistur_save_bulk_movement', array($this, 'ajax_save_bulk_movement'));
        add_action('wp_ajax_nopriv_sistur_save_bulk_movement', array($this, 'ajax_save_bulk_movement')); // Portal do Colaborador
        add_action('wp_ajax_sistur_get_movements', array($this, 'ajax_get_movements'));

        // AJAX handlers para Portal (vendas e baixas com aprovação)
        add_action('wp_ajax_sistur_record_sale', array($this, 'ajax_record_sale'));
        add_action('wp_ajax_nopriv_sistur_record_sale', array($this, 'ajax_record_sale')); // Portal do Colaborador (SISTUR_Session)
        add_action('wp_ajax_sistur_submit_stock_loss', array($this, 'ajax_request_loss'));
        add_action('wp_ajax_nopriv_sistur_submit_stock_loss', array($this, 'ajax_request_loss'));

        // AJAX handlers para CMV Module
        add_action('wp_ajax_sistur_cmv_save_product', array($this, 'ajax_cmv_save_product'));
        add_action('wp_ajax_nopriv_sistur_cmv_save_product', array($this, 'ajax_cmv_save_product')); // Permite acesso via sessão
        add_action('wp_ajax_sistur_cmv_produce', array($this, 'ajax_cmv_produce'));
        add_action('wp_ajax_nopriv_sistur_cmv_produce', array($this, 'ajax_cmv_produce')); // Permite acesso via sessão

        // AJAX handler para Stock by Location
        add_action('wp_ajax_sistur_get_stock_by_location', array($this, 'ajax_get_stock_by_location'));
        add_action('wp_ajax_nopriv_sistur_get_stock_by_location', array($this, 'ajax_get_stock_by_location')); // Portal do Colaborador (SISTUR_Session)

        error_log('✅ [DEBUG] SISTUR_Inventory::init_hooks() completed');
    }

    /**
     * AJAX: Salvar produto (CMV Module - inclui receivers)
     */
    public function ajax_cmv_save_product()
    {
        check_ajax_referer('sistur_stock_nonce', 'nonce');

        // Check permissions (Admin OR Employee with permission)
        $has_permission = false;
        if (current_user_can('manage_options')) {
            $has_permission = true;
        } elseif (class_exists('SISTUR_Session') && class_exists('SISTUR_Permissions')) {
            $session = SISTUR_Session::get_instance();
            if ($session->is_employee_logged_in()) {
                $employee_id = $session->get_employee_id();
                $permissions = SISTUR_Permissions::get_instance();
                if ($permissions->can($employee_id, 'cmv', 'manage_products')) {
                    $has_permission = true;
                }
            }
        }

        if (!$has_permission) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        global $wpdb;
        $table_products = $wpdb->prefix . 'sistur_products';
        $table_recipes = $wpdb->prefix . 'sistur_recipes';

        $id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'RAW';

        // Se SKU estiver vazio (comum em RESOURCE), gerar um automático para evitar erro de duplicidade
        if (empty($sku)) {
            $sku = 'AUTO-' . strtoupper(substr($type, 0, 3)) . '-' . date('ymdHis') . '-' . rand(100, 999);
        }
        $unit_id = isset($_POST['unit_id']) ? intval($_POST['unit_id']) : null;
        $min_stock = isset($_POST['min_stock']) ? floatval($_POST['min_stock']) : 0;
        $selling_price = isset($_POST['selling_price']) ? floatval($_POST['selling_price']) : 0;

        $content_quantity = isset($_POST['content_quantity']) && $_POST['content_quantity'] !== '' ? floatval($_POST['content_quantity']) : null;
        $content_unit_id = isset($_POST['content_unit_id']) && $_POST['content_unit_id'] !== '' ? intval($_POST['content_unit_id']) : null;

        $resource_parent_id = isset($_POST['resource_parent_id']) && $_POST['resource_parent_id'] !== '' ? intval($_POST['resource_parent_id']) : null;

        if (empty($name)) {
            wp_send_json_error(array('message' => 'Nome é obrigatório.'));
        }

        // 1. Salvar Produto
        // v2.13.0 - Perishability
        $is_perishable = isset($_POST['is_perishable']) ? intval($_POST['is_perishable']) : 1;

        $data = array(
            'name' => $name,
            'sku' => $sku,
            'type' => $type,
            'base_unit_id' => $unit_id,
            'min_stock' => $min_stock,
            'selling_price' => $selling_price,
            'content_quantity' => $content_quantity,
            'content_unit_id' => $content_unit_id,
            'resource_parent_id' => $resource_parent_id,
            'status' => 'active',
            'is_perishable' => $is_perishable
        );

        $format = array('%s', '%s', '%s', '%d', '%f', '%f', '%f', '%d', '%d', '%s', '%d');

        if ($id > 0) {
            $wpdb->update($table_products, $data, array('id' => $id), $format, array('%d'));
        } else {
            $wpdb->insert($table_products, $data, $format);
            $id = $wpdb->insert_id;
        }

        if (!$id) {
            wp_send_json_error(array('message' => 'Erro ao salvar produto.'));
        }

        // 2. Salvar Receita (Ingredientes) se for MANUFACTURED ou BASE
        if (strpos($type, 'MANUFACTURED') !== false || $type === 'BASE') {
            // Limpar receita anterior
            $wpdb->delete($table_recipes, array('parent_product_id' => $id));

            $ingredients = isset($_POST['ingredients']) ? $_POST['ingredients'] : array();

            if (!empty($ingredients) && is_array($ingredients)) {
                foreach ($ingredients as $ing) {
                    $child_id = intval($ing['id']);
                    $qty = floatval($ing['qty']);
                    $ing_unit_id = !empty($ing['unit_id']) ? intval($ing['unit_id']) : null;

                    if ($child_id > 0 && $qty > 0) {
                        $wpdb->insert($table_recipes, array(
                            'parent_product_id' => $id,
                            'child_product_id' => $child_id,
                            'quantity_net' => $qty,
                            'quantity_gross' => $qty, // Simplificado, yield=1
                            'yield_factor' => 1.00,
                            'unit_id' => $ing_unit_id
                        ));
                    }
                }
            }
        }

        wp_send_json_success(array(
            'message' => 'Produto salvo com sucesso!',
            'product_id' => $id
        ));
    }

    /**
     * AJAX: Produzir produto (CMV Module)
     * Dá baixa nos ingredientes e adiciona estoque ao produto final
     */
    public function ajax_cmv_produce()
    {
        check_ajax_referer('sistur_stock_nonce', 'nonce');

        // Check permissions (Admin OR Employee with permission)
        $has_permission = false;
        if (current_user_can('manage_options')) {
            $has_permission = true;
        } elseif (class_exists('SISTUR_Session') && class_exists('SISTUR_Permissions')) {
            $session = SISTUR_Session::get_instance();
            if ($session->is_employee_logged_in()) {
                $employee_id = $session->get_employee_id();
                $permissions = SISTUR_Permissions::get_instance();
                if ($permissions->can($employee_id, 'cmv', 'produce')) {
                    $has_permission = true;
                }
            }
        }

        if (!$has_permission) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? floatval($_POST['quantity']) : 0;

        if ($product_id <= 0 || $quantity <= 0) {
            wp_send_json_error(array('message' => 'Produto e quantidade são obrigatórios.'));
        }

        // Usar Recipe Manager produce() que já tem toda lógica correta
        $recipe_manager = Sistur_Recipe_Manager::get_instance();
        $result = $recipe_manager->produce($product_id, $quantity, 'Produção via CMV Portal');

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
        }

        wp_send_json_success(array(
            'message' => sprintf('Produção realizada! %d unidades adicionadas ao estoque.', $quantity),
            'details' => $result
        ));
    }

    /**
     * AJAX: Salvar produto
     */
    public function ajax_save_product()
    {
        check_ajax_referer('sistur_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_products';

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
        $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
        $barcode = isset($_POST['barcode']) ? sanitize_text_field($_POST['barcode']) : '';
        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : null;
        $cost_price = isset($_POST['cost_price']) ? floatval($_POST['cost_price']) : 0;
        $selling_price = isset($_POST['selling_price']) ? floatval($_POST['selling_price']) : 0;
        $min_stock = isset($_POST['min_stock']) ? intval($_POST['min_stock']) : 0;
        $current_stock = isset($_POST['current_stock']) ? intval($_POST['current_stock']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'active';

        // Campos de conteúdo da embalagem (v2.3)
        $content_quantity = isset($_POST['content_quantity']) && $_POST['content_quantity'] !== ''
            ? floatval($_POST['content_quantity'])
            : null;

        // v2.13.0 - Perishability
        $is_perishable = isset($_POST['is_perishable']) ? intval($_POST['is_perishable']) : 1;

        if (empty($name)) {
            wp_send_json_error(array('message' => 'Nome é obrigatório.'));
        }

        // Verificar se SKU já existe
        if (!empty($sku)) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE sku = %s AND id != %d",
                $sku,
                $id
            ));

            if ($existing) {
                wp_send_json_error(array('message' => 'SKU já cadastrado.'));
            }
        }

        $data = array(
            'name' => $name,
            'description' => $description,
            'sku' => $sku,
            'barcode' => $barcode,
            'category_id' => $category_id,
            'cost_price' => $cost_price,
            'selling_price' => $selling_price,
            'min_stock' => $min_stock,
            'current_stock' => $current_stock,
            'status' => $status,
            'content_quantity' => $content_quantity,
            'content_unit_id' => $content_unit_id,
            'is_perishable' => $is_perishable
        );

        $format = array('%s', '%s', '%s', '%s', '%d', '%f', '%f', '%d', '%d', '%s', '%f', '%d', '%d');

        if ($id > 0) {
            $result = $wpdb->update($table, $data, array('id' => $id), $format, array('%d'));
        } else {
            $result = $wpdb->insert($table, $data, $format);
            $id = $wpdb->insert_id;
        }

        if ($result === false && $wpdb->last_error) {
            wp_send_json_error(array('message' => 'Erro ao salvar produto.'));
        }

        wp_send_json_success(array(
            'message' => 'Produto salvo com sucesso!',
            'product_id' => $id
        ));
    }

    /**
     * AJAX: Deletar produto
     */
    public function ajax_delete_product()
    {
        check_ajax_referer('sistur_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_products';

        $result = $wpdb->delete($table, array('id' => $id), array('%d'));

        if ($result === false) {
            wp_send_json_error(array('message' => 'Erro ao excluir produto.'));
        }

        wp_send_json_success(array('message' => 'Produto excluído com sucesso!'));
    }

    /**
     * AJAX: Obter produto
     */
    public function ajax_get_product()
    {
        check_ajax_referer('sistur_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        if ($id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_products';

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ), ARRAY_A);

        if (!$product) {
            wp_send_json_error(array('message' => 'Produto não encontrado.'));
        }

        wp_send_json_success(array('product' => $product));
    }

    /**
     * AJAX: Obter produtos
     */
    public function ajax_get_products()
    {
        check_ajax_referer('sistur_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_products';

        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'active';
        $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;

        $where = "status = %s";
        $params = array($status);

        if ($category_id) {
            $where .= " AND category_id = %d";
            $params[] = $category_id;
        }

        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE $where ORDER BY name ASC",
            $params
        ), ARRAY_A);

        wp_send_json_success(array('products' => $products));
    }

    /**
     * AJAX: Salvar categoria
     */
    public function ajax_save_category()
    {
        check_ajax_referer('sistur_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_product_categories';

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';

        if (empty($name)) {
            wp_send_json_error(array('message' => 'Nome é obrigatório.'));
        }

        $data = array(
            'name' => $name,
            'description' => $description
        );

        $format = array('%s', '%s');

        if ($id > 0) {
            $result = $wpdb->update($table, $data, array('id' => $id), $format, array('%d'));
        } else {
            $result = $wpdb->insert($table, $data, $format);
            $id = $wpdb->insert_id;
        }

        if ($result === false && $wpdb->last_error) {
            wp_send_json_error(array('message' => 'Erro ao salvar categoria.'));
        }

        wp_send_json_success(array(
            'message' => 'Categoria salva com sucesso!',
            'category_id' => $id
        ));
    }

    /**
     * AJAX: Deletar categoria
     */
    public function ajax_delete_category()
    {
        check_ajax_referer('sistur_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_product_categories';
        $products_table = $wpdb->prefix . 'sistur_products';

        // Verificar se há produtos nesta categoria
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $products_table WHERE category_id = %d",
            $id
        ));

        if ($count > 0) {
            wp_send_json_error(array('message' => 'Não é possível excluir categoria com produtos vinculados.'));
        }

        $result = $wpdb->delete($table, array('id' => $id), array('%d'));

        if ($result === false) {
            wp_send_json_error(array('message' => 'Erro ao excluir categoria.'));
        }

        wp_send_json_success(array('message' => 'Categoria excluída com sucesso!'));
    }

    /**
     * AJAX: Obter categorias
     */
    public function ajax_get_categories()
    {
        check_ajax_referer('sistur_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_product_categories';

        $categories = $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC", ARRAY_A);

        wp_send_json_success(array('categories' => $categories));
    }

    /**
     * AJAX: Salvar movimentação
     */
    public function ajax_save_movement()
    {
        error_log('🔍 [DEBUG] ajax_save_movement called - BEFORE NONCE CHECK');
        error_log('📋 [DEBUG] POST data: ' . print_r($_POST, true));
        error_log('🔑 [DEBUG] Nonce from POST: ' . ($_POST['nonce'] ?? 'NOT SET'));

        check_ajax_referer('sistur_inventory_nonce', 'nonce');
        error_log('✅ [DEBUG] Nonce validated');

        // Obter employee_id PRIMEIRO (independente de ser admin ou não)
        $employee_id = null;
        if (class_exists('SISTUR_Session')) {
            $session = SISTUR_Session::get_instance();
            $employee_id = $session->get_employee_id();
            error_log('👤 [DEBUG] Employee ID: ' . ($employee_id ?: 'NULL (maybe admin without employee record)'));
        }

        // Verificar permissões (Admin OU Funcionário com permissão de estoque/CMV)
        $has_permission = false;
        if (current_user_can('manage_options')) {
            $has_permission = true;
            error_log('✅ [DEBUG] User is admin');
        } elseif (class_exists('SISTUR_Permissions') && $employee_id) {
            $permissions = SISTUR_Permissions::get_instance();

            // Permitir se tem permissão de gerenciar estoque OU gerenciar produtos CMV
            $has_permission = $permissions->can($employee_id, 'inventory', 'manage') ||
                $permissions->can($employee_id, 'cmv', 'manage_products') ||
                $permissions->can($employee_id, 'cmv', 'manage_full');
            error_log('🔐 [DEBUG] Employee permission check: ' . ($has_permission ? 'GRANTED' : 'DENIED'));
        }

        if (!$has_permission) {
            error_log('❌ [DEBUG] Permission denied');
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        error_log('✅ [DEBUG] Permission granted, proceeding...');

        global $wpdb;
        $movements_table = $wpdb->prefix . 'sistur_inventory_movements';
        $products_table = $wpdb->prefix . 'sistur_products';

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $quantity = isset($_POST['quantity']) ? floatval($_POST['quantity']) : 0;
        $unit_price = isset($_POST['unit_price']) ? floatval($_POST['unit_price']) : 0;
        $expiry_date = isset($_POST['expiry_date']) ? sanitize_text_field($_POST['expiry_date']) : null;
        $reference = isset($_POST['reference']) ? sanitize_text_field($_POST['reference']) : '';
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';

        error_log(sprintf(
            '📦 [DEBUG] Data parsed - Product: %d, Type: %s, Qty: %f, Price: %f',
            $product_id,
            $type,
            $quantity,
            $unit_price
        ));

        if ($product_id <= 0) {
            error_log('❌ [DEBUG] Invalid product ID');
            wp_send_json_error(array('message' => 'Produto inválido.'));
        }

        if ($quantity <= 0) {
            error_log('❌ [DEBUG] Invalid quantity');
            wp_send_json_error(array('message' => 'Quantidade inválida.'));
        }

        // Se for Entrada, usar API de Estoque (Moderno)
        if ($type === 'entry' && class_exists('SISTUR_Stock_API')) {
            error_log('🔄 [DEBUG] Processing ENTRY via Stock API');
            if (empty($expiry_date))
                $expiry_date = null;

            $location_id = isset($_POST['location_id']) && !empty($_POST['location_id']) ? intval($_POST['location_id']) : null;
            $acquisition_location = isset($_POST['acquisition_location']) ? sanitize_text_field($_POST['acquisition_location']) : null;

            $batch_id = SISTUR_Stock_API::register_entry(
                $product_id,
                $quantity,
                $unit_price,
                $expiry_date,
                $location_id,
                $notes,
                $employee_id,
                null, // batch_code (auto-generated)
                $acquisition_location,
                null // entry_date (auto-generated)
            );

            error_log('📝 [DEBUG] register_entry returned: ' . print_r($batch_id, true));

            if (is_wp_error($batch_id)) {
                error_log('❌ [DEBUG] register_entry error: ' . $batch_id->get_error_message());
                wp_send_json_error(array('message' => $batch_id->get_error_message()));
            }

            error_log('✅ [DEBUG] Entry registered successfully. Batch ID: ' . $batch_id);
            wp_send_json_success(array('message' => 'Entrada registrada com sucesso! Lote #' . $batch_id));
            return;
        }

        // Fallback Legado (apenas para outros tipos se houver)
        if (!in_array($type, array('exit', 'adjustment'))) {
            wp_send_json_error(array('message' => 'Tipo inválido.'));
        }

        $total_value = $quantity * $unit_price;

        // Inserir movimentação
        $result = $wpdb->insert(
            $movements_table,
            array(
                'product_id' => $product_id,
                'employee_id' => $employee_id,  // ← Rastreabilidade
                'type' => $type,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'total_value' => $total_value,
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => get_current_user_id()
            ),
            array('%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%d')
        );

        if ($result === false) {
            wp_send_json_error(array('message' => 'Erro ao salvar movimentação.'));
        }

        // Atualizar estoque do produto
        $current_stock = $wpdb->get_var($wpdb->prepare(
            "SELECT current_stock FROM $products_table WHERE id = %d",
            $product_id
        ));

        $new_stock = $current_stock;

        if ($type === 'exit') {
            $new_stock -= $quantity;
        } elseif ($type === 'adjustment') {
            $new_stock = $quantity;
        }

        $wpdb->update(
            $products_table,
            array('current_stock' => $new_stock),
            array('id' => $product_id),
            array('%d'),
            array('%d')
        );

        wp_send_json_success(array(
            'message' => 'Movimentação registrada com sucesso!',
            'new_stock' => $new_stock
        ));
    }

    public function ajax_save_bulk_movement()
    {
        check_ajax_referer('sistur_inventory_nonce', 'nonce');

        $employee_id = null;
        if (class_exists('SISTUR_Session')) {
            $session = SISTUR_Session::get_instance();
            $employee_id = $session->get_employee_id();
        }

        $has_permission = false;
        if (current_user_can('manage_options')) {
            $has_permission = true;
        } elseif (class_exists('SISTUR_Permissions') && $employee_id) {
            $permissions = SISTUR_Permissions::get_instance();
            $has_permission = $permissions->can($employee_id, 'inventory', 'manage') ||
                $permissions->can($employee_id, 'cmv', 'manage_products') ||
                $permissions->can($employee_id, 'cmv', 'manage_full');
        }

        if (!$has_permission) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $location_id = isset($_POST['location_id']) && !empty($_POST['location_id'])
            ? intval($_POST['location_id'])
            : null;

        if (!$location_id) {
            wp_send_json_error(array('message' => 'Local de armazenamento não informado.'));
        }

        $supplier_id = isset($_POST['supplier_id']) && !empty($_POST['supplier_id'])
            ? intval($_POST['supplier_id'])
            : null;

        // Manter acquisition_location para compatibilidade reversa
        $acquisition_location = isset($_POST['acquisition_location'])
            ? sanitize_text_field($_POST['acquisition_location'])
            : null;

        $notes = isset($_POST['notes'])
            ? sanitize_textarea_field($_POST['notes'])
            : '';

        $entries_raw = isset($_POST['entries']) ? $_POST['entries'] : '[]';
        $entries = json_decode(stripslashes($entries_raw), true);

        if (!is_array($entries) || count($entries) === 0) {
            wp_send_json_error(array('message' => 'Nenhum produto informado.'));
        }

        if (!class_exists('SISTUR_Stock_API')) {
            wp_send_json_error(array('message' => 'API de estoque não disponível.'));
        }

        $registered = 0;
        $errors = array();

        foreach ($entries as $entry) {
            $product_id = isset($entry['product_id']) ? intval($entry['product_id']) : 0;
            $quantity = isset($entry['quantity']) ? floatval($entry['quantity']) : 0;
            $unit_price = isset($entry['unit_price']) ? floatval($entry['unit_price']) : 0;
            $expiry_date = isset($entry['expiry_date']) && !empty($entry['expiry_date'])
                ? sanitize_text_field($entry['expiry_date'])
                : null;

            if ($product_id <= 0 || $quantity <= 0) {
                continue;
            }

            $batch_id = SISTUR_Stock_API::register_entry(
                $product_id,
                $quantity,
                $unit_price,
                $expiry_date,
                $location_id,
                $notes ?: null,
                $employee_id,
                null,
                $acquisition_location ?: null,
                null,
                null,
                $supplier_id
            );

            if (is_wp_error($batch_id)) {
                $errors[] = 'Produto #' . $product_id . ': ' . $batch_id->get_error_message();
            } else {
                $registered++;
            }
        }

        if ($registered === 0 && !empty($errors)) {
            wp_send_json_error(array('message' => implode('; ', $errors)));
        }

        $msg = $registered . ' ' . ($registered === 1 ? 'entrada registrada' : 'entradas registradas') . ' com sucesso!';
        if (!empty($errors)) {
            $msg .= ' Erros: ' . implode('; ', $errors);
        }

        wp_send_json_success(array(
            'message' => $msg,
            'registered' => $registered,
            'errors' => $errors,
        ));
    }

    /**
     * AJAX: Obter movimentações
     */
    public function ajax_get_movements()
    {
        check_ajax_referer('sistur_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_inventory_movements';

        $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : null;
        $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : null;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;

        $where = array('1=1');
        $params = array();

        if ($product_id) {
            $where[] = 'product_id = %d';
            $params[] = $product_id;
        }

        if ($type) {
            $where[] = 'type = %s';
            $params[] = $type;
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT * FROM $table WHERE $where_clause ORDER BY created_at DESC LIMIT %d";
        $params[] = $limit;

        $movements = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        wp_send_json_success(array('movements' => $movements));
    }

    /**
     * Renderiza dashboard de inventário
     */
    public static function render_inventory_dashboard()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/inventory/dashboard.php';
    }

    /**
     * Shortcode: Inventário público
     */
    public static function shortcode_inventory($atts)
    {
        $atts = shortcode_atts(array(
            'view' => 'list',
            'category' => ''
        ), $atts);

        ob_start();
        ?>
        <div class="sistur-inventory-public">
            <h2><?php _e('Inventário', 'sistur'); ?></h2>
            <p><?php _e('Visualização pública de inventário.', 'sistur'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Registrar venda (saída de estoque sem aprovação)
     */
    public function ajax_record_sale()
    {
        check_ajax_referer('sistur_inventory_nonce', 'nonce');

        $session = SISTUR_Session::get_instance();
        if (!$session->is_employee_logged_in()) {
            wp_send_json_error(array('message' => 'Não autenticado.'));
            return;
        }

        $employee_id = $session->get_employee_id();
        $permissions = SISTUR_Permissions::get_instance();

        if (!$permissions->can($employee_id, 'inventory', 'record_sale')) {
            wp_send_json_error(array('message' => 'Sem permissão.'));
            return;
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? $_POST['quantity_display'] ?? 0);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        if (!$product_id || $quantity <= 0) {
            wp_send_json_error(array('message' => 'Produto e quantidade são obrigatórios.'));
            return;
        }

        global $wpdb;
        $products_table = $wpdb->prefix . 'sistur_products';
        $movements_table = $wpdb->prefix . 'sistur_inventory_movements';

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $products_table WHERE id = %d",
            $product_id
        ), ARRAY_A);

        if (!$product || $product['current_stock'] < $quantity) {
            wp_send_json_error(array('message' => 'Estoque insuficiente.'));
            return;
        }

        // Inserir movimentação
        $wpdb->insert($movements_table, array(
            'product_id' => $product_id,
            'type' => 'exit',
            'movement_reason' => 'sale',
            'quantity' => $quantity,
            'unit_price' => $product['selling_price'],
            'total_value' => $quantity * $product['selling_price'],
            'notes' => $notes,
            'requires_approval' => 0,
            'employee_id' => $employee_id,
            'created_by' => $employee_id
        ));

        // Atualizar estoque
        $wpdb->update(
            $products_table,
            array('current_stock' => $product['current_stock'] - $quantity),
            array('id' => $product_id)
        );

        wp_send_json_success(array('message' => 'Venda registrada com sucesso!'));
    }

    /**
     * AJAX: Solicitar baixa (perda/quebra) - requer aprovação
     */
    public function ajax_request_loss()
    {
        // DEBUG: Log initial request
        error_log('SISTUR DEBUG: ajax_request_loss (sistur_submit_stock_loss) called. POST: ' . print_r($_POST, true));

        check_ajax_referer('sistur_inventory_nonce', 'nonce');

        $session = SISTUR_Session::get_instance();
        if (!$session->is_employee_logged_in()) {
            error_log('SISTUR DEBUG: Employee not logged in');
            wp_send_json_error(array('message' => 'Não autenticado.'));
            return;
        }

        $employee_id = $session->get_employee_id();
        $permissions = SISTUR_Permissions::get_instance();

        if (!$permissions->can($employee_id, 'inventory', 'request_loss')) {
            error_log('SISTUR DEBUG: No permission');
            wp_send_json_error(array('message' => 'Sem permissão.'));
            return;
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        // Handle comma in quantity if present
        $raw_qty = $_POST['quantity'] ?? $_POST['quantity_display'] ?? 0;
        $raw_qty = str_replace(',', '.', (string) $raw_qty);
        $quantity = floatval($raw_qty);

        $reason = sanitize_text_field($_POST['reason'] ?? 'loss');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        error_log("SISTUR DEBUG: Parsed values - Product: $product_id, Qty: $quantity");

        if (!$product_id || $quantity <= 0) {
            error_log('SISTUR DEBUG: Invalid product or quantity');
            wp_send_json_error(array('message' => 'Produto e quantidade são obrigatórios.'));
            return;
        }

        if (empty($notes)) {
            error_log('SISTUR DEBUG: Empty notes');
            wp_send_json_error(array('message' => 'Justificativa é obrigatória.'));
            return;
        }

        global $wpdb;
        $products_table = $wpdb->prefix . 'sistur_products';

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $products_table WHERE id = %d",
            $product_id
        ), ARRAY_A);

        if (!$product) {
            error_log('SISTUR DEBUG: Product not found in DB');
            wp_send_json_error(array('message' => 'Produto não encontrado.'));
            return;
        }

        $approvals = SISTUR_Approvals::get_instance();
        $request_id = $approvals->create_request('inventory', 'stock_loss', $product_id, array(
            'entity_type' => 'product',
            'product_id' => $product_id,
            'product_name' => $product['name'],
            'quantity' => $quantity,
            'reason' => $reason,
            'unit_price' => $product['cost_price'],
            'notes' => $notes
        ), $employee_id);

        if ($request_id) {
            error_log("SISTUR DEBUG: Request created ID: $request_id");
            wp_send_json_success(array(
                'message' => 'Solicitação enviada para aprovação!',
                'request_id' => $request_id
            ));
        } else {
            error_log('SISTUR DEBUG: Failed to create approval request');
            wp_send_json_error(array('message' => 'Erro ao criar solicitação.'));
        }
    }

    /**
     * AJAX: Obter estoque agrupado por local
     */
    public function ajax_get_stock_by_location()
    {
        check_ajax_referer('sistur_inventory_nonce', 'nonce');

        $permissions = SISTUR_Permissions::get_instance();
        $session = SISTUR_Session::get_instance();
        $employee_id = $session->get_employee_id();

        if (!$permissions->can($employee_id, 'inventory', 'view')) {
            wp_send_json_error(array('message' => 'Sem permissão.'));
            return;
        }

        global $wpdb;

        // Buscar lotes ativos com quantidades > 0 e suas localizações (incluindo pai e setor) - v2.15.3
        $results = $wpdb->get_results("
            SELECT
                b.id as batch_id,
                b.product_id,
                b.quantity,
                b.expiry_date,
                b.batch_code,
                b.sector_id,
                p.name as product_name,
                p.sku,
                u.symbol as unit,
                l.id as location_id,
                l.name as location_name,
                l.parent_id as location_parent_id,
                lp.name as parent_location_name,
                s.name as sector_name
            FROM {$wpdb->prefix}sistur_inventory_batches b
            INNER JOIN {$wpdb->prefix}sistur_products p ON b.product_id = p.id
            LEFT JOIN {$wpdb->prefix}sistur_units u ON p.base_unit_id = u.id
            LEFT JOIN {$wpdb->prefix}sistur_storage_locations l ON b.location_id = l.id
            LEFT JOIN {$wpdb->prefix}sistur_storage_locations lp ON l.parent_id = lp.id
            LEFT JOIN {$wpdb->prefix}sistur_storage_locations s ON b.sector_id = s.id
            WHERE b.status = 'active'
            AND b.quantity > 0
            ORDER BY lp.name ASC, l.name ASC, p.name ASC
        ", ARRAY_A);

        // Agrupar por local
        $grouped = array();

        foreach ($results as $row) {
            $loc_id = $row['location_id'] ? $row['location_id'] : 0;

            // Formatar nome do local: "Setor A1" ou "Almoxarifado - A1"
            $loc_name = 'Não definido';
            if ($row['location_name']) {
                $loc_name = $row['location_name'];
                if ($row['parent_location_name']) {
                    $loc_name = $row['parent_location_name'] . ' - ' . $loc_name;
                }
            }

            $target_id = $loc_id;

            if (!isset($grouped[$target_id])) {
                $grouped[$target_id] = array(
                    'id' => $target_id,
                    'name' => $target_id == 0 ? 'Sem Local Definido' : $loc_name,
                    'parent_id' => $row['location_parent_id'],
                    'items' => array()
                );
            }

            if (!isset($grouped[$target_id]['items'][$row['product_id']])) {
                $grouped[$target_id]['items'][$row['product_id']] = array(
                    'product_id' => $row['product_id'],
                    'name' => $row['product_name'],
                    'sku' => $row['sku'],
                    'unit' => $row['unit'],
                    'total_quantity' => 0,
                    'batches' => array()
                );
            }

            $grouped[$target_id]['items'][$row['product_id']]['total_quantity'] += (float) $row['quantity'];
            $grouped[$target_id]['items'][$row['product_id']]['batches'][] = array(
                'batch_code' => $row['batch_code'],
                'quantity' => (float) $row['quantity'],
                'expiry' => $row['expiry_date'],
                'sector_id' => $row['sector_id'],
                'sector_name' => $row['sector_name']
            );
        }

        // Reindexar items para array
        foreach ($grouped as &$loc) {
            $loc['items'] = array_values($loc['items']);
        }

        wp_send_json_success(array_values($grouped));
    }
}
