<?php
/**
 * SISTUR - Correção Temporária para Erro RCG
 *
 * Este plugin Must-Use remove hooks AJAX órfãos que causam erros fatais.
 *
 * PROBLEMA: Hook rcg_ajax_check_database registrado mas função não existe
 * SOLUÇÃO: Remove o hook antes que ele seja executado
 *
 * Para desativar: Delete este arquivo de wp-content/mu-plugins/
 *
 * @package SISTUR
 * @version 1.0.0
 */

// Executar o mais cedo possível
add_action('init', 'sistur_remove_orphan_rcg_hooks', 1);

/**
 * Remove hooks AJAX órfãos do RCG
 */
function sistur_remove_orphan_rcg_hooks() {
    global $wp_filter;

    // Lista completa de hooks órfãos RCG conhecidos
    $orphan_hooks = array(
        'wp_ajax_rcg_ajax_check_database',
        'wp_ajax_nopriv_rcg_ajax_check_database',
        'wp_ajax_rcg_update_status',
        'wp_ajax_nopriv_rcg_update_status',
        'wp_ajax_rcg_delete_reservation',
        'wp_ajax_nopriv_rcg_delete_reservation',
        'wp_ajax_rcg_check_database',
        'wp_ajax_nopriv_rcg_check_database',
        'wp_ajax_rcg_recreate_table',
        'wp_ajax_nopriv_rcg_recreate_table',
    );

    $removed_count = 0;

    foreach ($orphan_hooks as $hook) {
        // Remover do wp_filter se existir
        if (isset($wp_filter[$hook])) {
            unset($wp_filter[$hook]);
            $removed_count++;
            error_log("SISTUR FIX: Hook órfão removido: {$hook}");
        }

        // Remover das options se existir
        delete_option($hook);

        // Remover também todas as ações já registradas
        remove_all_actions($hook);
    }

    if ($removed_count > 0) {
        error_log("SISTUR FIX: Total de {$removed_count} hooks RCG órfãos removidos");
    }
}

// Também remover quando o admin_init for chamado (antes do admin-ajax.php)
add_action('admin_init', 'sistur_remove_orphan_rcg_hooks', 1);
add_action('wp_loaded', 'sistur_remove_orphan_rcg_hooks', 1);

// Log para debug
error_log('SISTUR FIX: Plugin de correção RCG carregado');

/**
 * Adicionar notice no admin
 */
add_action('admin_notices', 'sistur_rcg_fix_notice');

function sistur_rcg_fix_notice() {
    $screen = get_current_screen();

    // Só mostrar na página de diagnóstico
    if ($screen && strpos($screen->id, 'sistur-diagnostics') !== false) {
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>SISTUR:</strong> Plugin de correção RCG está ativo. ';
        echo 'Hooks órfãos estão sendo removidos automaticamente. ';
        echo 'Se o erro foi resolvido, você pode deletar o arquivo ';
        echo '<code>wp-content/mu-plugins/sistur-fix-rcg-error.php</code></p>';
        echo '</div>';
    }
}
