<?php
/**
 * Classe de instalação do módulo de Estoque e CMV
 * 
 * Cria e atualiza as tabelas necessárias para o sistema de gestão de estoque
 * com controle por lotes, conversão de unidades e custo médio ponderado.
 *
 * @package SISTUR
 * @subpackage Stock
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SISTUR_Stock_Installer
{

    /**
     * Versão atual do schema de estoque
     */
    const SCHEMA_VERSION = '2.18.0';

    /**
     * Prefixo das tabelas
     * @var string
     */
    private static $prefix;

    /**
     * Charset do banco
     * @var string
     */
    private static $charset_collate;

    /**
     * Instala ou atualiza todas as tabelas do módulo de estoque
     * 
     * Este método é idempotente - pode ser chamado múltiplas vezes
     * sem efeitos colaterais indesejados.
     */
    public static function install()
    {
        global $wpdb;

        self::$prefix = $wpdb->prefix . 'sistur_';
        self::$charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Criar tabelas na ordem correta (dependências primeiro)
        self::create_units_table();
        self::migrate_products_table();
        self::create_unit_conversions_table();
        self::create_storage_locations_table();
        self::create_inventory_batches_table();
        self::create_inventory_transactions_table();
        self::create_product_supplier_links_table();
        self::create_recipes_table();  // Ficha Técnica
        self::create_inventory_losses_table();  // v2.7.0 - Rastreamento de Perdas CMV
        self::create_blind_inventory_tables();  // v2.8.0 - Inventário Cego
        self::create_macro_revenue_table();     // v2.16.0 - Faturamento Macro (Fechar Caixa)

        // Executar migrações para rastreabilidade (v2.9.0)
        // Executar migrações para rastreabilidade (v2.9.0)
        self::migrate_add_employee_tracking();

        // Executar migração para dados de aquisição (v2.9.1)
        self::migrate_add_acquisition_info();

        // Executar migração para escopo do inventário cego (v2.10.0)
        self::migrate_blind_inventory_scope();

        // Executar migração para setor padrão do produto (v2.11.0)
        self::migrate_add_default_sector();

        // Executar migração para campos extras do inventário cego (v2.12.0)
        self::migrate_blind_inventory_extras();

        // Executar migração para sector_id nos lotes (v2.14.0)
        self::migrate_add_sector_to_batches();

        // Executar migração para modo wizard no inventário cego (v2.15.0)
        self::migrate_blind_inventory_wizard();

        // Corrigir lotes salvos com sector em location_id (v2.15.1)
        self::migrate_fix_batch_sector_ids();

        // Permitir mesmo produto em setores diferentes no inventário (v2.15.3)
        self::migrate_blind_inventory_unique_per_sector();

        // Criar tabela de fornecedores e migrar acquisition_location (v2.17.0)
        self::create_suppliers_table();
        self::migrate_add_supplier_id();
        self::migrate_acquisition_location_to_suppliers();

        // Adicionar tipo BASE e coluna production_yield (v2.18.0)
        self::migrate_add_base_type();

        // Inserir dados padrão
        self::seed_default_units();
        self::seed_default_locations();

        // Atualizar versão do schema
        update_option('sistur_stock_schema_version', self::SCHEMA_VERSION);

        error_log('SISTUR Stock: Schema instalado/atualizado para versão ' . self::SCHEMA_VERSION);
    }

    /**
     * Tabela: sistur_units (Unidades de Medida)
     * 
     * Armazena as unidades disponíveis para uso no sistema.
     * Tipos:
     * - 'dimensional': Unidades que podem ser convertidas (L, kg, m)
     * - 'unitary': Unidades indivisíveis (un, cx, pc)
     */
    private static function create_units_table()
    {
        $table = self::$prefix . 'units';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            symbol varchar(10) NOT NULL,
            type enum('dimensional','unitary') DEFAULT 'unitary',
            is_system tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY symbol (symbol),
            KEY type (type)
        ) " . self::$charset_collate . ";";

        dbDelta($sql);
    }

    /**
     * Migração: sistur_products (Schema ERP)
     * 
     * Adiciona novas colunas à tabela existente de produtos para suporte
     * ao sistema de CMV e controle por unidades.
     * 
     * Novas colunas:
     * - type: Tipo do produto (RAW=Insumo, RESALE=Revenda, MANUFACTURED=Produzido)
     * - base_unit_id: Unidade base do estoque (FK → sistur_units)
     * - average_cost: Custo médio ponderado calculado
     * - cached_stock: Saldo cacheado (soma de todos os lotes)
     */
    private static function migrate_products_table()
    {
        global $wpdb;

        $table = self::$prefix . 'products';

        // Verificar se a coluna 'type' já existe
        $type_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'type'");
        if (empty($type_exists)) {
            $wpdb->query("ALTER TABLE {$table} 
                ADD COLUMN type enum('RAW','RESALE','MANUFACTURED') DEFAULT 'RESALE' AFTER barcode");
            error_log("SISTUR Stock: Coluna 'type' adicionada à tabela products");
        }

        // Verificar se a coluna 'base_unit_id' já existe
        $unit_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'base_unit_id'");
        if (empty($unit_exists)) {
            $wpdb->query("ALTER TABLE {$table} 
                ADD COLUMN base_unit_id int(10) UNSIGNED DEFAULT NULL AFTER type,
                ADD KEY base_unit_id (base_unit_id)");
            error_log("SISTUR Stock: Coluna 'base_unit_id' adicionada à tabela products");
        }

        // Verificar se a coluna 'average_cost' já existe
        $avg_cost_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'average_cost'");
        if (empty($avg_cost_exists)) {
            $wpdb->query("ALTER TABLE {$table} 
                ADD COLUMN average_cost decimal(10,4) DEFAULT 0.0000 AFTER cost_price");
            error_log("SISTUR Stock: Coluna 'average_cost' adicionada à tabela products");
        }

        // Verificar se a coluna 'cached_stock' já existe
        $cached_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'cached_stock'");
        if (empty($cached_exists)) {
            $wpdb->query("ALTER TABLE {$table} 
                ADD COLUMN cached_stock decimal(10,3) DEFAULT 0.000 AFTER current_stock");
            error_log("SISTUR Stock: Coluna 'cached_stock' adicionada à tabela products");
        }

        // Modificar min_stock para decimal (permitir 0.050 kg por exemplo)
        $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN min_stock decimal(10,3) DEFAULT 0.000");

        // Migração v2.3: Campos de conteúdo da embalagem (volume/peso por unidade física)
        // Permite manter estoque em unidades físicas mas calcular volume total para relatórios
        $content_qty_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'content_quantity'");
        if (empty($content_qty_exists)) {
            $wpdb->query("ALTER TABLE {$table} 
                ADD COLUMN content_quantity decimal(10,3) DEFAULT NULL COMMENT 'Quantidade de conteúdo por embalagem (ex: 0.75 para garrafa 750ml)' AFTER cached_stock,
                ADD COLUMN content_unit_id int(10) UNSIGNED DEFAULT NULL COMMENT 'Unidade do conteúdo (FK sistur_units - ex: Litros, Gramas)' AFTER content_quantity,
                ADD KEY content_unit_id (content_unit_id)");
            error_log("SISTUR Stock: Colunas 'content_quantity' e 'content_unit_id' adicionadas à tabela products");
        }

        // Migração v2.4: Hierarquia de Insumos Genéricos (RESOURCE)
        // Permite vincular produtos específicos (Arroz Tio João) a um genérico (Arroz)
        $resource_parent_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'resource_parent_id'");
        if (empty($resource_parent_exists)) {
            $wpdb->query("ALTER TABLE {$table} 
                ADD COLUMN resource_parent_id MEDIUMINT(9) UNSIGNED DEFAULT NULL 
                COMMENT 'FK para produto genérico (RESOURCE) pai' AFTER content_unit_id,
                ADD KEY resource_parent_id (resource_parent_id)");
            error_log("SISTUR Stock: Coluna 'resource_parent_id' adicionada à tabela products");
        }

        // Modificar ENUM de type para incluir RESOURCE (v2.4 - legado, será substituído por SET)
        // RESOURCE = produto conceitual que agrupa marcas específicas, não tem estoque próprio
        // Primeiro garante que o ENUM existe com todos os valores
        $current_type = $wpdb->get_var("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'type'");

        // Migração v2.6: Converter ENUM para SET (permite múltiplos tipos)
        // Produtos podem ser RAW+MANUFACTURED (ex: Vinagrete - preparado E ingrediente)
        if (strpos($current_type, 'set') === false) {
            // Ainda é ENUM, converter para SET
            $wpdb->query("ALTER TABLE {$table} 
                MODIFY COLUMN type SET('RAW','RESALE','MANUFACTURED','RESOURCE') DEFAULT 'RAW'");
            error_log("SISTUR Stock: Coluna 'type' migrada de ENUM para SET (suporte multi-tipo v2.6)");
        }
        error_log("SISTUR Stock: ENUM 'type' atualizado para incluir RESOURCE");

        // Migração v2.13.0: Perishability (Perecível ou Não)
        // Definido na GENERIC (RESOURCE) e herdado pelos filhos.
        // Default 1 (Perecível) para manter compatibilidade.
        $perishable_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'is_perishable'");
        if (empty($perishable_exists)) {
            $wpdb->query("ALTER TABLE {$table} 
                ADD COLUMN is_perishable TINYINT(1) DEFAULT 1 COMMENT '1=Perecível, 0=Não Perecível' AFTER type");
            error_log("SISTUR Stock: Coluna 'is_perishable' adicionada à tabela products");
        }
    }

    /**
     * Tabela: sistur_unit_conversions (Tradutor de Unidades)
     * 
     * Permite definir fatores de conversão entre unidades.
     * Se product_id é NULL, a conversão é global (válida para todos os produtos).
     * 
     * Exemplo: Garrafa → Litro com fator 0.75
     *          (1 garrafa = 0.75 litros)
     */
    private static function create_unit_conversions_table()
    {
        $table = self::$prefix . 'unit_conversions';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id int(10) UNSIGNED DEFAULT NULL,
            from_unit_id int(10) UNSIGNED NOT NULL,
            to_unit_id int(10) UNSIGNED NOT NULL,
            factor decimal(10,4) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_conversion (product_id, from_unit_id, to_unit_id),
            KEY product_id (product_id),
            KEY from_unit_id (from_unit_id),
            KEY to_unit_id (to_unit_id)
        ) " . self::$charset_collate . ";";

        dbDelta($sql);
    }

    /**
     * Tabela: sistur_storage_locations (Mapa Físico de Armazenamento)
     * 
     * Define os locais físicos onde os produtos são armazenados.
     * Exemplos: Freezer 1, Estoque Seco, Prateleira A, Câmara Fria
     */
    private static function create_storage_locations_table()
    {
        $table = self::$prefix . 'storage_locations';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            code varchar(50) DEFAULT NULL,
            description text DEFAULT NULL,
            location_type enum('warehouse','freezer','refrigerator','shelf','other') DEFAULT 'warehouse',
            parent_id int(10) UNSIGNED DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY parent_id (parent_id),
            KEY location_type (location_type),
            KEY is_active (is_active)
        ) " . self::$charset_collate . ";";

        dbDelta($sql);
    }

    /**
     * Tabela: sistur_inventory_batches (Lotes - Onde o Item Mora)
     * 
     * Esta é a tabela central do controle de estoque por lotes.
     * O saldo real do sistema está aqui, não na tabela de produtos.
     * 
     * Cada lote representa uma entrada física com:
     * - Código do lote do fabricante
     * - Data de validade
     * - Custo de aquisição específico
     * - Quantidade atual
     */
    private static function create_inventory_batches_table()
    {
        $table = self::$prefix . 'inventory_batches';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id int(10) UNSIGNED NOT NULL,
            location_id int(10) UNSIGNED DEFAULT NULL,
            batch_code varchar(100) DEFAULT NULL,
            acquisition_location varchar(255) DEFAULT NULL COMMENT 'Local de aquisição (Mercado, Fornecedor)',
            entry_date datetime DEFAULT NULL COMMENT 'Data e hora da entrada no estoque',
            expiry_date date DEFAULT NULL,
            manufacturing_date date DEFAULT NULL,
            cost_price decimal(10,4) NOT NULL DEFAULT 0.0000,
            quantity decimal(10,3) NOT NULL DEFAULT 0.000,
            initial_quantity decimal(10,3) NOT NULL DEFAULT 0.000,
            unit_id int(10) UNSIGNED DEFAULT NULL,
            supplier_cnpj varchar(18) DEFAULT NULL,
            nfe_number varchar(50) DEFAULT NULL,
            nfe_key varchar(44) DEFAULT NULL,
            notes text DEFAULT NULL,
            status enum('active','depleted','expired','blocked') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY location_id (location_id),
            KEY batch_code (batch_code),
            KEY expiry_date (expiry_date),
            KEY status (status),
            KEY supplier_cnpj (supplier_cnpj),
            KEY product_location (product_id, location_id)
        ) " . self::$charset_collate . ";";

        dbDelta($sql);
    }

    /**
     * Tabela: sistur_inventory_transactions (Livro Razão - Log Imutável)
     * 
     * Registro de todas as movimentações de estoque.
     * Esta tabela é append-only (apenas inserções, sem updates ou deletes).
     * 
     * Tipos de transação:
     * - IN: Entrada (compra, devolução de cliente)
     * - OUT: Saída genérica
     * - SALE: Venda
     * - LOSS: Perda/Quebra
     * - ADJUST: Ajuste de inventário
     * - TRANSFER: Transferência entre locais
     */
    private static function create_inventory_transactions_table()
    {
        $table = self::$prefix . 'inventory_transactions';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id int(10) UNSIGNED NOT NULL,
            batch_id int(10) UNSIGNED DEFAULT NULL,
            from_location_id int(10) UNSIGNED DEFAULT NULL,
            to_location_id int(10) UNSIGNED DEFAULT NULL,
            user_id bigint(20) DEFAULT NULL,
            type enum('IN','OUT','SALE','LOSS','ADJUST','TRANSFER') NOT NULL,
            quantity decimal(10,3) NOT NULL,
            unit_id int(10) UNSIGNED DEFAULT NULL,
            unit_cost decimal(10,4) DEFAULT NULL,
            total_cost decimal(10,4) DEFAULT NULL,
            reason varchar(255) DEFAULT NULL,
            reference_type varchar(50) DEFAULT NULL,
            reference_id varchar(100) DEFAULT NULL,
            approval_request_id int(10) UNSIGNED DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY batch_id (batch_id),
            KEY user_id (user_id),
            KEY type (type),
            KEY created_at (created_at),
            KEY reference_type_id (reference_type, reference_id),
            KEY approval_request_id (approval_request_id)
        ) " . self::$charset_collate . ";";

        dbDelta($sql);
    }

    /**
     * Tabela: sistur_product_supplier_links (Matching XML/NFe)
     * 
     * Vincula códigos de produtos dos fornecedores aos produtos internos.
     * Usado para importação automática de notas fiscais XML.
     * 
     * O conversion_factor_entry permite converter a unidade da nota
     * para a unidade base do estoque.
     * Exemplo: Nota vem em "Caixa com 12", fator = 12 para converter em unidades.
     */
    private static function create_product_supplier_links_table()
    {
        $table = self::$prefix . 'product_supplier_links';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            supplier_cnpj varchar(18) NOT NULL,
            supplier_name varchar(255) DEFAULT NULL,
            external_code varchar(100) NOT NULL,
            external_description varchar(255) DEFAULT NULL,
            product_id int(10) UNSIGNED NOT NULL,
            unit_id int(10) UNSIGNED DEFAULT NULL,
            conversion_factor_entry decimal(10,4) DEFAULT 1.0000,
            is_verified tinyint(1) DEFAULT 0,
            last_used_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_supplier_product (supplier_cnpj, external_code),
            KEY product_id (product_id),
            KEY supplier_cnpj (supplier_cnpj),
            KEY is_verified (is_verified)
        ) " . self::$charset_collate . ";";

        dbDelta($sql);
    }

    /**
     * Tabela: sistur_recipes (Ficha Técnica / Composição de Pratos)
     * 
     * Define os ingredientes que compõem um produto final (prato/receita).
     * Suporta fator de rendimento/cocção para calcular peso bruto vs líquido.
     * 
     * Fórmula: quantity_gross = quantity_net / yield_factor
     * 
     * Exemplos de yield_factor:
     * - Arroz: 2.5 (100g cru vira 250g cozido)
     * - Carne: 0.8 (100g crua vira 80g pronta)
     * - Legumes: 0.9 (perda por descasque)
     */
    private static function create_recipes_table()
    {
        $table = self::$prefix . 'recipes';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_product_id int(10) UNSIGNED NOT NULL COMMENT 'Prato final (MANUFACTURED/RESALE)',
            child_product_id int(10) UNSIGNED NOT NULL COMMENT 'Ingrediente (RAW)',
            quantity_net decimal(10,3) NOT NULL COMMENT 'Peso líquido no prato final',
            yield_factor decimal(10,2) NOT NULL DEFAULT 1.00 COMMENT 'Fator de rendimento/cocção',
            quantity_gross decimal(10,3) NOT NULL COMMENT 'Peso bruto que sai do estoque',
            unit_id int(10) UNSIGNED DEFAULT NULL COMMENT 'Unidade de medida',
            notes text DEFAULT NULL COMMENT 'Observações de preparo',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_recipe_item (parent_product_id, child_product_id),
            KEY idx_parent (parent_product_id),
            KEY idx_child (child_product_id),
            KEY idx_unit (unit_id)
        ) " . self::$charset_collate . ";";

        dbDelta($sql);
    }

    /**
     * Tabela: sistur_inventory_losses (Rastreamento de Perdas para CMV)
     * 
     * Registra perdas de estoque categorizadas para análise de CMV.
     * Cada perda está vinculada a uma transação de saída (LOSS) e
     * captura o custo no momento para cálculos precisos.
     *
     * Categorias de perda:
     * - expired: Produto vencido
     * - production_error: Erro durante produção
     * - damaged: Dano físico (queda, quebra, etc)
     * - other: Outros motivos
     *
     * @since 2.7.0
     */
    private static function create_inventory_losses_table()
    {
        $table = self::$prefix . 'inventory_losses';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id int(10) UNSIGNED NOT NULL COMMENT 'Produto com perda',
            batch_id int(10) UNSIGNED DEFAULT NULL COMMENT 'Lote consumido (preenchido pelo FIFO)',
            transaction_id bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK para sistur_inventory_transactions',
            quantity decimal(10,3) NOT NULL COMMENT 'Quantidade perdida',
            reason enum('expired','production_error','damaged','inventory_divergence','other') NOT NULL COMMENT 'Categoria da perda',
            reason_details text DEFAULT NULL COMMENT 'Descrição adicional',
            cost_at_time decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'Custo unitário no momento',
            total_loss_value decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'Valor total da perda',
            location_id int(10) UNSIGNED DEFAULT NULL COMMENT 'Local da ocorrência',
            user_id bigint(20) DEFAULT NULL COMMENT 'Quem registrou',
            ip_address varchar(45) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY batch_id (batch_id),
            KEY transaction_id (transaction_id),
            KEY reason (reason),
            KEY created_at (created_at),
            KEY user_id (user_id)
        ) " . self::$charset_collate . ";";

        dbDelta($sql);
    }

    /**
     * Tabela: sistur_blind_inventory_sessions e sistur_blind_inventory_items
     * 
     * Sistema de Inventário Cego (Blind Count) para auditoria de estoque.
     * O usuário insere quantidades físicas sem ver o estoque teórico,
     * permitindo uma contagem imparcial.
     *
     * @since 2.8.0
     */
    private static function create_blind_inventory_tables()
    {
        // Tabela de Sessões
        $sessions_table = self::$prefix . 'blind_inventory_sessions';

        $sql_sessions = "CREATE TABLE IF NOT EXISTS {$sessions_table} (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL COMMENT 'Usuário que iniciou',
            status enum('in_progress','completed','cancelled') DEFAULT 'in_progress',
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            total_divergence_value decimal(10,4) DEFAULT 0.0000 COMMENT 'Impacto financeiro total',
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY started_at (started_at)
        ) " . self::$charset_collate . ";";

        dbDelta($sql_sessions);

        // Tabela de Itens da Contagem
        $items_table = self::$prefix . 'blind_inventory_items';

        $sql_items = "CREATE TABLE IF NOT EXISTS {$items_table} (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id int(10) UNSIGNED NOT NULL COMMENT 'FK para sessão',
            product_id int(10) UNSIGNED NOT NULL COMMENT 'Produto contado',
            theoretical_qty decimal(10,3) NOT NULL COMMENT 'Estoque teórico capturado no início',
            physical_qty decimal(10,3) DEFAULT NULL COMMENT 'Quantidade física inserida',
            divergence decimal(10,3) DEFAULT NULL COMMENT 'physical - theoretical',
            unit_cost decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'Custo unitário no momento',
            divergence_value decimal(10,4) DEFAULT NULL COMMENT 'Impacto financeiro (divergence * unit_cost)',
            loss_id int(10) UNSIGNED DEFAULT NULL COMMENT 'FK se criou perda',
            transaction_id bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK se criou ajuste',
            expiry_date date DEFAULT NULL COMMENT 'Data de validade (para sobras)',
            batch_id int(10) UNSIGNED DEFAULT NULL COMMENT 'Lote específico (para perdas)',
            PRIMARY KEY (id),
            UNIQUE KEY unique_session_product (session_id, product_id),
            KEY session_id (session_id),
            KEY product_id (product_id)
        ) " . self::$charset_collate . ";";

        dbDelta($sql_items);

        error_log('SISTUR Stock: Tabelas de Inventário Cego criadas/atualizadas');
    }

    /**
     * Insere unidades padrão do sistema
     */
    private static function seed_default_units()
    {

        global $wpdb;

        $table = self::$prefix . 'units';

        $default_units = array(
            // Unidades de massa
            array('name' => 'Quilograma', 'symbol' => 'kg', 'type' => 'dimensional', 'is_system' => 1),
            array('name' => 'Grama', 'symbol' => 'g', 'type' => 'dimensional', 'is_system' => 1),

            // Unidades de volume
            array('name' => 'Litro', 'symbol' => 'L', 'type' => 'dimensional', 'is_system' => 1),
            array('name' => 'Mililitro', 'symbol' => 'mL', 'type' => 'dimensional', 'is_system' => 1),

            // Unidades contáveis
            array('name' => 'Unidade', 'symbol' => 'un', 'type' => 'unitary', 'is_system' => 1),
            array('name' => 'Caixa', 'symbol' => 'cx', 'type' => 'unitary', 'is_system' => 1),
            array('name' => 'Pacote', 'symbol' => 'pct', 'type' => 'unitary', 'is_system' => 1),
            array('name' => 'Peça', 'symbol' => 'pc', 'type' => 'unitary', 'is_system' => 1),
            array('name' => 'Garrafa', 'symbol' => 'gfa', 'type' => 'unitary', 'is_system' => 1),
            array('name' => 'Lata', 'symbol' => 'lt', 'type' => 'unitary', 'is_system' => 1),
            array('name' => 'Dúzia', 'symbol' => 'dz', 'type' => 'unitary', 'is_system' => 1),
        );

        foreach ($default_units as $unit) {
            // Verificar se já existe pelo símbolo
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE symbol = %s",
                $unit['symbol']
            ));

            if (!$exists) {
                $wpdb->insert($table, $unit, array('%s', '%s', '%s', '%d'));
            }
        }

        // Inserir conversões padrão de sistema
        self::seed_default_conversions();
    }

    /**
     * Insere conversões padrão do sistema
     */
    private static function seed_default_conversions()
    {
        global $wpdb;

        $units_table = self::$prefix . 'units';
        $conversions_table = self::$prefix . 'unit_conversions';

        // Buscar IDs das unidades
        $kg_id = $wpdb->get_var("SELECT id FROM {$units_table} WHERE symbol = 'kg'");
        $g_id = $wpdb->get_var("SELECT id FROM {$units_table} WHERE symbol = 'g'");
        $l_id = $wpdb->get_var("SELECT id FROM {$units_table} WHERE symbol = 'L'");
        $ml_id = $wpdb->get_var("SELECT id FROM {$units_table} WHERE symbol = 'mL'");
        $dz_id = $wpdb->get_var("SELECT id FROM {$units_table} WHERE symbol = 'dz'");
        $un_id = $wpdb->get_var("SELECT id FROM {$units_table} WHERE symbol = 'un'");

        $conversions = array();

        // Quilograma <-> Grama
        if ($kg_id && $g_id) {
            $conversions[] = array(
                'product_id' => null,
                'from_unit_id' => $kg_id,
                'to_unit_id' => $g_id,
                'factor' => 1000.0000
            );
            $conversions[] = array(
                'product_id' => null,
                'from_unit_id' => $g_id,
                'to_unit_id' => $kg_id,
                'factor' => 0.0010
            );
        }

        // Litro <-> Mililitro
        if ($l_id && $ml_id) {
            $conversions[] = array(
                'product_id' => null,
                'from_unit_id' => $l_id,
                'to_unit_id' => $ml_id,
                'factor' => 1000.0000
            );
            $conversions[] = array(
                'product_id' => null,
                'from_unit_id' => $ml_id,
                'to_unit_id' => $l_id,
                'factor' => 0.0010
            );
        }

        // Dúzia <-> Unidade
        if ($dz_id && $un_id) {
            $conversions[] = array(
                'product_id' => null,
                'from_unit_id' => $dz_id,
                'to_unit_id' => $un_id,
                'factor' => 12.0000
            );
            $conversions[] = array(
                'product_id' => null,
                'from_unit_id' => $un_id,
                'to_unit_id' => $dz_id,
                'factor' => 0.0833
            );
        }

        foreach ($conversions as $conv) {
            // Verificar se já existe
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$conversions_table} 
                 WHERE product_id IS NULL 
                 AND from_unit_id = %d 
                 AND to_unit_id = %d",
                $conv['from_unit_id'],
                $conv['to_unit_id']
            ));

            if (!$exists) {
                $wpdb->insert($conversions_table, $conv, array('%d', '%d', '%d', '%f'));
            }
        }
    }

    /**
     * Insere locais de armazenamento padrão
     */
    private static function seed_default_locations()
    {
        global $wpdb;

        $table = self::$prefix . 'storage_locations';

        $default_locations = array(
            array(
                'name' => 'Estoque Principal',
                'code' => 'EST-PRINCIPAL',
                'description' => 'Estoque principal do estabelecimento',
                'location_type' => 'warehouse',
                'is_active' => 1
            ),
            array(
                'name' => 'Câmara Fria',
                'code' => 'CAM-FRIA',
                'description' => 'Câmara fria para produtos refrigerados',
                'location_type' => 'refrigerator',
                'is_active' => 1
            ),
            array(
                'name' => 'Freezer',
                'code' => 'FREEZER',
                'description' => 'Freezer para produtos congelados',
                'location_type' => 'freezer',
                'is_active' => 1
            ),
        );

        foreach ($default_locations as $location) {
            // Verificar se já existe pelo código
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE code = %s",
                $location['code']
            ));

            if (!$exists) {
                $wpdb->insert($table, $location, array('%s', '%s', '%s', '%s', '%d'));
            }
        }
    }

    /**
     * Migração v2.9.0: Adicionar rastreabilidade de funcionário
     * 
     * Adiciona employee_id às tabelas de inventário para rastrear
     * qual funcionário executou cada operação (WHO, WHAT, WHERE, WHEN).
     * 
     * @since 2.9.0
     */
    private static function migrate_add_employee_tracking()
    {
        global $wpdb;

        // Migração 1: inventory_batches - Adicionar created_by_employee_id e created_by_user_id
        $table_batches = self::$prefix . 'inventory_batches';
        $employee_id_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_batches} LIKE 'created_by_employee_id'");

        if (empty($employee_id_exists)) {
            $wpdb->query("ALTER TABLE {$table_batches}
                ADD COLUMN created_by_employee_id mediumint(9) DEFAULT NULL COMMENT 'ID do funcionário que criou o lote' AFTER notes,
                ADD COLUMN created_by_user_id bigint(20) DEFAULT NULL COMMENT 'ID do usuário WordPress' AFTER created_by_employee_id,
                ADD KEY created_by_employee_id (created_by_employee_id),
                ADD KEY created_by_user_id (created_by_user_id)");

            error_log('SISTUR Stock: Colunas de rastreabilidade adicionadas à tabela inventory_batches');
        }

        // Migração 2: inventory_transactions - Adicionar employee_id
        $table_transactions = self::$prefix . 'inventory_transactions';
        $employee_id_trans_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_transactions} LIKE 'employee_id'");

        if (empty($employee_id_trans_exists)) {
            $wpdb->query("ALTER TABLE {$table_transactions}
                ADD COLUMN employee_id mediumint(9) DEFAULT NULL COMMENT 'ID do funcionário que executou a transação' AFTER user_id,
                ADD KEY employee_id (employee_id)");

            error_log('SISTUR Stock: Coluna employee_id adicionada à tabela inventory_transactions');
        }
    }

    /**
     * Migração v2.9.1: Adicionar informações de aquisição
     * 
     * Adiciona acquisition_location e entry_date para melhor controle
     * de onde e quando o produto entrou.
     * 
     * @since 2.9.1
     */
    private static function migrate_add_acquisition_info()
    {
        global $wpdb;

        $table = self::$prefix . 'inventory_batches';

        // Adicionar acquisition_location
        $col_location_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'acquisition_location'");
        if (empty($col_location_exists)) {
            $wpdb->query("ALTER TABLE {$table} 
                ADD COLUMN acquisition_location varchar(255) DEFAULT NULL COMMENT 'Local de aquisição (Mercado, Fornecedor)' AFTER batch_code");
            error_log('SISTUR Stock: Coluna acquisition_location adicionada à tabela inventory_batches');
        }

        // Adicionar entry_date
        $col_date_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'entry_date'");
        if (empty($col_date_exists)) {
            $wpdb->query("ALTER TABLE {$table} 
                ADD COLUMN entry_date datetime DEFAULT NULL COMMENT 'Data e hora da entrada no estoque' AFTER acquisition_location");

            // Popula entry_date com created_at para registros antigos
            $wpdb->query("UPDATE {$table} SET entry_date = created_at WHERE entry_date IS NULL");

            error_log('SISTUR Stock: Coluna entry_date adicionada à tabela inventory_batches');
        }
    }

    /**
     * Migração v2.10.0: Adicionar escopo de local/setor ao inventário cego
     * 
     * Adiciona location_id e sector_id à tabela de sessões para permitir
     * inventário cego por local e setor específico.
     * 
     * @since 2.10.0
     */
    public static function migrate_blind_inventory_scope()
    {
        global $wpdb;

        $table = self::$prefix . 'blind_inventory_sessions';

        // Verificar se a tabela existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$table_exists) {
            return;
        }

        // Adicionar location_id
        $col_loc = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'location_id'");
        if (empty($col_loc)) {
            $wpdb->query("ALTER TABLE {$table} 
                ADD COLUMN location_id int(10) UNSIGNED DEFAULT NULL COMMENT 'Local de armazenamento do inventário',
                ADD COLUMN sector_id int(10) UNSIGNED DEFAULT NULL COMMENT 'Setor dentro do local',
                ADD KEY location_id (location_id),
                ADD KEY sector_id (sector_id)");

            error_log('SISTUR Stock: Colunas location_id e sector_id adicionadas à tabela blind_inventory_sessions');
        }
    }

    /**
     * Migração v2.11.0: Adicionar setor padrão ao produto
     *
     * Adiciona default_sector_id à tabela de produtos para definir
     * o setor esperado do produto (usado no inventário cego para
     * detectar itens fora de lugar).
     *
     * @since 2.11.0
     */
    private static function migrate_add_default_sector()
    {
        global $wpdb;

        $table = self::$prefix . 'products';

        $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'default_sector_id'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$table}
                ADD COLUMN default_sector_id int(10) UNSIGNED DEFAULT NULL
                COMMENT 'Setor padrão onde o produto deve ficar (FK sistur_storage_locations)'
                AFTER resource_parent_id,
                ADD KEY default_sector_id (default_sector_id)");

            error_log("SISTUR Stock: Coluna 'default_sector_id' adicionada à tabela products");
        }
    }

    /**
     * Migração v2.14.0: Adicionar sector_id à tabela de lotes
     *
     * Separa o conceito de localização (parent) e setor (child)
     * adicionando uma coluna sector_id dedicada aos lotes.
     * Também migra dados existentes onde location_id aponta para um setor.
     *
     * @since 2.14.0
     */
    private static function migrate_add_sector_to_batches()
    {
        global $wpdb;

        $batches_table = self::$prefix . 'inventory_batches';
        $locations_table = self::$prefix . 'storage_locations';

        $col = $wpdb->get_results("SHOW COLUMNS FROM {$batches_table} LIKE 'sector_id'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$batches_table}
                ADD COLUMN sector_id int(10) UNSIGNED DEFAULT NULL
                COMMENT 'Setor específico onde o lote está armazenado (FK sistur_storage_locations)'
                AFTER location_id,
                ADD KEY sector_id (sector_id)");

            error_log("SISTUR Stock: Coluna 'sector_id' adicionada à tabela inventory_batches");

            // Migrar dados existentes: se location_id aponta para um setor (tem parent_id),
            // mover para sector_id e corrigir location_id para o parent
            $migrated = $wpdb->query("
                UPDATE {$batches_table} b
                INNER JOIN {$locations_table} l ON b.location_id = l.id
                SET b.sector_id = l.id, b.location_id = l.parent_id
                WHERE l.parent_id IS NOT NULL
            ");

            if ($migrated > 0) {
                error_log("SISTUR Stock: Migrados {$migrated} lotes com sector_id corrigido");
            }
        }
    }

    /**
     * Migração v2.15.0: Adicionar suporte a modo wizard no inventário cego
     *
     * Adiciona colunas para controlar o fluxo setor-a-setor:
     * - wizard_mode: flag indicando se usa fluxo wizard
     * - current_sector_index: posição atual no array de setores
     * - sectors_list: JSON com IDs dos setores a serem percorridos
     *
     * @since 2.15.0
     */
    private static function migrate_blind_inventory_wizard()
    {
        global $wpdb;

        $sessions_table = self::$prefix . 'blind_inventory_sessions';

        $col = $wpdb->get_results("SHOW COLUMNS FROM {$sessions_table} LIKE 'wizard_mode'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$sessions_table}
                ADD COLUMN wizard_mode tinyint(1) DEFAULT 0
                    COMMENT 'Se 1, usa modo wizard setor-a-setor'
                    AFTER sector_id,
                ADD COLUMN current_sector_index int DEFAULT 0
                    COMMENT 'Índice do setor atual sendo contado (0-based)'
                    AFTER wizard_mode,
                ADD COLUMN sectors_list TEXT DEFAULT NULL
                    COMMENT 'JSON array de IDs dos setores a serem contados'
                    AFTER current_sector_index");

            error_log("SISTUR Stock: Colunas wizard_mode, current_sector_index, sectors_list adicionadas à tabela blind_inventory_sessions");
        }
    }

    /**
     * Migração v2.15.1: Corrigir lotes com sector_id NULL
     *
     * Após v2.14.0, novos lotes continuaram salvando o ID do setor
     * na coluna location_id (sem preencher sector_id). Esta migração
     * corrige esses dados reaplicando a mesma lógica da v2.14.0.
     *
     * @since 2.15.1
     */
    private static function migrate_fix_batch_sector_ids()
    {
        global $wpdb;

        $batches_table = self::$prefix . 'inventory_batches';
        $locations_table = self::$prefix . 'storage_locations';

        // Verificar se a coluna sector_id existe
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$batches_table} LIKE 'sector_id'");
        if (empty($col)) {
            return; // Coluna não existe, nada a fazer
        }

        // Corrigir lotes onde location_id aponta para setor (tem parent_id) mas sector_id é NULL
        $fixed = $wpdb->query("
            UPDATE {$batches_table} b
            INNER JOIN {$locations_table} l ON b.location_id = l.id
            SET b.sector_id = l.id, b.location_id = l.parent_id
            WHERE l.parent_id IS NOT NULL
              AND b.sector_id IS NULL
        ");

        if ($fixed > 0) {
            error_log("SISTUR Stock v2.15.1: Corrigidos {$fixed} lotes com sector_id NULL (location_id apontava para setor)");
        }
    }

    /**
     * Migração v2.15.3: Permitir mesmo produto em setores diferentes
     *
     * Remove constraint UNIQUE(session_id, product_id) e adiciona
     * UNIQUE(session_id, product_id, sector_id) para permitir contar
     * o mesmo produto em múltiplos setores durante uma sessão.
     *
     * @since 2.15.3
     */
    private static function migrate_blind_inventory_unique_per_sector()
    {
        global $wpdb;

        $items_table = self::$prefix . 'blind_inventory_items';

        // Verificar se a constraint antiga existe
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$items_table} WHERE Key_name = 'unique_session_product'");

        if (!empty($indexes)) {
            // Remover constraint antiga
            $wpdb->query("ALTER TABLE {$items_table} DROP INDEX unique_session_product");
            error_log("SISTUR Stock v2.15.3: Constraint antiga 'unique_session_product' removida");
        }

        // Verificar se a nova constraint já existe
        $new_indexes = $wpdb->get_results("SHOW INDEX FROM {$items_table} WHERE Key_name = 'unique_session_product_sector'");

        if (empty($new_indexes)) {
            // Adicionar nova constraint incluindo sector_id
            $wpdb->query("ALTER TABLE {$items_table}
                ADD UNIQUE KEY unique_session_product_sector (session_id, product_id, sector_id)");
            error_log("SISTUR Stock v2.15.3: Nova constraint 'unique_session_product_sector' adicionada");
        }
    }

    /**
     * Tabela: sistur_macro_revenue (Faturamento Diário — Modo Macro)
     *
     * Armazena o faturamento bruto diário por categoria (ex: Restaurante, Bar).
     * Usada como fonte de receita no DRE quando sistur_sales_mode = 'MACRO'.
     * Em modo Micro, a receita continua vindo de inventory_transactions tipo SALE.
     *
     * @since 2.16.0
     */
    private static function create_macro_revenue_table()
    {
        $table = self::$prefix . 'macro_revenue';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            revenue_date date NOT NULL COMMENT 'Data do faturamento (dia de operação)',
            total_amount decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor bruto arrecadado no período',
            category varchar(100) NOT NULL DEFAULT 'Geral' COMMENT 'Centro de resultado (ex: Restaurante, Bar)',
            notes text DEFAULT NULL COMMENT 'Observações livres (ex: evento especial)',
            user_id bigint(20) DEFAULT NULL COMMENT 'Usuário WordPress que registrou',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY revenue_date (revenue_date),
            KEY category (category),
            KEY user_id (user_id)
        ) " . self::$charset_collate . ";";

        dbDelta($sql);
    }

    /**
     * Tabela: sistur_suppliers (Cadastro de Fornecedores)
     *
     * Permite que operadores mantenham um cadastro centralizado de fornecedores
     * para evitar duplicatas e erros de digitação no campo "Local de Aquisição".
     *
     * @since 2.17.0
     */
    private static function create_suppliers_table()
    {
        $table = self::$prefix . 'suppliers';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL COMMENT 'Nome do fornecedor',
            tax_id varchar(18) DEFAULT NULL COMMENT 'CNPJ do fornecedor',
            contact_info text DEFAULT NULL COMMENT 'Informações de contato (telefone, e-mail, endereço)',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY name (name),
            KEY tax_id (tax_id)
        ) " . self::$charset_collate . ";";

        dbDelta($sql);
        error_log('SISTUR Stock v2.17.0: Tabela sistur_suppliers criada/verificada.');
    }

    /**
     * Migração: adicionar supplier_id em inventory_batches (v2.17.0)
     *
     * A coluna acquisition_location é mantida para preservar dados históricos.
     */
    private static function migrate_add_supplier_id()
    {
        global $wpdb;

        $batches_table = self::$prefix . 'inventory_batches';
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$batches_table} LIKE 'supplier_id'");

        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$batches_table}
                ADD COLUMN supplier_id int(10) UNSIGNED DEFAULT NULL
                    COMMENT 'FK para sistur_suppliers (v2.17.0)' AFTER acquisition_location,
                ADD KEY supplier_id (supplier_id)");
            error_log('SISTUR Stock v2.17.0: Coluna supplier_id adicionada em inventory_batches.');
        }
    }

    /**
     * Migração: converter acquisition_location (texto livre) para supplier_id (FK) (v2.17.0)
     *
     * Para cada valor distinto em acquisition_location que ainda não tenha supplier_id,
     * cria (ou reutiliza) um registro em sistur_suppliers e vincula via supplier_id.
     * Idempotente: pode ser executado múltiplas vezes sem duplicar fornecedores.
     */
    private static function migrate_acquisition_location_to_suppliers()
    {
        global $wpdb;

        $batches_table   = self::$prefix . 'inventory_batches';
        $suppliers_table = self::$prefix . 'suppliers';

        // Verificar se a coluna supplier_id existe antes de tentar migrar
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$batches_table} LIKE 'supplier_id'");
        if (empty($col)) {
            return;
        }

        $locations = $wpdb->get_col(
            "SELECT DISTINCT acquisition_location
             FROM {$batches_table}
             WHERE acquisition_location IS NOT NULL
               AND acquisition_location != ''
               AND supplier_id IS NULL"
        );

        if (empty($locations)) {
            return;
        }

        $migrated = 0;
        foreach ($locations as $name) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$suppliers_table} WHERE name = %s LIMIT 1",
                $name
            ));

            if (!$existing_id) {
                $wpdb->insert(
                    $suppliers_table,
                    array('name' => $name, 'created_at' => current_time('mysql')),
                    array('%s', '%s')
                );
                $supplier_id = $wpdb->insert_id;
            } else {
                $supplier_id = (int) $existing_id;
            }

            $wpdb->query($wpdb->prepare(
                "UPDATE {$batches_table}
                 SET supplier_id = %d
                 WHERE acquisition_location = %s AND supplier_id IS NULL",
                $supplier_id,
                $name
            ));

            $migrated++;
        }

        error_log("SISTUR Stock v2.17.0: {$migrated} fornecedor(es) migrado(s) de acquisition_location para sistur_suppliers.");
    }

    /**
     * Remove todas as tabelas do módulo de estoque
     * CUIDADO: Isso apaga todos os dados!
     */
    public static function uninstall()
    {
        global $wpdb;

        $tables = array(
            'inventory_transactions',
            'inventory_batches',
            'product_supplier_links',
            'unit_conversions',
            'storage_locations',
            'units'
        );

        foreach ($tables as $table) {
            $full_table = self::$prefix . $table;
            $wpdb->query("DROP TABLE IF EXISTS {$full_table}");
        }

        delete_option('sistur_stock_schema_version');

        error_log('SISTUR Stock: Todas as tabelas do módulo de estoque foram removidas');
    }

    /**
     * Verifica se o schema está atualizado
     * 
     * @return bool
     */
    public static function needs_upgrade()
    {
        $current_version = get_option('sistur_stock_schema_version', '0.0.0');
        return version_compare($current_version, self::SCHEMA_VERSION, '<');
    }

    /**
     * Retorna informações sobre as tabelas do módulo
     * 
     * @return array
     */
    /**
     * Migração v2.12.0: Adicionar campos extras ao inventário cego
     * 
     * Adiciona expiry_date e batch_id aos itens do inventário cego
     * para permitir controle preciso de sobras e perdas.
     * 
     * @since 2.12.0
     */
    public static function migrate_blind_inventory_extras()
    {
        global $wpdb;

        $table = self::$prefix . 'blind_inventory_items';

        // expiry_date
        $expiry_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'expiry_date'");
        if (empty($expiry_exists)) {
            $wpdb->query("ALTER TABLE {$table} 
                ADD COLUMN expiry_date date DEFAULT NULL COMMENT 'Data de validade (para sobras)' AFTER transaction_id,
                ADD KEY expiry_date (expiry_date)");
            error_log('SISTUR Stock: Coluna expiry_date adicionada à tabela blind_inventory_items');
        }

        // batch_id
        $batch_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'batch_id'");
        if (empty($batch_exists)) {
            $wpdb->query("ALTER TABLE {$table}
                ADD COLUMN batch_id int(10) UNSIGNED DEFAULT NULL COMMENT 'Lote específico (para perdas)' AFTER expiry_date,
                ADD KEY batch_id (batch_id)");
            error_log('SISTUR Stock: Coluna batch_id adicionada à tabela blind_inventory_items');
        }

        // sector_id (v2.15.2)
        $sector_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'sector_id'");
        if (empty($sector_exists)) {
            $wpdb->query("ALTER TABLE {$table}
                ADD COLUMN sector_id int(10) UNSIGNED DEFAULT NULL COMMENT 'Setor onde item foi contado (modo wizard)' AFTER batch_id,
                ADD KEY sector_id (sector_id)");
            error_log('SISTUR Stock v2.15.2: Coluna sector_id adicionada à tabela blind_inventory_items');
        }
    }

    public static function get_tables_info()
    {
        global $wpdb;

        $prefix = $wpdb->prefix . 'sistur_';
        $tables = array(
            'units',
            'unit_conversions',
            'storage_locations',
            'inventory_batches',
            'inventory_transactions',
            'product_supplier_links'
        );

        $info = array();
        foreach ($tables as $table) {
            $full_name = $prefix . $table;
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$full_name}");
            $info[$table] = array(
                'name' => $full_name,
                'exists' => ($count !== null),
                'count' => (int) $count
            );
        }

        return $info;
    }

    /**
     * Migração v2.18.0: Adicionar tipo BASE e coluna production_yield
     *
     * - Tipo BASE: Prato Base / Pré-Preparo (produto manufaturado intermediário
     *   que consome insumos e é usado como ingrediente em pratos finais).
     * - production_yield: Fator de rendimento da produção do prato BASE
     *   (ex: 0.8 = 80% de aproveitamento dos insumos viram produto final).
     *   Usado na fórmula: custo_unitário = Σ(custo_insumos) / production_yield
     */
    private static function migrate_add_base_type()
    {
        global $wpdb;

        $table = self::$prefix . 'products';

        // 1. Adicionar BASE ao SET de tipos (idempotente)
        $current_type = $wpdb->get_var("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$table}'
              AND COLUMN_NAME = 'type'");

        if ($current_type && strpos($current_type, 'BASE') === false) {
            $wpdb->query("ALTER TABLE {$table}
                MODIFY COLUMN type SET('RAW','RESALE','MANUFACTURED','RESOURCE','BASE') DEFAULT 'RAW'");
            error_log("SISTUR Stock: Tipo 'BASE' adicionado ao SET de produtos (v2.18.0)");
        }

        // 2. Adicionar coluna production_yield (idempotente)
        $yield_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'production_yield'");
        if (empty($yield_exists)) {
            $wpdb->query("ALTER TABLE {$table}
                ADD COLUMN production_yield DECIMAL(10,4) NOT NULL DEFAULT 1.0000
                COMMENT 'Fator de rendimento da produção do Prato Base (ex: 0.8 = 80% de aproveitamento). Fórmula: custo_unitário = Σ(custo_insumos) / production_yield'
                AFTER cost_price");
            error_log("SISTUR Stock: Coluna 'production_yield' adicionada à tabela products (v2.18.0)");
        }
    }
}
