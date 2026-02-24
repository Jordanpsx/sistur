<?php
/**
 * SISTUR Search & Filter Helper
 * Sistema de busca avançada e filtros
 *
 * @package SISTUR
 * @version 1.2.0
 */

class SISTUR_Search {

    /**
     * Construir query SQL com filtros
     *
     * @param string $base_query Query base
     * @param array $filters Filtros aplicados
     * @param array $allowed_fields Campos permitidos para busca
     * @return string Query SQL modificada
     */
    public static function build_search_query($base_query, $filters, $allowed_fields = array()) {
        global $wpdb;

        $conditions = array();
        $search = isset($filters['search']) ? sanitize_text_field($filters['search']) : '';

        // Busca por texto
        if (!empty($search) && !empty($allowed_fields)) {
            $search_conditions = array();
            $search_term = '%' . $wpdb->esc_like($search) . '%';

            foreach ($allowed_fields as $field) {
                $search_conditions[] = $wpdb->prepare("$field LIKE %s", $search_term);
            }

            $conditions[] = '(' . implode(' OR ', $search_conditions) . ')';
        }

        // Retornar query modificada
        if (!empty($conditions)) {
            $where_clause = implode(' AND ', $conditions);

            // Se já existe WHERE, adicionar com AND
            if (stripos($base_query, 'WHERE') !== false) {
                $base_query .= ' AND ' . $where_clause;
            } else {
                $base_query .= ' WHERE ' . $where_clause;
            }
        }

        return $base_query;
    }

    /**
     * Renderizar formulário de busca
     *
     * @param array $config Configuração do formulário
     * @return string HTML
     */
    public static function render_search_form($config = array()) {
        $defaults = array(
            'placeholder' => 'Buscar...',
            'show_per_page' => true,
            'show_filters' => false,
            'filters' => array()
        );

        $config = array_merge($defaults, $config);
        $current_search = isset($_GET['search']) ? esc_attr($_GET['search']) : '';
        $current_per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;

        ob_start();
        ?>
        <div class="sistur-search-container" style="margin-bottom: var(--sistur-space-6);">
            <form method="GET" class="sistur-search-form" style="display: flex; gap: var(--sistur-space-3); flex-wrap: wrap; align-items: center;">
                <!-- Preservar parâmetros existentes -->
                <?php foreach ($_GET as $key => $value): ?>
                    <?php if (!in_array($key, array('search', 'per_page', 'paged'))): ?>
                        <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>" />
                    <?php endif; ?>
                <?php endforeach; ?>

                <!-- Campo de busca -->
                <div style="flex: 1; min-width: 300px;">
                    <input
                        type="text"
                        name="search"
                        class="sistur-form-input"
                        placeholder="<?php echo esc_attr($config['placeholder']); ?>"
                        value="<?php echo $current_search; ?>"
                        style="margin: 0;"
                    />
                </div>

                <!-- Filtros adicionais -->
                <?php if ($config['show_filters'] && !empty($config['filters'])): ?>
                    <?php foreach ($config['filters'] as $filter): ?>
                        <div>
                            <select name="<?php echo esc_attr($filter['name']); ?>" class="sistur-form-input" style="margin: 0;">
                                <option value=""><?php echo esc_html($filter['label']); ?></option>
                                <?php foreach ($filter['options'] as $value => $label): ?>
                                    <option
                                        value="<?php echo esc_attr($value); ?>"
                                        <?php selected(isset($_GET[$filter['name']]) ? $_GET[$filter['name']] : '', $value); ?>
                                    >
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Items por página -->
                <?php if ($config['show_per_page']): ?>
                    <div>
                        <select name="per_page" class="sistur-form-input" style="margin: 0;">
                            <option value="10" <?php selected($current_per_page, 10); ?>>10 por página</option>
                            <option value="20" <?php selected($current_per_page, 20); ?>>20 por página</option>
                            <option value="50" <?php selected($current_per_page, 50); ?>>50 por página</option>
                            <option value="100" <?php selected($current_per_page, 100); ?>>100 por página</option>
                        </select>
                    </div>
                <?php endif; ?>

                <!-- Botões -->
                <div style="display: flex; gap: var(--sistur-space-2);">
                    <button type="submit" class="sistur-btn sistur-btn-primary">
                        <span class="dashicons dashicons-search" style="margin-right: 4px;"></span>
                        Buscar
                    </button>

                    <?php if (!empty($current_search) || isset($_GET['per_page'])): ?>
                        <a href="<?php echo esc_url(remove_query_arg(array('search', 'per_page', 'paged'))); ?>" class="sistur-btn sistur-btn-secondary">
                            Limpar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Aplicar ordenação na query
     *
     * @param string $query Query SQL
     * @param array $params Parâmetros (orderby, order)
     * @param array $allowed_fields Campos permitidos
     * @return string Query modificada
     */
    public static function apply_order($query, $params, $allowed_fields = array()) {
        $orderby = isset($params['orderby']) ? $params['orderby'] : 'id';
        $order = isset($params['order']) ? strtoupper($params['order']) : 'DESC';

        // Validar campo
        if (!in_array($orderby, $allowed_fields)) {
            $orderby = 'id';
        }

        // Validar direção
        if (!in_array($order, array('ASC', 'DESC'))) {
            $order = 'DESC';
        }

        $query .= " ORDER BY $orderby $order";

        return $query;
    }
}
