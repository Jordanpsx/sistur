/**
 * Módulo de RH - JavaScript
 * 
 * Gerencia navegação por abas SPA-style,
 * chamadas AJAX e interações do usuário.
 * 
 * @package SISTUR
 * @since 2.0.0
 */

(function ($) {
    'use strict';

    // ========================================
    // VARIÁVEIS GLOBAIS
    // ========================================
    const RH = {
        ajaxurl: sisturRH.ajaxurl,
        nonce: sisturRH.nonce,
        permissions: sisturRH.permissions,
        i18n: sisturRH.i18n,
        currentTab: 'dashboard',
        selectedEmployeeId: null,
        employeesCache: [],
        currentPage: 1
    };

    // ========================================
    // INICIALIZAÇÃO
    // ========================================
    $(document).ready(function () {
        initTabs();
        initEventListeners();
        loadInitialData();
    });

    // ========================================
    // TABS NAVIGATION
    // ========================================
    function initTabs() {
        $('.sistur-rh-tabs__tab').on('click', function () {
            const tab = $(this).data('tab');
            switchTab(tab);
        });

        // Verificar hash na URL
        const hash = window.location.hash.replace('#', '');
        if (hash && $(`.sistur-rh-panel[data-panel="${hash}"]`).length) {
            switchTab(hash);
        }
    }

    function switchTab(tabName) {
        // Atualizar tabs
        $('.sistur-rh-tabs__tab').removeClass('sistur-rh-tabs__tab--active');
        $(`.sistur-rh-tabs__tab[data-tab="${tabName}"]`).addClass('sistur-rh-tabs__tab--active');

        // Atualizar panels
        $('.sistur-rh-panel').removeClass('sistur-rh-panel--active');
        $(`.sistur-rh-panel[data-panel="${tabName}"]`).addClass('sistur-rh-panel--active');

        // Atualizar URL
        window.history.replaceState(null, null, `#${tabName}`);

        RH.currentTab = tabName;

        // Carregar dados da aba
        loadTabData(tabName);
    }

    function loadTabData(tabName) {
        switch (tabName) {
            case 'dashboard':
                loadDashboardStats();
                loadPunchesSpreadsheet();
                loadPunchAlerts();
                break;
            case 'colaboradores':
                loadEmployees();
                break;
            case 'banco-horas':
                loadEmployeesDropdown();
                loadAllTransactions();
                break;
        }
    }

    // ========================================
    // EVENT LISTENERS
    // ========================================
    function initEventListeners() {
        // Colaboradores
        $('#employee-search').on('input', debounce(loadEmployees, 300));
        $('#employee-status-filter').on('change', loadEmployees);

        // Banco de Horas
        $('#timebank-employee-select').on('change', onEmployeeSelect);
        $('#btn-liquidate').on('click', handleLiquidateHours);
        $('#btn-folga').on('click', handleGrantFolga);
        $('#transactions-type-filter').on('change', loadAllTransactions);

        // Modal
        $('#close-employee-modal, #cancel-employee-edit, .sistur-rh-modal__overlay').on('click', closeEmployeeModal);
        $('#save-employee-edit').on('click', handleSaveEmployee);

        // Delegated events para botões dinâmicos
        $(document).on('click', '.btn-edit-employee', function () {
            const employeeId = $(this).data('id');
            openEmployeeModal(employeeId);
        });

        $(document).on('click', '.btn-view-timebank', function () {
            const employeeId = $(this).data('id');
            switchTab('banco-horas');
            $('#timebank-employee-select').val(employeeId).trigger('change');
        });

        // Seletor de mês da planilha
        $('#punches-month-select').on('change', function () {
            loadPunchesSpreadsheet();
        });

        // Novo Funcionário
        $('#btn-new-employee').on('click', function (e) {
            e.preventDefault();
            openNewEmployeeModal();
        });

        // Auditoria: Carregar histórico de batidas
        $('#btn-load-punch-history').on('click', loadPunchHistory);
    }

    // ========================================
    // DASHBOARD
    // ========================================
    function loadDashboardStats() {
        $.ajax({
            url: RH.ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_rh_get_dashboard_stats',
                nonce: RH.nonce
            },
            success: function (response) {
                if (response.success) {
                    const data = response.data;
                    $('#stat-total-employees').text(data.total_employees);
                    $('#stat-incomplete-punches').text(data.incomplete_punches);
                    $('#stat-total-bank').text(data.total_bank_formatted);
                    $('#stat-pending').text(data.pending_deductions);
                }
            }
        });
    }

    function loadPunchAlerts() {
        $.ajax({
            url: RH.ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_rh_get_punch_alerts',
                nonce: RH.nonce
            },
            success: function (response) {
                const tbody = $('#punch-alerts-table tbody');
                tbody.empty();

                if (response.success && response.data.alerts.length > 0) {
                    response.data.alerts.forEach(function (alert) {
                        const statusClass = alert.punch_count % 2 !== 0 ? 'incomplete' : 'pending';
                        const statusText = alert.punch_count % 2 !== 0 ? 'Ímpar' : 'Incompleto';

                        tbody.append(`
                            <tr>
                                <td>${escapeHtml(alert.employee_name)}</td>
                                <td>${formatDate(alert.shift_date)}</td>
                                <td>${alert.punch_count} batida(s)</td>
                                <td><span class="sistur-rh-badge sistur-rh-badge--${statusClass}">${statusText}</span></td>
                            </tr>
                        `);
                    });
                } else {
                    tbody.append(`
                        <tr>
                            <td colspan="4" class="sistur-rh-table__empty">Nenhum alerta encontrado ✓</td>
                        </tr>
                    `);
                }
            },
            error: function () {
                $('#punch-alerts-table tbody').html(`
                    <tr><td colspan="4" class="sistur-rh-table__empty">Erro ao carregar</td></tr>
                `);
            }
        });
    }

    // ========================================
    // PLANILHA: BATIDAS POR FUNCIONÁRIO
    // ========================================
    function loadPunchesSpreadsheet() {
        const month = $('#punches-month-select').val() || '';

        $.ajax({
            url: RH.ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_rh_get_punches_spreadsheet',
                nonce: RH.nonce,
                month: month
            },
            beforeSend: function () {
                $('#punches-spreadsheet-body').html(`
                    <tr><td colspan="32" class="sistur-rh-table__loading">Carregando dados...</td></tr>
                `);
            },
            success: function (response) {
                if (response.success) {
                    renderPunchesSpreadsheet(response.data);
                } else {
                    $('#punches-spreadsheet-body').html(`
                        <tr><td colspan="32" class="sistur-rh-table__empty">Erro ao carregar dados</td></tr>
                    `);
                }
            },
            error: function () {
                $('#punches-spreadsheet-body').html(`
                    <tr><td colspan="32" class="sistur-rh-table__empty">Erro ao carregar planilha</td></tr>
                `);
            }
        });
    }

    function renderPunchesSpreadsheet(data) {
        const thead = $('#punches-spreadsheet-header');
        const tbody = $('#punches-spreadsheet-body');
        const daysInMonth = data.days_in_month;
        const weekends = data.weekends || [];

        // Renderizar cabeçalho
        let headerHtml = '<tr><th class="sistur-rh-spreadsheet__label">Funcionário / Dia</th>';
        for (let day = 1; day <= daysInMonth; day++) {
            const isWeekend = weekends.includes(day);
            const weekendClass = isWeekend ? ' sistur-rh-spreadsheet__weekend' : '';
            headerHtml += `<th class="sistur-rh-spreadsheet__cell${weekendClass}">${day}</th>`;
        }
        headerHtml += '</tr>';
        thead.html(headerHtml);

        // Renderizar corpo
        tbody.empty();

        if (data.employees.length === 0) {
            tbody.html(`<tr><td colspan="${daysInMonth + 1}" class="sistur-rh-table__empty">Nenhum funcionário encontrado</td></tr>`);
            return;
        }

        data.employees.forEach((employee, empIndex) => {
            // Linha 1: Nome do funcionário
            let nameRowHtml = `<tr class="sistur-rh-spreadsheet__employee-name">`;
            nameRowHtml += `<td class="sistur-rh-spreadsheet__label">${escapeHtml(employee.name)}</td>`;
            for (let day = 1; day <= daysInMonth; day++) {
                const isWeekend = weekends.includes(day);
                const weekendClass = isWeekend ? ' sistur-rh-spreadsheet__weekend' : '';
                nameRowHtml += `<td class="sistur-rh-spreadsheet__cell${weekendClass}"></td>`;
            }
            nameRowHtml += '</tr>';
            tbody.append(nameRowHtml);

            // Linhas de dados (batidas e futuras linhas adicionais)
            employee.rows.forEach(row => {
                let dataRowHtml = `<tr class="sistur-rh-spreadsheet__data-row">`;
                dataRowHtml += `<td class="sistur-rh-spreadsheet__label">${escapeHtml(row.label)}</td>`;

                for (let day = 1; day <= daysInMonth; day++) {
                    const value = row.values[day];
                    const isWeekend = weekends.includes(day);

                    let cellClass = 'sistur-rh-spreadsheet__cell';
                    if (isWeekend) cellClass += ' sistur-rh-spreadsheet__weekend';

                    if (value === null || value === undefined) {
                        cellClass += ' sistur-rh-spreadsheet__cell--empty';
                        dataRowHtml += `<td class="${cellClass}">-</td>`;
                    } else {
                        // Colorir baseado no número de batidas
                        if (value === 4) {
                            cellClass += ' sistur-rh-spreadsheet__cell--complete';
                        } else if (value % 2 !== 0) {
                            cellClass += ' sistur-rh-spreadsheet__cell--odd';
                        } else if (value > 0 && value < 4) {
                            cellClass += ' sistur-rh-spreadsheet__cell--incomplete';
                        }
                        dataRowHtml += `<td class="${cellClass}">${value}</td>`;
                    }
                }

                dataRowHtml += '</tr>';
                tbody.append(dataRowHtml);
            });

            // Separador entre funcionários (exceto o último)
            if (empIndex < data.employees.length - 1) {
                let sepHtml = `<tr class="sistur-rh-spreadsheet__separator"><td colspan="${daysInMonth + 1}"></td></tr>`;
                tbody.append(sepHtml);
            }
        });
    }

    // ========================================
    // COLABORADORES
    // ========================================
    function loadEmployees() {
        const search = $('#employee-search').val();
        const status = $('#employee-status-filter').val();

        $.ajax({
            url: RH.ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_rh_get_employees',
                nonce: RH.nonce,
                search: search,
                status: status,
                page: RH.currentPage
            },
            beforeSend: function () {
                $('#employees-table tbody').html(`
                    <tr><td colspan="6" class="sistur-rh-table__loading">Carregando...</td></tr>
                `);
            },
            success: function (response) {
                const tbody = $('#employees-table tbody');
                tbody.empty();

                if (response.success && response.data.employees.length > 0) {
                    RH.employeesCache = response.data.employees;

                    response.data.employees.forEach(function (emp) {
                        const canEdit = RH.permissions.edit_employees || RH.permissions.is_admin;
                        const canViewTimebank = RH.permissions.manage_timebank || RH.permissions.is_admin;

                        let actionsHtml = '';
                        if (canEdit) {
                            actionsHtml += `<button class="sistur-rh-btn sistur-rh-btn--sm sistur-rh-btn--secondary btn-edit-employee" data-id="${emp.id}" title="Editar">✏️</button>`;
                        }
                        if (canViewTimebank) {
                            actionsHtml += `<button class="sistur-rh-btn sistur-rh-btn--sm sistur-rh-btn--secondary btn-view-timebank" data-id="${emp.id}" title="Ver Banco">⏱️</button>`;
                        }

                        const statusBadge = emp.status == 1
                            ? '<span class="sistur-rh-badge sistur-rh-badge--success">✓ Ativo</span>'
                            : '<span class="sistur-rh-badge sistur-rh-badge--warning">⊘ Inativo</span>';

                        tbody.append(`
                            <tr>
                                <td>
                                    <strong>${escapeHtml(emp.name)}</strong>
                                    ${emp.email ? `<br><small class="text-muted">${escapeHtml(emp.email)}</small>` : ''}
                                </td>
                                <td>${escapeHtml(emp.position || '-')}</td>
                                <td>${escapeHtml(emp.department_name || '-')}</td>
                                <td>${statusBadge}</td>
                                <td>
                                    <span class="${parseInt(emp.bank_balance) >= 0 ? 'text-success' : 'text-danger'}">
                                        ${emp.bank_balance_formatted}
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group">${actionsHtml}</div>
                                </td>
                            </tr>
                        `);
                    });

                    // Paginação
                    renderPagination(response.data.pages, response.data.current_page);
                } else {
                    tbody.append(`
                        <tr>
                            <td colspan="6" class="sistur-rh-table__empty">Nenhum funcionário encontrado</td>
                        </tr>
                    `);
                }
            }
        });
    }

    function renderPagination(totalPages, currentPage) {
        const container = $('#employees-pagination');
        container.empty();

        if (totalPages <= 1) return;

        for (let i = 1; i <= totalPages; i++) {
            const btn = $(`<button>${i}</button>`);
            if (i === currentPage) btn.addClass('active');
            btn.on('click', function () {
                RH.currentPage = i;
                loadEmployees();
            });
            container.append(btn);
        }
    }

    // ========================================
    // EMPLOYEE MODAL
    // ========================================
    function openEmployeeModal(employeeId) {
        $.ajax({
            url: RH.ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_rh_get_employee',
                nonce: RH.nonce,
                employee_id: employeeId
            },
            success: function (response) {
                if (response.success) {
                    const emp = response.data;
                    console.log('📥 Dados do funcionário recebidos:', emp);

                    // Popular selects ANTES de setar valores
                    populateFormOptions();

                    $('#edit-employee-id').val(emp.id);
                    $('#edit-employee-name').val(emp.name);
                    $('#edit-employee-email').val(emp.email);
                    $('#edit-employee-phone').val(emp.phone);
                    $('#edit-employee-cpf').val(emp.cpf);
                    $('#edit-employee-matricula').val(emp.matricula);
                    $('#edit-employee-ctps').val(emp.ctps);
                    $('#edit-employee-ctps-uf').val(emp.ctps_uf);
                    $('#edit-employee-cbo').val(emp.cbo);
                    $('#edit-employee-position').val(emp.position);
                    $('#edit-employee-status').prop('checked', emp.status == 1);
                    $('#edit-employee-hire-date').val(emp.hire_date);

                    $('#edit-employee-department').val(emp.department_id);
                    $('#edit-employee-role').val(emp.role_id);
                    $('#edit-employee-shift-pattern').val(emp.shift_pattern_id);

                    // Senha opcional na edição
                    $('#password-required').hide();
                    $('#password-hint').text('Deixe em branco para manter a senha atual');
                    $('#edit-employee-password').prop('required', false);

                    $('#employee-modal .sistur-rh-modal__header h3').text('Editar Funcionário');
                    $('#employee-modal').css('display', 'flex');
                } else {
                    showToast('Erro ao carregar funcionário', 'error');
                }
            }
        });
    }

    function closeEmployeeModal() {
        $('#employee-modal').fadeOut(200);
    }

    function openNewEmployeeModal() {
        $('#employee-edit-form')[0].reset();
        $('#edit-employee-id').val('0'); // 0 = Criar novo
        $('#employee-modal .sistur-rh-modal__header h3').text('Novo Funcionário');

        populateFormOptions();

        // Padrão: Ativo
        $('#edit-employee-status').prop('checked', true);

        // Senha obrigatória para novo
        $('#password-required').show();
        $('#password-hint').text('Senha usada para login no painel do funcionário');
        $('#edit-employee-password').prop('required', true);

        $('#employee-modal').css('display', 'flex');
    }

    function populateFormOptions() {
        const deptSelect = $('#edit-employee-department');
        const roleSelect = $('#edit-employee-role');
        const shiftSelect = $('#edit-employee-shift-pattern');

        // Sempre repopular para garantir valores corretos
        deptSelect.html('<option value="">Selecione...</option>');
        if (sisturRH.departments) {
            sisturRH.departments.forEach(function (d) {
                deptSelect.append(`<option value="${d.id}">${escapeHtml(d.name)}</option>`);
            });
        }

        roleSelect.html('<option value="">Selecione...</option>');
        if (sisturRH.roles) {
            sisturRH.roles.forEach(function (r) {
                roleSelect.append(`<option value="${r.id}">${escapeHtml(r.name)}</option>`);
            });
        }

        shiftSelect.html('<option value="">Selecione...</option>');
        if (sisturRH.shift_patterns) {
            sisturRH.shift_patterns.forEach(function (s) {
                shiftSelect.append(`<option value="${s.id}">${escapeHtml(s.name)}</option>`);
            });
        }
    }

    function handleSaveEmployee(e) {
        const employeeId = $('#edit-employee-id').val();
        const data = {
            action: 'sistur_rh_save_employee',
            nonce: RH.nonce,
            employee_id: employeeId,
            name: $('#edit-employee-name').val(),
            email: $('#edit-employee-email').val(),
            phone: $('#edit-employee-phone').val(),
            cpf: $('#edit-employee-cpf').val(),
            matricula: $('#edit-employee-matricula').val(),
            ctps: $('#edit-employee-ctps').val(),
            ctps_uf: $('#edit-employee-ctps-uf').val(),
            cbo: $('#edit-employee-cbo').val(),
            position: $('#edit-employee-position').val(),
            department_id: $('#edit-employee-department').val(),
            role_id: $('#edit-employee-role').val(),
            hire_date: $('#edit-employee-hire-date').val(),
            shift_pattern_id: $('#edit-employee-shift-pattern').val(),
            status: $('#edit-employee-status').is(':checked') ? 1 : 0
        };

        // Só enviar senha se preenchida
        const password = $('#edit-employee-password').val();
        if (password) {
            data.password = password;
        }

        console.log('💾 Salvando funcionário:', data);

        $.ajax({
            url: RH.ajaxurl,
            type: 'POST',
            data: data,
            beforeSend: function () {
                $('#save-employee-edit').prop('disabled', true).text('Salvando...');
            },
            success: function (response) {
                if (response.success) {
                    showToast(response.data.message, 'success');
                    closeEmployeeModal();
                    loadEmployees();
                } else {
                    showToast(response.data.message, 'error');
                }
            },
            complete: function () {
                $('#save-employee-edit').prop('disabled', false).text('Salvar');
            }
        });
    }

    // ========================================
    // BANCO DE HORAS
    // ========================================
    function loadEmployeesDropdown() {
        $.ajax({
            url: RH.ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_rh_get_employees',
                nonce: RH.nonce,
                status: 'active',
                per_page: 500
            },
            success: function (response) {
                const select = $('#timebank-employee-select');
                select.find('option:not(:first)').remove();

                if (response.success && response.data.employees) {
                    response.data.employees.forEach(function (emp) {
                        select.append(`<option value="${emp.id}">${escapeHtml(emp.name)} (${emp.bank_balance_formatted})</option>`);
                    });
                }
            }
        });
    }

    function onEmployeeSelect() {
        const employeeId = $(this).val();

        if (!employeeId) {
            $('#timebank-details').hide();
            return;
        }

        RH.selectedEmployeeId = employeeId;
        loadTimebankData(employeeId);
        loadEmployeeTransactions(employeeId);
        loadAuditSummary(employeeId);

        // Setar mês atual no picker de histórico
        const now = new Date();
        const monthStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
        $('#audit-punch-month').val(monthStr);
    }

    function loadTimebankData(employeeId) {
        $.ajax({
            url: RH.ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_rh_get_timebank_data',
                nonce: RH.nonce,
                employee_id: employeeId
            },
            success: function (response) {
                if (response.success) {
                    const data = response.data;

                    $('#timebank-employee-name').text(data.employee.name);
                    $('#timebank-balance').text(data.balance_formatted);
                    $('#timebank-details').show();

                    // Limpar campos
                    $('#liquidate-hours, #liquidate-notes').val('');
                    $('#folga-hours, #folga-start-date, #folga-end-date, #folga-description').val('');
                }
            }
        });
    }

    function loadAuditSummary(employeeId) {
        console.log('[AUDIT] Carregando resumo para funcionário:', employeeId);
        const restBase = sisturRH.restBase || '/wp-json/sistur/v1/time-bank/';
        console.log('[AUDIT] Base URL:', restBase);
        console.log('[AUDIT] Nonce:', sisturRH.restNonce);

        // Carregar resumo semanal
        $.ajax({
            url: restBase + employeeId + '/weekly',
            type: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', sisturRH.restNonce);
            }
        })
            .done(function (response) {
                console.log('[AUDIT] Resumo semanal resposta:', response);
                if (response && response.summary) {
                    $('#audit-week-worked').text(response.summary.total_worked_formatted || '--');
                    $('#audit-week-deviation').text('Desvio: ' + (response.summary.week_deviation_formatted || '--'));
                    $('#audit-accumulated').text(response.summary.accumulated_bank_formatted || '--');
                } else {
                    console.warn('[AUDIT] Resposta semanal sem summary:', response);
                }
            })
            .fail(function (xhr, status, error) {
                console.error('[AUDIT] Erro resumo semanal:', { status, error, responseText: xhr.responseText });
                $('#audit-week-worked, #audit-accumulated').text('--');
                $('#audit-week-deviation').text('Erro ao carregar');
            });

        // Carregar resumo mensal
        $.ajax({
            url: restBase + employeeId + '/monthly',
            type: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', sisturRH.restNonce);
            }
        })
            .done(function (response) {
                console.log('[AUDIT] Resumo mensal resposta:', response);
                if (response && response.summary) {
                    $('#audit-month-worked').text(response.summary.total_worked_formatted || '--');
                    $('#audit-month-deviation').text('Desvio: ' + (response.summary.total_deviation_formatted || '--'));
                } else {
                    console.warn('[AUDIT] Resposta mensal sem summary:', response);
                }
            })
            .fail(function (xhr, status, error) {
                console.error('[AUDIT] Erro resumo mensal:', { status, error, responseText: xhr.responseText });
                $('#audit-month-worked').text('--');
                $('#audit-month-deviation').text('Erro ao carregar');
            });
    }

    function loadPunchHistory() {
        const employeeId = RH.selectedEmployeeId;
        const monthVal = $('#audit-punch-month').val();

        console.log('[AUDIT] Carregando histórico batidas:', { employeeId, monthVal });

        if (!employeeId || !monthVal) {
            showToast('Selecione um funcionário e um mês', 'warning');
            return;
        }

        const [year, month] = monthVal.split('-');
        const startDate = `${year}-${month}-01`;
        const lastDay = new Date(year, month, 0).getDate();
        const endDate = `${year}-${month}-${lastDay}`;

        console.log('[AUDIT] Período:', { startDate, endDate });

        const tbody = $('#audit-punch-table tbody');
        tbody.html('<tr><td colspan="7" class="sistur-rh-table__loading">Carregando...</td></tr>');

        $.ajax({
            url: RH.ajaxurl,
            type: 'GET',
            data: {
                action: 'sistur_time_get_sheet',
                nonce: sisturRH.timeTrackingNonce,
                employee_id: employeeId,
                start_date: startDate,
                end_date: endDate
            },
            success: function (response) {
                console.log('[AUDIT] Resposta histórico:', response);
                if (response.success && response.data.sheet) {
                    renderPunchHistoryTable(response.data.sheet);
                } else {
                    console.warn('[AUDIT] Histórico sem dados ou erro:', response);
                    tbody.html('<tr><td colspan="7" class="sistur-rh-table__empty">Nenhum registro encontrado</td></tr>');
                }
            },
            error: function (xhr, status, error) {
                console.error('[AUDIT] Erro histórico:', { status, error, responseText: xhr.responseText });
                tbody.html('<tr><td colspan="7" class="sistur-rh-table__empty">Erro ao carregar histórico</td></tr>');
            }
        });
    }

    function renderPunchHistoryTable(sheet) {
        console.log('[AUDIT] Renderizando tabela com', sheet.length, 'registros');
        const tbody = $('#audit-punch-table tbody');
        tbody.empty();

        if (!sheet || sheet.length === 0) {
            tbody.html('<tr><td colspan="7" class="sistur-rh-table__empty">Nenhum registro encontrado</td></tr>');
            return;
        }

        const expectedDailyMinutes = 480; // 8h padrão (pode ser ajustado)

        sheet.forEach(function (day) {
            const clockIn = findPunch(day.entries, 'clock_in') || findPunch(day.entries, 'entry');
            const lunchStart = findPunch(day.entries, 'lunch_start');
            const lunchEnd = findPunch(day.entries, 'lunch_end');
            const clockOut = findPunch(day.entries, 'clock_out') || findPunch(day.entries, 'exit');

            // Calcular horas trabalhadas
            let workedMinutes = 0;
            if (clockIn && clockOut) {
                workedMinutes = calculateWorkedMinutes(clockIn, lunchStart, lunchEnd, clockOut);
            }

            const worked = workedMinutes > 0 ? formatMinutes(workedMinutes) : '--';
            const balanceMinutes = workedMinutes > 0 ? workedMinutes - expectedDailyMinutes : 0;
            const balance = workedMinutes > 0 ? formatBalance(balanceMinutes) : '--';

            console.log(`[AUDIT] Dia ${day.date}: workedMinutes=${workedMinutes}, balanceMinutes=${balanceMinutes}`);

            const dateFormatted = formatDate(day.date);
            const rowClass = (!clockIn || !clockOut) ? 'sistur-rh-row--warning' : '';

            tbody.append(`
                <tr class="${rowClass}">
                    <td>${dateFormatted}</td>
                    <td>${clockIn || '-'}</td>
                    <td>${lunchStart || '-'}</td>
                    <td>${lunchEnd || '-'}</td>
                    <td>${clockOut || '-'}</td>
                    <td>${worked}</td>
                    <td class="${balanceMinutes >= 0 ? 'text-success' : 'text-danger'}">${balance}</td>
                </tr>
            `);
        });
    }

    function findPunch(entries, type) {
        if (!entries || !Array.isArray(entries)) return null;
        const entry = entries.find(e => e.punch_type === type);
        if (!entry) return null;

        // Se já for apenas o horário (HH:mm:ss)
        if (entry.punch_time.length <= 8) return entry.punch_time.substring(0, 5);

        // Se for datetime completo
        const parts = entry.punch_time.split(' ');
        return parts.length > 1 ? parts[1].substring(0, 5) : entry.punch_time.substring(0, 5);
    }

    function calculateWorkedMinutes(clockIn, lunchStart, lunchEnd, clockOut) {
        const toMinutes = (time) => {
            if (!time) return 0;
            const [h, m] = time.split(':').map(Number);
            return h * 60 + m;
        };

        const inMin = toMinutes(clockIn);
        const outMin = toMinutes(clockOut);

        // Total bruto
        let total = outMin - inMin;

        // Subtrair almoço se tiver
        if (lunchStart && lunchEnd) {
            const lunchStartMin = toMinutes(lunchStart);
            const lunchEndMin = toMinutes(lunchEnd);

            const lunchDuration = lunchEndMin - lunchStartMin;
            // console.log('[AUDIT] Calculando almoço:', {lunchStart, lunchEnd, lunchStartMin, lunchEndMin, lunchDuration});

            total -= lunchDuration;
        }

        // console.log('[AUDIT] Calculando dia:', {clockIn, clockOut, total});

        return Math.max(0, total);
    }

    function formatMinutes(minutes) {
        const h = Math.floor(Math.abs(minutes) / 60);
        const m = Math.abs(minutes) % 60;
        return `${h}h ${m.toString().padStart(2, '0')}min`;
    }

    function formatBalance(minutes) {
        const sign = minutes >= 0 ? '+' : '-';
        const h = Math.floor(Math.abs(minutes) / 60);
        const m = Math.abs(minutes) % 60;
        return `${sign}${h}h ${m.toString().padStart(2, '0')}min`;
    }

    function loadEmployeeTransactions(employeeId) {
        $.ajax({
            url: RH.ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_rh_get_transactions',
                nonce: RH.nonce,
                employee_id: employeeId,
                limit: 20
            },
            success: function (response) {
                renderTransactionsTable(response.data.transactions, '#transactions-table tbody');
            }
        });
    }

    function loadAllTransactions() {
        const type = $('#transactions-type-filter').val();

        $.ajax({
            url: RH.ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_rh_get_transactions',
                nonce: RH.nonce,
                type: type,
                limit: 50
            },
            success: function (response) {
                const tbody = $('#all-transactions-table tbody');
                tbody.empty();

                if (response.success && response.data.transactions.length > 0) {
                    response.data.transactions.forEach(function (t) {
                        tbody.append(`
                            <tr>
                                <td>${escapeHtml(t.employee_name)}</td>
                                <td>${formatDateTime(t.created_at)}</td>
                                <td><span class="sistur-rh-badge sistur-rh-badge--${t.deduction_type}">${t.deduction_type === 'pagamento' ? '💰 Pagamento' : '🏖️ Folga'}</span></td>
                                <td>${t.minutes_formatted}</td>
                                <td><span class="sistur-rh-badge sistur-rh-badge--${t.approval_status}">${t.approval_status}</span></td>
                            </tr>
                        `);
                    });
                } else {
                    tbody.append(`
                        <tr>
                            <td colspan="5" class="sistur-rh-table__empty">Nenhuma transação encontrada</td>
                        </tr>
                    `);
                }
            }
        });
    }

    function renderTransactionsTable(transactions, selector) {
        const tbody = $(selector);
        tbody.empty();

        if (transactions && transactions.length > 0) {
            transactions.forEach(function (t) {
                tbody.append(`
                    <tr>
                        <td>${formatDateTime(t.created_at)}</td>
                        <td><span class="sistur-rh-badge sistur-rh-badge--${t.deduction_type}">${t.deduction_type === 'pagamento' ? '💰 Pagamento' : '🏖️ Folga'}</span></td>
                        <td>${t.minutes_formatted}</td>
                        <td>${t.balance_before_formatted}</td>
                        <td>${t.balance_after_formatted}</td>
                        <td>${escapeHtml(t.approved_by_name || '-')}</td>
                    </tr>
                `);
            });
        } else {
            tbody.append(`
                <tr>
                    <td colspan="6" class="sistur-rh-table__empty">Sem transações</td>
                </tr>
            `);
        }
    }

    // ========================================
    // AÇÕES: LIQUIDAR / FOLGA
    // ========================================
    function handleLiquidateHours() {
        const hours = parseFloat($('#liquidate-hours').val());
        const notes = $('#liquidate-notes').val();

        if (!hours || hours <= 0) {
            showToast('Informe a quantidade de horas', 'warning');
            return;
        }

        if (!confirm(RH.i18n.confirm_liquidate)) return;

        const minutes = Math.round(hours * 60);

        $.ajax({
            url: RH.ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_rh_liquidate_hours',
                nonce: RH.nonce,
                employee_id: RH.selectedEmployeeId,
                minutes: minutes,
                notes: notes
            },
            beforeSend: function () {
                $('#btn-liquidate').prop('disabled', true).text('Processando...');
            },
            success: function (response) {
                if (response.success) {
                    showToast(response.data.message, 'success');
                    $('#timebank-balance').text(response.data.new_balance_formatted);
                    $('#liquidate-hours, #liquidate-notes').val('');
                    loadEmployeeTransactions(RH.selectedEmployeeId);
                    loadAllTransactions();
                    loadEmployeesDropdown();
                } else {
                    showToast(response.data.message, 'error');
                }
            },
            complete: function () {
                $('#btn-liquidate').prop('disabled', false).text('Liquidar Horas');
            }
        });
    }

    function handleGrantFolga() {
        const hours = parseFloat($('#folga-hours').val());
        const startDate = $('#folga-start-date').val();
        const endDate = $('#folga-end-date').val() || startDate;
        const description = $('#folga-description').val();

        if (!hours || hours <= 0) {
            showToast('Informe a quantidade de horas', 'warning');
            return;
        }

        if (!startDate) {
            showToast('Informe a data de início', 'warning');
            return;
        }

        if (!confirm(RH.i18n.confirm_folga)) return;

        const minutes = Math.round(hours * 60);

        $.ajax({
            url: RH.ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_rh_grant_folga',
                nonce: RH.nonce,
                employee_id: RH.selectedEmployeeId,
                minutes: minutes,
                start_date: startDate,
                end_date: endDate,
                description: description
            },
            beforeSend: function () {
                $('#btn-folga').prop('disabled', true).text('Processando...');
            },
            success: function (response) {
                if (response.success) {
                    showToast(response.data.message, 'success');
                    $('#timebank-balance').text(response.data.new_balance_formatted);
                    $('#folga-hours, #folga-start-date, #folga-end-date, #folga-description').val('');
                    loadEmployeeTransactions(RH.selectedEmployeeId);
                    loadAllTransactions();
                    loadEmployeesDropdown();
                } else {
                    showToast(response.data.message, 'error');
                }
            },
            complete: function () {
                $('#btn-folga').prop('disabled', false).text('Lançar Folga');
            }
        });
    }

    // ========================================
    // LOAD INITIAL DATA
    // ========================================
    function loadInitialData() {
        // Carregar dados da aba ativa
        const activePanel = $('.sistur-rh-panel--active').data('panel');
        if (activePanel) {
            loadTabData(activePanel);
        }
    }

    // ========================================
    // HELPERS
    // ========================================
    function showToast(message, type = 'info') {
        const toast = $(`<div class="sistur-rh-toast__item sistur-rh-toast__item--${type}">${escapeHtml(message)}</div>`);
        $('#toast-container').append(toast);

        setTimeout(function () {
            toast.fadeOut(300, function () {
                $(this).remove();
            });
        }, 4000);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr + 'T00:00:00');
        return date.toLocaleDateString('pt-BR');
    }

    function formatDateTime(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleDateString('pt-BR') + ' ' + date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }

    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

})(jQuery);
