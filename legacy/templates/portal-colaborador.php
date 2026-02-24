<?php
/**
 * Template do Portal do Colaborador (Dashboard Central)
 *
 * @package SISTUR
 * @since 2.0.0
 */

// Verificar se está logado
$session = SISTUR_Session::get_instance();
$is_logged_in = $session->is_employee_logged_in();

// Se não estiver logado, mostrar formulário de login
if (!$is_logged_in) {
    include SISTUR_PLUGIN_DIR . 'templates/login-funcionario.php';
    return;
}

// Obter dados do funcionário e módulos
$portal = SISTUR_Portal::get_instance();
$approvals = SISTUR_Approvals::get_instance();
$permissions = SISTUR_Permissions::get_instance();

$employee = $portal->get_current_employee_with_role();
$modules = $portal->get_modules_for_employee($employee['id']);
$stats = $portal->get_dashboard_stats($employee['id']);

// Fallback: se não houver módulos configurados, mostrar padrões
if (empty($modules)) {
    $modules = array(
        array(
            'module_key' => 'ponto',
            'module_name' => __('Ponto Eletrônico', 'sistur'),
            'module_icon' => 'dashicons-clock',
            'module_color' => '#0d9488',
            'module_description' => __('Registre sua entrada e saída', 'sistur'),
            'target_url' => '#tab-ponto',
            'target_type' => 'tab',
            'required_permission' => '' // Disponível para todos
        ),
        array(
            'module_key' => 'restaurante',
            'module_name' => __('Restaurante', 'sistur'),
            'module_icon' => 'dashicons-food',
            'module_color' => '#f59e0b',
            'module_description' => __('CMV, PDV e gestão do restaurante', 'sistur'),
            'target_url' => '#tab-restaurante',
            'target_type' => 'tab',
            'required_permission' => 'restaurant.view'
        ),
        array(
            'module_key' => 'aprovacoes',
            'module_name' => __('Aprovações', 'sistur'),
            'module_icon' => 'dashicons-yes-alt',
            'module_color' => '#6366f1',
            'module_description' => __('Solicitações e aprovações', 'sistur'),
            'target_url' => '#tab-aprovacoes',
            'target_type' => 'tab',
            'required_permission' => 'approvals.approve'
        ),
        array(
            'module_key' => 'estoque',
            'module_name' => __('Estoque / CMV', 'sistur'),
            'module_icon' => 'dashicons-archive',
            'module_color' => '#f59e0b',
            'module_description' => __('Gerencie produtos e movimentações', 'sistur'),
            'target_url' => '#tab-estoque',
            'target_type' => 'tab',
            'required_permission' => 'inventory.view'
        ),
        array(
            'module_key' => 'gestao-funcionarios',
            'module_name' => __('Gestão de Funcionários', 'sistur'),
            'module_icon' => 'dashicons-businessman',
            'module_color' => '#10b981',
            'module_description' => __('Abonos, banco de horas e informações', 'sistur'),
            'target_url' => '#tab-gestao',
            'target_type' => 'tab',
            'required_permission' => 'employees.view'
        ),
        array(
            'module_key' => 'financas',
            'module_name' => __('Finanças', 'sistur'),
            'module_icon' => 'dashicons-chart-area',
            'module_color' => '#eab308',
            'module_description' => __('Controle financeiro e relatórios', 'sistur'),
            'target_url' => '#tab-financas',
            'target_type' => 'tab',
            'required_permission' => 'payments.view_all'
        ),
        array(
            'module_key' => 'perfil',
            'module_name' => __('Meu Perfil', 'sistur'),
            'module_icon' => 'dashicons-admin-users',
            'module_color' => '#8b5cf6',
            'module_description' => __('Visualizar e editar perfil', 'sistur'),
            'target_url' => '#tab-perfil',
            'target_type' => 'tab',
            'required_permission' => '' // Disponível para todos
        )
    );
}

