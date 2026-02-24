<?php
/**
 * Componente: Módulo Restaurante (CMV Dashboard + PDV)
 *
 * Dashboard visual com:
 * - Compass gauge para CMV% vs 30% target
 * - Bar chart: Compras vs Vendas
 * - Pie chart: Categorias de Perdas
 * - Filtro por período
 *
 * @package SISTUR
 * @subpackage Components
 * @since 2.1.0 - Initial version
 * @since 2.9.1 - Enhanced with charts
 */

$session = SISTUR_Session::get_instance();
$permissions = SISTUR_Permissions::get_instance();
$employee_id = $session->get_employee_id();

$can_view_cmv = $permissions->can($employee_id, 'cmv', 'view');
$can_edit_cmv = $permissions->can($employee_id, 'cmv', 'edit');
$can_edit_restaurant = $permissions->can($employee_id, 'restaurant', 'edit');

// Default date range: current month
$default_start = date('Y-m-01');
$default_end = date('Y-m-d');
$is_macro_mode = get_option('sistur_sales_mode', 'MICRO') === 'MACRO';
?>

<div class="module-restaurante">
    <h2><?php _e('Gestão do Restaurante', 'sistur'); ?></h2>
    
    <!-- Tab Navigation -->
    <div class="restaurante-tabs">
        <?php if ($can_view_cmv): ?>
            <button class="restaurante-tab active" data-target="cmv-content">
                <span class="dashicons dashicons-chart-pie"></span>
                <?php _e('CMV', 'sistur'); ?>
            </button>
        <?php endif; ?>
        <button class="restaurante-tab<?php echo !$can_view_cmv ? ' active' : ''; ?>" data-target="pdv-content">
            <span class="dashicons dashicons-store"></span>
            <?php _e('PDV', 'sistur'); ?>
        </button>
        <?php if ($can_edit_restaurant && $is_macro_mode): ?>
            <button class="restaurante-tab" data-target="fechar-caixa-content">
                <span class="dashicons dashicons-money-alt"></span>
                <?php _e('Fechar Caixa', 'sistur'); ?>
            </button>
        <?php endif; ?>
    </div>
    
    <!-- Tab Contents -->
    <div class="restaurante-tab-contents">
        <?php if ($can_view_cmv): ?>
            <div id="cmv-content" class="restaurante-tab-content active">
                
                <!-- Date Range Picker -->
                <div class="cmv-date-picker">
                    <div class="date-inputs">
                        <div class="date-field">
                            <label for="cmv-start-date"><?php _e('De:', 'sistur'); ?></label>
                            <input type="date" id="cmv-start-date" value="<?php echo $default_start; ?>">
                        </div>
                        <div class="date-field">
                            <label for="cmv-end-date"><?php _e('Até:', 'sistur'); ?></label>
                            <input type="date" id="cmv-end-date" value="<?php echo $default_end; ?>">
                        </div>
                        <button type="button" id="cmv-generate-btn" class="cmv-btn primary">
                            <span class="dashicons dashicons-chart-bar"></span>
                            <?php _e('Gerar Relatório', 'sistur'); ?>
                        </button>
                    </div>
                    <div class="quick-periods">
                        <button type="button" class="quick-period" data-period="week"><?php _e('Semana', 'sistur'); ?></button>
                        <button type="button" class="quick-period" data-period="month"><?php _e('Mês', 'sistur'); ?></button>
                        <button type="button" class="quick-period" data-period="quarter"><?php _e('Trimestre', 'sistur'); ?></button>
                    </div>
                </div>

                <!-- Loading State -->
                <div id="cmv-loading" class="cmv-loading" style="display: none;">
                    <div class="loading-spinner"></div>
                    <p><?php _e('Calculando relatório...', 'sistur'); ?></p>
                </div>

                <!-- Dashboard Content -->
                <div id="cmv-dashboard" style="display: none;">
                    
                    <!-- Top Row: Compass + Summary Cards -->
                    <div class="cmv-top-row">
                        
                        <!-- CMV Compass Gauge -->
                        <div class="cmv-compass-card">
                            <h3><?php _e('CMV do Período', 'sistur'); ?></h3>
                            <div class="compass-container">
                                <svg viewBox="0 0 200 120" class="compass-svg">
                                    <!-- Background arc (gray) -->
                                    <path d="M 20 100 A 80 80 0 0 1 180 100" 
                                          stroke="#e5e7eb" stroke-width="16" fill="none" stroke-linecap="round"/>
                                    
                                    <!-- Green zone (0-30%) -->
                                    <path d="M 20 100 A 80 80 0 0 1 67 32" 
                                          stroke="#10b981" stroke-width="16" fill="none" stroke-linecap="round"/>
                                    
                                    <!-- Yellow zone (30-40%) -->
                                    <path d="M 67 32 A 80 80 0 0 1 100 20" 
                                          stroke="#f59e0b" stroke-width="16" fill="none"/>
                                    
                                    <!-- Red zone (40%+) -->
                                    <path d="M 100 20 A 80 80 0 0 1 180 100" 
                                          stroke="#ef4444" stroke-width="16" fill="none" stroke-linecap="round"/>
                                    
                                    <!-- Needle -->
                                    <line id="compass-needle" x1="100" y1="100" x2="100" y2="30" 
                                          stroke="#1f2937" stroke-width="3" stroke-linecap="round"
                                          transform="rotate(0, 100, 100)"/>
                                    
                                    <!-- Center dot -->
                                    <circle cx="100" cy="100" r="8" fill="#1f2937"/>
                                    
                                    <!-- Labels -->
                                    <text x="15" y="115" font-size="10" fill="#6b7280">0%</text>
                                    <text x="85" y="15" font-size="10" fill="#6b7280">50%</text>
                                    <text x="175" y="115" font-size="10" fill="#6b7280">100%</text>
                                </svg>
                                <div class="compass-value">
                                    <span id="cmv-percent-value">--</span>%
                                </div>
                                <div class="compass-target"><?php _e('Meta: 30%', 'sistur'); ?></div>
                            </div>
                            <div id="cmv-status-badge" class="status-badge"></div>
                        </div>
                        
                        <!-- Summary Cards -->
                        <div class="cmv-summary-cards">
                            <div class="summary-card revenue" data-detail="revenue" title="<?php esc_attr_e('Clique para ver detalhes', 'sistur'); ?>">
                                <span class="card-icon">💰</span>
                                <div class="card-content">
                                    <span class="card-label"><?php _e('Receita', 'sistur'); ?></span>
                                    <span class="card-value" id="cmv-revenue">R$ 0,00</span>
                                </div>
                                <span class="card-detail-hint">↗</span>
                            </div>
                            <div class="summary-card cost" data-detail="cmv" title="<?php esc_attr_e('Clique para ver detalhes', 'sistur'); ?>">
                                <span class="card-icon">📦</span>
                                <div class="card-content">
                                    <span class="card-label"><?php _e('CMV (Custo)', 'sistur'); ?></span>
                                    <span class="card-value" id="cmv-cost">R$ 0,00</span>
                                </div>
                                <span class="card-detail-hint">↗</span>
                            </div>
                            <div class="summary-card result" data-detail="result" title="<?php esc_attr_e('Clique para ver detalhes', 'sistur'); ?>">
                                <span class="card-icon">📈</span>
                                <div class="card-content">
                                    <span class="card-label"><?php _e('Resultado Bruto', 'sistur'); ?></span>
                                    <span class="card-value" id="cmv-result">R$ 0,00</span>
                                </div>
                                <span class="card-detail-hint">↗</span>
                            </div>
                            <div class="summary-card losses" data-detail="losses" title="<?php esc_attr_e('Clique para ver detalhes', 'sistur'); ?>">
                                <span class="card-icon">⚠️</span>
                                <div class="card-content">
                                    <span class="card-label"><?php _e('Perdas', 'sistur'); ?></span>
                                    <span class="card-value" id="cmv-losses">R$ 0,00</span>
                                </div>
                                <span class="card-detail-hint">↗</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Detail Panel (hidden until card click) -->
                    <div id="cmv-detail-panel" class="cmv-detail-panel" style="display:none;">
                        <div class="cdp-header">
                            <strong id="cdp-title"></strong>
                            <button id="cdp-close" class="cdp-close-btn" aria-label="Fechar">✕</button>
                        </div>
                        <div id="cdp-body" class="cdp-body"></div>
                    </div>

                    <!-- Charts Row -->
                    <div class="cmv-charts-row">
                        
                        <!-- Bar Chart: Purchases vs Sales -->
                        <div class="cmv-chart-card">
                            <h4><?php _e('Compras vs Vendas', 'sistur'); ?></h4>
                            <div class="chart-container">
                                <canvas id="purchases-sales-chart"></canvas>
                            </div>
                        </div>
                        
                        <!-- Pie Chart: Loss Categories -->
                        <div class="cmv-chart-card">
                            <h4><?php _e('Categorias de Perdas', 'sistur'); ?></h4>
                            <div class="chart-container">
                                <canvas id="losses-chart"></canvas>
                            </div>
                            <div id="losses-empty" class="chart-empty" style="display: none;">
                                <span>✓</span>
                                <p><?php _e('Nenhuma perda registrada no período!', 'sistur'); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- CMV Breakdown Section (espelho do Admin) -->
                    <div class="cmv-breakdown-section">
                        <div class="cmv-breakdown-card">
                            <h4><?php _e('Composição do CMV', 'sistur'); ?></h4>
                            <table class="cmv-breakdown-table">
                                <tbody id="cmv-breakdown-body">
                                    <tr>
                                        <td><span class="op">+</span> <?php _e('Estoque Inicial', 'sistur'); ?></td>
                                        <td class="value" id="breakdown-opening">R$ 0,00</td>
                                    </tr>
                                    <tr>
                                        <td><span class="op">+</span> <?php _e('Compras no Período', 'sistur'); ?></td>
                                        <td class="value" id="breakdown-purchases">R$ 0,00</td>
                                    </tr>
                                    <tr>
                                        <td><span class="op">−</span> <?php _e('Estoque Final', 'sistur'); ?></td>
                                        <td class="value" id="breakdown-closing">R$ 0,00</td>
                                    </tr>
                                    <tr class="total-row">
                                        <td><span class="op">=</span> <strong><?php _e('CMV', 'sistur'); ?></strong></td>
                                        <td class="value" id="breakdown-cmv"><strong>R$ 0,00</strong></td>
                                    </tr>
                                    <tr class="losses-row" id="breakdown-losses-row" style="display: none;">
                                        <td><span class="sub">↳</span> <?php _e('Perdas no Período', 'sistur'); ?></td>
                                        <td class="value losses" id="breakdown-losses">R$ 0,00</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Vendas por Categoria -->
                        <div class="cmv-category-card" id="category-section" style="display: none;">
                            <h4><?php _e('Vendas por Categoria', 'sistur'); ?></h4>
                            <table class="cmv-category-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('Categoria', 'sistur'); ?></th>
                                        <th class="num"><?php _e('Receita', 'sistur'); ?></th>
                                        <th class="num"><?php _e('Custo', 'sistur'); ?></th>
                                        <th class="num"><?php _e('Margem', 'sistur'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="category-body">
                                    <!-- Preenchido via JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <?php if ($can_edit_cmv): ?>
                        <div class="cmv-actions">
                            <a href="<?php echo admin_url('admin.php?page=sistur-cmv-dashboard'); ?>" 
                               class="cmv-action-btn primary">
                                <span class="dashicons dashicons-chart-bar"></span>
                                <?php _e('Relatório Completo (Admin)', 'sistur'); ?>
                            </a>
                            <a href="#tab-estoque" data-tab="tab-estoque"
                               class="cmv-action-btn secondary portal-nav-link">
                                <span class="dashicons dashicons-archive"></span>
                                <?php _e('Gestão de Estoque', 'sistur'); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Empty State -->
                <div id="cmv-empty" class="cmv-empty">
                    <span class="empty-icon">📊</span>
                    <p><?php _e('Selecione um período e clique em "Gerar Relatório" para visualizar os dados.', 'sistur'); ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <div id="pdv-content" class="restaurante-tab-content<?php echo !$can_view_cmv ? ' active' : ''; ?>">
            <div class="pdv-placeholder">
                <span class="placeholder-icon">🏪</span>
                <h3><?php _e('PDV - Ponto de Venda', 'sistur'); ?></h3>
                <p><?php _e('Módulo em desenvolvimento. Em breve você poderá registrar vendas diretamente aqui.', 'sistur'); ?></p>
                <div class="coming-soon-features">
                    <h4><?php _e('Funcionalidades Planejadas:', 'sistur'); ?></h4>
                    <ul>
                        <li>✓ <?php _e('Registro rápido de vendas', 'sistur'); ?></li>
                        <li>✓ <?php _e('Baixa automática de estoque', 'sistur'); ?></li>
                        <li>✓ <?php _e('Histórico de vendas', 'sistur'); ?></li>
                        <li>✓ <?php _e('Integração com CMV', 'sistur'); ?></li>
                    </ul>
                </div>
            </div>
        </div>

        <?php if ($can_edit_restaurant && $is_macro_mode): ?>
        <div id="fechar-caixa-content" class="restaurante-tab-content">

            <!-- ── Formulário de lançamento ─────────────────────────────────── -->
            <div class="fc-card" style="margin-bottom:24px;">
                <h3 class="fc-card-title"><?php _e('Registrar Faturamento do Dia', 'sistur'); ?></h3>

                <div class="fc-form-row">
                    <label class="fc-label" for="fc-date"><?php _e('Data', 'sistur'); ?></label>
                    <input type="date" id="fc-date" class="fc-input" value="<?php echo esc_attr(date('Y-m-d')); ?>" />
                </div>

                <div class="fc-form-row">
                    <label class="fc-label" for="fc-category"><?php _e('Categoria', 'sistur'); ?></label>
                    <select id="fc-category" class="fc-input">
                        <option value="Restaurante"><?php _e('Restaurante', 'sistur'); ?></option>
                        <option value="Bar"><?php _e('Bar', 'sistur'); ?></option>
                        <option value="Eventos"><?php _e('Eventos', 'sistur'); ?></option>
                        <option value="Delivery"><?php _e('Delivery', 'sistur'); ?></option>
                        <option value="Geral"><?php _e('Geral', 'sistur'); ?></option>
                    </select>
                </div>

                <div class="fc-form-row">
                    <label class="fc-label" for="fc-amount"><?php _e('Valor (R$)', 'sistur'); ?></label>
                    <input type="number" id="fc-amount" class="fc-input" min="0.01" step="0.01"
                           placeholder="0,00" style="max-width:180px;" />
                </div>

                <div class="fc-form-row">
                    <label class="fc-label" for="fc-notes"><?php _e('Observações', 'sistur'); ?></label>
                    <textarea id="fc-notes" class="fc-input" rows="2"
                              placeholder="<?php esc_attr_e('Opcional — ex: evento especial, feriado...', 'sistur'); ?>"></textarea>
                </div>

                <div class="fc-form-actions">
                    <button id="fc-submit" class="cmv-btn primary">
                        <span class="dashicons dashicons-money-alt"></span>
                        <?php _e('Registrar Faturamento', 'sistur'); ?>
                    </button>
                    <span id="fc-feedback" class="fc-feedback" style="display:none;"></span>
                </div>
            </div>

            <!-- ── Filtro de período ──────────────────────────────────────────── -->
            <div class="fc-period-bar">
                <label><?php _e('Período:', 'sistur'); ?>
                    <input type="date" id="fc-filter-start" value="<?php echo esc_attr(date('Y-m-01')); ?>" />
                </label>
                <span><?php _e('até', 'sistur'); ?></span>
                <input type="date" id="fc-filter-end" value="<?php echo esc_attr(date('Y-m-d')); ?>" />
                <button id="fc-filter-btn" class="cmv-btn" style="padding:8px 16px; background:#f3f4f6; color:#374151; border:1px solid #e5e7eb;">
                    <?php _e('Filtrar', 'sistur'); ?>
                </button>
                <strong id="fc-total-label" style="font-size:15px;"></strong>
            </div>

            <!-- ── Tabela de lançamentos ──────────────────────────────────────── -->
            <div class="fc-card">
                <table class="fc-table">
                    <thead>
                        <tr>
                            <th><?php _e('Data', 'sistur'); ?></th>
                            <th><?php _e('Categoria', 'sistur'); ?></th>
                            <th><?php _e('Valor', 'sistur'); ?></th>
                            <th><?php _e('Observações', 'sistur'); ?></th>
                            <th><?php _e('Ações', 'sistur'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="fc-tbody">
                        <tr><td colspan="5" class="fc-cell-center"><?php _e('Carregando...', 'sistur'); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
(function($) {
    'use strict';
    
    // Chart instances
    let purchasesSalesChart = null;
    let lossesChart = null;

    // Cached report data (populated by fetchReport, used by detail panel)
    let _reportData  = null;
    let _lossesData  = null;
    
    // API endpoints
    const API_BASE = '<?php echo rest_url('sistur/v1'); ?>';
    const NONCE = '<?php echo wp_create_nonce('wp_rest'); ?>';
    
    // Format currency
    function formatCurrency(value) {
        return 'R$ ' + parseFloat(value).toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    
    // Calculate needle rotation (0% = -90deg, 100% = 90deg)
    function getNeedleRotation(percent) {
        const clamped = Math.min(Math.max(percent, 0), 100);
        return -90 + (clamped * 1.8); // 180deg total sweep
    }
    
    // Update compass gauge
    function updateCompass(percent, status) {
        const percentValue = document.getElementById('cmv-percent-value');
        const needle = document.getElementById('compass-needle');
        const badge = document.getElementById('cmv-status-badge');
        
        percentValue.textContent = percent.toFixed(1);
        
        const rotation = getNeedleRotation(percent);
        needle.setAttribute('transform', `rotate(${rotation}, 100, 100)`);
        
        // Status badge
        let badgeClass = 'excellent';
        let badgeText = '<?php _e('Excelente!', 'sistur'); ?>';
        
        if (percent <= 25) {
            badgeClass = 'excellent';
            badgeText = '✓ <?php _e('Excelente', 'sistur'); ?>';
        } else if (percent <= 30) {
            badgeClass = 'good';
            badgeText = '✓ <?php _e('Dentro da Meta', 'sistur'); ?>';
        } else if (percent <= 40) {
            badgeClass = 'warning';
            badgeText = '⚠ <?php _e('Atenção', 'sistur'); ?>';
        } else {
            badgeClass = 'critical';
            badgeText = '⚠ <?php _e('Crítico', 'sistur'); ?>';
        }
        
        badge.className = 'status-badge ' + badgeClass;
        badge.textContent = badgeText;
    }
    
    // Create/Update Purchases vs Sales Chart
    function updatePurchasesSalesChart(purchases, revenue) {
        const ctx = document.getElementById('purchases-sales-chart');
        
        if (purchasesSalesChart) {
            purchasesSalesChart.destroy();
        }
        
        purchasesSalesChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['<?php _e('Compras (Custo)', 'sistur'); ?>', '<?php _e('Vendas (Receita)', 'sistur'); ?>'],
                datasets: [{
                    data: [purchases, revenue],
                    backgroundColor: ['#ef4444', '#10b981'],
                    borderRadius: 8,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return formatCurrency(context.raw);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: '#f3f4f6' },
                        ticks: {
                            callback: function(value) {
                                return formatCurrency(value);
                            }
                        }
                    },
                    y: {
                        grid: { display: false }
                    }
                }
            }
        });
    }
    
    // Create/Update Losses Pie Chart
    function updateLossesChart(byReason) {
        const ctx = document.getElementById('losses-chart');
        const emptyEl = document.getElementById('losses-empty');
        
        if (lossesChart) {
            lossesChart.destroy();
        }
        
        if (!byReason || byReason.length === 0) {
            ctx.style.display = 'none';
            emptyEl.style.display = 'flex';
            return;
        }
        
        ctx.style.display = 'block';
        emptyEl.style.display = 'none';
        
        const labels = byReason.map(r => r.reason_label || r.reason);
        const data = byReason.map(r => parseFloat(r.total_value));
        
        const colors = {
            'Produto Vencido': '#ef4444',
            'Erro de Produção': '#f59e0b',
            'Dano Físico': '#8b5cf6',
            'Divergência de Inventário': '#3b82f6',
            'Outro': '#6b7280'
        };
        
        const backgroundColors = labels.map(label => colors[label] || '#6b7280');
        
        lossesChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: backgroundColors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 15, font: { size: 12 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = ((context.raw / total) * 100).toFixed(1);
                                return context.label + ': ' + formatCurrency(context.raw) + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Fetch report data
    async function fetchReport(startDate, endDate) {
        $('#cmv-loading').show();
        $('#cmv-dashboard').hide();
        $('#cmv-empty').hide();
        
        try {
            // Fetch DRE report
            const dreResponse = await fetch(
                `${API_BASE}/stock/reports/dre?start_date=${startDate}&end_date=${endDate}`,
                { headers: { 'X-WP-Nonce': NONCE } }
            );
            const dreData = await dreResponse.json();
            
            // Fetch losses summary
            const lossesResponse = await fetch(
                `${API_BASE}/stock/losses/summary?start_date=${startDate}&end_date=${endDate}`,
                { headers: { 'X-WP-Nonce': NONCE } }
            );
            const lossesData = await lossesResponse.json();
            
            if (!dreData.success) {
                throw new Error(dreData.message || 'Erro ao carregar relatório');
            }
            
            // Update UI
            const dre = dreData.dre;
            const breakdown = dreData.cmv_breakdown;
            const categories = dreData.by_category;
            
            // Summary cards
            $('#cmv-revenue').text(formatCurrency(dre.revenue));
            $('#cmv-cost').text(formatCurrency(dre.cmv));
            $('#cmv-result').text(formatCurrency(dre.gross_result));
            $('#cmv-losses').text(formatCurrency(lossesData.totals?.value || 0));
            
            // Add color classes to result
            const resultEl = $('#cmv-result');
            resultEl.removeClass('positive negative');
            resultEl.addClass(dre.gross_result >= 0 ? 'positive' : 'negative');
            
            // Update compass
            updateCompass(dre.cmv_percentage, dre.cmv_status);
            
            // Update charts
            updatePurchasesSalesChart(breakdown.purchases, dre.revenue);
            updateLossesChart(lossesData.by_reason);
            
            // Update CMV Breakdown table (espelho do Admin)
            $('#breakdown-opening').text(formatCurrency(breakdown.opening_stock || 0));
            $('#breakdown-purchases').text(formatCurrency(breakdown.purchases || 0));
            $('#breakdown-closing').text(formatCurrency(breakdown.closing_stock || 0));
            $('#breakdown-cmv').html('<strong>' + formatCurrency(breakdown.cmv || 0) + '</strong>');
            
            // Show losses row if there are losses
            if (breakdown.losses_included && parseFloat(breakdown.losses_included) > 0) {
                $('#breakdown-losses').text(formatCurrency(breakdown.losses_included));
                $('#breakdown-losses-row').show();
            } else {
                $('#breakdown-losses-row').hide();
            }
            
            // Update Categories table (espelho do Admin)
            if (categories && categories.length > 0) {
                let categoryHtml = '';
                categories.forEach(function(cat) {
                    const margin = cat.revenue > 0 ? ((cat.revenue - cat.cost) / cat.revenue * 100).toFixed(1) : 0;
                    const marginClass = margin >= 70 ? 'excellent' : (margin >= 50 ? 'good' : 'warning');
                    categoryHtml += `
                        <tr>
                            <td>${cat.category || 'Sem categoria'}</td>
                            <td class="num">${formatCurrency(cat.revenue)}</td>
                            <td class="num">${formatCurrency(cat.cost)}</td>
                            <td class="num ${marginClass}">${margin}%</td>
                        </tr>
                    `;
                });
                $('#category-body').html(categoryHtml);
                $('#category-section').show();
            } else {
                $('#category-section').hide();
            }
            
            // Cache for detail panel
            _reportData = dreData;
            _lossesData = lossesData;

            $('#cmv-loading').hide();
            $('#cmv-dashboard').show();

        } catch (error) {
            console.error('CMV Report Error:', error);
            $('#cmv-loading').hide();
            $('#cmv-empty').show();
            alert('Erro ao carregar relatório: ' + error.message);
        }
    }
    
    // Quick period buttons
    function setQuickPeriod(period) {
        const today = new Date();
        let start, end = today.toISOString().split('T')[0];
        
        switch(period) {
            case 'week':
                start = new Date(today);
                start.setDate(start.getDate() - 7);
                break;
            case 'month':
                start = new Date(today.getFullYear(), today.getMonth(), 1);
                break;
            case 'quarter':
                const quarter = Math.floor(today.getMonth() / 3);
                start = new Date(today.getFullYear(), quarter * 3, 1);
                break;
        }
        
        $('#cmv-start-date').val(start.toISOString().split('T')[0]);
        $('#cmv-end-date').val(end);
    }
    
    // Initialize
    $(document).ready(function() {
        // Tab switching
        $('.restaurante-tab').on('click', function() {
            const target = $(this).data('target');
            
            $('.restaurante-tab').removeClass('active');
            $(this).addClass('active');
            
            $('.restaurante-tab-content').removeClass('active');
            $('#' + target).addClass('active');
        });
        
        // Generate report button
        $('#cmv-generate-btn').on('click', function() {
            const startDate = $('#cmv-start-date').val();
            const endDate = $('#cmv-end-date').val();
            
            if (!startDate || !endDate) {
                alert('<?php _e('Por favor, selecione as datas de início e fim.', 'sistur'); ?>');
                return;
            }
            
            if (startDate > endDate) {
                alert('<?php _e('A data inicial não pode ser maior que a data final.', 'sistur'); ?>');
                return;
            }
            
            fetchReport(startDate, endDate);
        });
        
        // Quick period buttons
        $('.quick-period').on('click', function() {
            setQuickPeriod($(this).data('period'));
        });

        // ── Card detail drill-down ───────────────────────────────────────────
        $(document).on('click', '.summary-card[data-detail]', function() {
            const type = $(this).data('detail');
            // Toggle: click same card again closes the panel
            if ($('#cmv-detail-panel').is(':visible') &&
                $('#cmv-detail-panel').data('active-type') === type) {
                $('#cmv-detail-panel').slideUp(200);
                return;
            }
            showCmvDetail(type);
        });

        $('#cdp-close').on('click', function() {
            $('#cmv-detail-panel').slideUp(200);
        });

        // ── Fechar Caixa ────────────────────────────────────────────────────
        <?php if ($can_edit_restaurant && $is_macro_mode): ?>

        // Submit revenue
        $('#fc-submit').on('click', async function() {
            const date     = document.getElementById('fc-date').value;
            const category = document.getElementById('fc-category').value;
            const amount   = parseFloat(document.getElementById('fc-amount').value);
            const notes    = document.getElementById('fc-notes').value;

            if (!date || !amount || amount <= 0) {
                fcShowFeedback('<?php _e('Por favor, preencha Data e Valor.', 'sistur'); ?>', 'error');
                return;
            }

            this.disabled = true;
            fcShowFeedback('<?php _e('Salvando...', 'sistur'); ?>', 'info');

            try {
                const res  = await fetch(API_BASE + '/stock/revenue', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body: JSON.stringify({ revenue_date: date, total_amount: amount, category, notes })
                });
                const data = await res.json();

                if (data.success) {
                    document.getElementById('fc-amount').value = '';
                    document.getElementById('fc-notes').value  = '';
                    fcShowFeedback('<?php _e('Faturamento registrado com sucesso!', 'sistur'); ?>', 'success');
                    fcLoadRevenue();
                } else {
                    fcShowFeedback('<?php _e('Erro:', 'sistur'); ?> ' + (data.message || ''), 'error');
                }
            } catch (e) {
                fcShowFeedback('<?php _e('Erro de conexão:', 'sistur'); ?> ' + e.message, 'error');
            } finally {
                this.disabled = false;
            }
        });

        // Filter button
        $('#fc-filter-btn').on('click', fcLoadRevenue);

        // Load table when tab becomes active
        $('.restaurante-tab[data-target="fechar-caixa-content"]').on('click', function() {
            fcLoadRevenue();
        });

        <?php endif; ?>
    });

    // ── Card detail drill-down ──────────────────────────────────────────────

    // Translate loss reason codes to Portuguese
    function translateLossReason(reasonCode) {
        const translations = {
            'inventory_divergence': '<?php _e('Divergência de Inventário', 'sistur'); ?>',
            'expired_product': '<?php _e('Produto Vencido', 'sistur'); ?>',
            'physical_damage': '<?php _e('Dano Físico', 'sistur'); ?>',
            'production_error': '<?php _e('Erro de Produção', 'sistur'); ?>',
            'other': '<?php _e('Outro', 'sistur'); ?>'
        };
        return translations[reasonCode] || reasonCode;
    }

    function showCmvDetail(type) {
        if (!_reportData) return;

        const dre        = _reportData.dre;
        const breakdown  = _reportData.cmv_breakdown;
        const categories = _reportData.by_category || [];
        const byReason   = (_lossesData && _lossesData.by_reason) ? _lossesData.by_reason : [];

        const panel = $('#cmv-detail-panel');
        panel.data('active-type', type);

        let title = '';
        let html  = '';

        switch (type) {
            // ── Receita ──────────────────────────────────────────────────────
            case 'revenue':
                title = '<?php _e('Detalhes da Receita', 'sistur'); ?>';
                if (categories.length) {
                    const totalRev = categories.reduce((s, c) => s + parseFloat(c.revenue), 0);
                    html = '<table class="cdp-table"><thead><tr>' +
                        '<th><?php _e('Categoria', 'sistur'); ?></th>' +
                        '<th class="num"><?php _e('Receita', 'sistur'); ?></th>' +
                        '<th class="num"><?php _e('Participação', 'sistur'); ?></th>' +
                        '</tr></thead><tbody>';
                    categories.forEach(function(c) {
                        const pct = totalRev > 0 ? ((parseFloat(c.revenue) / totalRev) * 100).toFixed(1) : 0;
                        html += `<tr>
                            <td>${escHtmlInner(c.category || '<?php _e('Sem categoria', 'sistur'); ?>')}</td>
                            <td class="num">${formatCurrency(c.revenue)}</td>
                            <td class="num"><div class="cdp-bar-wrap"><div class="cdp-bar revenue-bar" style="width:${pct}%"></div><span>${pct}%</span></div></td>
                        </tr>`;
                    });
                    html += `<tr class="cdp-total-row"><td><strong><?php _e('Total', 'sistur'); ?></strong></td><td class="num"><strong>${formatCurrency(dre.revenue)}</strong></td><td></td></tr>`;
                    html += '</tbody></table>';
                } else {
                    html = '<p class="cdp-empty"><?php _e('Dados de categoria não disponíveis para este período.', 'sistur'); ?></p>';
                }
                break;

            // ── CMV (Custo) ──────────────────────────────────────────────────
            case 'cmv':
                title = '<?php _e('Composição do CMV', 'sistur'); ?>';
                html = '<div class="cdp-formula">' +
                    `<div class="cdp-formula-row"><span class="op plus">+</span><span class="label"><?php _e('Estoque Inicial', 'sistur'); ?></span><span class="val">${formatCurrency(breakdown.opening_stock || 0)}</span></div>` +
                    `<div class="cdp-formula-row"><span class="op plus">+</span><span class="label"><?php _e('Compras no Período', 'sistur'); ?></span><span class="val">${formatCurrency(breakdown.purchases || 0)}</span></div>` +
                    `<div class="cdp-formula-row minus"><span class="op">−</span><span class="label"><?php _e('Estoque Final', 'sistur'); ?></span><span class="val">${formatCurrency(breakdown.closing_stock || 0)}</span></div>` +
                    `<div class="cdp-formula-row total"><span class="op">=</span><span class="label"><strong><?php _e('CMV', 'sistur'); ?></strong></span><span class="val"><strong>${formatCurrency(breakdown.cmv || 0)}</strong></span></div>`;
                if (breakdown.losses_included && parseFloat(breakdown.losses_included) > 0) {
                    html += `<div class="cdp-formula-row loss-note"><span class="op sub">↳</span><span class="label"><?php _e('Inclui Perdas', 'sistur'); ?></span><span class="val">${formatCurrency(breakdown.losses_included)}</span></div>`;
                }
                html += '</div>';
                if (categories.length) {
                    html += '<h4 class="cdp-subtitle"><?php _e('Custo por Categoria', 'sistur'); ?></h4>';
                    html += '<table class="cdp-table"><thead><tr>' +
                        '<th><?php _e('Categoria', 'sistur'); ?></th>' +
                        '<th class="num"><?php _e('Custo', 'sistur'); ?></th>' +
                        '<th class="num"><?php _e('Participação', 'sistur'); ?></th>' +
                        '</tr></thead><tbody>';
                    const totalCost = categories.reduce((s, c) => s + parseFloat(c.cost), 0);
                    categories.forEach(function(c) {
                        const pct = totalCost > 0 ? ((parseFloat(c.cost) / totalCost) * 100).toFixed(1) : 0;
                        html += `<tr>
                            <td>${escHtmlInner(c.category || '<?php _e('Sem categoria', 'sistur'); ?>')}</td>
                            <td class="num">${formatCurrency(c.cost)}</td>
                            <td class="num"><div class="cdp-bar-wrap"><div class="cdp-bar cost-bar" style="width:${pct}%"></div><span>${pct}%</span></div></td>
                        </tr>`;
                    });
                    html += `<tr class="cdp-total-row"><td><strong><?php _e('Total', 'sistur'); ?></strong></td><td class="num"><strong>${formatCurrency(dre.cmv)}</strong></td><td></td></tr>`;
                    html += '</tbody></table>';
                }
                break;

            // ── Resultado Bruto ──────────────────────────────────────────────
            case 'result':
                title = '<?php _e('Resultado Bruto por Categoria', 'sistur'); ?>';
                if (categories.length) {
                    html = '<table class="cdp-table"><thead><tr>' +
                        '<th><?php _e('Categoria', 'sistur'); ?></th>' +
                        '<th class="num"><?php _e('Receita', 'sistur'); ?></th>' +
                        '<th class="num"><?php _e('CMV', 'sistur'); ?></th>' +
                        '<th class="num"><?php _e('Resultado', 'sistur'); ?></th>' +
                        '<th class="num"><?php _e('Margem', 'sistur'); ?></th>' +
                        '</tr></thead><tbody>';
                    categories.forEach(function(c) {
                        const result = parseFloat(c.revenue) - parseFloat(c.cost);
                        const margin = c.revenue > 0 ? (result / parseFloat(c.revenue) * 100).toFixed(1) : 0;
                        const cls    = margin >= 70 ? 'excellent' : (margin >= 50 ? 'good' : (result < 0 ? 'negative' : 'warning'));
                        html += `<tr>
                            <td>${escHtmlInner(c.category || '<?php _e('Sem categoria', 'sistur'); ?>')}</td>
                            <td class="num">${formatCurrency(c.revenue)}</td>
                            <td class="num">${formatCurrency(c.cost)}</td>
                            <td class="num ${result >= 0 ? 'positive' : 'negative'}">${formatCurrency(result)}</td>
                            <td class="num ${cls}">${margin}%</td>
                        </tr>`;
                    });
                    html += `<tr class="cdp-total-row"><td><strong><?php _e('Total', 'sistur'); ?></strong></td><td class="num"><strong>${formatCurrency(dre.revenue)}</strong></td><td class="num"><strong>${formatCurrency(dre.cmv)}</strong></td><td class="num ${dre.gross_result >= 0 ? 'positive' : 'negative'}"><strong>${formatCurrency(dre.gross_result)}</strong></td><td class="num"><strong>${dre.cmv_percentage.toFixed(1)}% CMV</strong></td></tr>`;
                    html += '</tbody></table>';
                } else {
                    html = `<div class="cdp-formula">
                        <div class="cdp-formula-row"><span class="op plus">+</span><span class="label"><?php _e('Receita', 'sistur'); ?></span><span class="val">${formatCurrency(dre.revenue)}</span></div>
                        <div class="cdp-formula-row minus"><span class="op">−</span><span class="label"><?php _e('CMV', 'sistur'); ?></span><span class="val">${formatCurrency(dre.cmv)}</span></div>
                        <div class="cdp-formula-row total"><span class="op">=</span><span class="label"><strong><?php _e('Resultado Bruto', 'sistur'); ?></strong></span><span class="val ${dre.gross_result >= 0 ? 'positive' : 'negative'}"><strong>${formatCurrency(dre.gross_result)}</strong></span></div>
                    </div>`;
                }
                break;

            // ── Perdas ───────────────────────────────────────────────────────
            case 'losses':
                title = '<?php _e('Detalhes das Perdas', 'sistur'); ?>';
                if (byReason.length) {
                    const totalLoss = byReason.reduce((s, r) => s + parseFloat(r.total_value), 0);
                    html = '<table class="cdp-table"><thead><tr>' +
                        '<th><?php _e('Motivo', 'sistur'); ?></th>' +
                        '<th class="num"><?php _e('Valor', 'sistur'); ?></th>' +
                        '<th class="num"><?php _e('Participação', 'sistur'); ?></th>' +
                        '</tr></thead><tbody>';
                    byReason.forEach(function(r) {
                        const pct = totalLoss > 0 ? ((parseFloat(r.total_value) / totalLoss) * 100).toFixed(1) : 0;
                        const reasonCode = r.reason_label || r.reason || 'other';
                        const label = translateLossReason(reasonCode);
                        html += `<tr>
                            <td>${escHtmlInner(label)}</td>
                            <td class="num">${formatCurrency(r.total_value)}</td>
                            <td class="num"><div class="cdp-bar-wrap"><div class="cdp-bar loss-bar" style="width:${pct}%"></div><span>${pct}%</span></div></td>
                        </tr>`;
                    });
                    html += `<tr class="cdp-total-row"><td><strong><?php _e('Total', 'sistur'); ?></strong></td><td class="num negative"><strong>${formatCurrency(totalLoss)}</strong></td><td></td></tr>`;
                    html += '</tbody></table>';
                } else {
                    html = '<p class="cdp-empty">✓ <?php _e('Nenhuma perda registrada neste período.', 'sistur'); ?></p>';
                }
                break;
        }

        $('#cdp-title').text(title);
        $('#cdp-body').html(html);
        panel.slideDown(250);
        panel[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function escHtmlInner(str) {
        const d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }

    // ── Fechar Caixa helpers (outside ready, still inside IIFE) ────────────
    <?php if ($can_edit_restaurant && $is_macro_mode): ?>

    async function fcLoadRevenue() {
        const start = document.getElementById('fc-filter-start')?.value;
        const end   = document.getElementById('fc-filter-end')?.value;
        const tbody = document.getElementById('fc-tbody');
        if (!tbody) return;

        tbody.innerHTML = '<tr><td colspan="5" class="fc-cell-center">Carregando...</td></tr>';

        try {
            const res  = await fetch(`${API_BASE}/stock/revenue?start_date=${start}&end_date=${end}`, {
                headers: { 'X-WP-Nonce': NONCE }
            });
            const data = await res.json();

            if (!data.success || !data.rows || !data.rows.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="fc-cell-center"><?php _e('Nenhum lançamento encontrado.', 'sistur'); ?></td></tr>';
                const lbl = document.getElementById('fc-total-label');
                if (lbl) lbl.textContent = '';
                return;
            }

            tbody.innerHTML = data.rows.map(function(row) {
                return `<tr id="fc-row-${row.id}">
                    <td>${fcFormatDate(row.revenue_date)}</td>
                    <td>${fcEscHtml(row.category)}</td>
                    <td><strong>${formatCurrency(row.total_amount)}</strong></td>
                    <td>${fcEscHtml(row.notes || '-')}</td>
                    <td><button class="fc-delete-btn" onclick="fcDelete(${row.id})"><?php _e('Excluir', 'sistur'); ?></button></td>
                </tr>`;
            }).join('');

            const lbl = document.getElementById('fc-total-label');
            if (lbl) lbl.textContent = '<?php _e('Total do período:', 'sistur'); ?> ' + formatCurrency(data.total);

        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="5" style="color:#ef4444;padding:16px;text-align:center;">Erro: ${e.message}</td></tr>`;
        }
    }

    window.fcDelete = async function(id) {
        if (!confirm('<?php _e('Excluir este lançamento? Esta ação não pode ser desfeita.', 'sistur'); ?>')) return;

        try {
            const res  = await fetch(`${API_BASE}/stock/revenue/${id}`, {
                method: 'DELETE',
                headers: { 'X-WP-Nonce': NONCE }
            });
            const data = await res.json();
            if (data.success) {
                const row = document.getElementById(`fc-row-${id}`);
                if (row) row.remove();
                fcLoadRevenue();
            } else {
                alert('<?php _e('Erro ao excluir:', 'sistur'); ?> ' + (data.message || ''));
            }
        } catch (e) {
            alert('<?php _e('Erro de conexão:', 'sistur'); ?> ' + e.message);
        }
    };

    function fcShowFeedback(msg, type) {
        const el = document.getElementById('fc-feedback');
        if (!el) return;
        const colors = { success: '#10b981', error: '#ef4444', info: '#6b7280' };
        el.textContent = msg;
        el.style.color = colors[type] || '#333';
        el.style.display = 'inline';
    }

    function fcFormatDate(d) {
        if (!d) return '-';
        const [y, m, day] = d.split('-');
        return `${day}/${m}/${y}`;
    }

    function fcEscHtml(str) {
        const d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }

    <?php endif; ?>
})(jQuery);
</script>

<style>
/* ================================================
   CMV Dashboard - Scoped CSS
   ================================================ */

.module-restaurante {
    font-family: system-ui, -apple-system, sans-serif;
    max-width: 100%;
}

.module-restaurante h2 {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 20px;
    color: #1f2937;
}

/* Tab Navigation */
.restaurante-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    border-bottom: 2px solid #e5e7eb;
    padding-bottom: 0;
}

.restaurante-tab {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: #6b7280;
    margin-bottom: -2px;
    transition: all 0.2s;
}

.restaurante-tab:hover {
    color: #374151;
    background: #f9fafb;
}

.restaurante-tab.active {
    color: #f59e0b;
    border-bottom-color: #f59e0b;
}

.restaurante-tab .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
}

/* Tab Content */
.restaurante-tab-content {
    display: none;
}

.restaurante-tab-content.active {
    display: block;
}

/* Date Picker */
.cmv-date-picker {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.date-inputs {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
    margin-bottom: 15px;
}

.date-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.date-field label {
    font-size: 13px;
    font-weight: 500;
    color: #6b7280;
}

.date-field input {
    padding: 10px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    min-width: 150px;
}

.date-field input:focus {
    outline: none;
    border-color: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
}

.cmv-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.cmv-btn.primary {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
}

.cmv-btn.primary:hover {
    background: linear-gradient(135deg, #d97706, #b45309);
    transform: translateY(-1px);
}

.quick-periods {
    display: flex;
    gap: 10px;
}

.quick-period {
    padding: 6px 12px;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    font-size: 12px;
    color: #6b7280;
    cursor: pointer;
    transition: all 0.2s;
}

.quick-period:hover {
    background: #e5e7eb;
    color: #374151;
}

/* Loading State */
.cmv-loading {
    text-align: center;
    padding: 60px 20px;
    background: #fff;
    border-radius: 12px;
}

.loading-spinner {
    width: 40px;
    height: 40px;
    border: 3px solid #e5e7eb;
    border-top-color: #f59e0b;
    border-radius: 50%;
    margin: 0 auto 15px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Empty State */
.cmv-empty {
    text-align: center;
    padding: 60px 20px;
    background: #fff;
    border-radius: 12px;
}

.cmv-empty .empty-icon {
    font-size: 48px;
    display: block;
    margin-bottom: 15px;
}

.cmv-empty p {
    color: #6b7280;
    max-width: 300px;
    margin: 0 auto;
}

/* Top Row: Compass + Cards */
.cmv-top-row {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

@media (max-width: 900px) {
    .cmv-top-row {
        grid-template-columns: 1fr;
    }
}

/* Compass Card */
.cmv-compass-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.cmv-compass-card h3 {
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 15px;
}

.compass-container {
    position: relative;
}

.compass-svg {
    width: 100%;
    max-width: 200px;
}

.compass-value {
    font-size: 2rem;
    font-weight: 700;
    color: #1f2937;
    margin-top: -20px;
}

.compass-target {
    font-size: 12px;
    color: #6b7280;
    margin-top: 5px;
}

.status-badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin-top: 15px;
}

.status-badge.excellent {
    background: #dcfce7;
    color: #166534;
}

.status-badge.good {
    background: #d1fae5;
    color: #065f46;
}

.status-badge.warning {
    background: #fef3c7;
    color: #92400e;
}

.status-badge.critical {
    background: #fee2e2;
    color: #991b1b;
}

/* Summary Cards */
.cmv-summary-cards {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

@media (max-width: 600px) {
    .cmv-summary-cards {
        grid-template-columns: 1fr;
    }
}

.summary-card {
    display: flex;
    align-items: center;
    gap: 15px;
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.summary-card .card-icon {
    font-size: 32px;
}

.summary-card .card-content {
    flex: 1;
}

.summary-card .card-label {
    display: block;
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 4px;
}

.summary-card .card-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1f2937;
}

.summary-card.revenue .card-value { color: #10b981; }
.summary-card.cost .card-value { color: #ef4444; }
.summary-card.result .card-value.positive { color: #10b981; }
.summary-card.result .card-value.negative { color: #ef4444; }
.summary-card.losses .card-value { color: #f59e0b; }

/* Charts Row */
.cmv-charts-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

@media (max-width: 800px) {
    .cmv-charts-row {
        grid-template-columns: 1fr;
    }
}

.cmv-chart-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.cmv-chart-card h4 {
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 15px;
}

.chart-container {
    height: 200px;
    position: relative;
}

.chart-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 200px;
    color: #10b981;
}

.chart-empty span {
    font-size: 48px;
    margin-bottom: 10px;
}

.chart-empty p {
    font-size: 14px;
    color: #6b7280;
}

/* Action Buttons */
.cmv-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.cmv-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s;
}

.cmv-action-btn.primary {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
}

.cmv-action-btn.primary:hover {
    background: linear-gradient(135deg, #d97706, #b45309);
    color: #fff;
    transform: translateY(-1px);
}

.cmv-action-btn.secondary {
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #e5e7eb;
}

.cmv-action-btn.secondary:hover {
    background: #e5e7eb;
    color: #1f2937;
}

/* PDV Placeholder */
.pdv-placeholder {
    text-align: center;
    padding: 60px 20px;
    background: #fff;
    border-radius: 12px;
}

.pdv-placeholder .placeholder-icon {
    font-size: 64px;
    display: block;
    margin-bottom: 20px;
}

.pdv-placeholder h3 {
    font-size: 1.25rem;
    color: #1f2937;
    margin-bottom: 10px;
}

.pdv-placeholder p {
    color: #6b7280;
    margin-bottom: 25px;
}

.coming-soon-features {
    background: #f9fafb;
    border-radius: 8px;
    padding: 20px;
    display: inline-block;
    text-align: left;
}

.coming-soon-features h4 {
    font-size: 14px;
    color: #374151;
    margin-bottom: 10px;
}

.coming-soon-features ul {
    list-style: none;
    margin: 0;
    padding: 0;
}

.coming-soon-features li {
    padding: 6px 0;
    color: #10b981;
    font-size: 14px;
}

/* CMV Breakdown Section (espelho do Admin) */
.cmv-breakdown-section {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

@media (max-width: 900px) {
    .cmv-breakdown-section {
        grid-template-columns: 1fr;
    }
}

.cmv-breakdown-card,
.cmv-category-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.cmv-breakdown-card h4,
.cmv-category-card h4 {
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e5e7eb;
}

.cmv-breakdown-table {
    width: 100%;
    border-collapse: collapse;
}

.cmv-breakdown-table td {
    padding: 10px 0;
    font-size: 14px;
    color: #374151;
}

.cmv-breakdown-table td.value {
    text-align: right;
    font-weight: 500;
}

.cmv-breakdown-table .op {
    display: inline-block;
    width: 18px;
    color: #6b7280;
    font-weight: 600;
}

.cmv-breakdown-table .sub {
    display: inline-block;
    width: 18px;
    color: #ef4444;
}

.cmv-breakdown-table .total-row {
    border-top: 2px solid #e5e7eb;
    background: #f0f7ff;
}

.cmv-breakdown-table .total-row td {
    font-weight: 700;
    color: #1f2937;
}

.cmv-breakdown-table .losses-row td {
    color: #ef4444;
    font-size: 13px;
}

.cmv-breakdown-table .losses-row td.losses {
    color: #ef4444;
    font-weight: 600;
}

/* Category Table */
.cmv-category-table {
    width: 100%;
    border-collapse: collapse;
}

.cmv-category-table th,
.cmv-category-table td {
    padding: 10px 8px;
    text-align: left;
    font-size: 13px;
}

.cmv-category-table th {
    color: #6b7280;
    font-weight: 600;
    border-bottom: 1px solid #e5e7eb;
}

.cmv-category-table td {
    color: #374151;
    border-bottom: 1px solid #f3f4f6;
}

.cmv-category-table .num {
    text-align: right;
}

.cmv-category-table .excellent {
    color: #10b981;
    font-weight: 600;
}

.cmv-category-table .good {
    color: #3b82f6;
    font-weight: 500;
}

.cmv-category-table .warning {
    color: #f59e0b;
    font-weight: 500;
}

/* ── Card detail hint & clickable state ──────────────────────────────── */

.summary-card[data-detail] {
    cursor: pointer;
    position: relative;
    transition: transform 0.15s, box-shadow 0.15s;
}

.summary-card[data-detail]:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}

.card-detail-hint {
    position: absolute;
    top: 10px;
    right: 12px;
    font-size: 14px;
    color: #d1d5db;
    transition: color 0.15s;
}

.summary-card[data-detail]:hover .card-detail-hint {
    color: #f59e0b;
}

/* ── Detail Panel ─────────────────────────────────────────────────────── */

.cmv-detail-panel {
    background: #fff;
    border-radius: 12px;
    border-left: 4px solid #f59e0b;
    padding: 20px 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.10);
    margin-bottom: 20px;
}

.cdp-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.cdp-header strong {
    font-size: 15px;
    color: #1f2937;
}

.cdp-close-btn {
    background: none;
    border: none;
    font-size: 16px;
    color: #9ca3af;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    line-height: 1;
}

.cdp-close-btn:hover {
    background: #f3f4f6;
    color: #374151;
}

.cdp-subtitle {
    font-size: 13px;
    font-weight: 600;
    color: #6b7280;
    margin: 18px 0 10px;
}

/* Detail table */
.cdp-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.cdp-table th {
    text-align: left;
    padding: 8px 10px;
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    border-bottom: 2px solid #e5e7eb;
}

.cdp-table td {
    padding: 9px 10px;
    color: #374151;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: middle;
}

.cdp-table tr:last-child td {
    border-bottom: none;
}

.cdp-table .num { text-align: right; }

.cdp-total-row td {
    border-top: 2px solid #e5e7eb !important;
    background: #fafafa;
    font-weight: 600;
}

/* Progress bar in table */
.cdp-bar-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 120px;
}

.cdp-bar {
    height: 8px;
    border-radius: 4px;
    min-width: 2px;
    flex-shrink: 0;
}

.cdp-bar-wrap span {
    font-size: 12px;
    color: #6b7280;
    white-space: nowrap;
}

.revenue-bar { background: #10b981; }
.cost-bar    { background: #ef4444; }
.loss-bar    { background: #f59e0b; }

/* CMV formula */
.cdp-formula {
    max-width: 420px;
    margin-bottom: 4px;
}

.cdp-formula-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
}

.cdp-formula-row .op {
    width: 20px;
    text-align: center;
    font-weight: 700;
    color: #6b7280;
    flex-shrink: 0;
}

.cdp-formula-row .op.plus { color: #10b981; }
.cdp-formula-row.minus .op { color: #ef4444; }
.cdp-formula-row .op.sub  { color: #ef4444; font-size: 12px; }

.cdp-formula-row .label { flex: 1; color: #374151; }

.cdp-formula-row .val {
    font-weight: 600;
    color: #1f2937;
    text-align: right;
}

.cdp-formula-row.total {
    background: #f0f7ff;
    border-radius: 8px;
    border-bottom: none;
    margin-top: 4px;
}

.cdp-formula-row.loss-note {
    background: #fff7ed;
    border-radius: 6px;
    font-size: 13px;
    color: #92400e;
}

/* Colour helpers */
.positive { color: #10b981 !important; }
.negative { color: #ef4444 !important; }
.excellent { color: #10b981; }
.good { color: #3b82f6; }
.warning { color: #f59e0b; }

.cdp-empty {
    text-align: center;
    color: #6b7280;
    padding: 24px;
    font-size: 14px;
}

/* ── Fechar Caixa Tab ─────────────────────────────────────────────────── */

.fc-card {
    background: #fff;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.fc-card-title {
    font-size: 15px;
    font-weight: 600;
    color: #1f2937;
    margin: 0 0 20px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.fc-form-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 14px;
}

.fc-label {
    min-width: 110px;
    font-size: 13px;
    font-weight: 500;
    color: #374151;
    padding-top: 8px;
}

.fc-input {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    flex: 1;
    max-width: 360px;
    box-sizing: border-box;
}

.fc-input:focus {
    outline: none;
    border-color: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245,158,11,0.1);
}

textarea.fc-input {
    resize: vertical;
}

.fc-form-actions {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #f3f4f6;
}

.fc-feedback {
    font-size: 13px;
    font-weight: 500;
}

.fc-period-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
    font-size: 14px;
    color: #374151;
}

.fc-period-bar input[type="date"] {
    padding: 6px 10px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
}

.fc-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.fc-table th {
    text-align: left;
    padding: 10px 12px;
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    border-bottom: 2px solid #e5e7eb;
    white-space: nowrap;
}

.fc-table td {
    padding: 10px 12px;
    color: #374151;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: middle;
}

.fc-table tr:last-child td {
    border-bottom: none;
}

.fc-cell-center {
    text-align: center;
    padding: 20px !important;
    color: #6b7280;
}

.fc-delete-btn {
    padding: 4px 10px;
    background: transparent;
    border: 1px solid #ef4444;
    color: #ef4444;
    border-radius: 6px;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.15s;
}

.fc-delete-btn:hover {
    background: #ef4444;
    color: #fff;
}
</style>