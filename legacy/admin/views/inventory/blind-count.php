<?php
/**
 * Inventário Cego (Blind Count) - Admin View
 * 
 * Permite realizar contagem física de estoque sem visualizar
 * as quantidades teóricas do sistema para auditoria imparcial.
 * 
 * @package SISTUR
 * @subpackage Stock
 * @since 2.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(__('Acesso negado.', 'sistur'));
}

global $wpdb;
?>

<div class="wrap sistur-blind-inventory">
    <h1>📋
        <?php _e('Inventário Cego', 'sistur'); ?>
    </h1>
    <p class="description">
        <?php _e('Contagem física sem visualizar estoque do sistema para auditoria imparcial.', 'sistur'); ?>
    </p>

    <!-- Sessões Existentes -->
    <div class="sistur-section">
        <h2>
            <?php _e('Sessões de Inventário', 'sistur'); ?>
        </h2>
        <div id="sessions-container">
            <table class="wp-list-table widefat fixed striped" id="sessions-table">
                <thead>
                    <tr>
                        <th>
                            <?php _e('ID', 'sistur'); ?>
                        </th>
                        <th>
                            <?php _e('Status', 'sistur'); ?>
                        </th>
                        <th>
                            <?php _e('Usuário', 'sistur'); ?>
                        </th>
                        <th>
                            <?php _e('Início', 'sistur'); ?>
                        </th>
                        <th>
                            <?php _e('Progresso', 'sistur'); ?>
                        </th>
                        <th>
                            <?php _e('Divergência', 'sistur'); ?>
                        </th>
                        <th>
                            <?php _e('Ações', 'sistur'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody id="sessions-body">
                    <tr>
                        <td colspan="7" class="loading">
                            <?php _e('Carregando...', 'sistur'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="session-actions" style="margin-top: 15px;">
            <button type="button" id="start-new-session" class="button button-primary">
                ➕
                <?php _e('Iniciar Nova Contagem', 'sistur'); ?>
            </button>
        </div>

        <!-- Formulário de nova sessão (oculto por padrão) -->
        <div id="new-session-form" style="display: none; margin-top: 15px; background: #f0f6fc; padding: 15px; border-radius: 4px; border: 1px solid #c3c4c7;">
            <h3 style="margin-top: 0;"><?php _e('Configurar Nova Contagem', 'sistur'); ?></h3>
            <table class="form-table" style="margin: 0;">
                <tr>
                    <th style="padding: 8px 10px 8px 0; width: 120px;">
                        <label for="session-location"><?php _e('Local', 'sistur'); ?></label>
                    </th>
                    <td style="padding: 8px 0;">
                        <select id="session-location" style="min-width: 200px;">
                            <option value=""><?php _e('Todos os locais', 'sistur'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th style="padding: 8px 10px 8px 0;">
                        <label for="session-sector"><?php _e('Setor', 'sistur'); ?></label>
                    </th>
                    <td style="padding: 8px 0;">
                        <select id="session-sector" style="min-width: 200px;" disabled>
                            <option value=""><?php _e('Todos os setores', 'sistur'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th style="padding: 8px 10px 8px 0;">
                        <label for="session-notes"><?php _e('Observações', 'sistur'); ?></label>
                    </th>
                    <td style="padding: 8px 0;">
                        <input type="text" id="session-notes" class="regular-text" placeholder="<?php _e('Opcional...', 'sistur'); ?>">
                    </td>
                </tr>
            </table>
            <div style="margin-top: 10px;">
                <button type="button" id="confirm-new-session" class="button button-primary">
                    <?php _e('Confirmar e Iniciar', 'sistur'); ?>
                </button>
                <button type="button" id="cancel-new-session" class="button" style="margin-left: 5px;">
                    <?php _e('Cancelar', 'sistur'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Contagem Ativa (hidden by default) -->
    <div class="sistur-section" id="active-count-section" style="display: none;">
        <h2>
            <?php _e('Contagem em Andamento', 'sistur'); ?> - <span id="session-id-display"></span>
        </h2>

        <!-- Wizard Progress Indicator (v2.15.0) -->
        <div id="wizard-progress" style="display: none; margin-bottom: 15px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <div style="font-size: 14px; font-weight: 600; color: #1d2327;">
                    📍 <span id="wizard-sector-label"></span>
                </div>
                <div style="font-size: 13px; color: #666;">
                    <?php _e('Setor', 'sistur'); ?> <span id="wizard-current-step">1</span> <?php _e('de', 'sistur'); ?> <span id="wizard-total-steps">1</span>
                </div>
            </div>
            <div class="wizard-progress-bar">
                <div class="wizard-progress-fill" id="wizard-progress-fill" style="width: 0%;"></div>
            </div>
            <div id="wizard-sectors-dots" style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;"></div>
        </div>

        <div
            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; background: #f0f6fc; padding: 10px; border-radius: 4px;">
            <div class="count-progress" style="margin: 0; background: none; padding: 0;">
                <span id="items-filled">0</span> / <span id="items-total">0</span>
                <?php _e('itens contados', 'sistur'); ?>
            </div>
            <button type="button" id="add-item-btn" class="button button-small">
                ➕ <?php _e('Adicionar Item', 'sistur'); ?>
            </button>
        </div>

        <table class="wp-list-table widefat fixed striped" id="count-table">
            <thead>
                <tr>
                    <th style="width: 40%;">
                        <?php _e('Produto', 'sistur'); ?>
                    </th>
                    <th style="width: 15%;">
                        <?php _e('Lote (Opcional)', 'sistur'); ?>
                    </th>
                    <th style="width: 15%;">
                        <?php _e('Validade (Opcional)', 'sistur'); ?>
                    </th>
                    <th style="width: 15%;">
                        <?php _e('Unidade', 'sistur'); ?>
                    </th>
                    <th style="width: 15%;">
                        <?php _e('Qtd Física', 'sistur'); ?>
                    </th>
                </tr>
            </thead>
            <tbody id="count-body">
            </tbody>
        </table>

        <div class="count-actions" style="margin-top: 20px;">
            <button type="button" id="save-progress" class="button">
                💾
                <?php _e('Salvar Progresso', 'sistur'); ?>
            </button>
            <!-- Wizard: Next sector button (v2.15.0) -->
            <button type="button" id="wizard-next-sector" class="button button-primary" style="margin-left: 10px; display: none;">
                ➡️ <?php _e('Próximo Setor', 'sistur'); ?>
            </button>
            <button type="button" id="submit-count" class="button button-primary" style="margin-left: 10px;">
                ✅
                <?php _e('Finalizar e Processar', 'sistur'); ?>
            </button>
            <button type="button" id="cancel-count" class="button" style="margin-left: 10px;">
                ❌
                <?php _e('Voltar', 'sistur'); ?>
            </button>
        </div>
    </div>

    <!-- Relatório (hidden by default) -->
    <div class="sistur-section" id="report-section" style="display: none;">
        <h2>
            <?php _e('Relatório de Divergências', 'sistur'); ?> - <span id="report-session-id"></span>
        </h2>
        // ... (omitted for brevity, assume unchanged or handle differently if needed)
        // Actually, I should just target the table part or better yet, use specific targets.
        // The file has JS inside PHP.
        // I'll replace renderCountItems separately? No, I need to replace HTML table AND JS renderCountItems.
        // Let's do HTML first.

        // Wait, I can't split easily.
        // I'll target the HTML Table part first (lines 93-109).
        // Then I'll target JS renderCountItems (lines 403-428).
        // And JS Save logic (lines 436-470).

        // REPLACEMENT 1: HTML Table Headers


        <div class="count-actions" style="margin-top: 20px;">
            <button type="button" id="save-progress" class="button">
                💾
                <?php _e('Salvar Progresso', 'sistur'); ?>
            </button>
            <button type="button" id="submit-count" class="button button-primary" style="margin-left: 10px;">
                ✅
                <?php _e('Finalizar e Processar', 'sistur'); ?>
            </button>
            <button type="button" id="cancel-count" class="button" style="margin-left: 10px;">
                ❌
                <?php _e('Voltar', 'sistur'); ?>
            </button>
        </div>
    </div>

    <!-- Relatório (hidden by default) -->
    <div class="sistur-section" id="report-section" style="display: none;">
        <h2>
            <?php _e('Relatório de Divergências', 'sistur'); ?> - <span id="report-session-id"></span>
        </h2>

        <div id="submission-feedback"
            style="display: none; margin-bottom: 20px; padding: 15px; border: 1px solid #c3e6cb; background-color: #d4edda; border-radius: 4px;">
            <h3 style="margin-top: 0; color: #155724;"><?php _e('Resultado do Processamento', 'sistur'); ?></h3>
            <ul id="feedback-list" style="margin: 0; padding-left: 20px; color: #155724;"></ul>
        </div>

        <div class="report-summary" id="report-summary">
            <!-- Filled by JS -->
        </div>

        <table class="wp-list-table widefat fixed striped" id="report-table">
            <thead>
                <tr>
                    <th>
                        <?php _e('Produto', 'sistur'); ?>
                    </th>
                    <th>
                        <?php _e('Teórico', 'sistur'); ?>
                    </th>
                    <th>
                        <?php _e('Físico', 'sistur'); ?>
                    </th>
                    <th>
                        <?php _e('Divergência', 'sistur'); ?>
                    </th>
                    <th>
                        <?php _e('Custo Unit.', 'sistur'); ?>
                    </th>
                    <th>
                        <?php _e('Impacto R$', 'sistur'); ?>
                    </th>
                    <th>
                        <?php _e('Status', 'sistur'); ?>
                    </th>
                </tr>
            </thead>
            <tbody id="report-body">
            </tbody>
        </table>

        <div class="report-actions" style="margin-top: 20px;">
            <button type="button" id="back-to-sessions" class="button">
                ←
                <?php _e('Voltar às Sessões', 'sistur'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal Adicionar Item -->
<div id="add-item-modal"
    style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; justify-content: center; align-items: center;">
    <div
        style="background: #fff; padding: 20px; width: 400px; border-radius: 5px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <h3 style="margin-top: 0; display: flex; justify-content: space-between;">
            <?php _e('Adicionar Item', 'sistur'); ?>
            <button type="button" id="close-add-modal"
                style="background:none; border:none; cursor:pointer; font-size: 20px;">&times;</button>
        </h3>
        <p style="margin-bottom: 10px; font-size: 13px; color: #666;">
            <?php _e('Busque e adicione um produto que não estava na lista original.', 'sistur'); ?></p>

        <input type="text" id="product-search-input" class="regular-text"
            placeholder="<?php _e('Digite nome do produto...', 'sistur'); ?>"
            style="width: 100%; box-sizing: border-box; padding: 8px;">

        <div id="search-spinner" style="display: none; margin: 10px 0; color: #666;">
            <?php _e('Buscando...', 'sistur'); ?></div>

        <ul id="product-results-list"
            style="margin: 10px 0; max-height: 200px; overflow-y: auto; border: 1px solid #ddd; display: none; list-style: none; padding: 0;">
        </ul>
    </div>
</div>

<style>
    /* Add Item Modal Styles */
    #product-results-list li {
        padding: 10px;
        border-bottom: 1px solid #eee;
        cursor: pointer;
    }

    #product-results-list li:hover {
        background: #f0f6fc;
    }

    #product-results-list li:last-child {
        border-bottom: none;
    }

    .highlight-required {
        border-color: #dc3545 !important;
        box-shadow: 0 0 0 1px #dc3545;
    }

    .sistur-blind-inventory {
        max-width: 1200px;
    }

    .sistur-section {
        background: #fff;
        padding: 20px;
        margin: 20px 0;
        border: 1px solid #ccd0d4;
        border-radius: 4px;
    }

    .sistur-section h2 {
        margin-top: 0;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }

    .count-progress {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 15px;
        padding: 10px;
        background: #f0f6fc;
        border-radius: 4px;
    }

    .report-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }

    .report-summary .summary-card {
        padding: 15px;
        border-radius: 6px;
        text-align: center;
    }

    .report-summary .summary-card.loss {
        background: #fce4ec;
        border: 1px solid #f8bbd9;
    }

    .report-summary .summary-card.gain {
        background: #e8f5e9;
        border: 1px solid #c8e6c9;
    }

    .report-summary .summary-card.neutral {
        background: #f5f5f5;
        border: 1px solid #e0e0e0;
    }

    .summary-card .value {
        font-size: 24px;
        font-weight: 700;
    }

    .summary-card .label {
        font-size: 12px;
        color: #666;
        margin-top: 5px;
    }

    .status-badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
    }

    .status-in_progress {
        background: #fff3cd;
        color: #856404;
    }

    .status-completed {
        background: #d4edda;
        color: #155724;
    }

    .status-cancelled {
        background: #f8d7da;
        color: #721c24;
    }

    .divergence-positive {
        color: #28a745;
    }

    .divergence-negative {
        color: #dc3545;
    }

    .quantity-input {
        width: 100%;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }

    .quantity-input:focus {
        border-color: #007cba;
        outline: none;
    }

    .quantity-input.filled {
        background: #e8f5e9;
        border-color: #4caf50;
    }

    /* Wizard Progress Styles (v2.15.0) */
    .wizard-progress-bar {
        width: 100%;
        height: 8px;
        background: #e0e0e0;
        border-radius: 4px;
        overflow: hidden;
    }

    .wizard-progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #2271b1, #135e96);
        border-radius: 4px;
        transition: width 0.4s ease;
    }

    .wizard-dot {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 700;
        border: 2px solid #c3c4c7;
        background: #fff;
        color: #666;
        transition: all 0.3s ease;
        cursor: default;
        position: relative;
    }

    .wizard-dot.completed {
        background: #2271b1;
        border-color: #2271b1;
        color: #fff;
    }

    .wizard-dot.active {
        border-color: #2271b1;
        background: #f0f6fc;
        color: #2271b1;
        box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.2);
    }

    .wizard-dot .dot-tooltip {
        display: none;
        position: absolute;
        bottom: 110%;
        left: 50%;
        transform: translateX(-50%);
        background: #1d2327;
        color: #fff;
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 11px;
        white-space: nowrap;
        z-index: 10;
    }

    .wizard-dot:hover .dot-tooltip {
        display: block;
    }

    #wizard-next-sector {
        font-size: 14px;
        padding: 6px 16px;
    }
