<?php
/**
 * Módulo de RH - Template Principal
 * 
 * Interface com navegação por abas SPA-style.
 * Visibilidade das abas controlada por permissões.
 * 
 * @package SISTUR
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$rh_module = SISTUR_RH_Module::get_instance();
$permissions = $rh_module->get_current_user_permissions();
?>

<div class="sistur-rh-wrapper">
    <!-- Header do Módulo -->
    <?php if (empty($is_embedded)): ?>
        <header class="sistur-rh-header">
            <div class="sistur-rh-header__container">
                <div class="sistur-rh-header__title">
                    <span class="sistur-rh-header__icon">👥</span>
                    <h1>Recursos Humanos</h1>
                </div>
                <div class="sistur-rh-header__actions">
                    <a href="<?php echo esc_url(home_url('/areafuncionario')); ?>"
                        class="sistur-rh-btn sistur-rh-btn--secondary">
                        ← Voltar ao Dashboard
                    </a>
                </div>
            </div>
        </header>
    <?php endif; ?>

    <!-- Navegação por Abas -->
    <nav class="sistur-rh-tabs">
        <div class="sistur-rh-tabs__container">
            <?php if ($permissions['view_dashboard'] || $permissions['is_admin']): ?>
                <button class="sistur-rh-tabs__tab sistur-rh-tabs__tab--active" data-tab="dashboard">
                    <span class="sistur-rh-tabs__icon">📊</span>
                    <span class="sistur-rh-tabs__label">Dashboard</span>
                </button>
            <?php endif; ?>

            <?php if ($permissions['view_employees'] || $permissions['is_admin']): ?>
                <button class="sistur-rh-tabs__tab" data-tab="colaboradores">
                    <span class="sistur-rh-tabs__icon">👤</span>
                    <span class="sistur-rh-tabs__label">Colaboradores</span>
                </button>
            <?php endif; ?>

            <?php if ($permissions['manage_timebank'] || $permissions['is_admin']): ?>
                <button class="sistur-rh-tabs__tab" data-tab="banco-horas">
                    <span class="sistur-rh-tabs__icon">⏱️</span>
                    <span class="sistur-rh-tabs__label">Ponto / Banco de Horas</span>
                </button>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Conteúdo das Abas -->
    <main class="sistur-rh-content">

        <!-- ABA: DASHBOARD -->
        <?php if ($permissions['view_dashboard'] || $permissions['is_admin']): ?>
            <section class="sistur-rh-panel sistur-rh-panel--active" data-panel="dashboard">
                <div class="sistur-rh-panel__header">
                    <h2>Visão Geral</h2>
                </div>

                <!-- Cards de Métricas -->
                <div class="sistur-rh-stats">
                    <div class="sistur-rh-stat sistur-rh-stat--blue">
                        <div class="sistur-rh-stat__icon">👥</div>
                        <div class="sistur-rh-stat__content">
                            <span class="sistur-rh-stat__value" id="stat-total-employees">--</span>
                            <span class="sistur-rh-stat__label">Funcionários Ativos</span>
                        </div>
                    </div>

                    <div class="sistur-rh-stat sistur-rh-stat--orange">
                        <div class="sistur-rh-stat__icon">⚠️</div>
                        <div class="sistur-rh-stat__content">
                            <span class="sistur-rh-stat__value" id="stat-incomplete-punches">--</span>
                            <span class="sistur-rh-stat__label">Batidas Incompletas</span>
                        </div>
                    </div>



                    <div class="sistur-rh-stat sistur-rh-stat--purple">
                        <div class="sistur-rh-stat__icon">📋</div>
                        <div class="sistur-rh-stat__content">
                            <span class="sistur-rh-stat__value" id="stat-pending">--</span>
                            <span class="sistur-rh-stat__label">Pendências</span>
                        </div>
                    </div>
                </div>


                <!-- Planilha de Batidas por Funcionário -->
                <div class="sistur-rh-section">
                    <div class="sistur-rh-section__header">
                        <h3 class="sistur-rh-section__title">
                            <span>📋</span> Planilha de Batidas (Mensal)
                        </h3>
                        <div class="sistur-rh-section__actions">
                            <select class="sistur-rh-select sistur-rh-select--sm" id="punches-month-select">
                                <?php
                                // Gerar opções para os últimos 6 meses
                                for ($i = 0; $i < 6; $i++) {
                                    $date = strtotime("-{$i} months");
                                    $value = date('Y-m', $date);
                                    $label = ucfirst(strftime('%B %Y', $date));
                                    $selected = $i === 0 ? 'selected' : '';
                                    echo "<option value=\"{$value}\" {$selected}>{$label}</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="sistur-rh-spreadsheet-container">
                        <table class="sistur-rh-spreadsheet" id="punches-spreadsheet">
                            <thead id="punches-spreadsheet-header">
                                <tr>
                                    <th class="sistur-rh-spreadsheet__label">Carregando...</th>
                                </tr>
                            </thead>
                            <tbody id="punches-spreadsheet-body">
                                <tr>
                                    <td colspan="32" class="sistur-rh-table__loading">Carregando dados...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Alertas de Batidas -->
                <div class="sistur-rh-section">
                    <h3 class="sistur-rh-section__title">
                        <span>⚠️</span> Alertas de Batidas (Últimos 7 dias)
                    </h3>
                    <div class="sistur-rh-table-container">
                        <table class="sistur-rh-table" id="punch-alerts-table">
                            <thead>
                                <tr>
                                    <th>Funcionário</th>
                                    <th>Data</th>
                                    <th>Batidas</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="4" class="sistur-rh-table__loading">Carregando...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- ABA: COLABORADORES -->
        <?php if ($permissions['view_employees'] || $permissions['is_admin']): ?>
            <section class="sistur-rh-panel" data-panel="colaboradores">
                <div class="sistur-rh-panel__header">
                    <h2>Colaboradores</h2>
                    <div class="sistur-rh-panel__actions">
                        <button class="sistur-rh-btn sistur-rh-btn--primary" id="btn-new-employee">
                            + Novo
                        </button>
                        <input type="search" class="sistur-rh-input" id="employee-search"
                            placeholder="Buscar por nome, email ou CPF...">
                        <select class="sistur-rh-select" id="employee-status-filter">
                            <option value="active">Ativos</option>
                            <option value="inactive">Inativos</option>
                            <option value="all">Todos</option>
                        </select>
                    </div>
                </div>

                <div class="sistur-rh-table-container">
                    <table class="sistur-rh-table" id="employees-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Cargo</th>
                                <th>Departamento</th>
                                <th>Status</th>
                                <th>Banco de Horas</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="5" class="sistur-rh-table__loading">Carregando...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="sistur-rh-pagination" id="employees-pagination"></div>
            </section>
        <?php endif; ?>

        <!-- ABA: BANCO DE HORAS -->
        <?php if ($permissions['manage_timebank'] || $permissions['is_admin']): ?>
            <section class="sistur-rh-panel" data-panel="banco-horas">
                <div class="sistur-rh-panel__header">
                    <h2>Ponto / Banco de Horas</h2>
                </div>

                <!-- Seletor de Funcionário -->
                <div class="sistur-rh-section">
                    <div class="sistur-rh-form-group">
                        <label for="timebank-employee-select">Selecione o Funcionário:</label>
                        <select class="sistur-rh-select sistur-rh-select--large" id="timebank-employee-select">
                            <option value="">-- Selecione --</option>
                        </select>
                    </div>
                </div>

                <!-- Detalhes do Funcionário Selecionado -->
                <div class="sistur-rh-timebank-details" id="timebank-details" style="display: none;">
                    <!-- Saldo Atual -->
                    <div class="sistur-rh-balance-card">
                        <div class="sistur-rh-balance-card__header">
                            <span id="timebank-employee-name">--</span>
                        </div>
                        <div class="sistur-rh-balance-card__body">
                            <div class="sistur-rh-balance-card__value" id="timebank-balance">--</div>
                        </div>
                    </div>

                    <!-- Resumo Semanal/Mensal (Auditoria) -->
                    <div class="sistur-rh-audit-summary">
                        <div class="sistur-rh-audit-card">
                            <div class="sistur-rh-audit-card__icon">📅</div>
                            <div class="sistur-rh-audit-card__content">
                                <span class="sistur-rh-audit-card__label">Esta Semana</span>
                                <span class="sistur-rh-audit-card__value" id="audit-week-worked">--</span>
                                <span class="sistur-rh-audit-card__sub" id="audit-week-deviation">--</span>
                            </div>
                        </div>
                        <div class="sistur-rh-audit-card">
                            <div class="sistur-rh-audit-card__icon">📊</div>
                            <div class="sistur-rh-audit-card__content">
                                <span class="sistur-rh-audit-card__label">Este Mês</span>
                                <span class="sistur-rh-audit-card__value" id="audit-month-worked">--</span>
                                <span class="sistur-rh-audit-card__sub" id="audit-month-deviation">--</span>
                            </div>
                        </div>
                        <div class="sistur-rh-audit-card">
                            <div class="sistur-rh-audit-card__icon">🏦</div>
                            <div class="sistur-rh-audit-card__content">
                                <span class="sistur-rh-audit-card__label">Banco Acumulado</span>
                                <span class="sistur-rh-audit-card__value" id="audit-accumulated">--</span>
                                <span class="sistur-rh-audit-card__sub" id="audit-accumulated-sub">Saldo total</span>
                            </div>
                        </div>
                    </div>

                    <!-- Planilha de Batidas (Auditoria) -->
                    <div class="sistur-rh-section">
                        <h3 class="sistur-rh-section__title">
                            <span>📋</span> Histórico de Batidas
                            <div class="sistur-rh-section__actions">
                                <input type="month" class="sistur-rh-input" id="audit-punch-month" style="width: auto;">
                                <button class="sistur-rh-btn sistur-rh-btn--secondary" id="btn-load-punch-history">
                                    Carregar
                                </button>
                            </div>
                        </h3>
                        <div class="sistur-rh-table-container">
                            <table class="sistur-rh-table" id="audit-punch-table">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Entrada</th>
                                        <th>Início Almoço</th>
                                        <th>Fim Almoço</th>
                                        <th>Saída</th>
                                        <th>Trabalhado</th>
                                        <th>Saldo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="7" class="sistur-rh-table__empty">Selecione um período e clique em
                                            "Carregar"</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Ações -->
                    <div class="sistur-rh-actions-grid">
                        <!-- Liquidar Horas -->
                        <div class="sistur-rh-action-card">
                            <h4>💰 Liquidar Horas (Pagamento)</h4>
                            <div class="sistur-rh-form-group">
                                <label for="liquidate-hours">Horas:</label>
                                <input type="number" class="sistur-rh-input" id="liquidate-hours" min="0" step="0.5"
                                    placeholder="Ex: 8">
                            </div>
                            <div class="sistur-rh-form-group">
                                <label for="liquidate-notes">Observações:</label>
                                <textarea class="sistur-rh-textarea" id="liquidate-notes" rows="2"></textarea>
                            </div>
                            <button class="sistur-rh-btn sistur-rh-btn--primary" id="btn-liquidate">
                                Liquidar Horas
                            </button>
                        </div>

                        <!-- Lançar Folga -->
                        <div class="sistur-rh-action-card">
                            <h4>🏖️ Lançar Folga (Compensação)</h4>
                            <div class="sistur-rh-form-group">
                                <label for="folga-hours">Horas:</label>
                                <input type="number" class="sistur-rh-input" id="folga-hours" min="0" step="0.5"
                                    placeholder="Ex: 8">
                            </div>
                            <div class="sistur-rh-form-group">
                                <label for="folga-start-date">Data Início:</label>
                                <input type="date" class="sistur-rh-input" id="folga-start-date">
                            </div>
                            <div class="sistur-rh-form-group">
                                <label for="folga-end-date">Data Fim:</label>
                                <input type="date" class="sistur-rh-input" id="folga-end-date">
                            </div>
                            <div class="sistur-rh-form-group">
                                <label for="folga-description">Descrição:</label>
                                <textarea class="sistur-rh-textarea" id="folga-description" rows="2"></textarea>
                            </div>
                            <button class="sistur-rh-btn sistur-rh-btn--success" id="btn-folga">
                                Lançar Folga
                            </button>
                        </div>
                    </div>

                    <!-- Histórico de Transações -->
                    <div class="sistur-rh-section">
                        <h3 class="sistur-rh-section__title">
                            <span>📜</span> Histórico de Transações
                        </h3>
                        <div class="sistur-rh-table-container">
                            <table class="sistur-rh-table" id="transactions-table">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Tipo</th>
                                        <th>Minutos</th>
                                        <th>Saldo Anterior</th>
                                        <th>Saldo Após</th>
                                        <th>Aprovado Por</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="6" class="sistur-rh-table__empty">Selecione um funcionário</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Lista geral de transações recentes -->
                <div class="sistur-rh-section" id="all-transactions-section">
                    <h3 class="sistur-rh-section__title">
                        <span>📋</span> Transações Recentes (Todos os Funcionários)
                    </h3>
                    <div class="sistur-rh-filter-row">
                        <select class="sistur-rh-select" id="transactions-type-filter">
                            <option value="">Todos os tipos</option>
                            <option value="pagamento">Pagamentos</option>
                            <option value="folga">Folgas</option>
                        </select>
                    </div>
                    <div class="sistur-rh-table-container">
                        <table class="sistur-rh-table" id="all-transactions-table">
                            <thead>
                                <tr>
                                    <th>Funcionário</th>
                                    <th>Data</th>
                                    <th>Tipo</th>
                                    <th>Minutos</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="5" class="sistur-rh-table__loading">Carregando...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>

    </main>

    <!-- Modal de Edição de Funcionário -->
    <div class="sistur-rh-modal" id="employee-modal" style="display: none;">
        <div class="sistur-rh-modal__overlay"></div>
        <div class="sistur-rh-modal__content">
            <div class="sistur-rh-modal__header">
                <h3>Editar Funcionário</h3>
                <button class="sistur-rh-modal__close" id="close-employee-modal">&times;</button>
            </div>
            <div class="sistur-rh-modal__body">
                <form id="employee-edit-form">
                    <input type="hidden" id="edit-employee-id">

                    <div class="sistur-rh-form-group">
                        <label for="edit-employee-name">Nome *</label>
                        <input type="text" class="sistur-rh-input" id="edit-employee-name" required>
                    </div>

                    <div class="sistur-rh-form-row">
                        <div class="sistur-rh-form-group">
                            <label for="edit-employee-email">Email</label>
                            <input type="email" class="sistur-rh-input" id="edit-employee-email">
                        </div>
                        <div class="sistur-rh-form-group">
                            <label for="edit-employee-phone">Telefone</label>
                            <input type="text" class="sistur-rh-input" id="edit-employee-phone">
                        </div>
                    </div>

                    <div class="sistur-rh-form-row">
                        <div class="sistur-rh-form-group">
                            <label for="edit-employee-cpf">CPF</label>
                            <input type="text" class="sistur-rh-input" id="edit-employee-cpf"
                                placeholder="000.000.000-00">
                        </div>
                        <div class="sistur-rh-form-group">
                            <label for="edit-employee-matricula">Matrícula</label>
                            <input type="text" class="sistur-rh-input" id="edit-employee-matricula">
                        </div>
                    </div>

                    <div class="sistur-rh-form-row">
                        <div class="sistur-rh-form-group" style="flex: 2;">
                            <label for="edit-employee-ctps">CTPS</label>
                            <input type="text" class="sistur-rh-input" id="edit-employee-ctps">
                        </div>
                        <div class="sistur-rh-form-group" style="flex: 1;">
                            <label for="edit-employee-ctps-uf">UF</label>
                            <input type="text" class="sistur-rh-input" id="edit-employee-ctps-uf" maxlength="2"
                                style="text-transform: uppercase;">
                        </div>
                        <div class="sistur-rh-form-group" style="flex: 2;">
                            <label for="edit-employee-cbo">CBO</label>
                            <input type="text" class="sistur-rh-input" id="edit-employee-cbo">
                        </div>
                    </div>

                    <div class="sistur-rh-form-group" id="password-group">
                        <label for="edit-employee-password">Senha de Acesso <span
                                id="password-required">*</span></label>
                        <input type="password" class="sistur-rh-input" id="edit-employee-password"
                            placeholder="Digite a senha para acesso ao painel">
                        <small id="password-hint">Senha usada para login no painel do funcionário</small>
                    </div>

                    <div class="sistur-rh-form-row">
                        <div class="sistur-rh-form-group" style="flex: 1;">
                            <label for="edit-employee-position">Cargo</label>
                            <input type="text" class="sistur-rh-input" id="edit-employee-position">
                        </div>
                        <div class="sistur-rh-form-group"
                            style="width: 100px; display: flex; align-items: flex-end; padding-bottom: 12px;">
                            <label
                                style="cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 500;">
                                <input type="checkbox" id="edit-employee-status">
                                Ativo
                            </label>
                        </div>
                    </div>

                    <div class="sistur-rh-form-row">
                        <div class="sistur-rh-form-group">
                            <label for="edit-employee-department">Departamento</label>
                            <select class="sistur-rh-select" id="edit-employee-department"></select>
                        </div>
                        <div class="sistur-rh-form-group">
                            <label for="edit-employee-role">Papel/Permissões</label>
                            <select class="sistur-rh-select" id="edit-employee-role"></select>
                        </div>
                    </div>

                    <div class="sistur-rh-form-row">
                        <div class="sistur-rh-form-group">
                            <label for="edit-employee-hire-date">Data de Contratação</label>
                            <input type="date" class="sistur-rh-input" id="edit-employee-hire-date">
                        </div>
                        <div class="sistur-rh-form-group">
                            <label for="edit-employee-shift-pattern">Escala de Trabalho *</label>
                            <select class="sistur-rh-select" id="edit-employee-shift-pattern" required></select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="sistur-rh-modal__footer">
                <button class="sistur-rh-btn sistur-rh-btn--secondary" id="cancel-employee-edit">Cancelar</button>
                <button class="sistur-rh-btn sistur-rh-btn--primary" id="save-employee-edit">Salvar</button>
            </div>
        </div>
    </div>

    <!-- Toast de Notificações -->
    <div class="sistur-rh-toast" id="toast-container"></div>
</div>