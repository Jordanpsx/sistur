<?php
/**
 * Classe de ativação do plugin
 *
 * @package SISTUR
 */

class SISTUR_Activator
{

    /**
     * Código executado durante a ativação do plugin
     */
    public static function activate()
    {
        self::create_tables();
        self::create_default_pages();
        self::set_default_options();
        self::update_rh_role_permissions();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Código executado durante a desativação do plugin
     */
    public static function deactivate()
    {
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Cria todas as tabelas do banco de dados
     */
    private static function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Tabela de funcionários
        $table_employees = $wpdb->prefix . 'sistur_employees';
        $sql_employees = "CREATE TABLE IF NOT EXISTS $table_employees (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            email varchar(255) DEFAULT NULL,
            phone varchar(20) DEFAULT NULL,
            department_id mediumint(9) DEFAULT NULL,
            position varchar(255) DEFAULT NULL,
            photo varchar(500) DEFAULT NULL,
            bio text DEFAULT NULL,
            hire_date date DEFAULT NULL,
            cpf varchar(14) DEFAULT NULL UNIQUE,
            password varchar(255) DEFAULT NULL,
            token_qr varchar(36) DEFAULT NULL UNIQUE,
            contract_type_id mediumint(9) DEFAULT NULL,
            perfil_localizacao enum('INTERNO','EXTERNO') DEFAULT 'INTERNO',
            time_expected_minutes smallint DEFAULT 480,
            lunch_minutes smallint DEFAULT 60,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            status tinyint(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY department_id (department_id),
            KEY status (status),
            KEY cpf (cpf),
            KEY token_qr (token_qr),
            KEY contract_type_id (contract_type_id)
        ) $charset_collate;";

        // Tabela de departamentos
        $table_departments = $wpdb->prefix . 'sistur_departments';
        $sql_departments = "CREATE TABLE IF NOT EXISTS $table_departments (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL UNIQUE,
            description text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            status tinyint(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY status (status)
        ) $charset_collate;";

        // Tabela de registros de ponto
        $table_time_entries = $wpdb->prefix . 'sistur_time_entries';
        $sql_time_entries = "CREATE TABLE IF NOT EXISTS $table_time_entries (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            employee_id mediumint(9) NOT NULL,
            punch_type varchar(20) NOT NULL,
            punch_time datetime NOT NULL,
            shift_date date NOT NULL,
            notes varchar(255) DEFAULT NULL,
            created_by bigint(20) DEFAULT NULL,
            source enum('admin','employee','system','KIOSK','MOBILE_APP','MANUAL_AJUSTE') DEFAULT 'admin',
            processing_status enum('PENDENTE','PROCESSADO') DEFAULT 'PENDENTE',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY employee_id (employee_id),
            KEY shift_date (shift_date),
            KEY punch_type (punch_type),
            KEY created_by (created_by),
            KEY processing_status (processing_status)
        ) $charset_collate;";

        // Tabela de status diário de ponto
        $table_time_days = $wpdb->prefix . 'sistur_time_days';
        $sql_time_days = "CREATE TABLE IF NOT EXISTS $table_time_days (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            employee_id mediumint(9) NOT NULL,
            shift_date date NOT NULL,
            status enum('present','absence_no_pay','absence_medical','bank_used','holiday','day_off') DEFAULT 'present',
            notes text DEFAULT NULL,
            attachment_id bigint(20) DEFAULT NULL,
            bank_minutes_adjustment int DEFAULT 0,
            needs_review tinyint(1) DEFAULT 0,
            supervisor_notes text DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            reviewed_by bigint(20) DEFAULT NULL,
            minutos_trabalhados int DEFAULT 0,
            saldo_calculado_minutos int DEFAULT 0,
            saldo_final_minutos int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY employee_date (employee_id, shift_date),
            KEY shift_date (shift_date),
            KEY status (status),
            KEY needs_review (needs_review)
        ) $charset_collate;";

        // Tabela de registros de pagamento
        $table_payment_records = $wpdb->prefix . 'sistur_payment_records';
        $sql_payment_records = "CREATE TABLE IF NOT EXISTS $table_payment_records (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            employee_id mediumint(9) NOT NULL,
            payment_type varchar(20) NOT NULL,
            amount decimal(10,2) NOT NULL,
            payment_date date NOT NULL,
            period_start date DEFAULT NULL,
            period_end date DEFAULT NULL,
            notes text DEFAULT NULL,
            created_by bigint(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY employee_id (employee_id),
            KEY payment_date (payment_date),
            KEY payment_type (payment_type)
        ) $charset_collate;";

