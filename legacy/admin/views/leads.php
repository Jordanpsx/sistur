<?php
/**
 * Página de Gerenciamento de Leads
 *
 * @package SISTUR
 */

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}

global $wpdb;
$leads_table = $wpdb->prefix . 'sistur_leads';

$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

$where = array('1=1');
$params = array();

if ($status_filter) {
    $where[] = 'status = %s';
    $params[] = $status_filter;
}

if ($search) {
    $where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s)';
    $search_term = '%' . $wpdb->esc_like($search) . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_clause = implode(' AND ', $where);
$sql = "SELECT * FROM $leads_table WHERE $where_clause ORDER BY created_at DESC LIMIT 50";

if (!empty($params)) {
    $sql = $wpdb->prepare($sql, $params);
}

$leads = $wpdb->get_results($sql, ARRAY_A);

// Estatísticas
$stats = $wpdb->get_results("SELECT status, COUNT(*) as count FROM $leads_table GROUP BY status", ARRAY_A);
$stats_array = array('new' => 0, 'contacted' => 0, 'total' => 0);
foreach ($stats as $stat) {
    $stats_array[$stat['status']] = intval($stat['count']);
    $stats_array['total'] += intval($stat['count']);
}
?>

<div class="wrap">
    <h1><?php _e('Gerenciamento de Leads', 'sistur'); ?></h1>

    <div class="sistur-stats-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0;">
        <div style="background: white; padding: 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin: 0; color: #646970; font-size: 14px;"><?php _e('Total de Leads', 'sistur'); ?></h3>
            <p style="font-size: 28px; font-weight: bold; color: #2271b1; margin: 10px 0;"><?php echo esc_html($stats_array['total']); ?></p>
        </div>
        <div style="background: white; padding: 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin: 0; color: #646970; font-size: 14px;"><?php _e('Novos', 'sistur'); ?></h3>
            <p style="font-size: 28px; font-weight: bold; color: #d63638; margin: 10px 0;"><?php echo esc_html($stats_array['new']); ?></p>
        </div>
        <div style="background: white; padding: 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin: 0; color: #646970; font-size: 14px;"><?php _e('Contatados', 'sistur'); ?></h3>
            <p style="font-size: 28px; font-weight: bold; color: #00a32a; margin: 10px 0;"><?php echo esc_html($stats_array['contacted']); ?></p>
        </div>
    </div>

    <div style="background: white; padding: 20px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <form method="get" action="" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="sistur-leads" />
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Buscar leads...', 'sistur'); ?>" style="width: 300px;" />
                <select name="status">
                    <option value=""><?php _e('Todos os status', 'sistur'); ?></option>
                    <option value="new" <?php selected($status_filter, 'new'); ?>><?php _e('Novos', 'sistur'); ?></option>
                    <option value="contacted" <?php selected($status_filter, 'contacted'); ?>><?php _e('Contatados', 'sistur'); ?></option>
                </select>
                <button type="submit" class="button"><?php _e('Filtrar', 'sistur'); ?></button>
                <?php if ($search || $status_filter) : ?>
                    <a href="<?php echo admin_url('admin.php?page=sistur-leads'); ?>" class="button"><?php _e('Limpar Filtros', 'sistur'); ?></a>
                <?php endif; ?>
            </div>
        </form>

        <?php if (!empty($leads)) : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Nome', 'sistur'); ?></th>
                        <th><?php _e('Email', 'sistur'); ?></th>
                        <th><?php _e('Telefone', 'sistur'); ?></th>
                        <th><?php _e('Origem', 'sistur'); ?></th>
                        <th><?php _e('Status', 'sistur'); ?></th>
                        <th><?php _e('Data', 'sistur'); ?></th>
                        <th><?php _e('Ações', 'sistur'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leads as $lead) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($lead['name']); ?></strong></td>
                            <td><?php echo esc_html($lead['email']); ?></td>
                            <td><?php echo esc_html($lead['phone']); ?></td>
                            <td><?php echo esc_html($lead['source']); ?></td>
                            <td>
                                <span class="sistur-lead-status sistur-status-<?php echo esc_attr($lead['status']); ?>">
                                    <?php echo $lead['status'] === 'new' ? __('Novo', 'sistur') : __('Contatado', 'sistur'); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(date('d/m/Y H:i', strtotime($lead['created_at']))); ?></td>
                            <td>
                                <button class="button button-small sistur-toggle-status" data-id="<?php echo $lead['id']; ?>" data-current-status="<?php echo esc_attr($lead['status']); ?>">
                                    <?php echo $lead['status'] === 'new' ? __('Marcar como Contatado', 'sistur') : __('Marcar como Novo', 'sistur'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p><?php _e('Nenhum lead encontrado.', 'sistur'); ?></p>
        <?php endif; ?>
    </div>
</div>

<style>
    .sistur-lead-status {
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 12px;
        font-weight: 500;
    }
    .sistur-status-new {
        background-color: #fcf0f1;
        color: #d63638;
    }
    .sistur-status-contacted {
        background-color: #edfaef;
        color: #00a32a;
    }
</style>

<script>
jQuery(document).ready(function($) {
    $('.sistur-toggle-status').on('click', function() {
        var button = $(this);
        var leadId = button.data('id');
        var currentStatus = button.data('current-status');
        var newStatus = currentStatus === 'new' ? 'contacted' : 'new';

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_update_lead_status',
                nonce: '<?php echo wp_create_nonce('sistur_admin_nonce'); ?>',
                id: leadId,
                status: newStatus
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Erro ao atualizar status.');
                }
            },
            error: function() {
                alert('Erro ao atualizar status.');
            }
        });
    });
});
</script>
