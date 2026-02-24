<?php
/**
 * Classe de gerenciamento de funcionários
 *
 * @package SISTUR
 */

class SISTUR_Employees {

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
     * Inicializa os hooks do WordPress
     */
    private function init_hooks() {
        // AJAX handlers para funcionários
        add_action('wp_ajax_save_employee', array($this, 'ajax_save_employee'));
        add_action('wp_ajax_delete_employee', array($this, 'ajax_delete_employee'));
        add_action('wp_ajax_get_employee', array($this, 'ajax_get_employee'));

        // AJAX handlers para departamentos
        add_action('wp_ajax_save_department', array($this, 'ajax_save_department'));
        add_action('wp_ajax_delete_department', array($this, 'ajax_delete_department'));
        add_action('wp_ajax_get_department', array($this, 'ajax_get_department'));
        add_action('wp_ajax_get_all_departments', array($this, 'ajax_get_all_departments'));

        // AJAX handlers para pagamentos
        add_action('wp_ajax_save_payment', array($this, 'ajax_save_payment'));
        add_action('wp_ajax_get_employee_payments', array($this, 'ajax_get_employee_payments'));

        // Hook para sincronizar usuários WordPress com tabela de funcionários
        add_action('user_register', array($this, 'sync_wordpress_user_to_employee'), 10, 1);
        add_action('profile_update', array($this, 'sync_wordpress_user_to_employee'), 10, 1);

        // Scripts e estilos
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Carrega scripts e estilos
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'sistur') === false) {
            return;
        }

        // CSS - Design System e Admin devem ser carregados primeiro
        wp_enqueue_style('sistur-design-system', SISTUR_PLUGIN_URL . 'assets/css/sistur-design-system.css', array(), SISTUR_VERSION);
        wp_enqueue_style('sistur-admin', SISTUR_PLUGIN_URL . 'assets/css/admin.css', array('sistur-design-system'), SISTUR_VERSION);
        wp_enqueue_style('sistur-employees', SISTUR_PLUGIN_URL . 'assets/css/employees.css', array('sistur-design-system'), SISTUR_VERSION);
        wp_enqueue_style('sistur-employee-grid', SISTUR_PLUGIN_URL . 'assets/css/employee-grid.css', array('sistur-design-system'), SISTUR_VERSION);
        wp_enqueue_style('sistur-payments', SISTUR_PLUGIN_URL . 'assets/css/payments.css', array('sistur-design-system'), SISTUR_VERSION);

        // JS
        wp_enqueue_media(); // Para upload de fotos
        wp_enqueue_script('sistur-employees', SISTUR_PLUGIN_URL . 'assets/js/employees.js', array('jquery'), SISTUR_VERSION, true);
        wp_enqueue_script('sistur-employee-admin', SISTUR_PLUGIN_URL . 'assets/js/employee-admin.js', array('jquery'), SISTUR_VERSION, true);
        wp_enqueue_script('sistur-payments', SISTUR_PLUGIN_URL . 'assets/js/payments.js', array('jquery'), SISTUR_VERSION, true);

        // Localização
        wp_localize_script('sistur-employees', 'sisturEmployees', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sistur_employees_nonce'),
            'strings' => array(
                'confirm_delete' => __('Tem certeza que deseja excluir este funcionário?', 'sistur'),
                'error' => __('Ocorreu um erro. Tente novamente.', 'sistur'),
                'success' => __('Operação realizada com sucesso!', 'sistur')
            )
        ));
    }

    /**
     * Sincroniza usuários WordPress com a tabela de funcionários SISTUR
     *
     * Quando um administrador registra manualmente um usuário no WordPress,
     * este hook garante que o funcionário seja criado na tabela wp_sistur_employees
     *
     * @param int $user_id ID do usuário WordPress
     */
    public function sync_wordpress_user_to_employee($user_id) {
        // Obter dados do usuário
        $user = get_userdata($user_id);

        if (!$user) {
            error_log("SISTUR: Não foi possível obter dados do usuário ID {$user_id}");
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employees';

        // Verificar se o funcionário já existe na tabela SISTUR
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d",
            $user_id
        ));

        // Se já existe, não fazer nada
        if ($existing) {
            return;
        }

        // Verificar se o usuário tem uma role de funcionário
        // Pode ser 'employee', 'subscriber', ou qualquer outra role que você use para funcionários
        $allowed_roles = array('employee', 'subscriber');
        $user_roles = (array) $user->roles;

        $has_employee_role = !empty(array_intersect($user_roles, $allowed_roles));

        // Se não tem role de funcionário, não criar registro
        if (!$has_employee_role && !in_array('administrator', $user_roles)) {
            return;
        }

        // Preparar dados do novo funcionário
        $data = array(
            'user_id' => $user_id,
            'name' => $user->display_name ? $user->display_name : $user->user_login,
            'email' => $user->user_email,
            'phone' => get_user_meta($user_id, 'billing_phone', true),
            'department_id' => null,
            'role_id' => null,
            'position' => '',
            'photo' => '',
            'bio' => '',
            'hire_date' => current_time('Y-m-d'),
            'cpf' => '',
            'matricula' => '',
            'ctps' => '',
            'ctps_uf' => '',
            'cbo' => '',
            'time_expected_minutes' => 480, // 8 horas padrão
            'lunch_minutes' => 60, // 1 hora padrão
            'status' => 1
        );

        $format = array(
            '%d', // user_id
            '%s', // name
            '%s', // email
            '%s', // phone
            '%d', // department_id
            '%d', // role_id
            '%s', // position
            '%s', // photo
            '%s', // bio
            '%s', // hire_date
            '%s', // cpf
            '%s', // matricula
            '%s', // ctps
            '%s', // ctps_uf
            '%s', // cbo
            '%d', // time_expected_minutes
            '%d', // lunch_minutes
            '%d'  // status
        );

        // Inserir funcionário
        $result = $wpdb->insert($table, $data, $format);

        if ($result === false) {
            error_log("SISTUR: Erro ao criar funcionário para usuário ID {$user_id}");
            error_log("SISTUR: SQL Error: " . $wpdb->last_error);
            return;
        }

        $employee_id = $wpdb->insert_id;
        error_log("SISTUR: Funcionário ID {$employee_id} criado automaticamente para usuário WordPress ID {$user_id}");

        // Gerar token UUID e QR code automaticamente
        if (class_exists('SISTUR_QRCode')) {
            $qrcode = SISTUR_QRCode::get_instance();

            // Gerar token UUID
            $token_generated = $qrcode->generate_token_for_employee($employee_id);

            // Gerar QR code
            if ($token_generated) {
                $qrcode->generate_qrcode($employee_id);
                error_log("SISTUR: Token UUID e QR Code gerados automaticamente para funcionário ID {$employee_id}");
            }
        }
    }

    /**
     * AJAX: Salvar funcionário
     */
    public function ajax_save_employee() {
        // Log de debug para investigar problemas em produção
        error_log('========== SISTUR DEBUG: ajax_save_employee INICIADO ==========');
        error_log('SISTUR DEBUG: Timestamp: ' . current_time('Y-m-d H:i:s'));
        error_log('SISTUR DEBUG: User ID: ' . get_current_user_id());
        error_log('SISTUR DEBUG: User IP: ' . $_SERVER['REMOTE_ADDR']);
        error_log('SISTUR DEBUG: POST data recebido: ' . print_r($_POST, true));

        // Verificar nonce
        if (!isset($_POST['nonce'])) {
            error_log('SISTUR DEBUG ERRO: Nonce não foi enviado no POST');
            wp_send_json_error(array('message' => 'Falha na verificação de segurança. Nonce ausente. Recarregue a página e tente novamente.'));
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'sistur_employees_nonce')) {
            error_log('SISTUR DEBUG ERRO: Nonce inválido. Nonce recebido: ' . $_POST['nonce']);
            wp_send_json_error(array('message' => 'Falha na verificação de segurança. Nonce inválido. Recarregue a página e tente novamente.'));
            return;
        }
        
        error_log('SISTUR DEBUG: ✓ Nonce validado com sucesso');

        if (!current_user_can('manage_options')) {
            $current_user = wp_get_current_user();
            error_log('SISTUR DEBUG ERRO: Usuário sem permissão manage_options');
            error_log('SISTUR DEBUG: User login: ' . $current_user->user_login);
            error_log('SISTUR DEBUG: User roles: ' . implode(', ', $current_user->roles));
            wp_send_json_error(array('message' => 'Permissão negada.'));
            return;
        }
        
        error_log('SISTUR DEBUG: ✓ Permissões validadas com sucesso');

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employees';
        error_log('SISTUR DEBUG: Tabela: ' . $table);
        
        // Verificar se tabela existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        if (!$table_exists) {
            error_log('SISTUR DEBUG ERRO CRÍTICO: Tabela não existe: ' . $table);
            wp_send_json_error(array('message' => 'Erro: Tabela de funcionários não existe. Entre em contato com o administrador.'));
            return;
        }
        error_log('SISTUR DEBUG: ✓ Tabela existe');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $department_id = isset($_POST['department_id']) ? intval($_POST['department_id']) : null;
        $role_id = isset($_POST['role_id']) && $_POST['role_id'] !== '' ? intval($_POST['role_id']) : null;
        $position = isset($_POST['position']) ? sanitize_text_field($_POST['position']) : '';
        $photo = isset($_POST['photo']) ? esc_url_raw($_POST['photo']) : '';
        $bio = isset($_POST['bio']) ? sanitize_textarea_field($_POST['bio']) : '';
        $hire_date = !empty($_POST['hire_date']) ? sanitize_text_field($_POST['hire_date']) : null;
        $cpf_raw = isset($_POST['cpf']) ? $_POST['cpf'] : '';
        $cpf = preg_replace('/[^0-9]/', '', $cpf_raw);
        if (empty($cpf)) {
            $cpf = null;
        }
        $matricula = isset($_POST['matricula']) ? sanitize_text_field($_POST['matricula']) : '';
        $ctps = isset($_POST['ctps']) ? sanitize_text_field($_POST['ctps']) : '';
        $ctps_uf = isset($_POST['ctps_uf']) ? sanitize_text_field($_POST['ctps_uf']) : '';
        $cbo = isset($_POST['cbo']) ? sanitize_text_field($_POST['cbo']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $status = isset($_POST['status']) ? intval($_POST['status']) : 1;

        // OBRIGATÓRIO: Capturar shift_pattern_id (escala é obrigatória agora)
        $shift_pattern_id = isset($_POST['shift_pattern_id']) && $_POST['shift_pattern_id'] !== '' ? intval($_POST['shift_pattern_id']) : null;

        // NOVOS CAMPOS: Detalhes da jornada
        $shift_start_time = !empty($_POST['shift_start_time']) ? sanitize_text_field($_POST['shift_start_time']) : null;
        $shift_end_time = !empty($_POST['shift_end_time']) ? sanitize_text_field($_POST['shift_end_time']) : null;
        $lunch_duration_minutes = isset($_POST['lunch_duration_minutes']) && $_POST['lunch_duration_minutes'] !== '' ? intval($_POST['lunch_duration_minutes']) : null;
        
        // Active Days agora é um JSON, não int. Sanitizar como string (JSON)
        // BUG FIX: WordPress adiciona slashes em POST, precisamos remover para decodificar JSON corretamente
        $active_days = isset($_POST['active_days']) ? wp_unslash($_POST['active_days']) : null;
        
        if ($active_days) {
            // Validar se é um JSON válido
            json_decode($active_days);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('SISTUR DEBUG ERRO: Falha ao decodificar JSON active_days: ' . json_last_error_msg());
                error_log('SISTUR DEBUG: Conteudo active_days que falhou: ' . $active_days);
                $active_days = null;
            } else {
                 error_log('SISTUR DEBUG: active_days JSON validado com sucesso');
            }
        }
        
        error_log('SISTUR DEBUG ajax_save_employee: active_days final processado: ' . var_export($active_days, true));

        // Log dos dados sanitizados
        error_log('SISTUR DEBUG: Dados sanitizados:');
        error_log('SISTUR DEBUG:   - ID: ' . $id);
        error_log('SISTUR DEBUG:   - Nome: ' . $name);
        error_log('SISTUR DEBUG:   - Email: ' . $email);
        error_log('SISTUR DEBUG:   - CPF: ' . ($cpf ?: 'vazio'));
        error_log('SISTUR DEBUG:   - Senha fornecida: ' . (!empty($password) ? 'SIM' : 'NÃO'));
        
        // Validações
        if (empty($name)) {
            error_log('SISTUR DEBUG ERRO: Nome vazio ao salvar funcionário');
            wp_send_json_error(array('message' => 'Nome é obrigatório.'));
            return;
        }
        error_log('SISTUR DEBUG: ✓ Nome validado');

        // BUG FIX #9: Validação completa de CPF usando algoritmo correto
        if (!empty($cpf)) {
            error_log('SISTUR DEBUG: Iniciando validação de CPF...');
            
            // Carregar função de validação se não estiver disponível
            if (!function_exists('sistur_validate_cpf')) {
                error_log('SISTUR DEBUG: Função sistur_validate_cpf não encontrada, tentando carregar...');
                $cpf_file = SISTUR_PLUGIN_DIR . 'includes/login-funcionario-new.php';
                
                if (file_exists($cpf_file)) {
                    error_log('SISTUR DEBUG: Arquivo encontrado: ' . $cpf_file);
                    require_once $cpf_file;
                    
                    if (function_exists('sistur_validate_cpf')) {
                        error_log('SISTUR DEBUG: ✓ Função sistur_validate_cpf carregada');
                    } else {
                        error_log('SISTUR DEBUG ERRO: Função não foi carregada do arquivo');
                    }
                } else {
                    error_log('SISTUR DEBUG ERRO CRÍTICO: Arquivo não encontrado: ' . $cpf_file);
                    error_log('SISTUR DEBUG: Plugin DIR: ' . SISTUR_PLUGIN_DIR);
                    wp_send_json_error(array('message' => 'Erro ao validar CPF. Arquivo de validação não encontrado.'));
                    return;
                }
            }

            if (!sistur_validate_cpf($cpf)) {
                error_log('SISTUR DEBUG ERRO: CPF inválido: ' . $cpf);
                wp_send_json_error(array('message' => 'CPF inválido. Verifique os dígitos informados.'));
                return;
            }
            
            error_log('SISTUR DEBUG: ✓ CPF validado: ' . $cpf);
        }

        // Validar senha ao criar novo funcionário
        if ($id == 0 && empty($password)) {
            error_log('SISTUR DEBUG ERRO: Senha vazia ao criar novo funcionário');
            wp_send_json_error(array('message' => 'Senha de acesso é obrigatória ao cadastrar novo funcionário. O funcionário precisará desta senha para fazer login no sistema.'));
            return;
        }
        
        if ($id == 0) {
            error_log('SISTUR DEBUG: ✓ Senha fornecida para novo funcionário');
        }

        // VALIDAÇÃO: Escala de trabalho é obrigatória
        if ($shift_pattern_id === null || $shift_pattern_id <= 0) {
            error_log('SISTUR DEBUG ERRO: Escala de trabalho não informada');
            wp_send_json_error(array('message' => 'Escala de trabalho é obrigatória. Todos os funcionários devem ter uma escala vinculada.'));
            return;
        }
        error_log('SISTUR DEBUG: ✓ Escala de trabalho validada: ID ' . $shift_pattern_id);

        // BUG FIX #8: Verificar CPF duplicado com tratamento de deadlock
        if (!empty($cpf)) {
            error_log('SISTUR DEBUG: Verificando CPF duplicado...');
            
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE cpf = %s AND id != %d",
                $cpf, $id
            ));

            if ($existing) {
                error_log('SISTUR DEBUG ERRO: CPF duplicado encontrado (ID existente: ' . $existing . ')');
                wp_send_json_error(array('message' => 'CPF já cadastrado para outro funcionário.'));
                return;
            }
            
            error_log('SISTUR DEBUG: ✓ CPF não duplicado');
        }

        $data = array(
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'department_id' => $department_id,
            'role_id' => $role_id,
            'position' => $position,
            'photo' => $photo,
            'bio' => $bio,
            'hire_date' => $hire_date,
            'cpf' => $cpf,
            'matricula' => $matricula,
            'ctps' => $ctps,
            'ctps_uf' => $ctps_uf,
            'cbo' => $cbo,
            'status' => $status
        );

        $format = array('%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d');

        // Adicionar senha ao array se foi fornecida
        if (!empty($password)) {
            $data['password'] = wp_hash_password($password);
            $format[] = '%s';
        }

        if ($id > 0) {
            // Atualizar
            error_log('SISTUR DEBUG: Executando UPDATE para funcionário ID: ' . $id);
            error_log('SISTUR DEBUG: Dados para update: ' . print_r($data, true));
            
            $result = $wpdb->update($table, $data, array('id' => $id), $format, array('%d'));

            error_log('SISTUR DEBUG: Resultado do UPDATE: ' . var_export($result, true));
            error_log('SISTUR DEBUG: Last query: ' . $wpdb->last_query);
            
            if ($result === false) {
                // Log detalhado do erro para debug
                $error_message = 'Erro ao atualizar funcionário.';
                error_log('SISTUR DEBUG ERRO CRÍTICO: UPDATE falhou!');
                error_log('SISTUR DEBUG: wpdb->last_error: ' . $wpdb->last_error);
                error_log('SISTUR DEBUG: wpdb->last_query: ' . $wpdb->last_query);
                
                if (!empty($wpdb->last_error)) {
                    error_log('SISTUR CRITICAL - Employee Update Error: ' . $wpdb->last_error);
                    error_log('SISTUR Employee Update Query: ' . $wpdb->last_query);

                    // Em ambiente de desenvolvimento, retornar o erro SQL
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        $error_message .= ' SQL Error: ' . $wpdb->last_error;
                    }
                }

                wp_send_json_error(array('message' => $error_message));
            }

            error_log('SISTUR DEBUG: ✓ Funcionário atualizado com sucesso! ID: ' . $id);


            // BUG FIX: Vincular escala de trabalho automaticamente ao editar funcionário
            if ($shift_pattern_id !== null && class_exists('SISTUR_Shift_Patterns')) {
                error_log('SISTUR DEBUG: Vinculando escala ID ' . $shift_pattern_id . ' ao funcionário ID ' . $id);
                error_log('SISTUR DEBUG: active_days recebido: ' . var_export($active_days, true));

                $shift_patterns = SISTUR_Shift_Patterns::get_instance();

                // Verificar se já existe uma escala ativa para este funcionário
                $existing_schedule = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}sistur_employee_schedules 
                     WHERE employee_id = %d AND is_active = 1 
                     LIMIT 1",
                    $id
                ), ARRAY_A);

                $schedule_data = array(
                    'employee_id' => $id,
                    'shift_pattern_id' => $shift_pattern_id,
                    'start_date' => $hire_date ?: current_time('Y-m-d'),
                    'is_active' => 1,
                    'notes' => 'Vinculação automática via cadastro de funcionário',
                    'shift_start_time' => $shift_start_time,
                    'shift_end_time' => $shift_end_time,
                    'lunch_duration_minutes' => $lunch_duration_minutes,
                    'active_days' => $active_days
                );

                // Se existe, atualizar. Se não, criar.
                if ($existing_schedule) {
                    $schedule_data['id'] = $existing_schedule['id'];
                    error_log('SISTUR DEBUG: Atualizando schedule existente ID: ' . $existing_schedule['id']);
                } else {
                    error_log('SISTUR DEBUG: Criando novo schedule');
                }

                $schedule_result = $shift_patterns->save_employee_schedule($schedule_data);

                if ($schedule_result) {
                    error_log('SISTUR DEBUG: ✓ Escala vinculada/atualizada com sucesso! Schedule ID: ' . $schedule_result);
                } else {
                    error_log('SISTUR DEBUG AVISO: Falha ao vincular escala');
                }
            }

            error_log('========== SISTUR DEBUG: ajax_save_employee FINALIZADO COM SUCESSO ==========');

            wp_send_json_success(array(
                'message' => 'Funcionário atualizado com sucesso!',
                'employee_id' => $id
            ));
        } else {
            // Inserir
            error_log('SISTUR DEBUG: Executando INSERT para novo funcionário');
            error_log('SISTUR DEBUG: Dados para insert: ' . print_r($data, true));
            error_log('SISTUR DEBUG: Format: ' . print_r($format, true));
            
            $result = $wpdb->insert($table, $data, $format);
            
            error_log('SISTUR DEBUG: Resultado do INSERT: ' . var_export($result, true));
            error_log('SISTUR DEBUG: Insert ID: ' . $wpdb->insert_id);
            error_log('SISTUR DEBUG: Last query: ' . $wpdb->last_query);

            if ($result === false) {
                // BUG FIX #8: Tratamento específico para CPF duplicado (race condition)
                $error_message = 'Erro ao criar funcionário.';
                error_log('SISTUR DEBUG ERRO CRÍTICO: INSERT falhou!');
                error_log('SISTUR DEBUG: wpdb->last_error: ' . $wpdb->last_error);
                error_log('SISTUR DEBUG: wpdb->last_query: ' . $wpdb->last_query);
                error_log('SISTUR DEBUG: wpdb->insert_id: ' . $wpdb->insert_id);
                
                if (!empty($wpdb->last_error)) {
                    error_log('SISTUR CRITICAL - Employee Insert Error: ' . $wpdb->last_error);
                    error_log('SISTUR Employee Insert Query: ' . $wpdb->last_query);

                    // Detectar erro de duplicate entry
                    if (strpos($wpdb->last_error, 'Duplicate entry') !== false && strpos($wpdb->last_error, 'cpf') !== false) {
                        $error_message = 'CPF já cadastrado. Outro usuário pode ter registrado este CPF simultaneamente.';
                    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
                        // Em ambiente de desenvolvimento, retornar o erro SQL
                        $error_message .= ' SQL Error: ' . $wpdb->last_error;
                    }
                }

                wp_send_json_error(array('message' => $error_message));
            }

            // Gerar token UUID e QR code automaticamente para o novo funcionário
            $employee_id = $wpdb->insert_id;
            error_log('SISTUR DEBUG: ✓ INSERT executado com sucesso!');
            error_log("SISTUR DEBUG: Funcionário ID {$employee_id} criado com sucesso");
            error_log("SISTUR DEBUG: Nome: {$name}, Email: {$email}, CPF: " . ($cpf ?: 'não informado'));

            if (class_exists('SISTUR_QRCode')) {
                $qrcode = SISTUR_QRCode::get_instance();

                // Gerar token UUID
                $token_generated = $qrcode->generate_token_for_employee($employee_id);

                // Gerar QR code (o método já verifica se o token existe)
                if ($token_generated) {
                    $qrcode->generate_qrcode($employee_id);
                    error_log("SISTUR: Token UUID e QR Code gerados automaticamente para funcionário ID {$employee_id}");
                } else {
                    error_log("SISTUR: Falha ao gerar token para funcionário ID {$employee_id}");
                }
            } else {
                error_log("SISTUR: Classe SISTUR_QRCode não encontrada. QR Code não foi gerado.");
            }

            // BUG FIX: Vincular escala de trabalho automaticamente ao criar funcionário
            if ($shift_pattern_id !== null && class_exists('SISTUR_Shift_Patterns')) {
                error_log('SISTUR DEBUG: Vinculando escala ID ' . $shift_pattern_id . ' ao funcionário ID ' . $employee_id);

                $shift_patterns = SISTUR_Shift_Patterns::get_instance();

                $schedule_result = $shift_patterns->save_employee_schedule(array(
                    'employee_id' => $employee_id,
                    'shift_pattern_id' => $shift_pattern_id,
                    'start_date' => $hire_date ?: current_time('Y-m-d'),
                    'is_active' => 1,
                    'employee_id' => $employee_id,
                    'shift_pattern_id' => $shift_pattern_id,
                    'start_date' => $hire_date ?: current_time('Y-m-d'),
                    'is_active' => 1,
                    'notes' => 'Vinculação automática via cadastro de funcionário',
                    // New fields
                    'shift_start_time' => $shift_start_time,
                    'shift_end_time' => $shift_end_time,
                    'lunch_duration_minutes' => $lunch_duration_minutes,
                    'active_days' => $active_days
                ));

                if ($schedule_result) {
                    error_log('SISTUR DEBUG: ✓ Escala vinculada com sucesso ao novo funcionário!');
                } else {
                    error_log('SISTUR DEBUG AVISO: Falha ao vincular escala ao novo funcionário');
                }
            }

            error_log('========== SISTUR DEBUG: ajax_save_employee FINALIZADO COM SUCESSO ==========');

            wp_send_json_success(array(
                'message' => 'Funcionário criado com sucesso!',
                'employee_id' => $employee_id
            ));
        }
    }

    /**
     * AJAX: Deletar funcionário
     */
    public function ajax_delete_employee() {
        check_ajax_referer('sistur_employees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employees';

        $result = $wpdb->delete($table, array('id' => $id), array('%d'));

        if ($result === false) {
            wp_send_json_error(array('message' => 'Erro ao excluir funcionário.'));
        }

        wp_send_json_success(array('message' => 'Funcionário excluído com sucesso!'));
    }

    /**
     * AJAX: Obter funcionário
     */
    public function ajax_get_employee() {
        check_ajax_referer('sistur_employees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        if ($id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employees';
        $table_schedules = $wpdb->prefix . 'sistur_employee_schedules';

        // Buscar dados do funcionário
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ), ARRAY_A);

        if (!$employee) {
            wp_send_json_error(array('message' => 'Funcionário não encontrado.'));
        }

        // Buscar escala ativa vinculada (se existir)
        $active_schedule = $wpdb->get_row($wpdb->prepare(
            "SELECT shift_pattern_id, start_date, end_date, shift_start_time, shift_end_time, lunch_duration_minutes, active_days
             FROM $table_schedules
             WHERE employee_id = %d AND is_active = 1
             ORDER BY start_date DESC
             LIMIT 1",
            $id
        ), ARRAY_A);

        // Adicionar shift_pattern_id ao array de funcionário
        if ($active_schedule) {
            $employee['shift_pattern_id'] = $active_schedule['shift_pattern_id'];
            $employee['schedule_start_date'] = $active_schedule['start_date'];
            $employee['schedule_end_date'] = $active_schedule['end_date'];
            // New fields
            $employee['schedule_shift_start_time'] = $active_schedule['shift_start_time'];
            $employee['schedule_shift_end_time'] = $active_schedule['shift_end_time'];
            $employee['schedule_lunch_duration_minutes'] = $active_schedule['lunch_duration_minutes'];
            $employee['schedule_active_days'] = $active_schedule['active_days'];
            
            error_log('SISTUR DEBUG ajax_get_employee: active_days carregado do banco: ' . var_export($active_schedule['active_days'], true));
        } else {
            $employee['shift_pattern_id'] = null;
            error_log('SISTUR DEBUG ajax_get_employee: Nenhum schedule ativo encontrado para funcionário ID ' . $id);
        }
        wp_send_json_success(array('employee' => $employee));
    }

    /**
     * AJAX: Salvar departamento
     */
    public function ajax_save_department() {
        // Log de debug para investigar problemas
        error_log('SISTUR: ajax_save_department chamado');
        error_log('SISTUR: POST data: ' . print_r($_POST, true));

        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sistur_employees_nonce')) {
            error_log('SISTUR: Falha na verificação do nonce');
            wp_send_json_error(array('message' => 'Falha na verificação de segurança. Recarregue a página e tente novamente.'));
            return;
        }

        // Verificar permissão
        if (!current_user_can('manage_options')) {
            error_log('SISTUR: Usuário sem permissão manage_options');
            wp_send_json_error(array('message' => 'Permissão negada.'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_departments';

        // Sanitizar e validar dados
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(trim($_POST['name'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(trim($_POST['description'])) : '';
        $status = isset($_POST['status']) ? intval($_POST['status']) : 1;

        // Validar campos obrigatórios
        if (empty($name)) {
            error_log('SISTUR: Nome do departamento vazio');
            wp_send_json_error(array('message' => 'Nome é obrigatório.'));
            return;
        }

        // Verificar se já existe departamento com mesmo nome (exceto o atual)
        $existing_query = "SELECT id FROM $table WHERE name = %s";
        $existing_params = array($name);

        if ($id > 0) {
            $existing_query .= " AND id != %d";
            $existing_params[] = $id;
        }

        $existing = $wpdb->get_var($wpdb->prepare($existing_query, $existing_params));

        if ($existing) {
            error_log("SISTUR: Departamento com nome '$name' já existe (ID: $existing)");
            wp_send_json_error(array('message' => 'Já existe um departamento com este nome.'));
            return;
        }

        // Preparar dados
        $data = array(
            'name' => $name,
            'description' => $description,
            'status' => $status
        );

        $format = array('%s', '%s', '%d');

        // Executar inserção ou atualização
        if ($id > 0) {
            error_log("SISTUR: Atualizando departamento ID: $id");
            $result = $wpdb->update($table, $data, array('id' => $id), $format, array('%d'));

            if ($result === false) {
                error_log("SISTUR: Erro ao atualizar departamento: " . $wpdb->last_error);
                wp_send_json_error(array('message' => 'Erro ao atualizar departamento: ' . $wpdb->last_error));
                return;
            }

            error_log("SISTUR: Departamento atualizado com sucesso");
        } else {
            error_log("SISTUR: Inserindo novo departamento: $name");
            $result = $wpdb->insert($table, $data, $format);

            if ($result === false) {
                error_log("SISTUR: Erro ao inserir departamento: " . $wpdb->last_error);
                wp_send_json_error(array('message' => 'Erro ao inserir departamento: ' . $wpdb->last_error));
                return;
            }

            $id = $wpdb->insert_id;
            error_log("SISTUR: Departamento inserido com sucesso. Novo ID: $id");
        }

        wp_send_json_success(array(
            'message' => 'Departamento salvo com sucesso!',
            'department_id' => $id
        ));
    }

    /**
     * AJAX: Deletar departamento
     */
    public function ajax_delete_department() {
        // Log de debug
        error_log('SISTUR: ajax_delete_department chamado');
        error_log('SISTUR: POST data: ' . print_r($_POST, true));

        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sistur_employees_nonce')) {
            error_log('SISTUR: Falha na verificação do nonce');
            wp_send_json_error(array('message' => 'Falha na verificação de segurança. Recarregue a página e tente novamente.'));
            return;
        }

        // Verificar permissão
        if (!current_user_can('manage_options')) {
            error_log('SISTUR: Usuário sem permissão manage_options');
            wp_send_json_error(array('message' => 'Permissão negada.'));
            return;
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            error_log('SISTUR: ID inválido: ' . $id);
            wp_send_json_error(array('message' => 'ID inválido.'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_departments';
        $employees_table = $wpdb->prefix . 'sistur_employees';

        // Verificar se o departamento existe
        $department = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ), ARRAY_A);

        if (!$department) {
            error_log("SISTUR: Departamento ID $id não encontrado");
            wp_send_json_error(array('message' => 'Departamento não encontrado.'));
            return;
        }

        // Verificar se há funcionários neste departamento
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $employees_table WHERE department_id = %d",
            $id
        ));

        if ($count > 0) {
            error_log("SISTUR: Departamento ID $id tem $count funcionários vinculados");
            wp_send_json_error(array(
                'message' => "Não é possível excluir departamento com $count funcionário(s) vinculado(s)."
            ));
            return;
        }

        // Deletar departamento
        error_log("SISTUR: Deletando departamento ID $id: " . $department['name']);
        $result = $wpdb->delete($table, array('id' => $id), array('%d'));

        if ($result === false) {
            error_log("SISTUR: Erro ao deletar departamento: " . $wpdb->last_error);
            wp_send_json_error(array('message' => 'Erro ao excluir departamento: ' . $wpdb->last_error));
            return;
        }

        error_log("SISTUR: Departamento deletado com sucesso");
        wp_send_json_success(array('message' => 'Departamento excluído com sucesso!'));
    }

    /**
     * AJAX: Obter departamento
     */
    public function ajax_get_department() {
        // Log de debug
        error_log('SISTUR: ajax_get_department chamado');
        error_log('SISTUR: GET data: ' . print_r($_GET, true));

        // Verificar nonce
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'sistur_employees_nonce')) {
            error_log('SISTUR: Falha na verificação do nonce');
            wp_send_json_error(array('message' => 'Falha na verificação de segurança. Recarregue a página e tente novamente.'));
            return;
        }

        // Verificar permissão
        if (!current_user_can('manage_options')) {
            error_log('SISTUR: Usuário sem permissão manage_options');
            wp_send_json_error(array('message' => 'Permissão negada.'));
            return;
        }

        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        if ($id <= 0) {
            error_log('SISTUR: ID inválido: ' . $id);
            wp_send_json_error(array('message' => 'ID inválido.'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_departments';

        error_log("SISTUR: Buscando departamento ID: $id");
        $department = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ), ARRAY_A);

        if (!$department) {
            error_log("SISTUR: Departamento ID $id não encontrado");
            wp_send_json_error(array('message' => 'Departamento não encontrado.'));
            return;
        }

        error_log("SISTUR: Departamento encontrado: " . $department['name']);
        wp_send_json_success(array('department' => $department));
    }

    /**
     * AJAX: Obter todos os departamentos
     */
    public function ajax_get_all_departments() {
        // Verificar nonce - aceitar tanto sistur_nonce quanto sistur_employees_nonce para compatibilidade
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_GET['nonce']) ? $_GET['nonce'] : '');
        $nonce_valid = wp_verify_nonce($nonce, 'sistur_nonce') || wp_verify_nonce($nonce, 'sistur_employees_nonce');

        if (!$nonce_valid) {
            wp_send_json_error(array('message' => 'Falha na verificação de segurança.'));
            return;
        }

        // Verificar permissão
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
            return;
        }

        $status = isset($_POST['status']) ? intval($_POST['status']) : null;
        $departments = self::get_all_departments($status);

        wp_send_json_success($departments);
    }

    /**
     * AJAX: Salvar pagamento
     */
    public function ajax_save_payment() {
        check_ajax_referer('sistur_employees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_payment_records';

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $payment_type = isset($_POST['payment_type']) ? sanitize_text_field($_POST['payment_type']) : '';
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $payment_date = isset($_POST['payment_date']) ? sanitize_text_field($_POST['payment_date']) : date('Y-m-d');
        $period_start = isset($_POST['period_start']) ? sanitize_text_field($_POST['period_start']) : null;
        $period_end = isset($_POST['period_end']) ? sanitize_text_field($_POST['period_end']) : null;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';

        if ($employee_id <= 0) {
            wp_send_json_error(array('message' => 'Funcionário inválido.'));
        }

        if (empty($payment_type)) {
            wp_send_json_error(array('message' => 'Tipo de pagamento é obrigatório.'));
        }

        if ($amount <= 0) {
            wp_send_json_error(array('message' => 'Valor deve ser maior que zero.'));
        }

        $data = array(
            'employee_id' => $employee_id,
            'payment_type' => $payment_type,
            'amount' => $amount,
            'payment_date' => $payment_date,
            'period_start' => $period_start,
            'period_end' => $period_end,
            'notes' => $notes,
            'created_by' => get_current_user_id()
        );

        $format = array('%d', '%s', '%f', '%s', '%s', '%s', '%s', '%d');

        $result = $wpdb->insert($table, $data, $format);

        if ($result === false) {
            wp_send_json_error(array('message' => 'Erro ao salvar pagamento.'));
        }

        wp_send_json_success(array(
            'message' => 'Pagamento registrado com sucesso!',
            'payment_id' => $wpdb->insert_id
        ));
    }

    /**
     * AJAX: Obter pagamentos de funcionário
     */
    public function ajax_get_employee_payments() {
        check_ajax_referer('sistur_employees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;

        if ($employee_id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_payment_records';

        $payments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE employee_id = %d ORDER BY payment_date DESC, created_at DESC",
            $employee_id
        ), ARRAY_A);

        wp_send_json_success(array('payments' => $payments));
    }

    /**
     * Renderiza a página de funcionários
     */
    public static function render_employees_page() {
        include SISTUR_PLUGIN_DIR . 'admin/views/employees/employees.php';
    }

    /**
     * Renderiza a página de departamentos
     */
    public static function render_departments_page() {
        include SISTUR_PLUGIN_DIR . 'admin/views/employees/departments.php';
    }

    /**
     * Renderiza a página de pagamentos
     */
    public static function render_payments_page() {
        include SISTUR_PLUGIN_DIR . 'admin/views/employees/payments.php';
    }

    /**
     * Obtém todos os funcionários
     */
    public static function get_all_employees($args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employees';

        $defaults = array(
            'status' => null,
            'department_id' => null,
            'search' => '',
            'orderby' => 'name',
            'order' => 'ASC',
            'limit' => -1,
            'offset' => 0
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $where_values = array();

        if ($args['status'] !== null) {
            $where[] = 'status = %d';
            $where_values[] = $args['status'];
        }

        if ($args['department_id'] !== null) {
            $where[] = 'department_id = %d';
            $where_values[] = $args['department_id'];
        }

        if (!empty($args['search'])) {
            $where[] = '(name LIKE %s OR email LIKE %s OR cpf LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        $where_clause = implode(' AND ', $where);

        // BUG FIX #3: Validar sanitize_sql_orderby e usar fallback seguro
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        if ($orderby === false || empty($orderby)) {
            $orderby = 'name ASC'; // fallback seguro
        }

        $sql = "SELECT * FROM $table WHERE $where_clause";

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        $sql .= " ORDER BY $orderby";

        if ($args['limit'] > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $args['limit'], $args['offset']);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Conta total de funcionários (para paginação)
     */
    public static function count_employees($args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employees';

        $defaults = array(
            'status' => null,
            'department_id' => null,
            'search' => ''
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $where_values = array();

        if ($args['status'] !== null) {
            $where[] = 'status = %d';
            $where_values[] = $args['status'];
        }

        if ($args['department_id'] !== null) {
            $where[] = 'department_id = %d';
            $where_values[] = $args['department_id'];
        }

        if (!empty($args['search'])) {
            $where[] = '(name LIKE %s OR email LIKE %s OR cpf LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT COUNT(*) FROM $table WHERE $where_clause";

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Obtém todos os departamentos
     */
    public static function get_all_departments($status = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_departments';

        if ($status !== null) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE status = %d ORDER BY name ASC",
                $status
            ), ARRAY_A);
        }

        return $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC", ARRAY_A);
    }
}
