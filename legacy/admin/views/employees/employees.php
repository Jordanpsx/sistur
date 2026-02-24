<?php
/**
 * Página de Gerenciamento de Funcionários
 *
 * @package SISTUR
 */

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}

// Obter parâmetros de busca e paginação
$query_params = SISTUR_Pagination::get_query_params();

// Preparar argumentos para busca
$search_args = array(
    'status' => 1,
    'search' => $query_params['search'],
    'orderby' => $query_params['orderby'],
    'order' => $query_params['order']
);

// Filtro por departamento
if (isset($_GET['department_id']) && $_GET['department_id'] !== '') {
    $search_args['department_id'] = intval($_GET['department_id']);
}

// Contar total de funcionários
$total_items = SISTUR_Employees::count_employees($search_args);

// Calcular paginação
$pagination = SISTUR_Pagination::calculate(
    $total_items,
    $query_params['per_page'],
    $query_params['page']
);

// Buscar funcionários com paginação
$search_args['limit'] = $pagination['per_page'];
$search_args['offset'] = $pagination['offset'];
$employees = SISTUR_Employees::get_all_employees($search_args);

// Obter departamentos e roles
$departments = SISTUR_Employees::get_all_departments(1);
$permissions = SISTUR_Permissions::get_instance();
$roles = $permissions->get_all_roles();

// Processar export se solicitado
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Buscar todos os funcionários (sem paginação) para export
    $export_args = $search_args;
    unset($export_args['limit']);
    unset($export_args['offset']);
    $all_employees = SISTUR_Employees::get_all_employees($export_args);

    // Preparar dados para export
    $export_data = SISTUR_Export::prepare_employees_export($all_employees, $departments);

    // Gerar e enviar arquivo CSV
    SISTUR_Export::to_csv($export_data, 'funcionarios.csv', array(
        'Nome', 'Email', 'Telefone', 'CPF', 'Cargo', 'Departamento',
        'Data de Contratação', 'Status'
    ));

    // Registrar log de auditoria
    $audit = SISTUR_Audit::get_instance();
    $audit->log_export('employees', array(
        'count' => count($all_employees),
        'format' => 'csv',
        'filters' => $search_args
    ), get_current_user_id());

    exit;
}
?>

