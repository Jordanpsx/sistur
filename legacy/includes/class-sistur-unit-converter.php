<?php
/**
 * Serviço de Conversão de Unidades
 * 
 * Responsável por converter valores entre unidades de medida
 * usando a tabela sistur_unit_conversions.
 * 
 * Regras:
 * - Busca conversão específica por produto primeiro
 * - Se não encontrar, busca conversão global (product_id = NULL)
 * - Se não existir conversão, retorna erro explícito
 *
 * @package SISTUR
 * @subpackage Stock
 * @since 2.2.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sistur_Unit_Converter {

    /**
     * Instância única (Singleton)
     */
    private static $instance = null;

    /**
     * Prefixo das tabelas
     */
    private $prefix;

    /**
     * Cache de conversões para evitar consultas repetidas
     */
    private $conversion_cache = array();

    /**
     * Construtor privado
     */
    private function __construct() {
        global $wpdb;
        $this->prefix = $wpdb->prefix . 'sistur_';
    }

    /**
     * Retorna instância única
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Converte um valor de uma unidade para outra
     * 
     * @param float $value Valor a ser convertido
     * @param int $from_unit_id ID da unidade de origem
     * @param int $to_unit_id ID da unidade de destino
     * @param int|null $product_id ID do produto (para conversões específicas)
     * @return float|WP_Error Valor convertido ou erro
     */
    public function convert($value, $from_unit_id, $to_unit_id, $product_id = null) {
        global $wpdb;

        $value = (float) $value;
        $from_unit_id = (int) $from_unit_id;
        $to_unit_id = (int) $to_unit_id;

        // Se as unidades são iguais, não há conversão
        if ($from_unit_id === $to_unit_id) {
            return $value;
        }

        // Validar que ambas as unidades existem
        $from_unit = $this->get_unit($from_unit_id);
        $to_unit = $this->get_unit($to_unit_id);

        if (!$from_unit) {
            return new WP_Error(
                'invalid_from_unit',
                sprintf('Unidade de origem (ID: %d) não existe no sistema.', $from_unit_id),
                array('status' => 400)
            );
        }

        if (!$to_unit) {
            return new WP_Error(
                'invalid_to_unit',
                sprintf('Unidade de destino (ID: %d) não existe no sistema.', $to_unit_id),
                array('status' => 400)
            );
        }

        // Buscar fator de conversão
        $factor = $this->get_conversion_factor($from_unit_id, $to_unit_id, $product_id);

        if (is_wp_error($factor)) {
            return $factor;
        }

        if ($factor === null) {
            // Montar mensagem de erro detalhada
            $product_name = '';
            if ($product_id) {
                $product = $wpdb->get_row($wpdb->prepare(
                    "SELECT name FROM {$this->prefix}products WHERE id = %d",
                    $product_id
                ));
                $product_name = $product ? $product->name : "ID $product_id";
            }

            return new WP_Error(
                'conversion_not_found',
                sprintf(
                    'Erro: Conversão de unidade %s para %s não definida%s.',
                    $from_unit->name,
                    $to_unit->name,
                    $product_id ? " para o produto '$product_name'" : ''
                ),
                array(
                    'status' => 400,
                    'from_unit' => $from_unit->name,
                    'to_unit' => $to_unit->name,
                    'product_id' => $product_id
                )
            );
        }

        return $value * $factor;
    }

    /**
     * Busca o fator de conversão entre duas unidades
     * 
     * @param int $from_unit_id
     * @param int $to_unit_id
     * @param int|null $product_id
     * @return float|null|WP_Error Fator, null se não encontrado, ou erro
     */
    public function get_conversion_factor($from_unit_id, $to_unit_id, $product_id = null) {
        global $wpdb;

        // Verificar cache
        $cache_key = "{$from_unit_id}_{$to_unit_id}_{$product_id}";
        if (isset($this->conversion_cache[$cache_key])) {
            return $this->conversion_cache[$cache_key];
        }

        $factor = null;

        // 1. Buscar conversão específica para o produto
        if ($product_id) {
            $factor = $wpdb->get_var($wpdb->prepare(
                "SELECT factor FROM {$this->prefix}unit_conversions 
                 WHERE from_unit_id = %d AND to_unit_id = %d AND product_id = %d",
                $from_unit_id, $to_unit_id, $product_id
            ));
        }

        // 2. Se não encontrou, buscar conversão global
        if ($factor === null) {
            $factor = $wpdb->get_var($wpdb->prepare(
                "SELECT factor FROM {$this->prefix}unit_conversions 
                 WHERE from_unit_id = %d AND to_unit_id = %d AND product_id IS NULL",
                $from_unit_id, $to_unit_id
            ));
        }

        // 3. Cachear resultado (mesmo que null)
        $this->conversion_cache[$cache_key] = $factor !== null ? (float) $factor : null;

        return $this->conversion_cache[$cache_key];
    }

    /**
     * Verifica se existe conversão entre duas unidades
     * 
     * @param int $from_unit_id
     * @param int $to_unit_id
     * @param int|null $product_id
     * @return bool
     */
    public function has_conversion($from_unit_id, $to_unit_id, $product_id = null) {
        if ($from_unit_id === $to_unit_id) {
            return true;
        }

        $factor = $this->get_conversion_factor($from_unit_id, $to_unit_id, $product_id);
        return $factor !== null && !is_wp_error($factor);
    }

    /**
     * Obtém uma unidade pelo ID
     * 
     * @param int $unit_id
     * @return object|null
     */
    public function get_unit($unit_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->prefix}units WHERE id = %d",
            (int) $unit_id
        ));
    }

    /**
     * Valida se uma unidade existe no sistema
     * 
     * @param int $unit_id
     * @return bool
     */
    public function unit_exists($unit_id) {
        return $this->get_unit($unit_id) !== null;
    }

    /**
     * Valida que uma unidade é válida para uso
     * Impede unidades "on-the-fly"
     * 
     * @param int $unit_id
     * @return true|WP_Error
     */
    public function validate_unit($unit_id) {
        if (empty($unit_id)) {
            return new WP_Error(
                'unit_required',
                'Unidade de medida é obrigatória.',
                array('status' => 400)
            );
        }

        if (!$this->unit_exists($unit_id)) {
            return new WP_Error(
                'invalid_unit',
                sprintf('Unidade de medida (ID: %d) não existe no sistema. Unidades devem ser cadastradas antes do uso.', $unit_id),
                array('status' => 400)
            );
        }

        return true;
    }

    /**
     * Converte quantidade da receita para unidade base do ingrediente
     * 
     * @param float $quantity Quantidade na unidade da receita
     * @param int $recipe_unit_id Unidade usada na receita
     * @param int $ingredient_product_id ID do produto ingrediente
     * @return float|WP_Error Quantidade na unidade base do ingrediente
     */
    public function convert_to_ingredient_base_unit($quantity, $recipe_unit_id, $ingredient_product_id) {
        global $wpdb;

        // Buscar unidade base do ingrediente
        $ingredient = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, base_unit_id FROM {$this->prefix}products WHERE id = %d",
            $ingredient_product_id
        ));

        if (!$ingredient) {
            return new WP_Error(
                'ingredient_not_found',
                sprintf('Ingrediente (ID: %d) não encontrado.', $ingredient_product_id),
                array('status' => 404)
            );
        }

        // Se ingrediente não tem unidade base definida, usar a da receita
        if (empty($ingredient->base_unit_id)) {
            error_log("SISTUR: Ingrediente '{$ingredient->name}' sem unidade base definida. Usando unidade da receita.");
            return $quantity;
        }

        // Converter da unidade da receita para a unidade base do ingrediente
        $converted = $this->convert(
            $quantity,
            $recipe_unit_id,
            $ingredient->base_unit_id,
            $ingredient_product_id
        );

        if (is_wp_error($converted)) {
            // Adicionar nome do ingrediente ao erro
            $error_data = $converted->get_error_data();
            $error_data['ingredient_name'] = $ingredient->name;
            
            return new WP_Error(
                $converted->get_error_code(),
                str_replace(
                    'para o produto',
                    "para o ingrediente '{$ingredient->name}'",
                    $converted->get_error_message()
                ),
                $error_data
            );
        }

        return $converted;
    }

    /**
     * Limpa o cache de conversões
     */
    public function clear_cache() {
        $this->conversion_cache = array();
    }
}