// Filtrar módulos baseado em permissões
$is_portal_admin = $permissions->is_admin($employee['id']);
$modules = array_filter($modules, function ($module) use ($permissions, $employee, $is_portal_admin) {
    if (empty($module['required_permission'])) {
        return true; // Módulo sem permissão específica - disponível para todos
    }
    // Super admin do portal tem acesso a todos os módulos
    if ($is_portal_admin) {
        return true;
    }
    $parts = explode('.', $module['required_permission']);
    if (count($parts) === 2) {
        return $permissions->can($employee['id'], $parts[0], $parts[1]);
    }
    return true;
});

// Verificar se tem permissão de aprovar (super admin do portal tem acesso total)
$can_approve = $is_portal_admin || $permissions->can($employee['id'], 'approvals', 'approve');

// Obter aprovações pendentes se for gerente
$pending_approvals = $can_approve ? $approvals->get_pending_for_approver($employee['id']) : array();
?>

<div class="sistur-portal sistur-container">
    <!-- DEBUG: ACTIVE FILE VERIFIED [<?php echo date('Y-m-d H:i:s'); ?>] -->
    <!-- Header do Portal -->
    <div class="portal-header">
        <div class="portal-welcome">
            <h1><?php printf(__('Olá, %s!', 'sistur'), esc_html(explode(' ', $employee['name'])[0])); ?></h1>
            <p class="portal-subtitle">
                <?php if (!empty($employee['role_name'])): ?>
                    <span class="role-badge"><?php echo esc_html($employee['role_name']); ?></span>
                <?php endif; ?>
                <?php _e('Portal do Colaborador', 'sistur'); ?>
            </p>
        </div>
        <div class="portal-actions">
            <a href="<?php echo add_query_arg('sistur_logout', '1'); ?>" class="portal-btn portal-btn-secondary">
                <span class="dashicons dashicons-exit"></span>
                <?php _e('Sair', 'sistur'); ?>
            </a>
        </div>
    </div>

    <!-- Resumo Rápido -->
    <div class="portal-quick-stats">
        <?php if ($stats['today_punches'] > 0): ?>
            <div class="quick-stat-item">
                <span class="dashicons dashicons-clock"></span>
                <span><?php printf(__('%d registro(s) hoje', 'sistur'), $stats['today_punches']); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($stats['pending_approvals'] > 0): ?>
            <div class="quick-stat-item urgent">
                <span class="dashicons dashicons-warning"></span>
                <span><?php printf(__('%d aprovação(ões) pendente(s)', 'sistur'), $stats['pending_approvals']); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($stats['my_pending_requests'] > 0): ?>
            <div class="quick-stat-item">
                <span class="dashicons dashicons-clock"></span>
                <span><?php printf(__('%d solicitação(ões) aguardando', 'sistur'), $stats['my_pending_requests']); ?></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Grid de Módulos -->
    <div class="portal-modules-grid">
        <?php foreach ($modules as $module): ?>
            <?php
            // FIX: Garantir que o link do restaurante abra a aba corretamente
            // Adicionando verificação mais ampla
            if (
                $module['module_key'] === 'restaurante' ||
                strtolower($module['module_name']) === 'restaurante' ||
                strpos(strtolower($module['module_name']), 'restaurante') !== false
            ) {
                $module['target_url'] = '#tab-restaurante';
                $module['target_type'] = 'tab';
            }
            echo $portal->render_module_card($module, $stats);
            ?>
        <?php endforeach; ?>
    </div>

    <!-- Seção de Aprovações Pendentes (apenas para gerentes) -->
    <?php if ($can_approve && !empty($pending_approvals)): ?>
        <div class="portal-section portal-approvals-section">
            <h2>
                <span class="dashicons dashicons-yes-alt"></span>
                <?php _e('Aprovações Pendentes', 'sistur'); ?>
                <span class="count-badge"><?php echo count($pending_approvals); ?></span>
            </h2>
            <div class="approvals-list">
                <?php foreach (array_slice($pending_approvals, 0, 5) as $request): ?>
                    <div class="approval-item" data-request-id="<?php echo esc_attr($request['id']); ?>">
                        <div class="approval-info">
                            <strong><?php echo esc_html($request['requester_name']); ?></strong>
                            <span class="approval-type">
                                <?php echo esc_html(ucfirst(str_replace('_', ' ', $request['action']))); ?>
                            </span>
                            <span class="approval-time">
                                <?php echo esc_html(human_time_diff(strtotime($request['requested_at']), current_time('timestamp'))); ?>
                                atrás
                            </span>
                        </div>
                        <div class="approval-actions">
                            <button class="approval-btn approve" data-action="approved"
                                title="<?php _e('Aprovar', 'sistur'); ?>">
                                <span class="dashicons dashicons-yes"></span>
                            </button>
                            <button class="approval-btn reject" data-action="rejected"
                                title="<?php _e('Rejeitar', 'sistur'); ?>">
                                <span class="dashicons dashicons-no"></span>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (count($pending_approvals) > 5): ?>
                    <a href="#tab-aprovacoes" class="view-all-link" data-tab="tab-aprovacoes">
                        <?php printf(__('Ver todas (%d)', 'sistur'), count($pending_approvals)); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Conteúdo das Tabs (oculto por padrão) -->
    <div class="portal-tab-contents" style="display: none;">
        <!-- Tab: Ponto Eletrônico -->
        <div class="portal-tab-content" id="tab-ponto">
            <?php include SISTUR_PLUGIN_DIR . 'templates/components/module-ponto.php'; ?>
        </div>

        <!-- Tab: Estoque -->
        <div class="portal-tab-content" id="tab-estoque">
            <?php if ($is_portal_admin || $permissions->can($employee['id'], 'inventory', 'view')): ?>
                <?php include SISTUR_PLUGIN_DIR . 'templates/components/module-estoque.php'; ?>
            <?php endif; ?>
        </div>

        <!-- Tab: Restaurante -->
        <div class="portal-tab-content" id="tab-restaurante">
            <?php if ($is_portal_admin || $permissions->can($employee['id'], 'restaurant', 'view')): ?>
                <?php include SISTUR_PLUGIN_DIR . 'templates/components/module-restaurante.php'; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 60px 20px;">
                    <span style="font-size: 64px;">🔒</span>
                    <h2 style="margin: 20px 0 10px;"><?php _e('Acesso Restrito', 'sistur'); ?></h2>
                    <p style="color: #64748b;"><?php _e('Você não tem permissão para acessar este módulo.', 'sistur'); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab: Aprovações -->
        <div class="portal-tab-content" id="tab-aprovacoes">
            <?php include SISTUR_PLUGIN_DIR . 'templates/components/module-aprovacoes.php'; ?>
        </div>

        <!-- Tab: Gestão de Funcionários -->
        <div class="portal-tab-content" id="tab-gestao">
            <?php include SISTUR_PLUGIN_DIR . 'templates/components/module-gestao-funcionarios.php'; ?>
        </div>

        <!-- Tab: Finanças -->
        <div class="portal-tab-content" id="tab-financas">
            <?php include SISTUR_PLUGIN_DIR . 'templates/components/module-financas.php'; ?>
        </div>

        <!-- Tab: Perfil -->
        <div class="portal-tab-content" id="tab-perfil">
            <?php include SISTUR_PLUGIN_DIR . 'templates/components/module-perfil.php'; ?>
        </div>
    </div>
