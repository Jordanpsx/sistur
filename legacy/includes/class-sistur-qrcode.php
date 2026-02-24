<?php
/**
 * Classe de geração de QR Code para funcionários
 *
 * @package SISTUR
 */

class SISTUR_QRCode {

    /**
     * Instância única da classe (Singleton)
     */
    private static $instance = null;

    /**
     * Diretório de armazenamento dos QR Codes
     */
    private $qrcode_dir;

    /**
     * URL do diretório de QR Codes
     */
    private $qrcode_url;

    /**
     * Construtor privado (Singleton)
     */
    private function __construct() {
        $upload_dir = wp_upload_dir();
        $this->qrcode_dir = $upload_dir['basedir'] . '/sistur/qrcodes/';
        $this->qrcode_url = $upload_dir['baseurl'] . '/sistur/qrcodes/';

        // Criar diretório se não existir
        if (!file_exists($this->qrcode_dir)) {
            wp_mkdir_p($this->qrcode_dir);
        }

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
        // AJAX handlers
        add_action('wp_ajax_sistur_generate_qrcode', array($this, 'ajax_generate_qrcode'));
        add_action('wp_ajax_sistur_delete_qrcode', array($this, 'ajax_delete_qrcode'));
        add_action('wp_ajax_sistur_download_qrcode', array($this, 'ajax_download_qrcode'));
        add_action('wp_ajax_sistur_generate_tokens_bulk', array($this, 'ajax_generate_tokens_bulk'));

        // Scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Shortcodes
        add_shortcode('sistur_qrcode', array(__CLASS__, 'shortcode_employee_qrcode'));
    }

    /**
     * Carrega scripts
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'sistur-employees') === false) {
            return;
        }

        wp_enqueue_script('sistur-qrcode-admin', SISTUR_PLUGIN_URL . 'assets/js/qrcode-admin.js', array('jquery'), SISTUR_VERSION, true);

        wp_localize_script('sistur-qrcode-admin', 'sisturQRCode', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sistur_qrcode_nonce')
        ));
    }

    /**
     * Gera QR Code para funcionário
     */
    public function generate_qrcode($employee_id, $size = 300) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employees';

