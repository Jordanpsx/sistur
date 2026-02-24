<?php
/**
 * Página de Gerenciamento de Ponto Eletrônico
 *
 * @package SISTUR
 */

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}

$employees = SISTUR_Employees::get_all_employees(array('status' => 1));
?>

<div class="wrap">
    <h1><?php _e('Registro de Ponto Eletrônico', 'sistur'); ?></h1>

    <!-- Seção de Registro Rápido pelo Admin -->
    <div style="background: #e8f5e9; padding: 20px; margin-top: 20px; border-radius: 4px; border-left: 4px solid #4caf50;">
        <h2><?php _e('Registrar Ponto para Funcionário', 'sistur'); ?></h2>
        <p style="color: #555; margin-bottom: 15px;">
            <?php _e('Use esta ferramenta para registrar o ponto de qualquer funcionário. Útil para testes ou registros manuais.', 'sistur'); ?>
        </p>

        <div style="display: flex; gap: 15px; align-items: end; flex-wrap: wrap;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                    <?php _e('Funcionário:', 'sistur'); ?>
                </label>
                <select id="sistur-quick-punch-employee" style="min-width: 250px; padding: 8px;">
                    <option value=""><?php _e('Selecione um funcionário...', 'sistur'); ?></option>
                    <?php foreach ($employees as $emp) : ?>
                        <option value="<?php echo $emp['id']; ?>"><?php echo esc_html($emp['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                    <?php _e('Tipo de Registro:', 'sistur'); ?>
                </label>
                <select id="sistur-quick-punch-type" style="min-width: 180px; padding: 8px;">
                    <option value="clock_in"><?php _e('Entrada', 'sistur'); ?></option>
                    <option value="lunch_start"><?php _e('Início Almoço', 'sistur'); ?></option>
                    <option value="lunch_end"><?php _e('Fim Almoço', 'sistur'); ?></option>
                    <option value="clock_out"><?php _e('Saída', 'sistur'); ?></option>
                </select>
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                    <?php _e('Data:', 'sistur'); ?>
                </label>
                <input type="date" id="sistur-quick-punch-date" value="<?php echo date('Y-m-d'); ?>" style="padding: 8px;" />
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                    <?php _e('Hora:', 'sistur'); ?>
                </label>
                <input type="time" id="sistur-quick-punch-time" value="<?php echo date('H:i'); ?>" style="padding: 8px;" />
            </div>

            <div>
                <button type="button" class="button button-primary button-large" id="sistur-quick-punch-register" style="padding: 8px 20px;">
                    <span class="dashicons dashicons-clock" style="vertical-align: middle; margin-top: 4px;"></span>
                    <?php _e('Registrar Ponto', 'sistur'); ?>
                </button>
            </div>
        </div>

        <div id="sistur-quick-punch-message" style="margin-top: 15px; display: none;"></div>
    </div>

    <div style="background: white; padding: 20px; margin-top: 20px; border-radius: 4px;">
        <h2><?php _e('Folha de Ponto', 'sistur'); ?></h2>

        <div style="margin-bottom: 20px;">
            <label><?php _e('Funcionário:', 'sistur'); ?></label>
            <select id="sistur-time-employee" style="min-width: 300px;">
                <option value=""><?php _e('Selecione um funcionário...', 'sistur'); ?></option>
                <?php foreach ($employees as $emp) : ?>
                    <option value="<?php echo $emp['id']; ?>"><?php echo esc_html($emp['name']); ?></option>
                <?php endforeach; ?>
            </select>

            <label style="margin-left: 20px;"><?php _e('Período:', 'sistur'); ?></label>
            <input type="date" id="sistur-time-start" value="<?php echo date('Y-m-01'); ?>" />
            <span> até </span>
            <input type="date" id="sistur-time-end" value="<?php echo date('Y-m-t'); ?>" />

            <button type="button" class="button button-primary" id="sistur-time-load">
                <?php _e('Carregar', 'sistur'); ?>
            </button>

            <button type="button" class="button button-secondary" id="sistur-time-print" style="margin-left: 10px;">
                <span class="dashicons dashicons-printer" style="vertical-align: middle; margin-top: 4px;"></span>
                <?php _e('Imprimir', 'sistur'); ?>
            </button>
        </div>

        <div id="sistur-time-sheet-container">
            <p><?php _e('Selecione um funcionário e clique em Carregar para visualizar a folha de ponto.', 'sistur'); ?></p>
        </div>
    </div>

    <!-- NOVA SEÇÃO: Registro em Massa para Testes -->
    <div style="background: #fff3e0; padding: 20px; margin-top: 20px; border-radius: 4px; border-left: 4px solid #ff9800;">
        <h2><?php _e('🧪 Registro em Massa (Testes)', 'sistur'); ?></h2>
        <p style="color: #555; margin-bottom: 15px;">
            <?php _e('Preenche automaticamente um mês inteiro de registros para um funcionário. Útil para testes do sistema.', 'sistur'); ?>
            <br><strong style="color: #e65100;"><?php _e('⚠️ Use apenas em ambiente de testes!', 'sistur'); ?></strong>
        </p>

        <div style="display: flex; gap: 15px; align-items: end; flex-wrap: wrap;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                    <?php _e('Funcionário:', 'sistur'); ?>
                </label>
                <select id="sistur-bulk-employee" style="min-width: 250px; padding: 8px;">
                    <option value=""><?php _e('Selecione um funcionário...', 'sistur'); ?></option>
                    <?php foreach ($employees as $emp) : ?>
                        <option value="<?php echo $emp['id']; ?>"><?php echo esc_html($emp['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                    <?php _e('Mês:', 'sistur'); ?>
                </label>
                <input type="month" id="sistur-bulk-month" value="<?php echo date('Y-m'); ?>" style="padding: 8px;" />
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                    <?php _e('Entrada base:', 'sistur'); ?>
                </label>
                <input type="time" id="sistur-bulk-entry-time" value="08:00" style="padding: 8px;" />
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                    <?php _e('Almoço (min):', 'sistur'); ?>
                </label>
                <input type="number" id="sistur-bulk-lunch-duration" value="60" min="30" max="120" style="padding: 8px; width: 80px;" />
            </div>

            <div>
                <button type="button" class="button button-primary button-large" id="sistur-bulk-register" style="padding: 8px 20px; background: #ff9800; border-color: #f57c00;">
                    <span class="dashicons dashicons-calendar-alt" style="vertical-align: middle; margin-top: 4px;"></span>
                    <?php _e('Preencher Mês', 'sistur'); ?>
                </button>
            </div>
        </div>

        <div id="sistur-bulk-progress" style="margin-top: 15px; display: none;">
            <div style="background: #e0e0e0; border-radius: 4px; height: 20px; overflow: hidden;">
                <div id="sistur-bulk-progress-bar" style="background: #4caf50; height: 100%; width: 0%; transition: width 0.3s;"></div>
            </div>
            <p id="sistur-bulk-status" style="margin-top: 10px; color: #555;"></p>
        </div>

        <div id="sistur-bulk-message" style="margin-top: 15px; display: none;"></div>
    </div>
</div>

<style>
.sistur-reason-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 100000;
}
.sistur-reason-modal-content {
    background: white;
    padding: 25px;
    border-radius: 5px;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
}
.sistur-reason-modal-content h3 {
    margin-top: 0;
    color: #d63638;
}
.sistur-reason-modal-content textarea {
    width: 100%;
    height: 100px;
    margin: 15px 0;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    font-family: inherit;
}
.sistur-reason-modal-content .buttons {
    text-align: right;
}
.sistur-reason-modal-content button {
    margin-left: 10px;
}
</style>

<!-- Modal para solicitar motivo de alteração -->
<div id="sistur-reason-modal" class="sistur-reason-modal">
    <div class="sistur-reason-modal-content">
        <h3>⚠ Motivo da Alteração Administrativa</h3>
        <p><strong>É obrigatório informar o motivo desta alteração.</strong></p>
        <p>Esta informação será registrada no histórico de auditoria.</p>
        <textarea id="sistur-reason-input" placeholder="Digite o motivo da alteração..."></textarea>
        <div class="buttons">
            <button type="button" class="button" id="sistur-reason-cancel">Cancelar</button>
            <button type="button" class="button button-primary" id="sistur-reason-confirm">Confirmar</button>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Verificar se sisturTimeTracking está definido
    if (typeof sisturTimeTracking === 'undefined') {
        console.error('sisturTimeTracking não está definido!');
        alert('Erro: Configuração do sistema não carregada. Por favor, recarregue a página.');
        return;
    }

    console.log('SISTUR Time Tracking inicializado', sisturTimeTracking);

    // Função para solicitar motivo de alteração
    function requestChangeReason(callback) {
        var $modal = $('#sistur-reason-modal');
        var $input = $('#sistur-reason-input');

        $input.val('');
        $modal.css('display', 'flex');
        $input.focus();

        $('#sistur-reason-confirm').off('click').on('click', function() {
            var reason = $input.val().trim();
            if (!reason) {
                alert('<?php _e('O motivo é obrigatório!', 'sistur'); ?>');
                return;
            }
            $modal.hide();
            callback(reason);
        });

        $('#sistur-reason-cancel').off('click').on('click', function() {
            $modal.hide();
            callback(null);
        });

        // Permitir Enter para confirmar
        $input.off('keypress').on('keypress', function(e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                $('#sistur-reason-confirm').click();
            }
        });
    }

    // Registro rápido de ponto pelo admin
    $('#sistur-quick-punch-register').on('click', function() {
        var $button = $(this);
        var employeeId = $('#sistur-quick-punch-employee').val();
        var punchType = $('#sistur-quick-punch-type').val();
        var punchDate = $('#sistur-quick-punch-date').val();
        var punchTime = $('#sistur-quick-punch-time').val();
        var $message = $('#sistur-quick-punch-message');

        if (!employeeId) {
            $message.html('<div class="notice notice-error"><p><?php _e('Por favor, selecione um funcionário.', 'sistur'); ?></p></div>').show();
            return;
        }

        if (!punchDate || !punchTime) {
            $message.html('<div class="notice notice-error"><p><?php _e('Por favor, preencha a data e hora.', 'sistur'); ?></p></div>').show();
            return;
        }

        // Solicitar motivo da alteração
        requestChangeReason(function(reason) {
            if (!reason) {
                return; // Usuário cancelou
            }

            // Combinar data e hora
            var punchDateTime = punchDate + ' ' + punchTime + ':00';

            // Desabilitar botão durante o processamento
            $button.prop('disabled', true).text('<?php _e('Registrando...', 'sistur'); ?>');
            $message.hide();

            $.ajax({
                url: sisturTimeTracking.ajax_url,
                type: 'POST',
                data: {
                    action: 'sistur_time_save_entry',
                    nonce: sisturTimeTracking.nonce,
                    employee_id: employeeId,
                    punch_type: punchType,
                    punch_time: punchDateTime,
                    shift_date: punchDate,
                    notes: 'Registrado pelo administrador',
                    admin_change_reason: reason
                },
                success: function(response) {
                    if (response.success) {
                        $message.html('<div class="notice notice-success"><p><strong><?php _e('Sucesso!', 'sistur'); ?></strong> ' + (response.data.message || '<?php _e('Ponto registrado com sucesso!', 'sistur'); ?>') + '</p></div>').show();

                        // Atualizar a hora para a hora atual
                        var now = new Date();
                        var hours = String(now.getHours()).padStart(2, '0');
                        var minutes = String(now.getMinutes()).padStart(2, '0');
                        $('#sistur-quick-punch-time').val(hours + ':' + minutes);

                        // Se estiver visualizando a folha de ponto do funcionário, recarregar
                        var selectedEmployee = $('#sistur-time-employee').val();
                        if (selectedEmployee == employeeId) {
                            $('#sistur-time-load').click();
                        }
                    } else {
                        $message.html('<div class="notice notice-error"><p><strong><?php _e('Erro!', 'sistur'); ?></strong> ' + (response.data.message || '<?php _e('Erro ao registrar ponto.', 'sistur'); ?>') + '</p></div>').show();
                    }
                },
                error: function() {
                    $message.html('<div class="notice notice-error"><p><?php _e('Erro de comunicação com o servidor.', 'sistur'); ?></p></div>').show();
                },
                complete: function() {
                    // Reabilitar botão
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-clock" style="vertical-align: middle; margin-top: 4px;"></span> <?php _e('Registrar Ponto', 'sistur'); ?>');
                }
            });
        });
    });

    // Permitir registro rápido pressionando Enter nos campos
    $('#sistur-quick-punch-employee, #sistur-quick-punch-type, #sistur-quick-punch-date, #sistur-quick-punch-time').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            e.preventDefault();
            $('#sistur-quick-punch-register').click();
        }
    });

    // Carregar folha de ponto
    $('#sistur-time-load').on('click', function() {
        var employeeId = $('#sistur-time-employee').val();
        var startDate = $('#sistur-time-start').val();
        var endDate = $('#sistur-time-end').val();

        if (!employeeId) {
            alert('<?php _e('Selecione um funcionário.', 'sistur'); ?>');
            return;
        }

        $.ajax({
            url: sisturTimeTracking.ajax_url,
            type: 'GET',
            data: {
                action: 'sistur_time_get_sheet',
                nonce: sisturTimeTracking.nonce,
                employee_id: employeeId,
                start_date: startDate,
                end_date: endDate
            },
            success: function(response) {
                if (response.success) {
                    renderTimeSheet(response.data.sheet);
                } else {
                    alert(response.data.message || 'Erro ao carregar folha de ponto.');
                }
            }
        });
    });

    // Imprimir folha de ponto
    $('#sistur-time-print').on('click', function() {
        var employeeId = $('#sistur-time-employee').val();
        var startDate = $('#sistur-time-start').val();
        var endDate = $('#sistur-time-end').val();

        if (!employeeId) {
            alert('<?php _e('Selecione um funcionário para imprimir.', 'sistur'); ?>');
            return;
        }

        var url = 'admin.php?page=sistur-attendance-sheet-print&employee_id=' + employeeId + 
                  '&period_type=weekly&start_date=' + startDate + '&end_date=' + endDate;
        
        window.open(url, '_blank');
    });

    function renderTimeSheet(sheet) {
        var html = '<table class="wp-list-table widefat fixed striped">';
        html += '<thead><tr>';
        html += '<th><?php _e('Data', 'sistur'); ?></th>';
        html += '<th><?php _e('Entrada', 'sistur'); ?></th>';
        html += '<th><?php _e('Início Almoço', 'sistur'); ?></th>';
        html += '<th><?php _e('Fim Almoço', 'sistur'); ?></th>';
        html += '<th><?php _e('Saída', 'sistur'); ?></th>';
        html += '<th><?php _e('Status', 'sistur'); ?></th>';
        html += '<th style="width: 150px;"><?php _e('Ações', 'sistur'); ?></th>';
        html += '</tr></thead><tbody>';

        if (sheet.length === 0) {
            html += '<tr><td colspan="7"><?php _e('Nenhum registro encontrado.', 'sistur'); ?></td></tr>';
        } else {
            sheet.forEach(function(day) {
                html += '<tr>';
                html += '<td>' + formatDate(day.date) + '</td>';

                var clockIn = findEntry(day.entries, 'clock_in');
                var lunchStart = findEntry(day.entries, 'lunch_start');
                var lunchEnd = findEntry(day.entries, 'lunch_end');
                var clockOut = findEntry(day.entries, 'clock_out');

                html += '<td>' + renderEntryCell(clockIn, day.entries, 'clock_in') + '</td>';
                html += '<td>' + renderEntryCell(lunchStart, day.entries, 'lunch_start') + '</td>';
                html += '<td>' + renderEntryCell(lunchEnd, day.entries, 'lunch_end') + '</td>';
                html += '<td>' + renderEntryCell(clockOut, day.entries, 'clock_out') + '</td>';
                html += '<td>' + (day.status || 'present') + '</td>';
                html += '<td>' + renderActions(day) + '</td>';
                html += '</tr>';
            });
        }

        html += '</tbody></table>';
        $('#sistur-time-sheet-container').html(html);
    }

    function renderEntryCell(timeStr, entries, type) {
        if (!timeStr) {
            return '-';
        }
        var entry = entries.find(function(e) { return e.punch_type === type; });
        if (!entry) {
            return timeStr;
        }
        return '<span style="cursor: pointer; text-decoration: underline;" class="edit-entry-time" data-entry-id="' + entry.id + '" data-entry-type="' + type + '" data-entry-time="' + entry.punch_time + '" data-entry-date="' + entry.shift_date + '">' + timeStr + '</span>';
    }

    function renderActions(day) {
        var html = '';
        if (day.entries && day.entries.length > 0) {
            day.entries.forEach(function(entry) {
                html += '<button class="button button-small delete-entry" data-entry-id="' + entry.id + '" style="margin-right: 5px;">';
                html += '<span class="dashicons dashicons-trash" style="font-size: 13px; line-height: 1.3;"></span>';
                html += '</button>';
            });
        }
        return html;
    }

    function findEntry(entries, type) {
        var entry = entries.find(function(e) { return e.punch_type === type; });
        return entry ? entry.punch_time.split(' ')[1].substring(0, 5) : null;
    }

    function formatDate(dateStr) {
        var parts = dateStr.split('-');
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    // Event handler para editar registro (clique no horário)
    $(document).on('click', '.edit-entry-time', function() {
        var $this = $(this);
        var entryId = $this.data('entry-id');
        var entryType = $this.data('entry-type');
        var entryTime = $this.data('entry-time');
        var entryDate = $this.data('entry-date');

        // Extrair hora do timestamp
        var timeParts = entryTime.split(' ');
        var timeOnly = timeParts.length > 1 ? timeParts[1].substring(0, 5) : entryTime.substring(0, 5);

        // Prompt para novo horário
        var newTime = prompt('<?php _e('Digite o novo horário (HH:MM):', 'sistur'); ?>', timeOnly);

        if (newTime && newTime !== timeOnly) {
            // Validar formato HH:MM
            if (!/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/.test(newTime)) {
                alert('<?php _e('Formato de horário inválido! Use HH:MM', 'sistur'); ?>');
                return;
            }

            var newDateTime = entryDate + ' ' + newTime + ':00';
            var employeeId = $('#sistur-time-employee').val();

            // Solicitar motivo da alteração
            requestChangeReason(function(reason) {
                if (!reason) {
                    return; // Usuário cancelou
                }
                updateEntry(entryId, employeeId, entryType, newDateTime, entryDate, reason);
            });
        }
    });

    // Event handler para deletar registro
    $(document).on('click', '.delete-entry', function() {
        if (!confirm('<?php _e('Tem certeza que deseja excluir este registro?', 'sistur'); ?>')) {
            return;
        }

        var entryId = $(this).data('entry-id');

        // Solicitar motivo da exclusão
        requestChangeReason(function(reason) {
            if (!reason) {
                return; // Usuário cancelou
            }
            deleteEntry(entryId, reason);
        });
    });

    // Função para atualizar registro
    function updateEntry(entryId, employeeId, punchType, punchTime, shiftDate, reason) {
        console.log('Atualizando entrada:', {entryId, employeeId, punchType, punchTime, shiftDate, reason});
        $.ajax({
            url: sisturTimeTracking.ajax_url,
            type: 'POST',
            data: {
                action: 'sistur_time_update_entry',
                nonce: sisturTimeTracking.nonce,
                entry_id: entryId,
                employee_id: employeeId,
                punch_type: punchType,
                punch_time: punchTime,
                shift_date: shiftDate,
                notes: 'Atualizado pelo administrador',
                admin_change_reason: reason
            },
            success: function(response) {
                console.log('Resposta do servidor:', response);
                if (response.success) {
                    alert('<?php _e('Registro atualizado com sucesso!', 'sistur'); ?>');
                    $('#sistur-time-load').click(); // Recarregar folha
                } else {
                    console.error('Erro na atualização:', response);
                    alert('<?php _e('Erro:', 'sistur'); ?> ' + (response.data && response.data.message ? response.data.message : '<?php _e('Erro ao atualizar registro.', 'sistur'); ?>'));
                }
            },
            error: function(xhr, status, error) {
                console.error('Erro AJAX:', {xhr, status, error});
                console.error('Resposta do servidor:', xhr.responseText);
                alert('<?php _e('Erro de comunicação com o servidor:', 'sistur'); ?> ' + error);
            }
        });
    }

    // Função para deletar registro
    function deleteEntry(entryId, reason) {
        $.ajax({
            url: sisturTimeTracking.ajax_url,
            type: 'POST',
            data: {
                action: 'sistur_time_delete_entry',
                nonce: sisturTimeTracking.nonce,
                entry_id: entryId,
                admin_change_reason: reason
            },
            success: function(response) {
                if (response.success) {
                    alert('<?php _e('Registro excluído com sucesso!', 'sistur'); ?>');
                    $('#sistur-time-load').click(); // Recarregar folha
                } else {
                    alert('<?php _e('Erro:', 'sistur'); ?> ' + (response.data.message || '<?php _e('Erro ao excluir registro.', 'sistur'); ?>'));
                }
            },
            error: function() {
                alert('<?php _e('Erro de comunicação com o servidor.', 'sistur'); ?>');
            }
        });
    }

    // ============================================================
    // REGISTRO EM MASSA PARA TESTES
    // ============================================================
    
    $('#sistur-bulk-register').on('click', function() {
        var employeeId = $('#sistur-bulk-employee').val();
        var month = $('#sistur-bulk-month').val();
        var baseEntryTime = $('#sistur-bulk-entry-time').val();
        var lunchDuration = parseInt($('#sistur-bulk-lunch-duration').val()) || 60;
        
        if (!employeeId) {
            alert('<?php _e('Selecione um funcionário.', 'sistur'); ?>');
            return;
        }
        
        if (!month) {
            alert('<?php _e('Selecione um mês.', 'sistur'); ?>');
            return;
        }
        
        if (!confirm('<?php _e('Isso irá preencher todo o mês selecionado com registros de ponto.\n\nDias com registros existentes serão IGNORADOS.\nFins de semana e folgas (conforme escala) serão IGNORADOS.\n\nDeseja continuar?', 'sistur'); ?>')) {
            return;
        }
        
        // Desabilitar botão
        var $button = $(this);
        $button.prop('disabled', true);
        
        // Mostrar progresso
        $('#sistur-bulk-progress').show();
        $('#sistur-bulk-message').hide();
        
        // Calcular dias do mês
        var yearMonth = month.split('-');
        var year = parseInt(yearMonth[0]);
        var monthNum = parseInt(yearMonth[1]);
        var daysInMonth = new Date(year, monthNum, 0).getDate();
        
        var days = [];
        for (var d = 1; d <= daysInMonth; d++) {
            var dateStr = year + '-' + String(monthNum).padStart(2, '0') + '-' + String(d).padStart(2, '0');
            days.push(dateStr);
        }
        
        // Processar dias um a um
        var currentIndex = 0;
        var successCount = 0;
        var skipCount = 0;
        var errorCount = 0;
        var weekWorkedMinutes = 0; // Acumulador semanal
        var weeklyTarget = 2640; // 44h padrão (será atualizado via AJAX)
        var dailyTarget = 480; // 8h padrão
        
        // Primeiro, buscar a escala do funcionário
        $.ajax({
            url: sisturTimeTracking.ajax_url,
            type: 'POST',
            async: false, // Síncrono para ter os dados antes de processar
            data: {
                action: 'sistur_get_employee_active_schedule',
                nonce: sisturTimeTracking.nonce,
                employee_id: employeeId
            },
            success: function(response) {
                console.log('[BULK] Resposta da escala:', response);
                if (response.success && response.data) {
                    weeklyTarget = parseInt(response.data.weekly_hours_minutes) || 2640;
                    dailyTarget = parseInt(response.data.daily_hours_minutes) || 0;
                    
                    // Se daily é 0 (escala flexível), calcular baseado em 5 dias úteis
                    if (dailyTarget === 0 && weeklyTarget > 0) {
                        dailyTarget = Math.round(weeklyTarget / 5);
                        console.log('[BULK] Escala flexível detectada, calculando daily:', dailyTarget + ' min/dia');
                    }
                    
                    console.log('[BULK] Escala carregada - Semanal:', weeklyTarget + ' min (' + (weeklyTarget/60).toFixed(1) + 'h), Diário:', dailyTarget + ' min (' + (dailyTarget/60).toFixed(1) + 'h)');
                } else {
                    console.warn('[BULK] Escala não encontrada, usando padrões: 44h/semana, 8h/dia');
                }
            },
            error: function(xhr, status, error) {
                console.error('[BULK] Erro ao buscar escala:', error);
            }
        });
        
        function processNextDay() {
            if (currentIndex >= days.length) {
                // Finalizado
                finishBulkRegistration();
                return;
            }
            
            var dateStr = days[currentIndex];
            var dateObj = new Date(dateStr + 'T12:00:00');
            var dayOfWeek = dateObj.getDay(); // 0 = domingo, 6 = sábado
            
            // Atualizar progresso
            var progress = Math.round((currentIndex / days.length) * 100);
            $('#sistur-bulk-progress-bar').css('width', progress + '%');
            $('#sistur-bulk-status').text('Processando ' + formatDatePT(dateStr) + '... (' + (currentIndex + 1) + '/' + days.length + ')');
            
            // Se é segunda-feira, reiniciar contador semanal
            if (dayOfWeek === 1) {
                weekWorkedMinutes = 0;
            }
            
            // Verificar se é fim de semana (padrão) - depois será ajustado pela escala
            var isWeekend = (dayOfWeek === 0 || dayOfWeek === 6);
            
            // Verificar se é sexta-feira (último dia útil da semana)
            var isFriday = (dayOfWeek === 5);
            
            // Pula fim de semana por padrão
            if (isWeekend) {
                skipCount++;
                currentIndex++;
                setTimeout(processNextDay, 50);
                return;
            }
            
            // Verificar se já existe registro para este dia
            $.ajax({
                url: sisturTimeTracking.ajax_url,
                type: 'GET',
                data: {
                    action: 'sistur_time_get_day_detail',
                    nonce: sisturTimeTracking.nonce,
                    employee_id: employeeId,
                    date: dateStr
                },
                success: function(response) {
                    if (response.success && response.data && response.data.entries && response.data.entries.length > 0) {
                        // Dia já tem registros, pular
                        skipCount++;
                        currentIndex++;
                        setTimeout(processNextDay, 50);
                    } else {
                        // Calcular horas para este dia
                        var targetMinutesForDay = dailyTarget;
                        
                        // Se é sexta-feira, ajustar para completar a semana
                        if (isFriday) {
                            var remainingForWeek = weeklyTarget - weekWorkedMinutes;
                            if (remainingForWeek > 0 && remainingForWeek < 540) { // Max 9h
                                targetMinutesForDay = remainingForWeek;
                            } else if (remainingForWeek >= 540) {
                                targetMinutesForDay = 540; // Cap em 9h
                            }
                        }
                        
                        // Registrar o dia
                        registerDayWithRandomTimes(employeeId, dateStr, baseEntryTime, lunchDuration, targetMinutesForDay, function(success, workedMinutes) {
                            if (success) {
                                successCount++;
                                weekWorkedMinutes += workedMinutes;
                            } else {
                                errorCount++;
                            }
                            currentIndex++;
                            setTimeout(processNextDay, 100);
                        });
                    }
                },
                error: function() {
                    // Em caso de erro na verificação, tentar registrar mesmo assim
                    registerDayWithRandomTimes(employeeId, dateStr, baseEntryTime, lunchDuration, dailyTarget, function(success, workedMinutes) {
                        if (success) {
                            successCount++;
                            weekWorkedMinutes += workedMinutes;
                        } else {
                            errorCount++;
                        }
                        currentIndex++;
                        setTimeout(processNextDay, 100);
                    });
                }
            });
        }
        
        function registerDayWithRandomTimes(empId, date, baseEntry, lunchDur, targetMinutes, callback) {
            // Gerar horários aleatórios
            var entryParts = baseEntry.split(':');
            var entryHour = parseInt(entryParts[0]);
            var entryMin = parseInt(entryParts[1]);
            
            // Adicionar variação aleatória (-15 a +20 min) na entrada
            var entryVariation = Math.floor(Math.random() * 36) - 15;
            entryMin += entryVariation;
            if (entryMin < 0) { entryHour--; entryMin += 60; }
            if (entryMin >= 60) { entryHour++; entryMin -= 60; }
            
            var clockIn = String(entryHour).padStart(2, '0') + ':' + String(entryMin).padStart(2, '0') + ':00';
            
            // Almoço: 4h após entrada, com variação de -20 a +30 min
            var lunchStartHour = entryHour + 4;
            var lunchStartMin = entryMin + Math.floor(Math.random() * 51) - 20;
            if (lunchStartMin < 0) { lunchStartHour--; lunchStartMin += 60; }
            if (lunchStartMin >= 60) { lunchStartHour++; lunchStartMin -= 60; }
            
            var lunchStart = String(lunchStartHour).padStart(2, '0') + ':' + String(lunchStartMin).padStart(2, '0') + ':00';
            
            // Fim do almoço: duração do almoço + variação de -10 a +15 min
            var lunchEndMin = lunchStartMin + lunchDur + Math.floor(Math.random() * 26) - 10;
            var lunchEndHour = lunchStartHour;
            while (lunchEndMin >= 60) { lunchEndHour++; lunchEndMin -= 60; }
            if (lunchEndMin < 0) { lunchEndHour--; lunchEndMin += 60; }
            
            var lunchEnd = String(lunchEndHour).padStart(2, '0') + ':' + String(lunchEndMin).padStart(2, '0') + ':00';
            
            // Calcular saída para bater o target de minutos trabalhados
            // Tempo trabalhado = (lunchStart - clockIn) + (clockOut - lunchEnd)
            // targetMinutes = morningWork + afternoonWork
            // morningWork = (lunchStartHour * 60 + lunchStartMin) - (entryHour * 60 + entryMin)
            var morningWork = (lunchStartHour * 60 + lunchStartMin) - (entryHour * 60 + entryMin);
            var afternoonWork = targetMinutes - morningWork;
            
            // Adicionar pequena variação para parecer natural (-5 a +5 min)
            afternoonWork += Math.floor(Math.random() * 11) - 5;
            
            // Calcular horário de saída
            var clockOutTotalMin = (lunchEndHour * 60 + lunchEndMin) + afternoonWork;
            var clockOutHour = Math.floor(clockOutTotalMin / 60);
            var clockOutMin = clockOutTotalMin % 60;
            
            // Max 22:00
            if (clockOutHour > 22) { clockOutHour = 22; clockOutMin = 0; }
            
            var clockOut = String(clockOutHour).padStart(2, '0') + ':' + String(clockOutMin).padStart(2, '0') + ':00';
            
            // Calcular minutos reais trabalhados
            var actualWorked = morningWork + ((clockOutHour * 60 + clockOutMin) - (lunchEndHour * 60 + lunchEndMin));
            
            // Registrar os 4 pontos em sequência
            var punches = [
                { type: 'clock_in', time: clockIn },
                { type: 'lunch_start', time: lunchStart },
                { type: 'lunch_end', time: lunchEnd },
                { type: 'clock_out', time: clockOut }
            ];
            
            var punchIndex = 0;
            
            function registerNextPunch() {
                if (punchIndex >= punches.length) {
                    console.log('[BULK] Dia ' + date + ' registrado com sucesso. Trabalhado: ' + actualWorked + ' min');
                    callback(true, actualWorked);
                    return;
                }
                
                var punch = punches[punchIndex];
                var requestData = {
                    action: 'sistur_time_save_entry',
                    nonce: sisturTimeTracking.nonce,
                    employee_id: empId,
                    punch_type: punch.type,
                    punch_time: date + ' ' + punch.time,
                    shift_date: date,
                    notes: '[BULK] Registro automático para testes',
                    admin_change_reason: 'Registro em massa para testes'
                };
                
                console.log('[BULK] Registrando ' + punch.type + ' para ' + date + ' às ' + punch.time);
                
                $.ajax({
                    url: sisturTimeTracking.ajax_url,
                    type: 'POST',
                    data: requestData,
                    success: function(response) {
                        if (response.success) {
                            console.log('[BULK] ✓ ' + punch.type + ' salvo com sucesso', response);
                            punchIndex++;
                            setTimeout(registerNextPunch, 50);
                        } else {
                            var errorMsg = response.data && response.data.message ? response.data.message : JSON.stringify(response.data);
                            console.error('[BULK] ✗ Erro ao salvar ' + punch.type + ': ' + errorMsg);
                            // Continuar mesmo com erro para não travar
                            punchIndex++;
                            setTimeout(registerNextPunch, 50);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('[BULK] ✗ Erro AJAX:', status, error);
                        console.error('[BULK] Resposta:', xhr.responseText);
                        // Continuar mesmo com erro
                        punchIndex++;
                        setTimeout(registerNextPunch, 50);
                    }
                });
            }
            
            registerNextPunch();
        }
        
        function finishBulkRegistration() {
            $('#sistur-bulk-progress-bar').css('width', '100%');
            $('#sistur-bulk-status').text('Concluído!');
            
            var msg = '<div class="notice notice-success"><p><strong>Registro em massa concluído!</strong><br>';
            msg += '✅ Registrados: ' + successCount + ' dias<br>';
            msg += '⏭️ Pulados (já preenchidos ou folgas): ' + skipCount + ' dias<br>';
            if (errorCount > 0) {
                msg += '❌ Erros: ' + errorCount + ' dias<br>';
            }
            msg += '</p></div>';
            
            $('#sistur-bulk-message').html(msg).show();
            $button.prop('disabled', false);
        }
        
        function formatDatePT(dateStr) {
            var parts = dateStr.split('-');
            return parts[2] + '/' + parts[1] + '/' + parts[0];
        }
        
        // Iniciar processamento
        processNextDay();
    });
});
</script>
