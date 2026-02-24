<?php
/**
 * Componente: Módulo de Aprovações
 * Central de aprovações para todos os módulos do SISTUR
 * 
 * @package SISTUR
 * @since 2.0.0
 */

$session = SISTUR_Session::get_instance();
$approvals = SISTUR_Approvals::get_instance();
$permissions = SISTUR_Permissions::get_instance();
$employee_id = $session->get_employee_id();

$can_approve = $permissions->can($employee_id, 'approvals', 'approve');
$my_requests = $approvals->get_requests_by_employee($employee_id, null, 50);
$pending_for_me = $can_approve ? $approvals->get_pending_for_approver($employee_id) : array();

// Labels de status
$status_labels = array(
    'pending'   => array('label' => __('Pendente', 'sistur'),   'class' => 'pending',   'icon' => 'dashicons-clock'),
    'approved'  => array('label' => __('Aprovado', 'sistur'),   'class' => 'approved',  'icon' => 'dashicons-yes-alt'),
    'rejected'  => array('label' => __('Rejeitado', 'sistur'),  'class' => 'rejected',  'icon' => 'dashicons-dismiss'),
    'cancelled' => array('label' => __('Cancelado', 'sistur'),  'class' => 'cancelled', 'icon' => 'dashicons-no')
);

// Labels de módulo para nomes legíveis
$module_labels = array(
    'inventory'    => 'Estoque',
    'time_tracking'=> 'Ponto',
    'payments'     => 'Financeiro',
    'employees'    => 'Funcionários',
    'approvals'    => 'Aprovações'
);

// Labels de ação para nomes legíveis  
$action_labels = array(
    'stock_loss'       => 'Baixa de Estoque',
    'stock_adjustment' => 'Ajuste de Estoque',
    'stock_transfer'   => 'Transferência',
    'record_sale'      => 'Registro de Venda',
    'time_correction'  => 'Correção de Ponto',
    'absence_request'  => 'Solicitação de Falta',
    'vacation_request' => 'Solicitação de Férias'
);

/**
 * Helper: renderiza detalhes do request_data
 */
function sistur_render_request_details($parsed_data, $action) {
    $html = '<div class="request-details">';
    
    if (!empty($parsed_data['product_name'])) {
        $html .= '<div class="detail-item"><span class="detail-label">Produto:</span> <span class="detail-value">' . esc_html($parsed_data['product_name']) . '</span></div>';
    }
    
    if (!empty($parsed_data['quantity'])) {
        $html .= '<div class="detail-item"><span class="detail-label">Quantidade:</span> <span class="detail-value">' . esc_html($parsed_data['quantity']) . '</span></div>';
    }

    if (!empty($parsed_data['reason'])) {
        $reason_labels = array(
            'loss' => 'Perda', 'damage' => 'Dano', 'theft' => 'Furto',
            'donation' => 'Doação', 'sample' => 'Amostra', 'adjustment' => 'Ajuste'
        );
        $reason_text = $reason_labels[$parsed_data['reason']] ?? ucfirst($parsed_data['reason']);
        $html .= '<div class="detail-item"><span class="detail-label">Motivo:</span> <span class="detail-value">' . esc_html($reason_text) . '</span></div>';
    }
    
    if (!empty($parsed_data['unit_price'])) {
        $html .= '<div class="detail-item"><span class="detail-label">Preço Unit.:</span> <span class="detail-value">R$ ' . number_format(floatval($parsed_data['unit_price']), 2, ',', '.') . '</span></div>';
    }

    if (!empty($parsed_data['quantity']) && !empty($parsed_data['unit_price'])) {
        $total = floatval($parsed_data['quantity']) * floatval($parsed_data['unit_price']);
        $html .= '<div class="detail-item"><span class="detail-label">Valor Total:</span> <span class="detail-value total-value">R$ ' . number_format($total, 2, ',', '.') . '</span></div>';
    }
    
    if (!empty($parsed_data['notes'])) {
        $html .= '<div class="detail-item detail-notes"><span class="detail-label">Justificativa:</span><p>' . esc_html($parsed_data['notes']) . '</p></div>';
    }
    
    $html .= '</div>';
    return $html;
}

// Contagem por status para badges
$count_pending = count(array_filter($my_requests, function($r) { return $r['status'] === 'pending'; }));
$count_approved = count(array_filter($my_requests, function($r) { return $r['status'] === 'approved'; }));
$count_rejected = count(array_filter($my_requests, function($r) { return $r['status'] === 'rejected'; }));
?>