        // Tabela de leads
        $table_leads = $wpdb->prefix . 'sistur_leads';
        $sql_leads = "CREATE TABLE IF NOT EXISTS $table_leads (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            email varchar(255) DEFAULT NULL,
            phone varchar(20) DEFAULT NULL,
            source varchar(100) DEFAULT 'form_inicial',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(50) DEFAULT 'new',
            notes text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at),
            KEY source (source)
        ) $charset_collate;";

        // Tabela de produtos
        $table_products = $wpdb->prefix . 'sistur_products';
        $sql_products = "CREATE TABLE IF NOT EXISTS $table_products (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            sku varchar(100) DEFAULT NULL,
            barcode varchar(100) DEFAULT NULL,
            category_id mediumint(9) DEFAULT NULL,
            cost_price decimal(10,2) DEFAULT 0.00,
            selling_price decimal(10,2) DEFAULT 0.00,
            min_stock int DEFAULT 0,
            current_stock int DEFAULT 0,
            status enum('active','inactive') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY sku (sku),
            KEY category_id (category_id),
            KEY status (status)
        ) $charset_collate;";

        // Tabela de categorias de produtos
        $table_product_categories = $wpdb->prefix . 'sistur_product_categories';
        $sql_product_categories = "CREATE TABLE IF NOT EXISTS $table_product_categories (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL UNIQUE,
            description text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // Tabela de movimentações de inventário
        $table_inventory_movements = $wpdb->prefix . 'sistur_inventory_movements';
        $sql_inventory_movements = "CREATE TABLE IF NOT EXISTS $table_inventory_movements (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            product_id mediumint(9) NOT NULL,
            type enum('entry','exit','adjustment') NOT NULL,
            movement_reason enum('sale','loss','damage','theft','adjustment','donation','sample','purchase','return') DEFAULT 'sale',
            quantity int NOT NULL,
            unit_price decimal(10,2) DEFAULT 0.00,
            total_value decimal(10,2) DEFAULT 0.00,
            reference varchar(100) DEFAULT NULL,
            notes text DEFAULT NULL,
            requires_approval tinyint(1) DEFAULT 0,
            approval_request_id mediumint(9) DEFAULT NULL,
            created_by bigint(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY type (type),
            KEY movement_reason (movement_reason),
            KEY approval_request_id (approval_request_id),
            KEY created_at (created_at),
            KEY created_by (created_by)
        ) $charset_collate;";

        // Tabela de papéis/funções (roles)
        $table_roles = $wpdb->prefix . 'sistur_roles';
        $sql_roles = "CREATE TABLE IF NOT EXISTS $table_roles (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL UNIQUE,
            description text DEFAULT NULL,
            department_id mediumint(9) DEFAULT NULL,
            is_admin tinyint(1) DEFAULT 0,
            approval_level int DEFAULT 0,
            can_approve_for_roles text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY is_admin (is_admin),
            KEY department_id (department_id),
            KEY approval_level (approval_level)
        ) $charset_collate;";

        // Tabela de permissões
        $table_permissions = $wpdb->prefix . 'sistur_permissions';
        $sql_permissions = "CREATE TABLE IF NOT EXISTS $table_permissions (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            module varchar(50) NOT NULL,
            action varchar(50) NOT NULL,
            label varchar(100) NOT NULL,
            description text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_permission (module, action),
            KEY module (module)
        ) $charset_collate;";

        // Tabela de relação papéis → permissões
        $table_role_permissions = $wpdb->prefix . 'sistur_role_permissions';
        $sql_role_permissions = "CREATE TABLE IF NOT EXISTS $table_role_permissions (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            role_id mediumint(9) NOT NULL,
            permission_id mediumint(9) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_role_permission (role_id, permission_id),
            KEY role_id (role_id),
            KEY permission_id (permission_id)
        ) $charset_collate;";

        // Tabela de logs de auditoria
        $table_audit_logs = $wpdb->prefix . 'sistur_audit_logs';
        $sql_audit_logs = "CREATE TABLE IF NOT EXISTS $table_audit_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id mediumint(9) DEFAULT NULL,
            module varchar(50) NOT NULL,
            action varchar(50) NOT NULL,
            entity_id mediumint(9) DEFAULT NULL,
            old_values text DEFAULT NULL,
            new_values text DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY module (module),
            KEY action (action),
            KEY entity_id (entity_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Tabela de tipos de contrato
        $table_contract_types = $wpdb->prefix . 'sistur_contract_types';
        $sql_contract_types = "CREATE TABLE IF NOT EXISTS $table_contract_types (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            descricao varchar(255) NOT NULL,
            carga_horaria_diaria_minutos int NOT NULL DEFAULT 480,
            carga_horaria_semanal_minutos int NOT NULL DEFAULT 2640,
            intervalo_minimo_almoco_minutos int NOT NULL DEFAULT 60,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // Tabela de padrões de escala (shift patterns)
        $table_shift_patterns = $wpdb->prefix . 'sistur_shift_patterns';
        $sql_shift_patterns = "CREATE TABLE IF NOT EXISTS $table_shift_patterns (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            pattern_type enum('fixed_days','weekly_rotation','flexible_hours') DEFAULT 'fixed_days',
            work_days_count int DEFAULT 6,
            rest_days_count int DEFAULT 1,
            weekly_hours_minutes int DEFAULT 2640,
            daily_hours_minutes int DEFAULT 480,
            lunch_break_minutes int DEFAULT 60,
            pattern_config text DEFAULT NULL,
            status tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY pattern_type (pattern_type),
            KEY status (status)
        ) $charset_collate;";

        // Tabela de escalas de funcionários (employee schedules)
        $table_employee_schedules = $wpdb->prefix . 'sistur_employee_schedules';
        $sql_employee_schedules = "CREATE TABLE IF NOT EXISTS $table_employee_schedules (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            employee_id mediumint(9) NOT NULL,
            shift_pattern_id mediumint(9) NOT NULL,
            start_date date NOT NULL,
            end_date date DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY employee_id (employee_id),
            KEY shift_pattern_id (shift_pattern_id),
            KEY is_active (is_active),
            KEY start_date (start_date),
            KEY end_date (end_date)
        ) $charset_collate;";

        // Tabela de configurações do sistema
        $table_settings = $wpdb->prefix . 'sistur_settings';
        $sql_settings = "CREATE TABLE IF NOT EXISTS $table_settings (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            setting_key varchar(100) NOT NULL,
            setting_value text DEFAULT NULL,
            setting_type enum('string','integer','boolean','json') DEFAULT 'string',
            description text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset_collate;";

        // Tabela de redes Wi-Fi autorizadas para registro de ponto
        $table_wifi_networks = $wpdb->prefix . 'sistur_wifi_networks';
        $sql_wifi_networks = "CREATE TABLE IF NOT EXISTS $table_wifi_networks (
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

        // Tabela de localizações autorizadas para registro de ponto
        $table_authorized_locations = $wpdb->prefix . 'sistur_authorized_locations';
        $sql_authorized_locations = "CREATE TABLE IF NOT EXISTS $table_authorized_locations (
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

        // Tabela de exceções de escalas (feriados, afastamentos, trocas de folga)
        $table_schedule_exceptions = $wpdb->prefix . 'sistur_schedule_exceptions';
        $sql_schedule_exceptions = "CREATE TABLE IF NOT EXISTS $table_schedule_exceptions (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            employee_id mediumint(9) NOT NULL,
            exception_type enum('holiday','sick_leave','vacation','day_off_trade','special_event','absence') NOT NULL,
            date date NOT NULL,
            custom_expected_minutes int DEFAULT NULL,
            traded_with_employee_id mediumint(9) DEFAULT NULL,
            traded_date date DEFAULT NULL,
            status enum('pending','approved','rejected') DEFAULT 'pending',
            approved_by int DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY employee_date (employee_id, date),
            KEY exception_type (exception_type),
            KEY status (status),
            KEY traded_with (traded_with_employee_id),
            KEY approved_by (approved_by)
        ) $charset_collate;";

        // Tabela de feriados (sistur_holidays) - Corrigindo erro de tabela inexistente
        $table_holidays = $wpdb->prefix . 'sistur_holidays';
        $sql_holidays = "CREATE TABLE IF NOT EXISTS $table_holidays (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            holiday_date date NOT NULL,
            description varchar(255) NOT NULL,
            holiday_type enum('national','state','municipal','optional') DEFAULT 'national',
            status enum('active','inactive') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY holiday_date (holiday_date),
            KEY status (status)
        ) $charset_collate;";

        // Tabela de períodos de banco de horas
        $table_time_bank_periods = $wpdb->prefix . 'sistur_time_bank_periods';
        $sql_time_bank_periods = "CREATE TABLE IF NOT EXISTS $table_time_bank_periods (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            employee_id mediumint(9) NOT NULL,
            period_name varchar(100) NOT NULL,
            start_date date NOT NULL,
            end_date date NOT NULL,
            balance_minutes int DEFAULT 0,
            expiration_policy enum('6_months','1_year','never') DEFAULT '1_year',
            expires_at date DEFAULT NULL,
            expiration_action enum('lose','convert_to_payment','require_use') DEFAULT 'require_use',
            status enum('active','expired','compensated','closed') DEFAULT 'active',
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY employee_id (employee_id),
            KEY status (status),
            KEY expires_at (expires_at),
            KEY period_dates (start_date, end_date)
        ) $charset_collate;";

        // Tabela de transações do banco de horas
        $table_time_bank_transactions = $wpdb->prefix . 'sistur_time_bank_transactions';
        $sql_time_bank_transactions = "CREATE TABLE IF NOT EXISTS $table_time_bank_transactions (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            period_id mediumint(9) NOT NULL,
            employee_id mediumint(9) NOT NULL,
            transaction_date date NOT NULL,
            type enum('accrual','deduction','compensation','expiration','adjustment','transfer') NOT NULL,
            minutes int NOT NULL,
            source_type enum('punch','manual','holiday','absence','trade','overtime') DEFAULT 'punch',
            source_reference varchar(255) DEFAULT NULL,
            requires_approval tinyint(1) DEFAULT 0,
            approved_by int DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY period_id (period_id),
            KEY employee_date (employee_id, transaction_date),
            KEY type (type),
            KEY source_type (source_type),
            KEY approved_by (approved_by)
        ) $charset_collate;";

        // Tabela de solicitações de aprovação (ERP Modular v2.0)
        $table_approval_requests = $wpdb->prefix . 'sistur_approval_requests';
        $sql_approval_requests = "CREATE TABLE IF NOT EXISTS $table_approval_requests (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            module varchar(50) NOT NULL,
            action varchar(50) NOT NULL,
            entity_type varchar(50) DEFAULT NULL,
            entity_id mediumint(9) DEFAULT NULL,
            request_data longtext NOT NULL,
            requested_by mediumint(9) NOT NULL,
            requested_at datetime DEFAULT CURRENT_TIMESTAMP,
            status enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
            priority enum('low','normal','high','urgent') DEFAULT 'normal',
            required_role_id mediumint(9) DEFAULT NULL,
            approved_by mediumint(9) DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            approval_notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY module_action (module, action),
            KEY status (status),
            KEY requested_by (requested_by),
            KEY approved_by (approved_by),
            KEY required_role_id (required_role_id),
            KEY entity_type_id (entity_type, entity_id)
        ) $charset_collate;";

        // Tabela de configuração de módulos do Portal (ERP Modular v2.0)
        $table_module_config = $wpdb->prefix . 'sistur_module_config';
        $sql_module_config = "CREATE TABLE IF NOT EXISTS $table_module_config (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            module_key varchar(50) NOT NULL UNIQUE,
            module_name varchar(100) NOT NULL,
            module_icon varchar(50) NOT NULL,
            module_color varchar(20) DEFAULT '#0d9488',
            module_description text DEFAULT NULL,
            required_permission varchar(100) NOT NULL,
            target_url varchar(255) NOT NULL,
            target_type enum('tab','page','external') DEFAULT 'tab',
            sort_order int DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY module_key (module_key),
            KEY is_active (is_active),
            KEY sort_order (sort_order)
        ) $charset_collate;";

        // Executar queries
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($sql_employees);
        dbDelta($sql_departments);
        dbDelta($sql_time_entries);
        dbDelta($sql_time_days);
        dbDelta($sql_payment_records);
        dbDelta($sql_leads);
        dbDelta($sql_products);
        dbDelta($sql_product_categories);
        dbDelta($sql_inventory_movements);
        dbDelta($sql_roles);
        dbDelta($sql_permissions);
        dbDelta($sql_role_permissions);
        dbDelta($sql_audit_logs);
        dbDelta($sql_contract_types);
        dbDelta($sql_shift_patterns);
        dbDelta($sql_employee_schedules);
        dbDelta($sql_settings);
        dbDelta($sql_wifi_networks);
        dbDelta($sql_authorized_locations);
        dbDelta($sql_schedule_exceptions);
        dbDelta($sql_holidays);
        dbDelta($sql_time_bank_periods);
        dbDelta($sql_time_bank_transactions);
        dbDelta($sql_approval_requests);
        dbDelta($sql_module_config);

        // Migração: Adicionar coluna password se não existir
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_employees LIKE 'password'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table_employees ADD COLUMN password varchar(255) DEFAULT NULL AFTER cpf");
        }

        // Migração: Adicionar coluna role_id se não existir
        $role_column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_employees LIKE 'role_id'");
        if (empty($role_column_exists)) {
            $wpdb->query("ALTER TABLE $table_employees ADD COLUMN role_id mediumint(9) DEFAULT NULL AFTER password, ADD KEY role_id (role_id)");
        }

        // Migração: Adicionar colunas do novo sistema de ponto (v1.4.0)
        $token_qr_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_employees LIKE 'token_qr'");
        if (empty($token_qr_exists)) {
            $wpdb->query("ALTER TABLE $table_employees
                ADD COLUMN token_qr varchar(36) DEFAULT NULL UNIQUE AFTER password,
                ADD COLUMN contract_type_id mediumint(9) DEFAULT NULL AFTER token_qr,
                ADD COLUMN perfil_localizacao enum('INTERNO','EXTERNO') DEFAULT 'INTERNO' AFTER contract_type_id,
                ADD KEY token_qr (token_qr),
                ADD KEY contract_type_id (contract_type_id)");
        }

        // Migração: Adicionar colunas de processamento em time_entries
        $processing_status_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_time_entries LIKE 'processing_status'");
        if (empty($processing_status_exists)) {
            $wpdb->query("ALTER TABLE $table_time_entries
                MODIFY source enum('admin','employee','system','KIOSK','MOBILE_APP','MANUAL_AJUSTE') DEFAULT 'admin',
                ADD COLUMN processing_status enum('PENDENTE','PROCESSADO') DEFAULT 'PENDENTE' AFTER source,
                ADD KEY processing_status (processing_status)");
        }

        // Migração: Adicionar colunas de revisão em time_days
        $needs_review_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_time_days LIKE 'needs_review'");
        if (empty($needs_review_exists)) {
            $wpdb->query("ALTER TABLE $table_time_days
                ADD COLUMN needs_review tinyint(1) DEFAULT 0 AFTER bank_minutes_adjustment,
                ADD COLUMN supervisor_notes text DEFAULT NULL AFTER needs_review,
                ADD COLUMN reviewed_at datetime DEFAULT NULL AFTER supervisor_notes,
                ADD COLUMN reviewed_by bigint(20) DEFAULT NULL AFTER reviewed_at,
                ADD COLUMN minutos_trabalhados int DEFAULT 0 AFTER reviewed_by,
                ADD COLUMN saldo_calculado_minutos int DEFAULT 0 AFTER minutos_trabalhados,
                ADD COLUMN saldo_final_minutos int DEFAULT 0 AFTER saldo_calculado_minutos,
                ADD KEY needs_review (needs_review)");
        }

        // Migração: Adicionar campos para folha de ponto (v1.4.2)
        $matricula_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_employees LIKE 'matricula'");
        if (empty($matricula_exists)) {
            $wpdb->query("ALTER TABLE $table_employees
                ADD COLUMN matricula varchar(50) DEFAULT NULL AFTER cpf,
                ADD COLUMN ctps varchar(20) DEFAULT NULL AFTER matricula,
                ADD COLUMN ctps_uf varchar(2) DEFAULT NULL AFTER ctps,
                ADD COLUMN cbo varchar(10) DEFAULT NULL AFTER ctps_uf,
                ADD KEY matricula (matricula)");
        }

        // Migração: Adicionar coluna user_id para vincular com usuários WordPress
        $user_id_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_employees LIKE 'user_id'");
        if (empty($user_id_exists)) {
            $wpdb->query("ALTER TABLE $table_employees
                ADD COLUMN user_id bigint(20) DEFAULT NULL AFTER id,
                ADD UNIQUE KEY user_id (user_id)");
            error_log("SISTUR: Coluna user_id adicionada à tabela de funcionários");
        }

        // Migração: Adicionar campos de auditoria administrativa em time_entries
        $admin_change_reason_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_time_entries LIKE 'admin_change_reason'");
        if (empty($admin_change_reason_exists)) {
            $wpdb->query("ALTER TABLE $table_time_entries
                ADD COLUMN admin_change_reason text DEFAULT NULL AFTER notes,
                ADD COLUMN changed_by_user_id bigint(20) DEFAULT NULL AFTER admin_change_reason,
                ADD COLUMN changed_by_role varchar(50) DEFAULT NULL AFTER changed_by_user_id,
                ADD KEY changed_by_user_id (changed_by_user_id)");
            error_log("SISTUR: Campos de auditoria administrativa adicionados à tabela time_entries");
        }

        // Migração: Adicionar campos de auditoria administrativa em time_days
        $admin_change_reason_days_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_time_days LIKE 'admin_change_reason'");
        if (empty($admin_change_reason_days_exists)) {
            $wpdb->query("ALTER TABLE $table_time_days
                ADD COLUMN admin_change_reason text DEFAULT NULL AFTER supervisor_notes,
                ADD COLUMN changed_by_user_id bigint(20) DEFAULT NULL AFTER admin_change_reason,
                ADD COLUMN changed_by_role varchar(50) DEFAULT NULL AFTER changed_by_user_id,
                ADD KEY changed_by_user_id (changed_by_user_id)");
            error_log("SISTUR: Campos de auditoria administrativa adicionados à tabela time_days");
        }

        // Migração: Adicionar campo is_justified na tabela schedule_exceptions (v1.5.0)
        $is_justified_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_schedule_exceptions LIKE 'is_justified'");
        if (empty($is_justified_exists)) {
            $wpdb->query("ALTER TABLE $table_schedule_exceptions
                ADD COLUMN is_justified tinyint(1) DEFAULT 0 AFTER notes,
                ADD KEY is_justified (is_justified)");
            error_log("SISTUR: Campo is_justified adicionado à tabela schedule_exceptions");
        }

        // Migração: Remover colunas de geolocalização antigas da tabela wifi_networks (se existirem)
        $latitude_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_wifi_networks LIKE 'latitude'");
        if (!empty($latitude_exists)) {
            $wpdb->query("ALTER TABLE $table_wifi_networks
                DROP COLUMN latitude,
                DROP COLUMN longitude,
                DROP COLUMN location_radius_meters");
        }

        // Migração: Adicionar coluna department_id na tabela roles
        $department_id_roles_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_roles LIKE 'department_id'");
        if (empty($department_id_roles_exists)) {
            $wpdb->query("ALTER TABLE $table_roles
                ADD COLUMN department_id mediumint(9) DEFAULT NULL AFTER description,
                ADD KEY department_id (department_id)");
            error_log("SISTUR: Coluna department_id adicionada à tabela de roles");
        }

        // ========================================================================
        // MIGRAÇÃO v2.2.0: Arquitetura de Escalas com Snapshots Históricos
        // ========================================================================

        // Migração: Adicionar colunas de snapshot histórico na tabela time_days
        // Isso permite que alterações de escala NÃO afetem cálculos passados
        $expected_snapshot_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_time_days LIKE 'expected_minutes_snapshot'");
        if (empty($expected_snapshot_exists)) {
            $wpdb->query("ALTER TABLE $table_time_days
                ADD COLUMN expected_minutes_snapshot INT DEFAULT NULL COMMENT 'Snapshot histórico de minutos esperados no momento do processamento' AFTER saldo_final_minutos,
                ADD COLUMN schedule_id_snapshot INT DEFAULT NULL COMMENT 'ID da escala usada no momento do processamento' AFTER expected_minutes_snapshot");
            error_log("SISTUR: Colunas de snapshot histórico adicionadas à tabela time_days");
        }

        // Migração: Adicionar campos de janela de tempo na tabela employee_schedules
        // Permite definir horário de entrada/saída (H:i) além de apenas minutos esperados
        $shift_start_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_employee_schedules LIKE 'shift_start_time'");
        if (empty($shift_start_exists)) {
            $wpdb->query("ALTER TABLE $table_employee_schedules
                ADD COLUMN shift_start_time TIME DEFAULT '08:00:00' COMMENT 'Horário de início do turno (H:i)' AFTER notes,
                ADD COLUMN shift_end_time TIME DEFAULT '17:00:00' COMMENT 'Horário de fim do turno (H:i)' AFTER shift_start_time,
                ADD COLUMN lunch_duration_minutes INT DEFAULT 60 COMMENT 'Duração do intervalo de almoço em minutos' AFTER shift_end_time,
                ADD COLUMN active_days JSON DEFAULT NULL COMMENT 'Array de dias ativos da semana [0-6]' AFTER lunch_duration_minutes");
            error_log("SISTUR: Campos de janela de tempo adicionados à tabela employee_schedules");
        }

        // Migração: Adicionar scale_mode na tabela shift_patterns
        // Define os 3 tipos de contrato: TYPE_FIXED_DAYS, TYPE_HOURLY, TYPE_DAILY
        $scale_mode_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_shift_patterns LIKE 'scale_mode'");
        if (empty($scale_mode_exists)) {
            $wpdb->query("ALTER TABLE $table_shift_patterns
                ADD COLUMN scale_mode ENUM('TYPE_FIXED_DAYS','TYPE_HOURLY','TYPE_DAILY') DEFAULT 'TYPE_FIXED_DAYS' COMMENT 'Tipo de modo de contrato' AFTER pattern_type,
                ADD KEY scale_mode (scale_mode)");
            error_log("SISTUR: Coluna scale_mode adicionada à tabela shift_patterns");

            // Migrar padrões existentes baseado em pattern_type
            $wpdb->query("UPDATE $table_shift_patterns SET scale_mode = 'TYPE_FIXED_DAYS' WHERE pattern_type IN ('fixed_days', 'weekly_rotation')");
            $wpdb->query("UPDATE $table_shift_patterns SET scale_mode = 'TYPE_HOURLY' WHERE pattern_type = 'flexible_hours'");
        }

        // Criar permissões e roles padrão
        self::create_default_permissions();
        self::create_default_roles();

        // Criar tipo de contrato padrão e configurações iniciais
        self::create_default_contract_types();
        self::create_default_shift_patterns();
        self::create_default_settings();

        // Gerar tokens UUID e QR codes para funcionários que não possuem
        self::generate_missing_tokens_and_qrcodes();

        // Migração: Garantir que punch_type tenha tamanho suficiente (v1.4.1)
        // Isso corrige o problema onde 'lunch_start' era truncado em versões antigas do MySQL/MariaDB
        $wpdb->query("ALTER TABLE $table_time_entries MODIFY COLUMN punch_type varchar(20) NOT NULL");

        // Sincronizar funcionários existentes com usuários WordPress
        self::sync_existing_employees_with_wordpress_users();

        // ERP Modular v2.0: Migrações para novas colunas
        self::run_erp_modular_migrations();

        // ERP Modular v2.0: Criar módulos padrão do portal
        self::create_default_portal_modules();

        // Stock Management & CMV v2.1: Instalar tabelas do módulo de estoque avançado
        require_once dirname(__FILE__) . '/class-sistur-stock-installer.php';
        SISTUR_Stock_Installer::install();

        // Atualizar versão do banco de dados
        update_option('sistur_db_version', SISTUR_DB_VERSION);
        update_option('sistur_time_db_version', '1.1.0');
        update_option('sistur_inventory_db_version', '2.1');
        update_option('sistur_erp_version', '2.0.0');
    }

    /**
     * Cria as páginas padrão do plugin
     */
    public static function create_default_pages()
    {
        // Página de Login do Funcionário
        $login_page = get_page_by_path('login-funcionario');
        if (!$login_page) {
            wp_insert_post(array(
                'post_title' => 'Login de Funcionário',
                'post_name' => 'login-funcionario',
                'post_content' => '[sistur_login_funcionario]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => 1
            ));
        }

        // Página do Painel do Funcionário (versão completa)
        $painel_page = get_page_by_path('painel-funcionario');
        if (!$painel_page) {
            wp_insert_post(array(
                'post_title' => 'Painel do Funcionário',
                'post_name' => 'painel-funcionario',
                'post_content' => '[sistur_registrar_ponto]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => 1
            ));
        } else {
            // Atualizar páginas existentes para usar o novo shortcode simplificado
            $current_content = $painel_page->post_content;
            if (strpos($current_content, '[sistur_painel_funcionario]') !== false) {
                wp_update_post(array(
                    'ID' => $painel_page->ID,
                    'post_content' => '[sistur_registrar_ponto]'
                ));
            }
        }

        // Página de Registro de Ponto Simplificado (nova)
        $clock_page = get_page_by_path('registrar-ponto');
        if (!$clock_page) {
            wp_insert_post(array(
                'post_title' => 'Registrar Ponto',
                'post_name' => 'registrar-ponto',
                'post_content' => '[sistur_registrar_ponto]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => 1
            ));
        }

        // Página de Banco de Horas
        $bank_page = get_page_by_path('banco-de-horas');
        if (!$bank_page) {
            wp_insert_post(array(
                'post_title' => 'Banco de Horas',
                'post_name' => 'banco-de-horas',
                'post_content' => '[sistur_banco_horas]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => 1
            ));
        }

        // Dashboard do Funcionário (Novo)
        $dashboard_slug = 'areafuncionario';
        $dashboard_page = get_page_by_path($dashboard_slug);
        if (!$dashboard_page) {
            wp_insert_post(array(
                'post_title' => 'Área do Funcionário',
                'post_name' => $dashboard_slug,
                'post_content' => '[sistur_dashboard]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => 1,
                'comment_status' => 'closed'
            ));
        }

        // Módulo de RH (ERP Modular v2.0)
        $rh_page = get_page_by_path('rh');
        if (!$rh_page) {
            wp_insert_post(array(
                'post_title' => 'Recursos Humanos',
                'post_name' => 'rh',
                'post_content' => '[sistur_rh_module]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => 1,
                'comment_status' => 'closed'
            ));
        }
    }

    /**
     * Define as opções padrão do plugin
     */
    private static function set_default_options()
    {
        // Horários de funcionamento padrão
        $default_hours = array(
            'monday' => array('open' => '08:00', 'close' => '18:00'),
            'tuesday' => array('open' => '08:00', 'close' => '18:00'),
            'wednesday' => array('open' => '08:00', 'close' => '18:00'),
            'thursday' => array('open' => '08:00', 'close' => '18:00'),
            'friday' => array('open' => '08:00', 'close' => '18:00'),
            'saturday' => array('open' => '09:00', 'close' => '17:00'),
            'sunday' => array('open' => 'closed', 'close' => 'closed')
        );

        if (!get_option('sistur_business_hours')) {
            add_option('sistur_business_hours', $default_hours);
        }

        // Criar departamentos padrão
        global $wpdb;
        $table_departments = $wpdb->prefix . 'sistur_departments';

        $default_departments = array(
            'Administração',
            'Operacional',
            'Atendimento',
            'Restaurante',
            'Manutenção'
        );

        foreach ($default_departments as $dept_name) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_departments WHERE name = %s",
                $dept_name
            ));

            if (!$exists) {
                $wpdb->insert(
                    $table_departments,
                    array(
                        'name' => $dept_name,
                        'description' => 'Departamento de ' . $dept_name,
                        'status' => 1
                    ),
                    array('%s', '%s', '%d')
                );
            }
        }
    }

    /**
     * Cria as permissões padrão do sistema
     */
    private static function create_default_permissions()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_permissions';

        $permissions = array(
            // Módulo: Funcionários
            array('module' => 'employees', 'action' => 'view', 'label' => 'Ver Funcionários', 'description' => 'Visualizar lista e detalhes de funcionários'),
            array('module' => 'employees', 'action' => 'create', 'label' => 'Criar Funcionários', 'description' => 'Adicionar novos funcionários ao sistema'),
            array('module' => 'employees', 'action' => 'edit', 'label' => 'Editar Funcionários', 'description' => 'Modificar informações de funcionários'),
            array('module' => 'employees', 'action' => 'delete', 'label' => 'Excluir Funcionários', 'description' => 'Remover funcionários do sistema'),
            array('module' => 'employees', 'action' => 'export', 'label' => 'Exportar Funcionários', 'description' => 'Exportar dados de funcionários'),
            array('module' => 'employees', 'action' => 'manage_departments', 'label' => 'Gerenciar Departamentos', 'description' => 'Criar, editar e excluir departamentos'),

            // Módulo: Ponto Eletrônico
            array('module' => 'time_tracking', 'action' => 'view_own', 'label' => 'Ver Próprio Ponto', 'description' => 'Visualizar os próprios registros de ponto'),
            array('module' => 'time_tracking', 'action' => 'view_all', 'label' => 'Ver Todos os Pontos', 'description' => 'Visualizar registros de ponto de todos os funcionários'),
            array('module' => 'time_tracking', 'action' => 'edit_own', 'label' => 'Editar Próprio Ponto', 'description' => 'Editar os próprios registros de ponto'),
            array('module' => 'time_tracking', 'action' => 'edit_all', 'label' => 'Editar Todos os Pontos', 'description' => 'Editar registros de ponto de qualquer funcionário'),
            array('module' => 'time_tracking', 'action' => 'approve', 'label' => 'Aprovar Registros', 'description' => 'Aprovar e validar registros de ponto'),
            array('module' => 'time_tracking', 'action' => 'export', 'label' => 'Exportar Relatórios', 'description' => 'Exportar relatórios de ponto'),

            // Módulo: Pagamentos
            array('module' => 'payments', 'action' => 'view_own', 'label' => 'Ver Próprios Pagamentos', 'description' => 'Visualizar os próprios pagamentos'),
            array('module' => 'payments', 'action' => 'view_all', 'label' => 'Ver Todos os Pagamentos', 'description' => 'Visualizar pagamentos de todos os funcionários'),
            array('module' => 'payments', 'action' => 'create', 'label' => 'Criar Pagamentos', 'description' => 'Registrar novos pagamentos'),
            array('module' => 'payments', 'action' => 'edit', 'label' => 'Editar Pagamentos', 'description' => 'Modificar registros de pagamentos'),
            array('module' => 'payments', 'action' => 'delete', 'label' => 'Excluir Pagamentos', 'description' => 'Remover registros de pagamentos'),
            array('module' => 'payments', 'action' => 'export', 'label' => 'Exportar Pagamentos', 'description' => 'Exportar dados de pagamentos'),

            // Módulo: Leads
            array('module' => 'leads', 'action' => 'view', 'label' => 'Ver Leads', 'description' => 'Visualizar leads do sistema'),
            array('module' => 'leads', 'action' => 'create', 'label' => 'Criar Leads', 'description' => 'Adicionar novos leads'),
            array('module' => 'leads', 'action' => 'edit', 'label' => 'Editar Leads', 'description' => 'Modificar informações de leads'),
            array('module' => 'leads', 'action' => 'delete', 'label' => 'Excluir Leads', 'description' => 'Remover leads do sistema'),
            array('module' => 'leads', 'action' => 'assign', 'label' => 'Atribuir Leads', 'description' => 'Atribuir leads para funcionários'),
            array('module' => 'leads', 'action' => 'export', 'label' => 'Exportar Leads', 'description' => 'Exportar dados de leads'),

            // Módulo: Inventário
            array('module' => 'inventory', 'action' => 'view', 'label' => 'Ver Inventário', 'description' => 'Visualizar produtos e estoque'),
            array('module' => 'inventory', 'action' => 'create', 'label' => 'Criar Produtos', 'description' => 'Adicionar novos produtos'),
            array('module' => 'inventory', 'action' => 'edit', 'label' => 'Editar Produtos', 'description' => 'Modificar informações de produtos'),
            array('module' => 'inventory', 'action' => 'delete', 'label' => 'Excluir Produtos', 'description' => 'Remover produtos do sistema'),
            array('module' => 'inventory', 'action' => 'movements', 'label' => 'Movimentar Estoque', 'description' => 'Registrar entradas e saídas de estoque'),
            array('module' => 'inventory', 'action' => 'export', 'label' => 'Exportar Inventário', 'description' => 'Exportar dados de inventário'),
            array('module' => 'inventory', 'action' => 'record_sale', 'label' => 'Registrar Venda', 'description' => 'Baixar estoque por venda'),
            array('module' => 'inventory', 'action' => 'request_loss', 'label' => 'Solicitar Baixa por Perda', 'description' => 'Criar solicitação de baixa por perda/quebra'),
            array('module' => 'inventory', 'action' => 'approve_loss', 'label' => 'Aprovar Baixa por Perda', 'description' => 'Aprovar ou rejeitar solicitações de baixa'),
            array('module' => 'inventory', 'action' => 'manage', 'label' => 'Gerenciar Estoque', 'description' => 'Acesso completo à gestão de estoque'),

            // Módulo: CMV (Custo de Mercadoria Vendida) - Portal do Colaborador
            array('module' => 'cmv', 'action' => 'manage_products', 'label' => 'Gerenciar Produtos CMV', 'description' => 'Criar, editar e excluir produtos no portal'),
            array('module' => 'cmv', 'action' => 'manage_recipes', 'label' => 'Gerenciar Fichas Técnicas', 'description' => 'Criar e editar receitas/composições de produtos'),
            array('module' => 'cmv', 'action' => 'produce', 'label' => 'Produzir Itens', 'description' => 'Executar produção de itens MANUFACTURED'),
            array('module' => 'cmv', 'action' => 'view_batches', 'label' => 'Visualizar Lotes', 'description' => 'Ver detalhes de lotes de produtos'),
            array('module' => 'cmv', 'action' => 'view_costs', 'label' => 'Visualizar Custos', 'description' => 'Ver custos e CMV de produtos'),
            array('module' => 'cmv', 'action' => 'manage_full', 'label' => 'Gestão Completa CMV', 'description' => 'Acesso completo a todas as funcionalidades de CMV no portal'),

            // Módulo: Relatórios

            array('module' => 'reports', 'action' => 'view_basic', 'label' => 'Ver Relatórios Básicos', 'description' => 'Acessar relatórios básicos'),
            array('module' => 'reports', 'action' => 'view_advanced', 'label' => 'Ver Relatórios Avançados', 'description' => 'Acessar relatórios avançados e análises'),
            array('module' => 'reports', 'action' => 'export', 'label' => 'Exportar Relatórios', 'description' => 'Exportar relatórios'),

            // Módulo: Configurações
            array('module' => 'settings', 'action' => 'view', 'label' => 'Ver Configurações', 'description' => 'Visualizar configurações do sistema'),
            array('module' => 'settings', 'action' => 'edit', 'label' => 'Editar Configurações', 'description' => 'Modificar configurações do sistema'),

            // Módulo: Banco de Horas
            array('module' => 'timebank', 'action' => 'manage', 'label' => 'Gerenciar Banco de Horas', 'description' => 'Abater horas, solicitar pagamentos e perdoar faltas no banco de horas'),

            // Módulo: Permissões
            array('module' => 'permissions', 'action' => 'manage', 'label' => 'Gerenciar Permissões', 'description' => 'Criar e editar papéis e permissões'),

            // Módulo: Dashboard (ERP Modular v2.0)
            array('module' => 'dashboard', 'action' => 'view', 'label' => 'Ver Dashboard Central', 'description' => 'Acessar o dashboard principal do portal'),

            // Módulo: Aprovações (ERP Modular v2.0)
            array('module' => 'approvals', 'action' => 'view_own', 'label' => 'Ver Próprias Solicitações', 'description' => 'Ver status das próprias solicitações'),
            array('module' => 'approvals', 'action' => 'view_pending', 'label' => 'Ver Solicitações Pendentes', 'description' => 'Ver solicitações aguardando aprovação'),
            array('module' => 'approvals', 'action' => 'approve', 'label' => 'Aprovar Solicitações', 'description' => 'Aprovar ou rejeitar solicitações'),

            // Módulo: Restaurante (ERP Modular v2.1)
            array('module' => 'restaurant', 'action' => 'view', 'label' => 'Ver Módulo Restaurante', 'description' => 'Acessar o módulo do restaurante'),
            array('module' => 'restaurant', 'action' => 'edit', 'label' => 'Editar Restaurante', 'description' => 'Modificar configurações do restaurante'),

            // Módulo: CMV - Custo de Mercadoria Vendida (ERP Modular v2.1)
            array('module' => 'cmv', 'action' => 'view', 'label' => 'Ver CMV', 'description' => 'Visualizar relatórios de CMV'),
            array('module' => 'cmv', 'action' => 'edit', 'label' => 'Editar CMV', 'description' => 'Registrar custos e lançamentos de CMV'),
        );

        foreach ($permissions as $perm) {
            // Verificar se já existe
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE module = %s AND action = %s",
                $perm['module'],
                $perm['action']
            ));

            if (!$exists) {
                $wpdb->insert($table, $perm, array('%s', '%s', '%s', '%s'));
            }
        }
    }

    /**
     * Cria os papéis/roles padrão do sistema
     */
    private static function create_default_roles()
    {
        global $wpdb;
        $roles_table = $wpdb->prefix . 'sistur_roles';
        $permissions_table = $wpdb->prefix . 'sistur_permissions';
        $role_permissions_table = $wpdb->prefix . 'sistur_role_permissions';

        $roles = array(
            array(
                'name' => 'Administrador',
                'description' => 'Acesso completo ao sistema com todas as permissões',
                'is_admin' => 1,
                'permissions' => array() // Admin tem todas automaticamente
            ),
            array(
                'name' => 'Gerente de RH',
                'description' => 'Gerencia funcionários, departamentos, pagamentos e ponto eletrônico',
                'is_admin' => 0,
                'permissions' => array(
                    'dashboard.view',
                    'employees.view',
                    'employees.create',
                    'employees.edit',
                    'employees.delete',
                    'employees.export',
                    'employees.manage_departments',
                    'time_tracking.view_all',
                    'time_tracking.edit_all',
                    'time_tracking.approve',
                    'time_tracking.export',
                    'payments.view_all',
                    'payments.create',
                    'payments.edit',
                    'payments.export',
                    'reports.view_advanced',
                    'reports.export',
                    'timebank.manage',
                    'permissions.manage',
                    'approvals.view_pending',
                    'approvals.approve'
                )
            ),
            array(
                'name' => 'Supervisor',
                'description' => 'Supervisiona equipe, aprova pontos e visualiza relatórios',
                'is_admin' => 0,
                'permissions' => array(
                    'employees.view',
                    'time_tracking.view_all',
                    'time_tracking.approve',
                    'time_tracking.export',
                    'leads.view',
                    'leads.assign',
                    'reports.view_basic',
                    'reports.export'
                )
            ),
            array(
                'name' => 'Gerente de Vendas',
                'description' => 'Gerencia leads e equipe de vendas',
                'is_admin' => 0,
                'permissions' => array(
                    'employees.view',
                    'time_tracking.view_all',
                    'leads.view',
                    'leads.create',
                    'leads.edit',
                    'leads.delete',
                    'leads.assign',
                    'leads.export',
                    'reports.view_basic',
                    'reports.export'
                )
            ),
            array(
                'name' => 'Vendedor',
                'description' => 'Gerencia leads e realiza vendas',
                'is_admin' => 0,
                'permissions' => array(
                    'time_tracking.view_own',
                    'time_tracking.edit_own',
                    'payments.view_own',
                    'leads.view',
                    'leads.create',
                    'leads.edit',
                    'reports.view_basic'
                )
            ),
            array(
                'name' => 'Estoquista',
                'description' => 'Gerencia inventário e movimentações de estoque',
                'is_admin' => 0,
                'permissions' => array(
                    'time_tracking.view_own',
                    'time_tracking.edit_own',
                    'payments.view_own',
                    'inventory.view',
                    'inventory.create',
                    'inventory.edit',
                    'inventory.movements',
                    'inventory.export',
                    'reports.view_basic'
                )
            ),
            array(
                'name' => 'Funcionário',
                'description' => 'Acesso básico ao sistema - visualiza apenas informações próprias',
                'is_admin' => 0,
                'permissions' => array(
                    'time_tracking.view_own',
                    'time_tracking.edit_own',
                    'payments.view_own'
                )
            ),
            array(
                'name' => 'Gestor de Banco de Horas',
                'description' => 'Responsável por abatimentos, pagamentos e perdão de faltas no banco de horas',
                'is_admin' => 0,
                'permissions' => array(
                    'timebank.manage',
                    'time_tracking.view_all',
                    'time_tracking.approve',
                    'payments.view_all'
                )
            ),
            array(
                'name' => 'Restaurante',
                'description' => 'Acesso ao módulo do restaurante, CMV e estoque de alimentos',
                'is_admin' => 0,
                'permissions' => array(
                    'time_tracking.view_own',
                    'time_tracking.edit_own',
                    'payments.view_own',
                    'restaurant.view',
                    'restaurant.edit',
                    'cmv.view',
                    'cmv.edit',
                    'inventory.view',
                    'inventory.movements',
                    'inventory.record_sale'
                )
            )
        );

        foreach ($roles as $role_data) {
            // Verificar se já existe
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $roles_table WHERE name = %s",
                $role_data['name']
            ));

            if (!$exists) {
                // Criar role
                $wpdb->insert(
                    $roles_table,
                    array(
                        'name' => $role_data['name'],
                        'description' => $role_data['description'],
                        'is_admin' => $role_data['is_admin']
                    ),
                    array('%s', '%s', '%d')
                );

                $role_id = $wpdb->insert_id;

                // Atribuir permissões (se não for admin)
                if (!$role_data['is_admin'] && !empty($role_data['permissions'])) {
                    foreach ($role_data['permissions'] as $perm_key) {
                        list($module, $action) = explode('.', $perm_key);

                        // Buscar ID da permissão
                        $perm_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM $permissions_table WHERE module = %s AND action = %s",
                            $module,
                            $action
                        ));

                        if ($perm_id) {
                            $wpdb->insert(
                                $role_permissions_table,
                                array(
                                    'role_id' => $role_id,
                                    'permission_id' => $perm_id
                                ),
                                array('%d', '%d')
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Cria os tipos de contrato padrão
     */
    private static function create_default_contract_types()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_contract_types';

        $contract_types = array(
            array(
                'descricao' => 'Mensalista 8h/dia (44h semanais)',
                'carga_horaria_diaria_minutos' => 480, // 8 horas
                'carga_horaria_semanal_minutos' => 2640, // 44 horas
                'intervalo_minimo_almoco_minutos' => 60 // 1 hora
            ),
            array(
                'descricao' => 'Mensalista 6h/dia (36h semanais)',
                'carga_horaria_diaria_minutos' => 360, // 6 horas
                'carga_horaria_semanal_minutos' => 2160, // 36 horas
                'intervalo_minimo_almoco_minutos' => 15 // 15 minutos
            ),
            array(
                'descricao' => 'Mensalista 4h/dia (24h semanais)',
                'carga_horaria_diaria_minutos' => 240, // 4 horas
                'carga_horaria_semanal_minutos' => 1440, // 24 horas
                'intervalo_minimo_almoco_minutos' => 0 // Sem intervalo obrigatório
            )
        );

        foreach ($contract_types as $contract) {
            // Verificar se já existe
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE descricao = %s",
                $contract['descricao']
            ));

            if (!$exists) {
                $wpdb->insert($table, $contract, array('%s', '%d', '%d', '%d'));
            }
        }
    }

    /**
     * Cria os padrões de escala padrão
     */
    private static function create_default_shift_patterns()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_shift_patterns';

        // NÃO recriar padrões se já existem (evita duplicatas em reativações)
        $existing_count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($existing_count > 0) {
            error_log("SISTUR: Padrões de escala já existem ($existing_count). Pulando criação automática.");
            return;
        }

        $shift_patterns = array(
            array(
                'name' => 'Escala 6x1 (8h/dia)',
                'description' => 'Trabalha 6 dias e folga 1 dia (8 horas por dia)',
                'pattern_type' => 'fixed_days',
                'work_days_count' => 6,
                'rest_days_count' => 1,
                'weekly_hours_minutes' => 2880, // 48 horas
                'daily_hours_minutes' => 480, // 8 horas
                'lunch_break_minutes' => 60,
                'pattern_config' => json_encode(array(
                    'type' => '6x1',
                    'work_days' => 6,
                    'rest_days' => 1
                )),
                'status' => 1
            ),
            array(
                'name' => 'Escala 5x2 (8h/dia)',
                'description' => 'Trabalha 5 dias e folga 2 dias (8 horas por dia)',
                'pattern_type' => 'fixed_days',
                'work_days_count' => 5,
                'rest_days_count' => 2,
                'weekly_hours_minutes' => 2400, // 40 horas
                'daily_hours_minutes' => 480, // 8 horas
                'lunch_break_minutes' => 60,
                'pattern_config' => json_encode(array(
                    'type' => '5x2',
                    'work_days' => 5,
                    'rest_days' => 2
                )),
                'status' => 1
            ),
            array(
                'name' => 'Escala 5x2 (6h/dia)',
                'description' => 'Trabalha 5 dias e folga 2 dias (6 horas por dia)',
                'pattern_type' => 'fixed_days',
                'work_days_count' => 5,
                'rest_days_count' => 2,
                'weekly_hours_minutes' => 1800, // 30 horas
                'daily_hours_minutes' => 360, // 6 horas
                'lunch_break_minutes' => 15,
                'pattern_config' => json_encode(array(
                    'type' => '5x2',
                    'work_days' => 5,
                    'rest_days' => 2
                )),
                'status' => 1
            ),
            array(
                'name' => 'Escala 12x36 (12h/dia)',
                'description' => 'Trabalha 12 horas e folga 36 horas',
                'pattern_type' => 'fixed_days',
                'work_days_count' => 1,
                'rest_days_count' => 1,
                'weekly_hours_minutes' => 2520, // ~42 horas (média)
                'daily_hours_minutes' => 720, // 12 horas
                'lunch_break_minutes' => 60,
                'pattern_config' => json_encode(array(
                    'type' => '12x36',
                    'work_hours' => 12,
                    'rest_hours' => 36
                )),
                'status' => 1
            ),
            array(
                'name' => 'Horas Flexíveis (44h semanais)',
                'description' => 'Cumpre 44 horas semanais de forma flexível',
                'pattern_type' => 'flexible_hours',
                'work_days_count' => 0,
                'rest_days_count' => 0,
                'weekly_hours_minutes' => 2640, // 44 horas
                'daily_hours_minutes' => 0, // Variável
                'lunch_break_minutes' => 60,
                'pattern_config' => json_encode(array(
                    'type' => 'flexible',
                    'weekly_hours_required' => 2640,
                    'allow_flexible_days' => true
                )),
                'status' => 1
            ),
            array(
                'name' => 'Horas Flexíveis (36h semanais)',
                'description' => 'Cumpre 36 horas semanais de forma flexível',
                'pattern_type' => 'flexible_hours',
                'work_days_count' => 0,
                'rest_days_count' => 0,
                'weekly_hours_minutes' => 2160, // 36 horas
                'daily_hours_minutes' => 0, // Variável
                'lunch_break_minutes' => 15,
                'pattern_config' => json_encode(array(
                    'type' => 'flexible',
                    'weekly_hours_required' => 2160,
                    'allow_flexible_days' => true
                )),
                'status' => 1
            )
        );

        foreach ($shift_patterns as $pattern) {
            // Verificar se já existe
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE name = %s",
                $pattern['name']
            ));

            if (!$exists) {
                $wpdb->insert($table, $pattern, array('%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d'));
            }
        }
    }

    /**
     * Cria as configurações padrão do sistema
     */
    private static function create_default_settings()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_settings';

        $settings = array(
            // Tolerância para atraso (minutos de débito perdoados)
            array(
                'setting_key' => 'tolerance_minutes_delay',
                'setting_value' => '0',
                'setting_type' => 'integer',
                'description' => 'Tolerância em minutos para atraso (débitos menores que esse valor são perdoados)'
            ),
            array(
                'setting_key' => 'cron_secret_key',
                'setting_value' => wp_generate_password(32, false),
                'setting_type' => 'string',
                'description' => 'Chave secreta para autenticação do endpoint de cron'
            ),
            array(
                'setting_key' => 'auto_processing_enabled',
                'setting_value' => '1',
                'setting_type' => 'boolean',
                'description' => 'Habilita o processamento automático de ponto (job noturno)'
            ),
            array(
                'setting_key' => 'processing_batch_size',
                'setting_value' => '50',
                'setting_type' => 'integer',
                'description' => 'Quantidade de funcionários processados por lote no job'
            ),
            array(
                'setting_key' => 'processing_time',
                'setting_value' => '01:00',
                'setting_type' => 'string',
                'description' => 'Horário de execução do processamento automático (formato HH:MM)'
            ),
            // Configurações da Empresa (para Folha de Ponto)
            array(
                'setting_key' => 'company_name',
                'setting_value' => '',
                'setting_type' => 'string',
                'description' => 'Razão Social da Empresa'
            ),
            array(
                'setting_key' => 'company_cnpj',
                'setting_value' => '',
                'setting_type' => 'string',
                'description' => 'CNPJ da Empresa'
            ),
            array(
                'setting_key' => 'company_address',
                'setting_value' => '',
                'setting_type' => 'string',
                'description' => 'Endereço Completo da Empresa'
            ),
            array(
                'setting_key' => 'company_default_department',
                'setting_value' => '',
                'setting_type' => 'string',
                'description' => 'Departamento Padrão da Empresa'
            )
        );

        foreach ($settings as $setting) {
            // Verificar se já existe
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE setting_key = %s",
                $setting['setting_key']
            ));

            if (!$exists) {
                $wpdb->insert($table, $setting, array('%s', '%s', '%s', '%s'));
            }
        }
    }

    /**
     * Gera tokens UUID e QR codes para funcionários que não possuem
     * Executado durante a ativação/reativação do plugin
     */
    private static function generate_missing_tokens_and_qrcodes()
    {
        // Verificar se a classe SISTUR_QRCode está disponível
        if (!class_exists('SISTUR_QRCode')) {
            error_log('SISTUR: Classe SISTUR_QRCode não encontrada para geração automática de tokens');
            return;
        }

        $qrcode = SISTUR_QRCode::get_instance();

        // Gerar tokens para todos os funcionários que não possuem
        $result = $qrcode->generate_tokens_for_all_employees();

        if ($result['total'] > 0) {
            error_log(sprintf(
                'SISTUR: Geração automática de tokens na ativação - Total: %d, Gerados: %d, Falhas: %d',
                $result['total'],
                $result['generated'],
                $result['failed']
            ));

            // Se tokens foram gerados, gerar QR codes correspondentes
            if ($result['generated'] > 0) {
                global $wpdb;
                $table = $wpdb->prefix . 'sistur_employees';

                // Buscar funcionários que agora têm token mas podem não ter QR code gerado
                $employees_with_tokens = $wpdb->get_results(
                    "SELECT id FROM $table WHERE token_qr IS NOT NULL AND status = 1",
                    ARRAY_A
                );

                $qrcodes_generated = 0;
                foreach ($employees_with_tokens as $employee) {
                    $qr_url = $qrcode->generate_qrcode($employee['id']);
                    if ($qr_url) {
                        $qrcodes_generated++;
                    }
                }

                error_log("SISTUR: {$qrcodes_generated} QR codes gerados automaticamente na ativação");
            }
        } else {
            error_log('SISTUR: Nenhum funcionário sem token encontrado na ativação');
        }
    }

    /**
     * Sincroniza funcionários existentes com usuários WordPress
     * Vincula funcionários que têm o mesmo email de usuários WordPress
     */
    private static function sync_existing_employees_with_wordpress_users()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employees';

        // Buscar todos os funcionários que não têm user_id vinculado
        $employees = $wpdb->get_results(
            "SELECT id, email FROM $table WHERE user_id IS NULL AND email IS NOT NULL AND email != ''",
            ARRAY_A
        );

        if (empty($employees)) {
            error_log('SISTUR: Nenhum funcionário sem user_id encontrado para sincronização');
            return;
        }

        $synced = 0;
        foreach ($employees as $employee) {
            // Buscar usuário WordPress com o mesmo email
            $user = get_user_by('email', $employee['email']);

            if ($user) {
                // Atualizar funcionário com o user_id
                $wpdb->update(
                    $table,
                    array('user_id' => $user->ID),
                    array('id' => $employee['id']),
                    array('%d'),
                    array('%d')
                );

                $synced++;
                error_log("SISTUR: Funcionário ID {$employee['id']} vinculado ao usuário WordPress ID {$user->ID}");
            }
        }

        error_log("SISTUR: Sincronização completa - {$synced} funcionários vinculados a usuários WordPress");
    }

    /**
     * Executa migrações para novas colunas do ERP Modular v2.0
     */
    private static function run_erp_modular_migrations()
    {
        global $wpdb;

        // Migração: Adicionar colunas de hierarquia na tabela roles
        $table_roles = $wpdb->prefix . 'sistur_roles';
        $approval_level_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_roles LIKE 'approval_level'");
        if (empty($approval_level_exists)) {
            $wpdb->query("ALTER TABLE $table_roles
                ADD COLUMN approval_level int DEFAULT 0 AFTER is_admin,
                ADD COLUMN can_approve_for_roles text DEFAULT NULL AFTER approval_level,
                ADD KEY approval_level (approval_level)");
            error_log("SISTUR ERP: Colunas de hierarquia adicionadas à tabela roles");
        }

        // Migração: Adicionar colunas ao inventory_movements
        $table_movements = $wpdb->prefix . 'sistur_inventory_movements';
        $movement_reason_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_movements LIKE 'movement_reason'");
        if (empty($movement_reason_exists)) {
            $wpdb->query("ALTER TABLE $table_movements
                ADD COLUMN movement_reason enum('sale','loss','damage','theft','adjustment','donation','sample','purchase','return') DEFAULT 'sale' AFTER type,
                ADD COLUMN requires_approval tinyint(1) DEFAULT 0 AFTER notes,
                ADD COLUMN approval_request_id mediumint(9) DEFAULT NULL AFTER requires_approval,
                ADD KEY movement_reason (movement_reason),
                ADD KEY approval_request_id (approval_request_id)");
            error_log("SISTUR ERP: Colunas de motivo e aprovação adicionadas à tabela inventory_movements");
        }

        // Migração: Adicionar employee_id ao inventory_movements para rastrear quem registrou
        $employee_id_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_movements LIKE 'employee_id'");
        if (empty($employee_id_exists)) {
            $wpdb->query("ALTER TABLE $table_movements
                ADD COLUMN employee_id mediumint(9) DEFAULT NULL AFTER product_id,
                ADD KEY employee_id (employee_id)");
            error_log("SISTUR: Coluna employee_id adicionada à tabela inventory_movements");
        }

        // Migração: Atualizar níveis de aprovação nos roles existentes
        self::update_roles_approval_levels();

        error_log("SISTUR ERP: Migrações v2.0 executadas com sucesso");
    }

    /**
     * Atualiza os níveis de aprovação nos roles existentes
     */
    private static function update_roles_approval_levels()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_roles';

        // Definir níveis de aprovação para roles padrão
        $approval_levels = array(
            'Administrador' => 99,
            'Gerente de RH' => 3,
            'Supervisor' => 2,
            'Gerente de Vendas' => 3,
            'Gestor de Banco de Horas' => 2,
            'Estoquista' => 1,
            'Vendedor' => 1,
            'Funcionário' => 0
        );

        foreach ($approval_levels as $role_name => $level) {
            $wpdb->update(
                $table,
                array('approval_level' => $level),
                array('name' => $role_name),
                array('%d'),
                array('%s')
            );
        }

        // Atualizar can_approve_for_roles para gerentes
        $gerente_rh_id = $wpdb->get_var("SELECT id FROM $table WHERE name = 'Gerente de RH'");
        $supervisor_id = $wpdb->get_var("SELECT id FROM $table WHERE name = 'Supervisor'");
        $estoquista_id = $wpdb->get_var("SELECT id FROM $table WHERE name = 'Estoquista'");
        $funcionario_id = $wpdb->get_var("SELECT id FROM $table WHERE name = 'Funcionário'");

        // Gerente de RH pode aprovar para Supervisor, Estoquista e Funcionário
        if ($gerente_rh_id) {
            $can_approve = json_encode(array_filter(array($supervisor_id, $estoquista_id, $funcionario_id)));
            $wpdb->update($table, array('can_approve_for_roles' => $can_approve), array('id' => $gerente_rh_id));
        }

        // Supervisor pode aprovar para Estoquista e Funcionário
        if ($supervisor_id) {
            $can_approve = json_encode(array_filter(array($estoquista_id, $funcionario_id)));
            $wpdb->update($table, array('can_approve_for_roles' => $can_approve), array('id' => $supervisor_id));
        }
    }

    /**
     * Migration: Garante que roles existentes tenham todas as permissões corretas.
     *
     * Resolve o bug onde create_default_roles() pulava roles já existentes (if !$exists),
     * deixando permissões novas (dashboard.view, timebank.manage, etc.) fora do banco.
     * Usa INSERT IGNORE para não duplicar permissões já atribuídas.
     * Executado em toda ativação/reativação do plugin.
     */
    private static function update_rh_role_permissions()
    {
        global $wpdb;
        $roles_table = $wpdb->prefix . 'sistur_roles';
        $permissions_table = $wpdb->prefix . 'sistur_permissions';
        $rp_table = $wpdb->prefix . 'sistur_role_permissions';

        // Mapa de role => permissões que DEVEM existir
        $role_permissions_map = array(
            'Gerente de RH' => array(
                'dashboard.view',
                'employees.view',
                'employees.create',
                'employees.edit',
                'employees.delete',
                'employees.export',
                'employees.manage_departments',
                'time_tracking.view_all',
                'time_tracking.edit_all',
                'time_tracking.approve',
                'time_tracking.export',
                'payments.view_all',
                'payments.create',
                'payments.edit',
                'payments.export',
                'reports.view_advanced',
                'reports.export',
                'timebank.manage',
                'permissions.manage',
                'approvals.view_pending',
                'approvals.approve',
            ),
            'Supervisor' => array(
                'dashboard.view',
                'employees.view',
                'time_tracking.view_all',
                'time_tracking.approve',
                'time_tracking.export',
                'reports.view_basic',
                'approvals.view_pending',
                'approvals.approve',
            ),
            'Gestor de Banco de Horas' => array(
                'dashboard.view',
                'timebank.manage',
                'time_tracking.view_all',
                'time_tracking.approve',
                'approvals.view_pending',
                'approvals.approve',
            ),
        );

        foreach ($role_permissions_map as $role_name => $permissions) {
            // Buscar ID do role pelo nome
            $role_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $roles_table WHERE name = %s",
                $role_name
            ));

            if (!$role_id) {
                continue; // Role não existe ainda, create_default_roles cuidará disso
            }

            foreach ($permissions as $perm_key) {
                list($module, $action) = explode('.', $perm_key);

                // Buscar ID da permissão
                $perm_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $permissions_table WHERE module = %s AND action = %s",
                    $module,
                    $action
                ));

                if (!$perm_id) {
                    continue; // Permissão não existe na tabela de permissões
                }

                // INSERT IGNORE: adiciona apenas se ainda não existir
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO $rp_table (role_id, permission_id) VALUES (%d, %d)",
                    $role_id,
                    $perm_id
                ));
            }
        }
    }

    /**
     * Cria os módulos padrão do Portal do Colaborador
     */
    private static function create_default_portal_modules()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_module_config';

        $modules = array(
            array(
                'module_key' => 'ponto',
                'module_name' => 'Ponto Eletrônico',
                'module_icon' => 'dashicons-clock',
                'module_color' => '#10b981',
                'module_description' => 'Registre sua entrada, saída e intervalos',
                'required_permission' => 'time_tracking.view_own',
                'target_url' => '#tab-ponto',
                'target_type' => 'tab',
                'sort_order' => 1,
                'is_active' => 1
            ),
            array(
                'module_key' => 'banco_horas',
                'module_name' => 'Banco de Horas',
                'module_icon' => 'dashicons-chart-bar',
                'module_color' => '#0d9488',
                'module_description' => 'Acompanhe seu saldo de horas',
                'required_permission' => 'time_tracking.view_own',
                'target_url' => '/banco-de-horas/',
                'target_type' => 'page',
                'sort_order' => 2,
                'is_active' => 1
            ),
            array(
                'module_key' => 'estoque',
                'module_name' => 'Estoque / CMV',
                'module_icon' => 'dashicons-archive',
                'module_color' => '#f59e0b',
                'module_description' => 'Gerencie produtos e movimentações',
                'required_permission' => 'inventory.view',
                'target_url' => '#tab-estoque',
                'target_type' => 'tab',
                'sort_order' => 3,
                'is_active' => 1
            ),
            array(
                'module_key' => 'aprovacoes',
                'module_name' => 'Aprovações',
                'module_icon' => 'dashicons-yes-alt',
                'module_color' => '#8b5cf6',
                'module_description' => 'Aprove ou acompanhe solicitações',
                'required_permission' => 'approvals.view_own',
                'target_url' => '#tab-aprovacoes',
                'target_type' => 'tab',
                'sort_order' => 4,
                'is_active' => 1
            ),
            array(
                'module_key' => 'perfil',
                'module_name' => 'Meu Perfil',
                'module_icon' => 'dashicons-admin-users',
                'module_color' => '#6366f1',
                'module_description' => 'Veja suas informações pessoais',
                'required_permission' => 'dashboard.view',
                'target_url' => '#tab-perfil',
                'target_type' => 'tab',
                'sort_order' => 10,
                'is_active' => 1
            )
        );

        foreach ($modules as $module) {
            // Verificar se já existe
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE module_key = %s",
                $module['module_key']
            ));

            if (!$exists) {
                $wpdb->insert($table, $module, array(
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%d'
                ));
            }
        }

        error_log("SISTUR ERP: Módulos padrão do portal criados");
    }
}