</div>

<script>
    jQuery(document).ready(function ($) {
        var portalNonce = '<?php echo wp_create_nonce('sistur_portal_nonce'); ?>';

        // Navegação para módulos tipo Tab (Card, Link ou Botão)
        $('.portal-module-card[data-tab], .view-all-link[data-tab], .portal-nav-link[data-tab]').on('click', function (e) {
            e.preventDefault();
            var tabId = $(this).data('tab');
            showTab(tabId);
        });



        function showTab(tabId) {
            // Ocultar grid de módulos
            $('.portal-modules-grid').slideUp(200);
            $('.portal-quick-stats').slideUp(200);
            $('.portal-approvals-section').slideUp(200);

            // Mostrar container de tabs
            $('.portal-tab-contents').slideDown(200);

            // Mostrar tab específica
            $('.portal-tab-content').hide();
            $('#' + tabId).fadeIn(300);

            // Adicionar botão de voltar se não existir
            if (!$('.portal-back-btn').length) {
                $('.portal-header .portal-actions').prepend(
                    '<button class="portal-btn portal-btn-ghost portal-back-btn">' +
                    '<span class="dashicons dashicons-arrow-left-alt"></span> Voltar' +
                    '</button>'
                );
            }
        }

        // Voltar para Dashboard
        $(document).on('click', '.portal-back-btn', function () {
            $('.portal-tab-contents').slideUp(200, function () {
                $('.portal-tab-content').hide();
            });
            $('.portal-modules-grid').slideDown(200);
            $('.portal-quick-stats').slideDown(200);
            $('.portal-approvals-section').slideDown(200);
            $(this).remove();
        });

        // Processar aprovação
        $('.approval-btn').on('click', function () {
            var $item = $(this).closest('.approval-item');
            var requestId = $item.data('request-id');
            var decision = $(this).data('action');
            var $btn = $(this);

            $btn.prop('disabled', true);

            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'sistur_process_approval',
                    nonce: portalNonce,
                    request_id: requestId,
                    decision: decision,
                    notes: ''
                },
                success: function (response) {
                    if (response.success) {
                        $item.fadeOut(300, function () {
                            $(this).remove();
                            // Atualizar contador
                            var $badge = $('.portal-approvals-section .count-badge');
                            var count = parseInt($badge.text()) - 1;
                            if (count <= 0) {
                                $('.portal-approvals-section').fadeOut();
                            } else {
                                $badge.text(count);
                            }
                        });
                    } else {
                        alert(response.data.message || 'Erro ao processar');
                        $btn.prop('disabled', false);
                    }
                },
                error: function () {
                    alert('Erro de conexão');
                    $btn.prop('disabled', false);
                }
            });
        });

        // Logout
        <?php if (isset($_GET['sistur_logout'])): ?>
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: { action: 'sistur_funcionario_logout' },
                success: function () {
                    window.location.href = '<?php echo home_url('/login-funcionario/'); ?>';
                }
            });
        <?php endif; ?>
    });
