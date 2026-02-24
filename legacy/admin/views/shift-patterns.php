<?php
/**
 * View de gestão de padrões de escala
 *
 * @package SISTUR
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

$shift_patterns_manager = SISTUR_Shift_Patterns::get_instance();
?>

<!-- Modal para Visualizar Funcionários Vinculados -->
<div id="view-employees-modal" class="sistur-modal" style="display: none;">
    <div class="sistur-modal-content" style="max-width: 600px;">
        <div class="sistur-modal-header">
            <h3>Funcionários Vinculados à Escala</h3>
            <span class="sistur-modal-close">&times;</span>
        </div>
        <div class="sistur-modal-body">
            <div id="employees-loading" style="text-align: center; padding: 20px;">
                <span class="spinner is-active" style="float: none; margin: 0;"></span> Carregando...
            </div>
            <div id="employees-list-container" style="display: none;">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Cargo</th>
                        </tr>
                    </thead>
                    <tbody id="employees-list-body">
                        <!-- Populated via JS -->
                    </tbody>
                </table>
                <p id="no-employees-message" style="display: none; text-align: center; margin-top: 15px; color: #666;">
                    Nenhum funcionário vinculado a esta escala.
                </p>
            </div>
        </div>
        <div class="sistur-modal-footer">
            <button type="button" class="button sistur-modal-close-btn">Fechar</button>
        </div>
    </div>
</div>

<div class="wrap sistur-wrap">
    <h1 class="wp-heading-inline">
        <i class="dashicons dashicons-calendar-alt"></i>
        Escalas de Trabalho
    </h1>
    <button type="button" class="page-title-action" id="btn-add-shift-pattern">
        Adicionar Nova Escala
    </button>
    <hr class="wp-header-end">

    <div class="sistur-card">
        <div class="card-header">
            <h2>Padrões de Escala</h2>
            <p class="description">
                Gerencie os diferentes padrões de escala de trabalho (6x1, 5x2, horas flexíveis, etc).
                Estes padrões podem ser atribuídos aos funcionários para controlar jornadas de trabalho.
            </p>
        </div>

        <div class="card-body">
            <table class="wp-list-table widefat fixed striped" id="shift-patterns-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">ID</th>
                        <th style="width: 20%;">Nome</th>
                        <th style="width: 25%;">Descrição</th>
                        <th style="width: 15%;">Tipo</th>
                        <th style="width: 10%;">Dias Trabalho</th>
                        <th style="width: 10%;">Dias Folga</th>
                        <th style="width: 10%;">Status</th>
                        <th style="width: 5%;">Ações</th>
                    </tr>
                </thead>
                <tbody id="shift-patterns-list">
                    <tr>
                        <td colspan="8" style="text-align: center;">
                            <span class="spinner is-active" style="float: none;"></span>
                            Carregando padrões de escala...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal para adicionar/editar padrão de escala -->
<div id="shift-pattern-modal" class="sistur-modal" style="display: none;">
    <div class="sistur-modal-content">
        <div class="sistur-modal-header">
            <h2 id="modal-title">Adicionar Padrão de Escala</h2>
            <span class="sistur-modal-close">&times;</span>
        </div>
        <div class="sistur-modal-body">
            <form id="shift-pattern-form">
                <input type="hidden" id="pattern-id" name="id">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="pattern-name">Nome <span class="required">*</span></label></th>
                        <td>
                            <input type="text" id="pattern-name" name="name" class="regular-text" required>
                            <p class="description">Ex: Escala 6x1 (8h/dia)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pattern-description">Descrição</label></th>
                        <td>
                            <textarea id="pattern-description" name="description" class="large-text" rows="3"></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="scale-mode">Modo da Escala <span class="required">*</span></label></th>
                        <td>
                            <select id="scale-mode" name="scale_mode" required>
                                <option value="TYPE_FIXED_DAYS">CLT Padrão (Dias Fixos)</option>
                                <option value="TYPE_HOURLY">CLT Horista (Flexível)</option>
                                <option value="TYPE_DAILY">Diarista (Por Dia)</option>
                            </select>
                            <p class="description">Define o tipo de contrato e regras de cálculo.</p>
                        </td>
                    </tr>
                    <tr id="row-work-days">
                        <th scope="row"><label for="work-days-count">Dias de Trabalho</label></th>
                        <td>
                            <input type="number" id="work-days-count" name="work_days_count" min="0" max="7" value="6">
                            <p class="description">Quantos dias trabalha por ciclo</p>
                        </td>
                    </tr>
                    <tr id="row-rest-days">
                        <th scope="row"><label for="rest-days-count">Dias de Folga</label></th>
                        <td>
                            <input type="number" id="rest-days-count" name="rest_days_count" min="0" max="7" value="1">
                            <p class="description">Quantos dias folga por ciclo</p>
                        </td>
                    </tr>
                    
                    <tr id="row-weekly-hours">
                        <th scope="row"><label for="weekly-hours">Horas Semanais (minutos)</label></th>
                        <td>
                            <input type="number" id="weekly-hours" name="weekly_hours_minutes" min="0" value="2640">
                            <p class="description">Total de minutos por semana (2640 = 44h)</p>
                        </td>
                    </tr>
                    <!-- Daily hours moved to Employee settings -->
                    <tr>
                        <th scope="row"><label for="pattern-status">Status</label></th>
                        <td>
                            <select id="pattern-status" name="status">
                                <option value="1">Ativo</option>
                                <option value="0">Inativo</option>
                            </select>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        <div class="sistur-modal-footer">
            <button type="button" class="button button-secondary" id="btn-cancel-pattern">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-save-pattern">Salvar</button>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    const modal = $('#shift-pattern-modal');
    const form = $('#shift-pattern-form');
    const table = $('#shift-patterns-list');

    // Carregar padrões de escala
    function loadShiftPatterns() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_get_shift_patterns',
                nonce: '<?php echo wp_create_nonce('sistur_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    renderShiftPatterns(response.data);
                } else {
                    table.html('<tr><td colspan="8" style="text-align: center;">Erro ao carregar padrões de escala</td></tr>');
                }
            }
        });
    }

    // Renderizar lista de padrões
    function renderShiftPatterns(patterns) {
        if (patterns.length === 0) {
            table.html('<tr><td colspan="8" style="text-align: center;">Nenhum padrão de escala cadastrado</td></tr>');
            return;
        }

        let html = '';
        patterns.forEach(function(pattern) {
            const typeLabels = {
                'fixed_days': 'Dias Fixos',
                'weekly_rotation': 'Rotação Semanal',
                'flexible_hours': 'Horas Flexíveis'
            };

            html += '<tr>';
            html += '<td>' + pattern.id + '</td>';
            html += '<td><strong>' + pattern.name + '</strong></td>';
            html += '<td>' + (pattern.description || '-') + '</td>';
            html += '<td>' + (typeLabels[pattern.pattern_type] || pattern.pattern_type) + '</td>';
            html += '<td>' + (pattern.work_days_count || '-') + '</td>';
            html += '<td>' + (pattern.rest_days_count || '-') + '</td>';
            html += '<td>' + (pattern.status == '1' ? '<span class="badge badge-success">Ativo</span>' : '<span class="badge badge-inactive">Inativo</span>') + '</td>';
            html += '<td>';
            html += '<button class="button button-small btn-view-employees" data-id="' + pattern.id + '" title="Ver Funcionários"><span class="dashicons dashicons-groups" style="margin-top: 3px;"></span></button> ';
            html += '<button class="button button-small btn-edit-pattern" data-id="' + pattern.id + '">Editar</button> ';
            html += '<button class="button button-small btn-delete-pattern" data-id="' + pattern.id + '">Excluir</button>';
            html += '</td>';
            html += '</tr>';
        });

        table.html(html);
    }

    // Abrir modal para adicionar
    $('#btn-add-shift-pattern').on('click', function() {
        form[0].reset();
        $('#pattern-id').val('');
        $('#modal-title').text('Adicionar Padrão de Escala');
        modal.show();
        
        // Resetar interface
        $('#scale-mode').val('TYPE_FIXED_DAYS');
        if (typeof updateInterface === 'function') {
            updateInterface();
        }
    });

    // Visualizar funcionários
    $(document).on('click', '.btn-view-employees', function() {
        const id = $(this).data('id');
        const viewModal = $('#view-employees-modal');
        const listBody = $('#employees-list-body');
        const loading = $('#employees-loading');
        const container = $('#employees-list-container');
        const noData = $('#no-employees-message');

        viewModal.show();
        loading.show();
        container.hide();
        listBody.empty();
        noData.hide();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_get_employees_by_pattern',
                id: id,
                nonce: '<?php echo wp_create_nonce('sistur_nonce'); ?>'
            },
            success: function(response) {
                loading.hide();
                container.show();
                if (response.success && response.data.length > 0) {
                    let html = '';
                    response.data.forEach(function(employee) {
                        html += '<tr>';
                        html += '<td>' + employee.name + '</td>';
                        html += '<td>' + (employee.position || '-') + '</td>';
                        html += '</tr>';
                    });
                    listBody.html(html);
                } else {
                    noData.show();
                }
            },
            error: function() {
                loading.hide();
                alert('Erro ao carregar funcionários.');
                viewModal.hide();
            }
        });
    });

    // Fechar modal de visualização
    $('.sistur-modal-close, .sistur-modal-close-btn').on('click', function() {
        $('#view-employees-modal').hide();
    });

    // Abrir modal para editar
    $(document).on('click', '.btn-edit-pattern', function() {
        const id = $(this).data('id');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_get_shift_pattern',
                id: id,
                nonce: '<?php echo wp_create_nonce('sistur_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    const pattern = response.data;
                    $('#pattern-id').val(pattern.id);
                    $('#pattern-name').val(pattern.name);
                    $('#pattern-description').val(pattern.description);
                    $('#scale-mode').val(pattern.scale_mode || 'TYPE_FIXED_DAYS');
                    $('#work-days-count').val(pattern.work_days_count);
                    $('#rest-days-count').val(pattern.rest_days_count);
                    $('#weekly-hours').val(pattern.weekly_hours_minutes);
                    // Daily hours moved to employee, default handled by backend/employee config
                    // $('#daily-hours').val(pattern.daily_hours_minutes);
                    // $('#lunch-break').val(pattern.lunch_break_minutes); // Moved
                    
                    $('#pattern-status').val(pattern.status);
                    
                    // Carregar configuração de dias para TYPE_FIXED_DAYS e TYPE_DAILY
                    if ((pattern.scale_mode === 'TYPE_FIXED_DAYS' || pattern.scale_mode === 'TYPE_DAILY') && pattern.pattern_config) {
                        try {
                            const config = JSON.parse(pattern.pattern_config);
                            if (config.pattern_type === 'weekly' && config.weekly) {
                                // Carregar configuração semanal
                                Object.keys(config.weekly).forEach(function(day) {
                                    const dayConfig = config.weekly[day];
                                    $('.day-work-check[data-day="' + day + '"]').prop('checked', dayConfig.type === 'work');
                                    $('.day-hours[data-day="' + day + '"]').val(dayConfig.expected_minutes || 0);
                                    if (dayConfig.type === 'work') {
                                        $('.day-hours[data-day="' + day + '"]').prop('disabled', false);
                                    } else {
                                        $('.day-hours[data-day="' + day + '"]').prop('disabled', true);
                                    }
                                });
                                updateWeeklyTotal();
                            }
                        } catch (e) {
                            console.error('Erro ao parsear pattern_config:', e);
                        }
                    }
                    
                    $('#modal-title').text('Editar Padrão de Escala');
                    modal.show();
                    updateInterface();
                }
            }
        });
    });

    // Controle de visibilidade de campos baseado no tipo
    // Controle de interface baseado no Modo da Escala e Tipo
    function updateInterface() {
        const scaleMode = $('#scale-mode').val();
        
        // Lógica baseada APENAS no Modo da Escala:
        // TYPE_FIXED_DAYS: Mostra configuração de dias fixos da semana
        // TYPE_HOURLY: Mostra apenas horas semanais (flexível)
        // TYPE_DAILY: Mostra configuração de dias (diarista)
        
        if (scaleMode === 'TYPE_HOURLY') {
            // Horista: apenas horas semanais, sem configuração de dias
            $('#row-work-days, #row-rest-days, #row-weekly-config').hide();
            $('#row-weekly-hours').show();
        } else if (scaleMode === 'TYPE_DAILY' || scaleMode === 'TYPE_FIXED_DAYS') {
            // Diarista e CLT Padrão: usa configuração semanal (checkboxes)
            $('#row-work-days, #row-rest-days').hide();
            $('#row-weekly-config').show();
            $('#row-weekly-hours').hide(); // Total calculado automaticamente
        }
    }

    // Event listeners
    $('#scale-mode').on('change', updateInterface);
    
    // Inicializar no carregamento do modal (será chamado no edit/add)

    // Toggle para habilitar/desabilitar campo de horas quando checkbox muda
    $(document).on('change', '.day-work-check', function() {
        const day = $(this).data('day');
        const hoursInput = $('.day-hours[data-day="' + day + '"]');
        if ($(this).is(':checked')) {
            hoursInput.prop('disabled', false);
            if (hoursInput.val() == 0) hoursInput.val(480);
        } else {
            hoursInput.prop('disabled', true).val(0);
        }
        updateWeeklyTotal();
    });

    // Atualizar total semanal quando horas mudam
    $(document).on('input', '.day-hours', function() {
        updateWeeklyTotal();
    });

    // Função para calcular total semanal
    function updateWeeklyTotal() {
        let total = 0;
        $('.day-hours').each(function() {
            if (!$(this).prop('disabled')) {
                total += parseInt($(this).val()) || 0;
            }
        });
        $('#calculated-weekly-total').text(total);
        $('#calculated-weekly-hours').text((total / 60).toFixed(1));
        // Atualiza também o campo hidden de weekly_hours
        $('#weekly-hours').val(total);
    }

    // Salvar padrão
    $('#btn-save-pattern').on('click', function() {
        // Construir pattern_config para TYPE_FIXED_DAYS e TYPE_DAILY
        let patternConfig = null;
        const scaleMode = $('#scale-mode').val();
        
        if (scaleMode === 'TYPE_FIXED_DAYS' || scaleMode === 'TYPE_DAILY') {
            patternConfig = {
                pattern_type: 'weekly',
                weekly: {}
            };
            
            $('.day-work-check').each(function() {
                const day = $(this).data('day');
                const isWork = $(this).is(':checked');
                const hours = parseInt($('.day-hours[data-day="' + day + '"]').val()) || 0;
                
                patternConfig.weekly[day] = {
                    type: isWork ? 'work' : 'rest',
                    expected_minutes: isWork ? hours : 0
                };
            });
        }
        
        let data = form.serialize() + '&action=sistur_save_shift_pattern&nonce=<?php echo wp_create_nonce('sistur_nonce'); ?>';
        
        if (patternConfig) {
            data += '&pattern_config=' + encodeURIComponent(JSON.stringify(patternConfig));
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    modal.hide();
                    loadShiftPatterns();
                    alert('Padrão de escala salvo com sucesso!');
                } else {
                    alert('Erro ao salvar padrão: ' + response.data);
                }
            }
        });
    });

    // Excluir padrão
    $(document).on('click', '.btn-delete-pattern', function() {
        if (!confirm('Tem certeza que deseja excluir este padrão de escala?')) {
            return;
        }

        const id = $(this).data('id');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_delete_shift_pattern',
                id: id,
                nonce: '<?php echo wp_create_nonce('sistur_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    loadShiftPatterns();
                    alert('Padrão excluído com sucesso!');
                } else {
                    alert('Erro ao excluir: ' + response.data);
                }
            }
        });
    });

    // Fechar modal
    $('.sistur-modal-close, #btn-cancel-pattern').on('click', function() {
        modal.hide();
    });

    // Carregar padrões ao iniciar
    loadShiftPatterns();
});
</script>

<style>
.sistur-modal {
    position: fixed;
    z-index: 100001;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.6);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 40px 20px;
    box-sizing: border-box;
}

.sistur-modal-content {
    background-color: #fff;
    padding: 0;
    border: none;
    width: 100%;
    max-width: 900px;
    border-radius: 8px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    margin: 0;
}

.sistur-modal-header {
    padding: 16px 20px;
    background-color: #0073aa;
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 8px 8px 0 0;
}

.sistur-modal-header h2 {
    margin: 0;
    color: white;
    font-size: 18px;
}

.sistur-modal-close {
    color: white;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    line-height: 1;
}

.sistur-modal-close:hover,
.sistur-modal-close:focus {
    color: #ddd;
}

.sistur-modal-body {
    padding: 20px;
    max-height: 65vh;
    overflow-y: auto;
}

.sistur-modal-body .form-table {
    margin: 0;
}

.sistur-modal-body .form-table th {
    width: 140px;
    padding: 12px 10px 12px 0;
    vertical-align: top;
    font-weight: 600;
}

.sistur-modal-body .form-table td {
    padding: 10px 0;
}

.sistur-modal-body .form-table input[type="text"],
.sistur-modal-body .form-table input[type="number"],
.sistur-modal-body .form-table textarea,
.sistur-modal-body .form-table select {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.sistur-modal-body .form-table input[type="number"] {
    max-width: 150px;
}

.sistur-modal-body .weekly-days-config {
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow: hidden;
}

.sistur-modal-body .weekly-days-config input[type="number"] {
    width: 70px !important;
}

.sistur-modal-footer {
    padding: 15px 20px;
    background-color: #f5f5f5;
    text-align: right;
    border-top: 1px solid #ddd;
    border-radius: 0 0 8px 8px;
}

.sistur-modal-footer .button {
    margin-left: 8px;
}

.badge {
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
}

.badge-success {
    background-color: #46b450;
    color: white;
}

.badge-inactive {
    background-color: #999;
    color: white;
}

.required {
    color: red;
}

/* Responsive */
@media screen and (max-width: 782px) {
    .sistur-modal {
        padding: 20px 10px;
    }
    
    .sistur-modal-content {
        max-width: 100%;
    }
    
    .sistur-modal-body .form-table th {
        display: block;
        width: 100%;
        padding-bottom: 5px;
    }
    
    .sistur-modal-body .form-table td {
        display: block;
        width: 100%;
        padding-left: 0;
    }
}
</style>