<div class="module-aprovacoes">
    <div class="aprovacoes-header">
        <h2><span class="dashicons dashicons-yes-alt"></span> <?php _e('Central de Aprovações', 'sistur'); ?></h2>
    </div>

    <!-- Tabs -->
    <div class="aprovacoes-tabs">
        <button class="tab-btn active" data-tab="mine">
            <span class="dashicons dashicons-clipboard"></span>
            <?php _e('Minhas Solicitações', 'sistur'); ?>
            <?php if ($count_pending > 0): ?><span class="tab-badge"><?php echo $count_pending; ?></span><?php endif; ?>
        </button>
        <?php if ($can_approve): ?>
        <button class="tab-btn" data-tab="pending">
            <span class="dashicons dashicons-shield"></span>
            <?php _e('Aguardando Aprovação', 'sistur'); ?>
            <?php if (count($pending_for_me) > 0): ?><span class="tab-badge urgent"><?php echo count($pending_for_me); ?></span><?php endif; ?>
        </button>
        <?php endif; ?>
    </div>

    <!-- ===================== TAB: Minhas Solicitações ===================== -->
    <div class="tab-content active" id="tab-mine">
        <?php if (!empty($my_requests)): ?>
            <!-- Filtros rápidos -->
            <div class="filter-bar">
                <button class="filter-btn active" data-filter="all"><?php _e('Todos', 'sistur'); ?> (<?php echo count($my_requests); ?>)</button>
                <button class="filter-btn" data-filter="pending"><?php _e('Pendentes', 'sistur'); ?> (<?php echo $count_pending; ?>)</button>
                <button class="filter-btn" data-filter="approved"><?php _e('Aprovados', 'sistur'); ?> (<?php echo $count_approved; ?>)</button>
                <button class="filter-btn" data-filter="rejected"><?php _e('Rejeitados', 'sistur'); ?> (<?php echo $count_rejected; ?>)</button>
            </div>
        <?php endif; ?>

        <?php if (empty($my_requests)): ?>
            <div class="empty-state">
                <span class="dashicons dashicons-clipboard"></span>
                <p><?php _e('Nenhuma solicitação registrada.', 'sistur'); ?></p>
                <small><?php _e('Solicitações de baixa, ajuste e transferência aparecerão aqui.', 'sistur'); ?></small>
            </div>
        <?php else: ?>
            <div class="aprovacoes-list">
            <?php foreach ($my_requests as $r): 
                $status_info = $status_labels[$r['status']] ?? $status_labels['pending'];
                $module_name = $module_labels[$r['module']] ?? ucfirst($r['module']);
                $action_name = $action_labels[$r['action']] ?? ucfirst(str_replace('_', ' ', $r['action']));
                $parsed = $r['parsed_data'] ?? array();
            ?>
                <div class="aprovacao-card <?php echo esc_attr($r['status']); ?>" data-status="<?php echo esc_attr($r['status']); ?>" data-request-id="<?php echo esc_attr($r['id']); ?>">
                    <div class="aprovacao-header">
                        <div class="header-left">
                            <span class="module-tag"><?php echo esc_html($module_name); ?></span>
                            <span class="action-text"><?php echo esc_html($action_name); ?></span>
                        </div>
                        <span class="aprovacao-status <?php echo esc_attr($status_info['class']); ?>">
                            <span class="dashicons <?php echo esc_attr($status_info['icon']); ?>"></span>
                            <?php echo esc_html($status_info['label']); ?>
                        </span>
                    </div>
                    <div class="aprovacao-body">
                        <?php echo sistur_render_request_details($parsed, $r['action']); ?>
                    </div>
                    <div class="aprovacao-footer">
                        <div class="footer-info">
                            <span class="aprovacao-date">
                                <span class="dashicons dashicons-calendar-alt"></span>
                                <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($r['requested_at']))); ?>
                            </span>
                            <?php if (!empty($r['approver_name'])): ?>
                                <span class="aprovacao-approver">
                                    <span class="dashicons dashicons-admin-users"></span>
                                    <?php echo $r['status'] === 'approved' ? 'Aprovado por' : 'Rejeitado por'; ?>: <?php echo esc_html($r['approver_name']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($r['approval_notes'])): ?>
                                <span class="aprovacao-obs">
                                    <span class="dashicons dashicons-format-quote"></span>
                                    "<?php echo esc_html($r['approval_notes']); ?>"
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($r['status'] === 'pending'): ?>
                            <button class="btn-cancel-request" data-request-id="<?php echo esc_attr($r['id']); ?>">
                                <span class="dashicons dashicons-no"></span> <?php _e('Cancelar', 'sistur'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ===================== TAB: Aguardando Aprovação ===================== -->
    <?php if ($can_approve): ?>
    <div class="tab-content" id="tab-pending" style="display:none;">
        <?php if (empty($pending_for_me)): ?>
            <div class="empty-state">
                <span class="dashicons dashicons-yes-alt"></span>
                <p><?php _e('Nenhuma solicitação pendente.', 'sistur'); ?></p>
                <small><?php _e('Tudo limpo! Não há nada para aprovar no momento.', 'sistur'); ?></small>
            </div>
        <?php else: ?>
            <div class="aprovacoes-list">
            <?php foreach ($pending_for_me as $r): 
                $module_name = $module_labels[$r['module']] ?? ucfirst($r['module']);
                $action_name = $action_labels[$r['action']] ?? ucfirst(str_replace('_', ' ', $r['action']));
                $parsed = $r['parsed_data'] ?? array();
            ?>
                <div class="aprovacao-card pending-review" data-request-id="<?php echo esc_attr($r['id']); ?>">
                    <div class="aprovacao-header">
                        <div class="header-left">
                            <span class="aprovacao-requester">
                                <span class="dashicons dashicons-admin-users"></span>
                                <?php echo esc_html($r['requester_name']); ?>
                                <?php if (!empty($r['requester_role_name'])): ?>
                                    <small>(<?php echo esc_html($r['requester_role_name']); ?>)</small>
                                <?php endif; ?>
                            </span>
                        </div>
                        <span class="aprovacao-time">
                            <span class="dashicons dashicons-clock"></span>
                            <?php echo esc_html(human_time_diff(strtotime($r['requested_at']), current_time('timestamp'))); ?> atrás
                        </span>
                    </div>
                    <div class="aprovacao-body">
                        <div class="action-info">
                            <span class="module-tag"><?php echo esc_html($module_name); ?></span>
                            <span class="action-text"><?php echo esc_html($action_name); ?></span>
                        </div>
                        <?php echo sistur_render_request_details($parsed, $r['action']); ?>
                    </div>
                    <div class="aprovacao-footer">
                        <textarea name="approver_notes" placeholder="<?php _e('Observação (opcional)', 'sistur'); ?>" rows="2"></textarea>
                        <div class="approval-buttons">
                            <button class="btn-reject" data-decision="rejected">
                                <span class="dashicons dashicons-no"></span> <?php _e('Rejeitar', 'sistur'); ?>
                            </button>
                            <button class="btn-approve" data-decision="approved">
                                <span class="dashicons dashicons-yes"></span> <?php _e('Aprovar', 'sistur'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
(function($) {
    var nonce = '<?php echo wp_create_nonce('sistur_portal_nonce'); ?>';
    var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';

    // Tabs
    $('.module-aprovacoes .aprovacoes-tabs .tab-btn').on('click', function() {
        var $tabs = $(this).closest('.module-aprovacoes');
        $tabs.find('.tab-btn').removeClass('active');
        $(this).addClass('active');
        $tabs.find('.tab-content').hide();
        $tabs.find('#tab-' + $(this).data('tab')).fadeIn(200);
    });

    // Filtros de status (Minhas Solicitações)
    $('.module-aprovacoes .filter-btn').on('click', function() {
        $('.filter-btn').removeClass('active');
        $(this).addClass('active');
        var filter = $(this).data('filter');
        
        $('#tab-mine .aprovacao-card').each(function() {
            if (filter === 'all' || $(this).data('status') === filter) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    // Aprovar / Rejeitar
    $('.module-aprovacoes .btn-approve, .module-aprovacoes .btn-reject').on('click', function() {
        var $card = $(this).closest('.aprovacao-card');
        var id = $card.data('request-id');
        var decision = $(this).data('decision');
        var notes = $card.find('textarea[name="approver_notes"]').val();
        var $btn = $(this);

        var msg = decision === 'approved' ? 'Confirma APROVAÇÃO desta solicitação?' : 'Confirma REJEIÇÃO desta solicitação?';
        if (!confirm(msg)) return;

        $btn.prop('disabled', true).css('opacity', '0.5');

        $.post(ajaxUrl, {
            action: 'sistur_process_approval',
            nonce: nonce,
            request_id: id,
            decision: decision,
            notes: notes
        }, function(res) {
            if (res.success) {
                var statusClass = decision === 'approved' ? 'approved' : 'rejected';
                var statusText = decision === 'approved' ? 'Aprovado ✅' : 'Rejeitado ❌';
                $card.removeClass('pending-review').addClass(statusClass);
                $card.find('.aprovacao-footer').html('<div class="result-message ' + statusClass + '">' + statusText + '</div>');
                
                // Atualizar badge
                var $badge = $('.tab-btn[data-tab="pending"] .tab-badge');
                var count = parseInt($badge.text()) - 1;
                if (count > 0) { $badge.text(count); } else { $badge.remove(); }
                
                setTimeout(function() { $card.slideUp(300); }, 2000);
            } else {
                alert(res.data.message || 'Erro ao processar.');
                $btn.prop('disabled', false).css('opacity', '1');
            }
        }).fail(function() {
            alert('Erro de conexão.');
            $btn.prop('disabled', false).css('opacity', '1');
        });
    });

    // Cancelar solicitação
    $('.module-aprovacoes .btn-cancel-request').on('click', function() {
        var $btn = $(this);
        var $card = $btn.closest('.aprovacao-card');
        var id = $btn.data('request-id');

        if (!confirm('Deseja cancelar esta solicitação?')) return;

        $btn.prop('disabled', true).css('opacity', '0.5');

        $.post(ajaxUrl, {
            action: 'sistur_cancel_request',
            nonce: nonce,
            request_id: id
        }, function(res) {
            if (res.success) {
                $card.removeClass('pending').addClass('cancelled').attr('data-status', 'cancelled');
                $card.find('.aprovacao-status').removeClass('pending').addClass('cancelled')
                    .html('<span class="dashicons dashicons-no"></span> Cancelado');
                $btn.remove();
            } else {
                alert(res.data.message || 'Erro ao cancelar.');
                $btn.prop('disabled', false).css('opacity', '1');
            }
        }).fail(function() {
            alert('Erro de conexão.');
            $btn.prop('disabled', false).css('opacity', '1');
        });
    });
})(jQuery);
</script>

<style>
/* ===== Aprovações Module ===== */
.module-aprovacoes { max-width: 900px; }
.aprovacoes-header h2 { 
    margin: 0 0 25px; font-size: 1.5rem; color: #1e293b; 
    display: flex; align-items: center; gap: 10px;
    border-bottom: 2px solid #6366f1; padding-bottom: 12px; 
}
.aprovacoes-header h2 .dashicons { color: #6366f1; }

/* Tabs */
.aprovacoes-tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; }
.tab-btn { 
    padding: 10px 18px; background: transparent; border: none; border-radius: 8px; 
    font-weight: 600; cursor: pointer; color: #64748b; 
    display: flex; align-items: center; gap: 8px; transition: all 0.2s; 
}
.tab-btn:hover { background: #f1f5f9; color: #475569; }
.tab-btn.active { background: #eef2ff; color: #4f46e5; }
.tab-btn .dashicons { font-size: 18px; width: 18px; height: 18px; }
.tab-badge { background: #0d9488; color: white; font-size: .75rem; padding: 2px 8px; border-radius: 20px; min-width: 20px; text-align: center; }
.tab-badge.urgent { background: #ef4444; animation: pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.6} }

/* Filter Bar */
.filter-bar { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; }
.filter-btn { 
    padding: 6px 14px; border-radius: 20px; border: 1px solid #e2e8f0; 
    background: white; color: #64748b; font-size: .85rem; font-weight: 500;
    cursor: pointer; transition: all 0.2s; 
}
.filter-btn:hover { border-color: #6366f1; color: #6366f1; }
.filter-btn.active { background: #eef2ff; border-color: #6366f1; color: #4f46e5; font-weight: 600; }

/* Empty State */
.empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
.empty-state .dashicons { font-size: 48px; width: 48px; height: 48px; color: #cbd5e1; margin-bottom: 15px; display: block; }
.empty-state p { font-size: 1.1rem; margin: 0 0 8px; }
.empty-state small { color: #b0bec5; }

/* Cards */
.aprovacoes-list { display: flex; flex-direction: column; gap: 12px; }
.aprovacao-card { background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; transition: all 0.3s; }
.aprovacao-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
.aprovacao-card.pending { border-left: 4px solid #f59e0b; }
.aprovacao-card.approved { border-left: 4px solid #10b981; }
.aprovacao-card.rejected { border-left: 4px solid #ef4444; }
.aprovacao-card.cancelled { border-left: 4px solid #94a3b8; opacity: 0.7; }
.aprovacao-card.pending-review { border-left: 4px solid #6366f1; background: #fafbff; }

/* Card Header */
.aprovacao-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #f1f5f9; }
.header-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.module-tag { background: #e0e7ff; color: #4f46e5; padding: 4px 10px; border-radius: 6px; font-size: .8rem; font-weight: 600; text-transform: uppercase; }
.action-text { font-weight: 600; color: #1e293b; font-size: .95rem; }
.aprovacao-requester { display: flex; align-items: center; gap: 6px; font-weight: 600; color: #1e293b; }
.aprovacao-requester small { font-weight: 400; color: #94a3b8; }
.aprovacao-time { color: #94a3b8; font-size: .85rem; display: flex; align-items: center; gap: 4px; }
.aprovacao-time .dashicons { font-size: 14px; width: 14px; height: 14px; }

/* Status Badge */
.aprovacao-status { padding: 4px 12px; border-radius: 20px; font-size: .8rem; font-weight: 600; display: flex; align-items: center; gap: 4px; }
.aprovacao-status .dashicons { font-size: 14px; width: 14px; height: 14px; }
.aprovacao-status.pending { background: #fef3c7; color: #92400e; }
.aprovacao-status.approved { background: #dcfce7; color: #166534; }
.aprovacao-status.rejected { background: #fee2e2; color: #991b1b; }
.aprovacao-status.cancelled { background: #f1f5f9; color: #64748b; }

/* Card Body */
.aprovacao-body { padding: 14px 16px; }
.action-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }

/* Request Details */
.request-details { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.detail-item { display: flex; align-items: baseline; gap: 6px; font-size: .9rem; padding: 4px 0; }
.detail-label { color: #64748b; font-weight: 500; }
.detail-value { color: #1e293b; font-weight: 600; }
.detail-value.total-value { color: #dc2626; font-size: 1rem; }
.detail-notes { grid-column: 1 / -1; flex-direction: column; gap: 4px; }
.detail-notes p { margin: 4px 0 0; padding: 8px 12px; background: #f8fafc; border-radius: 8px; color: #475569; font-style: italic; font-weight: 400; border-left: 3px solid #cbd5e1; }

/* Card Footer */
.aprovacao-footer { padding: 12px 16px; background: #f8fafc; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
.footer-info { display: flex; flex-direction: column; gap: 4px; }
.aprovacao-date, .aprovacao-approver, .aprovacao-obs { font-size: .85rem; color: #94a3b8; display: flex; align-items: center; gap: 4px; }
.aprovacao-date .dashicons, .aprovacao-approver .dashicons, .aprovacao-obs .dashicons { font-size: 14px; width: 14px; height: 14px; }
.aprovacao-approver { color: #64748b; }
.aprovacao-obs { color: #475569; font-style: italic; }

/* Buttons */
.btn-cancel-request { 
    display: flex; align-items: center; gap: 4px; padding: 6px 14px; 
    border-radius: 6px; font-size: .85rem; font-weight: 500; cursor: pointer;
    border: 1px solid #fecaca; background: #fef2f2; color: #dc2626; transition: all 0.2s; 
}
.btn-cancel-request:hover { background: #dc2626; color: white; border-color: #dc2626; }
.btn-cancel-request .dashicons { font-size: 14px; width: 14px; height: 14px; }

.aprovacao-footer textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; resize: none; margin-bottom: 10px; font-family: inherit; font-size: .9rem; }
.aprovacao-footer textarea:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
.approval-buttons { display: flex; gap: 10px; justify-content: flex-end; }
.btn-approve, .btn-reject { 
    display: flex; align-items: center; gap: 6px; padding: 10px 22px; 
    border-radius: 8px; font-weight: 600; cursor: pointer; border: none; 
    font-size: .9rem; transition: all 0.2s; 
}
.btn-approve { background: linear-gradient(135deg, #10b981, #059669); color: white; box-shadow: 0 2px 6px rgba(16,185,129,0.3); }
.btn-approve:hover { box-shadow: 0 4px 12px rgba(16,185,129,0.4); transform: translateY(-1px); }
.btn-reject { background: #fee2e2; color: #dc2626; }
.btn-reject:hover { background: #dc2626; color: white; }
.btn-approve .dashicons, .btn-reject .dashicons { font-size: 18px; width: 18px; height: 18px; }

/* Result Message */
.result-message { padding: 12px; text-align: center; font-weight: 600; border-radius: 8px; }
.result-message.approved { background: #dcfce7; color: #166534; }
.result-message.rejected { background: #fee2e2; color: #991b1b; }

/* Responsive */
@media(max-width: 768px) {
    .aprovacoes-tabs { flex-direction: column; }
    .filter-bar { flex-direction: column; }
    .aprovacao-header { flex-direction: column; align-items: flex-start; gap: 8px; }
    .request-details { grid-template-columns: 1fr; }
    .approval-buttons { flex-direction: column; width: 100%; }
    .btn-approve, .btn-reject { justify-content: center; }
    .aprovacao-footer { flex-direction: column; align-items: stretch; }
}
</style>