</style>

<script>
    jQuery(document).ready(function ($) {
        const apiBase = '<?php echo rest_url('sistur/v1/stock/blind-inventory'); ?>';
        const stockApiBase = '<?php echo rest_url('sistur/v1/stock'); ?>';
        const nonce = '<?php echo wp_create_nonce('wp_rest'); ?>';

        let currentSessionId = null;
        let sessionItems = [];
        let locationsCache = [];

        // Wizard state (v2.15.0)
        let wizardMode = false;
        let wizardData = null; // { sectors_list, sectors_names, current_sector_index, total_sectors }
        let wizardCompleted = false;

        // Carregar locais para o formulário de nova sessão
        function loadLocationsForSession() {
            $.ajax({
                url: stockApiBase + '/locations',
                method: 'GET',
                headers: { 'X-WP-Nonce': nonce }
            }).done(function (locations) {
                locationsCache = locations;
                const $loc = $('#session-location');
                $loc.find('option:not(:first)').remove();
                locations.forEach(function (l) {
                    $loc.append('<option value="' + l.id + '">' + l.name + '</option>');
                });
            });
        }

        // Cascata: local -> setores
        $('#session-location').on('change', function () {
            const locId = parseInt($(this).val());
            const $sec = $('#session-sector');
            $sec.find('option:not(:first)').remove();

            if (!locId) {
                $sec.prop('disabled', true);
                return;
            }

            const loc = locationsCache.find(function (l) { return l.id === locId; });
            if (loc && loc.sectors && loc.sectors.length > 0) {
                loc.sectors.forEach(function (s) {
                    $sec.append('<option value="' + s.id + '">' + s.name + '</option>');
                });
                $sec.prop('disabled', false);
            } else {
                $sec.prop('disabled', true);
            }
        });

        // Helper function for API calls
        function apiCall(method, endpoint, data = null) {
            const options = {
                url: apiBase + endpoint,
                method: method,
                headers: { 'X-WP-Nonce': nonce },
                contentType: 'application/json'
            };
            if (data) {
                options.data = JSON.stringify(data);
            }
            return $.ajax(options);
        }

        // Load sessions
        function loadSessions() {
            apiCall('GET', '').done(function (response) {
                if (response.success) {
                    renderSessions(response.sessions);
                }
            });
        }

        // Render sessions table
        function renderSessions(sessions) {
            const tbody = $('#sessions-body');
            tbody.empty();

            if (sessions.length === 0) {
                tbody.html('<tr><td colspan="7"><?php _e('Nenhuma sessão encontrada. Inicie uma nova contagem.', 'sistur'); ?></td></tr>');
                return;
            }

            sessions.forEach(function (s) {
                const statusClass = 'status-' + s.status;
                const statusText = {
                    'in_progress': '<?php _e('Em Andamento', 'sistur'); ?>',
                    'completed': '<?php _e('Concluída', 'sistur'); ?>',
                    'cancelled': '<?php _e('Cancelada', 'sistur'); ?>'
                }[s.status];

                let actions = '';
                if (s.status === 'in_progress') {
                    actions = '<button class="button continue-count" data-id="' + s.id + '"><?php _e('Continuar', 'sistur'); ?></button>';
                } else if (s.status === 'completed') {
                    actions = '<button class="button view-report" data-id="' + s.id + '"><?php _e('Ver Relatório', 'sistur'); ?></button>';
                }

                tbody.append(`
                <tr>
                    <td>#${s.id}</td>
                    <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                    <td>${s.user_name || '-'}</td>
                    <td>${s.started_at}</td>
                    <td>${s.items_filled}/${s.items_count}</td>
                    <td>R$ ${s.total_divergence_value.toFixed(2)}</td>
                    <td>${actions}</td>
                </tr>
            `);
            });
        }

        // Mostrar formulário de nova sessão
        $('#start-new-session').on('click', function () {
            loadLocationsForSession();
            $('#new-session-form').slideDown(200);
            $(this).hide();
        });

        // Cancelar nova sessão
        $('#cancel-new-session').on('click', function () {
            $('#new-session-form').slideUp(200);
            $('#start-new-session').show();
        });

        // Confirmar e iniciar nova sessão
        $('#confirm-new-session').on('click', function () {
            const btn = $(this);
            btn.prop('disabled', true).text('<?php _e('Iniciando...', 'sistur'); ?>');

            const data = {
                notes: $('#session-notes').val() || ''
            };
            const locId = $('#session-location').val();
            const secId = $('#session-sector').val();
            if (locId) data.location_id = parseInt(locId);
            if (secId) data.sector_id = parseInt(secId);

            apiCall('POST', '/start', data).done(function (response) {
                if (response.success) {
                    $('#new-session-form').slideUp(200);
                    $('#start-new-session').show();
                    $('#session-notes').val('');
                    $('#session-location').val('');
                    $('#session-sector').val('').prop('disabled', true);
                    alert('<?php _e('Sessão criada! ', 'sistur'); ?>' + response.message);
                    openCountSession(response.session_id);
                } else {
                    alert('<?php _e('Erro: ', 'sistur'); ?>' + response.message);
                }
            }).fail(function () {
                alert('<?php _e('Erro ao criar sessão', 'sistur'); ?>');
            }).always(function () {
                btn.prop('disabled', false).text('<?php _e('Confirmar e Iniciar', 'sistur'); ?>');
            });
        });

        // Open count session
        function openCountSession(sessionId) {
            currentSessionId = sessionId;
            $('#session-id-display').text('#' + sessionId);
            wizardMode = false;
            wizardData = null;
            wizardCompleted = false;

            apiCall('GET', '/' + sessionId).done(function (response) {
                if (response.success) {
                    // Check if wizard mode
                    if (response.wizard && response.wizard.wizard_mode) {
                        wizardMode = true;
                        wizardData = response.wizard;
                        initWizardUI();
                        loadCurrentSectorItems();
                    } else {
                        // Normal flat-list mode
                        wizardMode = false;
                        $('#wizard-progress').hide();
                        $('#wizard-next-sector').hide();
                        $('#submit-count').show();
                        sessionItems = response.items;
                        renderCountItems(response.items);
                    }
                    $('#sessions-container').hide();
                    $('.session-actions').hide();
                    $('#active-count-section').show();
                }
            });
        }

        // ============================================
        // Wizard Functions (v2.15.0)
        // ============================================

        /**
         * Initialize the Wizard UI with progress dots and labels
         */
        function initWizardUI() {
            if (!wizardData) return;

            const $progress = $('#wizard-progress');
            $progress.show();

            // Show/hide buttons based on wizard state
            updateWizardButtons();

            // Build sector dots
            const $dots = $('#wizard-sectors-dots');
            $dots.empty();

            wizardData.sectors_list.forEach(function (sectorId, idx) {
                const name = wizardData.sectors_names[sectorId] || 'Setor ' + (idx + 1);
                let dotClass = 'wizard-dot';
                if (idx < wizardData.current_sector_index) dotClass += ' completed';
                if (idx === wizardData.current_sector_index) dotClass += ' active';

                $dots.append(`
                    <div class="${dotClass}" data-index="${idx}">
                        ${idx < wizardData.current_sector_index ? '✓' : (idx + 1)}
                        <span class="dot-tooltip">${name}</span>
                    </div>
                `);
            });

            // Update labels
            const currentName = wizardData.current_sector_name || 'Setor ' + (wizardData.current_sector_index + 1);
            $('#wizard-sector-label').text(currentName);
            $('#wizard-current-step').text(wizardData.current_sector_index + 1);
            $('#wizard-total-steps').text(wizardData.total_sectors);

            // Progress bar
            const pct = ((wizardData.current_sector_index) / wizardData.total_sectors) * 100;
            $('#wizard-progress-fill').css('width', pct + '%');
        }

        /**
         * Show/hide wizard navigation buttons based on current state
         */
        function updateWizardButtons() {
            if (!wizardMode) return;

            if (wizardCompleted) {
                // All sectors done - show Submit, hide Next
                $('#wizard-next-sector').hide();
                $('#submit-count').show();
            } else {
                // Still navigating sectors - show Next, hide Submit
                $('#wizard-next-sector').show();
                $('#submit-count').hide();

                // Update button text for last sector
                if (wizardData && wizardData.current_sector_index >= wizardData.total_sectors - 1) {
                    $('#wizard-next-sector').html('✅ <?php _e("Concluir Setores", "sistur"); ?>');
                } else {
                    $('#wizard-next-sector').html('➡️ <?php _e("Próximo Setor", "sistur"); ?>');
                }
            }
        }

        /**
         * Load items for the current sector via the API
         */
        function loadCurrentSectorItems() {
            apiCall('GET', '/' + currentSessionId + '/current-sector').done(function (response) {
                if (response.success) {
                    sessionItems = response.items;
                    renderCountItems(response.items);

                    // Update wizard data with latest sector info
                    if (response.sector) {
                        wizardData.current_sector_index = response.sector.index;
                        wizardData.current_sector_name = response.sector.name;
                        wizardData.current_sector_id = response.sector.id;

                        // Refresh the wizard UI
                        initWizardUI();
                    }
                } else {
                    alert(response.message);
                }
            }).fail(function () {
                alert('<?php _e("Erro ao carregar itens do setor", "sistur"); ?>');
            });
        }

        /**
         * Advance to next sector (wizard "Next" button)
         */
        $('#wizard-next-sector').on('click', function () {
            const btn = $(this);
            btn.prop('disabled', true);

            // First: save all pending items for the current sector
            const savePromises = [];
            $('#count-body tr').each(function () {
                const row = $(this);
                const productId = row.data('product-id');
                if (!productId) return;

                const val = row.find('.quantity-input').val();
                const batch = row.find('.batch-code-input').val();
                const expiry = row.find('.expiry-date-input').val();

                if (val !== '' || batch !== '' || expiry !== '') {
                    savePromises.push(apiCall('PUT', '/' + currentSessionId + '/item/' + productId, {
                        physical_qty: val !== '' ? parseFloat(val) : null,
                        batch_code: batch,
                        expiry_date: expiry
                    }));
                }
            });

            // Wait for saves, then advance
            $.when.apply($, savePromises).done(function () {
                apiCall('POST', '/' + currentSessionId + '/advance').done(function (response) {
                    if (response.success) {
                        if (response.completed) {
                            // All sectors done!
                            wizardCompleted = true;
                            updateWizardButtons();

                            // Update progress bar to 100%
                            $('#wizard-progress-fill').css('width', '100%');

                            // Mark all dots as completed
                            $('.wizard-dot').removeClass('active').addClass('completed').each(function () {
                                $(this).text('✓');
                            });
                            $('#wizard-sector-label').text('<?php _e("Todos os setores concluídos!", "sistur"); ?>');

                            // Load ALL items for the final review
                            apiCall('GET', '/' + currentSessionId).done(function (resp) {
                                if (resp.success) {
                                    sessionItems = resp.items;
                                    renderCountItems(resp.items);
                                }
                            });
                        } else {
                            // Move to the next sector
                            wizardData.current_sector_index = response.current_sector_index;
                            if (response.sector) {
                                wizardData.current_sector_name = response.sector.name;
                                wizardData.current_sector_id = response.sector.id;
                            }
                            initWizardUI();
                            loadCurrentSectorItems();

                            // Scroll to top
                            $('html, body').animate({ scrollTop: $('#active-count-section').offset().top - 50 }, 300);
                        }
                    } else {
                        alert(response.message);
                    }
                }).fail(function () {
                    alert('<?php _e("Erro ao avançar setor", "sistur"); ?>');
                }).always(function () {
                    btn.prop('disabled', false);
                });
            }).fail(function () {
                alert('<?php _e("Erro ao salvar itens do setor atual", "sistur"); ?>');
                btn.prop('disabled', false);
            });
        });

        // Render count items
        function renderCountItems(items) {
            const tbody = $('#count-body');
            tbody.empty();

            if (items.length === 0) {
                tbody.html('<tr><td colspan="5" style="text-align: center; padding: 20px; color: #666;">' +
                    '<?php _e('Nenhum item esperado neste local. Utilize o botão "Adicionar Item" se necessário.', 'sistur'); ?>' +
                    '</td></tr>');
            }

            let filled = 0;
            items.forEach(function (item) {
                const qtyInputClass = item.physical_qty !== null ? 'quantity-input filled' : 'quantity-input';
                const qtyValue = item.physical_qty !== null ? item.physical_qty : '';

                const batchCodeValue = item.batch_code || '';
                const expiryDateValue = item.expiry_date || '';
                const theoreticalQty = item.theoretical_qty || 0;

                if (item.physical_qty !== null) filled++;

                tbody.append(`
                <tr data-product-id="${item.product_id}" data-theoretical-qty="${theoreticalQty}">
                    <td>${item.product_name}</td>
                    <td>
                         <input type="text" 
                               class="batch-code-input" 
                               value="${batchCodeValue}"
                               placeholder="<?php _e('Código do Lote', 'sistur'); ?>"
                               style="width: 100%;">
                    </td>
                    <td>
                         <input type="date" 
                               class="expiry-date-input" 
                               value="${expiryDateValue}"
                               style="width: 100%;">
                    </td>
                    <td>${item.unit_symbol || 'un'}</td>
                    <td>
                        <input type="number" 
                               class="${qtyInputClass}" 
                               value="${qtyValue}"
                               step="0.001"
                               min="0"
                               placeholder="<?php _e('Quantidade contada', 'sistur'); ?>"
                               style="width: 100%;">
                    </td>
                </tr>
            `);
            });

            $('#items-filled').text(filled);
            $('#items-total').text(items.length);
        }

        // Auto-save on input change (debounced)
        let saveTimeout;
        $(document).on('change', '.quantity-input, .batch-code-input, .expiry-date-input', function () {
            const input = $(this);
            const row = input.closest('tr');
            const productId = row.data('product-id');
            const theoreticalQty = parseFloat(row.data('theoretical-qty')) || 0;

            const qtyInput = row.find('.quantity-input');
            const batchInput = row.find('.batch-code-input');
            const expiryInput = row.find('.expiry-date-input');

            const physical_qty_val = qtyInput.val();
            const physical_qty = physical_qty_val !== '' ? parseFloat(physical_qty_val) : null;
            const batch_code = batchInput.val();
            const expiry_date = expiryInput.val();

            // Visual feedback for filled quantity
            if (physical_qty !== null) {
                qtyInput.addClass('filled');
            } else {
                qtyInput.removeClass('filled');
            }

            // Check Surplus -> Prompt Expiry
            if (physical_qty !== null && physical_qty > theoreticalQty) {
                if (!expiry_date) {
                    // Highlight expiry input to prompt user
                    expiryInput.addClass('highlight-required');
                    // Optional: You could show a tooltip or toast here
                    // If we have a known current date for this product (from previous logic?), we could pre-fill
                    // For now, just visually prompt
                    expiryInput.focus();
                } else {
                    expiryInput.removeClass('highlight-required');
                }
            } else {
                expiryInput.removeClass('highlight-required');
            }

            // Update filled count
            const filled = $('.quantity-input.filled').length;
            $('#items-filled').text(filled);

            // Debounced save
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(function () {
                // Send update if any field is changed
                apiCall('PUT', '/' + currentSessionId + '/item/' + productId, {
                    physical_qty: physical_qty,
                    batch_code: batch_code,
                    expiry_date: expiry_date
                });
            }, 500);
        });

        // Save progress manually
        $('#save-progress').on('click', function () {
            const btn = $(this);
            btn.prop('disabled', true).text('<?php _e('Salvando...', 'sistur'); ?>');

            // Save all rows with at least one field filled
            const promises = [];
            $('#count-body tr').each(function () {
                const row = $(this);
                const productId = row.data('product-id');

                const qtyInput = row.find('.quantity-input');
                const batchInput = row.find('.batch-code-input');
                const expiryInput = row.find('.expiry-date-input');

                const val = qtyInput.val();
                const batch = batchInput.val();
                const expiry = expiryInput.val();

                if (val !== '' || batch !== '' || expiry !== '') {
                    promises.push(apiCall('PUT', '/' + currentSessionId + '/item/' + productId, {
                        physical_qty: val !== '' ? parseFloat(val) : null,
                        batch_code: batch,
                        expiry_date: expiry
                    }));
                }
            });

            $.when.apply($, promises).done(function () {
                alert('<?php _e('Progresso salvo!', 'sistur'); ?>');
            }).always(function () {
                btn.prop('disabled', false).html('💾 <?php _e('Salvar Progresso', 'sistur'); ?>');
            });
        });

        // --- ADD ITEM LOGIC ---
        const addModal = $('#add-item-modal');
        const searchInput = $('#product-search-input');
        const resultsList = $('#product-results-list');
        let searchTimeout;

        $('#add-item-btn').on('click', function () {
            addModal.css('display', 'flex');
            searchInput.val('').focus();
            resultsList.empty().hide();
        });

        $('#close-add-modal').on('click', function () {
            addModal.hide();
        });

        // Close on clicking outside
        addModal.on('click', function (e) {
            if (e.target === this) $(this).hide();
        });

        // Search Product
        searchInput.on('input', function () {
            const term = $(this).val().trim();
            if (term.length < 3) {
                resultsList.empty().hide();
                return;
            }

            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function () {
                $('#search-spinner').show();
                // Call general product search
                apiCall('GET', stockApiBase + '/products?search=' + encodeURIComponent(term) + '&per_page=10').done(function (response) {
                    $('#search-spinner').hide();
                    resultsList.empty().show();

                    const products = response.products || [];

                    if (products.length === 0) {
                        resultsList.html('<li style="padding: 10px; color: #666;"><?php _e('Nenhum produto encontrado.', 'sistur'); ?></li>');
                        return;
                    }

                    resultsList.empty();
                    products.forEach(function (p) {
                        // Check if already in sessionItems
                        const exists = sessionItems.find(i => i.product_id == p.id);
                        const style = exists ? 'opacity: 0.5; cursor: not-allowed;' : '';
                        const label = exists ? ' (<?php _e('Já na lista', 'sistur'); ?>)' : '';

                        const li = $(`
                           <li style="${style}" ${exists ? '' : 'class="add-candidate"'} data-id="${p.id}">
                               ${p.name} <small>(${p.unit_symbol})</small>${label}
                           </li>
                       `);
                        resultsList.append(li);
                    });
                }).fail(function () {
                    $('#search-spinner').hide();
                    resultsList.html('<li style="color: red; padding: 10px;"><?php _e('Erro ao buscar.', 'sistur'); ?></li>').show();
                });
            }, 500);
        });

        // Add item click
        $(document).on('click', '.add-candidate', function () {
            const prodId = $(this).data('id');
            if (!confirm('<?php _e('Adicionar este produto à contagem?', 'sistur'); ?>')) return;

            addModal.hide();
            // Call Add Endpoint
            apiCall('POST', '/' + currentSessionId + '/add', { product_id: prodId }).done(function (response) {
                if (response.success) {
                    alert('<?php _e('Item adicionado!', 'sistur'); ?>');
                    // Append to sessionItems
                    sessionItems.push(response.item);
                    renderCountItems(sessionItems);

                    // Highlight the new row
                    setTimeout(() => {
                        const newRow = $(`tr[data-product-id="${prodId}"]`);
                        newRow.css('background-color', '#e8f5e9');
                        setTimeout(() => newRow.css('background-color', ''), 2000);

                        $('html, body').animate({
                            scrollTop: newRow.offset().top - 200
                        }, 500);

                        newRow.find('.quantity-input').focus();
                    }, 100);
                } else {
                    alert('<?php _e('Erro: ', 'sistur'); ?>' + response.message);
                }
            });
        });

        // Submit count
        $('#submit-count').on('click', function () {
            const filled = $('.quantity-input.filled').length;
            const total = $('.quantity-input').length;

            if (total === 0) {
                if (!confirm('<?php _e('Nenhum item foi adicionado à contagem. Deseja finalizar o inventário como vazio? (Isso pode zerar o estoque deste local)', 'sistur'); ?>')) {
                    return;
                }
            } else if (filled === 0) {
                alert('<?php _e('Insira pelo menos uma quantidade antes de finalizar.', 'sistur'); ?>');
                return;
            }

            if (filled < total) {
                if (!confirm('<?php _e('Apenas ', 'sistur'); ?>' + filled + '/' + total + ' <?php _e(' itens foram contados. Deseja finalizar mesmo assim?', 'sistur'); ?>')) {
                    return;
                }
            }

            const btn = $(this);
            btn.prop('disabled', true).text('<?php _e('Processando...', 'sistur'); ?>');

            apiCall('POST', '/' + currentSessionId + '/submit').done(function (response) {
                if (response.success) {
                    alert('<?php _e('Inventário processado! ', 'sistur'); ?>\n' +
                        '<?php _e('Perdas criadas: ', 'sistur'); ?>' + response.summary.losses_created + '\n' +
                        '<?php _e('Ajustes criados: ', 'sistur'); ?>' + response.summary.adjustments_created + '\n' +
                        '<?php _e('Impacto total: R$ ', 'sistur'); ?>' + response.summary.total_divergence_value.toFixed(2));

                    // Pass results to openReport
                    openReport(currentSessionId, response.results);
                } else {
                    alert('<?php _e('Erro: ', 'sistur'); ?>' + response.message);
                }
            }).fail(function () {
                alert('<?php _e('Erro ao processar inventário', 'sistur'); ?>');
            }).always(function () {
                btn.prop('disabled', false).html('✅ <?php _e('Finalizar e Processar', 'sistur'); ?>');
            });
        });

        // Cancel/back
        $('#cancel-count').on('click', function () {
            $('#active-count-section').hide();
            $('#sessions-container').show();
            $('.session-actions').show();
            loadSessions();
        });

        // Continue session
        $(document).on('click', '.continue-count', function () {
            openCountSession($(this).data('id'));
        });

        // View report
        $(document).on('click', '.view-report', function () {
            openReport($(this).data('id'));
        });

        // Open report
        function openReport(sessionId, submissionResults = null) {
            $('#report-session-id').text('#' + sessionId);

            apiCall('GET', '/' + sessionId + '/report').done(function (response) {
                if (response.success) {
                    renderReport(response);

                    // Show submission feedback if available
                    const feedbackDiv = $('#submission-feedback');
                    const feedbackList = $('#feedback-list');
                    if (submissionResults && submissionResults.length > 0) {
                        feedbackList.empty();
                        submissionResults.forEach(function (res) {
                            let icon = 'ℹ️';
                            if (res.status.includes('loss')) icon = '📉';
                            if (res.status.includes('surplus')) icon = '📈';

                            // Translate status if needed or just show message
                            // The message from backend is already formatted in Portuguese
                            feedbackList.append(`<li>${icon} <strong>${res.product_name}:</strong> ${res.message}</li>`);
                        });
                        feedbackDiv.show();
                    } else {
                        feedbackDiv.hide();
                    }

                    $('#sessions-container').hide();
                    $('.session-actions').hide();
                    $('#active-count-section').hide();
                    $('#report-section').show();
                }
            });
        }

        // Render report
        function renderReport(data) {
            const summary = data.summary;

            $('#report-summary').html(`
            <div class="summary-card neutral">
                <div class="value">${summary.total_items}</div>
                <div class="label"><?php _e('Itens Contados', 'sistur'); ?></div>
            </div>
            <div class="summary-card loss">
                <div class="value">${summary.items_with_loss}</div>
                <div class="label"><?php _e('Com Falta', 'sistur'); ?></div>
            </div>
            <div class="summary-card gain">
                <div class="value">${summary.items_with_gain}</div>
                <div class="label"><?php _e('Com Sobra', 'sistur'); ?></div>
            </div>
            <div class="summary-card loss">
                <div class="value">R$ ${summary.total_losses_value.toFixed(2)}</div>
                <div class="label"><?php _e('Perdas', 'sistur'); ?></div>
            </div>
            <div class="summary-card gain">
                <div class="value">R$ ${summary.total_gains_value.toFixed(2)}</div>
                <div class="label"><?php _e('Ganhos', 'sistur'); ?></div>
            </div>
            <div class="summary-card ${summary.net_impact < 0 ? 'loss' : 'gain'}">
                <div class="value">R$ ${summary.net_impact.toFixed(2)}</div>
                <div class="label"><?php _e('Impacto Líquido', 'sistur'); ?></div>
            </div>
        `);

            const tbody = $('#report-body');
            tbody.empty();

            data.items.forEach(function (item) {
                const divClass = item.divergence < 0 ? 'divergence-negative' : (item.divergence > 0 ? 'divergence-positive' : '');
                const status = item.has_loss ? '<span class="status-badge status-cancelled"><?php _e('Perda Registrada', 'sistur'); ?></span>' :
                    (item.divergence > 0 ? '<span class="status-badge status-completed"><?php _e('Ajuste Criado', 'sistur'); ?></span>' : '-');

                tbody.append(`
                <tr>
                    <td>${item.product_name}</td>
                    <td>${item.theoretical_qty} ${item.unit || ''}</td>
                    <td>${item.physical_qty} ${item.unit || ''}</td>
                    <td class="${divClass}">${item.divergence > 0 ? '+' : ''}${item.divergence}</td>
                    <td>R$ ${item.unit_cost.toFixed(4)}</td>
                    <td class="${divClass}">R$ ${item.divergence_value.toFixed(2)}</td>
                    <td>${status}</td>
                </tr>
            `);
            });
        }

        // Back to sessions
        $('#back-to-sessions').on('click', function () {
            $('#report-section').hide();
            $('#sessions-container').show();
            $('.session-actions').show();
            loadSessions();
        });

        // Initial load
        loadSessions();
    });
</script>