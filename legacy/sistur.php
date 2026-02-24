<?php
/**
 * Plugin Name: SISTUR - Sistema de Turismo
 * Plugin URI: https://webfluence.com.br/girassol/
 * Description: Sistema completo para gerenciamento de operações turísticas incluindo RH, ponto eletrônico, inventário e leads
 * Version: 2.7.0
 * Author: WebFluence
 * Author URI: https://webfluence.com.br/girassol/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: sistur
 * Domain Path: /languages
 */

// Se este arquivo for chamado diretamente, abortar.
if (!defined('WPINC')) {
    die;
}

/**
 * Versões do plugin e do banco de dados
 */
define('SISTUR_VERSION', '2.13.1');
define('SISTUR_DB_VERSION', '2.0.0');
define('SISTUR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SISTUR_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Código que roda durante a ativação do plugin
 */
function activate_sistur()
{
    require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-activator.php';
    SISTUR_Activator::activate();
}

/**
 * Código que roda durante a desativação do plugin
 */
function deactivate_sistur()
{
    require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-activator.php';
    SISTUR_Activator::deactivate();
}

register_activation_hook(__FILE__, 'activate_sistur');
register_deactivation_hook(__FILE__, 'deactivate_sistur');

/**
 * Classe principal do plugin SISTUR
 */
class SISTUR
{

    /**
     * Instância única da classe (Singleton)
     */
    private static $instance = null;

    /**
     * Versão do plugin
     */
    protected $version;

    /**
     * Nome do plugin
     */
    protected $plugin_name;

    /**
     * Construtor privado (Singleton)
     */
    private function __construct()
    {
        $this->version = SISTUR_VERSION;
        $this->plugin_name = 'sistur';

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->init_session();
        $this->set_timezone();
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
     * Carrega as dependências necessárias
     */
    private function load_dependencies()
    {
        // Carregar autoload do Composer (para bibliotecas externas como endroid/qr-code)
        if (file_exists(SISTUR_PLUGIN_DIR . 'vendor/autoload.php')) {
            require_once SISTUR_PLUGIN_DIR . 'vendor/autoload.php';
        }

        // Classes principais
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-admin.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-employees.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-time-tracking.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-punch-processing.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-qrcode.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-wifi-networks.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-authorized-locations.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-inventory.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-leads.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-leads-db.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-session.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-diagnostics.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-permissions.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-shift-patterns.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-schedule-exceptions.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/admin-notices-migration.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/migrations/migrate-shift-patterns.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/create-weekly-patterns.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-pagination.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-search.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-export.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-audit.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-timebank-manager.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/login-funcionario-new.php';

        // ERP Modular v2.0 - Novas classes
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-portal.php';
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-approvals.php';

        // Módulo de RH v2.0
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-rh-module.php';

        // Stock Management v2.1 - Gestão de Estoque Avançada
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-stock-api.php';

        // Unit Converter v2.2.1 - Conversão de Unidades
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-unit-converter.php';

        // Recipe/Technical Sheet v2.2 - Ficha Técnica
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-recipe-manager.php';

        // Integração com Elementor
        if (defined('ELEMENTOR_VERSION')) {
            require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-elementor-handler.php';
        }
    }

    /**
     * Define a localização do plugin
     */
    private function set_locale()
    {
        add_action('plugins_loaded', array($this, 'load_plugin_textdomain'));
    }

    /**
     * Carrega os arquivos de tradução
     */
    public function load_plugin_textdomain()
    {
        // DEBUG: Log ALL requests to admin-ajax to see if we are receiving data
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $user_status = is_user_logged_in() ? 'User ID: ' . get_current_user_id() : 'GUEST';
            error_log('SISTUR DEBUG (plugins_loaded): AJAX Request received. Action: ' . ($_REQUEST['action'] ?? 'NONE') . ". Auth: $user_status");
            error_log('SISTUR DEBUG (plugins_loaded): POST Data: ' . print_r($_POST, true));
        }

        load_plugin_textdomain(
            'sistur',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Registra os hooks do admin
     */
    private function define_admin_hooks()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'admin_init'));

        // Inicializar classes singleton
        SISTUR_Admin::get_instance();
        SISTUR_Employees::get_instance();
        SISTUR_Time_Tracking::get_instance();
        SISTUR_Punch_Processing::get_instance();
        SISTUR_QRCode::get_instance();
        SISTUR_WiFi_Networks::get_instance();
        SISTUR_Authorized_Locations::get_instance();
        SISTUR_Inventory::get_instance();
        SISTUR_Leads::get_instance();
        SISTUR_Diagnostics::get_instance();
        SISTUR_Shift_Patterns::get_instance();
        SISTUR_Schedule_Exceptions::get_instance();
        SISTUR_Timebank_Manager::get_instance();

        // ERP Modular v2.0 - Novas classes
        SISTUR_Portal::get_instance();
        SISTUR_Approvals::get_instance();

        // Stock Management v2.1 - API REST
        SISTUR_Stock_API::get_instance();
    }

    /**
     * Registra os hooks públicos
     */
    private function define_public_hooks()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_scripts'));
        add_action('init', array($this, 'register_shortcodes'));
        add_action('template_redirect', array($this, 'handle_template_redirect'));
        add_action('init', array($this, 'setup_homepage'));
    }

    /**
     * Inicializa a sessão segura
     */
    private function init_session()
    {
        SISTUR_Session::get_instance();
    }

    /**
     * Define o timezone para São Paulo
     */
    private function set_timezone()
    {
        date_default_timezone_set('America/Sao_Paulo');
    }

    /**
     * Adiciona menus ao admin
     */
    public function add_admin_menu()
    {
        // Menu principal
        add_menu_page(
            __('SISTUR', 'sistur'),
            __('SISTUR', 'sistur'),
            'manage_options',
            'sistur',
            array($this, 'render_dashboard'),
            'dashicons-palmtree',
            30
        );

        // Dashboard
        add_submenu_page(
            'sistur',
            __('Dashboard', 'sistur'),
            __('Dashboard', 'sistur'),
            'manage_options',
            'sistur',
            array($this, 'render_dashboard')
        );

        // Menu RH
        add_menu_page(
            __('RH', 'sistur'),
            __('RH', 'sistur'),
            'manage_options',
            'sistur-rh',
            array($this, 'render_rh_dashboard'),
            'dashicons-groups',
            31
        );

        // Funcionários
        add_submenu_page(
            'sistur-rh',
            __('Funcionários', 'sistur'),
            __('Funcionários', 'sistur'),
            'manage_options',
            'sistur-employees',
            array('SISTUR_Employees', 'render_employees_page')
        );

        // Departamentos
        add_submenu_page(
            'sistur-rh',
            __('Departamentos', 'sistur'),
            __('Departamentos', 'sistur'),
            'manage_options',
            'sistur-departments',
            array('SISTUR_Employees', 'render_departments_page')
        );

        // Registro de Ponto
        add_submenu_page(
            'sistur-rh',
            __('Registro de Ponto', 'sistur'),
            __('Registro de Ponto', 'sistur'),
            'manage_options',
            'sistur-time-tracking',
            array('SISTUR_Time_Tracking', 'render_admin_page')
        );

        // Pagamentos
        add_submenu_page(
            'sistur-rh',
            __('Pagamentos', 'sistur'),
            __('Pagamentos', 'sistur'),
            'manage_options',
            'sistur-payments',
            array('SISTUR_Employees', 'render_payments_page')
        );

        // QR Codes
        add_submenu_page(
            'sistur-rh',
            __('QR Codes', 'sistur'),
            __('QR Codes', 'sistur'),
            'manage_options',
            'sistur-qrcodes',
            array('SISTUR_QRCode', 'render_qrcodes_page')
        );

        // Validação de Ponto - Redes Wi-Fi
        add_submenu_page(
            'sistur-rh',
            __('Validação WiFi', 'sistur'),
            __('Validação WiFi', 'sistur'),
            'manage_options',
            'sistur-wifi-networks',
            array($this, 'render_wifi_networks_page')
        );

        // Validação de Ponto - Localizações
        add_submenu_page(
            'sistur-rh',
            __('Validação GPS', 'sistur'),
            __('Validação GPS', 'sistur'),
            'manage_options',
            'sistur-authorized-locations',
            array($this, 'render_authorized_locations_page')
        );

        // Folha de Ponto
        add_submenu_page(
            'sistur-rh',
            __('Folha de Ponto', 'sistur'),
            __('Folha de Ponto', 'sistur'),
            'manage_options',
            'sistur-attendance-sheet',
            array($this, 'render_attendance_sheet_page')
        );

        // Configurações da Empresa
        add_submenu_page(
            'sistur-rh',
            __('Configurações da Empresa', 'sistur'),
            __('Configurações da Empresa', 'sistur'),
            'manage_options',
            'sistur-company-settings',
            array($this, 'render_company_settings_page')
        );

        // Escalas de Trabalho
        add_submenu_page(
            'sistur-rh',
            __('Escalas de Trabalho', 'sistur'),
            __('Escalas de Trabalho', 'sistur'),
            'manage_options',
            'sistur-shift-patterns',
            array($this, 'render_shift_patterns_page')
        );

        // Funções/Cargos - REMOVIDO: Agora integrado na página de Departamentos
        // As funções agora são gerenciadas diretamente dentro da página de Departamentos
        // para melhor organização e contexto visual
        /*
        add_submenu_page(
            'sistur-rh',
            __('Funções/Cargos', 'sistur'),
            __('Funções', 'sistur'),
            'manage_options',
            'sistur-roles',
            array($this, 'render_roles_page')
        );
        */

        // Página de impressão de folha de ponto (oculta do menu)
        add_submenu_page(
            null, // Submenu oculto
            __('Imprimir Folha de Ponto', 'sistur'),
            __('Imprimir Folha de Ponto', 'sistur'),
            'manage_options',
            'sistur-attendance-sheet-print',
            array($this, 'render_attendance_sheet_print_page')
        );

        // Leads
        add_submenu_page(
            'sistur',
            __('Leads', 'sistur'),
            __('Leads', 'sistur'),
            'manage_options',
            'sistur-leads',
            array('SISTUR_Leads', 'render_leads_page')
        );

        // Feriados (submenu de RH)
        add_submenu_page(
            'sistur-rh',
            __('Feriados', 'sistur'),
            __('📅 Feriados', 'sistur'),
            'manage_options',
            'sistur-holidays',
            array($this, 'render_holidays_page')
        );

        // Auditoria Semanal (submenu de RH)
        add_submenu_page(
            'sistur-rh',
            __('Auditoria Semanal', 'sistur'),
            __('📊 Auditoria Semanal', 'sistur'),
            'manage_options',
            'sistur-weekly-audit',
            array($this, 'render_weekly_audit_page')
        );

        // Menu Restaurante
        add_menu_page(
            __('Restaurante', 'sistur'),
            __('Restaurante', 'sistur'),
            'manage_options',
            'sistur-restaurant',
            array($this, 'render_restaurant_dashboard'),
            'dashicons-food',
            32
        );

        // Estoque (submenu de Restaurante)
        add_submenu_page(
            'sistur-restaurant',
            __('Estoque', 'sistur'),
            __('Estoque', 'sistur'),
            'manage_options',
            'sistur-inventory',
            array('SISTUR_Inventory', 'render_inventory_dashboard')
        );

        // Gestão de Estoque Avançada (submenu de Restaurante)
        add_submenu_page(
            'sistur-restaurant',
            __('Gestão de Estoque', 'sistur'),
            __('📦 Gestão de Estoque', 'sistur'),
            'manage_options',
            'sistur-stock-management',
            array($this, 'render_stock_management_page')
        );

        // Configurações de Estoque (submenu de Restaurante)
        add_submenu_page(
            'sistur-restaurant',
            __('Configurações de Estoque', 'sistur'),
            __('⚙️ Configurações', 'sistur'),
            'manage_options',
            'sistur-stock-settings',
            array($this, 'render_stock_settings_page')
        );

        // Fechar Caixa / Faturamento Macro (v2.16.0)
        add_submenu_page(
            'sistur-restaurant',
            __('Registrar Faturamento', 'sistur'),
            __('💰 Fechar Caixa', 'sistur'),
            'manage_options',
            'sistur-macro-revenue',
            array($this, 'render_macro_revenue_page')
        );

        // Tipos de Produtos - Insumos Genéricos (v2.4)
        add_submenu_page(
            'sistur-restaurant',
            __('Tipos de Produtos', 'sistur'),
            __('🌱 Tipos de Produtos', 'sistur'),
            'manage_options',
            'sistur-product-types',
            array($this, 'render_product_types_page')
        );

        // Inventário Cego - Blind Count (v2.8.0)
        add_submenu_page(
            'sistur-restaurant',
            __('Inventário Cego', 'sistur'),
            __('📋 Inventário Cego', 'sistur'),
            'manage_options',
            'sistur-blind-inventory',
            array($this, 'render_blind_inventory_page')
        );

        // Relatório de Gestão CMV/DRE (v2.9.0)
        add_submenu_page(
            'sistur-restaurant',
            __('Relatório de Gestão', 'sistur'),
            __('📊 Relatório CMV', 'sistur'),
            'manage_options',
            'sistur-cmv-report',
            array($this, 'render_cmv_report_page')
        );


        // Configurações
        add_submenu_page(
            'sistur',
            __('Configurações', 'sistur'),
            __('Configurações', 'sistur'),
            'manage_options',
            'sistur-settings',
            array($this, 'render_settings')
        );

        // Diagnóstico do Sistema
        add_submenu_page(
            'sistur',
            __('Diagnóstico do Sistema', 'sistur'),
            __('🔧 Diagnóstico', 'sistur'),
            'manage_options',
            'sistur-diagnostics',
            array($this, 'render_diagnostics')
        );
    }

    /**
     * Inicialização do admin
     */
    public function admin_init()
    {
        // Registrar configurações
        register_setting('sistur_settings', 'sistur_business_hours');

        // Self-Healing: Garantir que as páginas padrão existam (útil para dev/remote sync)
        if (!class_exists('SISTUR_Activator')) {
            require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-activator.php';
        }
        SISTUR_Activator::create_default_pages();
    }

    /**
     * Carrega scripts e estilos do admin
     */
    public function enqueue_admin_scripts($hook)
    {
        // Verifica se estamos em uma página do SISTUR
        if (strpos($hook, 'sistur') === false) {
            return;
        }

        // CSS - Dashicons primeiro (necessário para ícones)
        wp_enqueue_style('dashicons');
        
        // CSS - Design System
        wp_enqueue_style('sistur-design-system', SISTUR_PLUGIN_URL . 'assets/css/sistur-design-system.css', array('dashicons'), $this->version);
        wp_enqueue_style('sistur-admin', SISTUR_PLUGIN_URL . 'assets/css/admin.css', array('sistur-design-system'), $this->version);
        wp_enqueue_style('sistur-admin-style', SISTUR_PLUGIN_URL . 'assets/css/admin-style.css', array('sistur-design-system'), $this->version);

        // JS - Diagnóstico primeiro (apenas em debug)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_enqueue_script('sistur-diagnostics', SISTUR_PLUGIN_URL . 'assets/js/sistur-diagnostics.js', array(), $this->version, false);
        }


        // JS - Toast primeiro, depois scripts que dependem dele
        wp_enqueue_script('sistur-toast', SISTUR_PLUGIN_URL . 'assets/js/sistur-toast.js', array('jquery'), $this->version, true);
        wp_enqueue_script('sistur-admin', SISTUR_PLUGIN_URL . 'assets/js/admin-script.js', array('jquery', 'sistur-toast'), $this->version, true);



        // Localização
        wp_localize_script('sistur-admin', 'sisturAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sistur_admin_nonce'),
            'strings' => array(
                'confirm_delete' => __('Tem certeza que deseja excluir?', 'sistur'),
                'error' => __('Ocorreu um erro. Tente novamente.', 'sistur'),
                'success' => __('Operação realizada com sucesso!', 'sistur')
            )
        ));
    }

    /**
     * Carrega scripts e estilos públicos
     */
    public function enqueue_public_scripts()
    {
        // CSS - Dashicons primeiro (necessário para ícones no portal do colaborador)
        wp_enqueue_style('dashicons');
        
        // CSS - Design System
        wp_enqueue_style('sistur-design-system', SISTUR_PLUGIN_URL . 'assets/css/sistur-design-system.css', array('dashicons'), $this->version);
        wp_enqueue_style('sistur-public', SISTUR_PLUGIN_URL . 'assets/css/sistur-custom-styles.css', array('sistur-design-system'), $this->version);

        // JS - Toast primeiro, depois scripts que dependem dele
        wp_enqueue_script('sistur-toast', SISTUR_PLUGIN_URL . 'assets/js/sistur-toast.js', array('jquery'), $this->version, true);
        wp_enqueue_script('sistur-public', SISTUR_PLUGIN_URL . 'assets/js/sistur-login.js', array('jquery', 'sistur-toast'), $this->version, true);
        
        // JS - Stock Transfer (v2.10.0)
        wp_enqueue_script('sistur-stock-transfer', SISTUR_PLUGIN_URL . 'assets/js/stock-transfer.js', array('jquery', 'sistur-public'), $this->version, true);

        // Localização
        wp_localize_script('sistur-public', 'sisturPublic', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sistur_public_nonce'),
            'api_root' => esc_url_raw(rest_url()),
            'api_nonce' => wp_create_nonce('wp_rest')
        ));
    }

    /**
     * Registra os shortcodes
     */
    public function register_shortcodes()
    {
        add_shortcode('sistur_login_funcionario', array($this, 'shortcode_login_funcionario'));
        add_shortcode('sistur_painel_funcionario', array($this, 'shortcode_painel_funcionario'));
        add_shortcode('shortcode_painel_funcionario', array($this, 'shortcode_painel_funcionario')); // Alias
        add_shortcode('sistur_registrar_ponto', array($this, 'shortcode_registrar_ponto')); // Novo shortcode simplificado
        add_shortcode('sistur_banco_horas', array($this, 'shortcode_banco_horas')); // Banco de horas
        add_shortcode('sistur_folha_ponto', array('SISTUR_Time_Tracking', 'shortcode_folha_ponto'));
        add_shortcode('sistur_employee_qrcode', array('SISTUR_QRCode', 'shortcode_employee_qrcode'));
        add_shortcode('sistur_inventory', array('SISTUR_Inventory', 'shortcode_inventory'));
        add_shortcode('sistur_debug_horario', array($this, 'shortcode_debug_horario'));
        add_shortcode('sistur_debug_timezone', array($this, 'shortcode_debug_timezone'));

        // ERP Modular v2.0 - Shortcode do Portal Central
        add_shortcode('sistur_portal', array($this, 'shortcode_portal_colaborador'));

        // Dashboard do Funcionário v2.0 - Tela inicial modular
        add_shortcode('sistur_dashboard', array($this, 'shortcode_dashboard'));

        // Módulo de RH v2.0 - Gestão de Funcionários, Ponto e Banco de Horas
        add_shortcode('sistur_rh_module', array($this, 'shortcode_rh_module'));
    }

    /**
     * Shortcode: Login de funcionário
     */
    public function shortcode_login_funcionario($atts)
    {
        ob_start();
        include SISTUR_PLUGIN_DIR . 'templates/login-funcionario.php';
        return ob_get_clean();
    }

    /**
     * Shortcode: Painel do funcionário (versão completa)
     */
    public function shortcode_painel_funcionario($atts)
    {
        ob_start();
        include SISTUR_PLUGIN_DIR . 'templates/painel-funcionario.php';
        return ob_get_clean();
    }

    /**
     * Shortcode: Registrar Ponto (versão simplificada com validação Wi-Fi)
     */
    public function shortcode_registrar_ponto($atts)
    {
        ob_start();
        include SISTUR_PLUGIN_DIR . 'templates/painel-funcionario-clock.php';
        return ob_get_clean();
    }

    /**
     * Shortcode: Banco de Horas
     */
    public function shortcode_banco_horas($atts)
    {
        ob_start();
        include SISTUR_PLUGIN_DIR . 'templates/banco-de-horas.php';
        return ob_get_clean();
    }

    /**
     * Shortcode: Portal do Colaborador (ERP Modular v2.0)
     */
    public function shortcode_portal_colaborador($atts)
    {
        ob_start();
        include SISTUR_PLUGIN_DIR . 'templates/portal-colaborador.php';
        return ob_get_clean();
    }

    /**
     * Shortcode: Dashboard do Funcionário (ERP Modular v2.0)
     * Tela inicial modular com acesso aos módulos principais
     */
    public function shortcode_dashboard($atts)
    {
        ob_start();
        // MUDANÇA (v2.0): Redirecionando dashboard legado para o novo Portal Colaborador
        include SISTUR_PLUGIN_DIR . 'templates/portal-colaborador.php';
        return ob_get_clean();
    }

    /**
     * Shortcode: Módulo de RH (ERP Modular v2.0)
     * Interface de gestão de funcionários, ponto e banco de horas
     */
    public function shortcode_rh_module($atts)
    {
        $rh_module = SISTUR_RH_Module::get_instance();
        return $rh_module->render();
    }

    /**
     * Shortcode: Debug de horário
     */
    public function shortcode_debug_horario($atts)
    {
        $output = '<div class="sistur-debug">';
        $output .= '<h3>Debug de Horário</h3>';
        $output .= '<p><strong>Hora do Servidor:</strong> ' . date('H:i:s') . '</p>';
        $output .= '<p><strong>Hora do WordPress:</strong> ' . current_time('H:i:s') . '</p>';
        $output .= '<p><strong>Timezone PHP:</strong> ' . date_default_timezone_get() . '</p>';
        $output .= '<p><strong>Timezone WordPress:</strong> ' . wp_timezone_string() . '</p>';
        $output .= '</div>';
        return $output;
    }

    /**
     * Shortcode: Debug de timezone
     */
    public function shortcode_debug_timezone($atts)
    {
        return $this->shortcode_debug_horario($atts);
    }

    /**
     * Trata redirecionamentos de template
     */
    public function handle_template_redirect()
    {
        // Implementar redirecionamentos customizados se necessário
    }

    /**
     * Configuração rápida: Definir Portal como Home
     * Trigger: ?sistur_set_homepage=1
     */
    public function setup_homepage()
    {
        if (isset($_GET['sistur_set_homepage']) && current_user_can('manage_options')) {
            $slug = 'portal-colaborador';
            $page = get_page_by_path($slug);

            if (!$page) {
                // Criar página se não existir
                $page_id = wp_insert_post(array(
                    'post_title' => 'Portal do Colaborador',
                    'post_name' => $slug,
                    'post_content' => '[sistur_dashboard]',
                    'post_status' => 'publish',
                    'post_type' => 'page',
                ));
                $page = get_post($page_id);
                $msg = "Página criada e definida como inicial.";
            } else {
                $msg = "Página existente definida como inicial.";
            }

            if ($page) {
                update_option('show_on_front', 'page');
                update_option('page_on_front', $page->ID);
                wp_die("Sucesso! $msg <a href='" . home_url() . "'>Ir para a home</a>");
            } else {
                wp_die("Erro ao criar/encontrar página.");
            }
        }
    }

    /**
     * Renderiza o dashboard principal
     */
    public function render_dashboard()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Renderiza o dashboard de RH
     */
    public function render_rh_dashboard()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/rh-dashboard.php';
    }

    /**
     * Renderiza o dashboard de Restaurante
     */
    public function render_restaurant_dashboard()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/restaurant-dashboard.php';
    }

    /**
     * Renderiza a página de configurações
     */
    public function render_settings()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * Renderiza a página de diagnóstico do sistema
     */
    public function render_diagnostics()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/system-diagnostics.php';
    }

    /**
     * Renderiza a página de gerenciamento de redes Wi-Fi
     */
    public function render_wifi_networks_page()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/wifi-networks.php';
    }

    /**
     * Renderiza a página de localizações autorizadas
     */
    public function render_authorized_locations_page()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/authorized-locations.php';
    }

    /**
     * Renderiza a página de folha de ponto
     */
    public function render_attendance_sheet_page()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/attendance-sheet.php';
    }

    /**
     * Renderiza a página de configurações da empresa
     */
    public function render_company_settings_page()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/company-settings.php';
    }

    /**
     * Renderiza a página de gestão de escalas de trabalho
     */
    public function render_shift_patterns_page()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/shift-patterns.php';
    }

    /**
     * DEPRECIADO: Renderiza a página de funções/cargos
     *
     * Este método foi mantido por compatibilidade, mas a página de funções
     * agora está integrada na página de Departamentos para melhor UX.
     *
     * @deprecated Usar página de Departamentos (sistur-departments)
     */
    public function render_roles_page()
    {
        // Redirecionar para a nova página integrada de departamentos
        wp_redirect(admin_url('admin.php?page=sistur-departments'));
        exit;
    }

    /**
     * Renderiza a página de impressão de folha de ponto
     */
    public function render_attendance_sheet_print_page()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/attendance-sheet-print.php';
    }

    /**
     * Renderiza a página de gerenciamento de feriados
     */
    public function render_holidays_page()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/holidays-manager.php';
    }

    /**
     * Renderiza a página de auditoria semanal
     */
    public function render_weekly_audit_page()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/weekly-audit.php';
    }

    /**
     * Renderiza a página de gestão de estoque avançada
     */
    public function render_stock_management_page()
    {
        // Enqueue scripts específicos desta página
        wp_enqueue_script(
            'sistur-stock-management',
            SISTUR_PLUGIN_URL . 'assets/js/stock-management.js',
            array(),
            $this->version,
            true
        );

        include SISTUR_PLUGIN_DIR . 'admin/views/stock-management.php';
    }

    /**
     * Renderiza a página de configurações de estoque
     */
    public function render_stock_settings_page()
    {
        // Enqueue scripts específicos desta página
        wp_enqueue_script(
            'sistur-stock-settings',
            SISTUR_PLUGIN_URL . 'assets/js/stock-settings.js',
            array(),
            $this->version,
            true
        );

        include SISTUR_PLUGIN_DIR . 'admin/views/stock-settings.php';
    }

    /**
     * Renderiza a página Fechar Caixa / Faturamento Macro (v2.16.0)
     */
    public function render_macro_revenue_page()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/revenue/macro-revenue.php';
    }

    /**
     * Renderiza a página de tipos de produtos (RESOURCE v2.4)
     */
    public function render_product_types_page()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/product-types.php';
    }

    /**
     * Renderiza a página de Inventário Cego (v2.8.0)
     */
    public function render_blind_inventory_page()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/inventory/blind-count.php';
    }

    /**
     * Renderiza a página de Relatório de Gestão CMV/DRE (v2.9.0)
     */
    public function render_cmv_report_page()
    {
        include SISTUR_PLUGIN_DIR . 'admin/views/reports/cmv-report.php';
    }


    /**
     * Retorna a versão do plugin
     */
    public function get_version()
    {
        return $this->version;
    }

    /**
     * Retorna o nome do plugin
     */
    public function get_plugin_name()
    {
        return $this->plugin_name;
    }
}

/**
 * Inicia o plugin
 */
if (!function_exists('run_sistur')) {
    function run_sistur()
    {
        return SISTUR::get_instance();
    }
}

// Inicia o plugin
run_sistur();
