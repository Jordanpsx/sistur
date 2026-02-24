<?php
/**
 * Classe de funcionalidades administrativas
 *
 * @package SISTUR
 */

class SISTUR_Admin {

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
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));
        add_action('admin_init', array($this, 'check_upgrade'));
    }

    /**
     * Verifica se precisa rodar atualizações de banco de dados
     */
    public function check_upgrade() {
        if (!class_exists('SISTUR_Stock_Installer')) {
            require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-stock-installer.php';
        }
        
        $installed_ver = get_option('sistur_stock_schema_version');
        if (version_compare($installed_ver, SISTUR_Stock_Installer::SCHEMA_VERSION, '<')) {
            SISTUR_Stock_Installer::install();
        }
    }

    /**
     * Adiciona widgets ao dashboard do WordPress
     */
    public function add_dashboard_widgets() {
        wp_add_dashboard_widget(
            'sistur_dashboard_widget',
            __('SISTUR - Resumo', 'sistur'),
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * Renderiza o widget do dashboard
     */
    public function render_dashboard_widget() {
        global $wpdb;

        // Contar funcionários ativos
        $employees_table = $wpdb->prefix . 'sistur_employees';
        $total_employees = $wpdb->get_var("SELECT COUNT(*) FROM $employees_table WHERE status = 1");

        // Contar leads
        $leads_table = $wpdb->prefix . 'sistur_leads';
        $total_leads = $wpdb->get_var("SELECT COUNT(*) FROM $leads_table");
        $leads_today = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $leads_table WHERE DATE(created_at) = %s",
            date('Y-m-d')
        ));

        // Contar produtos
        $products_table = $wpdb->prefix . 'sistur_products';
        $total_products = $wpdb->get_var("SELECT COUNT(*) FROM $products_table WHERE status = 'active'");

        ?>
        <div class="sistur-dashboard-widget">
            <style>
                .sistur-stats {
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 15px;
                    margin-top: 15px;
                }
                .sistur-stat-box {
                    background: #f0f0f1;
                    padding: 15px;
                    border-radius: 4px;
                    text-align: center;
                }
                .sistur-stat-number {
                    font-size: 32px;
                    font-weight: bold;
                    color: #2271b1;
                    display: block;
                }
                .sistur-stat-label {
                    font-size: 14px;
                    color: #646970;
                    margin-top: 5px;
                }
            </style>

            <div class="sistur-stats">
                <div class="sistur-stat-box">
                    <span class="sistur-stat-number"><?php echo esc_html($total_employees); ?></span>
                    <span class="sistur-stat-label"><?php _e('Funcionários Ativos', 'sistur'); ?></span>
                </div>

                <div class="sistur-stat-box">
                    <span class="sistur-stat-number"><?php echo esc_html($total_leads); ?></span>
                    <span class="sistur-stat-label"><?php _e('Total de Leads', 'sistur'); ?></span>
                </div>

                <div class="sistur-stat-box">
                    <span class="sistur-stat-number"><?php echo esc_html($leads_today); ?></span>
                    <span class="sistur-stat-label"><?php _e('Leads Hoje', 'sistur'); ?></span>
                </div>

                <div class="sistur-stat-box">
                    <span class="sistur-stat-number"><?php echo esc_html($total_products); ?></span>
                    <span class="sistur-stat-label"><?php _e('Produtos Ativos', 'sistur'); ?></span>
                </div>
            </div>

            <p style="margin-top: 15px; text-align: center;">
                <a href="<?php echo admin_url('admin.php?page=sistur'); ?>" class="button button-primary">
                    <?php _e('Ir para Dashboard SISTUR', 'sistur'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