<div class="wrap">
    <h1>
        <?php _e('Funcionários', 'sistur'); ?>
        <button class="button button-primary" id="sistur-add-employee">
            <?php _e('Adicionar Funcionário', 'sistur'); ?>
        </button>
        <button class="button" id="sistur-export-employees" style="margin-left: 8px;">
            <span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
            <?php _e('Exportar CSV', 'sistur'); ?>
        </button>
    </h1>

    <!-- Formulário de Busca e Filtros -->
    <?php
    // Preparar opções de departamentos para o filtro
    $dept_options = array();
    foreach ($departments as $dept) {
        $dept_options[$dept['id']] = $dept['name'];
    }

    echo SISTUR_Search::render_search_form(array(
        'placeholder' => 'Buscar por nome, email ou CPF...',
        'show_per_page' => true,
        'show_filters' => true,
        'filters' => array(
            array(
                'name' => 'department_id',
                'label' => 'Filtrar por Departamento',
                'options' => $dept_options
            )
        )
    ));
    ?>

    <div class="sistur-employees-container" style="background: white; padding: 20px; margin-top: 20px; border-radius: 4px;">
        <?php if (!empty($employees)) : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Foto', 'sistur'); ?></th>
                        <th><?php _e('Nome', 'sistur'); ?></th>
                        <th><?php _e('Email', 'sistur'); ?></th>
                        <th><?php _e('Telefone', 'sistur'); ?></th>
                        <th><?php _e('Cargo', 'sistur'); ?></th>
                        <th><?php _e('CPF', 'sistur'); ?></th>
                        <th><?php _e('Ações', 'sistur'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $employee) : ?>
                        <tr>
                            <td>
                                <?php if ($employee['photo']) : ?>
                                    <img src="<?php echo esc_url($employee['photo']); ?>" alt="" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;" />
                                <?php else : ?>
                                    <div style="width: 40px; height: 40px; border-radius: 50%; background: #f0f0f1; display: flex; align-items: center; justify-content: center;">
                                        <span class="dashicons dashicons-admin-users"></span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo esc_html($employee['name']); ?></strong></td>
                            <td><?php echo esc_html($employee['email']); ?></td>
                            <td><?php echo esc_html($employee['phone']); ?></td>
                            <td><?php echo esc_html($employee['position']); ?></td>
                            <td><?php echo esc_html($employee['cpf']); ?></td>
                            <td>
                                <button class="button button-small sistur-edit-employee" data-id="<?php echo $employee['id']; ?>">
                                    <?php _e('Editar', 'sistur'); ?>
                                </button>
                                <button class="button button-small sistur-delete-employee" data-id="<?php echo $employee['id']; ?>">
                                    <?php _e('Excluir', 'sistur'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Paginação -->
            <div id="sistur-pagination-container">
                <?php echo SISTUR_Pagination::render($pagination); ?>
            </div>

        <?php else : ?>
            <p><?php _e('Nenhum funcionário cadastrado ainda.', 'sistur'); ?></p>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de Edição/Criação -->
<div id="sistur-employee-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 8px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
        <h2 id="modal-title"><?php _e('Adicionar Funcionário', 'sistur'); ?></h2>
        <form id="sistur-employee-form">
            <input type="hidden" id="employee-id" name="id" value="0" />

            <p>
                <label><?php _e('Nome *', 'sistur'); ?></label>
                <input type="text" name="name" id="employee-name" required style="width: 100%;" />
            </p>

            <p>
                <label><?php _e('Email', 'sistur'); ?></label>
                <input type="email" name="email" id="employee-email" style="width: 100%;" />
            </p>

            <p>
                <label><?php _e('Telefone', 'sistur'); ?></label>
                <input type="text" name="phone" id="employee-phone" style="width: 100%;" />
            </p>

            <p>
                <label><?php _e('CPF', 'sistur'); ?></label>
                <input type="text" name="cpf" id="employee-cpf" placeholder="000.000.000-00" style="width: 100%;" />
            </p>

            <p>
                <label><?php _e('Matrícula', 'sistur'); ?></label>
                <input type="text" name="matricula" id="employee-matricula" placeholder="Número da matrícula" style="width: 100%;" />
                <small style="display: block; margin-top: 5px; color: #666;">
                    <?php _e('Número de identificação interno do funcionário', 'sistur'); ?>
                </small>
            </p>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 10px;">
                <p>
                    <label><?php _e('CTPS', 'sistur'); ?></label>
                    <input type="text" name="ctps" id="employee-ctps" placeholder="Número da CTPS" style="width: 100%;" />
                </p>

                <p>
                    <label><?php _e('UF', 'sistur'); ?></label>
                    <input type="text" name="ctps_uf" id="employee-ctps-uf" placeholder="UF" maxlength="2" style="width: 100%; text-transform: uppercase;" />
                </p>
            </div>

            <p>
                <label><?php _e('CBO', 'sistur'); ?></label>
                <input type="text" name="cbo" id="employee-cbo" placeholder="Código CBO" style="width: 100%;" />
                <small style="display: block; margin-top: 5px; color: #666;">
                    <?php _e('Classificação Brasileira de Ocupações', 'sistur'); ?>
                </small>
            </p>

            <p>
                <label><?php _e('Senha de Acesso *', 'sistur'); ?> <span id="password-edit-note" style="display:none; color: #666; font-size: 12px;">(deixe em branco para manter a senha atual)</span></label>
                <input type="password" name="password" id="employee-password" placeholder="Digite a senha para acesso ao painel" style="width: 100%;" required />
                <small style="display: block; margin-top: 5px; color: #666;">
                    <?php _e('Senha usada para login no painel do funcionário (obrigatória ao criar novo funcionário)', 'sistur'); ?>
                </small>
            </p>

            <p>
                <label><?php _e('Cargo', 'sistur'); ?></label>
                <input type="text" name="position" id="employee-position" style="width: 100%;" />
            </p>

            <p>
                <label><?php _e('Departamento', 'sistur'); ?></label>
                <select name="department_id" id="employee-department" style="width: 100%;">
                    <option value=""><?php _e('Selecione...', 'sistur'); ?></option>
                    <?php foreach ($departments as $dept) : ?>
                        <option value="<?php echo $dept['id']; ?>"><?php echo esc_html($dept['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label><?php _e('Papel/Função', 'sistur'); ?></label>
                <select name="role_id" id="employee-role" style="width: 100%;">
                    <option value=""><?php _e('Selecione um papel...', 'sistur'); ?></option>
                    <?php foreach ($roles as $role) : ?>
                        <option value="<?php echo $role['id']; ?>" title="<?php echo esc_attr($role['description']); ?>">
                            <?php echo esc_html($role['name']); ?>
                            <?php if ($role['is_admin']) echo ' (Admin)'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="display: block; margin-top: 5px; color: #666;">
                    <?php _e('Define as permissões de acesso ao sistema', 'sistur'); ?>
                </small>
            </p>

            <p>
                <label><?php _e('Data de Contratação', 'sistur'); ?></label>
                <input type="date" name="hire_date" id="employee-hire-date" style="width: 100%;" />
            </p>

            <p>
                <label><?php _e('Escala de Trabalho *', 'sistur'); ?> <span style="color: red;">*</span></label>
                <select name="shift_pattern_id" id="employee-shift-pattern" style="width: 100%;" required>
                    <option value=""><?php _e('Selecione uma escala...', 'sistur'); ?></option>
                </select>
                <small style="display: block; margin-top: 5px; color: #666;">
                    <?php _e('Define o padrão de jornada e expectativa de horas (obrigatório)', 'sistur'); ?>
                </small>
                <button type="button" class="button button-small" id="btn-manage-employee-schedule" style="margin-top: 5px; display: none;">
                    <?php _e('Gerenciar Escalas', 'sistur'); ?>
                </button>
            </p>

            <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 20px;">
                <h4 style="margin: 0 0 10px 0;">Configuração da Jornada (Opcional)</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <p style="margin: 0;">
                        <label><?php _e('Entrada (HH:MM)', 'sistur'); ?></label>
                        <input type="time" name="shift_start_time" id="employee-shift-start" style="width: 100%;" />
                    </p>
                    <p style="margin: 0;">
                        <label><?php _e('Saída (HH:MM)', 'sistur'); ?></label>
                        <input type="time" name="shift_end_time" id="employee-shift-end" style="width: 100%;" />
                    </p>
                    <p style="margin: 0;">
                        <label><?php _e('Almoço (min)', 'sistur'); ?></label>
                        <input type="number" name="lunch_duration_minutes" id="employee-lunch-duration" placeholder="Ex: 60" style="width: 100%;" />
                    </p>
                    <hr style="grid-column: 1 / -1; margin: 10px 0; border: 0; border-top: 1px solid #eee;">
                    
                    <div style="grid-column: 1 / -1;">
                        <label style="display:block; margin-bottom:5px; font-weight:600;"><?php _e('Configuração Semanal Detalhada', 'sistur'); ?></label>
                        <p class="description" style="margin-top:0; margin-bottom: 10px; font-size:12px; color:#666;">
                            Marque os dias trabalhados e defina as horas para cada dia.
                        </p>
                        <table class="weekly-days-config" style="width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #ddd;">
                            <thead>
                                <tr style="background: #f5f5f5;">
                                    <th style="padding: 8px; text-align: left; border-bottom: 1px solid #ddd;">Dia</th>
                                    <th style="padding: 8px; text-align: center; border-bottom: 1px solid #ddd;">Trabalha?</th>
                                    <th style="padding: 8px; text-align: center; border-bottom: 1px solid #ddd;">Horas (min)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $days = [
                                    'Monday' => 'Segunda-feira', 'Tuesday' => 'Terça-feira', 'Wednesday' => 'Quarta-feira',
                                    'Thursday' => 'Quinta-feira', 'Friday' => 'Sexta-feira', 'Saturday' => 'Sábado', 'Sunday' => 'Domingo'
                                ];
                                foreach ($days as $key => $label): 
                                    $default_hours = ($key === 'Saturday' || $key === 'Sunday') ? 0 : 480;
                                    $is_weekend = ($key === 'Saturday' || $key === 'Sunday');
                                ?>
                                <tr>
                                    <td style="padding: 6px; border-bottom: 1px solid #eee;"><?php echo $label; ?></td>
                                    <td style="padding: 6px; text-align: center; border-bottom: 1px solid #eee;">
                                        <input type="checkbox" class="day-work-check" data-day="<?php echo $key; ?>" <?php echo !$is_weekend ? 'checked' : ''; ?>>
                                    </td>
                                    <td style="padding: 6px; text-align: center; border-bottom: 1px solid #eee;">
                                        <input type="number" class="day-hours" data-day="<?php echo $key; ?>" value="<?php echo $default_hours; ?>" min="0" max="720" style="width: 80px;" <?php echo $is_weekend ? 'disabled' : ''; ?>>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="description" style="margin-top: 10px;">
                            <strong>Total Semanal:</strong> <span id="calculated-weekly-total">0</span> minutos (<span id="calculated-weekly-hours">0</span>h)
                        </p>
                    </div>
                </div>
            </div>

            <p>
                <label>
                    <input type="checkbox" name="status" id="employee-status" value="1" checked />
                    <?php _e('Funcionário Ativo', 'sistur'); ?>
                </label>
            </p>

            <p style="text-align: right; margin-top: 20px;">
                <button type="button" class="button" id="sistur-cancel-employee"><?php _e('Cancelar', 'sistur'); ?></button>
                <button type="submit" class="button button-primary"><?php _e('Salvar', 'sistur'); ?></button>
            </p>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var modal = $('#sistur-employee-modal');

    // Carregar padrões de escala
    function loadShiftPatterns() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_get_shift_patterns',
                status: 1,
                nonce: sisturEmployees.nonce
            },
            success: function(response) {
                if (response.success) {
                    var select = $('#employee-shift-pattern');
                    select.find('option:not(:first)').remove();

                    response.data.forEach(function(pattern) {
                        select.append('<option value="' + pattern.id + '">' + pattern.name + '</option>');
                    });
                }
            }
        });
    }

    // Carregar escalas ao iniciar
    loadShiftPatterns();

    // Adicionar
    $('#sistur-add-employee').on('click', function() {
        $('#sistur-employee-form')[0].reset();
        $('#employee-id').val('0');
        $('#modal-title').text('<?php _e('Adicionar Funcionário', 'sistur'); ?>');
        $('#password-edit-note').hide();
        $('#employee-password').attr('placeholder', 'Digite a senha para acesso ao painel');
        $('#employee-password').prop('required', true); // Tornar obrigatório ao criar novo
        modal.css('display', 'flex');
    });

    // Editar
    $('.sistur-edit-employee').on('click', function() {
        var id = $(this).data('id');

        $.ajax({
            url: ajaxurl,
            type: 'GET',
            data: {
                action: 'get_employee',
                nonce: sisturEmployees.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    var emp = response.data.employee;
                    $('#employee-id').val(emp.id);
                    $('#employee-name').val(emp.name);
                    $('#employee-email').val(emp.email);
                    $('#employee-phone').val(emp.phone);
                    $('#employee-cpf').val(emp.cpf);
                    $('#employee-matricula').val(emp.matricula || '');
                    $('#employee-ctps').val(emp.ctps || '');
                    $('#employee-ctps-uf').val(emp.ctps_uf || '');
                    $('#employee-cbo').val(emp.cbo || '');
                    $('#employee-password').val(''); // Limpar campo de senha
                    $('#password-edit-note').show(); // Mostrar nota
                    $('#employee-password').attr('placeholder', 'Deixe em branco para manter a senha atual');
                    $('#employee-password').prop('required', false); // Não obrigatório ao editar
                    $('#employee-position').val(emp.position);
                    $('#employee-department').val(emp.department_id);
                    $('#employee-role').val(emp.role_id);
                    $('#employee-hire-date').val(emp.hire_date);
                    $('#employee-shift-pattern').val(emp.shift_pattern_id || '');
                    
                    // Populate time window fields
                    $('#employee-shift-start').val(emp.schedule_shift_start_time || '');
                    $('#employee-shift-end').val(emp.schedule_shift_end_time || '');
                    $('#employee-lunch-duration').val(emp.schedule_lunch_duration_minutes || '');
                    
                    // Populate Weekly Config Table
                    if (emp.schedule_active_days) {
                        try {
                            var config = typeof emp.schedule_active_days === 'string' 
                                ? JSON.parse(emp.schedule_active_days) 
                                : emp.schedule_active_days;
                                
                            $('#sistur-employee-modal .day-work-check').each(function() {
                                var day = $(this).data('day');
                                var dayConfig = config[day]; // Direct access, unlike pattern_config.weekly
                                if (dayConfig) {
                                    var isWork = dayConfig.type === 'work';
                                    $(this).prop('checked', isWork);
                                    var hoursInput = $('#sistur-employee-modal .day-hours[data-day="' + day + '"]');
                                    hoursInput.prop('disabled', !isWork);
                                    hoursInput.val(dayConfig.expected_minutes || 0);
                                }
                            });
                        } catch(e) {
                            console.error('Erro ao parsear active_days:', e);
                        }
                    } else {
                        // Default reset if no config
                         $('#sistur-employee-modal .day-work-check').each(function() {
                            var day = $(this).data('day');
                            var isWeekend = (day === 'Saturday' || day === 'Sunday');
                            $(this).prop('checked', !isWeekend);
                            var hoursInput = $('#sistur-employee-modal .day-hours[data-day="' + day + '"]');
                            hoursInput.prop('disabled', isWeekend);
                            hoursInput.val(isWeekend ? 0 : 480);
                        });
                    }
                    updateEmployeeWeeklyTotal();

                    $('#employee-status').prop('checked', emp.status == 1);
                    $('#modal-title').text('<?php _e('Editar Funcionário', 'sistur'); ?>');
                    modal.css('display', 'flex');
                }
            }
        });
    });

    // Cancelar
    $('#sistur-cancel-employee').on('click', function() {
        modal.hide();
    });

    // Salvar
    $('#sistur-employee-form').on('submit', function(e) {
        e.preventDefault();

        var formData = $(this).serializeArray();
        formData.push({name: 'action', value: 'save_employee'});
        formData.push({name: 'nonce', value: sisturEmployees.nonce});
        
        // Fix: serializeArray não inclui checkboxes desmarcados
        formData.push({name: 'status', value: $('#employee-status').is(':checked') ? '1' : '0'});

        // Construct Weekly Config JSON
        var weeklyConfig = {};
        $('#sistur-employee-modal .day-work-check').each(function() {
            var day = $(this).data('day');
            var isWork = $(this).is(':checked');
            var hours = parseInt($('#sistur-employee-modal .day-hours[data-day="' + day + '"]').val()) || 0;
            
            weeklyConfig[day] = {
                type: isWork ? 'work' : 'rest',
                expected_minutes: isWork ? hours : 0
            };
        });
        formData.push({name: 'active_days', value: JSON.stringify(weeklyConfig)});

        // Debug: verificar se shift_pattern_id está sendo enviado
        var shiftPatternId = $('#employee-shift-pattern').val();
        console.log('SISTUR DEBUG: Enviando shift_pattern_id:', shiftPatternId);
        console.log('SISTUR DEBUG: Status:', $('#employee-status').is(':checked') ? 1 : 0);
        console.log('SISTUR DEBUG: Weekly Config:', weeklyConfig);
        console.log('SISTUR DEBUG: FormData completo:', formData);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: $.param(formData),
            success: function(response) {
                if (response.success) {
                    console.log('SISTUR DEBUG: Funcionário salvo com sucesso!', response.data);
                    alert(response.data.message);
                    location.reload();
                } else {
                    console.error('SISTUR DEBUG: Erro ao salvar:', response.data);
                    alert(response.data.message || 'Erro ao salvar.');
                }
            },
            error: function(xhr, status, error) {
                console.error('SISTUR DEBUG: Erro AJAX:', error);
                alert('Erro ao conectar com o servidor.');
            }
        });
    });

    // Deletar
    $('.sistur-delete-employee').on('click', function() {
        if (!confirm('<?php _e('Tem certeza que deseja excluir este funcionário?', 'sistur'); ?>')) {
            return;
        }

        var id = $(this).data('id');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'delete_employee',
                nonce: sisturEmployees.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert(response.data.message || 'Erro ao excluir.');
                }
            }
        });
    });

    // Paginação
    $(document).on('click', '.sistur-pagination-btn', function() {
        var page = $(this).data('page');
        var url = new URL(window.location.href);
        url.searchParams.set('paged', page);
        window.location.href = url.toString();
    });

    // Toggle para habilitar/desabilitar campo de horas quando checkbox muda (Employee Modal)
    $(document).on('change', '#sistur-employee-modal .day-work-check', function() {
        var day = $(this).data('day');
        var hoursInput = $('#sistur-employee-modal .day-hours[data-day="' + day + '"]');
        if ($(this).is(':checked')) {
            hoursInput.prop('disabled', false);
            if (hoursInput.val() == 0) hoursInput.val(480);
        } else {
            hoursInput.prop('disabled', true).val(0);
        }
        updateEmployeeWeeklyTotal();
    });

    $(document).on('input', '#sistur-employee-modal .day-hours', function() {
        updateEmployeeWeeklyTotal();
    });

    function updateEmployeeWeeklyTotal() {
        var total = 0;
        $('#sistur-employee-modal .day-hours').each(function() {
            if (!$(this).prop('disabled')) {
                total += parseInt($(this).val()) || 0;
            }
        });
        $('#calculated-weekly-total').text(total);
        $('#calculated-weekly-hours').text((total / 60).toFixed(1));
    }

    // Export CSV
    $('#sistur-export-employees').on('click', function() {
        var url = new URL(window.location.href);
        url.searchParams.set('export', 'csv');
        window.location.href = url.toString();
    });
});
</script>
