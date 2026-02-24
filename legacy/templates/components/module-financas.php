<?php
/**
 * Componente: Módulo de Finanças
 * 
 * Funcionalidades financeiras para o portal do colaborador:
 * - Resumo financeiro
 * - Controle de pagamentos
 * - Relatórios
 * 
 * @package SISTUR
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Verificar permissão de finanças
$permissions = SISTUR_Permissions::get_instance();
$can_manage_finance = $permissions->can($employee['id'], 'finance', 'manage') || 
                      $permissions->can($employee['id'], 'payments', 'view') ||
                      !empty($employee['role_name']);
?>

<div class="financas-container">
    <div class="financas-header">
        <h2>
            <span class="dashicons dashicons-chart-area"></span>
            <?php _e('Finanças', 'sistur'); ?>
        </h2>
        <p class="financas-subtitle"><?php _e('Controle financeiro e relatórios', 'sistur'); ?></p>
    </div>

    <?php if (!$can_manage_finance): ?>
        <div class="financas-no-permission">
            <span class="dashicons dashicons-lock"></span>
            <p><?php _e('Você não tem permissão para acessar esta área.', 'sistur'); ?></p>
        </div>
    <?php else: ?>

    <!-- Cards de Resumo -->
    <div class="financas-cards">
        <div class="finance-card" style="--card-color: #10b981;">
            <div class="finance-card-icon">
                <span class="dashicons dashicons-money-alt"></span>
            </div>
            <div class="finance-card-content">
                <span class="finance-card-label">Receitas do Mês</span>
                <span class="finance-card-valor">R$ --,--</span>
            </div>
        </div>

        <div class="finance-card" style="--card-color: #ef4444;">
            <div class="finance-card-icon">
                <span class="dashicons dashicons-arrow-down-alt"></span>
            </div>
            <div class="finance-card-content">
                <span class="finance-card-label">Despesas do Mês</span>
                <span class="finance-card-valor">R$ --,--</span>
            </div>
        </div>

        <div class="finance-card" style="--card-color: #3b82f6;">
            <div class="finance-card-icon">
                <span class="dashicons dashicons-chart-line"></span>
            </div>
            <div class="finance-card-content">
                <span class="finance-card-label">Saldo</span>
                <span class="finance-card-valor">R$ --,--</span>
            </div>
        </div>

        <div class="finance-card" style="--card-color: #eab308;">
            <div class="finance-card-icon">
                <span class="dashicons dashicons-calendar-alt"></span>
            </div>
            <div class="finance-card-content">
                <span class="finance-card-label">A Receber</span>
                <span class="finance-card-valor">R$ --,--</span>
            </div>
        </div>
    </div>

    <!-- Ações Rápidas -->
    <div class="financas-acoes">
        <h3><?php _e('Ações Rápidas', 'sistur'); ?></h3>
        <div class="acoes-grid">
            <button class="acao-btn" onclick="alert('🔧 Em desenvolvimento')">
                <span class="dashicons dashicons-plus-alt2"></span>
                <span>Nova Receita</span>
            </button>
            <button class="acao-btn" onclick="alert('🔧 Em desenvolvimento')">
                <span class="dashicons dashicons-minus"></span>
                <span>Nova Despesa</span>
            </button>
            <button class="acao-btn" onclick="alert('🔧 Em desenvolvimento')">
                <span class="dashicons dashicons-media-spreadsheet"></span>
                <span>Relatório</span>
            </button>
            <button class="acao-btn" onclick="alert('🔧 Em desenvolvimento')">
                <span class="dashicons dashicons-backup"></span>
                <span>Fluxo de Caixa</span>
            </button>
        </div>
    </div>

    <!-- Aviso de Em Desenvolvimento -->
    <div class="financas-dev-notice">
        <span class="dashicons dashicons-hammer"></span>
        <div>
            <strong><?php _e('Módulo em Desenvolvimento', 'sistur'); ?></strong>
            <p><?php _e('Este módulo está sendo construído. Em breve teremos funcionalidades completas de controle financeiro.', 'sistur'); ?></p>
        </div>
    </div>

    <?php endif; ?>
</div>

<style>
.financas-container {
    padding: 20px 0;
}

.financas-header h2 {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 5px 0;
    font-size: 1.5rem;
    color: #1e293b;
}

.financas-header h2 .dashicons {
    color: #eab308;
}

.financas-subtitle {
    color: #64748b;
    margin: 0 0 24px 0;
}

.financas-no-permission {
    text-align: center;
    padding: 60px 20px;
    color: #64748b;
}

.financas-no-permission .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
    display: block;
    margin: 0 auto 15px;
}

/* Cards */
.financas-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 30px;
}

.finance-card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    border-left: 4px solid var(--card-color, #3b82f6);
    transition: all 0.2s;
}

.finance-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    transform: translateY(-2px);
}

.finance-card-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    background: color-mix(in srgb, var(--card-color) 15%, white);
    display: flex;
    align-items: center;
    justify-content: center;
}

.finance-card-icon .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
    color: var(--card-color);
}

.finance-card-content {
    display: flex;
    flex-direction: column;
}

.finance-card-label {
    font-size: 0.85rem;
    color: #64748b;
    margin-bottom: 4px;
}

.finance-card-valor {
    font-size: 1.4rem;
    font-weight: 700;
    color: #1e293b;
}

/* Ações */
.financas-acoes {
    margin-bottom: 30px;
}

.financas-acoes h3 {
    font-size: 1rem;
    color: #374151;
    margin: 0 0 16px 0;
}

.acoes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
}

.acao-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 20px 16px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s;
    color: #374151;
    font-size: 0.9rem;
}

.acao-btn:hover {
    background: #eab308;
    color: white;
    border-color: #eab308;
}

.acao-btn .dashicons {
    font-size: 28px;
    width: 28px;
    height: 28px;
}

/* Aviso */
.financas-dev-notice {
    display: flex;
    gap: 16px;
    padding: 20px;
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border-radius: 12px;
    border: 1px solid #fcd34d;
}

.financas-dev-notice .dashicons {
    font-size: 32px;
    width: 32px;
    height: 32px;
    color: #b45309;
}

.financas-dev-notice strong {
    display: block;
    color: #92400e;
    margin-bottom: 4px;
}

.financas-dev-notice p {
    margin: 0;
    color: #a16207;
    font-size: 0.9rem;
}

@media (max-width: 768px) {
    .financas-cards {
        grid-template-columns: 1fr;
    }
    
    .acoes-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>
