<?php
/**
 * Modais para Gerenciamento de Banco de Horas
 *
 * Inclui todos os modais necessários para:
 * - Abater banco de horas (folga e pagamento)
 * - Aprovar/Rejeitar abatimentos
 * - Gerenciar feriados
 * - Configurar pagamento do funcionário
 *
 * @package SISTUR
 * @since 1.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$employees_table = $wpdb->prefix . 'sistur_employees';
$employees = $wpdb->get_results("SELECT id, name FROM {$employees_table} WHERE status = 'active' ORDER BY name ASC");
?>

<!-- ==================== MODAL: ABATER BANCO DE HORAS ==================== -->
<div id="modal-abater-banco-horas" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 8px; width: 90%; max-width: 700px; max-height: 90vh; overflow-y: auto;">
        <h2>Abater Banco de Horas</h2>

        <form id="form-abater-banco-horas">
            <!-- Seleção de Funcionário -->
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Funcionário *</label>
                <select name="employee_id" id="employee-select-timebank" required style="width: 100%; padding: 8px;">
                    <option value="">Selecione um funcionário...</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo esc_attr($emp->id); ?>"><?php echo esc_html($emp->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Exibir Saldo Atual -->
            <div id="balance-display" style="display: none; background: #e7f3ff; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                <p style="margin: 0 0 5px 0;"><strong>Saldo Atual:</strong> <span id="balance-text">-</span></p>
                <p style="margin: 0;"><strong>Total em Minutos:</strong> <span id="balance-minutes">-</span></p>
            </div>

            <!-- Tipo de Abatimento -->
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Tipo de Compensação *</label>
                <select name="deduction_type" id="deduction-type" required style="width: 100%; padding: 8px;">
                    <option value="">Selecione...</option>
                    <option value="folga">Compensação em Folga</option>
                    <option value="pagamento">Pagamento em Dinheiro</option>
                </select>
            </div>

            <!-- Quantidade -->
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Quantidade de Horas *</label>
                <div>
                    <label style="margin-right: 15px;">
                        <input type="radio" name="deduction_mode" value="integral" checked> Integral (Todo o saldo)
                    </label>
                    <label>
                        <input type="radio" name="deduction_mode" value="parcial"> Parcial
                    </label>
                </div>
            </div>

            <div id="partial-amount" style="display: none; margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Minutos a Abater *</label>
                <input type="number" name="minutes_deducted" min="1" id="minutes-input" style="width: 100%; padding: 8px;">
                <small style="color: #666;">Informe a quantidade em minutos (ex: 480 = 8 horas)</small>
            </div>

            <!-- CAMPOS ESPECÍFICOS PARA FOLGA -->
            <div id="folga-fields" style="display: none;">
                <h3 style="margin: 20px 0 10px 0; font-size: 16px; border-bottom: 1px solid #ddd; padding-bottom: 5px;">Detalhes da Folga</h3>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Data de Início da Folga *</label>
                    <input type="date" name="time_off_start_date" id="time-off-start" style="width: 100%; padding: 8px;">
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Data de Fim da Folga</label>
                    <input type="date" name="time_off_end_date" id="time-off-end" style="width: 100%; padding: 8px;">
                    <small style="color: #666;">Deixe em branco se for apenas 1 dia</small>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Descrição da Folga</label>
                    <textarea name="time_off_description" rows="3" style="width: 100%; padding: 8px;"></textarea>
                </div>
            </div>

            <!-- CAMPOS ESPECÍFICOS PARA PAGAMENTO -->
            <div id="pagamento-fields" style="display: none;">
                <h3 style="margin: 20px 0 10px 0; font-size: 16px; border-bottom: 1px solid #ddd; padding-bottom: 5px;">Detalhes do Pagamento</h3>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Período de Referência (opcional)</label>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="date" name="start_date" id="payment-start-date" style="flex: 1; padding: 8px;">
                        <span>até</span>
                        <input type="date" name="end_date" id="payment-end-date" style="flex: 1; padding: 8px;">
                    </div>
                    <small style="color: #666;">Deixe em branco para usar últimos 30 dias</small>
                </div>

                <button type="button" id="btn-calculate-preview" class="button" style="margin-bottom: 15px;">
                    🔍 Calcular Prévia
                </button>

                <div id="payment-preview" style="display: none; border: 1px solid #ccc; padding: 15px; margin: 10px 0; background: #f9f9f9;">
                    <h4 style="margin: 0 0 10px 0;">Prévia do Pagamento</h4>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #e0e0e0;">
                                <th style="padding: 8px; text-align: left;">Tipo</th>
                                <th style="padding: 8px; text-align: right;">Horas</th>
                                <th style="padding: 8px; text-align: right;">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="padding: 8px;">Dias Úteis</td>
                                <td style="padding: 8px; text-align: right;" id="preview-util-hours">-</td>
                                <td style="padding: 8px; text-align: right;" id="preview-util-value">-</td>
                            </tr>
                            <tr style="background: #f5f5f5;">
                                <td style="padding: 8px;">Fins de Semana</td>
                                <td style="padding: 8px; text-align: right;" id="preview-weekend-hours">-</td>
                                <td style="padding: 8px; text-align: right;" id="preview-weekend-value">-</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px;">Feriados</td>
                                <td style="padding: 8px; text-align: right;" id="preview-holiday-hours">-</td>
                                <td style="padding: 8px; text-align: right;" id="preview-holiday-value">-</td>
                            </tr>
                            <tr style="font-weight: bold; background: #d0e8ff; border-top: 2px solid #333;">
                                <td style="padding: 8px;">TOTAL</td>
                                <td style="padding: 8px; text-align: right;" id="preview-total-hours">-</td>
                                <td style="padding: 8px; text-align: right;" id="preview-total-value">-</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Observações Gerais -->
            <div class="form-group" style="margin: 20px 0 15px 0;">
                <label style="display: block; margin-bottom: 5px;">Observações</label>
                <textarea name="notes" rows="3" style="width: 100%; padding: 8px;"></textarea>
            </div>

            <input type="hidden" name="is_partial" id="is-partial" value="0">

            <div style="text-align: right; margin-top: 20px; border-top: 1px solid #ddd; padding-top: 15px;">
                <button type="button" id="btn-cancel-deduction" class="button" style="margin-right: 10px;">Cancelar</button>
                <button type="submit" class="button button-primary">Registrar Abatimento</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== MODAL: APROVAR ABATIMENTO ==================== -->
<div id="modal-aprovar-abatimento" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 8px; width: 90%; max-width: 500px;">
        <h2 id="modal-aprovar-title">Aprovar/Rejeitar Abatimento</h2>

        <form id="form-aprovar-abatimento">
            <input type="hidden" name="deduction_id" id="approval-deduction-id">
            <input type="hidden" name="status" id="approval-status">

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Observações</label>
                <textarea name="notes" rows="4" style="width: 100%; padding: 8px;" placeholder="Informe o motivo da aprovação/rejeição (opcional)"></textarea>
            </div>

            <div style="text-align: right; margin-top: 20px;">
                <button type="button" id="btn-cancel-approval" class="button" style="margin-right: 10px;">Cancelar</button>
                <button type="submit" class="button button-primary">Confirmar</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== MODAL: GERENCIAR FERIADOS ==================== -->
<div id="modal-gerenciar-feriados" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 8px; width: 90%; max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <h2>Gerenciar Feriados</h2>

        <button type="button" id="btn-add-holiday" class="button button-primary" style="margin-bottom: 15px;">
            + Adicionar Feriado
        </button>

        <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Descrição</th>
                    <th>Tipo</th>
                    <th>Adicional</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody id="holidays-list">
                <tr>
                    <td colspan="6" style="text-align: center;">Carregando...</td>
                </tr>
            </tbody>
        </table>

        <div style="text-align: right; margin-top: 20px;">
            <button type="button" id="btn-close-holidays" class="button">Fechar</button>
        </div>
    </div>
</div>

<!-- ==================== MODAL: ADICIONAR/EDITAR FERIADO ==================== -->
<div id="modal-holiday-form" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100001; align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 8px; width: 90%; max-width: 500px;">
        <h2 id="holiday-form-title">Adicionar Feriado</h2>

        <form id="form-holiday">
            <input type="hidden" name="holiday_id" id="holiday-id">

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Data *</label>
                <input type="date" name="holiday_date" required style="width: 100%; padding: 8px;">
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Descrição *</label>
                <input type="text" name="description" required placeholder="Ex: Natal, Independência" style="width: 100%; padding: 8px;">
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Tipo *</label>
                <select name="holiday_type" required style="width: 100%; padding: 8px;">
                    <option value="nacional">Nacional</option>
                    <option value="estadual">Estadual</option>
                    <option value="municipal">Municipal</option>
                    <option value="ponto_facultativo">Ponto Facultativo</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Multiplicador Adicional *</label>
                <input type="number" name="multiplicador_adicional" step="0.01" min="1.00" value="1.00" required style="width: 100%; padding: 8px;">
                <small style="color: #666;">1.00 = 100%, 1.50 = 150%, 2.00 = 200%</small>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Status</label>
                <select name="status" style="width: 100%; padding: 8px;">
                    <option value="active">Ativo</option>
                    <option value="inactive">Inativo</option>
                </select>
            </div>

            <div style="text-align: right; margin-top: 20px;">
                <button type="button" id="btn-cancel-holiday" class="button" style="margin-right: 10px;">Cancelar</button>
                <button type="submit" class="button button-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== MODAL: CONFIGURAÇÃO DE PAGAMENTO ==================== -->
<div id="modal-payment-config" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 8px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
        <h2>Configuração de Pagamento</h2>
        <p><strong>Funcionário:</strong> <span id="config-employee-name">-</span></p>

        <form id="form-payment-config">
            <input type="hidden" name="employee_id" id="config-employee-id">

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Salário Base (Mensal) *</label>
                <input type="number" name="salario_base" step="0.01" min="0" required style="width: 100%; padding: 8px;" placeholder="0.00">
                <small style="color: #666;">Salário mensal do funcionário</small>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Método de Cálculo</label>
                <select name="calculation_method" id="calculation-method" style="width: 100%; padding: 8px;">
                    <option value="automatic">Automático (calcula valor/hora)</option>
                    <option value="manual">Manual (definir valor/hora)</option>
                </select>
            </div>

            <div class="form-group" id="manual-valor-hora" style="display: none; margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Valor da Hora Base *</label>
                <input type="number" name="valor_hora_base" step="0.01" min="0" style="width: 100%; padding: 8px;" placeholder="0.00">
                <small style="color: #666;">Será calculado automaticamente se método = Automático</small>
            </div>

            <h3 style="margin: 20px 0 10px 0; font-size: 16px; border-bottom: 1px solid #ddd; padding-bottom: 5px;">Multiplicadores de Hora Extra</h3>

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Dias Úteis *</label>
                <input type="number" name="multiplicador_dia_util" step="0.01" min="1.00" value="1.50" required style="width: 100%; padding: 8px;">
                <small style="color: #666;">Padrão CLT: 1.50 (50% adicional)</small>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Fins de Semana *</label>
                <input type="number" name="multiplicador_fim_semana" step="0.01" min="1.00" value="2.00" required style="width: 100%; padding: 8px;">
                <small style="color: #666;">Padrão: 2.00 (100% adicional)</small>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Feriados *</label>
                <input type="number" name="multiplicador_feriado" step="0.01" min="1.00" value="2.50" required style="width: 100%; padding: 8px;">
                <small style="color: #666;">Padrão: 2.50 (150% adicional)</small>
            </div>

            <div style="text-align: right; margin-top: 20px; border-top: 1px solid #ddd; padding-top: 15px;">
                <button type="button" id="btn-cancel-config" class="button" style="margin-right: 10px;">Cancelar</button>
                <button type="submit" class="button button-primary">Salvar Configuração</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== MODAL: PERDOAR FALTA ==================== -->
<div id="modal-perdoar-falta" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 100001; align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 8px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
        <h2 style="margin-top: 0; border-bottom: 2px solid #46b450; padding-bottom: 10px; color: #1d2327;">
            <span class="dashicons dashicons-heart" style="color: #46b450;"></span>
            Perdoar/Abonar Falta
        </h2>

        <form id="form-perdoar-falta">
            <!-- Seleção de Funcionário -->
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Funcionário *</label>
                <select name="employee_id" id="forgive-employee-select" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="">Selecione um funcionário...</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo esc_attr($emp->id); ?>"><?php echo esc_html($emp->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Tipo de Justificativa -->
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Tipo de Justificativa *</label>
                <select name="justification_type" id="forgive-type" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="">Selecione...</option>
                    <option value="atestado">🏥 Atestado Médico</option>
                    <option value="abono">✅ Abono (Empresa)</option>
                    <option value="compensacao">🔄 Compensação de Horas</option>
                    <option value="licenca">📋 Licença (Luto, Casamento, etc)</option>
                    <option value="feriado_local">📅 Feriado Local/Municipal</option>
                    <option value="outros">📝 Outros</option>
                </select>
            </div>

            <!-- Data(s) -->
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Data(s) a Perdoar *</label>
                <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
                    <input type="date" name="date_start" id="forgive-date-start" required style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <span style="color: #666;">até</span>
                    <input type="date" name="date_end" id="forgive-date-end" style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <small style="color: #666;">Deixe "até" em branco para perdoar apenas 1 dia</small>
            </div>

            <!-- Observações -->
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Observações</label>
                <textarea name="notes" id="forgive-notes" rows="3" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" placeholder="Ex: CID, número do atestado, motivo do abono..."></textarea>
            </div>

            <!-- Aviso -->
            <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 4px; margin-bottom: 15px;">
                <strong>⚠️ Atenção:</strong> Esta ação irá zerar o débito de banco de horas para as datas selecionadas. O funcionário não terá desconto.
            </div>

            <div style="text-align: right; margin-top: 20px; border-top: 1px solid #ddd; padding-top: 15px;">
                <button type="button" id="btn-cancel-forgive" class="button" style="margin-right: 10px;">Cancelar</button>
                <button type="submit" class="button button-primary" style="background-color: #46b450; border-color: #3c9c3f;">
                    <span class="dashicons dashicons-yes" style="vertical-align: middle;"></span>
                    Confirmar Perdão
                </button>
            </div>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // ==================== PERDÃO DE FALTA ====================
    
    // Abrir modal de perdoar falta
    $(document).on('click', '.btn-open-forgive-modal, #btn-perdoar-falta', function() {
        var employeeId = $(this).data('employee-id') || '';
        var date = $(this).data('date') || '';
        
        if (employeeId) {
            $('#forgive-employee-select').val(employeeId);
        }
        if (date) {
            $('#forgive-date-start').val(date);
        }
        
        $('#modal-perdoar-falta').css('display', 'flex');
    });
    
    // Fechar modal
    $('#btn-cancel-forgive').on('click', function() {
        $('#modal-perdoar-falta').hide();
    });
    
    // Fechar ao clicar fora
    $('#modal-perdoar-falta').on('click', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
    
    // Submit do formulário
    $('#form-perdoar-falta').on('submit', function(e) {
        e.preventDefault();
        
        var employeeId = $('#forgive-employee-select').val();
        var dateStart = $('#forgive-date-start').val();
        var dateEnd = $('#forgive-date-end').val() || dateStart;
        var justificationType = $('#forgive-type').val();
        var notes = $('#forgive-notes').val();
        
        if (!employeeId || !dateStart || !justificationType) {
            alert('Por favor, preencha todos os campos obrigatórios.');
            return;
        }
        
        // Gerar array de datas
        var dates = [];
        var current = new Date(dateStart);
        var end = new Date(dateEnd);
        
        while (current <= end) {
            dates.push(current.toISOString().split('T')[0]);
            current.setDate(current.getDate() + 1);
        }
        
        // Preparar notas com tipo de justificativa
        var typeLabels = {
            'atestado': 'Atestado Médico',
            'abono': 'Abono da Empresa',
            'compensacao': 'Compensação de Horas',
            'licenca': 'Licença',
            'feriado_local': 'Feriado Local',
            'outros': 'Outros'
        };
        var fullNotes = 'Tipo: ' + typeLabels[justificationType] + (notes ? '. ' + notes : '');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_timebank_forgive_absence',
                nonce: sisturTimebankNonce.nonce,
                employee_id: employeeId,
                dates: dates,
                notes: fullNotes
            },
            success: function(response) {
                if (response.success) {
                    alert('✅ ' + response.data.message);
                    $('#modal-perdoar-falta').hide();
                    $('#form-perdoar-falta')[0].reset();
                    // Recarregar página para atualizar dados
                    location.reload();
                } else {
                    alert('❌ Erro: ' + (response.data.message || 'Erro desconhecido'));
                }
            },
            error: function() {
                alert('❌ Erro de conexão com o servidor.');
            }
        });
    });
});
</script>

<!-- Estilos adicionais para os modais -->
<style>
    #modal-abater-banco-horas,
    #modal-aprovar-abatimento,
    #modal-gerenciar-feriados,
    #modal-holiday-form,
    #modal-payment-config {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
    }

    #modal-abater-banco-horas h2,
    #modal-aprovar-abatimento h2,
    #modal-gerenciar-feriados h2,
    #modal-holiday-form h2,
    #modal-payment-config h2 {
        margin-top: 0;
        border-bottom: 2px solid #0073aa;
        padding-bottom: 10px;
    }

    #modal-abater-banco-horas input[type="text"],
    #modal-abater-banco-horas input[type="number"],
    #modal-abater-banco-horas input[type="date"],
    #modal-abater-banco-horas select,
    #modal-abater-banco-horas textarea,
    #modal-aprovar-abatimento textarea,
    #modal-holiday-form input,
    #modal-holiday-form select,
    #modal-payment-config input,
    #modal-payment-config select {
        border: 1px solid #ddd;
        border-radius: 4px;
    }

    #modal-abater-banco-horas input:focus,
    #modal-abater-banco-horas select:focus,
    #modal-abater-banco-horas textarea:focus,
    #modal-holiday-form input:focus,
    #modal-holiday-form select:focus,
    #modal-payment-config input:focus,
    #modal-payment-config select:focus {
        border-color: #0073aa;
        outline: none;
        box-shadow: 0 0 0 1px #0073aa;
    }
</style>
