/**
 * Gerenciador de Banco de Horas - Frontend
 *
 * Gerencia modais e interações para:
 * - Abatimento de banco de horas por folga
 * - Abatimento de banco de horas por pagamento
 * - Aprovação de abatimentos
 * - Gestão de feriados
 * - Configuração de pagamento
 *
 * @since 1.5.0
 */

(function($) {
    'use strict';

    const TimebankManager = {
        nonce: sisturTimebankNonce.nonce,
        ajaxurl: sisturTimebankNonce.ajaxurl,
        currentEmployeeId: null,
        currentBalance: null,
        currentDeductionId: null,

        /**
         * Inicializa o gerenciador
         */
        init: function() {
            this.bindEvents();
            this.loadPendingDeductions();
        },

        /**
         * Vincula eventos
         */
        bindEvents: function() {
            // Botão abrir modal principal
            $(document).on('click', '.btn-abater-banco-horas', this.openAbaterModal.bind(this));

            // Seleção de funcionário
            $(document).on('change', '#employee-select-timebank', this.onEmployeeSelect.bind(this));

            // Tipo de abatimento
            $(document).on('change', '#deduction-type', this.onDeductionTypeChange.bind(this));

            // Modo de abatimento (integral/parcial)
            $(document).on('change', 'input[name="deduction_mode"]', this.onDeductionModeChange.bind(this));

            // Calcular prévia de pagamento
            $(document).on('click', '#btn-calculate-preview', this.calculatePaymentPreview.bind(this));

            // Submit formulário
            $(document).on('submit', '#form-abater-banco-horas', this.submitDeduction.bind(this));

            // Cancelar
            $(document).on('click', '#btn-cancel-deduction', this.closeAbaterModal.bind(this));

            // Aprovação
            $(document).on('click', '.btn-approve-deduction', this.openApprovalModal.bind(this));
            $(document).on('submit', '#form-aprovar-abatimento', this.submitApproval.bind(this));
            $(document).on('click', '#btn-cancel-approval', this.closeApprovalModal.bind(this));

            // Feriados
            $(document).on('click', '#btn-manage-holidays', this.openHolidaysModal.bind(this));
            $(document).on('click', '#btn-add-holiday', this.openHolidayForm.bind(this));
            $(document).on('submit', '#form-holiday', this.submitHoliday.bind(this));
            $(document).on('click', '.btn-edit-holiday', this.editHoliday.bind(this));
            $(document).on('click', '.btn-delete-holiday', this.deleteHoliday.bind(this));
            $(document).on('click', '#btn-close-holidays', this.closeHolidaysModal.bind(this));
            $(document).on('click', '#btn-cancel-holiday', this.closeHolidayForm.bind(this));

            // Configuração de pagamento
            $(document).on('click', '.btn-payment-config', this.openPaymentConfigModal.bind(this));
            $(document).on('change', '#calculation-method', this.onCalculationMethodChange.bind(this));
            $(document).on('submit', '#form-payment-config', this.submitPaymentConfig.bind(this));
            $(document).on('click', '#btn-cancel-config', this.closePaymentConfigModal.bind(this));
        },

        /**
         * Abre modal de abater banco de horas
         */
        openAbaterModal: function(e) {
            e.preventDefault();
            const employeeId = $(e.currentTarget).data('employee-id');

            if (employeeId) {
                $('#employee-select-timebank').val(employeeId).trigger('change');
            }

            this.resetAbaterForm();
            $('#modal-abater-banco-horas').css('display', 'flex');
        },

        /**
         * Fecha modal de abater
         */
        closeAbaterModal: function() {
            $('#modal-abater-banco-horas').hide();
            this.resetAbaterForm();
        },

        /**
         * Reseta formulário de abatimento
         */
        resetAbaterForm: function() {
            $('#form-abater-banco-horas')[0].reset();
            $('#balance-display').hide();
            $('#folga-fields').hide();
            $('#pagamento-fields').hide();
            $('#partial-amount').hide();
            $('#payment-preview').hide();
            this.currentEmployeeId = null;
            this.currentBalance = null;
        },

        /**
         * Quando seleciona funcionário
         */
        onEmployeeSelect: function(e) {
            this.currentEmployeeId = $(e.currentTarget).val();

            if (!this.currentEmployeeId) {
                $('#balance-display').hide();
                return;
            }

            this.loadEmployeeBalance();
        },

        /**
         * Carrega saldo do funcionário
         */
        loadEmployeeBalance: function() {
            const self = this;

            $.ajax({
                url: this.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sistur_get_employee_timebank_balance',
                    nonce: this.nonce,
                    employee_id: this.currentEmployeeId
                },
                beforeSend: function() {
                    $('#balance-text').text('Carregando...');
                    $('#balance-display').show();
                },
                success: function(response) {
                    if (response.success) {
                        self.currentBalance = response.data.balance;
                        $('#balance-text').text(response.data.balance.formatted);
                        $('#balance-minutes').text(response.data.balance.total_minutos + ' minutos');

                        if (response.data.balance.total_minutos <= 0) {
                            $('#balance-alert').remove();
                            $('#balance-display').append(
                                '<p id="balance-alert" style="color: red; font-weight: bold;">⚠ Funcionário não possui saldo positivo no banco de horas.</p>'
                            );
                        } else {
                            $('#balance-alert').remove();
                        }
                    } else {
                        alert('Erro ao carregar saldo: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('Erro ao conectar com o servidor');
                }
            });
        },

        /**
         * Quando muda tipo de abatimento
         */
        onDeductionTypeChange: function(e) {
            const type = $(e.currentTarget).val();

            $('#folga-fields').hide();
            $('#pagamento-fields').hide();
            $('#payment-preview').hide();

            if (type === 'folga') {
                $('#folga-fields').show();
            } else if (type === 'pagamento') {
                $('#pagamento-fields').show();
            }
        },

        /**
         * Quando muda modo (integral/parcial)
         */
        onDeductionModeChange: function(e) {
            const mode = $(e.currentTarget).val();

            if (mode === 'parcial') {
                $('#partial-amount').show();
                $('#is-partial').val('1');
                $('#minutes-input').val('').focus();
            } else {
                $('#partial-amount').hide();
                $('#is-partial').val('0');
                if (this.currentBalance) {
                    $('#minutes-input').val(this.currentBalance.total_minutos);
                }
            }
        },

        /**
         * Calcula prévia de pagamento
         */
        calculatePaymentPreview: function(e) {
            e.preventDefault();

            const mode = $('input[name="deduction_mode"]:checked').val();
            let minutesToPay;

            if (mode === 'integral') {
                if (!this.currentBalance) {
                    alert('Selecione um funcionário primeiro');
                    return;
                }
                minutesToPay = this.currentBalance.total_minutos;
            } else {
                minutesToPay = parseInt($('#minutes-input').val());
            }

            if (!minutesToPay || minutesToPay <= 0) {
                alert('Informe a quantidade de minutos');
                return;
            }

            const startDate = $('#payment-start-date').val();
            const endDate = $('#payment-end-date').val();

            const self = this;

            $.ajax({
                url: this.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sistur_calculate_payment_preview',
                    nonce: this.nonce,
                    employee_id: this.currentEmployeeId,
                    minutes_to_pay: minutesToPay,
                    start_date: startDate,
                    end_date: endDate
                },
                beforeSend: function() {
                    $('#btn-calculate-preview').text('Calculando...').prop('disabled', true);
                },
                success: function(response) {
                    $('#btn-calculate-preview').text('Calcular Prévia').prop('disabled', false);

                    if (response.success) {
                        self.displayPaymentPreview(response.data);
                    } else {
                        alert('Erro: ' + response.data.message);
                    }
                },
                error: function() {
                    $('#btn-calculate-preview').text('Calcular Prévia').prop('disabled', false);
                    alert('Erro ao calcular');
                }
            });
        },

        /**
         * Exibe prévia de pagamento
         */
        displayPaymentPreview: function(data) {
            const breakdown = data.breakdown;
            const resumo = data.resumo;

            $('#preview-util-hours').text((breakdown.horas_dia_util.minutos / 60).toFixed(2) + 'h');
            $('#preview-util-value').text('R$ ' + (breakdown.horas_dia_util.valor_total || 0).toFixed(2));

            $('#preview-weekend-hours').text((breakdown.horas_fim_semana.minutos / 60).toFixed(2) + 'h');
            $('#preview-weekend-value').text('R$ ' + (breakdown.horas_fim_semana.valor_total || 0).toFixed(2));

            $('#preview-holiday-hours').text((breakdown.horas_feriado.minutos / 60).toFixed(2) + 'h');
            $('#preview-holiday-value').text('R$ ' + (breakdown.horas_feriado.valor_total || 0).toFixed(2));

            $('#preview-total-hours').text(resumo.total_horas.toFixed(2) + 'h');
            $('#preview-total-value').text('R$ ' + resumo.valor_total.toFixed(2));

            if (resumo.minutos_nao_pagos > 0) {
                $('#preview-warning').remove();
                $('#payment-preview').append(
                    '<p id="preview-warning" style="color: orange; margin-top: 10px;">⚠ ' +
                    resumo.minutos_nao_pagos + ' minutos não puderam ser pagos (saldo insuficiente no período selecionado)</p>'
                );
            } else {
                $('#preview-warning').remove();
            }

            $('#payment-preview').show();
        },

        /**
         * Submete formulário de abatimento
         */
        submitDeduction: function(e) {
            e.preventDefault();

            const deductionType = $('#deduction-type').val();

            if (!deductionType) {
                alert('Selecione o tipo de compensação');
                return;
            }

            if (!this.currentEmployeeId) {
                alert('Selecione um funcionário');
                return;
            }

            const formData = $('#form-abater-banco-horas').serializeArray();

            // Se integral, usar saldo total
            const mode = $('input[name="deduction_mode"]:checked').val();
            if (mode === 'integral' && this.currentBalance) {
                formData.push({name: 'minutes_deducted', value: this.currentBalance.total_minutos});
            } else {
                const minutes = parseInt($('#minutes-input').val());
                if (!minutes || minutes <= 0) {
                    alert('Informe a quantidade de minutos');
                    return;
                }
                formData.push({name: 'minutes_deducted', value: minutes});
            }

            const actionName = deductionType === 'folga'
                ? 'sistur_deduct_timebank_folga'
                : 'sistur_deduct_timebank_payment';

            formData.push({name: 'action', value: actionName});
            formData.push({name: 'nonce', value: this.nonce});
            formData.push({name: 'employee_id', value: this.currentEmployeeId});

            const self = this;

            $.ajax({
                url: this.ajaxurl,
                type: 'POST',
                data: $.param(formData),
                beforeSend: function() {
                    $('#form-abater-banco-horas button[type="submit"]').text('Processando...').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        self.closeAbaterModal();
                        self.loadPendingDeductions();
                        // Recarregar tabela de funcionários se existir
                        if (typeof window.reloadEmployeesList === 'function') {
                            window.reloadEmployeesList();
                        }
                    } else {
                        alert('Erro: ' + response.data.message);
                        $('#form-abater-banco-horas button[type="submit"]').text('Registrar Abatimento').prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Erro ao processar solicitação');
                    $('#form-abater-banco-horas button[type="submit"]').text('Registrar Abatimento').prop('disabled', false);
                }
            });
        },

        /**
         * Carrega abatimentos pendentes
         */
        loadPendingDeductions: function() {
            const self = this;

            $.ajax({
                url: this.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sistur_get_pending_deductions',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.displayPendingDeductions(response.data.deductions);
                    }
                }
            });
        },

        /**
         * Exibe lista de abatimentos pendentes
         */
        displayPendingDeductions: function(deductions) {
            const $list = $('#pending-deductions-list');

            if (!$list.length) {
                return;
            }

            if (!deductions || deductions.length === 0) {
                $list.html('<tr><td colspan="6">Nenhum abatimento pendente</td></tr>');
                return;
            }

            let html = '';
            deductions.forEach(function(item) {
                const hours = (item.minutes_deducted / 60).toFixed(2);
                const typeLabel = item.deduction_type === 'folga' ? 'Folga' : 'Pagamento';
                const amount = item.payment_amount ? 'R$ ' + parseFloat(item.payment_amount).toFixed(2) : '-';

                html += '<tr>';
                html += '<td>' + item.employee_name + '</td>';
                html += '<td>' + typeLabel + '</td>';
                html += '<td>' + hours + 'h</td>';
                html += '<td>' + amount + '</td>';
                html += '<td>' + item.created_at + '</td>';
                html += '<td>';
                html += '<button class="button button-small btn-approve-deduction" data-id="' + item.id + '" data-action="aprovado">Aprovar</button> ';
                html += '<button class="button button-small btn-approve-deduction" data-id="' + item.id + '" data-action="rejeitado">Rejeitar</button>';
                html += '</td>';
                html += '</tr>';
            });

            $list.html(html);
        },

        /**
         * Abre modal de aprovação
         */
        openApprovalModal: function(e) {
            e.preventDefault();

            this.currentDeductionId = $(e.currentTarget).data('id');
            const action = $(e.currentTarget).data('action');

            $('#approval-deduction-id').val(this.currentDeductionId);
            $('#approval-status').val(action);

            const title = action === 'aprovado' ? 'Aprovar Abatimento' : 'Rejeitar Abatimento';
            $('#modal-aprovar-title').text(title);

            $('#modal-aprovar-abatimento').css('display', 'flex');
        },

        /**
         * Fecha modal de aprovação
         */
        closeApprovalModal: function() {
            $('#modal-aprovar-abatimento').hide();
            $('#form-aprovar-abatimento')[0].reset();
        },

        /**
         * Submete aprovação/rejeição
         */
        submitApproval: function(e) {
            e.preventDefault();

            const formData = $(e.currentTarget).serializeArray();
            formData.push({name: 'action', value: 'sistur_approve_deduction'});
            formData.push({name: 'nonce', value: this.nonce});

            const self = this;

            $.ajax({
                url: this.ajaxurl,
                type: 'POST',
                data: $.param(formData),
                beforeSend: function() {
                    $('#form-aprovar-abatimento button[type="submit"]').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        self.closeApprovalModal();
                        self.loadPendingDeductions();
                    } else {
                        alert('Erro: ' + response.data.message);
                    }
                    $('#form-aprovar-abatimento button[type="submit"]').prop('disabled', false);
                },
                error: function() {
                    alert('Erro ao processar');
                    $('#form-aprovar-abatimento button[type="submit"]').prop('disabled', false);
                }
            });
        },

        /**
         * Abre modal de feriados
         */
        openHolidaysModal: function(e) {
            e.preventDefault();
            this.loadHolidays();
            $('#modal-gerenciar-feriados').css('display', 'flex');
        },

        /**
         * Fecha modal de feriados
         */
        closeHolidaysModal: function() {
            $('#modal-gerenciar-feriados').hide();
        },

        /**
         * Carrega lista de feriados
         */
        loadHolidays: function() {
            const self = this;

            $.ajax({
                url: this.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sistur_get_holidays',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.displayHolidays(response.data.holidays);
                    }
                }
            });
        },

        /**
         * Exibe lista de feriados
         */
        displayHolidays: function(holidays) {
            const $list = $('#holidays-list');

            if (!holidays || holidays.length === 0) {
                $list.html('<tr><td colspan="6">Nenhum feriado cadastrado</td></tr>');
                return;
            }

            let html = '';
            holidays.forEach(function(h) {
                const typeLabels = {
                    'nacional': 'Nacional',
                    'estadual': 'Estadual',
                    'municipal': 'Municipal',
                    'ponto_facultativo': 'Ponto Facultativo'
                };

                html += '<tr>';
                html += '<td>' + h.holiday_date + '</td>';
                html += '<td>' + h.description + '</td>';
                html += '<td>' + typeLabels[h.holiday_type] + '</td>';
                html += '<td>' + parseFloat(h.multiplicador_adicional).toFixed(2) + 'x</td>';
                html += '<td>' + (h.status === 'active' ? 'Ativo' : 'Inativo') + '</td>';
                html += '<td>';
                html += '<button class="button button-small btn-edit-holiday" data-id="' + h.id + '">Editar</button> ';
                html += '<button class="button button-small btn-delete-holiday" data-id="' + h.id + '">Excluir</button>';
                html += '</td>';
                html += '</tr>';
            });

            $list.html(html);
        },

        /**
         * Abre formulário de feriado
         */
        openHolidayForm: function(e) {
            e.preventDefault();
            $('#form-holiday')[0].reset();
            $('#holiday-id').val('');
            $('#holiday-form-title').text('Adicionar Feriado');
            $('#modal-holiday-form').css('display', 'flex');
        },

        /**
         * Fecha formulário de feriado
         */
        closeHolidayForm: function() {
            $('#modal-holiday-form').hide();
        },

        /**
         * Edita feriado
         */
        editHoliday: function(e) {
            e.preventDefault();
            // Implementar carregamento dos dados e preenchimento do formulário
            alert('Funcionalidade de edição será implementada');
        },

        /**
         * Deleta feriado
         */
        deleteHoliday: function(e) {
            e.preventDefault();

            if (!confirm('Tem certeza que deseja excluir este feriado?')) {
                return;
            }

            const holidayId = $(e.currentTarget).data('id');
            const self = this;

            $.ajax({
                url: this.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sistur_delete_holiday',
                    nonce: this.nonce,
                    holiday_id: holidayId
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        self.loadHolidays();
                    } else {
                        alert('Erro: ' + response.data.message);
                    }
                }
            });
        },

        /**
         * Submete formulário de feriado
         */
        submitHoliday: function(e) {
            e.preventDefault();

            const formData = $(e.currentTarget).serializeArray();
            formData.push({name: 'action', value: 'sistur_save_holiday'});
            formData.push({name: 'nonce', value: this.nonce});

            const self = this;

            $.ajax({
                url: this.ajaxurl,
                type: 'POST',
                data: $.param(formData),
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        self.closeHolidayForm();
                        self.loadHolidays();
                    } else {
                        alert('Erro: ' + response.data.message);
                    }
                }
            });
        },

        /**
         * Abre modal de configuração de pagamento
         */
        openPaymentConfigModal: function(e) {
            e.preventDefault();

            const employeeId = $(e.currentTarget).data('employee-id');
            const employeeName = $(e.currentTarget).data('employee-name');

            $('#config-employee-id').val(employeeId);
            $('#config-employee-name').text(employeeName);

            this.loadPaymentConfig(employeeId);

            $('#modal-payment-config').css('display', 'flex');
        },

        /**
         * Fecha modal de configuração
         */
        closePaymentConfigModal: function() {
            $('#modal-payment-config').hide();
        },

        /**
         * Carrega configuração de pagamento
         */
        loadPaymentConfig: function(employeeId) {
            const self = this;

            $.ajax({
                url: this.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sistur_get_payment_config',
                    nonce: this.nonce,
                    employee_id: employeeId
                },
                success: function(response) {
                    if (response.success) {
                        self.fillPaymentConfigForm(response.data.config);
                    }
                }
            });
        },

        /**
         * Preenche formulário de configuração
         */
        fillPaymentConfigForm: function(config) {
            $('input[name="salario_base"]').val(config.salario_base);
            $('input[name="valor_hora_base"]').val(config.valor_hora_base);
            $('select[name="calculation_method"]').val(config.calculation_method);
            $('input[name="multiplicador_dia_util"]').val(config.multiplicador_dia_util);
            $('input[name="multiplicador_fim_semana"]').val(config.multiplicador_fim_semana);
            $('input[name="multiplicador_feriado"]').val(config.multiplicador_feriado);

            if (config.calculation_method === 'manual') {
                $('#manual-valor-hora').show();
            }
        },

        /**
         * Quando muda método de cálculo
         */
        onCalculationMethodChange: function(e) {
            const method = $(e.currentTarget).val();

            if (method === 'manual') {
                $('#manual-valor-hora').show();
            } else {
                $('#manual-valor-hora').hide();
            }
        },

        /**
         * Submete configuração de pagamento
         */
        submitPaymentConfig: function(e) {
            e.preventDefault();

            const formData = $(e.currentTarget).serializeArray();
            formData.push({name: 'action', value: 'sistur_save_payment_config'});
            formData.push({name: 'nonce', value: this.nonce});

            const self = this;

            $.ajax({
                url: this.ajaxurl,
                type: 'POST',
                data: $.param(formData),
                beforeSend: function() {
                    $('#form-payment-config button[type="submit"]').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        self.closePaymentConfigModal();
                    } else {
                        alert('Erro: ' + response.data.message);
                    }
                    $('#form-payment-config button[type="submit"]').prop('disabled', false);
                },
                error: function() {
                    alert('Erro ao salvar');
                    $('#form-payment-config button[type="submit"]').prop('disabled', false);
                }
            });
        }
    };

    // Inicializar quando o documento estiver pronto
    $(document).ready(function() {
        TimebankManager.init();
    });

    // Exportar para escopo global
    window.TimebankManager = TimebankManager;

})(jQuery);
