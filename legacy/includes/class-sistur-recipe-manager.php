<?php
/**
 * Gerenciador de Receitas / Ficha Técnica
 * 
 * Responsável por calcular custos de pratos e dar baixa nos ingredientes
 * considerando o fator de rendimento/cocção.
 *
 * @package SISTUR
 * @subpackage Stock
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sistur_Recipe_Manager
{

    /**
     * Instância única (Singleton)
     */
    private static $instance = null;

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
     * Valida recursivamente se há estoque suficiente para produzir um prato
     * 
     * Percorre toda a árvore de dependências (incluindo pratos BASE) e cria
     * um plano de produção para itens que precisam ser auto-produzidos.
     * 
     * @param int $product_id ID do produto a produzir
     * @param float $quantity Quantidade a produzir
     * @param array $visited IDs já visitados (detecção de ciclos)
     * @param array $production_plan Plano de produção acumulado
     * @return array{success: bool, missing: array, production_plan: array}|WP_Error
     */
    public function validate_recursive_stock($product_id, $quantity, &$visited = [], &$production_plan = [])
    {
        global $wpdb;
        
        $product_id = (int) $product_id;
        $quantity = abs((float) $quantity);
        
        // Detectar dependências circulares
        if (in_array($product_id, $visited)) {
            return new WP_Error('circular_dependency', 
                sprintf('Dependência circular detectada no produto ID %d', $product_id),
                array('status' => 400)
            );
        }
        $visited[] = $product_id;
        
        // Buscar ingredientes da receita
        $ingredients = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, 
                    p.name as ingredient_name,
                    p.type as ingredient_type,
                    p.base_unit_id as ingredient_base_unit_id,
                    u.name as recipe_unit_name,
                    ub.name as base_unit_name
             FROM {$this->prefix}recipes r
             INNER JOIN {$this->prefix}products p ON r.child_product_id = p.id
             LEFT JOIN {$this->prefix}units u ON r.unit_id = u.id
             LEFT JOIN {$this->prefix}units ub ON p.base_unit_id = ub.id
             WHERE r.parent_product_id = %d",
            $product_id
        ));
        
        if (empty($ingredients)) {
            // Produto sem receita - OK (pode ser ingrediente simples)
            return array('success' => true, 'missing' => [], 'production_plan' => $production_plan);
        }
        
        $converter = Sistur_Unit_Converter::get_instance();
        $missing = [];
        
        foreach ($ingredients as $ing) {
            $recipe_quantity = (float) $ing->quantity_gross * $quantity;
            $consume_quantity = $recipe_quantity;
            $recipe_unit_id = (int) $ing->unit_id;
            $base_unit_id = (int) $ing->ingredient_base_unit_id;
            
            // Converter unidades se necessário
            if ($recipe_unit_id && $base_unit_id && $recipe_unit_id !== $base_unit_id) {
                $converted = $converter->convert($recipe_quantity, $recipe_unit_id, $base_unit_id, $ing->child_product_id);
                if (!is_wp_error($converted)) {
                    $consume_quantity = $converted;
                }
            }
            
            // Verificar tipo do ingrediente (SET pode ter múltiplos valores ex: 'BASE,RAW')
            $is_resource = (strpos($ing->ingredient_type ?? '', 'RESOURCE') !== false);
            $is_base     = (strpos($ing->ingredient_type ?? '', 'BASE') !== false);

            // Obter estoque disponível
            if ($is_resource) {
                $available = $this->get_available_stock_from_children($ing->child_product_id, $base_unit_id);
            } else {
                $available = $this->get_available_stock($ing->child_product_id);
            }
            
            // Se é um prato BASE e não tem estoque suficiente
            if ($is_base && $available < $consume_quantity) {
                $needed = $consume_quantity - $available;
                
                // Validar recursivamente os ingredientes do prato BASE
                $sub_validation = $this->validate_recursive_stock($ing->child_product_id, $needed, $visited, $production_plan);
                
                if (is_wp_error($sub_validation)) {
                    return $sub_validation;
                }
                
                if (!$sub_validation['success']) {
                    // Propagar erros de ingredientes faltantes
                    $missing = array_merge($missing, $sub_validation['missing']);
                } else {
                    // Adicionar ao plano de produção
                    $production_plan[] = array(
                        'product_id' => $ing->child_product_id,
                        'product_name' => $ing->ingredient_name,
                        'quantity' => $needed,
                        'type' => 'BASE'
                    );
                }
            } elseif (!$is_base && $available < $consume_quantity) {
                // Ingrediente normal sem estoque suficiente
                $missing[] = array(
                    'ingredient_id' => $ing->child_product_id,
                    'ingredient_name' => $ing->ingredient_name,
                    'required' => $consume_quantity,
                    'available' => $available,
                    'unit' => $ing->base_unit_name ?: ''
                );
            }
        }
        
        return array(
            'success' => empty($missing),
            'missing' => $missing,
            'production_plan' => $production_plan
        );
    }
    
    /**
     * Executa o plano de produção de pratos BASE
     * 
     * Produz cada prato BASE na ordem do plano, criando lotes no estoque.
     * 
     * @param array $production_plan Lista de pratos a produzir
     * @param int $user_id ID do usuário
     * @return array Resultados da produção
     */
    private function execute_production_plan($production_plan, $user_id)
    {
        global $wpdb;
        
        $results = [];
        
        foreach ($production_plan as $item) {
            error_log(sprintf(
                "SISTUR BASE: Auto-produzindo %s (ID: %d) x%.3f",
                $item['product_name'],
                $item['product_id'],
                $item['quantity']
            ));
            
            // Primeiro, deduzir ingredientes do prato BASE
            $deduction = $this->deduct_recipe_stock_internal($item['product_id'], $item['quantity'],
                'Auto-produção para composição de prato');

            if (is_wp_error($deduction)) {
                error_log("SISTUR BASE: Erro na dedução - " . $deduction->get_error_message());
                continue;
            }

            // Calcular custo total dos ingredientes consumidos
            // _execute_recipe_deduction não retorna total_cost; calculamos a partir do custo
            // médio de cada ingrediente (IN + PRODUCTION_IN) × quantidade deduzida.
            $total_ingredient_cost = 0;
            if (!empty($deduction['details'])) {
                foreach ($deduction['details'] as $det) {
                    $avg = (float) $wpdb->get_var($wpdb->prepare(
                        "SELECT COALESCE(
                            SUM(ABS(quantity) * unit_cost) / NULLIF(SUM(ABS(quantity)), 0),
                            0
                         )
                         FROM {$this->prefix}inventory_transactions
                         WHERE product_id = %d
                           AND type IN ('IN', 'PRODUCTION_IN')
                           AND unit_cost > 0",
                        $det['ingredient_id']
                    ));
                    $total_ingredient_cost += $avg * $det['quantity_deducted'];
                }
            }

            $unit_cost_base = ($item['quantity'] > 0)
                ? $total_ingredient_cost / $item['quantity']
                : 0;

            // Criar lote do prato BASE produzido
            $wpdb->insert($this->prefix . 'inventory_batches', array(
                'product_id'      => $item['product_id'],
                'quantity'        => $item['quantity'],
                'initial_quantity' => $item['quantity'],
                'cost_price'      => round($unit_cost_base, 4),
                'status'          => 'active',
                'batch_code'      => 'AUTO-' . date('YmdHis') . '-' . $item['product_id'],
                'notes'           => 'Produção automática de Prato Base',
                'created_at'      => current_time('mysql'),
            ));
            $new_batch_id = $wpdb->insert_id;

            // Registrar transação de entrada (PRODUCTION_IN)
            $wpdb->insert($this->prefix . 'inventory_transactions', array(
                'product_id'     => $item['product_id'],
                'batch_id'       => $new_batch_id,
                'user_id'        => $user_id,
                'type'           => 'PRODUCTION_IN',
                'quantity'       => $item['quantity'],
                'unit_cost'      => round($unit_cost_base, 4),
                'total_cost'     => round($total_ingredient_cost, 4),
                'reason'         => 'Auto-produção de Prato Base',
                'reference_type' => 'base_auto_production',
                'ip_address'     => $this->get_client_ip(),
                'created_at'     => current_time('mysql'),
            ));

            // Atualizar cached_stock
            $this->update_cached_stock($item['product_id']);

            // Atualizar cost_price e average_cost do Prato BASE
            // (ausentes antes desta correção, causando custo zero no prato final)
            $new_avg = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(
                    SUM(ABS(quantity) * unit_cost) / NULLIF(SUM(ABS(quantity)), 0),
                    0
                 )
                 FROM {$this->prefix}inventory_transactions
                 WHERE product_id = %d
                   AND type IN ('IN', 'PRODUCTION_IN')
                   AND unit_cost > 0",
                $item['product_id']
            ));

            $wpdb->update(
                $this->prefix . 'products',
                array(
                    'cost_price'   => round($unit_cost_base, 4),
                    'average_cost' => round($new_avg > 0 ? $new_avg : $unit_cost_base, 4),
                ),
                array('id' => $item['product_id'])
            );

            error_log(sprintf(
                "SISTUR BASE: Auto-produzido %s (ID: %d) × %.3f | custo unitário R$ %.4f",
                $item['product_name'],
                $item['product_id'],
                $item['quantity'],
                $unit_cost_base
            ));

            $results[] = array(
                'product_id'      => $item['product_id'],
                'product_name'    => $item['product_name'],
                'quantity_produced' => $item['quantity'],
                'unit_cost'       => round($unit_cost_base, 4),
                'total_cost'      => round($total_ingredient_cost, 4),
            );
        }
        
        return $results;
    }
    
    /**
     * Versão interna de deduct_recipe_stock (sem validação recursiva)
     * Usada para produzir pratos BASE automaticamente
     */
    private function deduct_recipe_stock_internal($product_id, $quantity_sold, $reason = null)
    {
        // Reutiliza a lógica existente mas sem a pré-validação recursiva
        // para evitar loop infinito
        return $this->_execute_recipe_deduction($product_id, $quantity_sold, $reason, false);
    }

    /**
     * Calcula o custo total do prato baseado nos ingredientes (v2.18 - recursivo para Pratos BASE)
     *
     * Para ingredientes do tipo BASE, o custo é calculado recursivamente a partir
     * dos seus próprios insumos, dividido pelo production_yield do Prato BASE.
     * Isso garante que o custo reflita sempre o preço atual dos insumos, sem
     * depender de produções já executadas.
     *
     * Fórmula (ingrediente BASE): custo_unitário = Σ(custo_insumos_do_base) / production_yield
     * Fórmula (ingrediente normal): custo_unitário = average_cost ?? cost_price
     * Fórmula (prato final): custo_total = Σ(quantity_gross × custo_unitário_ingrediente)
     *
     * Para Pratos BASE chamados diretamente, aplica production_yield próprio ao total:
     * custo_por_unidade = custo_total_insumos / production_yield
     *
     * @param int $product_id ID do produto (prato)
     * @return array|WP_Error Array com detalhes do custo ou erro
     */
    public function calculate_plate_cost($product_id)
    {
        global $wpdb;

        $product_id = (int) $product_id;

        // Verificar se o produto existe e carregar production_yield
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, COALESCE(p.production_yield, 1.0) as production_yield
             FROM {$this->prefix}products p
             WHERE p.id = %d",
            $product_id
        ));

        if (!$product) {
            return new WP_Error('not_found', 'Produto não encontrado', array('status' => 404));
        }

        // Buscar ingredientes da receita com tipo do ingrediente (para detectar BASE)
        $ingredients = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*,
                    p.name as ingredient_name,
                    p.type as ingredient_type,
                    p.average_cost,
                    p.cost_price,
                    u.symbol as unit_symbol
             FROM {$this->prefix}recipes r
             INNER JOIN {$this->prefix}products p ON r.child_product_id = p.id
             LEFT JOIN {$this->prefix}units u ON r.unit_id = u.id
             WHERE r.parent_product_id = %d",
            $product_id
        ));

        if (empty($ingredients)) {
            return array(
                'product_id'       => $product_id,
                'product_name'     => $product->name,
                'production_yield' => (float) $product->production_yield,
                'total_cost'       => 0,
                'ingredients'      => array(),
                'message'          => 'Nenhum ingrediente cadastrado na receita'
            );
        }

        $total_cost = 0;
        $details    = array();

        foreach ($ingredients as $ing) {
            $ingredient_type = $ing->ingredient_type ?? '';

            if (strpos($ingredient_type, 'BASE') !== false) {
                // Ingrediente é um Prato BASE: calcular custo recursivamente a partir
                // dos seus insumos e aplicar o production_yield deste prato BASE.
                $sub_cost = $this->calculate_plate_cost($ing->child_product_id);

                if (!is_wp_error($sub_cost) && $sub_cost['total_cost'] > 0) {
                    $base_yield = (float) $sub_cost['production_yield'];
                    $base_yield = ($base_yield > 0) ? $base_yield : 1.0;
                    // total_cost do BASE já está com yield aplicado (ver final deste método)
                    $unit_cost  = $sub_cost['total_cost'];
                } else {
                    // Fallback: usar custo armazenado se não houver receita no BASE
                    $unit_cost = ($ing->average_cost > 0) ? $ing->average_cost : (float) $ing->cost_price;
                }
            } else {
                // Ingrediente normal (RAW, RESOURCE, etc.)
                $unit_cost = ($ing->average_cost > 0) ? $ing->average_cost : (float) $ing->cost_price;
            }

            // Custo = peso bruto × custo unitário
            $ingredient_cost = (float) $ing->quantity_gross * (float) $unit_cost;
            $total_cost     += $ingredient_cost;

            $details[] = array(
                'id'               => $ing->id,
                'ingredient_id'    => $ing->child_product_id,
                'ingredient_name'  => $ing->ingredient_name,
                'ingredient_type'  => $ingredient_type,
                'quantity_net'     => (float) $ing->quantity_net,
                'yield_factor'     => (float) $ing->yield_factor,
                'quantity_gross'   => (float) $ing->quantity_gross,
                'unit'             => $ing->unit_symbol,
                'unit_cost'        => (float) $unit_cost,
                'ingredient_cost'  => round($ingredient_cost, 4),
                'notes'            => $ing->notes,
            );
        }

        // Aplicar production_yield do próprio produto quando é Prato BASE.
        // Fórmula: custo_por_unidade = Σ(custo_insumos) / production_yield
        $this_yield      = (float) $product->production_yield;
        $this_yield      = ($this_yield > 0) ? $this_yield : 1.0;
        $is_base_product = (strpos($product->type ?? '', 'BASE') !== false);

        $cost_before_yield = round($total_cost, 4);
        $effective_cost    = $is_base_product
            ? round($total_cost / $this_yield, 4)
            : round($total_cost, 4);

        return array(
            'product_id'        => $product_id,
            'product_name'      => $product->name,
            'production_yield'  => $this_yield,
            'cost_before_yield' => $cost_before_yield,
            'total_cost'        => $effective_cost,
            'ingredient_count'  => count($details),
            'ingredients'       => $details,
        );
    }

    /**
     * Dá baixa no estoque dos ingredientes de uma receita
     * 
     * Quando um prato é vendido/produzido, consome os ingredientes
     * usando FIFO e a quantidade bruta (quantity_gross).
     * 
     * IMPORTANTE: Converte a quantidade da receita para a unidade base
     * do ingrediente antes de consultar os lotes.
     * 
     * @param int $product_id ID do produto (prato)
     * @param float $quantity_sold Quantidade de pratos vendidos/produzidos
     * @param string $reason Motivo da baixa (opcional)
     * @return array|WP_Error Resultado da operação
     */
    public function deduct_recipe_stock($product_id, $quantity_sold, $reason = null)
    {
        global $wpdb;

        $product_id = (int) $product_id;
        $quantity_sold = abs((float) $quantity_sold);

        if ($quantity_sold <= 0) {
            return new WP_Error('invalid_quantity', 'Quantidade deve ser maior que zero', array('status' => 400));
        }

        // ========== NOVO: Validação Recursiva e Auto-produção ==========
        // Verifica se há ingredientes suficientes em toda a árvore de dependências
        // Se houver Pratos BASE sem estoque, calcula se é possível produzi-los
        
        $visited = [];
        $production_plan = [];
        $validation = $this->validate_recursive_stock($product_id, $quantity_sold, $visited, $production_plan);
        
        if (is_wp_error($validation)) {
            return $validation;
        }
        
        if (!$validation['success']) {
            // Retorna erro detalhado com lista de ingredientes faltantes
            return new WP_Error('insufficient_stock', 
                'Ingredientes insuficientes para produção',
                array(
                    'status' => 400,
                    'missing' => $validation['missing']
                )
            );
        }
        
        // Se há pratos BASE para auto-produzir, executar agora
        if (!empty($validation['production_plan'])) {
            $user_id = get_current_user_id() ?: 1;
            error_log("SISTUR: Executando plano de auto-produção com " . count($validation['production_plan']) . " itens");
            $auto_produced = $this->execute_production_plan($validation['production_plan'], $user_id);
        }
        // ========== FIM: Validação Recursiva ==========

        // Continuar com a dedução normal dos ingredientes
        return $this->_execute_recipe_deduction($product_id, $quantity_sold, $reason, true);
    }
    
    /**
     * Executa a dedução de ingredientes da receita
     * 
     * @param int $product_id ID do produto
     * @param float $quantity_sold Quantidade produzida/vendida
     * @param string $reason Motivo
     * @param bool $include_base Se deve processar ingredientes BASE
     * @return array|WP_Error
     */
    private function _execute_recipe_deduction($product_id, $quantity_sold, $reason = null, $include_base = true)
    {
        global $wpdb;

        // Obter instância do conversor de unidades
        $converter = Sistur_Unit_Converter::get_instance();

        // Buscar ingredientes da receita COM unidade da receita E unidade base do ingrediente
        $ingredients = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, 
                    p.name as ingredient_name,
                    p.type as ingredient_type,
                    p.base_unit_id as ingredient_base_unit_id,
                    u.name as recipe_unit_name,
                    ub.name as base_unit_name
             FROM {$this->prefix}recipes r
             INNER JOIN {$this->prefix}products p ON r.child_product_id = p.id
             LEFT JOIN {$this->prefix}units u ON r.unit_id = u.id
             LEFT JOIN {$this->prefix}units ub ON p.base_unit_id = ub.id
             WHERE r.parent_product_id = %d",
            $product_id
        ));

        if (empty($ingredients)) {
            return new WP_Error('no_recipe', 'Nenhum ingrediente cadastrado para este produto', array('status' => 400));
        }

        // Validar usuário atual
        $user_id = get_current_user_id();
        if (!$user_id) {
            $user_id = 1; // Fallback para admin se não há usuário logado
            error_log("SISTUR Recipe: Nenhum usuário logado, usando admin como fallback");
        }

        // Iniciar transação
        $wpdb->query('START TRANSACTION');

        $results = array();
        $errors = array();

        try {
            foreach ($ingredients as $ing) {
                // 1. Calcular quantidade bruta na unidade da receita
                $recipe_quantity = (float) $ing->quantity_gross * $quantity_sold;

                // 2. CONVERTER para unidade base do ingrediente
                $consume_quantity = $recipe_quantity;
                $recipe_unit_id = (int) $ing->unit_id;
                $base_unit_id = (int) $ing->ingredient_base_unit_id;

                // Se a unidade da receita é diferente da unidade base do ingrediente
                if ($recipe_unit_id && $base_unit_id && $recipe_unit_id !== $base_unit_id) {
                    // Validar que a unidade da receita existe
                    $unit_valid = $converter->validate_unit($recipe_unit_id);
                    if (is_wp_error($unit_valid)) {
                        $errors[] = array(
                            'ingredient_id' => $ing->child_product_id,
                            'ingredient_name' => $ing->ingredient_name,
                            'error' => $unit_valid->get_error_message()
                        );
                        continue;
                    }

                    // Tentar converter
                    $converted = $converter->convert(
                        $recipe_quantity,
                        $recipe_unit_id,
                        $base_unit_id,
                        $ing->child_product_id
                    );

                    if (is_wp_error($converted)) {
                        // Erro de conversão - abortar ingrediente com mensagem clara
                        $errors[] = array(
                            'ingredient_id' => $ing->child_product_id,
                            'ingredient_name' => $ing->ingredient_name,
                            'recipe_unit' => $ing->recipe_unit_name,
                            'base_unit' => $ing->base_unit_name,
                            'error' => sprintf(
                                'Erro: Conversão de unidade %s para %s não definida para o ingrediente %s.',
                                $ing->recipe_unit_name ?: "(ID: {$recipe_unit_id})",
                                $ing->base_unit_name ?: "(ID: {$base_unit_id})",
                                $ing->ingredient_name
                            )
                        );
                        continue;
                    }

                    $consume_quantity = $converted;

                    error_log(sprintf(
                        "SISTUR Recipe: Converteu %.3f %s para %.3f %s (ingrediente: %s)",
                        $recipe_quantity,
                        $ing->recipe_unit_name,
                        $consume_quantity,
                        $ing->base_unit_name,
                        $ing->ingredient_name
                    ));
                }

                // 3. Detectar se ingrediente é RESOURCE (v2.4) ou BASE (v2.18)
                $ingredient_type = $wpdb->get_var($wpdb->prepare(
                    "SELECT type FROM {$this->prefix}products WHERE id = %d",
                    $ing->child_product_id
                ));

                // SET pode ter múltiplos valores (ex: 'BASE,RAW'), usar strpos
                $is_resource = (strpos($ingredient_type ?? '', 'RESOURCE') !== false);

                // 4. Verificar estoque disponível
                if ($is_resource) {
                    // Para RESOURCE: somar estoque de todos os filhos
                    $available = $this->get_available_stock_from_children($ing->child_product_id, $base_unit_id);
                } else {
                    // Para produtos normais: estoque direto
                    $available = $this->get_available_stock($ing->child_product_id);
                }

                if ($available < $consume_quantity) {
                    $errors[] = array(
                        'ingredient_id' => $ing->child_product_id,
                        'ingredient_name' => $ing->ingredient_name,
                        'is_resource' => $is_resource,
                        'required' => $consume_quantity,
                        'available' => $available,
                        'unit' => $ing->base_unit_name,
                        'error' => sprintf(
                            'Estoque insuficiente de %s%s. Necessário: %.3f %s, Disponível: %.3f %s',
                            $ing->ingredient_name,
                            $is_resource ? ' (agregado de todas as marcas)' : '',
                            $consume_quantity,
                            $ing->base_unit_name ?: '',
                            $available,
                            $ing->base_unit_name ?: ''
                        )
                    );
                    continue;
                }

                // 5. Processar saída
                $deduction_reason = $reason ?: sprintf(
                    'Produção: %d× prato ID %d - ingrediente %s',
                    $quantity_sold,
                    $product_id,
                    $ing->ingredient_name
                );

                if ($is_resource) {
                    // RESOURCE: consumir de lotes dos filhos (FIFO por validade)
                    error_log("SISTUR Recipe: Ingrediente {$ing->ingredient_name} é RESOURCE - usando lógica de filhos");
                    $result = $this->process_resource_exit(
                        $ing->child_product_id,
                        $consume_quantity,
                        $deduction_reason,
                        $base_unit_id,
                        $user_id
                    );
                } else {
                    // Produto normal: FIFO direto
                    $result = $this->process_ingredient_exit(
                        $ing->child_product_id,
                        $consume_quantity,
                        $deduction_reason,
                        $base_unit_id,
                        $user_id
                    );
                }

                if (is_wp_error($result)) {
                    $errors[] = array(
                        'ingredient_id' => $ing->child_product_id,
                        'ingredient_name' => $ing->ingredient_name,
                        'error' => $result->get_error_message()
                    );
                } else {
                    $results[] = array(
                        'ingredient_id' => $ing->child_product_id,
                        'ingredient_name' => $ing->ingredient_name,
                        'is_resource' => $is_resource,
                        'recipe_quantity' => $recipe_quantity,
                        'recipe_unit' => $ing->recipe_unit_name,
                        'quantity_deducted' => $consume_quantity,
                        'base_unit' => $ing->base_unit_name,
                        'batches_affected' => $result['batches_affected'] ?? 0,
                        'products_affected' => $result['products_affected'] ?? null
                    );
                }
            }

            // Se houver erros críticos, fazer rollback
            if (!empty($errors)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('production_failed', 'Produção abortada: erros na baixa de ingredientes', array(
                    'status' => 400,
                    'errors' => $errors,
                    'partial_results' => $results
                ));
            }

            $wpdb->query('COMMIT');

            return array(
                'success' => true,
                'product_id' => $product_id,
                'quantity_sold' => $quantity_sold,
                'ingredients_deducted' => count($results),
                'details' => $results
            );

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log("SISTUR Recipe Error: " . $e->getMessage());
            return new WP_Error('transaction_error', $e->getMessage(), array('status' => 500));
        }
    }

    /**
     * Obtém o estoque disponível de um produto
     * 
     * @param int $product_id
     * @return float
     */
    private function get_available_stock($product_id)
    {
        global $wpdb;

        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(quantity), 0) 
             FROM {$this->prefix}inventory_batches 
             WHERE product_id = %d AND status = 'active' AND quantity > 0",
            $product_id
        ));
    }

    /**
     * Busca produtos filhos de um RESOURCE (v2.4)
     * 
     * @param int $resource_id ID do produto RESOURCE
     * @return array Lista de produtos filhos com estoque
     */
    private function get_child_products($resource_id)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, u.symbol as unit_symbol, u.name as unit_name
             FROM {$this->prefix}products p
             LEFT JOIN {$this->prefix}units u ON p.base_unit_id = u.id
             WHERE p.resource_parent_id = %d 
             AND p.status = 'active'",
            $resource_id
        ));
    }

    /**
     * Calcula estoque disponível agregado dos filhos de um RESOURCE (v2.4)
     * Normaliza para a unidade base do RESOURCE pai
     * 
     * @param int $resource_id ID do RESOURCE
     * @param int $resource_base_unit_id Unidade base do RESOURCE
     * @return float Estoque total normalizado
     */
    private function get_available_stock_from_children($resource_id, $resource_base_unit_id)
    {
        $converter = Sistur_Unit_Converter::get_instance();
        $children = $this->get_child_products($resource_id);
        $total_stock = 0;

        foreach ($children as $child) {
            $child_stock = $this->get_available_stock($child->id);

            // Converter para unidade do RESOURCE se diferente
            if ($child->base_unit_id && $child->base_unit_id != $resource_base_unit_id) {
                $converted = $converter->convert($child_stock, $child->base_unit_id, $resource_base_unit_id, $child->id);
                if (!is_wp_error($converted)) {
                    $child_stock = $converted;
                } else {
                    error_log("SISTUR RESOURCE: Erro ao converter estoque do filho {$child->id}: " . $converted->get_error_message());
                }
            }

            $total_stock += $child_stock;
        }

        return $total_stock;
    }

    /**
     * Processa saída de um RESOURCE consumindo lotes dos filhos (v2.4)
     * Ordena lotes de TODOS os filhos por validade (FIFO global)
     * 
     * @param int $resource_id ID do RESOURCE
     * @param float $quantity Quantidade a consumir (na unidade do RESOURCE)
     * @param string $reason Motivo da baixa
     * @param int $resource_unit_id Unidade base do RESOURCE
     * @param int $user_id ID do usuário
     * @return array|WP_Error
     */
    private function process_resource_exit($resource_id, $quantity, $reason, $resource_unit_id, $user_id)
    {
        global $wpdb;
        $converter = Sistur_Unit_Converter::get_instance();

        // Buscar lotes de TODOS os filhos ordenados por validade
        // Inclui content_quantity e content_unit_id para conversão correta
        $batches = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, p.id as child_product_id, p.base_unit_id as child_unit_id, 
                    p.name as product_name, u.symbol as unit_symbol,
                    p.content_quantity, p.content_unit_id,
                    uc.symbol as content_unit_symbol
             FROM {$this->prefix}inventory_batches b
             INNER JOIN {$this->prefix}products p ON b.product_id = p.id
             LEFT JOIN {$this->prefix}units u ON p.base_unit_id = u.id
             LEFT JOIN {$this->prefix}units uc ON p.content_unit_id = uc.id
             WHERE p.resource_parent_id = %d
             AND b.status = 'active'
             AND b.quantity > 0
             ORDER BY COALESCE(b.expiry_date, '9999-12-31') ASC, b.created_at ASC",
            $resource_id
        ));

        if (empty($batches)) {
            return new WP_Error('no_batches', 'Nenhum lote disponível nos produtos vinculados', array('status' => 400));
        }

        $remaining = $quantity; // Quantidade em unidade do RESOURCE (ex: kg)
        $batches_affected = 0;
        $total_cost_deducted = 0;
        $products_updated = array();

        foreach ($batches as $batch) {
            if ($remaining <= 0)
                break;

            // Obter content_quantity e content_unit_id do filho
            $content_qty = (float) ($batch->content_quantity ?: 1);
            $content_unit_id = (int) ($batch->content_unit_id ?: $resource_unit_id);

            // 1. Converter quantidade restante (em unidade RESOURCE) para content_unit do filho
            $remaining_in_content_unit = $remaining;
            if ($content_unit_id && $content_unit_id != $resource_unit_id) {
                $converted = $converter->convert($remaining, $resource_unit_id, $content_unit_id, $batch->child_product_id);
                if (!is_wp_error($converted)) {
                    $remaining_in_content_unit = $converted;
                }
            }

            // 2. Calcular quantos lotes (pacotes/unidades) precisamos consumir
            // Ex: 100g (remaining_in_content_unit) / 1000g (content_qty) = 0.1 pacotes
            $remaining_in_batch_units = $remaining_in_content_unit / $content_qty;

            $to_consume = min((float) $batch->quantity, $remaining_in_batch_units);
            $new_quantity = (float) $batch->quantity - $to_consume;

            // 3. Converter quantidade consumida de volta para unidade do RESOURCE
            // Ex: 0.1 pacotes * 1000g = 100g, depois converter 100g -> 0.1kg
            $consumed_in_content_unit = $to_consume * $content_qty;
            $consumed_in_resource_unit = $consumed_in_content_unit;
            if ($content_unit_id && $content_unit_id != $resource_unit_id) {
                $converted = $converter->convert($consumed_in_content_unit, $content_unit_id, $resource_unit_id, $batch->child_product_id);
                if (!is_wp_error($converted)) {
                    $consumed_in_resource_unit = $converted;
                }
            }

            error_log(sprintf(
                "SISTUR RESOURCE FIFO: Lote %d (%s) - consumindo %.4f %s (%.3f g) de %.3f lotes disponíveis [content_qty=%.3f]",
                $batch->id,
                $batch->product_name,
                $to_consume,
                $batch->unit_symbol ?: 'un',
                $consumed_in_content_unit,
                $batch->quantity,
                $content_qty
            ));

            // Atualizar lote
            $update_data = array('quantity' => $new_quantity);
            if ($new_quantity <= 0) {
                $update_data['status'] = 'depleted';
            }

            $wpdb->update(
                $this->prefix . 'inventory_batches',
                $update_data,
                array('id' => $batch->id)
            );

            // Registrar transação
            $batch_cost = (float) $batch->cost_price;
            $line_total = $to_consume * $batch_cost;
            $total_cost_deducted += $line_total;

            $wpdb->insert($this->prefix . 'inventory_transactions', array(
                'product_id' => $batch->child_product_id,
                'batch_id' => (int) $batch->id,
                'user_id' => (int) $user_id,
                'type' => 'PRODUCTION_OUT',
                'quantity' => -$to_consume,
                'unit_cost' => $batch_cost,
                'total_cost' => $line_total,
                'reason' => substr($reason . " (via RESOURCE ID: {$resource_id})", 0, 500),
                'reference_type' => 'resource_deduction',
                'ip_address' => $this->get_client_ip()
            ));

            // Rastrear produtos atualizados
            $products_updated[$batch->child_product_id] = true;

            $remaining -= $consumed_in_resource_unit;
            $batches_affected++;
        }

        // Atualizar cached_stock de todos os produtos afetados
        foreach (array_keys($products_updated) as $pid) {
            $this->update_cached_stock($pid);
        }

        return array(
            'total_deducted' => $quantity - $remaining,
            'batches_affected' => $batches_affected,
            'total_cost' => $total_cost_deducted,
            'products_affected' => array_keys($products_updated)
        );
    }

    /**
     * Processa saída de estoque de um ingrediente via FIFO
     * 
     * @param int $product_id ID do produto
     * @param float $quantity Quantidade a consumir (na unidade base)
     * @param string $reason Motivo da baixa
     * @param int $unit_id ID da unidade base (para registro)
     * @param int $user_id ID do usuário responsável
     * @return array|WP_Error
     */
    private function process_ingredient_exit($product_id, $quantity, $reason, $unit_id = null, $user_id = null)
    {
        global $wpdb;

        // Validar parâmetros obrigatórios
        $product_id = (int) $product_id;
        $quantity = (float) $quantity;

        if (!$product_id) {
            error_log("SISTUR process_ingredient_exit: product_id inválido");
            return new WP_Error('invalid_product', 'Product ID inválido', array('status' => 400));
        }

        if ($quantity <= 0) {
            error_log("SISTUR process_ingredient_exit: quantidade inválida - $quantity");
            return new WP_Error('invalid_quantity', 'Quantidade deve ser maior que zero', array('status' => 400));
        }

        // Garantir user_id válido
        if (!$user_id) {
            $user_id = get_current_user_id() ?: 1;
        }

        // Buscar lotes disponíveis ordenados por data (FIFO)
        $batches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->prefix}inventory_batches 
             WHERE product_id = %d 
             AND status = 'active' 
             AND quantity > 0
             ORDER BY created_at ASC",
            $product_id
        ));

        if (empty($batches)) {
            error_log("SISTUR process_ingredient_exit: nenhum lote encontrado para produto $product_id");
            return new WP_Error('no_batches', 'Nenhum lote disponível para baixa', array('status' => 400));
        }

        $remaining = $quantity;
        $batches_affected = 0;
        $total_cost_deducted = 0;

        foreach ($batches as $batch) {
            if ($remaining <= 0)
                break;

            $to_consume = min((float) $batch->quantity, $remaining);
            $new_quantity = (float) $batch->quantity - $to_consume;

            // Debug: log antes do update
            error_log(sprintf(
                "SISTUR FIFO: Lote %d - consumindo %.3f de %.3f (restante: %.3f)",
                $batch->id,
                $to_consume,
                $batch->quantity,
                $new_quantity
            ));

            // Atualizar lote
            $update_data = array('quantity' => $new_quantity);
            if ($new_quantity <= 0) {
                $update_data['status'] = 'depleted';
            }

            $update_result = $wpdb->update(
                $this->prefix . 'inventory_batches',
                $update_data,
                array('id' => $batch->id),
                array('%f', '%s'),
                array('%d')
            );

            if ($update_result === false) {
                error_log("SISTUR process_ingredient_exit: falha ao atualizar lote " . $batch->id);
                error_log("MySQL Error: " . $wpdb->last_error);
                continue;
            }

            // Calcular custo
            $batch_cost = (float) $batch->cost_price;
            $line_total = $to_consume * $batch_cost;
            $total_cost_deducted += $line_total;

            // Preparar dados da transação - validar todos os campos
            $transaction_data = array(
                'product_id' => $product_id,
                'batch_id' => (int) $batch->id,
                'user_id' => (int) $user_id,
                'type' => 'PRODUCTION_OUT',
                'quantity' => -$to_consume,
                'unit_cost' => $batch_cost,
                'total_cost' => $line_total,
                'reason' => substr($reason, 0, 500), // Limitar tamanho
                'reference_type' => 'recipe_deduction',
                'ip_address' => $this->get_client_ip()
            );

            // Debug: log dados da transação
            error_log("SISTUR Transaction Data: " . json_encode($transaction_data));

            // Registrar transação
            $insert_result = $wpdb->insert(
                $this->prefix . 'inventory_transactions',
                $transaction_data,
                array('%d', '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s')
            );

            if ($insert_result === false) {
                error_log("SISTUR process_ingredient_exit: falha ao inserir transação");
                error_log("MySQL Error: " . $wpdb->last_error);
                error_log("Query: " . $wpdb->last_query);
            } else {
                error_log("SISTUR Transaction inserida com ID: " . $wpdb->insert_id);
            }

            $remaining -= $to_consume;
            $batches_affected++;
        }

        // Atualizar cached_stock
        $this->update_cached_stock($product_id);

        return array(
            'total_deducted' => $quantity - $remaining,
            'batches_affected' => $batches_affected,
            'total_cost' => $total_cost_deducted
        );
    }

    /**
     * Obtém IP do cliente de forma segura
     */
    private function get_client_ip()
    {
        $ip = null;
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip ? substr($ip, 0, 45) : null;
    }

    /**
     * Atualiza o estoque cacheado do produto
     * 
     * @param int $product_id
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
     * Lista os ingredientes de uma receita
     * 
     * @param int $product_id
     * @return array
     */
    public function get_recipe_ingredients($product_id)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, 
                    p.name as ingredient_name,
                    p.sku as ingredient_sku,
                    p.type as ingredient_type,
                    p.average_cost,
                    p.cost_price,
                    p.base_unit_id,
                    -- Estoque disponível convertido para unidade da receita
                    (CASE 
                        WHEN p.type = 'RESOURCE' THEN (
                            SELECT COALESCE(SUM(
                                b.quantity * COALESCE(child.content_quantity, 1) 
                                * COALESCE(
                                    (SELECT uc.factor FROM {$this->prefix}unit_conversions uc 
                                     WHERE uc.from_unit_id = child.content_unit_id 
                                     AND uc.to_unit_id = p.base_unit_id 
                                     AND uc.product_id IS NULL LIMIT 1),
                                    1
                                )
                            ), 0)
                            FROM {$this->prefix}inventory_batches b 
                            INNER JOIN {$this->prefix}products child ON b.product_id = child.id 
                            WHERE child.resource_parent_id = p.id 
                            AND b.status = 'active'
                            AND b.quantity > 0
                        )
                        ELSE COALESCE((SELECT SUM(b.quantity) FROM {$this->prefix}inventory_batches b WHERE b.product_id = p.id AND b.status = 'active'), 0)
                    END) 
                    * COALESCE(
                        (SELECT uc2.factor FROM {$this->prefix}unit_conversions uc2 
                         WHERE uc2.from_unit_id = p.base_unit_id 
                         AND uc2.to_unit_id = r.unit_id 
                         AND uc2.product_id IS NULL LIMIT 1),
                        1
                    ) as available_stock,
                    u.name as unit_name,
                    u.symbol as unit_symbol
             FROM {$this->prefix}recipes r
             INNER JOIN {$this->prefix}products p ON r.child_product_id = p.id
             LEFT JOIN {$this->prefix}units u ON r.unit_id = u.id
             WHERE r.parent_product_id = %d
             ORDER BY p.name ASC",
            (int) $product_id
        ));
    }

    /**
     * Adiciona um ingrediente à receita
     * 
     * @param array $data
     * @return int|WP_Error ID do registro ou erro
     */
    public function add_ingredient($data)
    {
        global $wpdb;

        // Validações
        if (empty($data['parent_product_id']) || empty($data['child_product_id'])) {
            return new WP_Error('invalid_data', 'parent_product_id e child_product_id são obrigatórios', array('status' => 400));
        }

        if (empty($data['quantity_net']) || $data['quantity_net'] <= 0) {
            return new WP_Error('invalid_quantity', 'quantity_net deve ser maior que zero', array('status' => 400));
        }

        $parent_id = (int) $data['parent_product_id'];
        $child_id = (int) $data['child_product_id'];

        // Não permitir produto referenciando a si mesmo
        if ($parent_id === $child_id) {
            return new WP_Error('self_reference', 'Produto não pode ser ingrediente de si mesmo', array('status' => 400));
        }

        // Verificar se já existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->prefix}recipes 
             WHERE parent_product_id = %d AND child_product_id = %d",
            $parent_id,
            $child_id
        ));

        if ($exists) {
            return new WP_Error('duplicate', 'Este ingrediente já está na receita', array('status' => 400));
        }

        // Calcular quantity_gross
        $quantity_net = (float) $data['quantity_net'];
        $yield_factor = isset($data['yield_factor']) && $data['yield_factor'] > 0
            ? (float) $data['yield_factor']
            : 1.0;
        $quantity_gross = $quantity_net / $yield_factor;

        // Validar unidade - NÃO permitir unidades on-the-fly
        $unit_id = null;
        if (isset($data['unit_id']) && !empty($data['unit_id'])) {
            $converter = Sistur_Unit_Converter::get_instance();
            $unit_valid = $converter->validate_unit($data['unit_id']);

            if (is_wp_error($unit_valid)) {
                return new WP_Error(
                    'invalid_unit',
                    'Unidade de medida inválida. Apenas unidades cadastradas são permitidas.',
                    array('status' => 400)
                );
            }

            $unit_id = (int) $data['unit_id'];
        }

        $result = $wpdb->insert($this->prefix . 'recipes', array(
            'parent_product_id' => $parent_id,
            'child_product_id' => $child_id,
            'quantity_net' => round($quantity_net, 3),
            'yield_factor' => round($yield_factor, 2),
            'quantity_gross' => round($quantity_gross, 3),
            'unit_id' => $unit_id,
            'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : null
        ));

        if ($result === false) {
            return new WP_Error('db_error', 'Erro ao adicionar ingrediente', array('status' => 500));
        }

        return $wpdb->insert_id;
    }

    /**
     * Atualiza um ingrediente da receita
     * 
     * @param int $id
     * @param array $data
     * @return bool|WP_Error
     */
    public function update_ingredient($id, $data)
    {
        global $wpdb;

        $id = (int) $id;

        // Verificar se existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->prefix}recipes WHERE id = %d",
            $id
        ));

        if (!$exists) {
            return new WP_Error('not_found', 'Ingrediente não encontrado', array('status' => 404));
        }

        $update_data = array();

        if (isset($data['quantity_net']) && $data['quantity_net'] > 0) {
            $update_data['quantity_net'] = round((float) $data['quantity_net'], 3);
        }

        if (isset($data['yield_factor']) && $data['yield_factor'] > 0) {
            $update_data['yield_factor'] = round((float) $data['yield_factor'], 2);
        }

        if (isset($data['unit_id'])) {
            $update_data['unit_id'] = (int) $data['unit_id'];
        }

        if (isset($data['notes'])) {
            $update_data['notes'] = sanitize_textarea_field($data['notes']);
        }

        // Recalcular quantity_gross se necessário
        if (isset($update_data['quantity_net']) || isset($update_data['yield_factor'])) {
            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT quantity_net, yield_factor FROM {$this->prefix}recipes WHERE id = %d",
                $id
            ));

            $net = isset($update_data['quantity_net']) ? $update_data['quantity_net'] : $current->quantity_net;
            $factor = isset($update_data['yield_factor']) ? $update_data['yield_factor'] : $current->yield_factor;
            $update_data['quantity_gross'] = round($net / $factor, 3);
        }

        if (empty($update_data)) {
            return new WP_Error('no_data', 'Nenhum dado para atualizar', array('status' => 400));
        }

        $result = $wpdb->update($this->prefix . 'recipes', $update_data, array('id' => $id));

        if ($result === false) {
            return new WP_Error('db_error', 'Erro ao atualizar ingrediente', array('status' => 500));
        }

        return true;
    }

    /**
     * Remove um ingrediente da receita
     * (Não exclui o produto, apenas o vínculo)
     * 
     * @param int $id
     * @return bool|WP_Error
     */
    public function remove_ingredient($id)
    {
        global $wpdb;

        $id = (int) $id;

        // Verificar se existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->prefix}recipes WHERE id = %d",
            $id
        ));

        if (!$exists) {
            return new WP_Error('not_found', 'Ingrediente não encontrado', array('status' => 404));
        }

        $result = $wpdb->delete($this->prefix . 'recipes', array('id' => $id));

        if ($result === false) {
            return new WP_Error('db_error', 'Erro ao remover ingrediente', array('status' => 500));
        }

        return true;
    }

    /**
     * Atualiza o custo de um produto baseado na sua receita
     * 
     * Útil para manter o cost_price do prato atualizado
     * automaticamente quando os custos dos ingredientes mudam.
     * 
     * @param int $product_id
     * @return bool|WP_Error
     */
    public function update_product_cost_from_recipe($product_id)
    {
        global $wpdb;

        $cost_data = $this->calculate_plate_cost($product_id);

        if (is_wp_error($cost_data)) {
            return $cost_data;
        }

        if ($cost_data['total_cost'] > 0) {
            $wpdb->update(
                $this->prefix . 'products',
                array('cost_price' => $cost_data['total_cost']),
                array('id' => (int) $product_id)
            );
        }

        return true;
    }

    /**
     * Recalcula o custo em cascata para todos os pratos que usam um produto como ingrediente.
     *
     * Quando o preço de um insumo muda (nova compra ou ajuste), este método propaga
     * a atualização de cost_price para todos os Pratos BASE e Pratos Finais que
     * dependem dele, subindo recursivamente na árvore de receitas.
     *
     * Ordem de execução:
     *   1. Encontra todos os pratos que têm $changed_product_id como ingrediente.
     *   2. Para cada prato pai, recalcula o cost_price via calculate_plate_cost().
     *   3. Sobe recursivamente para os pais do pai (detecção de ciclos via $visited).
     *
     * @param int   $changed_product_id ID do produto cujo custo mudou
     * @param array $visited            IDs já visitados (evita loops em receitas circulares)
     * @return void
     */
    public function cascade_cost_recalculation($changed_product_id, &$visited = [])
    {
        global $wpdb;

        $changed_product_id = (int) $changed_product_id;

        // Proteção contra dependências circulares
        if (in_array($changed_product_id, $visited)) {
            return;
        }
        $visited[] = $changed_product_id;

        // Localizar todos os pratos que usam este produto como ingrediente
        $parent_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT parent_product_id
             FROM {$this->prefix}recipes
             WHERE child_product_id = %d",
            $changed_product_id
        ));

        if (empty($parent_ids)) {
            return;
        }

        foreach ($parent_ids as $parent_id) {
            $parent_id = (int) $parent_id;

            // Recalcular cost_price do prato pai com base na receita atual
            $this->update_product_cost_from_recipe($parent_id);

            error_log(sprintf(
                "SISTUR CMV Cascade: Custo do produto ID %d atualizado (disparado por mudança no ID %d)",
                $parent_id,
                $changed_product_id
            ));

            // Propagar para cima (pratos que usam este prato pai como ingrediente)
            $this->cascade_cost_recalculation($parent_id, $visited);
        }
    }

    /**
     * Produz um produto MANUFACTURED: consome ingredientes e cria lote do produto final (v2.5)
     *
     * @param int $product_id ID do produto MANUFACTURED
     * @param float $quantity Quantidade a produzir
     * @param string $notes Observações opcionais
     * @return array|WP_Error Resultado da produção
     */
    public function produce($product_id, $quantity, $notes = '')
    {
        global $wpdb;

        $product_id = (int) $product_id;
        $quantity = abs((float) $quantity);

        if ($quantity <= 0) {
            return new WP_Error('invalid_quantity', 'Quantidade deve ser maior que zero', array('status' => 400));
        }

        // 1. Verificar se o produto existe e é MANUFACTURED
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, u.symbol as unit_symbol, u.name as unit_name
             FROM {$this->prefix}products p
             LEFT JOIN {$this->prefix}units u ON p.base_unit_id = u.id
             WHERE p.id = %d",
            $product_id
        ));

        if (!$product) {
            return new WP_Error('product_not_found', 'Produto não encontrado', array('status' => 404));
        }

        // v2.6 - Verificar se MANUFACTURED está no tipo (suporte a SET como 'RAW,MANUFACTURED')
        if (strpos($product->type, 'MANUFACTURED') === false) {
            return new WP_Error('not_manufactured', 'Apenas produtos do tipo PRODUZIDO podem ser manufaturados', array('status' => 400));
        }

        // 2. Verificar se tem receita
        $recipe_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->prefix}recipes WHERE parent_product_id = %d",
            $product_id
        ));

        if ($recipe_count === 0) {
            return new WP_Error('no_recipe', 'Produto não possui receita cadastrada. Adicione ingredientes primeiro.', array('status' => 400));
        }

        // 3. Consumir ingredientes (usa a lógica existente)
        $deduction_reason = sprintf('Produção de %s× %s', $quantity, $product->name);
        if ($notes) {
            $deduction_reason .= ' - ' . $notes;
        }

        $deduction_result = $this->deduct_recipe_stock($product_id, $quantity, $deduction_reason);

        if (is_wp_error($deduction_result)) {
            return $deduction_result;
        }

        // 4. Calcular custo total dos ingredientes consumidos
        // Usa custo médio móvel incluindo entradas diretas (IN) e produções (PRODUCTION_IN).
        // PRODUCTION_IN cobre Pratos BASE que foram produzidos (não comprados diretamente).
        $total_ingredient_cost = 0;
        if (!empty($deduction_result['details'])) {
            foreach ($deduction_result['details'] as $detail) {
                $ing_cost = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(
                        SUM(ABS(quantity) * unit_cost) / NULLIF(SUM(ABS(quantity)), 0),
                        0
                     )
                     FROM {$this->prefix}inventory_transactions
                     WHERE product_id = %d AND type IN ('IN', 'PRODUCTION_IN') AND unit_cost > 0",
                    $detail['ingredient_id']
                ));
                $total_ingredient_cost += $ing_cost * $detail['quantity_deducted'];
            }
        }

        // Custo por unidade produzida
        $unit_cost = $quantity > 0 ? $total_ingredient_cost / $quantity : 0;

        // 5. Criar lote de entrada do produto produzido
        $user_id = get_current_user_id() ?: 1;
        $batch_data = array(
            'product_id' => $product_id,
            'quantity' => $quantity,
            'initial_quantity' => $quantity,
            'cost_price' => round($unit_cost, 4),
            'expiry_date' => null, // Produzidos geralmente não têm validade fixa
            'batch_code' => 'PROD-' . date('Ymd-His') . '-' . $product_id,
            'status' => 'active',
            'notes' => $deduction_reason,
            'created_at' => current_time('mysql')
            // Nota: created_by não existe na tabela inventory_batches
        );

        $wpdb->insert($this->prefix . 'inventory_batches', $batch_data);
        $batch_id = $wpdb->insert_id;

        if (!$batch_id) {
            error_log("SISTUR Production Error: Failed to create batch for product $product_id");
            return new WP_Error('batch_creation_failed', 'Falha ao criar lote do produto produzido', array('status' => 500));
        }

        // 6. Registrar movimentação de entrada
        $wpdb->insert($this->prefix . 'inventory_movements', array(
            'product_id' => $product_id,
            'batch_id' => $batch_id,
            'type' => 'PRODUCTION_IN',
            'quantity' => $quantity,
            'unit_id' => $product->base_unit_id,
            'reason' => $deduction_reason,
            'reference_type' => 'production',
            'reference_id' => $product_id,
            'user_id' => $user_id,
            'created_at' => current_time('mysql')
        ));

        // 7. Atualizar cached_stock do produto
        $new_stock = $this->get_available_stock($product_id);

        // 8. Custo médio móvel incluindo entradas diretas (IN) e produções (PRODUCTION_IN).
        // Pratos BASE produzidos geram PRODUCTION_IN, que deve entrar no custo médio.
        $weighted_avg_cost = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(
                SUM(ABS(quantity) * unit_cost) / NULLIF(SUM(ABS(quantity)), 0),
                0
             )
             FROM {$this->prefix}inventory_transactions
             WHERE product_id = %d AND type IN ('IN', 'PRODUCTION_IN') AND unit_cost > 0",
            $product_id
        ));

        // 9. Atualizar produto: cached_stock, cost_price e average_cost
        $wpdb->update(
            $this->prefix . 'products',
            array(
                'cached_stock' => $new_stock,
                'cost_price' => round($unit_cost, 4),
                'average_cost' => round($weighted_avg_cost, 4)
            ),
            array('id' => $product_id)
        );

        error_log("SISTUR Production: Produced $quantity x {$product->name} (batch $batch_id, cost R$ $unit_cost)");

        return array(
            'success' => true,
            'product_id' => $product_id,
            'product_name' => $product->name,
            'quantity_produced' => $quantity,
            'unit' => $product->unit_symbol ?: $product->unit_name,
            'batch_id' => $batch_id,
            'batch_code' => $batch_data['batch_code'],
            'unit_cost' => round($unit_cost, 4),
            'total_cost' => round($total_ingredient_cost, 2),
            'ingredients_consumed' => count($deduction_result['details']),
            'ingredients_details' => $deduction_result['details'],
            'new_stock' => $new_stock
        );
    }
}
