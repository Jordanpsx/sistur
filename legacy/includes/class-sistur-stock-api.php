<?php
/**
 * API REST para Gestão de Estoque
 * 
 * Fornece endpoints para gerenciamento de produtos, lotes e movimentações
 * com controle FIFO para saídas.
 *
 * @package SISTUR
 * @subpackage Stock
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SISTUR_Stock_API
{

    /**
     * Instância única (Singleton)
     */
    private static $instance = null;

    /**
     * Namespace da API
     */
    const API_NAMESPACE = 'sistur/v1';

    /**
     * Prefixo das tabelas
     */
    private $prefix;

    /**
     * Construtor privado
     */
    private function __construct()
    {
        global $wpdb;
        $this->prefix = $wpdb->prefix . 'sistur_';

        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Retorna instância única
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Static helper para registrar entrada de estoque (usado por AJAX handlers)
     * 
     * @param int $product_id ID do produto
     * @param float $quantity Quantidade a adicionar
     * @param float $cost_price Custo unitário
     * @param string|null $expiry_date Data de validade (Y-m-d ou null)
     * @param int|null $location_id ID do local (opcional)
     * @param string|null $reason Motivo/observação
     * @param int|null $employee_id ID do funcionário que está registrando (rastreabilidade)
     * @param string|null $batch_code Código do lote (opcional)
     * @param string|null $acquisition_location Local de aquisição (v2.9.1)
     * @param string|null $entry_date Data de entrada (v2.9.1)
     * @return int|WP_Error ID do lote criado ou WP_Error em caso de falha
     */
    public static function register_entry($product_id, $quantity, $cost_price, $expiry_date = null, $location_id = null, $reason = null, $employee_id = null, $batch_code = null, $acquisition_location = null, $entry_date = null, $manufacturing_date = null, $supplier_id = null)
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'sistur_';

        // Validações básicas
        $product_id = (int) $product_id;
        $quantity = abs((float) $quantity);
        $cost_price = (float) $cost_price;

        if ($product_id <= 0 || $quantity <= 0) {
            return new WP_Error('invalid_data', 'Produto e quantidade são obrigatórios', array('status' => 400));
        }

        // Verificar se o produto existe
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT id, base_unit_id FROM {$prefix}products WHERE id = %d",
            $product_id
        ));

        if (!$product) {
            return new WP_Error('not_found', 'Produto não encontrado', array('status' => 404));
        }

        // Gerar código de lote automático se não informado
        if (empty($batch_code)) {
            $batch_code = 'ENT-' . date('ymdHis') . '-' . rand(100, 999);
        }

        // Detectar se location_id é um setor (tem parent_id) e separar location/sector
        $sector_id = null;
        if ($location_id) {
            $loc_info = $wpdb->get_row($wpdb->prepare(
                "SELECT id, parent_id FROM {$prefix}storage_locations WHERE id = %d",
                $location_id
            ));
            if ($loc_info && $loc_info->parent_id) {
                $sector_id = $location_id;
                $location_id = (int) $loc_info->parent_id;
            }
        }

        // Criar lote COM rastreabilidade
        $batch_data = array(
            'product_id' => $product_id,
            'location_id' => $location_id,
            'sector_id' => $sector_id,
            'batch_code' => $batch_code,
            'expiry_date' => $expiry_date ?: null,
            'cost_price' => $cost_price,
            'quantity' => $quantity,
            'initial_quantity' => $quantity,
            'status' => 'active',
            'created_by_employee_id' => $employee_id,
            'created_by_user_id' => get_current_user_id(),
            'acquisition_location' => $acquisition_location,
            'supplier_id' => $supplier_id ? intval($supplier_id) : null,
            'entry_date' => $entry_date ?: current_time('mysql'),
            'manufacturing_date' => $manufacturing_date ?: null,
        );

        $result = $wpdb->insert($prefix . 'inventory_batches', $batch_data);

        if ($result === false) {
            return new WP_Error('db_error', 'Erro ao criar lote: ' . $wpdb->last_error, array('status' => 500));
        }

        $batch_id = $wpdb->insert_id;

        // Registrar transação no ledger COM rastreabilidade
        $wpdb->insert($prefix . 'inventory_transactions', array(
            'product_id' => $product_id,
            'batch_id' => $batch_id,
            'type' => 'IN',
            'quantity' => $quantity,
            'reason' => $reason,
            'unit_cost' => $cost_price,
            'to_location_id' => $location_id,
            'employee_id' => $employee_id,
            'user_id' => get_current_user_id()
        ));

        // Atualizar cached_stock no produto
        $total_stock = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(quantity), 0) FROM {$prefix}inventory_batches 
             WHERE product_id = %d AND status = 'active' AND quantity > 0",
            $product_id
        ));

        $wpdb->update(
            $prefix . 'products',
            array('cached_stock' => $total_stock),
            array('id' => $product_id)
        );

        // Recalcular custo médio móvel baseado em todas as entradas históricas (tipo IN)
        $avg_cost = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(ABS(quantity) * unit_cost) / NULLIF(SUM(ABS(quantity)), 0)
             FROM {$prefix}inventory_transactions
             WHERE product_id = %d AND type = 'IN' AND unit_cost > 0",
            $product_id
        ));

        if ($avg_cost !== null) {
            $wpdb->update(
                $prefix . 'products',
                array('average_cost' => $avg_cost),
                array('id' => $product_id)
            );
        }

        return $batch_id;
    }

    /**
     * Registra todas as rotas REST
     */
    public function register_routes()
    {
        // Produtos
        register_rest_route(self::API_NAMESPACE, '/stock/products', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_products'),
                'permission_callback' => array($this, 'check_view_permission'),
                'args' => array(
                    'page' => array(
                        'default' => 1,
                        'validate_callback' => function ($param) {
                            return is_numeric($param) && $param > 0;
                        }
                    ),
                    'per_page' => array(
                        'default' => 20,
                        'validate_callback' => function ($param) {
                            return is_numeric($param) && $param > 0 && $param <= 100;
                        }
                    ),
                    'search' => array(
                        'default' => '',
                        'sanitize_callback' => 'sanitize_text_field'
                    ),
                    'type' => array(
                        'default' => '',
                        'sanitize_callback' => 'sanitize_text_field'
                    )
                )
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'create_product'),
                'permission_callback' => array($this, 'check_manage_products_permission'),
            )
        ));

        // Produto único
        register_rest_route(self::API_NAMESPACE, '/stock/products/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_product'),
                'permission_callback' => array($this, 'check_view_permission'),
            ),
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'update_product'),
                'permission_callback' => array($this, 'check_manage_products_permission'),
            )
        ));

        // Movimentações
        register_rest_route(self::API_NAMESPACE, '/stock/movements', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'create_movement'),
            'permission_callback' => array($this, 'check_movement_permission'),
        ));

        // Transferência entre estoques (v2.10.0)
        register_rest_route(self::API_NAMESPACE, '/stock/transfer', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'transfer_stock'),
            'permission_callback' => array($this, 'check_manage_stock_permission'),
        ));

        // Lotes de um produto
        register_rest_route(self::API_NAMESPACE, '/stock/batches/(?P<product_id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_product_batches'),
            'permission_callback' => array($this, 'check_view_permission'),
        ));

        // Unidades de medida - GET + POST
        register_rest_route(self::API_NAMESPACE, '/stock/units', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_units'),
                'permission_callback' => array($this, 'check_view_permission'),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'create_unit'),
                'permission_callback' => array($this, 'check_manage_settings_permission'),
            )
        ));

        // Unidade única - PUT + DELETE
        register_rest_route(self::API_NAMESPACE, '/stock/units/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'update_unit'),
                'permission_callback' => array($this, 'check_manage_settings_permission'),
            ),
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'delete_unit'),
                'permission_callback' => array($this, 'check_manage_settings_permission'),
            )
        ));

        // Locais de armazenamento - GET + POST
        register_rest_route(self::API_NAMESPACE, '/stock/locations', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_locations'),
                'permission_callback' => array($this, 'check_view_permission'),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'create_location'),
                'permission_callback' => array($this, 'check_manage_settings_permission'),
            )
        ));

        // Define Default Location - POST
        register_rest_route(self::API_NAMESPACE, '/stock/locations/default', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'set_default_location'),
            'permission_callback' => array($this, 'check_manage_settings_permission'),
        ));

        // Local único - PUT + DELETE
        register_rest_route(self::API_NAMESPACE, '/stock/locations/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'update_location'),
                'permission_callback' => array($this, 'check_manage_settings_permission'),
            ),
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'delete_location'),
                'permission_callback' => array($this, 'check_manage_settings_permission'),
            )
        ));

        // Fornecedores - GET + POST
        register_rest_route(self::API_NAMESPACE, '/stock/suppliers', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_suppliers'),
                'permission_callback' => array($this, 'check_view_permission'),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'create_supplier'),
                'permission_callback' => array($this, 'check_manage_settings_permission'),
            )
        ));

        // Fornecedor único - PUT + DELETE
        register_rest_route(self::API_NAMESPACE, '/stock/suppliers/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'update_supplier'),
                'permission_callback' => array($this, 'check_manage_settings_permission'),
            ),
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'delete_supplier'),
                'permission_callback' => array($this, 'check_manage_settings_permission'),
            )
        ));

        // Transações (log)
        register_rest_route(self::API_NAMESPACE, '/stock/transactions', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_transactions'),
            'permission_callback' => array($this, 'check_view_permission'),
            'args' => array(
                'product_id' => array(
                    'default' => 0,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    }
                ),
                'limit' => array(
                    'default' => 50,
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && $param > 0 && $param <= 200;
                    }
                )
            )
        ));

        // ============================================
        // Ficha Técnica / Receitas
        // ============================================

        // GET ingredientes de um produto / POST adicionar ingrediente
        register_rest_route(self::API_NAMESPACE, '/recipes/(?P<product_id>\d+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_recipe_ingredients'),
                'permission_callback' => array($this, 'check_view_permission'),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'add_recipe_ingredient'),
                'permission_callback' => array($this, 'check_manage_recipes_permission'),
            )
        ));

        // PUT/DELETE ingrediente específico
        register_rest_route(self::API_NAMESPACE, '/recipes/ingredient/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'update_recipe_ingredient'),
                'permission_callback' => array($this, 'check_manage_recipes_permission'),
            ),
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'delete_recipe_ingredient'),
                'permission_callback' => array($this, 'check_manage_recipes_permission'),
            )
        ));

        // Custo do prato
        register_rest_route(self::API_NAMESPACE, '/recipes/(?P<product_id>\d+)/cost', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_recipe_cost'),
            'permission_callback' => array($this, 'check_view_permission'),
        ));

        // Baixa de estoque da receita
        register_rest_route(self::API_NAMESPACE, '/recipes/(?P<product_id>\d+)/deduct', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'deduct_recipe_stock'),
            'permission_callback' => array($this, 'check_production_permission'),
        ));

        // PRODUÇÃO: Consome ingredientes + cria lote do produto final (v2.5)
        register_rest_route(self::API_NAMESPACE, '/recipes/(?P<product_id>\d+)/produce', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'produce_product'),
            'permission_callback' => array($this, 'check_production_permission'),
        ));

        // ============================================
        // Rastreamento de Perdas (v2.7.0)
        // ============================================

        // POST /stock/losses - Registrar perda
        register_rest_route(self::API_NAMESPACE, '/stock/losses', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'register_loss'),
                'permission_callback' => array($this, 'check_loss_permission'),
            ),
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_losses'),
                'permission_callback' => array($this, 'check_view_permission'),
                'args' => array(
                    'product_id' => array(
                        'default' => 0,
                        'validate_callback' => function ($param) {
                            return is_numeric($param);
                        }
                    ),
                    'reason' => array(
                        'default' => '',
                        'sanitize_callback' => 'sanitize_text_field'
                    ),
                    'start_date' => array(
                        'default' => '',
                        'sanitize_callback' => 'sanitize_text_field'
                    ),
                    'end_date' => array(
                        'default' => '',
                        'sanitize_callback' => 'sanitize_text_field'
                    ),
                    'limit' => array(
                        'default' => 50,
                        'validate_callback' => function ($param) {
                            return is_numeric($param) && $param > 0 && $param <= 200;
                        }
                    )
                )
            )
        ));

        // GET /stock/losses/summary - Resumo de perdas para CMV
        register_rest_route(self::API_NAMESPACE, '/stock/losses/summary', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_losses_summary'),
            'permission_callback' => array($this, 'check_view_permission'),
            'args' => array(
                'start_date' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'end_date' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));

        // ============================================
        // Inventário Cego / Blind Count (v2.8.0)
        // ============================================

        // POST /stock/blind-inventory/start - Iniciar nova sessão
        register_rest_route(self::API_NAMESPACE, '/stock/blind-inventory/start', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'start_blind_inventory'),
            'permission_callback' => array($this, 'check_manage_stock_permission'),
        ));

        // POST /stock/blind-inventory/(?P<id>\d+)/add - Adicionar item à sessão (v2.12.0)
        register_rest_route(self::API_NAMESPACE, '/stock/blind-inventory/(?P<id>\d+)/add', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'add_blind_inventory_item'),
            'permission_callback' => array($this, 'check_manage_stock_permission'),
        ));

        // GET /stock/blind-inventory/{session_id} - Listar itens da sessão para preenchimento
        register_rest_route(self::API_NAMESPACE, '/stock/blind-inventory/(?P<session_id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_blind_inventory_session'),
            'permission_callback' => array($this, 'check_manage_stock_permission'),
        ));

        // PUT /stock/blind-inventory/{session_id}/item/{product_id} - Atualizar quantidade física
        register_rest_route(self::API_NAMESPACE, '/stock/blind-inventory/(?P<session_id>\d+)/item/(?P<product_id>\d+)', array(
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => array($this, 'update_blind_inventory_item'),
            'permission_callback' => array($this, 'check_manage_stock_permission'),
        ));

        // POST /stock/blind-inventory/{session_id}/submit - Processar divergências
        register_rest_route(self::API_NAMESPACE, '/stock/blind-inventory/(?P<session_id>\d+)/submit', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'submit_blind_inventory'),
            'permission_callback' => array($this, 'check_manage_stock_permission'),
        ));

        // GET /stock/blind-inventory/{session_id}/report - Relatório de divergências
        register_rest_route(self::API_NAMESPACE, '/stock/blind-inventory/(?P<session_id>\d+)/report', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_blind_inventory_report'),
            'permission_callback' => array($this, 'check_manage_stock_permission'),
        ));

        // GET /stock/blind-inventory - Listar sessões
        register_rest_route(self::API_NAMESPACE, '/stock/blind-inventory', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_blind_inventory_sessions'),
            'permission_callback' => array($this, 'check_manage_stock_permission'),
        ));

        // ============================================
        // Wizard Setor-a-Setor (v2.15.0)
        // ============================================

        // GET /stock/blind-inventory/{id}/current-sector - Itens do setor atual
        register_rest_route(self::API_NAMESPACE, '/stock/blind-inventory/(?P<id>\d+)/current-sector', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_current_sector_items'),
            'permission_callback' => array($this, 'check_manage_stock_permission'),
        ));

        // POST /stock/blind-inventory/{id}/advance - Avançar para próximo setor
        register_rest_route(self::API_NAMESPACE, '/stock/blind-inventory/(?P<id>\d+)/advance', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'advance_to_next_sector'),
            'permission_callback' => array($this, 'check_manage_stock_permission'),
        ));

        // ============================================
        // Relatório de Gestão CMV/DRE (v2.9.0)
        // ============================================

        // GET /stock/reports/cmv - Relatório de CMV por período
        register_rest_route(self::API_NAMESPACE, '/stock/reports/cmv', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_cmv_report'),
            'permission_callback' => array($this, 'check_view_permission'),
            'args' => array(
                'start_date' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'end_date' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));

        // GET /stock/reports/dre - Relatório DRE simplificado
        register_rest_route(self::API_NAMESPACE, '/stock/reports/dre', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_dre_report'),
            'permission_callback' => array($this, 'check_view_permission'),
            'args' => array(
                'start_date' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'end_date' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));

        // ============================================
        // Faturamento Macro / Fechar Caixa (v2.16.0)
        // ============================================

        // POST /stock/revenue - Registrar faturamento diário
        register_rest_route(self::API_NAMESPACE, '/stock/revenue', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'create_macro_revenue'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args' => array(
                'revenue_date' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($v) {
                        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
                    }
                ),
                'total_amount' => array(
                    'required' => true,
                    'validate_callback' => function ($v) {
                        return is_numeric($v) && (float) $v > 0; }
                ),
                'category' => array(
                    'required' => false,
                    'default' => 'Geral',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'notes' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_textarea_field'
                )
            )
        ));

        // GET /stock/revenue - Listar faturamentos por período
        register_rest_route(self::API_NAMESPACE, '/stock/revenue', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_macro_revenue'),
            'permission_callback' => array($this, 'check_view_permission'),
            'args' => array(
                'start_date' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'end_date' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));

        // DELETE /stock/revenue/{id} - Remover lançamento
        register_rest_route(self::API_NAMESPACE, '/stock/revenue/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => array($this, 'delete_macro_revenue'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));
    }


    /**
     * Verifica permissão de administrador
     */
    public function check_admin_permission()
    {
        return current_user_can('manage_options');
    }

    /**
     * Verifica permissão de funcionário ou admin
     * 
     * @param string $capability Capability do WP para admins
     * @param string $module Módulo do sistema (ex: 'cmv', 'inventory')
     * @param string $action Ação (ex: 'view', 'manage')
     * @return bool
     */
    public function check_permission($capability, $module, $action)
    {
        // 1. Admin do WP sempre pode (se tiver a capability)
        if (current_user_can($capability)) {
            return true;
        }

        // 2. Verificar sessão de funcionário
        if (!class_exists('SISTUR_Session') || !class_exists('SISTUR_Permissions')) {
            return false;
        }

        $session = SISTUR_Session::get_instance();
        if (!$session->is_employee_logged_in()) {
            return false;
        }

        $employee_id = $session->get_employee_id();
        $permissions = SISTUR_Permissions::get_instance();

        return $permissions->can($employee_id, $module, $action);
    }

    /**
     * Verifica permissão de visualização (GET)
     * Permite: inventory.view, cmv.manage_products, cmv.view_costs
     */
    public function check_view_permission()
    {
        // Admin
        if (current_user_can('manage_options'))
            return true;

        // Funcionário
        if (!class_exists('SISTUR_Session'))
            return false;
        $session = SISTUR_Session::get_instance();
        if (!$session->is_employee_logged_in())
            return false;

        $permissions = SISTUR_Permissions::get_instance();
        $employee_id = $session->get_employee_id();

        // Qualquer uma dessas permite visualizar
        return $permissions->can($employee_id, 'inventory', 'view') ||
            $permissions->can($employee_id, 'cmv', 'manage_products') ||
            $permissions->can($employee_id, 'cmv', 'view_costs') ||
            $permissions->can($employee_id, 'cmv', 'produce');
    }

    /**
     * Verifica permissão de gerenciar produtos (POST/PUT/DELETE)
     * Permite: cmv.manage_products, inventory.manage
     */
    public function check_manage_products_permission()
    {
        return $this->check_permission('manage_options', 'cmv', 'manage_products') ||
            $this->check_permission('manage_options', 'inventory', 'manage');
    }

    /**
     * Verifica permissão de gerenciar receitas
     */
    public function check_manage_recipes_permission()
    {
        return $this->check_permission('manage_options', 'cmv', 'manage_recipes');
    }

    /**
     * Verifica permissão de produção
     */
    public function check_production_permission()
    {
        return $this->check_permission('manage_options', 'cmv', 'produce');
    }

    /**
     * Verifica permissão de movimentação (Entrada/Saída manual)
     */
    public function check_movement_permission($request)
    {
        $params = $request->get_json_params();
        $type = isset($params['type']) ? strtoupper($params['type']) : '';

        // Se for venda, checar permissão de venda
        if ($type === 'SALE') {
            return $this->check_permission('manage_options', 'inventory', 'record_sale');
        }

        // Se for perda, checar permissão de perda
        if ($type === 'LOSS') {
            return $this->check_permission('manage_options', 'inventory', 'request_loss');
        }

        // Outros (entrada manual, ajuste) requer gerenciamento
        return $this->check_permission('manage_options', 'inventory', 'manage');
    }

    /**
     * Verifica permissão de registrar perda
     */
    public function check_loss_permission()
    {
        return $this->check_permission('manage_options', 'inventory', 'request_loss');
    }

    /**
     * Verifica permissão de configurações/gerenciamento avançado
     */
    public function check_manage_settings_permission()
    {
        return $this->check_permission('manage_options', 'inventory', 'manage');
    }

    /**
     * Verifica permissão de gerenciamento de estoque (Inventário Cego, etc)
     */
    public function check_manage_stock_permission()
    {
        return $this->check_permission('manage_options', 'inventory', 'manage');
    }

    /**
     * GET /stock/products - Lista produtos com paginação
     */
    public function get_products($request)
    {
        global $wpdb;

        $page = (int) $request->get_param('page');
        $per_page = (int) $request->get_param('per_page');
        $search = $request->get_param('search');
        $type = $request->get_param('type');
        $offset = ($page - 1) * $per_page;

        $where = array("1=1");
        $params = array();

        if (!empty($search)) {
            $where[] = "(p.name LIKE %s OR p.sku LIKE %s OR p.barcode LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }

        // v2.6 - Filter by type using FIND_IN_SET for SET column
        if (!empty($type)) {
            $where[] = "FIND_IN_SET(%s, p.type) > 0";
            $params[] = $type;
        }

        // v2.6 - Filter by has_type (products that CONTAIN this type in their SET)
        // Used to find products that can be ingredients (has RAW in their type)
        $has_type = $request->get_param('has_type');
        if (!empty($has_type)) {
            $where[] = "FIND_IN_SET(%s, p.type) > 0";
            $params[] = $has_type;
        }

        // v2.6 - Exclude products containing specific type
        $exclude_type = $request->get_param('exclude_type');
        if (!empty($exclude_type)) {
            $where[] = "FIND_IN_SET(%s, p.type) = 0";
            $params[] = $exclude_type;
        }

        // v2.4 - Filter by resource_parent_id (to find children of a RESOURCE)
        $resource_parent_id = $request->get_param('resource_parent_id');
        if (!empty($resource_parent_id)) {
            $where[] = "p.resource_parent_id = %d";
            $params[] = (int) $resource_parent_id;
        }

        $where_sql = implode(' AND ', $where);

        // Count total
        $count_sql = "SELECT COUNT(*) FROM {$this->prefix}products p WHERE {$where_sql}";
        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, $params);
        }
        $total = (int) $wpdb->get_var($count_sql);

        // Get products
        $sql = "SELECT p.*,
                       u.name as unit_name,
                       u.symbol as unit_symbol,
                       c.name as category_name,
                       COALESCE(SUM(b.quantity), 0) as real_stock,
                       sec.name as default_sector_name,
                       sec_parent.name as default_location_name,
                       COALESCE(parent.is_perishable, 1) as parent_is_perishable
                FROM {$this->prefix}products p
                LEFT JOIN {$this->prefix}units u ON p.base_unit_id = u.id
                LEFT JOIN {$this->prefix}product_categories c ON p.category_id = c.id
                LEFT JOIN {$this->prefix}inventory_batches b ON p.id = b.product_id AND b.status = 'active'
                LEFT JOIN {$this->prefix}storage_locations sec ON p.default_sector_id = sec.id
                LEFT JOIN {$this->prefix}storage_locations sec_parent ON sec.parent_id = sec_parent.id
                LEFT JOIN {$this->prefix}products parent ON p.resource_parent_id = parent.id
                WHERE {$where_sql}
                GROUP BY p.id
                ORDER BY p.name ASC
                LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;
        $products = $wpdb->get_results($wpdb->prepare($sql, $params));

        return new WP_REST_Response(array(
            'products' => $products,
            'total' => $total,
            'total_pages' => ceil($total / $per_page),
            'page' => $page,
            'per_page' => $per_page
        ), 200);
    }

    /**
     * GET /stock/products/{id} - Obter produto específico
     */
    public function get_product($request)
    {
        global $wpdb;

        $id = (int) $request->get_param('id');

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*,
                    u.name as unit_name,
                    u.symbol as unit_symbol,
                    c.name as category_name,
                    sec.name as default_sector_name,
                    sec_parent.name as default_location_name,
                    COALESCE(parent.is_perishable, 1) as parent_is_perishable
             FROM {$this->prefix}products p
             LEFT JOIN {$this->prefix}units u ON p.base_unit_id = u.id
             LEFT JOIN {$this->prefix}product_categories c ON p.category_id = c.id
             LEFT JOIN {$this->prefix}storage_locations sec ON p.default_sector_id = sec.id
             LEFT JOIN {$this->prefix}storage_locations sec_parent ON sec.parent_id = sec_parent.id
             LEFT JOIN {$this->prefix}products parent ON p.resource_parent_id = parent.id
             WHERE p.id = %d",
            $id
        ));

        if (!$product) {
            return new WP_Error('not_found', 'Produto não encontrado', array('status' => 404));
        }

        // Obter lotes ativos
        $product->batches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->prefix}inventory_batches 
             WHERE product_id = %d AND status = 'active' AND quantity > 0
             ORDER BY created_at ASC",
            $id
        ));

        // v2.13.0 - Perishability Inheritance logic
        $product->effective_perishable = ($product->resource_parent_id > 0)
            ? $product->parent_is_perishable
            : $product->is_perishable;

        $product->is_inherited = ($product->resource_parent_id > 0);

        return new WP_REST_Response($product, 200);
    }

    /**
     * POST /stock/products - Criar novo produto
     */
    public function create_product($request)
    {
        global $wpdb;

        $data = $request->get_json_params();

        // Validações
        if (empty($data['name'])) {
            return new WP_Error('invalid_data', 'Nome é obrigatório', array('status' => 400));
        }

        // Verificar SKU duplicado
        if (!empty($data['sku'])) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->prefix}products WHERE sku = %s",
                $data['sku']
            ));
            if ($exists) {
                return new WP_Error('duplicate_sku', 'SKU já existe', array('status' => 400));
            }
        }

        // CRÍTICO: Validar base_unit_id - OBRIGATÓRIO
        if (empty($data['base_unit_id'])) {
            return new WP_Error('missing_unit', 'Unidade base é obrigatória. Selecione uma unidade de medida.', array('status' => 400));
        }

        $base_unit_id = (int) $data['base_unit_id'];

        // Verificar se a unidade existe na tabela units
        $unit_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->prefix}units WHERE id = %d",
            $base_unit_id
        ));

        if (!$unit_exists) {
            return new WP_Error('invalid_unit', 'Unidade de medida inválida. Selecione uma unidade cadastrada.', array('status' => 400));
        }

        $insert_data = array(
            'name' => sanitize_text_field($data['name']),
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : null,
            'sku' => isset($data['sku']) ? sanitize_text_field($data['sku']) : null,
            'barcode' => isset($data['barcode']) ? sanitize_text_field($data['barcode']) : null,
            // v2.6 - Suporte a múltiplos tipos (SET): aceita array ou string
            'type' => $this->normalize_product_types($data['type'] ?? 'RAW'),
            'base_unit_id' => $base_unit_id,
            'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
            'cost_price' => isset($data['cost_price']) ? (float) $data['cost_price'] : 0,
            'selling_price' => isset($data['selling_price']) ? (float) $data['selling_price'] : 0,
            'min_stock' => isset($data['min_stock']) ? (float) $data['min_stock'] : 0,
            // Campos de conteúdo da embalagem (v2.3)
            'content_quantity' => isset($data['content_quantity']) && $data['content_quantity'] !== ''
                ? (float) $data['content_quantity'] : null,
            'content_unit_id' => isset($data['content_unit_id']) && $data['content_unit_id'] !== ''
                ? (int) $data['content_unit_id'] : null,
            // Hierarquia de RESOURCE (v2.4)
            'resource_parent_id' => isset($data['resource_parent_id']) && $data['resource_parent_id'] !== ''
                ? (int) $data['resource_parent_id'] : null,
            // Setor padrão (v2.11.0)
            'default_sector_id' => isset($data['default_sector_id']) && $data['default_sector_id'] !== ''
                ? (int) $data['default_sector_id'] : null,
            'is_perishable' => isset($data['is_perishable']) ? (int) $data['is_perishable'] : 1, // v2.13.0
            'status' => 'active'
        );

        $result = $wpdb->insert($this->prefix . 'products', $insert_data);

        if ($result === false) {
            return new WP_Error('db_error', 'Erro ao criar produto: ' . $wpdb->last_error, array('status' => 500));
        }

        $product_id = $wpdb->insert_id;

        // Log de auditoria
        $this->log_transaction($product_id, null, 'ADJUST', 0, 'Produto criado');

        return new WP_REST_Response(array(
            'success' => true,
            'id' => $product_id,
            'message' => 'Produto criado com sucesso'
        ), 201);
    }

    /**
     * PUT /stock/products/{id} - Atualizar produto
     */
    public function update_product($request)
    {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $data = $request->get_json_params();

        // Verificar se existe
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->prefix}products WHERE id = %d",
            $id
        ));

        if (!$product) {
            return new WP_Error('not_found', 'Produto não encontrado', array('status' => 404));
        }

        // CRÍTICO: Verificar se está tentando mudar base_unit_id
        if (isset($data['base_unit_id']) && (int) $data['base_unit_id'] !== (int) $product->base_unit_id) {
            // Verificar se tem estoque
            $has_stock = (float) $product->cached_stock > 0;

            if ($has_stock) {
                return new WP_Error(
                    'unit_locked',
                    'Não é possível alterar a unidade base de um produto com estoque. Zere o estoque primeiro.',
                    array('status' => 400)
                );
            }

            // Validar nova unidade
            $new_unit_id = (int) $data['base_unit_id'];
            $unit_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->prefix}units WHERE id = %d",
                $new_unit_id
            ));

            if (!$unit_exists) {
                return new WP_Error('invalid_unit', 'Nova unidade de medida inválida.', array('status' => 400));
            }
        }

        $update_data = array();
        $allowed_fields = array(
            'name',
            'description',
            'sku',
            'barcode',
            'type',
            'base_unit_id',
            'category_id',
            'cost_price',
            'selling_price',
            'min_stock',
            'status',
            'content_quantity',
            'content_unit_id',
            'resource_parent_id',
            'default_sector_id',
            'is_perishable' // v2.13.0
        );

        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                if (in_array($field, array('name', 'description', 'sku', 'barcode', 'status'))) {
                    $update_data[$field] = sanitize_text_field($data[$field]);
                } elseif ($field === 'type') {
                    // v2.6 - Suporte a múltiplos tipos (SET)
                    $update_data[$field] = $this->normalize_product_types($data[$field]);
                } else {
                    $update_data[$field] = is_numeric($data[$field]) ? $data[$field] : null;
                }
            }
        }

        if (empty($update_data)) {
            return new WP_Error('no_data', 'Nenhum dado para atualizar', array('status' => 400));
        }

        $result = $wpdb->update($this->prefix . 'products', $update_data, array('id' => $id));

        if ($result === false) {
            return new WP_Error('db_error', 'Erro ao atualizar produto', array('status' => 500));
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Produto atualizado com sucesso'
        ), 200);
    }

    /**
     * POST /stock/movements - Criar movimentação (CRÍTICO: FIFO para saídas)
     * 
     * IMPORTANTE: Aceita unit_id opcional. Se fornecido e diferente da unidade base,
     * a quantidade será convertida para a unidade base antes de salvar.
     */
    public function create_movement($request)
    {
        global $wpdb;

        $data = $request->get_json_params();

        // Validações
        if (empty($data['product_id']) || empty($data['type']) || !isset($data['quantity'])) {
            return new WP_Error('invalid_data', 'product_id, type e quantity são obrigatórios', array('status' => 400));
        }

        $product_id = (int) $data['product_id'];
        $type = strtoupper($data['type']);
        $original_quantity = abs((float) $data['quantity']);
        $input_unit_id = isset($data['unit_id']) ? (int) $data['unit_id'] : null;
        $location_id = isset($data['location_id']) ? (int) $data['location_id'] : null;
        $cost_price = isset($data['cost_price']) ? (float) $data['cost_price'] : 0;
        $reason = isset($data['reason']) ? sanitize_text_field($data['reason']) : null;
        $batch_code = isset($data['batch_code']) ? sanitize_text_field($data['batch_code']) : null;
        $expiry_date = isset($data['expiry_date']) ? sanitize_text_field($data['expiry_date']) : null;

        // Validar tipo
        $valid_types = array('IN', 'OUT', 'SALE', 'LOSS', 'ADJUST', 'TRANSFER');
        if (!in_array($type, $valid_types)) {
            return new WP_Error('invalid_type', 'Tipo inválido. Use: ' . implode(', ', $valid_types), array('status' => 400));
        }

        // Verificar se o produto existe
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, u.name as unit_name, u.symbol as unit_symbol 
             FROM {$this->prefix}products p
             LEFT JOIN {$this->prefix}units u ON p.base_unit_id = u.id
             WHERE p.id = %d",
            $product_id
        ));

        if (!$product) {
            return new WP_Error('not_found', 'Produto não encontrado', array('status' => 404));
        }

        // Verificar se produto tem unidade base definida
        if (empty($product->base_unit_id)) {
            return new WP_Error('no_base_unit', 'Produto não possui unidade base definida.', array('status' => 400));
        }

        $base_unit_id = (int) $product->base_unit_id;

        // =====================================
        // CONVERSÃO DE UNIDADE (CRÍTICO!)
        // =====================================
        $quantity_in_base = $original_quantity;
        $conversion_applied = false;
        $conversion_details = null;

        // Se foi informado unit_id e é diferente da unidade base
        if ($input_unit_id && $input_unit_id !== $base_unit_id) {
            $converter = Sistur_Unit_Converter::get_instance();

            // Validar que a unidade de entrada existe
            $unit_valid = $converter->validate_unit($input_unit_id);
            if (is_wp_error($unit_valid)) {
                return $unit_valid;
            }

            // Converter para unidade base
            $converted = $converter->convert($original_quantity, $input_unit_id, $base_unit_id, $product_id);

            if (is_wp_error($converted)) {
                return $converted;
            }

            $quantity_in_base = $converted;
            $conversion_applied = true;

            // Buscar nome da unidade de entrada para log
            $input_unit = $wpdb->get_row($wpdb->prepare(
                "SELECT name, symbol FROM {$this->prefix}units WHERE id = %d",
                $input_unit_id
            ));

            $conversion_details = sprintf(
                'Convertido: %.3f %s → %.3f %s',
                $original_quantity,
                $input_unit ? $input_unit->symbol : $input_unit_id,
                $quantity_in_base,
                $product->unit_symbol ?: $base_unit_id
            );

            // Adicionar à razão
            if ($reason) {
                $reason .= ' | ' . $conversion_details;
            } else {
                $reason = $conversion_details;
            }
        }

        // Iniciar transação
        $wpdb->query('START TRANSACTION');

        try {
            if ($type === 'IN') {
                // ENTRADA: Criar novo lote (SEMPRE na unidade base!)
                $result = $this->process_entry($product_id, $quantity_in_base, $cost_price, $location_id, $batch_code, $expiry_date, $reason);
            } else {
                // SAÍDA: Consumir lotes via FIFO (SEMPRE na unidade base!)
                $result = $this->process_exit($product_id, $quantity_in_base, $type, $location_id, $reason);
            }

            if (is_wp_error($result)) {
                $wpdb->query('ROLLBACK');
                return $result;
            }

            // Atualizar cached_stock no produto
            $this->update_cached_stock($product_id);

            // Se for entrada, recalcular custo médio
            if ($type === 'IN') {
                $this->recalculate_average_cost($product_id);
            }

            $wpdb->query('COMMIT');

            // Após COMMIT: propagar mudança de custo para Pratos BASE e Pratos Finais
            // que usam este produto como ingrediente (v2.18.0 - cascata de CMV).
            if ($type === 'IN') {
                $recipe_manager = Sistur_Recipe_Manager::get_instance();
                $recipe_manager->cascade_cost_recalculation($product_id);
            }

            // Adicionar detalhes de conversão ao resultado
            $response_data = array(
                'success' => true,
                'message' => 'Movimentação registrada com sucesso',
                'details' => $result
            );

            if ($conversion_applied) {
                $response_data['conversion'] = array(
                    'applied' => true,
                    'original_quantity' => $original_quantity,
                    'original_unit_id' => $input_unit_id,
                    'converted_quantity' => $quantity_in_base,
                    'base_unit_id' => $base_unit_id,
                    'description' => $conversion_details
                );
            }

            return new WP_REST_Response($response_data, 201);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('transaction_error', $e->getMessage(), array('status' => 500));
        }
    }

    /**
     * POST /stock/transfer - Transferir estoque entre locais (v2.10.0)
     */
    public function transfer_stock($request)
    {
        global $wpdb;

        $data = $request->get_json_params();

        // Validações
        if (empty($data['from_location_id']) || empty($data['to_location_id']) || empty($data['products'])) {
            return new WP_Error('invalid_data', 'Origem, destino e produtos são obrigatórios', array('status' => 400));
        }

        $from_location_id = (int) $data['from_location_id'];
        $to_location_id = (int) $data['to_location_id'];
        $products = $data['products']; // Array de {product_id, quantity}
        $reason = isset($data['reason']) ? sanitize_text_field($data['reason']) : 'Transferência entre estoques';
        $employee_id = get_current_user_id(); // Ou pegar do funcionário logado se necessário

        if ($from_location_id === $to_location_id) {
            return new WP_Error('invalid_location', 'Origem e destino devem ser diferentes', array('status' => 400));
        }

        if (!is_array($products) || empty($products)) {
            return new WP_Error('border_error', 'Lista de produtos inválida', array('status' => 400));
        }

        // Iniciar transação
        $wpdb->query('START TRANSACTION');

        try {
            foreach ($products as $item) {
                if (empty($item['product_id']) || empty($item['quantity'])) {
                    continue; // Pular itens inválidos
                }

                $product_id = (int) $item['product_id'];
                $quantity = abs((float) $item['quantity']);

                // 1. Processar SAÍDA na origem
                $exit_result = $this->process_exit($product_id, $quantity, 'TRANSFER', $from_location_id, $reason . ' (Saída para Local #' . $to_location_id . ')');

                if (is_wp_error($exit_result)) {
                    throw new Exception($exit_result->get_error_message(), $exit_result->get_error_data()['status'] ?? 400);
                }

                // 2. Processar ENTRADA no destino - um lote por lote consumido para preservar rastreabilidade
                foreach ($exit_result['batches_affected'] as $consumed_batch) {
                    $trf_batch_code = 'TRF-' . $consumed_batch['batch_code'];

                    $entry_result = self::register_entry(
                        $product_id,
                        $consumed_batch['quantity_consumed'],
                        (float) $consumed_batch['cost_price'],
                        $consumed_batch['expiry_date'],
                        $to_location_id,
                        $reason . ' (Entrada de Local #' . $from_location_id . ')',
                        null,
                        $trf_batch_code,
                        $consumed_batch['acquisition_location'],
                        null,
                        $consumed_batch['manufacturing_date']
                    );

                    if (is_wp_error($entry_result)) {
                        throw new Exception('Erro na entrada do lote ' . $consumed_batch['batch_code'] . ': ' . $entry_result->get_error_message(), 500);
                    }
                }
            }

            $wpdb->query('COMMIT');

            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Transferência realizada com sucesso'
            ), 200);

        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');

            // Log for debugging
            error_log('SISTUR Transfer Stock Error: ' . $e->getMessage());

            $status = 500;
            if ($e->getCode() >= 400 && $e->getCode() < 600) {
                $status = $e->getCode();
            }

            return new WP_Error('transfer_error', $e->getMessage(), array('status' => $status));
        }
    }

    private function process_entry($product_id, $quantity, $cost_price, $location_id, $batch_code, $expiry_date, $reason)
    {
        global $wpdb;

        // Detectar se location_id é um setor (tem parent_id) e separar location/sector
        $sector_id = null;
        if ($location_id) {
            $loc_info = $wpdb->get_row($wpdb->prepare(
                "SELECT id, parent_id FROM {$this->prefix}storage_locations WHERE id = %d",
                $location_id
            ));
            if ($loc_info && $loc_info->parent_id) {
                $sector_id = $location_id;
                $location_id = (int) $loc_info->parent_id;
            }
        }

        // Criar novo lote
        $batch_data = array(
            'product_id' => $product_id,
            'location_id' => $location_id,
            'sector_id' => $sector_id,
            'batch_code' => $batch_code,
            'expiry_date' => $expiry_date ?: null,
            'cost_price' => $cost_price,
            'quantity' => $quantity,
            'initial_quantity' => $quantity,
            'status' => 'active'
        );

        $result = $wpdb->insert($this->prefix . 'inventory_batches', $batch_data);

        if ($result === false) {
            return new WP_Error('db_error', 'Erro ao criar lote: ' . $wpdb->last_error, array('status' => 500));
        }

        $batch_id = $wpdb->insert_id;

        // Registrar transação
        $this->log_transaction($product_id, $batch_id, 'IN', $quantity, $reason, $cost_price, $location_id);

        return array(
            'batch_id' => $batch_id,
            'quantity_added' => $quantity
        );
    }

    /**
     * Processa saída de estoque (FIFO - consome lotes mais antigos primeiro)
     */
    private function process_exit($product_id, $quantity, $type, $location_id, $reason)
    {
        global $wpdb;

        // Detectar se location_id é um setor (tem parent_id) e filtrar corretamente
        $where_location = "";
        if ($location_id) {
            $loc_info = $wpdb->get_row($wpdb->prepare(
                "SELECT id, parent_id FROM {$this->prefix}storage_locations WHERE id = %d",
                $location_id
            ));
            if ($loc_info && $loc_info->parent_id) {
                // É um setor - filtrar por sector_id
                $where_location = $wpdb->prepare(" AND sector_id = %d", $location_id);
            } else {
                // É um local pai - filtrar por location_id
                $where_location = $wpdb->prepare(" AND location_id = %d", $location_id);
            }
        }

        $batches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->prefix}inventory_batches 
             WHERE product_id = %d 
             AND status = 'active' 
             AND quantity > 0
             {$where_location}
             ORDER BY created_at ASC",
            $product_id
        ));

        // Calcular saldo disponível
        $available = array_sum(array_column($batches, 'quantity'));

        if ($available < $quantity) {
            return new WP_Error(
                'insufficient_stock',
                sprintf('Estoque insuficiente. Disponível: %.3f, Solicitado: %.3f', $available, $quantity),
                array('status' => 400)
            );
        }

        $remaining = $quantity;
        $consumed = array();

        foreach ($batches as $batch) {
            if ($remaining <= 0)
                break;

            $to_consume = min($batch->quantity, $remaining);
            $new_quantity = $batch->quantity - $to_consume;

            // Atualizar lote
            $update = array('quantity' => $new_quantity);
            if ($new_quantity <= 0) {
                $update['status'] = 'depleted';
            }

            $wpdb->update(
                $this->prefix . 'inventory_batches',
                $update,
                array('id' => $batch->id)
            );

            // Registrar transação para este lote
            $this->log_transaction(
                $product_id,
                $batch->id,
                $type,
                -$to_consume,
                $reason,
                $batch->cost_price,
                $location_id
            );

            $consumed[] = array(
                'batch_id' => $batch->id,
                'batch_code' => $batch->batch_code,
                'quantity_consumed' => $to_consume,
                'batch_remaining' => $new_quantity,
                'expiry_date' => $batch->expiry_date,
                'cost_price' => $batch->cost_price,
                'acquisition_location' => $batch->acquisition_location,
                'manufacturing_date' => $batch->manufacturing_date,
                'entry_date' => $batch->entry_date,
            );

            $remaining -= $to_consume;
        }

        return array(
            'total_consumed' => $quantity,
            'batches_affected' => $consumed
        );
    }

    /**
     * Registra transação no livro razão
     */
    private function log_transaction($product_id, $batch_id, $type, $quantity, $reason = null, $unit_cost = null, $location_id = null)
    {
        global $wpdb;

        $wpdb->insert($this->prefix . 'inventory_transactions', array(
            'product_id' => $product_id,
            'batch_id' => $batch_id,
            'user_id' => get_current_user_id(),
            'type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $unit_cost,
            'total_cost' => $unit_cost ? abs($quantity) * $unit_cost : null,
            'reason' => $reason,
            'to_location_id' => $location_id,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ));
    }

    /**
     * Atualiza o estoque cacheado do produto
     */
    private function update_cached_stock($product_id)
    {
        global $wpdb;

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(quantity), 0) 
             FROM {$this->prefix}inventory_batches 
             WHERE product_id = %d AND status = 'active'",
            $product_id
        ));

        $wpdb->update(
            $this->prefix . 'products',
            array('cached_stock' => $total),
            array('id' => $product_id)
        );
    }

    /**
     * Recalcula o custo médio ponderado
     */
    private function recalculate_average_cost($product_id)
    {
        global $wpdb;

        // Custo médio móvel baseado em todas as entradas históricas (tipo IN)
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(ABS(quantity)) as total_qty,
                    SUM(ABS(quantity) * unit_cost) as total_value
             FROM {$this->prefix}inventory_transactions
             WHERE product_id = %d AND type = 'IN' AND unit_cost > 0",
            $product_id
        ));

        if ($result && $result->total_qty > 0) {
            $average_cost = $result->total_value / $result->total_qty;

            $wpdb->update(
                $this->prefix . 'products',
                array('average_cost' => $average_cost),
                array('id' => $product_id)
            );
        }
    }

    /**
     * GET /stock/batches/{product_id} - Lista lotes de um produto
     */
    public function get_product_batches($request)
    {
        global $wpdb;

        $product_id = (int) $request->get_param('product_id');

        // v2.15.3: Incluir sector_name na resposta
        $batches = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*,
                    l.name as location_name,
                    s.name as sector_name,
                    CASE
                        WHEN s.id IS NOT NULL AND l.id IS NOT NULL THEN CONCAT(l.name, ' - ', s.name)
                        WHEN s.id IS NOT NULL THEN s.name
                        WHEN l.id IS NOT NULL THEN l.name
                        ELSE NULL
                    END as full_location_name
             FROM {$this->prefix}inventory_batches b
             LEFT JOIN {$this->prefix}storage_locations l ON b.location_id = l.id
             LEFT JOIN {$this->prefix}storage_locations s ON b.sector_id = s.id
             WHERE b.product_id = %d
             ORDER BY b.created_at ASC",
            $product_id
        ));

        return new WP_REST_Response($batches, 200);
    }

    /**
     * GET /stock/units - Lista unidades de medida
     */
    public function get_units($request)
    {
        global $wpdb;

        $units = $wpdb->get_results(
            "SELECT * FROM {$this->prefix}units ORDER BY name ASC"
        );

        return new WP_REST_Response($units, 200);
    }

    /**
     * GET /stock/locations - Lista locais de armazenamento com setores
     */
    public function get_locations($request)
    {
        global $wpdb;

        $all = $wpdb->get_results(
            "SELECT * FROM {$this->prefix}storage_locations 
             WHERE is_active = 1 
             ORDER BY parent_id ASC, name ASC"
        );

        $default_id = (int) get_option('sistur_stock_default_location_id', 0);

        // Separar locais (parent_id IS NULL) e setores (parent_id IS NOT NULL)
        $locations = array();
        $sectors = array();

        foreach ($all as $item) {
            $item->is_default = ((int) $item->id === $default_id);
            if (empty($item->parent_id)) {
                $item->sectors = array();
                $locations[$item->id] = $item;
            } else {
                $sectors[] = $item;
            }
        }

        // Agrupar setores nos seus locais pai
        foreach ($sectors as $sector) {
            if (isset($locations[$sector->parent_id])) {
                $locations[$sector->parent_id]->sectors[] = $sector;
            }
        }

        return new WP_REST_Response(array_values($locations), 200);
    }

    /**
     * GET /stock/transactions - Lista transações
     */
    public function get_transactions($request)
    {
        global $wpdb;

        $product_id = (int) $request->get_param('product_id');
        $limit = (int) $request->get_param('limit');

        $where = "1=1";
        $params = array();

        if ($product_id > 0) {
            $where .= " AND t.product_id = %d";
            $params[] = $product_id;
        }

        $params[] = $limit;

        $transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, 
                    p.name as product_name,
                    b.batch_code,
                    u.display_name as user_name
             FROM {$this->prefix}inventory_transactions t
             LEFT JOIN {$this->prefix}products p ON t.product_id = p.id
             LEFT JOIN {$this->prefix}inventory_batches b ON t.batch_id = b.id
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
             WHERE {$where}
             ORDER BY t.created_at DESC
             LIMIT %d",
            $params
        ));

        return new WP_REST_Response($transactions, 200);
    }

    // ===================================================================
    // CRUD para Unidades de Medida
    // ===================================================================

    /**
     * POST /stock/units - Criar nova unidade
     */
    public function create_unit($request)
    {
        global $wpdb;

        $data = $request->get_json_params();

        if (empty($data['name']) || empty($data['symbol'])) {
            return new WP_Error('invalid_data', 'Nome e símbolo são obrigatórios', array('status' => 400));
        }

        // Verificar se símbolo já existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->prefix}units WHERE symbol = %s",
            $data['symbol']
        ));

        if ($exists) {
            return new WP_Error('duplicate_symbol', 'Símbolo já existe', array('status' => 400));
        }

        $result = $wpdb->insert($this->prefix . 'units', array(
            'name' => sanitize_text_field($data['name']),
            'symbol' => sanitize_text_field($data['symbol']),
            'type' => isset($data['type']) && in_array($data['type'], array('dimensional', 'unitary'))
                ? $data['type'] : 'unitary',
            'is_system' => 0
        ));

        if ($result === false) {
            return new WP_Error('db_error', 'Erro ao criar unidade', array('status' => 500));
        }

        return new WP_REST_Response(array(
            'success' => true,
            'id' => $wpdb->insert_id,
            'message' => 'Unidade criada com sucesso'
        ), 201);
    }

    /**
     * PUT /stock/units/{id} - Atualizar unidade
     */
    public function update_unit($request)
    {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $data = $request->get_json_params();

        // Verificar se existe
        $unit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->prefix}units WHERE id = %d",
            $id
        ));

        if (!$unit) {
            return new WP_Error('not_found', 'Unidade não encontrada', array('status' => 404));
        }

        $update_data = array();

        if (isset($data['name']) && !empty($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
        }

        if (isset($data['symbol']) && !empty($data['symbol'])) {
            // Verificar se símbolo já existe em outra unidade
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->prefix}units WHERE symbol = %s AND id != %d",
                $data['symbol'],
                $id
            ));

            if ($exists) {
                return new WP_Error('duplicate_symbol', 'Símbolo já existe', array('status' => 400));
            }

            $update_data['symbol'] = sanitize_text_field($data['symbol']);
        }

        if (isset($data['type'])) {
            if (in_array($data['type'], array('dimensional', 'unitary'))) {
                $update_data['type'] = $data['type'];
            }
        }

        if (empty($update_data)) {
            return new WP_Error('no_data', 'Nenhum dado para atualizar', array('status' => 400));
        }

        $result = $wpdb->update($this->prefix . 'units', $update_data, array('id' => $id));

        if ($result === false) {
            return new WP_Error('db_error', 'Erro ao atualizar unidade', array('status' => 500));
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Unidade atualizada com sucesso'
        ), 200);
    }

    /**
     * DELETE /stock/units/{id} - Excluir unidade
     */
    public function delete_unit($request)
    {
        global $wpdb;

        $id = (int) $request->get_param('id');

        // Verificar se existe
        $unit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->prefix}units WHERE id = %d",
            $id
        ));

        if (!$unit) {
            return new WP_Error('not_found', 'Unidade não encontrada', array('status' => 404));
        }

        // Verificar se é unidade de sistema
        if ($unit->is_system) {
            return new WP_Error('protected', 'Unidades de sistema não podem ser excluídas', array('status' => 403));
        }

        // Verificar se está em uso em produtos
        $in_use = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->prefix}products WHERE base_unit_id = %d",
            $id
        ));

        if ($in_use > 0) {
            return new WP_Error('in_use', 'Unidade está em uso por ' . $in_use . ' produto(s)', array('status' => 400));
        }

        $result = $wpdb->delete($this->prefix . 'units', array('id' => $id));

        if ($result === false) {
            return new WP_Error('db_error', 'Erro ao excluir unidade', array('status' => 500));
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Unidade excluída com sucesso'
        ), 200);
    }

    // ===================================================================
    // CRUD para Locais de Armazenamento
    // ===================================================================

    /**
     * POST /stock/locations - Criar novo local ou setor
     * Se parent_id for fornecido, cria um setor dentro do local
     */
    public function create_location($request)
    {
        global $wpdb;

        $data = $request->get_json_params();

        if (empty($data['name'])) {
            return new WP_Error('invalid_data', 'Nome é obrigatório', array('status' => 400));
        }

        $parent_id = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        // Se é setor, verificar se local pai existe
        if ($parent_id) {
            $parent = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->prefix}storage_locations WHERE id = %d AND is_active = 1 AND parent_id IS NULL",
                $parent_id
            ));
            if (!$parent) {
                return new WP_Error('invalid_parent', 'Local pai não encontrado', array('status' => 400));
            }
        }

        // Gerar código se não fornecido
        $code = isset($data['code']) && !empty($data['code'])
            ? sanitize_text_field($data['code'])
            : strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $data['name']), 0, 10));

        // Verificar se código já existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->prefix}storage_locations WHERE code = %s",
            $code
        ));

        if ($exists) {
            $code = $code . '-' . time();
        }

        $insert_data = array(
            'name' => sanitize_text_field($data['name']),
            'code' => $code,
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : null,
            'location_type' => isset($data['location_type']) && in_array(
                $data['location_type'],
                array('warehouse', 'freezer', 'refrigerator', 'shelf', 'other')
            )
                ? $data['location_type'] : ($parent_id ? 'shelf' : 'warehouse'),
            'parent_id' => $parent_id,
            'is_active' => 1
        );

        $result = $wpdb->insert($this->prefix . 'storage_locations', $insert_data);

        if ($result === false) {
            return new WP_Error('db_error', 'Erro ao criar local/setor', array('status' => 500));
        }

        $label = $parent_id ? 'Setor' : 'Local';
        return new WP_REST_Response(array(
            'success' => true,
            'id' => $wpdb->insert_id,
            'message' => $label . ' criado com sucesso'
        ), 201);
    }

    /**
     * PUT /stock/locations/{id} - Atualizar local
     */
    public function update_location($request)
    {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $data = $request->get_json_params();

        // Verificar se existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->prefix}storage_locations WHERE id = %d",
            $id
        ));

        if (!$exists) {
            return new WP_Error('not_found', 'Local não encontrado', array('status' => 404));
        }

        $update_data = array();
        $allowed_fields = array('name', 'code', 'description', 'location_type', 'is_active');

        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                if ($field === 'is_active') {
                    $update_data[$field] = (int) $data[$field];
                } else {
                    $update_data[$field] = sanitize_text_field($data[$field]);
                }
            }
        }

        if (empty($update_data)) {
            return new WP_Error('no_data', 'Nenhum dado para atualizar', array('status' => 400));
        }

        $result = $wpdb->update($this->prefix . 'storage_locations', $update_data, array('id' => $id));

        if ($result === false) {
            return new WP_Error('db_error', 'Erro ao atualizar local', array('status' => 500));
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Local atualizado com sucesso'
        ), 200);
    }

    /**
     * DELETE /stock/locations/{id} - Excluir local
     */
    /**
     * POST /stock/locations/default - Definir local padrão
     */
    public function set_default_location($request)
    {
        $data = $request->get_json_params();
        $id = isset($data['id']) ? (int) $data['id'] : 0;

        if ($id <= 0) {
            return new WP_Error('invalid_id', 'ID inválido', array('status' => 400));
        }

        update_option('sistur_stock_default_location_id', $id);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Local padrão atualizado'
        ), 200);
    }

    /**
     * DELETE /stock/locations/{id} - Excluir local ou setor
     * Se for local pai, também desativa seus setores filhos
     */
    public function delete_location($request)
    {
        global $wpdb;

        $id = (int) $request->get_param('id');

        // Verificar se existe
        $location = $wpdb->get_row($wpdb->prepare(
            "SELECT id, parent_id FROM {$this->prefix}storage_locations WHERE id = %d",
            $id
        ));

        if (!$location) {
            return new WP_Error('not_found', 'Local/Setor não encontrado', array('status' => 404));
        }

        // Verificar se está em uso em lotes (próprio e setores filhos)
        $location_ids = array($id);
        if (empty($location->parent_id)) {
            // É um local pai, buscar IDs dos setores filhos
            $child_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$this->prefix}storage_locations WHERE parent_id = %d AND is_active = 1",
                $id
            ));
            $location_ids = array_merge($location_ids, $child_ids);
        }

        $ids_placeholder = implode(',', array_map('intval', $location_ids));
        $in_use = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}inventory_batches WHERE location_id IN ($ids_placeholder) AND status = 'active'"
        );

        if ($in_use > 0) {
            $label = empty($location->parent_id) ? 'Local (ou seus setores)' : 'Setor';
            return new WP_Error('in_use', $label . ' está em uso por ' . $in_use . ' lote(s) ativo(s)', array('status' => 400));
        }

        // Soft delete - desativar o item e seus filhos
        $wpdb->update(
            $this->prefix . 'storage_locations',
            array('is_active' => 0),
            array('id' => $id)
        );

        // Se é local pai, desativar setores filhos também
        if (empty($location->parent_id)) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->prefix}storage_locations SET is_active = 0 WHERE parent_id = %d",
                $id
            ));
        }

        $label = empty($location->parent_id) ? 'Local e seus setores excluídos' : 'Setor excluído';
        return new WP_REST_Response(array(
            'success' => true,
            'message' => $label . ' com sucesso'
        ), 200);
    }

    // ===================================================================
    // Métodos para Fornecedores (v2.17.0)
    // ===================================================================

    /**
     * GET /stock/suppliers — Lista todos os fornecedores ativos
     */
    public function get_suppliers($request)
    {
        global $wpdb;

        $suppliers = $wpdb->get_results(
            "SELECT id, name, tax_id, contact_info, created_at
             FROM {$this->prefix}suppliers
             ORDER BY name ASC"
        );

        return new WP_REST_Response($suppliers ?: array(), 200);
    }

    /**
     * POST /stock/suppliers — Cria novo fornecedor
     */
    public function create_supplier($request)
    {
        global $wpdb;

        $name = sanitize_text_field($request->get_param('name'));
        $tax_id = sanitize_text_field($request->get_param('tax_id') ?: '');
        $contact_info = sanitize_textarea_field($request->get_param('contact_info') ?: '');

        if (empty($name)) {
            return new WP_Error('missing_name', 'O nome do fornecedor é obrigatório.', array('status' => 400));
        }

        $employee_id = null;
        if (class_exists('SISTUR_Session')) {
            $session = SISTUR_Session::get_instance();
            $employee_id = $session->get_employee_id();
        }

        $result = $wpdb->insert(
            $this->prefix . 'suppliers',
            array(
                'name' => $name,
                'tax_id' => $tax_id ?: null,
                'contact_info' => $contact_info ?: null,
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Erro ao salvar fornecedor.', array('status' => 500));
        }

        $supplier_id = $wpdb->insert_id;
        $supplier = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, tax_id, contact_info, created_at FROM {$this->prefix}suppliers WHERE id = %d",
            $supplier_id
        ));

        error_log(sprintf(
            '[SISTUR Audit] Fornecedor criado — ID: %d, Nome: "%s", CNPJ: "%s", Employee: %s',
            $supplier_id,
            $name,
            $tax_id,
            $employee_id ?: 'N/A'
        ));

        return new WP_REST_Response(array('success' => true, 'data' => $supplier), 201);
    }

    /**
     * PUT /stock/suppliers/{id} — Atualiza fornecedor existente
     */
    public function update_supplier($request)
    {
        global $wpdb;

        $id = (int) $request->get_param('id');

        $supplier = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, tax_id, contact_info FROM {$this->prefix}suppliers WHERE id = %d",
            $id
        ));

        if (!$supplier) {
            return new WP_Error('not_found', 'Fornecedor não encontrado.', array('status' => 404));
        }

        $name = sanitize_text_field($request->get_param('name') ?: $supplier->name);
        $tax_id = sanitize_text_field($request->get_param('tax_id') ?: '');
        $contact_info = sanitize_textarea_field($request->get_param('contact_info') ?: '');

        if (empty($name)) {
            return new WP_Error('missing_name', 'O nome do fornecedor é obrigatório.', array('status' => 400));
        }

        $employee_id = null;
        if (class_exists('SISTUR_Session')) {
            $session = SISTUR_Session::get_instance();
            $employee_id = $session->get_employee_id();
        }

        $wpdb->update(
            $this->prefix . 'suppliers',
            array(
                'name' => $name,
                'tax_id' => $tax_id ?: null,
                'contact_info' => $contact_info ?: null,
            ),
            array('id' => $id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        error_log(sprintf(
            '[SISTUR Audit] Fornecedor atualizado — ID: %d, Antes: "%s" / Depois: "%s", Employee: %s',
            $id,
            $supplier->name,
            $name,
            $employee_id ?: 'N/A'
        ));

        $updated = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, tax_id, contact_info, created_at FROM {$this->prefix}suppliers WHERE id = %d",
            $id
        ));

        return new WP_REST_Response(array('success' => true, 'data' => $updated), 200);
    }

    /**
     * DELETE /stock/suppliers/{id} — Remove fornecedor (bloqueado se há lotes vinculados)
     */
    public function delete_supplier($request)
    {
        global $wpdb;

        $id = (int) $request->get_param('id');

        $supplier = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name FROM {$this->prefix}suppliers WHERE id = %d",
            $id
        ));

        if (!$supplier) {
            return new WP_Error('not_found', 'Fornecedor não encontrado.', array('status' => 404));
        }

        $in_use = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->prefix}inventory_batches WHERE supplier_id = %d",
            $id
        ));

        if ($in_use > 0) {
            return new WP_Error(
                'in_use',
                "O fornecedor \"{$supplier->name}\" está vinculado a {$in_use} lote(s) e não pode ser removido.",
                array('status' => 409)
            );
        }

        $employee_id = null;
        if (class_exists('SISTUR_Session')) {
            $session = SISTUR_Session::get_instance();
            $employee_id = $session->get_employee_id();
        }

        $wpdb->delete($this->prefix . 'suppliers', array('id' => $id), array('%d'));

        error_log(sprintf(
            '[SISTUR Audit] Fornecedor removido — ID: %d, Nome: "%s", Employee: %s',
            $id,
            $supplier->name,
            $employee_id ?: 'N/A'
        ));

        return new WP_REST_Response(array('success' => true, 'message' => 'Fornecedor removido com sucesso.'), 200);
    }

    // ===================================================================
    // Métodos para Ficha Técnica / Receitas
    // ===================================================================

    /**
     * GET /recipes/{product_id} - Lista ingredientes de uma receita
     */
    public function get_recipe_ingredients($request)
    {
        $product_id = (int) $request->get_param('product_id');

        $manager = Sistur_Recipe_Manager::get_instance();
        $ingredients = $manager->get_recipe_ingredients($product_id);

        return new WP_REST_Response($ingredients, 200);
    }

    /**
     * POST /recipes/{product_id} - Adiciona ingrediente à receita
     */
    public function add_recipe_ingredient($request)
    {
        $product_id = (int) $request->get_param('product_id');
        $data = $request->get_json_params();

        // Adicionar parent_product_id aos dados
        $data['parent_product_id'] = $product_id;

        $manager = Sistur_Recipe_Manager::get_instance();
        $result = $manager->add_ingredient($data);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(array(
            'success' => true,
            'id' => $result,
            'message' => 'Ingrediente adicionado com sucesso'
        ), 201);
    }

    /**
     * PUT /recipes/ingredient/{id} - Atualiza ingrediente
     */
    public function update_recipe_ingredient($request)
    {
        $id = (int) $request->get_param('id');
        $data = $request->get_json_params();

        $manager = Sistur_Recipe_Manager::get_instance();
        $result = $manager->update_ingredient($id, $data);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Ingrediente atualizado com sucesso'
        ), 200);
    }

    /**
     * DELETE /recipes/ingredient/{id} - Remove ingrediente (apenas vínculo)
     */
    public function delete_recipe_ingredient($request)
    {
        $id = (int) $request->get_param('id');

        $manager = Sistur_Recipe_Manager::get_instance();
        $result = $manager->remove_ingredient($id);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Ingrediente removido da receita'
        ), 200);
    }

    /**
     * GET /recipes/{product_id}/cost - Calcula custo do prato
     */
    public function get_recipe_cost($request)
    {
        $product_id = (int) $request->get_param('product_id');

        $manager = Sistur_Recipe_Manager::get_instance();
        $result = $manager->calculate_plate_cost($product_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * POST /recipes/{product_id}/deduct - Baixa estoque da receita
     */
    public function deduct_recipe_stock($request)
    {
        $product_id = (int) $request->get_param('product_id');
        $data = $request->get_json_params();

        $quantity = isset($data['quantity']) ? (float) $data['quantity'] : 1;
        $reason = isset($data['reason']) ? sanitize_text_field($data['reason']) : null;

        $manager = Sistur_Recipe_Manager::get_instance();
        $result = $manager->deduct_recipe_stock($product_id, $quantity, $reason);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * POST /recipes/{product_id}/produce - Produzir produto MANUFACTURED (v2.5)
     * Consome ingredientes do estoque e cria lote do produto final
     */
    public function produce_product($request)
    {
        $product_id = (int) $request->get_param('product_id');
        $data = $request->get_json_params();

        $quantity = isset($data['quantity']) ? (float) $data['quantity'] : 0;
        $notes = isset($data['notes']) ? sanitize_text_field($data['notes']) : '';

        if ($quantity <= 0) {
            return new WP_Error('invalid_quantity', 'Informe a quantidade a produzir', array('status' => 400));
        }

        $manager = Sistur_Recipe_Manager::get_instance();
        $result = $manager->produce($product_id, $quantity, $notes);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 200);
    }

    // ============================================
    // Rastreamento de Perdas (v2.7.0)
    // ============================================

    /**
     * POST /stock/losses - Registrar perda de estoque
     * 
     * Registra uma perda categorizada, executa baixa via FIFO,
     * e captura o custo para cálculos de CMV.
     * 
     * @since 2.7.0
     */
    public function register_loss($request)
    {
        global $wpdb;

        $data = $request->get_json_params();

        // Validações
        if (empty($data['product_id']) || empty($data['quantity']) || empty($data['reason'])) {
            return new WP_Error('invalid_data', 'product_id, quantity e reason são obrigatórios', array('status' => 400));
        }

        $product_id = (int) $data['product_id'];
        $quantity = abs((float) $data['quantity']);
        $reason = sanitize_text_field($data['reason']);
        $reason_details = isset($data['reason_details']) ? sanitize_textarea_field($data['reason_details']) : null;
        $location_id = isset($data['location_id']) ? (int) $data['location_id'] : null;

        // Validar razão
        $valid_reasons = array('expired', 'production_error', 'damaged', 'other');
        if (!in_array($reason, $valid_reasons)) {
            return new WP_Error('invalid_reason', 'Razão inválida. Use: ' . implode(', ', $valid_reasons), array('status' => 400));
        }

        // Verificar se o produto existe
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, u.symbol as unit_symbol 
             FROM {$this->prefix}products p
             LEFT JOIN {$this->prefix}units u ON p.base_unit_id = u.id
             WHERE p.id = %d",
            $product_id
        ));

        if (!$product) {
            return new WP_Error('not_found', 'Produto não encontrado', array('status' => 404));
        }

        // Verificar estoque disponível
        $available_stock = (float) $product->cached_stock;
        if ($available_stock < $quantity) {
            return new WP_Error(
                'insufficient_stock',
                sprintf(
                    'Estoque insuficiente. Disponível: %.3f %s, Solicitado: %.3f %s',
                    $available_stock,
                    $product->unit_symbol ?: '',
                    $quantity,
                    $product->unit_symbol ?: ''
                ),
                array('status' => 400)
            );
        }

        // Construir motivo para o log de transação
        $reason_labels = array(
            'expired' => 'Produto Vencido',
            'production_error' => 'Erro de Produção',
            'damaged' => 'Dano Físico',
            'other' => 'Outro'
        );
        $loss_reason = 'PERDA: ' . ($reason_labels[$reason] ?? $reason);
        if ($reason_details) {
            $loss_reason .= ' - ' . $reason_details;
        }

        // Iniciar transação
        $wpdb->query('START TRANSACTION');

        try {
            // Executar baixa via FIFO
            $exit_result = $this->process_exit($product_id, $quantity, 'LOSS', $location_id, $loss_reason);

            if (is_wp_error($exit_result)) {
                $wpdb->query('ROLLBACK');
                return $exit_result;
            }

            // Calcular custo total da perda (soma dos custos dos lotes consumidos)
            $total_loss_cost = 0;
            $avg_unit_cost = 0;
            $first_batch_id = null;
            $last_transaction_id = null;

            if (!empty($exit_result['batches_affected'])) {
                foreach ($exit_result['batches_affected'] as $batch_info) {
                    // Buscar custo do lote
                    $batch = $wpdb->get_row($wpdb->prepare(
                        "SELECT cost_price FROM {$this->prefix}inventory_batches WHERE id = %d",
                        $batch_info['batch_id']
                    ));
                    if ($batch) {
                        $total_loss_cost += $batch_info['quantity_consumed'] * $batch->cost_price;
                    }
                    if (!$first_batch_id) {
                        $first_batch_id = $batch_info['batch_id'];
                    }
                }
                $avg_unit_cost = $quantity > 0 ? $total_loss_cost / $quantity : 0;
            }

            // Obter ID da última transação registrada
            $last_transaction_id = $wpdb->get_var(
                "SELECT MAX(id) FROM {$this->prefix}inventory_transactions WHERE product_id = $product_id AND type = 'LOSS'"
            );

            // Registrar na tabela de perdas
            $loss_data = array(
                'product_id' => $product_id,
                'batch_id' => $first_batch_id,
                'transaction_id' => $last_transaction_id,
                'quantity' => $quantity,
                'reason' => $reason,
                'reason_details' => $reason_details,
                'cost_at_time' => $avg_unit_cost,
                'total_loss_value' => $total_loss_cost,
                'location_id' => $location_id,
                'user_id' => get_current_user_id(),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            );

            $wpdb->insert($this->prefix . 'inventory_losses', $loss_data);
            $loss_id = $wpdb->insert_id;

            if (!$loss_id) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('db_error', 'Erro ao registrar perda: ' . $wpdb->last_error, array('status' => 500));
            }

            // Atualizar estoque cacheado
            $this->update_cached_stock($product_id);

            $wpdb->query('COMMIT');

            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Perda registrada com sucesso',
                'loss_id' => $loss_id,
                'product_name' => $product->name,
                'quantity' => $quantity,
                'reason' => $reason,
                'reason_label' => $reason_labels[$reason] ?? $reason,
                'unit_cost' => round($avg_unit_cost, 4),
                'total_loss_value' => round($total_loss_cost, 4),
                'batches_affected' => $exit_result['batches_affected']
            ), 201);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('transaction_error', $e->getMessage(), array('status' => 500));
        }
    }

    /**
     * GET /stock/losses - Listar perdas registradas
     * 
     * @since 2.7.0
     */
    public function get_losses($request)
    {
        global $wpdb;

        $product_id = (int) $request->get_param('product_id');
        $reason = $request->get_param('reason');
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        $limit = (int) $request->get_param('limit');

        $where = array('1=1');
        $params = array();

        if ($product_id > 0) {
            $where[] = 'l.product_id = %d';
            $params[] = $product_id;
        }

        if (!empty($reason)) {
            $where[] = 'l.reason = %s';
            $params[] = $reason;
        }

        if (!empty($start_date)) {
            $where[] = 'DATE(l.created_at) >= %s';
            $params[] = $start_date;
        }

        if (!empty($end_date)) {
            $where[] = 'DATE(l.created_at) <= %s';
            $params[] = $end_date;
        }

        $where_sql = implode(' AND ', $where);
        $params[] = $limit;

        $sql = "SELECT l.*, 
                       p.name as product_name,
                       p.sku as product_sku,
                       u.symbol as unit_symbol,
                       loc.name as location_name
                FROM {$this->prefix}inventory_losses l
                LEFT JOIN {$this->prefix}products p ON l.product_id = p.id
                LEFT JOIN {$this->prefix}units u ON p.base_unit_id = u.id
                LEFT JOIN {$this->prefix}storage_locations loc ON l.location_id = loc.id
                WHERE {$where_sql}
                ORDER BY l.created_at DESC
                LIMIT %d";

        $losses = $wpdb->get_results($wpdb->prepare($sql, $params));

        // Traduzir razões
        $reason_labels = array(
            'expired' => 'Produto Vencido',
            'production_error' => 'Erro de Produção',
            'damaged' => 'Dano Físico',
            'other' => 'Outro'
        );

        foreach ($losses as &$loss) {
            $loss->reason_label = $reason_labels[$loss->reason] ?? $loss->reason;
        }

        return new WP_REST_Response(array(
            'losses' => $losses,
            'count' => count($losses)
        ), 200);
    }

    /**
     * GET /stock/losses/summary - Resumo de perdas para CMV
     * 
     * Retorna totais por categoria e por produto para análise.
     * 
     * @since 2.7.0
     */
    public function get_losses_summary($request)
    {
        global $wpdb;

        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');

        $where = array('1=1');
        $params = array();

        if (!empty($start_date)) {
            $where[] = 'DATE(l.created_at) >= %s';
            $params[] = $start_date;
        }

        if (!empty($end_date)) {
            $where[] = 'DATE(l.created_at) <= %s';
            $params[] = $end_date;
        }

        $where_sql = implode(' AND ', $where);

        // Total geral
        $total_sql = "SELECT 
                        COUNT(*) as total_records,
                        COALESCE(SUM(l.quantity), 0) as total_quantity,
                        COALESCE(SUM(l.total_loss_value), 0) as total_value
                      FROM {$this->prefix}inventory_losses l
                      WHERE {$where_sql}";

        $totals = !empty($params)
            ? $wpdb->get_row($wpdb->prepare($total_sql, $params))
            : $wpdb->get_row($total_sql);

        // Por categoria
        $by_reason_sql = "SELECT 
                            l.reason,
                            COUNT(*) as count,
                            COALESCE(SUM(l.quantity), 0) as total_quantity,
                            COALESCE(SUM(l.total_loss_value), 0) as total_value
                          FROM {$this->prefix}inventory_losses l
                          WHERE {$where_sql}
                          GROUP BY l.reason
                          ORDER BY total_value DESC";

        $by_reason = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($by_reason_sql, $params))
            : $wpdb->get_results($by_reason_sql);

        // Traduzir razões
        $reason_labels = array(
            'expired' => 'Produto Vencido',
            'production_error' => 'Erro de Produção',
            'damaged' => 'Dano Físico',
            'other' => 'Outro'
        );

        foreach ($by_reason as &$item) {
            $item->reason_label = $reason_labels[$item->reason] ?? $item->reason;
        }

        // Top 10 produtos com mais perdas
        $top_products_sql = "SELECT 
                               l.product_id,
                               p.name as product_name,
                               p.sku,
                               COUNT(*) as loss_count,
                               COALESCE(SUM(l.quantity), 0) as total_quantity,
                               COALESCE(SUM(l.total_loss_value), 0) as total_value
                             FROM {$this->prefix}inventory_losses l
                             LEFT JOIN {$this->prefix}products p ON l.product_id = p.id
                             WHERE {$where_sql}
                             GROUP BY l.product_id
                             ORDER BY total_value DESC
                             LIMIT 10";

        $top_products = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($top_products_sql, $params))
            : $wpdb->get_results($top_products_sql);

        return new WP_REST_Response(array(
            'period' => array(
                'start_date' => $start_date ?: 'Todos',
                'end_date' => $end_date ?: 'Todos'
            ),
            'totals' => array(
                'records' => (int) $totals->total_records,
                'quantity' => (float) $totals->total_quantity,
                'value' => round((float) $totals->total_value, 2)
            ),
            'by_reason' => $by_reason,
            'top_products' => $top_products
        ), 200);
    }

    /**
     * Normaliza tipos de produto para formato SET (v2.6)
     * 
     * Aceita:
     * - Array: ['RAW', 'MANUFACTURED']
     * - String separada por vírgula: 'RAW,MANUFACTURED'
     * - String única: 'RAW'
     * 
     * Retorna string para SET column: 'RAW,MANUFACTURED'
     * 
     * @param mixed $types Array ou string de tipos
     * @return string Tipos normalizados para SET
     */
    private function normalize_product_types($types)
    {
        $valid_types = array('RAW', 'RESALE', 'MANUFACTURED', 'RESOURCE', 'BASE');

        // Se for array, converte para array de strings
        if (is_array($types)) {
            $type_array = $types;
        } else {
            // Se for string, pode ser 'RAW' ou 'RAW,MANUFACTURED'
            $type_array = array_map('trim', explode(',', (string) $types));
        }

        // Filtrar apenas tipos válidos
        $filtered = array_filter($type_array, function ($t) use ($valid_types) {
            return in_array(strtoupper(trim($t)), $valid_types);
        });

        // Normalizar para uppercase
        $normalized = array_map(function ($t) {
            return strtoupper(trim($t));
        }, $filtered);

        // Remover duplicatas e ordenar para consistência
        $unique = array_unique($normalized);
        sort($unique);

        // RESOURCE não pode ser combinado com outros tipos
        if (in_array('RESOURCE', $unique) && count($unique) > 1) {
            // Remove RESOURCE se há outros tipos
            $unique = array_diff($unique, array('RESOURCE'));
            $unique = array_values($unique);
        }

        // Retornar como string separada por vírgula ou default
        return !empty($unique) ? implode(',', $unique) : 'RAW';
    }

    // ============================================
    // Inventário Cego / Blind Count (v2.8.0)
    // ============================================

    /**
     * POST /stock/blind-inventory/start
     * Inicia nova sessão de inventário cego
     * Captura estoque teórico e custo de todos os produtos ativos
     * Aceita location_id e sector_id para escopo por local/setor
     */
    public function start_blind_inventory($request)
    {
        global $wpdb;

        $products_table = $wpdb->prefix . 'sistur_products';
        $batches_table = $wpdb->prefix . 'sistur_inventory_batches';
        $sessions_table = $wpdb->prefix . 'sistur_blind_inventory_sessions';
        $items_table = $wpdb->prefix . 'sistur_blind_inventory_items';
        $locations_table = $wpdb->prefix . 'sistur_storage_locations';

        $notes = $request->get_param('notes') ?: '';
        $user_id = get_current_user_id();
        $location_id = $request->get_param('location_id') ? (int) $request->get_param('location_id') : null;
        $sector_id = $request->get_param('sector_id') ? (int) $request->get_param('sector_id') : null;

        // SELF-HEALING: Garantir que a tabela tem as colunas corretas (caso a migração não tenha rodado)
        if (!class_exists('SISTUR_Stock_Installer')) {
            require_once dirname(__FILE__) . '/class-sistur-stock-installer.php';
        }
        if (class_exists('SISTUR_Stock_Installer')) {
            SISTUR_Stock_Installer::migrate_blind_inventory_scope();
        }

        // Validar local se fornecido
        if ($location_id) {
            $loc_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $locations_table WHERE id = %d AND is_active = 1 AND parent_id IS NULL",
                $location_id
            ));
            if (!$loc_exists) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Local não encontrado'
                ), 400);
            }
        }

        // Validar setor se fornecido
        if ($sector_id) {
            $sec_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $locations_table WHERE id = %d AND is_active = 1 AND parent_id IS NOT NULL",
                $sector_id
            ));
            if (!$sec_exists) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Setor não encontrado'
                ), 400);
            }
        }

        // Detectar modo wizard: local com setores filhos e sem setor específico
        $wizard_mode = false;
        $sectors_list = array();

        if ($location_id && !$sector_id) {
            $child_sectors = $wpdb->get_results($wpdb->prepare(
                "SELECT id, name FROM $locations_table WHERE parent_id = %d AND is_active = 1 ORDER BY name ASC",
                $location_id
            ));
            if (!empty($child_sectors)) {
                $wizard_mode = true;
                $sectors_list = array_map(function ($s) {
                    return (int) $s->id; }, $child_sectors);
            }
        }

        // Criar sessão com escopo de local/setor
        $session_data = array(
            'user_id' => $user_id,
            'status' => 'in_progress',
            'notes' => $notes
        );
        $session_format = array('%d', '%s', '%s');

        if ($location_id) {
            $session_data['location_id'] = $location_id;
            $session_format[] = '%d';
        }
        if ($sector_id) {
            $session_data['sector_id'] = $sector_id;
            $session_format[] = '%d';
        }

        // Campos do wizard
        if ($wizard_mode) {
            $session_data['wizard_mode'] = 1;
            $session_data['current_sector_index'] = 0;
            $session_data['sectors_list'] = wp_json_encode($sectors_list);
            $session_format[] = '%d';
            $session_format[] = '%d';
            $session_format[] = '%s';
        }

        $wpdb->insert($sessions_table, $session_data, $session_format);

        $session_id = $wpdb->insert_id;

        if (!$session_id) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Erro ao criar sessão de inventário'
            ), 500);
        }

        // Determinar IDs de localização para filtro de lotes
        $filter_location_ids = array();
        if ($wizard_mode) {
            // No modo wizard, carregar itens APENAS do primeiro setor
            $filter_location_ids[] = $sectors_list[0];
        } elseif ($sector_id) {
            // Setor específico (modo flat list)
            $filter_location_ids[] = $sector_id;
        } elseif ($location_id) {
            // Local + todos seus setores (modo flat list sem setores filhos)
            $filter_location_ids[] = $location_id;
            $child_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $locations_table WHERE parent_id = %d AND is_active = 1",
                $location_id
            ));
            $filter_location_ids = array_merge($filter_location_ids, $child_ids);
        }

        error_log("SISTUR Blind Inventory: Sessão #{$session_id} - location_id={$location_id}, sector_id={$sector_id}, wizard_mode=" . ($wizard_mode ? '1' : '0') . ", filter_ids=[" . implode(',', $filter_location_ids) . "]");

        // Buscar produtos com estoque teórico (filtrado por local/setor se especificado)
        // No modo wizard com sector_id na tabela batches, filtramos por sector_id
        $has_sector_col = $wpdb->get_var("SHOW COLUMNS FROM $batches_table LIKE 'sector_id'");

        if (!empty($filter_location_ids)) {
            $ids_placeholder = implode(',', array_map('intval', $filter_location_ids));

            // Usar sector_id se disponível, senão fallback para location_id
            if ($has_sector_col && ($wizard_mode || $sector_id)) {
                // v2.15.3: Agrupar por produto + setor para permitir mesmo produto em setores diferentes
                $products = $wpdb->get_results("
                    SELECT p.id, p.name, p.average_cost,
                           b.sector_id,
                           COALESCE(SUM(b.quantity), 0) as theoretical_stock
                    FROM $products_table p
                    INNER JOIN $batches_table b ON b.product_id = p.id
                        AND b.status = 'active'
                        AND b.quantity > 0
                        AND b.sector_id IN ($ids_placeholder)
                    WHERE p.status = 'active'
                    GROUP BY p.id, b.sector_id
                    ORDER BY p.name, b.sector_id
                ");
            } else {
                // Modo legado ou sem setores - agrupar por location_id
                $sector_or_location = $wizard_mode ? 'b.sector_id' : 'b.location_id';

                $products = $wpdb->get_results("
                    SELECT p.id, p.name, p.average_cost,
                           NULL as sector_id,
                           COALESCE(SUM(b.quantity), 0) as theoretical_stock
                    FROM $products_table p
                    INNER JOIN $batches_table b ON b.product_id = p.id
                        AND b.status = 'active'
                        AND b.quantity > 0
                        AND $sector_or_location IN ($ids_placeholder)
                    WHERE p.status = 'active'
                    GROUP BY p.id
                    ORDER BY p.name
                ");
            }

            error_log("SISTUR Blind Inventory: Query principal retornou " . count($products) . " produtos para filter_ids=($ids_placeholder). last_error=" . $wpdb->last_error);

            // Diagnóstico detalhado se 0 produtos encontrados
            if (empty($products)) {
                $batch_filter_col = $wizard_mode ? 'sector_id' : 'location_id';
                $batch_in_filter = $wpdb->get_var("SELECT COUNT(*) FROM $batches_table WHERE status = 'active' AND quantity > 0 AND ($batch_filter_col IN ($ids_placeholder) OR location_id IN ($ids_placeholder))");
                $active_products = $wpdb->get_var("SELECT COUNT(*) FROM $products_table WHERE status = 'active'");
                error_log("SISTUR Blind Inventory DIAG: batches_in_filter=$batch_in_filter, active_products=$active_products");
            }

            // Query secundária (segura): incluir produtos com default_sector_id nestes locais
            // mas que não têm estoque físico aqui (aparecerão com estoque teórico = 0)
            $has_default_sector_col = $wpdb->get_var("SHOW COLUMNS FROM $products_table LIKE 'default_sector_id'");
            if ($has_default_sector_col) {
                $found_ids = array_map(function ($p) {
                    return (int) $p->id; }, $products);
                $exclude_sql = !empty($found_ids) ? "AND p.id NOT IN (" . implode(',', $found_ids) . ")" : "";

                // Também checar default_sector_id contra os IDs do filtro
                $extra = $wpdb->get_results("
                    SELECT p.id, p.name, p.average_cost, 0 as theoretical_stock
                    FROM $products_table p
                    WHERE p.status = 'active'
                      AND p.default_sector_id IN ($ids_placeholder)
                      $exclude_sql
                    ORDER BY p.name
                ");

                if (!empty($extra)) {
                    $products = array_merge($products, $extra);
                }
            }
        } else {
            // Sem filtro de local: todos os produtos ativos
            $products = $wpdb->get_results("
                SELECT
                    p.id,
                    p.name,
                    p.average_cost,
                    COALESCE(
                        (SELECT SUM(b.quantity) FROM $batches_table b WHERE b.product_id = p.id AND b.status = 'active' AND b.quantity > 0),
                        0
                    ) as theoretical_stock
                FROM $products_table p
                WHERE p.status = 'active'
                ORDER BY p.name
            ");

            error_log("SISTUR Blind Inventory: Sem filtro de local, retornou " . count($products) . " produtos. last_error=" . $wpdb->last_error);
        }

        // Inserir itens na sessão com estoque teórico capturado
        // v2.15.3: Incluir sector_id para permitir mesmo produto em setores diferentes
        $inserted = 0;
        foreach ($products as $product) {
            $wpdb->insert($items_table, array(
                'session_id' => $session_id,
                'product_id' => $product->id,
                'sector_id' => isset($product->sector_id) ? $product->sector_id : null,
                'theoretical_qty' => $product->theoretical_stock,
                'unit_cost' => $product->average_cost ?: 0
            ), array('%d', '%d', '%d', '%f', '%f'));
            $inserted++;
        }

        // Montar label do escopo
        $scope_label = 'Todos os locais';
        if ($location_id) {
            $loc_name = $wpdb->get_var($wpdb->prepare(
                "SELECT name FROM $locations_table WHERE id = %d",
                $location_id
            ));
            $scope_label = $loc_name ?: 'Local #' . $location_id;
        }
        if ($sector_id) {
            $sec_name = $wpdb->get_var($wpdb->prepare(
                "SELECT name FROM $locations_table WHERE id = %d",
                $sector_id
            ));
            $scope_label .= ' › ' . ($sec_name ?: 'Setor #' . $sector_id);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'session_id' => $session_id,
            'products_count' => $inserted,
            'scope' => $scope_label,
            'wizard_mode' => $wizard_mode,
            'total_sectors' => count($sectors_list),
            'message' => "Sessão #{$session_id} iniciada com {$inserted} produtos ({$scope_label})"
        ), 201);
    }

    /**
     * GET /stock/blind-inventory/{session_id}
     * Lista itens da sessão para preenchimento (SEM mostrar estoque teórico)
     */
    public function get_blind_inventory_session($request)
    {
        global $wpdb;

        $session_id = (int) $request->get_param('session_id');
        $sessions_table = $wpdb->prefix . 'sistur_blind_inventory_sessions';
        $items_table = $wpdb->prefix . 'sistur_blind_inventory_items';
        $products_table = $wpdb->prefix . 'sistur_products';
        $units_table = $wpdb->prefix . 'sistur_units';
        $locations_table = $wpdb->prefix . 'sistur_storage_locations';
        $batches_table = $wpdb->prefix . 'sistur_inventory_batches';

        // Verificar sessão existe  
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, 
                    loc.name as location_name,
                    sec.name as sector_name
             FROM $sessions_table s
             LEFT JOIN $locations_table loc ON s.location_id = loc.id
             LEFT JOIN $locations_table sec ON s.sector_id = sec.id
             WHERE s.id = %d",
            $session_id
        ));

        if (!$session) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Sessão não encontrada'
            ), 404);
        }

        // Self-healing: garantir que colunas expiry_date e batch_id existam
        $col_check = $wpdb->get_results("SHOW COLUMNS FROM $items_table LIKE 'expiry_date'");
        if (empty($col_check)) {
            SISTUR_Stock_Installer::migrate_blind_inventory_extras();
        }

        // Buscar itens (NÃO inclui theoretical_qty para manter "cego")
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT
                i.id,
                i.product_id,
                p.name as product_name,
                u.symbol as unit_symbol,
                i.physical_qty,
                i.theoretical_qty,
                i.expiry_date,
                i.batch_id,
                b.batch_code
            FROM $items_table i
            INNER JOIN $products_table p ON i.product_id = p.id
            LEFT JOIN $units_table u ON p.base_unit_id = u.id
            LEFT JOIN $batches_table b ON i.batch_id = b.id
            WHERE i.session_id = %d
            ORDER BY p.name
        ", $session_id));

        // Montar dados do wizard se aplicável
        $wizard_data = array();
        if (!empty($session->wizard_mode)) {
            $sectors_list = json_decode($session->sectors_list, true) ?: array();
            $current_index = (int) ($session->current_sector_index ?? 0);

            // Buscar nomes dos setores
            $sector_names = array();
            if (!empty($sectors_list)) {
                $ids_placeholder = implode(',', array_map('intval', $sectors_list));
                $sector_rows = $wpdb->get_results(
                    "SELECT id, name FROM $locations_table WHERE id IN ($ids_placeholder) ORDER BY FIELD(id, $ids_placeholder)"
                );
                foreach ($sector_rows as $sr) {
                    $sector_names[(int) $sr->id] = $sr->name;
                }
            }

            $wizard_data = array(
                'wizard_mode' => true,
                'current_sector_index' => $current_index,
                'total_sectors' => count($sectors_list),
                'sectors_list' => $sectors_list,
                'sectors_names' => $sector_names,
                'current_sector_id' => isset($sectors_list[$current_index]) ? $sectors_list[$current_index] : null,
                'current_sector_name' => isset($sectors_list[$current_index]) && isset($sector_names[$sectors_list[$current_index]]) ? $sector_names[$sectors_list[$current_index]] : null,
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'session' => array(
                'id' => (int) $session->id,
                'status' => $session->status,
                'started_at' => $session->started_at,
                'notes' => $session->notes,
                'location_id' => $session->location_id ? (int) $session->location_id : null,
                'location_name' => $session->location_name,
                'sector_id' => $session->sector_id ? (int) $session->sector_id : null,
                'sector_name' => $session->sector_name
            ),
            'wizard' => !empty($wizard_data) ? $wizard_data : null,
            'items' => $items
        ), 200);
    }

    /**
     * PUT /stock/blind-inventory/{session_id}/item/{product_id}
     * Atualiza quantidade física de um item
     */
    public function update_blind_inventory_item($request)
    {
        global $wpdb;

        $session_id = (int) $request->get_param('session_id');
        $product_id = (int) $request->get_param('product_id');
        $physical_qty = $request->get_param('physical_qty');
        $expiry_date = $request->get_param('expiry_date');
        $batch_id = (int) $request->get_param('batch_id');
        $batch_code = $request->get_param('batch_code');

        $items_table = $wpdb->prefix . 'sistur_blind_inventory_items';
        $sessions_table = $wpdb->prefix . 'sistur_blind_inventory_sessions';
        $batches_table = $wpdb->prefix . 'sistur_inventory_batches';

        // v2.15.4: Buscar sessão completa incluindo wizard_mode e current_sector_index
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $sessions_table WHERE id = %d",
            $session_id
        ));

        if (!$session || $session->status !== 'in_progress') {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Sessão não está em progresso'
            ), 400);
        }

        // v2.15.4: Obter sector_id do wizard atual para UPDATE correto
        $sector_id = null;
        if (!empty($session->wizard_mode)) {
            $sectors_list = json_decode($session->sectors_list, true) ?: array();
            $current_index = (int) ($session->current_sector_index ?? 0);
            if ($current_index < count($sectors_list)) {
                $sector_id = $sectors_list[$current_index];
            }
        }

        // Se informou código do lote, buscar ID
        if (!empty($batch_code) && $batch_id <= 0) {
            $batch = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $batches_table WHERE batch_code = %s AND product_id = %d LIMIT 1",
                $batch_code,
                $product_id
            ));
            if ($batch) {
                $batch_id = $batch->id;
            }
        }

        // Self-healing: garantir que colunas expiry_date e batch_id existam
        $col_check = $wpdb->get_results("SHOW COLUMNS FROM $items_table LIKE 'expiry_date'");
        if (empty($col_check)) {
            SISTUR_Stock_Installer::migrate_blind_inventory_extras();
        }

        // Preparar dados para atualização
        $data = array('physical_qty' => $physical_qty);
        $format = array('%f');

        if ($expiry_date) {
            $data['expiry_date'] = $expiry_date;
            $format[] = '%s';
        } else {
            $data['expiry_date'] = null;
            $format[] = '%s';
        }

        if ($batch_id > 0) {
            $data['batch_id'] = $batch_id;
            $format[] = '%d';
        } else {
            $data['batch_id'] = null;
            $format[] = '%d';
        }

        // v2.15.4: Atualizar incluindo sector_id no WHERE para não sobrescrever outros setores
        $where = array('session_id' => $session_id, 'product_id' => $product_id);
        $where_format = array('%d', '%d');

        if ($sector_id !== null) {
            $where['sector_id'] = $sector_id;
            $where_format[] = '%d';
        }

        $updated = $wpdb->update(
            $items_table,
            $data,
            $where,
            $format,
            $where_format
        );

        if ($updated === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Erro ao atualizar item'
            ), 500);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Quantidade atualizada'
        ), 200);
    }

    /**
     * POST /stock/blind-inventory/{session_id}/submit
     * Processa divergências e cria perdas/ajustes
     */
    public function submit_blind_inventory($request)
    {
        global $wpdb;

        $session_id = (int) $request->get_param('session_id');

        $sessions_table = $wpdb->prefix . 'sistur_blind_inventory_sessions';
        $items_table = $wpdb->prefix . 'sistur_blind_inventory_items';
        $losses_table = $wpdb->prefix . 'sistur_inventory_losses';
        $transactions_table = $wpdb->prefix . 'sistur_inventory_transactions';
        $batches_table = $wpdb->prefix . 'sistur_inventory_batches';
        $products_table = $wpdb->prefix . 'sistur_products';

        // Verificar sessão em progresso
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $sessions_table WHERE id = %d",
            $session_id
        ));

        if (!$session || $session->status !== 'in_progress') {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Sessão não está em progresso ou não existe'
            ), 400);
        }

        // Self-healing: garantir que colunas expiry_date e batch_id existam
        $col_check = $wpdb->get_results("SHOW COLUMNS FROM $items_table LIKE 'expiry_date'");
        if (empty($col_check)) {
            SISTUR_Stock_Installer::migrate_blind_inventory_extras();
        }

        // Buscar itens com quantidade física preenchida
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $items_table
            WHERE session_id = %d AND physical_qty IS NOT NULL
        ", $session_id));

        if (empty($items)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Nenhum item com quantidade física informada'
            ), 400);
        }

        $total_divergence_value = 0;
        $losses_created = 0;
        $adjustments_created = 0;
        $results = array();
        $affected_product_ids = array();
        $user_id = get_current_user_id();
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

        // Obter local/setor da sessão (se existirem)
        // Se houver setor, ele é o local mais específico e deve ser usado como location_id nas transações
        $session_location_id = !empty($session->sector_id) ? $session->sector_id : (isset($session->location_id) ? $session->location_id : null);

        // v2.15.2: Separar location_id (pai) e sector_id (setor específico) para filtros corretos
        $session_sector_id = !empty($session->sector_id) ? $session->sector_id : null;
        $session_parent_location_id = isset($session->location_id) ? $session->location_id : null;

        foreach ($items as $item) {
            $divergence = (float) $item->physical_qty - (float) $item->theoretical_qty;

            // v2.15.4: Usar sector_id do item (modo wizard) ou fallback para sessão (modo normal)
            $item_sector_id = !empty($item->sector_id) ? $item->sector_id : $session_sector_id;
            $item_location_id = !empty($item_sector_id) ? $item_sector_id : $session_parent_location_id;

            // Rastrear produtos afetados para recalcular cached_stock no final
            $affected_product_ids[$item->product_id] = true;

            // Buscar custo médio atual do produto para usar em sobras
            $product_avg_cost = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT average_cost FROM $products_table WHERE id = %d",
                $item->product_id
            ));

            // Se average_cost é 0, calcular diretamente das transações IN (fallback robusto)
            if ($product_avg_cost <= 0) {
                $computed_avg = $wpdb->get_var($wpdb->prepare(
                    "SELECT SUM(ABS(quantity) * unit_cost) / NULLIF(SUM(ABS(quantity)), 0)
                     FROM $transactions_table
                     WHERE product_id = %d AND type = 'IN' AND unit_cost > 0",
                    $item->product_id
                ));
                if ($computed_avg > 0) {
                    $product_avg_cost = (float) $computed_avg;
                }
            }

            // Determinar custo da divergência: average_cost > unit_cost capturado > 0
            if ($divergence > 0 && $product_avg_cost > 0) {
                $divergence_cost = $product_avg_cost;
            } elseif ((float) $item->unit_cost > 0) {
                $divergence_cost = (float) $item->unit_cost;
            } else {
                $divergence_cost = $product_avg_cost;
            }
            $divergence_value = $divergence * $divergence_cost;

            // Atualizar item com divergência calculada
            $wpdb->update(
                $items_table,
                array(
                    'divergence' => $divergence,
                    'divergence_value' => $divergence_value
                ),
                array('id' => $item->id),
                array('%f', '%f'),
                array('%d')
            );

            $total_divergence_value += $divergence_value;

            // ─── Divergência NEGATIVA = Perda (falta de estoque) ───
            if ($divergence < 0) {
                $qty_loss = abs($divergence);

                // v2.15.4: Criar transação de perda usando location_id do item
                $wpdb->insert($transactions_table, array(
                    'product_id' => $item->product_id,
                    'user_id' => $user_id,
                    'type' => 'LOSS',
                    'quantity' => $qty_loss,
                    'unit_cost' => $divergence_cost,
                    'total_cost' => abs($divergence_value),
                    'reason' => 'Divergência Inventário Cego #' . $session_id,
                    'reference_type' => 'blind_inventory',
                    'reference_id' => $session_id,
                    'ip_address' => $ip_address,
                    'to_location_id' => $item_location_id
                ), array('%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%d'));

                $transaction_id = $wpdb->insert_id;

                // v2.15.4: Criar registro de perda usando location_id do item
                $loss_data = array(
                    'product_id' => $item->product_id,
                    'transaction_id' => $transaction_id,
                    'quantity' => $qty_loss,
                    'reason' => 'inventory_divergence',
                    'reason_details' => "Sessão #{$session_id}: Teórico={$item->theoretical_qty}, Físico={$item->physical_qty}",
                    'cost_at_time' => $divergence_cost,
                    'total_loss_value' => abs($divergence_value),
                    'user_id' => $user_id,
                    'ip_address' => $ip_address,
                    'location_id' => $item_location_id
                );

                if (!empty($item->batch_id)) {
                    $loss_data['batch_id'] = $item->batch_id;
                }

                $wpdb->insert($losses_table, $loss_data);
                $loss_id = $wpdb->insert_id;

                // Atualizar item com referências
                $wpdb->update(
                    $items_table,
                    array('loss_id' => $loss_id, 'transaction_id' => $transaction_id),
                    array('id' => $item->id),
                    array('%d', '%d'),
                    array('%d')
                );

                // Baixar estoque: Lote Específico primeiro, depois FEFO para o restante
                $remaining_loss = $qty_loss;

                if (!empty($item->batch_id)) {
                    $batch = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $batches_table WHERE id = %d AND status = 'active'",
                        $item->batch_id
                    ));
                    if ($batch && $batch->quantity > 0) {
                        $to_consume = min($batch->quantity, $remaining_loss);
                        $new_qty = $batch->quantity - $to_consume;

                        $update_batch = array('quantity' => $new_qty);
                        if ($new_qty <= 0) {
                            $update_batch['status'] = 'depleted';
                        }

                        $wpdb->update($batches_table, $update_batch, array('id' => $batch->id));
                        $remaining_loss -= $to_consume;
                    }
                }

                // v2.15.4: FEFO para qualquer restante usando sector_id do item
                if ($remaining_loss > 0) {
                    $where_loc = $item_sector_id
                        ? $wpdb->prepare("AND sector_id = %d", $item_sector_id)
                        : "";

                    // Excluir o lote específico já consumido acima
                    $exclude_batch = !empty($item->batch_id)
                        ? $wpdb->prepare("AND id != %d", $item->batch_id)
                        : "";

                    $fefo_batches = $wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM $batches_table
                         WHERE product_id = %d AND status = 'active' AND quantity > 0
                         $where_loc $exclude_batch
                         ORDER BY
                            CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END,
                            expiry_date ASC,
                            created_at ASC",
                        $item->product_id
                    ));

                    foreach ($fefo_batches as $batch) {
                        if ($remaining_loss <= 0)
                            break;

                        $consume = min($batch->quantity, $remaining_loss);
                        $new_b_qty = $batch->quantity - $consume;

                        $upd_b = array('quantity' => $new_b_qty);
                        if ($new_b_qty <= 0) {
                            $upd_b['status'] = 'depleted';
                        }

                        $wpdb->update($batches_table, $upd_b, array('id' => $batch->id));
                        $remaining_loss -= $consume;
                    }
                }

                $loss_method = !empty($item->batch_id) ? 'loss_specific' : 'loss_fefo';
                $results[] = array(
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'status' => $loss_method,
                    'message' => sprintf('Perda de %s deduzida via %s', $qty_loss, $loss_method === 'loss_specific' ? 'lote específico + FEFO' : 'FEFO')
                );

                $losses_created++;
            }
            // ─── Divergência POSITIVA = Ajuste de entrada (sobra) ───
            elseif ($divergence > 0) {
                // v2.15.4: Criar transação de ajuste usando location_id do item
                $wpdb->insert($transactions_table, array(
                    'product_id' => $item->product_id,
                    'user_id' => $user_id,
                    'type' => 'ADJUST',
                    'quantity' => $divergence,
                    'unit_cost' => $divergence_cost,
                    'total_cost' => $divergence_value,
                    'reason' => 'Ajuste Inventário Cego #' . $session_id,
                    'reference_type' => 'blind_inventory',
                    'reference_id' => $session_id,
                    'ip_address' => $ip_address,
                    'to_location_id' => $item_location_id
                ), array('%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%d'));

                $transaction_id = $wpdb->insert_id;

                // Atualizar item com referência
                $wpdb->update(
                    $items_table,
                    array('transaction_id' => $transaction_id),
                    array('id' => $item->id),
                    array('%d'),
                    array('%d')
                );

                // ─── Expiry Matcher: Match/Inherit — buscar lote existente com validade EXATA ───
                $batch_updated = false;
                $existing_batch = null;

                // Branch A trigger: expiry_date informado — match exato obrigatório (datas ≠ = lotes ≠)
                if (!empty($item->expiry_date)) {
                    // v2.15.4: Filtrar por sector_id do item (modo wizard) ou location pai
                    $loc_filter = "";
                    if ($item_sector_id) {
                        $loc_filter = $wpdb->prepare("AND sector_id = %d", $item_sector_id);
                    } elseif ($session_parent_location_id) {
                        $loc_filter = $wpdb->prepare("AND location_id = %d", $session_parent_location_id);
                    }

                    // Match EXATO de validade — sem fuzzy matching (business rule: datas diferentes = lotes diferentes)
                    $existing_batch = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $batches_table
                         WHERE product_id = %d
                         AND expiry_date = %s
                         AND status = 'active'
                         AND quantity > 0
                         $loc_filter
                         ORDER BY created_at ASC LIMIT 1",
                        $item->product_id,
                        $item->expiry_date
                    ));
                }
                // Estratégia 2: Sem expiry_date — buscar lote sem validade no mesmo local/setor
                else {
                    // v2.15.4: Filtrar por sector_id do item (modo wizard) ou location pai
                    $loc_filter = "";
                    if ($item_sector_id) {
                        $loc_filter = $wpdb->prepare("AND sector_id = %d", $item_sector_id);
                    } elseif ($session_parent_location_id) {
                        $loc_filter = $wpdb->prepare("AND location_id = %d", $session_parent_location_id);
                    }

                    $existing_batch = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $batches_table
                         WHERE product_id = %d
                         AND expiry_date IS NULL
                         AND status = 'active'
                         AND quantity > 0
                         $loc_filter
                         ORDER BY created_at ASC LIMIT 1",
                        $item->product_id
                    ));
                }

                // ─── Branch A: Match encontrado — incrementar quantidade e atualizar setor físico ───
                if ($existing_batch) {
                    $new_total = $existing_batch->quantity + $divergence;
                    $wpdb->update(
                        $batches_table,
                        array(
                            'quantity' => $new_total,
                            'sector_id' => $item_sector_id,  // atualiza localização física auditada
                        ),
                        array('id' => $existing_batch->id),
                        array('%f', '%d'),
                        array('%d')
                    );
                    $batch_updated = true;

                    $results[] = array(
                        'product_id' => $item->product_id,
                        'product_name' => $item->product_name,
                        'status' => 'surplus_merged',
                        'message' => sprintf(
                            'Sobra de %s adicionada ao lote %s (validade: %s)',
                            $divergence,
                            $existing_batch->batch_code,
                            $existing_batch->expiry_date ?: 'sem validade'
                        )
                    );

                    // Vincular transação ao lote
                    $wpdb->update(
                        $transactions_table,
                        array('batch_id' => $existing_batch->id),
                        array('id' => $transaction_id),
                        array('%d'),
                        array('%d')
                    );
                }

                // ─── Branch B: Sem match — criar novo lote com custo herdado ───
                if (!$batch_updated) {
                    $batch_code = 'AUDIT-' . date('ymd') . '-' . $session_id . '-' . $item->id;

                    $batch_location_id = $session_parent_location_id;
                    $batch_sector_id = $item_sector_id;

                    // Cost Inheritance: usa custo médio móvel do produto.
                    // $divergence_cost já foi calculado com fallback duplo (average_cost → IN transactions).
                    // Garantia final: nunca persistir 0.00 — isso crasharia o cálculo de CMV.
                    $inherited_cost = $divergence_cost > 0 ? $divergence_cost : $product_avg_cost;
                    if ($inherited_cost <= 0) {
                        error_log("SISTUR Blind Inventory WARN: custo não apurado para produto #{$item->product_id}. Lote AUDIT criado com custo 0.");
                    }

                    $new_batch_data = array(
                        'product_id' => $item->product_id,
                        'location_id' => $batch_location_id,
                        'sector_id' => $batch_sector_id,
                        'batch_code' => $batch_code,
                        'expiry_date' => !empty($item->expiry_date) ? $item->expiry_date : null,
                        'cost_price' => $inherited_cost,  // nunca 0 — herdado do CMV médio
                        'quantity' => $divergence,
                        'initial_quantity' => $divergence,
                        'status' => 'active',
                        'entry_date' => current_time('mysql'),
                        'created_by_user_id' => $user_id
                    );

                    $wpdb->insert($batches_table, $new_batch_data);
                    $new_batch_id = $wpdb->insert_id;

                    if (!$new_batch_id) {
                        error_log("SISTUR Blind Inventory: Falha ao criar lote AUDIT para produto #{$item->product_id}. DB error: " . $wpdb->last_error);
                    }

                    $results[] = array(
                        'product_id' => $item->product_id,
                        'product_name' => $item->product_name,
                        'status' => 'surplus_new',
                        'message' => sprintf(
                            'Sobra de %s gerou novo lote %s (custo: R$ %s, local: %s)',
                            $divergence,
                            $batch_code,
                            number_format($divergence_cost, 2, ',', '.'),
                            $session_location_id ?: 'sem local'
                        )
                    );

                    $wpdb->update(
                        $transactions_table,
                        array('batch_id' => $new_batch_id),
                        array('id' => $transaction_id),
                        array('%d'),
                        array('%d')
                    );
                }

                $adjustments_created++;
            }
        }

        // ─── Recalcular cached_stock de todos os produtos afetados ───
        foreach (array_keys($affected_product_ids) as $pid) {
            $new_stock = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(quantity), 0) FROM $batches_table
                 WHERE product_id = %d AND status = 'active'",
                $pid
            ));
            $wpdb->update(
                $products_table,
                array('cached_stock' => $new_stock),
                array('id' => $pid),
                array('%f'),
                array('%d')
            );
        }

        // Finalizar sessão
        $wpdb->update(
            $sessions_table,
            array(
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
                'total_divergence_value' => $total_divergence_value
            ),
            array('id' => $session_id),
            array('%s', '%s', '%f'),
            array('%d')
        );

        return new WP_REST_Response(array(
            'success' => true,
            'session_id' => $session_id,
            'summary' => array(
                'items_processed' => count($items),
                'losses_created' => $losses_created,
                'adjustments_created' => $adjustments_created,
                'total_divergence_value' => round($total_divergence_value, 2)
            ),
            'results' => $results,
            'message' => 'Inventário processado com sucesso'
        ), 200);
    }

    /**
     * GET /stock/blind-inventory/{session_id}/report
     * Retorna relatório detalhado com impacto financeiro
     */
    public function get_blind_inventory_report($request)
    {
        global $wpdb;

        $session_id = (int) $request->get_param('session_id');

        $sessions_table = $wpdb->prefix . 'sistur_blind_inventory_sessions';
        $items_table = $wpdb->prefix . 'sistur_blind_inventory_items';
        $products_table = $wpdb->prefix . 'sistur_products';
        $units_table = $wpdb->prefix . 'sistur_units';
        $locations_table = $wpdb->prefix . 'sistur_storage_locations';

        // Buscar sessão
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, u.display_name as user_name,
                loc.name as location_name,
                sec.name as sector_name
             FROM $sessions_table s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             LEFT JOIN $locations_table loc ON s.location_id = loc.id
             LEFT JOIN $locations_table sec ON s.sector_id = sec.id
             WHERE s.id = %d",
            $session_id
        ));

        if (!$session) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Sessão não encontrada'
            ), 404);
        }

        // Buscar itens com divergência
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT 
                i.*,
                p.name as product_name,
                u.symbol as unit_symbol
            FROM $items_table i
            INNER JOIN $products_table p ON i.product_id = p.id
            LEFT JOIN $units_table u ON p.base_unit_id = u.id
            WHERE i.session_id = %d
            ORDER BY i.divergence_value ASC
        ", $session_id));

        // Calcular totais
        $total_losses = 0;
        $total_gains = 0;
        $items_with_loss = 0;
        $items_with_gain = 0;

        foreach ($items as $item) {
            if ($item->divergence < 0) {
                $total_losses += abs($item->divergence_value);
                $items_with_loss++;
            } elseif ($item->divergence > 0) {
                $total_gains += $item->divergence_value;
                $items_with_gain++;
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'session' => array(
                'id' => (int) $session->id,
                'status' => $session->status,
                'started_at' => $session->started_at,
                'completed_at' => $session->completed_at,
                'user_name' => $session->user_name,
                'notes' => $session->notes,
                'total_divergence_value' => round((float) $session->total_divergence_value, 2),
                'location_id' => $session->location_id ? (int) $session->location_id : null,
                'location_name' => $session->location_name,
                'sector_id' => $session->sector_id ? (int) $session->sector_id : null,
                'sector_name' => $session->sector_name
            ),
            'summary' => array(
                'total_items' => count($items),
                'items_with_loss' => $items_with_loss,
                'items_with_gain' => $items_with_gain,
                'total_losses_value' => round($total_losses, 2),
                'total_gains_value' => round($total_gains, 2),
                'net_impact' => round($total_gains - $total_losses, 2)
            ),
            'items' => array_map(function ($item) {
                return array(
                    'product_id' => (int) $item->product_id,
                    'product_name' => $item->product_name,
                    'unit' => $item->unit_symbol,
                    'theoretical_qty' => (float) $item->theoretical_qty,
                    'physical_qty' => (float) $item->physical_qty,
                    'divergence' => (float) $item->divergence,
                    'unit_cost' => round((float) $item->unit_cost, 4),
                    'divergence_value' => round((float) $item->divergence_value, 2),
                    'has_loss' => $item->loss_id ? true : false
                );
            }, $items)
        ), 200);
    }

    /**
     * GET /stock/blind-inventory
     * Lista todas as sessões de inventário cego
     */
    public function get_blind_inventory_sessions($request)
    {
        global $wpdb;

        $sessions_table = $wpdb->prefix . 'sistur_blind_inventory_sessions';
        $items_table = $wpdb->prefix . 'sistur_blind_inventory_items';
        $locations_table = $wpdb->prefix . 'sistur_storage_locations';

        $sessions = $wpdb->get_results("
            SELECT 
                s.*,
                u.display_name as user_name,
                loc.name as location_name,
                sec.name as sector_name,
                (SELECT COUNT(*) FROM $items_table WHERE session_id = s.id) as items_count,
                (SELECT COUNT(*) FROM $items_table WHERE session_id = s.id AND physical_qty IS NOT NULL) as items_filled
            FROM $sessions_table s
            LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
            LEFT JOIN $locations_table loc ON s.location_id = loc.id
            LEFT JOIN $locations_table sec ON s.sector_id = sec.id
            ORDER BY s.started_at DESC
            LIMIT 50
        ");

        return new WP_REST_Response(array(
            'success' => true,
            'sessions' => array_map(function ($s) {
                return array(
                    'id' => (int) $s->id,
                    'status' => $s->status,
                    'user_name' => $s->user_name,
                    'started_at' => $s->started_at,
                    'completed_at' => $s->completed_at,
                    'items_count' => (int) $s->items_count,
                    'items_filled' => (int) $s->items_filled,
                    'total_divergence_value' => round((float) $s->total_divergence_value, 2),
                    'location_id' => $s->location_id ? (int) $s->location_id : null,
                    'location_name' => $s->location_name,
                    'sector_id' => $s->sector_id ? (int) $s->sector_id : null,
                    'sector_name' => $s->sector_name
                );
            }, $sessions)
        ), 200);
    }

    // ============================================
    // Métodos de Relatório CMV/DRE (v2.9.0)
    // ============================================

    /**
     * Calcula o valor do estoque em uma data específica
     * Considera lotes ativos naquela data
     */
    private function calculate_stock_value_at_date($date)
    {
        global $wpdb;

        $batches_table = $wpdb->prefix . 'sistur_inventory_batches';

        // Lotes que existiam até a data e não foram esgotados antes dela
        $value = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(b.quantity * b.cost_per_unit), 0)
            FROM $batches_table b
            WHERE b.created_at <= %s
              AND (b.depleted_at IS NULL OR b.depleted_at > %s)
        ", $date . ' 23:59:59', $date . ' 23:59:59'));

        return (float) $value;
    }

    // =========================================================================
    // Faturamento Macro (v2.16.0)
    // =========================================================================

    /**
     * POST /stock/revenue
     * Registra faturamento bruto diário (Modo Macro / Fechar Caixa).
     */
    public function create_macro_revenue($request)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sistur_macro_revenue';

        $result = $wpdb->insert($table, array(
            'revenue_date' => $request->get_param('revenue_date'),
            'total_amount' => round((float) $request->get_param('total_amount'), 2),
            'category' => $request->get_param('category') ?: 'Geral',
            'notes' => $request->get_param('notes') ?: null,
            'user_id' => get_current_user_id(),
        ), array('%s', '%f', '%s', '%s', '%d'));

        if ($result === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Erro ao salvar faturamento: ' . $wpdb->last_error
            ), 500);
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $wpdb->insert_id
        ));

        return new WP_REST_Response(array('success' => true, 'data' => $row), 201);
    }

    /**
     * GET /stock/revenue
     * Lista faturamentos por período. Retorna também totais por categoria.
     */
    public function get_macro_revenue($request)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sistur_macro_revenue';
        $start_date = $request->get_param('start_date') ?: date('Y-m-01');
        $end_date = $request->get_param('end_date') ?: date('Y-m-d');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE revenue_date BETWEEN %s AND %s ORDER BY revenue_date DESC, id DESC",
            $start_date,
            $end_date
        ));

        $by_category = $wpdb->get_results($wpdb->prepare(
            "SELECT category, COALESCE(SUM(total_amount), 0) as total
             FROM $table WHERE revenue_date BETWEEN %s AND %s
             GROUP BY category ORDER BY total DESC",
            $start_date,
            $end_date
        ));

        $grand_total = array_sum(array_column($by_category, 'total'));

        return new WP_REST_Response(array(
            'success' => true,
            'period' => array('start' => $start_date, 'end' => $end_date),
            'rows' => $rows,
            'by_category' => $by_category,
            'total' => round((float) $grand_total, 2),
        ), 200);
    }

    /**
     * DELETE /stock/revenue/{id}
     * Remove um lançamento de faturamento macro.
     */
    public function delete_macro_revenue($request)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sistur_macro_revenue';
        $id = (int) $request->get_param('id');

        $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE id = %d", $id));
        if (!$row) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Lançamento não encontrado.'), 404);
        }

        $wpdb->delete($table, array('id' => $id), array('%d'));

        return new WP_REST_Response(array('success' => true, 'message' => 'Lançamento removido.'), 200);
    }

    /**
     * GET /stock/reports/cmv
     * Calcula CMV para um período: Estoque Inicial + Compras - Estoque Final
     */
    public function get_cmv_report($request)
    {
        global $wpdb;

        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');

        // Validar datas
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid date format. Use YYYY-MM-DD'
            ), 400);
        }

        $transactions_table = $wpdb->prefix . 'sistur_inventory_transactions';
        $losses_table = $wpdb->prefix . 'sistur_inventory_losses';

        // Estoque Inicial = dia anterior ao período inicial
        $prev_day = date('Y-m-d', strtotime($start_date . ' -1 day'));
        $opening_stock = $this->calculate_stock_value_at_date($prev_day);

        // Estoque Final = último dia do período
        $closing_stock = $this->calculate_stock_value_at_date($end_date);

        // Compras no período (type = IN)
        $purchases = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(total_cost), 0)
            FROM $transactions_table
            WHERE type = 'IN'
              AND created_at BETWEEN %s AND %s
        ", $start_date . ' 00:00:00', $end_date . ' 23:59:59'));

        // Perdas no período (para breakdown)
        $losses = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(total_loss_value), 0)
            FROM $losses_table
            WHERE created_at BETWEEN %s AND %s
        ", $start_date . ' 00:00:00', $end_date . ' 23:59:59'));

        // CMV = Estoque Inicial + Compras - Estoque Final
        $cmv = $opening_stock + (float) $purchases - $closing_stock;

        return new WP_REST_Response(array(
            'success' => true,
            'period' => array(
                'start' => $start_date,
                'end' => $end_date
            ),
            'data' => array(
                'opening_stock' => round($opening_stock, 2),
                'purchases' => round((float) $purchases, 2),
                'closing_stock' => round($closing_stock, 2),
                'cmv' => round($cmv, 2),
                'losses_included' => round((float) $losses, 2)
            ),
            'formula' => 'CMV = Estoque Inicial + Compras - Estoque Final'
        ), 200);
    }

    /**
     * GET /stock/reports/dre
     * Relatório DRE simplificado: Receita - CMV = Resultado Bruto
     */
    public function get_dre_report($request)
    {
        global $wpdb;

        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');

        // Validar datas
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid date format. Use YYYY-MM-DD'
            ), 400);
        }

        $transactions_table = $wpdb->prefix . 'sistur_inventory_transactions';
        $products_table = $wpdb->prefix . 'sistur_products';
        $macro_table = $wpdb->prefix . 'sistur_macro_revenue';

        // Calcular CMV primeiro (fórmula de consumo — independente do modo de vendas)
        $cmv_request = new WP_REST_Request('GET');
        $cmv_request->set_param('start_date', $start_date);
        $cmv_request->set_param('end_date', $end_date);
        $cmv_response = $this->get_cmv_report($cmv_request);
        $cmv_data = $cmv_response->get_data();
        $cmv = $cmv_data['data']['cmv'];

        // ── Fonte de Receita: MACRO vs MICRO ──────────────────────────────────
        $sales_mode = get_option('sistur_sales_mode', 'MICRO');

        if ($sales_mode === 'MACRO') {
            // Receita vem da tabela de faturamento diário (sistur_macro_revenue)
            $revenue = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(total_amount), 0) FROM $macro_table
                 WHERE revenue_date BETWEEN %s AND %s",
                $start_date,
                $end_date
            ));

            // Breakdown por categoria de receita (custo = CMV agregado, não por categoria)
            $by_category_raw = $wpdb->get_results($wpdb->prepare(
                "SELECT category, COALESCE(SUM(total_amount), 0) as revenue, 0 as cost
                 FROM $macro_table
                 WHERE revenue_date BETWEEN %s AND %s
                 GROUP BY category ORDER BY revenue DESC",
                $start_date,
                $end_date
            ));
        } else {
            // MICRO: Receita = SUM(quantidade vendida * preço de venda do produto)
            $revenue = (float) $wpdb->get_var($wpdb->prepare("
                SELECT COALESCE(SUM(t.quantity * p.selling_price), 0)
                FROM $transactions_table t
                INNER JOIN $products_table p ON t.product_id = p.id
                WHERE t.type = 'SALE'
                  AND t.created_at BETWEEN %s AND %s
            ", $start_date . ' 00:00:00', $end_date . ' 23:59:59'));

            // Breakdown por categoria de produto
            $by_category_raw = $wpdb->get_results($wpdb->prepare("
                SELECT
                    COALESCE(c.name, 'Sem Categoria') as category,
                    SUM(t.quantity * p.selling_price) as revenue,
                    SUM(t.total_cost) as cost
                FROM $transactions_table t
                INNER JOIN $products_table p ON t.product_id = p.id
                LEFT JOIN {$wpdb->prefix}sistur_product_categories c ON p.category_id = c.id
                WHERE t.type = 'SALE'
                  AND t.created_at BETWEEN %s AND %s
                GROUP BY c.id, c.name
                ORDER BY revenue DESC
            ", $start_date . ' 00:00:00', $end_date . ' 23:59:59'));
        }
        // ──────────────────────────────────────────────────────────────────────

        $gross_result = $revenue - $cmv;
        $cmv_percentage = $revenue > 0 ? ($cmv / $revenue) * 100 : 0;

        // Determinar status do CMV%
        $cmv_status = 'good';
        $cmv_alert = 'green';
        if ($cmv_percentage > 40) {
            $cmv_status = 'critical';
            $cmv_alert = 'red';
        } elseif ($cmv_percentage > 30) {
            $cmv_status = 'warning';
            $cmv_alert = 'yellow';
        }

        $by_category = $by_category_raw;

        return new WP_REST_Response(array(
            'success' => true,
            'period' => array(
                'start' => $start_date,
                'end' => $end_date
            ),
            'dre' => array(
                'revenue' => round($revenue, 2),
                'cmv' => round($cmv, 2),
                'gross_result' => round($gross_result, 2),
                'cmv_percentage' => round($cmv_percentage, 2),
                'cmv_status' => $cmv_status,
                'cmv_alert' => $cmv_alert
            ),
            'cmv_breakdown' => $cmv_data['data'],
            'by_category' => array_map(function ($row) {
                return array(
                    'category' => $row->category,
                    'revenue' => round((float) $row->revenue, 2),
                    'cost' => round((float) $row->cost, 2)
                );
            }, $by_category),
            'thresholds' => array(
                'good' => '< 30%',
                'warning' => '30% - 40%',
                'critical' => '> 40%'
            ),
            'sales_mode' => $sales_mode,  // 'MICRO' ou 'MACRO' — informa o frontend da fonte de receita
        ), 200);
    }

    /**
     * Adicionar item extra a uma sessão de inventário
     */
    public function add_blind_inventory_item($request)
    {
        global $wpdb;
        $session_id = $request['id'];
        $product_id = $request['product_id'];

        // Verificar sessão
        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}blind_inventory_sessions WHERE id = %d", $session_id));
        if (!$session) {
            return new WP_Error('not_found', 'Sessão não encontrada', array('status' => 404));
        }

        if ($session->status !== 'open' && $session->status !== 'in_progress') {
            return new WP_Error('invalid_status', 'Sessão não está aberta ou em andamento', array('status' => 400));
        }

        // Verificar se item já existe
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->prefix}blind_inventory_items WHERE session_id = %d AND product_id = %d", $session_id, $product_id));
        if ($exists) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Item já está na lista'), 400);
        }

        // Buscar dados do produto para cálculo teórico
        $filter_loc_sql = "";
        // Lógica simplificada: Se tem setor, filtra por setor. Se tem local, filtra por local.
        // A lógica original de create_session é mais complexa (inclui filhos), mas aqui vamos simplificar para consistência rápida.
        // O ideal seria extrair get_theoretical_stock para um método helper.

        $location_ids = array();
        if ($session->sector_id) {
            $location_ids[] = $session->sector_id;
        } elseif ($session->location_id) {
            $location_ids[] = $session->location_id;
            // Incluir filhos (mesma lógica do create_session)
            $child_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->prefix}locations WHERE parent_id = %d AND is_active = 1", $session->location_id));
            $location_ids = array_merge($location_ids, $child_ids);
        }

        if (!empty($location_ids)) {
            $ids_str = implode(',', array_map('intval', $location_ids));
            $filter_loc_sql = " AND b.location_id IN ($ids_str)";
        }

        $product_data = $wpdb->get_row($wpdb->prepare("
            SELECT 
                p.id,
                p.name,
                p.average_cost,
                u.symbol as unit_symbol,
                COALESCE(SUM(b.quantity), 0) as theoretical_stock
            FROM {$this->prefix}products p
            LEFT JOIN {$this->prefix}inventory_batches b ON b.product_id = p.id AND b.status = 'active' $filter_loc_sql
            LEFT JOIN {$this->prefix}units u ON p.base_unit_id = u.id
            WHERE p.id = %d
            GROUP BY p.id
        ", $product_id));

        if (!$product_data) {
            return new WP_Error('not_found', 'Produto não encontrado', array('status' => 404));
        }

        // v2.15.2: Capturar current_sector_id do modo wizard
        $current_sector_id = null;
        if (!empty($session->wizard_mode)) {
            $sectors_list = json_decode($session->sectors_list, true) ?: array();
            $current_index = (int) ($session->current_sector_index ?? 0);
            if ($current_index < count($sectors_list)) {
                $current_sector_id = $sectors_list[$current_index];
            }
        } elseif (!empty($session->sector_id)) {
            // Modo flat list com setor específico
            $current_sector_id = $session->sector_id;
        }

        // Inserir item
        $wpdb->insert(
            $this->prefix . 'blind_inventory_items',
            array(
                'session_id' => $session_id,
                'product_id' => $product_id,
                'theoretical_qty' => $product_data->theoretical_stock,
                'unit_cost' => $product_data->average_cost ?: 0,
                'physical_qty' => null,
                'sector_id' => $current_sector_id
            ),
            array('%d', '%d', '%f', '%f', '%f', '%d')
        );

        $item_data = array(
            'product_id' => $product_id,
            'product_name' => $product_data->name,
            'unit_symbol' => $product_data->unit_symbol,
            'theoretical_qty' => $product_data->theoretical_stock,
            'physical_qty' => null,
            'batch_code' => '',
            'expiry_date' => ''
        );

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Item adicionado',
            'item' => $item_data
        ));
    }

    // ============================================
    // Wizard Setor-a-Setor (v2.15.0)
    // ============================================

    /**
     * GET /stock/blind-inventory/{id}/current-sector
     * Retorna itens do setor atual no modo wizard
     *
     * Se os itens deste setor ainda não existem na sessão,
     * gera-os automaticamente a partir dos lotes existentes.
     *
     * @since 2.15.0
     */
    public function get_current_sector_items($request)
    {
        global $wpdb;

        $session_id = (int) $request->get_param('id');
        $sessions_table = $wpdb->prefix . 'sistur_blind_inventory_sessions';
        $items_table = $wpdb->prefix . 'sistur_blind_inventory_items';
        $products_table = $wpdb->prefix . 'sistur_products';
        $batches_table = $wpdb->prefix . 'sistur_inventory_batches';
        $units_table = $wpdb->prefix . 'sistur_units';
        $locations_table = $wpdb->prefix . 'sistur_storage_locations';

        // Buscar sessão
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $sessions_table WHERE id = %d",
            $session_id
        ));

        if (!$session) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Sessão não encontrada'
            ), 404);
        }

        if (empty($session->wizard_mode)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Sessão não está em modo wizard'
            ), 400);
        }

        $sectors_list = json_decode($session->sectors_list, true) ?: array();
        $current_index = (int) ($session->current_sector_index ?? 0);

        if ($current_index >= count($sectors_list)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Todos os setores já foram percorridos'
            ), 400);
        }

        $current_sector_id = $sectors_list[$current_index];

        // Buscar nome do setor atual
        $sector_name = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM $locations_table WHERE id = %d",
            $current_sector_id
        ));

        // Verificar se já existem itens para este setor nesta sessão
        // Usamos uma tag no campo notes ou um mecanismo simples:
        // Itens são criados por setor sob demanda. Verificamos se itens
        // já foram gerados checando a existência para este sector_index.
        //
        // Estratégia: Na primeira vez que um setor é acessado, geramos itens.
        // Identificamos itens do setor pelo product_id que tem lotes nesse setor.

        // Verificar se já temos itens de inventário para este setor
        // Usando sector_id in batches para filtrar
        $has_sector_col = $wpdb->get_var("SHOW COLUMNS FROM $batches_table LIKE 'sector_id'");

        if ($has_sector_col) {
            $filter_sql = $wpdb->prepare("b.sector_id = %d", $current_sector_id);
        } else {
            $filter_sql = $wpdb->prepare("b.location_id = %d", $current_sector_id);
        }

        // Buscar produtos que TÊM estoque neste setor
        $sector_products = $wpdb->get_results("
            SELECT p.id, p.name, p.average_cost,
                   COALESCE(SUM(b.quantity), 0) as theoretical_stock
            FROM $products_table p
            INNER JOIN $batches_table b ON b.product_id = p.id
                AND b.status = 'active'
                AND b.quantity > 0
                AND $filter_sql
            WHERE p.status = 'active'
            GROUP BY p.id
            ORDER BY p.name
        ");

        // v2.15.4: Diagnóstico - logar produtos encontrados no setor
        error_log("SISTUR Debug [Sessão #{$session_id}]: get_current_sector_items - Setor #{$current_sector_id} ({$sector_name})");
        error_log("  Filter SQL: {$filter_sql}");
        error_log("  Produtos encontrados: " . count($sector_products));
        if (!empty($sector_products)) {
            foreach ($sector_products as $p) {
                error_log("    - Produto #{$p->id} ({$p->name}): {$p->theoretical_stock} unidades");
            }
        } else {
            error_log("    - NENHUM produto encontrado! Verificar lotes com sector_id={$current_sector_id}");
        }

        // Também incluir produtos com default_sector_id neste setor mas sem estoque aqui
        $has_default_sector_col = $wpdb->get_var("SHOW COLUMNS FROM $products_table LIKE 'default_sector_id'");
        if ($has_default_sector_col) {
            $found_ids = array_map(function ($p) {
                return (int) $p->id; }, $sector_products);
            $exclude_sql = !empty($found_ids) ? "AND p.id NOT IN (" . implode(',', $found_ids) . ")" : "";

            $extra = $wpdb->get_results($wpdb->prepare("
                SELECT p.id, p.name, p.average_cost, 0 as theoretical_stock
                FROM $products_table p
                WHERE p.status = 'active'
                  AND p.default_sector_id = %d
                  $exclude_sql
                ORDER BY p.name
            ", $current_sector_id));

            if (!empty($extra)) {
                $sector_products = array_merge($sector_products, $extra);
            }
        }

        // Garantir que itens existam na tabela blind_inventory_items
        // v2.15.4: Verificar quais produtos deste setor JÁ estão na sessão (incluindo sector_id)
        $existing_product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT product_id FROM $items_table
             WHERE session_id = %d AND sector_id = %d",
            $session_id,
            $current_sector_id
        ));
        $existing_map = array_flip($existing_product_ids);

        $new_inserted = 0;
        foreach ($sector_products as $product) {
            if (!isset($existing_map[$product->id])) {
                // v2.15.4: Inserir com sector_id para permitir mesmo produto em setores diferentes
                $wpdb->insert($items_table, array(
                    'session_id' => $session_id,
                    'product_id' => $product->id,
                    'sector_id' => $current_sector_id,
                    'theoretical_qty' => $product->theoretical_stock,
                    'unit_cost' => $product->average_cost ?: 0
                ), array('%d', '%d', '%d', '%f', '%f'));
                $new_inserted++;
            }
        }

        if ($new_inserted > 0) {
            error_log("SISTUR Wizard: Gerados {$new_inserted} itens para setor #{$current_sector_id} na sessão #{$session_id}");
        } else {
            error_log("SISTUR Wizard: Nenhum item novo inserido. Existing product IDs: " . implode(',', $existing_product_ids));
        }

        // Agora buscar os itens deste setor (filtrar pelo product_id E sector_id) - v2.15.4
        $sector_product_ids = array_map(function ($p) {
            return (int) $p->id; }, $sector_products);

        $items = array();
        if (!empty($sector_product_ids)) {
            $ids_str = implode(',', $sector_product_ids);
            // v2.15.4: Filtrar por sector_id para evitar retornar itens de outros setores
            $items = $wpdb->get_results($wpdb->prepare("
                SELECT
                    i.id,
                    i.product_id,
                    p.name as product_name,
                    u.symbol as unit_symbol,
                    i.physical_qty,
                    i.theoretical_qty,
                    i.expiry_date,
                    i.batch_id,
                    b.batch_code
                FROM $items_table i
                INNER JOIN $products_table p ON i.product_id = p.id
                LEFT JOIN $units_table u ON p.base_unit_id = u.id
                LEFT JOIN $batches_table b ON i.batch_id = b.id
                WHERE i.session_id = %d
                  AND i.sector_id = %d
                  AND i.product_id IN ($ids_str)
                ORDER BY p.name
            ", $session_id, $current_sector_id));
        }

        // v2.15.4: Diagnóstico final - logar items retornados
        error_log("SISTUR Debug [Sessão #{$session_id}]: Retornando " . count($items) . " items para setor #{$current_sector_id}");
        if (!empty($items)) {
            foreach ($items as $item) {
                error_log("    - Item #{$item->id}: {$item->product_name} (theoretical: {$item->theoretical_qty}, physical: " . ($item->physical_qty ?? 'NULL') . ")");
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'sector' => array(
                'id' => $current_sector_id,
                'name' => $sector_name,
                'index' => $current_index,
                'total' => count($sectors_list),
                'is_last' => ($current_index >= count($sectors_list) - 1),
            ),
            'items' => $items,
            'items_count' => count($items),
        ), 200);
    }

    /**
     * POST /stock/blind-inventory/{id}/advance
     * Avança para o próximo setor no modo wizard
     *
     * Incrementa current_sector_index e retorna informações do novo setor.
     * Se era o último setor, retorna flag de conclusão.
     *
     * @since 2.15.0
     */
    public function advance_to_next_sector($request)
    {
        global $wpdb;

        $session_id = (int) $request->get_param('id');
        $sessions_table = $wpdb->prefix . 'sistur_blind_inventory_sessions';
        $locations_table = $wpdb->prefix . 'sistur_storage_locations';

        // Buscar sessão
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $sessions_table WHERE id = %d",
            $session_id
        ));

        if (!$session) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Sessão não encontrada'
            ), 404);
        }

        if ($session->status !== 'in_progress') {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Sessão não está em progresso'
            ), 400);
        }

        if (empty($session->wizard_mode)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Sessão não está em modo wizard'
            ), 400);
        }

        $sectors_list = json_decode($session->sectors_list, true) ?: array();
        $current_index = (int) ($session->current_sector_index ?? 0);
        $next_index = $current_index + 1;

        if ($next_index >= count($sectors_list)) {
            // Último setor alcançado — sinalizar conclusão do wizard
            return new WP_REST_Response(array(
                'success' => true,
                'completed' => true,
                'message' => 'Todos os setores foram percorridos! Pronto para finalizar.',
                'current_sector_index' => $current_index,
                'total_sectors' => count($sectors_list),
            ), 200);
        }

        // Avançar para o próximo setor
        $wpdb->update(
            $sessions_table,
            array('current_sector_index' => $next_index),
            array('id' => $session_id),
            array('%d'),
            array('%d')
        );

        // Buscar nome do novo setor
        $next_sector_id = $sectors_list[$next_index];
        $sector_name = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM $locations_table WHERE id = %d",
            $next_sector_id
        ));

        error_log("SISTUR Wizard: Sessão #{$session_id} avançou para setor #{$next_sector_id} (index {$next_index})");

        return new WP_REST_Response(array(
            'success' => true,
            'completed' => false,
            'message' => "Avançou para setor: {$sector_name}",
            'current_sector_index' => $next_index,
            'total_sectors' => count($sectors_list),
            'sector' => array(
                'id' => $next_sector_id,
                'name' => $sector_name,
                'index' => $next_index,
                'is_last' => ($next_index >= count($sectors_list) - 1),
            )
        ), 200);
    }

}