</script>

<style>
    /* Portal do Colaborador Styles */
    .sistur-portal {
        max-width: 1200px;
        margin: 40px auto;
        padding: 0 20px;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    /* Header */
    .portal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 30px 40px;
        background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);
        border-radius: 20px;
        margin-bottom: 30px;
        color: white;
        box-shadow: 0 10px 25px -5px rgba(13, 148, 136, 0.3);
    }

    .portal-welcome h1 {
        margin: 0 0 8px 0;
        font-size: 2rem;
        font-weight: 700;
        color: white;
    }

    .portal-subtitle {
        margin: 0;
        opacity: 0.9;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .role-badge {
        background: rgba(255, 255, 255, 0.2);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
    }

    .portal-actions {
        display: flex;
        gap: 10px;
    }

    .portal-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        border-radius: 10px;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.2s ease;
        border: none;
        font-size: 0.9rem;
    }

    .portal-btn-secondary {
        background: rgba(255, 255, 255, 0.2);
        color: #ffffff !important;
        border: 1px solid rgba(255, 255, 255, 0.3);
    }

    .portal-btn-secondary:hover {
        background: rgba(255, 255, 255, 0.3);
        color: #ffffff !important;
    }

    .portal-btn-secondary .dashicons {
        color: #ffffff !important;
    }

    .portal-btn-ghost {
        background: transparent;
        color: #334155;
        border: 1px solid #e2e8f0;
    }

    .portal-btn-ghost:hover {
        background: #f1f5f9;
    }

    /* Quick Stats */
    .portal-quick-stats {
        display: flex;
        gap: 15px;
        margin-bottom: 30px;
        flex-wrap: wrap;
    }

    .quick-stat-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        background: white;
        border-radius: 50px;
        font-size: 0.9rem;
        color: #64748b;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .quick-stat-item.urgent {
        background: #fef2f2;
        color: #dc2626;
    }

    .quick-stat-item .dashicons {
        font-size: 18px;
        width: 18px;
        height: 18px;
    }

    /* Modules Grid */
    .portal-modules-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 40px;
    }

    .portal-module-card {
        display: flex;
        align-items: center;
        gap: 20px;
        padding: 24px;
        background: white;
        border-radius: 16px;
        text-decoration: none;
        color: #334155;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        border: 1px solid #f1f5f9;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .portal-module-card::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 4px;
        background: var(--module-color, #0d9488);
    }

    .portal-module-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.1);
        border-color: var(--module-color, #0d9488);
    }

    .module-card-icon {
        width: 56px;
        height: 56px;
        border-radius: 14px;
        background: linear-gradient(135deg, var(--module-color, #0d9488), color-mix(in srgb, var(--module-color, #0d9488) 80%, black));
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        flex-shrink: 0;
    }

    .module-card-icon .dashicons {
        font-size: 28px;
        width: 28px;
        height: 28px;
        color: white;
    }

    .module-badge {
        position: absolute;
        top: -6px;
        right: -6px;
        background: #ef4444;
        color: white;
        font-size: 0.75rem;
        font-weight: 700;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .module-card-content {
        flex: 1;
    }

    .module-card-content h3 {
        margin: 0 0 4px 0;
        font-size: 1.1rem;
        font-weight: 600;
        color: #1e293b;
    }

    .module-card-content p {
        margin: 0;
        font-size: 0.875rem;
        color: #64748b;
    }

    .module-card-arrow {
        color: #cbd5e1;
        transition: transform 0.2s;
    }

    .portal-module-card:hover .module-card-arrow {
        transform: translateX(3px);
        color: var(--module-color, #0d9488);
    }

    /* Approvals Section */
    .portal-section {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .portal-section h2 {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 20px 0;
        font-size: 1.25rem;
        color: #1e293b;
    }

    .portal-section h2 .dashicons {
        color: #0d9488;
    }

    .count-badge {
        background: #0d9488;
        color: white;
        font-size: 0.8rem;
        padding: 2px 10px;
        border-radius: 20px;
        font-weight: 600;
    }

    .approval-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px;
        background: #f8fafc;
        border-radius: 10px;
        margin-bottom: 10px;
    }

    .approval-info {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
    }

    .approval-info strong {
        color: #1e293b;
    }

    .approval-type {
        background: #e2e8f0;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.8rem;
        color: #64748b;
    }

    .approval-time {
        color: #94a3b8;
        font-size: 0.85rem;
    }

    .approval-actions {
        display: flex;
        gap: 8px;
    }

    .approval-btn {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        border: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    .approval-btn.approve {
        background: #dcfce7;
        color: #16a34a;
    }

    .approval-btn.approve:hover {
        background: #16a34a;
        color: white;
    }

    .approval-btn.reject {
        background: #fee2e2;
        color: #dc2626;
    }

    .approval-btn.reject:hover {
        background: #dc2626;
        color: white;
    }

    .view-all-link {
        display: block;
        text-align: center;
        padding: 12px;
        color: #0d9488;
        font-weight: 600;
        text-decoration: none;
    }

    .view-all-link:hover {
        text-decoration: underline;
    }

    /* Tab Content */
    .portal-tab-content {
        background: white;
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .portal-header {
            flex-direction: column;
            text-align: center;
            gap: 20px;
            padding: 24px;
        }

        .portal-welcome h1 {
            font-size: 1.5rem;
        }

        .portal-modules-grid {
            grid-template-columns: 1fr;
        }

        .portal-module-card {
            padding: 18px;
        }

        .module-card-icon {
            width: 48px;
            height: 48px;
        }

        .approval-info {
            flex-direction: column;
            align-items: flex-start;
            gap: 5px;
        }
    }
</style>