        error_log("SISTUR QR Debug: Iniciando geração para employee_id {$employee_id}");

        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $employee_id
        ), ARRAY_A);

        if (!$employee) {
            error_log("SISTUR QR Error: Funcionário ID {$employee_id} não encontrado no banco de dados");
            return false;
        }

        error_log("SISTUR QR Debug: Funcionário encontrado: " . $employee['name']);

        // Garantir que o funcionário tem um token UUID
        $token = $employee['token_qr'];
        if (empty($token)) {
            error_log("SISTUR QR Debug: Token não existe, gerando novo...");
            $token = $this->generate_token_for_employee($employee_id);
            if (!$token) {
                error_log("SISTUR QR Error: Falha ao gerar token UUID para employee_id {$employee_id}");
                return false;
            }
            error_log("SISTUR QR Debug: Novo token gerado: {$token}");
        } else {
            error_log("SISTUR QR Debug: Token existente: {$token}");
        }

        // Conteúdo do QR Code = apenas o token UUID
        $qr_content = $token;

        // Nome do arquivo
        $hash = md5($employee['id'] . $employee['cpf']);
        $filename = 'employee_' . $employee['id'] . '_' . $hash . '.png';
        $filepath = $this->qrcode_dir . $filename;

        error_log("SISTUR QR Debug: Filepath: {$filepath}");

        // Verificar se já existe
        if (file_exists($filepath)) {
            error_log("SISTUR QR Debug: Arquivo já existe, retornando URL existente");
            return $this->qrcode_url . $filename;
        }

        // Verificar se o diretório existe
        if (!file_exists($this->qrcode_dir)) {
            error_log("SISTUR QR Debug: Diretório não existe, criando...");
            $created = wp_mkdir_p($this->qrcode_dir);
            if (!$created) {
                error_log("SISTUR QR Error: Falha ao criar diretório: " . $this->qrcode_dir);
                return false;
            }
        }

        // Verificar se o diretório é gravável
        if (!is_writable($this->qrcode_dir)) {
            error_log('SISTUR QR Error: Diretório não é gravável: ' . $this->qrcode_dir);
            return false;
        }

        error_log("SISTUR QR Debug: Diretório OK, tentando gerar QR code...");

        // OPÇÃO 1: Tentar usar biblioteca endroid/qr-code v4 (se Composer instalado)
        if (class_exists('Endroid\QrCode\QrCode')) {
            error_log("SISTUR QR Debug: Usando biblioteca Endroid (Composer)");
            try {
                $writer = new \Endroid\QrCode\Writer\PngWriter();
                $qrCode = \Endroid\QrCode\QrCode::create($qr_content)
                    ->setSize($size)
                    ->setMargin(10)
                    ->setErrorCorrectionLevel(new \Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh());

                $result = $writer->write($qrCode);
                $result->saveToFile($filepath);

                error_log("SISTUR QR Debug: QR Code gerado com sucesso via Endroid");
                return $this->qrcode_url . $filename;
            } catch (Exception $e) {
                error_log('SISTUR QR Error (endroid): ' . $e->getMessage());
                // Continuar para fallback
            }
        }

        // OPÇÃO 2: Usar biblioteca standalone (sem dependências)
        error_log("SISTUR QR Debug: Usando biblioteca standalone (API externa)");
        require_once SISTUR_PLUGIN_DIR . 'includes/lib/phpqrcode.php';

        if (class_exists('SISTUR_QRCodeGenerator')) {
            $result = SISTUR_QRCodeGenerator::generateAndSave($qr_content, $filepath, $size, 10);

            if ($result) {
                error_log("SISTUR QR Debug: QR Code gerado com sucesso via API externa");
                return $this->qrcode_url . $filename;
            } else {
                error_log('SISTUR QR Error: Falha ao gerar QR code via API externa. Verifique conexão com internet.');
                return false;
            }
        }

        // Se chegou aqui, algo deu muito errado
        error_log('SISTUR QR Error: Nenhum método de geração disponível - classe SISTUR_QRCodeGenerator não encontrada');
        return false;
    }

    /**
     * AJAX: Gerar QR Code
     */
    public function ajax_generate_qrcode() {
        check_ajax_referer('sistur_qrcode_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $size = isset($_POST['size']) ? intval($_POST['size']) : 300;

        if ($employee_id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        $qrcode_url = $this->generate_qrcode($employee_id, $size);

        if (!$qrcode_url) {
            wp_send_json_error(array('message' => 'Erro ao gerar QR Code.'));
        }

        wp_send_json_success(array(
            'message' => 'QR Code gerado com sucesso!',
            'qrcode_url' => $qrcode_url
        ));
    }

    /**
     * AJAX: Deletar QR Code
     */
    public function ajax_delete_qrcode() {
        check_ajax_referer('sistur_qrcode_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;

        if ($employee_id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        // Encontrar e deletar arquivos do funcionário
        $pattern = $this->qrcode_dir . 'employee_' . $employee_id . '_*.png';
        $files = glob($pattern);

        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        wp_send_json_success(array('message' => 'QR Code excluído com sucesso!'));
    }

    /**
     * AJAX: Download QR Code
     */
    public function ajax_download_qrcode() {
        check_ajax_referer('sistur_qrcode_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Permissão negada.');
        }

        $employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;

        if ($employee_id <= 0) {
            wp_die('ID inválido.');
        }

        // Encontrar arquivo
        $pattern = $this->qrcode_dir . 'employee_' . $employee_id . '_*.png';
        $files = glob($pattern);

        if (empty($files)) {
            wp_die('QR Code não encontrado.');
        }

        $file = $files[0];

        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="qrcode_employee_' . $employee_id . '.png"');
        header('Content-Length: ' . filesize($file));

        readfile($file);
        exit;
    }

    /**
     * Gera um token UUID v4 para um funcionário
     *
     * @param int $employee_id ID do funcionário
     * @return string|false Token UUID gerado ou false em caso de erro
     */
    public function generate_token_for_employee($employee_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employees';

        // Verificar se funcionário existe
        $employee = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE id = %d",
            $employee_id
        ));

        if (!$employee) {
            return false;
        }

        // Gerar UUID v4
        $token = $this->generate_uuid_v4();

        // Atualizar funcionário com o token
        $result = $wpdb->update(
            $table,
            array('token_qr' => $token),
            array('id' => $employee_id),
            array('%s'),
            array('%d')
        );

        if ($result === false) {
            return false;
        }

        return $token;
    }

    /**
     * Gera um UUID v4 válido
     *
     * @return string UUID v4
     */
    private function generate_uuid_v4() {
        // Gerar 16 bytes aleatórios
        $data = random_bytes(16);

        // Configurar bits de versão (0100xxxx)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);

        // Configurar bits de variante (10xxxxxx)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Formatar como UUID
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Gera tokens para todos os funcionários que não possuem
     *
     * @return array Resultado com contadores
     */
    public function generate_tokens_for_all_employees() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employees';

        // Buscar funcionários sem token
        $employees = $wpdb->get_results(
            "SELECT id, name FROM $table WHERE (token_qr IS NULL OR token_qr = '') AND status = 1"
        );

        $generated = 0;
        $failed = 0;

        foreach ($employees as $employee) {
            $token = $this->generate_token_for_employee($employee->id);
            if ($token) {
                $generated++;
            } else {
                $failed++;
            }
        }

        return array(
            'total' => count($employees),
            'generated' => $generated,
            'failed' => $failed
        );
    }

    /**
     * AJAX: Gerar tokens para todos os funcionários
     */
    public function ajax_generate_tokens_bulk() {
        check_ajax_referer('sistur_qrcode_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $result = $this->generate_tokens_for_all_employees();

        wp_send_json_success(array(
            'message' => sprintf(
                'Tokens gerados: %d de %d (Falhas: %d)',
                $result['generated'],
                $result['total'],
                $result['failed']
            ),
            'result' => $result
        ));
    }

    /**
     * Shortcode: Exibir QR Code do funcionário
     */
    public static function shortcode_employee_qrcode($atts) {
        $atts = shortcode_atts(array(
            'employee_id' => 0,
            'size' => 300
        ), $atts);

        $employee_id = intval($atts['employee_id']);
        $size = intval($atts['size']);

        if ($employee_id <= 0) {
            // Tentar obter do funcionário logado
            $employee = sistur_get_current_employee();
            if ($employee && isset($employee['id'])) {
                $employee_id = $employee['id'];
            }
        }

        if ($employee_id <= 0) {
            return '<p>' . __('Funcionário não identificado.', 'sistur') . '</p>';
        }

        $instance = self::get_instance();
        $qrcode_url = $instance->generate_qrcode($employee_id, $size);

        if (!$qrcode_url) {
            return '<p>' . __('Erro ao gerar QR Code.', 'sistur') . '</p>';
        }

        ob_start();
        ?>
        <div class="sistur-qrcode-container" style="text-align: center;">
            <img src="<?php echo esc_url($qrcode_url); ?>" alt="QR Code" style="max-width: <?php echo esc_attr($size); ?>px;" />
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza a página administrativa de QR Codes
     */
    public static function render_qrcodes_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
        }

        // Verificar métodos de geração disponíveis
        $has_endroid = class_exists('Endroid\QrCode\QrCode');
        $has_standalone = file_exists(SISTUR_PLUGIN_DIR . 'includes/lib/phpqrcode.php');
        $vendor_missing = !$has_endroid && !$has_standalone;

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employees';

        // Buscar todos os funcionários ativos
        $employees = $wpdb->get_results(
            "SELECT id, name, cpf, token_qr, status FROM $table WHERE status = 1 ORDER BY name",
            ARRAY_A
        );

        // Diretório e URL dos QR codes
        $upload_dir = wp_upload_dir();
        $qrcode_dir = $upload_dir['basedir'] . '/sistur/qrcodes/';
        $qrcode_url = $upload_dir['baseurl'] . '/sistur/qrcodes/';

        // Contar estatísticas
        $total_employees = count($employees);
        $employees_with_token = 0;
        $employees_with_qrcode = 0;

        foreach ($employees as $employee) {
            if (!empty($employee['token_qr'])) {
                $employees_with_token++;
            }
            // Buscar arquivo com pattern (inclui hash no nome)
            $qr_pattern = $qrcode_dir . 'employee_' . $employee['id'] . '_*.png';
            $qr_files = glob($qr_pattern);
            if (!empty($qr_files)) {
                $employees_with_qrcode++;
            }
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-tag" style="font-size: 28px; vertical-align: middle;"></span>
                <?php _e('QR Codes dos Funcionários', 'sistur'); ?>
            </h1>

            <a href="#" id="sistur-regenerate-all-qrcodes" class="page-title-action">
                <span class="dashicons dashicons-image-rotate"></span>
                <?php _e('Gerar Todos os QR Codes', 'sistur'); ?>
            </a>

            <hr class="wp-header-end">

            <?php if (!$has_endroid && $has_standalone): ?>
                <div class="notice notice-info" style="margin: 20px 0; padding: 15px; border-left: 4px solid #2271b1;">
                    <h3 style="margin-top: 0;">
                        <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                        Geração de QR Codes: Modo Fallback
                    </h3>
                    <p>O sistema está usando <strong>API externa</strong> para gerar QR codes (sem Composer).</p>
                    <p style="font-size: 13px; color: #646970;">
                        ✓ Funcionando normalmente<br>
                        ⚠ Requer conexão com internet<br>
                        💡 Para geração offline, instale: <code>composer install --no-dev</code>
                    </p>
                </div>
            <?php elseif ($has_endroid): ?>
                <div class="notice notice-success" style="margin: 20px 0; padding: 15px; border-left: 4px solid #00a32a;">
                    <p style="margin: 0;">
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        <strong>Geração de QR Codes: Modo Offline (Composer)</strong> - Funcionando perfeitamente!
                    </p>
                </div>
            <?php elseif ($vendor_missing): ?>
                <div class="notice notice-error" style="margin: 20px 0; padding: 15px; border-left: 4px solid #dc3232;">
                    <h2 style="margin-top: 0;">
                        <span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
                        Erro Crítico: Geração de QR Codes Desabilitada
                    </h2>
                    <p><strong>Nenhum método de geração disponível!</strong></p>
                    <p>Arquivo necessário não encontrado: <code>includes/lib/phpqrcode.php</code></p>
                    <p>Entre em contato com o suporte técnico.</p>
                </div>
            <?php endif; ?>

            <!-- Estatísticas -->
            <div class="sistur-qrcodes-stats" style="margin: 20px 0; display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                <div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 15px; border-radius: 4px;">
                    <div style="font-size: 32px; font-weight: bold; color: #2271b1;"><?php echo $total_employees; ?></div>
                    <div style="color: #646970; margin-top: 5px;">Funcionários Ativos</div>
                </div>
                <div style="background: #f0f6fc; border-left: 4px solid #00a32a; padding: 15px; border-radius: 4px;">
                    <div style="font-size: 32px; font-weight: bold; color: #00a32a;"><?php echo $employees_with_token; ?></div>
                    <div style="color: #646970; margin-top: 5px;">Com Token UUID</div>
                </div>
                <div style="background: #f0f6fc; border-left: 4px solid #8c8f94; padding: 15px; border-radius: 4px;">
                    <div style="font-size: 32px; font-weight: bold; color: #8c8f94;"><?php echo $employees_with_qrcode; ?></div>
                    <div style="color: #646970; margin-top: 5px;">Com QR Code Gerado</div>
                </div>
            </div>

            <!-- Mensagens -->
            <div id="sistur-qrcode-message"></div>

            <?php if (empty($employees)): ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php _e('Nenhum funcionário encontrado.', 'sistur'); ?></strong><br>
                        <?php _e('Crie funcionários primeiro para poder gerar QR codes.', 'sistur'); ?>
                    </p>
                </div>
            <?php else: ?>
                <!-- Grid de QR Codes -->
                <div class="sistur-qrcodes-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 20px;">
                    <?php foreach ($employees as $employee): ?>
                        <?php
                            // Buscar arquivo com pattern (inclui hash no nome)
                            $qr_pattern = $qrcode_dir . 'employee_' . $employee['id'] . '_*.png';
                            $qr_files = glob($qr_pattern);
                            $has_qr = !empty($qr_files);
                            $qr_path = $has_qr ? $qr_files[0] : '';
                            $qr_filename = $has_qr ? basename($qr_path) : '';
                            $qr_url = $has_qr ? $qrcode_url . $qr_filename : '';
                            $has_token = !empty($employee['token_qr']);
                        ?>
                        <div class="sistur-qrcode-card" data-employee-id="<?php echo esc_attr($employee['id']); ?>" style="border: 1px solid #dcdcde; border-radius: 8px; padding: 20px; background: #fff;">
                            <!-- Cabeçalho -->
                            <div style="text-align: center; margin-bottom: 15px;">
                                <h3 style="margin: 0 0 5px 0; font-size: 16px; font-weight: 600;">
                                    <?php echo esc_html($employee['name']); ?>
                                </h3>
                                <div style="color: #646970; font-size: 13px; font-family: 'Courier New', monospace;">
                                    CPF: <?php echo esc_html($employee['cpf']); ?>
                                </div>
                                <div style="color: #8c8f94; font-size: 12px; margin-top: 3px;">
                                    ID: <?php echo $employee['id']; ?>
                                </div>
                            </div>

                            <!-- Token UUID -->
                            <?php if ($has_token): ?>
                                <div style="background: #f0f6fc; padding: 8px; border-radius: 4px; margin-bottom: 15px; font-size: 11px; word-break: break-all; color: #0071a1; text-align: center;">
                                    <span class="dashicons dashicons-lock" style="font-size: 10px;"></span>
                                    <?php echo esc_html($employee['token_qr']); ?>
                                </div>
                            <?php else: ?>
                                <div style="background: #fcf0f1; padding: 8px; border-radius: 4px; margin-bottom: 15px; font-size: 12px; color: #d63638; text-align: center;">
                                    <span class="dashicons dashicons-warning" style="font-size: 12px;"></span>
                                    Sem token UUID
                                </div>
                            <?php endif; ?>

                            <!-- QR Code -->
                            <div style="text-align: center; margin: 15px 0;">
                                <?php if ($has_qr): ?>
                                    <div style="border: 2px solid #dcdcde; border-radius: 4px; padding: 10px; background: white; display: inline-block;">
                                        <img src="<?php echo esc_url($qr_url); ?>?v=<?php echo time(); ?>"
                                             alt="QR Code"
                                             style="width: 200px; height: 200px; display: block;">
                                    </div>
                                <?php else: ?>
                                    <div style="width: 200px; height: 200px; border: 2px dashed #dcdcde; border-radius: 4px; display: flex; align-items: center; justify-content: center; margin: 0 auto; background: #f6f7f7;">
                                        <span style="color: #8c8f94; font-size: 13px; text-align: center;">
                                            <span class="dashicons dashicons-admin-page" style="font-size: 40px; display: block; margin-bottom: 10px; opacity: 0.3;"></span>
                                            QR Code<br>não gerado
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Ações -->
                            <div style="display: flex; gap: 8px; justify-content: center; margin-top: 15px;">
                                <?php if ($has_qr): ?>
                                    <a href="<?php echo esc_url($qr_url); ?>"
                                       class="button button-primary"
                                       download="qrcode_<?php echo sanitize_title($employee['name']); ?>.png"
                                       style="display: inline-flex; align-items: center; gap: 5px;">
                                        <span class="dashicons dashicons-download" style="font-size: 16px;"></span>
                                        Baixar
                                    </a>
                                    <a href="<?php echo esc_url($qr_url); ?>"
                                       class="button"
                                       target="_blank"
                                       style="display: inline-flex; align-items: center; gap: 5px;">
                                        <span class="dashicons dashicons-visibility" style="font-size: 16px;"></span>
                                        Ver
                                    </a>
                                <?php else: ?>
                                    <button class="button button-primary sistur-generate-qrcode-btn"
                                            data-employee-id="<?php echo esc_attr($employee['id']); ?>"
                                            style="display: inline-flex; align-items: center; gap: 5px;">
                                        <span class="dashicons dashicons-image-rotate" style="font-size: 16px;"></span>
                                        Gerar QR Code
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Botão de Impressão -->
                <div style="margin-top: 30px; padding: 20px; background: #f0f0f1; border-radius: 8px; text-align: center;">
                    <button class="button button-secondary" onclick="window.print();" style="padding: 10px 20px; height: auto;">
                        <span class="dashicons dashicons-printer" style="font-size: 20px; vertical-align: middle;"></span>
                        Imprimir Todos os QR Codes
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Estilos de Impressão -->
        <style media="print">
            .wrap h1, .page-title-action, .sistur-qrcodes-stats, .button, .dashicons-lock, .dashicons-warning {
                display: none !important;
            }
            .sistur-qrcodes-grid {
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 10px !important;
            }
            .sistur-qrcode-card {
                page-break-inside: avoid;
                border: 1px solid #000 !important;
            }
        </style>

        <!-- JavaScript -->
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Gerar QR Code individual
            $('.sistur-generate-qrcode-btn').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var employeeId = $btn.data('employee-id');
                var $card = $btn.closest('.sistur-qrcode-card');

                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Gerando...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sistur_generate_qrcode',
                        nonce: '<?php echo wp_create_nonce('sistur_qrcode_nonce'); ?>',
                        employee_id: employeeId,
                        size: 300
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Erro: ' + response.data.message);
                            $btn.prop('disabled', false).html('<span class="dashicons dashicons-image-rotate"></span> Gerar QR Code');
                        }
                    },
                    error: function() {
                        alert('Erro ao gerar QR Code. Tente novamente.');
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-image-rotate"></span> Gerar QR Code');
                    }
                });
            });

            // Gerar todos os QR Codes
            $('#sistur-regenerate-all-qrcodes').on('click', function(e) {
                e.preventDefault();

                if (!confirm('Deseja gerar/regenerar todos os QR codes? Isso pode levar alguns segundos.')) {
                    return;
                }

                var $btn = $(this);
                $btn.html('<span class="dashicons dashicons-update spin"></span> Gerando...').addClass('disabled');

                // Primeiro gerar tokens
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sistur_generate_tokens_bulk',
                        nonce: '<?php echo wp_create_nonce('sistur_qrcode_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Após gerar tokens, recarregar página para gerar QR codes
                            location.reload();
                        } else {
                            alert('Erro: ' + response.data.message);
                            $btn.html('<span class="dashicons dashicons-image-rotate"></span> Gerar Todos os QR Codes').removeClass('disabled');
                        }
                    },
                    error: function() {
                        alert('Erro ao gerar tokens. Tente novamente.');
                        $btn.html('<span class="dashicons dashicons-image-rotate"></span> Gerar Todos os QR Codes').removeClass('disabled');
                    }
                });
            });
        });
        </script>

        <style>
            .dashicons.spin {
                animation: rotation 1s infinite linear;
            }
            @keyframes rotation {
                from { transform: rotate(0deg); }
                to { transform: rotate(359deg); }
            }
        </style>
        <?php
    }
}
