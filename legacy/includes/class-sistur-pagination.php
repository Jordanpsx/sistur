<?php
/**
 * SISTUR Pagination Helper
 * Sistema de paginação para listagens
 *
 * @package SISTUR
 * @version 1.2.0
 */

class SISTUR_Pagination {

    /**
     * Calcular paginação
     *
     * @param int $total_items Total de itens
     * @param int $per_page Itens por página
     * @param int $current_page Página atual
     * @return array Dados de paginação
     */
    public static function calculate($total_items, $per_page = 20, $current_page = 1) {
        $total_pages = ceil($total_items / $per_page);
        $current_page = max(1, min($current_page, $total_pages));
        $offset = ($current_page - 1) * $per_page;

        return array(
            'total_items' => $total_items,
            'per_page' => $per_page,
            'current_page' => $current_page,
            'total_pages' => $total_pages,
            'offset' => $offset,
            'has_prev' => $current_page > 1,
            'has_next' => $current_page < $total_pages,
            'prev_page' => $current_page - 1,
            'next_page' => $current_page + 1,
            'start_item' => $offset + 1,
            'end_item' => min($offset + $per_page, $total_items)
        );
    }

    /**
     * Renderizar HTML de paginação
     *
     * @param array $pagination Dados de paginação
     * @return string HTML
     */
    public static function render($pagination) {
        if ($pagination['total_pages'] <= 1) {
            return '';
        }

        $current = $pagination['current_page'];
        $total = $pagination['total_pages'];

        $html = '<div class="sistur-pagination">';
        $html .= '<div class="sistur-pagination-info">';
        $html .= sprintf(
            'Mostrando %d-%d de %d itens',
            $pagination['start_item'],
            $pagination['end_item'],
            $pagination['total_items']
        );
        $html .= '</div>';

        $html .= '<div class="sistur-pagination-controls">';

        // Botão Anterior
        if ($pagination['has_prev']) {
            $html .= sprintf(
                '<button class="sistur-btn sistur-btn-secondary sistur-btn-sm sistur-pagination-btn" data-page="%d">← Anterior</button>',
                $pagination['prev_page']
            );
        } else {
            $html .= '<button class="sistur-btn sistur-btn-secondary sistur-btn-sm" disabled>← Anterior</button>';
        }

        // Números de página
        $html .= '<div class="sistur-pagination-numbers">';

        // Sempre mostrar primeira página
        if ($current > 3) {
            $html .= sprintf('<button class="sistur-btn sistur-btn-secondary sistur-btn-sm sistur-pagination-btn" data-page="1">1</button>');
            if ($current > 4) {
                $html .= '<span class="sistur-pagination-ellipsis">...</span>';
            }
        }

        // Páginas ao redor da atual
        for ($i = max(1, $current - 2); $i <= min($total, $current + 2); $i++) {
            $active_class = $i === $current ? 'sistur-btn-primary' : 'sistur-btn-secondary';
            $html .= sprintf(
                '<button class="sistur-btn %s sistur-btn-sm sistur-pagination-btn" data-page="%d">%d</button>',
                $active_class,
                $i,
                $i
            );
        }

        // Sempre mostrar última página
        if ($current < $total - 2) {
            if ($current < $total - 3) {
                $html .= '<span class="sistur-pagination-ellipsis">...</span>';
            }
            $html .= sprintf(
                '<button class="sistur-btn sistur-btn-secondary sistur-btn-sm sistur-pagination-btn" data-page="%d">%d</button>',
                $total,
                $total
            );
        }

        $html .= '</div>';

        // Botão Próximo
        if ($pagination['has_next']) {
            $html .= sprintf(
                '<button class="sistur-btn sistur-btn-secondary sistur-btn-sm sistur-pagination-btn" data-page="%d">Próximo →</button>',
                $pagination['next_page']
            );
        } else {
            $html .= '<button class="sistur-btn sistur-btn-secondary sistur-btn-sm" disabled>Próximo →</button>';
        }

        $html .= '</div>'; // pagination-controls
        $html .= '</div>'; // pagination

        return $html;
    }

    /**
     * Obter parâmetros de query string
     *
     * @return array
     */
    public static function get_query_params() {
        return array(
            'page' => isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1,
            'per_page' => isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 20,
            'search' => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '',
            'orderby' => isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'id',
            'order' => isset($_GET['order']) && in_array(strtoupper($_GET['order']), array('ASC', 'DESC')) ? strtoupper($_GET['order']) : 'DESC'
        );
    }
}
