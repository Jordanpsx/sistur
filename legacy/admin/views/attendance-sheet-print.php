<?php
/**
 * View de impressão da Folha de Ponto Individual
 *
 * @package SISTUR
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Verificar permissões
if (!current_user_can('manage_options')) {
    wp_die('Você não tem permissão para acessar esta página.');
}

// Verificar parâmetros
if (!isset($_GET['employee_id'])) {
    wp_die('Parâmetros inválidos.');
}

$employee_id = intval($_GET['employee_id']);
$period_type = isset($_GET['period_type']) ? sanitize_text_field($_GET['period_type']) : 'monthly';

// Determinar período baseado no tipo
if ($period_type === 'weekly') {
    // Período semanal/customizado
    if (!isset($_GET['start_date']) || !isset($_GET['end_date'])) {
        wp_die('Período semanal requer data inicial e final.');
    }

    $start_date = sanitize_text_field($_GET['start_date']);
    $end_date = sanitize_text_field($_GET['end_date']);

    // Validar formato das datas (YYYY-MM-DD)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        wp_die('Formato de data inválido.');
    }

    // Validar que end_date >= start_date
    if (strtotime($end_date) < strtotime($start_date)) {
        wp_die('Data final deve ser maior ou igual à data inicial.');
    }
} else {
    // Período mensal (comportamento original)
    if (!isset($_GET['month'])) {
        wp_die('Parâmetros inválidos.');
    }

    $month = sanitize_text_field($_GET['month']);

    // Validar formato do mês (YYYY-MM)
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        wp_die('Formato de mês inválido.');
    }

    // Calcular dias do mês
    list($year, $month_num) = explode('-', $month);
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, intval($month_num), intval($year));

    $start_date = "$year-$month_num-01";
    $end_date = "$year-$month_num-$days_in_month";
}

global $wpdb;

// Buscar dados do funcionário
$table_employees = $wpdb->prefix . 'sistur_employees';
$employee = $wpdb->get_row($wpdb->prepare(
    "SELECT e.*, d.name as department_name
     FROM $table_employees e
     LEFT JOIN {$wpdb->prefix}sistur_departments d ON e.department_id = d.id
     WHERE e.id = %d",
    $employee_id
), ARRAY_A);

if (!$employee) {
    wp_die('Funcionário não encontrado.');
}

// Buscar configurações da empresa
$table_settings = $wpdb->prefix . 'sistur_settings';
$company_name = $wpdb->get_var($wpdb->prepare(
    "SELECT setting_value FROM $table_settings WHERE setting_key = %s",
    'company_name'
));
$company_cnpj = $wpdb->get_var($wpdb->prepare(
    "SELECT setting_value FROM $table_settings WHERE setting_key = %s",
    'company_cnpj'
));
$company_address = $wpdb->get_var($wpdb->prepare(
    "SELECT setting_value FROM $table_settings WHERE setting_key = %s",
    'company_address'
));
$company_default_department = $wpdb->get_var($wpdb->prepare(
    "SELECT setting_value FROM $table_settings WHERE setting_key = %s",
    'company_default_department'
));

// Usar departamento padrão se não houver departamento específico
$department = !empty($employee['department_name']) ? $employee['department_name'] : $company_default_department;

// Formatar período para exibição
$month_names = array(
    '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março',
    '04' => 'Abril', '05' => 'Maio', '06' => 'Junho',
    '07' => 'Julho', '08' => 'Agosto', '09' => 'Setembro',
    '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
);

if ($period_type === 'weekly') {
    // Formato: dd/mm/yyyy a dd/mm/yyyy
    $period_display = date('d/m/Y', strtotime($start_date)) . ' a ' . date('d/m/Y', strtotime($end_date));
    $period_title = "Semana de " . $period_display;
} else {
    // Formato: Mês/Ano
    list($year, $month_num) = explode('-', $month);
    $month_name = $month_names[$month_num];
    $period_display = $month_name . '/' . $year;
    $period_title = $period_display;
}

// Buscar registros de ponto do período
$table_time_entries = $wpdb->prefix . 'sistur_time_entries';
$time_entries = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table_time_entries
     WHERE employee_id = %d
     AND shift_date >= %s
     AND shift_date <= %s
     ORDER BY shift_date ASC, punch_time ASC",
    $employee_id,
    $start_date,
    $end_date
), ARRAY_A);

// Organizar registros por data (YYYY-MM-DD)
$entries_by_date = array();
foreach ($time_entries as $entry) {
    $date = $entry['shift_date'];
    if (!isset($entries_by_date[$date])) {
        $entries_by_date[$date] = array(
            'clock_in' => '',
            'lunch_start' => '',
            'lunch_end' => '',
            'clock_out' => '',
            'extra' => ''
        );
    }

    $time = date('H:i', strtotime($entry['punch_time']));

    switch ($entry['punch_type']) {
        case 'clock_in':
            $entries_by_date[$date]['clock_in'] = $time;
            break;
        case 'lunch_start':
            $entries_by_date[$date]['lunch_start'] = $time;
            break;
        case 'lunch_end':
            $entries_by_date[$date]['lunch_end'] = $time;
            break;
        case 'clock_out':
            $entries_by_date[$date]['clock_out'] = $time;
            break;
        default:
            $entries_by_date[$date]['extra'] = $time;
            break;
    }
}

// Buscar banco de horas do período
$table_time_days = $wpdb->prefix . 'sistur_time_days';
$time_days = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table_time_days
     WHERE employee_id = %d
     AND shift_date >= %s
     AND shift_date <= %s",
    $employee_id,
    $start_date,
    $end_date
), ARRAY_A);

$bank_by_date = array();
foreach ($time_days as $day_data) {
    $date = $day_data['shift_date'];
    $bank_by_date[$date] = array(
        'saldo_final_minutos' => $day_data['saldo_final_minutos']
    );
}

// Buscar exceções (faltas justificadas, feriados, etc.) do período
$table_exceptions = $wpdb->prefix . 'sistur_schedule_exceptions';
$exceptions = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table_exceptions
     WHERE employee_id = %d
     AND date >= %s
     AND date <= %s
     AND status = 'approved'",
    $employee_id,
    $start_date,
    $end_date
), ARRAY_A);

$exceptions_by_date = array();
foreach ($exceptions as $exception) {
    $date = $exception['date'];
    $exceptions_by_date[$date] = array(
        'exception_type' => $exception['exception_type'],
        'is_justified' => $exception['is_justified'],
        'notes' => $exception['notes']
    );
}

// Formatar CPF
$cpf_formatted = '';
if (!empty($employee['cpf']) && strlen($employee['cpf']) == 11) {
    $cpf_formatted = substr($employee['cpf'], 0, 3) . '.' .
                     substr($employee['cpf'], 3, 3) . '.' .
                     substr($employee['cpf'], 6, 3) . '-' .
                     substr($employee['cpf'], 9, 2);
}

// Formatar data de admissão
$hire_date_formatted = '';
if (!empty($employee['hire_date'])) {
    $hire_date_formatted = date('d/m/Y', strtotime($employee['hire_date']));
}

// Formatar CTPS
$ctps_formatted = '';
if (!empty($employee['ctps'])) {
    $ctps_formatted = $employee['ctps'];
    if (!empty($employee['ctps_uf'])) {
        $ctps_formatted .= '/' . strtoupper($employee['ctps_uf']);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Folha de Ponto - <?php echo esc_html($employee['name']); ?> - <?php echo esc_html($period_display); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.3;
            padding: 20px;
            background: #f5f5f5;
        }

        .container {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }

        .header h1 {
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .header-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 10px;
            text-align: left;
            font-size: 9pt;
        }

        .header-info div {
            padding: 5px;
            border: 1px solid #ccc;
        }

        .header-info strong {
            display: inline-block;
            min-width: 110px;
        }

        .period {
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            margin: 10px 0;
            padding: 6px;
            background: #f0f0f0;
            border: 1px solid #ccc;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        th, td {
            border: 1px solid #000;
            padding: 4px 3px;
            text-align: center;
            font-size: 8pt;
        }

        th {
            background: #e0e0e0;
            font-weight: bold;
            font-size: 8pt;
            line-height: 1.2;
        }

        .day-col {
            width: 30px;
        }

        .weekday-col {
            width: 65px;
        }

        .time-col {
            width: 52px;
        }

        .bank-col {
            width: 60px;
        }

        .hours-col {
            width: 60px;
        }

        .notes-col {
            font-size: 7pt;
            text-align: left;
        }

        .weekend {
            background: #f9f9f9;
        }

        .footer {
            margin-top: 25px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .signature-box {
            text-align: center;
        }

        .signature-line {
            border-top: 1px solid #000;
            margin-top: 40px;
            padding-top: 5px;
            font-size: 9pt;
        }

        .signature-label {
            font-size: 8pt;
            color: #666;
            margin-top: 3px;
        }

        @media print {
            /* Ocultar elementos específicos do WordPress admin */
            #wpadminbar,
            #adminmenumain,
            #adminmenuback,
            #adminmenuwrap,
            #wpfooter,
            .update-nag,
            .notice,
            .error {
                display: none !important;
            }

            /* Reset de página */
            @page {
                size: A4 portrait !important;
                margin: 8mm 10mm !important;
            }

            html, body {
                background: white !important;
                margin: 0 !important;
                padding: 0 !important;
                height: auto !important;
            }

            body {
                background: white !important;
                padding: 0 !important;
            }

            .container {
                box-shadow: none !important;
                padding: 8mm 10mm !important;
                max-width: 100% !important;
                width: 190mm !important;
                margin: 0 auto !important;
                background: white !important;
            }

            /* Otimizar quebras de página */
            table {
                page-break-inside: auto !important;
            }

            tr {
                page-break-inside: avoid !important;
                page-break-after: auto !important;
            }

            thead {
                display: table-header-group !important;
            }

            /* Reduzir espaçamentos para caber em uma folha */
            .header {
                margin-bottom: 8px !important;
                padding-bottom: 6px !important;
            }

            .header h1 {
                font-size: 14pt !important;
                margin-bottom: 6px !important;
            }

            .header-info {
                gap: 4px !important;
                margin-top: 6px !important;
                font-size: 8pt !important;
            }

            .header-info div {
                padding: 3px !important;
            }

            .period {
                margin: 6px 0 !important;
                padding: 4px !important;
                font-size: 10pt !important;
            }

            table {
                margin-bottom: 8px !important;
            }

            th, td {
                padding: 3px 2px !important;
                font-size: 7pt !important;
            }

            th {
                font-size: 7pt !important;
            }

            .footer {
                margin-top: 12px !important;
                gap: 20px !important;
            }

            .signature-line {
                margin-top: 25px !important;
                padding-top: 4px !important;
                font-size: 8pt !important;
            }

            .signature-label {
                font-size: 7pt !important;
            }

            /* Ocultar botões de ação */
            .no-print {
                display: none !important;
            }

            /* Manter cores e backgrounds para impressão */
            th {
                background: #e0e0e0 !important;
            }

            .weekend {
                background: #f5f5f5 !important;
            }

            .period {
                background: #f0f0f0 !important;
                border: 1px solid #ccc !important;
            }

            .header-info div {
                border: 1px solid #ccc !important;
            }

            table, th, td {
                border: 1px solid #000 !important;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Cabeçalho -->
        <div class="header">
            <h1>Folha de Presença Individual</h1>

            <div class="header-info">
                <div>
                    <strong>Empregador:</strong> <?php echo esc_html($company_name ?: '_______________'); ?>
                </div>
                <div>
                    <strong>CNPJ:</strong> <?php echo esc_html($company_cnpj ?: '_______________'); ?>
                </div>
                <div style="grid-column: 1 / -1;">
                    <strong>Endereço:</strong> <?php echo esc_html($company_address ?: '_______________'); ?>
                </div>
                <div>
                    <strong>Departamento:</strong> <?php echo esc_html($department ?: '_______________'); ?>
                </div>
                <div>
                    <strong>Empregado:</strong> <?php echo esc_html($employee['name']); ?>
                </div>
                <div>
                    <strong>Admissão:</strong> <?php echo esc_html($hire_date_formatted ?: '___/___/______'); ?>
                </div>
                <div>
                    <strong>CTPS/UF:</strong> <?php echo esc_html($ctps_formatted ?: '_______________'); ?>
                </div>
                <div>
                    <strong>C.B.O:</strong> <?php echo esc_html($employee['cbo'] ?: '_______________'); ?>
                </div>
                <div>
                    <strong>Matrícula:</strong> <?php echo esc_html($employee['matricula'] ?: '_______________'); ?>
                </div>
                <div>
                    <strong>Cargo:</strong> <?php echo esc_html($employee['position'] ?: '_______________'); ?>
                </div>
            </div>
        </div>

        <!-- Período -->
        <div class="period">
            Período: <?php echo esc_html($period_title); ?>
        </div>

        <!-- Tabela de Registros -->
        <table>
            <thead>
                <tr>
                    <th class="day-col">Dia</th>
                    <th class="weekday-col">Dia da<br>Semana</th>
                    <th class="time-col">Entrada</th>
                    <th class="time-col">Intervalo<br>Início</th>
                    <th class="time-col">Intervalo<br>Fim</th>
                    <th class="time-col">Saída</th>
                    <th class="bank-col">Hora Extra<br>(Banco)</th>
                    <th class="hours-col">Carga<br>Horária</th>
                    <th class="notes-col">Observações</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Iterar sobre cada dia no período
                $current_date = new DateTime($start_date);
                $end_date_obj = new DateTime($end_date);

                while ($current_date <= $end_date_obj) :
                    $date = $current_date->format('Y-m-d');
                    $day = $current_date->format('j');
                    $day_of_week = $current_date->format('w');
                    $is_weekend = ($day_of_week == 0 || $day_of_week == 6);

                    // Traduzir dia da semana para português
                    $weekday_names = array(
                        '0' => 'Domingo',
                        '1' => 'Segunda',
                        '2' => 'Terça',
                        '3' => 'Quarta',
                        '4' => 'Quinta',
                        '5' => 'Sexta',
                        '6' => 'Sábado'
                    );
                    $weekday_name = $weekday_names[$day_of_week];

                    $entry = isset($entries_by_date[$date]) ? $entries_by_date[$date] : array(
                        'clock_in' => '',
                        'lunch_start' => '',
                        'lunch_end' => '',
                        'clock_out' => '',
                        'extra' => ''
                    );

                    $bank_data = isset($bank_by_date[$date]) ? $bank_by_date[$date] : array('saldo_final_minutos' => 0);
                    $bank_minutes = $bank_data['saldo_final_minutos'];
                    $bank_formatted = '';
                    if ($bank_minutes != 0) {
                        $hours = floor(abs($bank_minutes) / 60);
                        $minutes = abs($bank_minutes) % 60;
                        $sign = $bank_minutes >= 0 ? '+' : '-';
                        $bank_formatted = sprintf("%s%02d:%02d", $sign, $hours, $minutes);
                    }

                    // Calcular carga horária trabalhada no dia
                    $worked_hours_formatted = '';
                    if (!empty($entry['clock_in']) && !empty($entry['clock_out'])) {
                        $clock_in_time = strtotime($date . ' ' . $entry['clock_in']);
                        $clock_out_time = strtotime($date . ' ' . $entry['clock_out']);

                        $worked_minutes = ($clock_out_time - $clock_in_time) / 60;

                        // Subtrair tempo de almoço se houver
                        if (!empty($entry['lunch_start']) && !empty($entry['lunch_end'])) {
                            $lunch_start_time = strtotime($date . ' ' . $entry['lunch_start']);
                            $lunch_end_time = strtotime($date . ' ' . $entry['lunch_end']);
                            $lunch_minutes = ($lunch_end_time - $lunch_start_time) / 60;
                            $worked_minutes -= $lunch_minutes;
                        }

                        if ($worked_minutes > 0) {
                            $hours = floor($worked_minutes / 60);
                            $minutes = $worked_minutes % 60;
                            $worked_hours_formatted = sprintf("%02d:%02d", $hours, $minutes);
                        }
                    }

                    // Observações - verificar se há falta justificada ou outras exceções
                    $observations = '';
                    if (isset($exceptions_by_date[$date])) {
                        $exception = $exceptions_by_date[$date];

                        // Verificar se é falta justificada
                        if ($exception['exception_type'] === 'absence' && $exception['is_justified'] == 1) {
                            $observations = '(FALTA JUSTIFICADA)';
                            if (!empty($exception['notes'])) {
                                $observations .= ' - ' . $exception['notes'];
                            }
                        } else {
                            // Outras exceções (feriados, etc.)
                            switch ($exception['exception_type']) {
                                case 'holiday':
                                    $observations = 'Feriado';
                                    break;
                                case 'sick_leave':
                                    $observations = 'Atestado Médico';
                                    break;
                                case 'vacation':
                                    $observations = 'Férias';
                                    break;
                                case 'day_off_trade':
                                    $observations = 'Troca de Folga';
                                    break;
                                case 'special_event':
                                    $observations = 'Evento Especial';
                                    break;
                            }
                            if (!empty($exception['notes'])) {
                                $observations .= ' - ' . $exception['notes'];
                            }
                        }
                    }
                ?>
                    <tr class="<?php echo $is_weekend ? 'weekend' : ''; ?>">
                        <td class="day-col"><?php echo $day; ?></td>
                        <td class="weekday-col"><?php echo esc_html($weekday_name); ?></td>
                        <td class="time-col"><?php echo esc_html($entry['clock_in']); ?></td>
                        <td class="time-col"><?php echo esc_html($entry['lunch_start']); ?></td>
                        <td class="time-col"><?php echo esc_html($entry['lunch_end']); ?></td>
                        <td class="time-col"><?php echo esc_html($entry['clock_out']); ?></td>
                        <td class="bank-col"><?php echo esc_html($bank_formatted); ?></td>
                        <td class="hours-col"><?php echo esc_html($worked_hours_formatted); ?></td>
                        <td class="notes-col"><?php echo esc_html($observations); ?></td>
                    </tr>
                <?php
                    $current_date->modify('+1 day');
                endwhile;
                ?>
            </tbody>
        </table>

        <!-- Rodapé com Assinaturas -->
        <div class="footer">
            <div class="signature-box">
                <div class="signature-line">
                    <?php echo esc_html($employee['name']); ?>
                </div>
                <div class="signature-label">
                    Assinatura do Empregado
                </div>
            </div>

            <div class="signature-box">
                <div class="signature-line">
                    <?php echo esc_html($company_name ?: ''); ?>
                </div>
                <div class="signature-label">
                    Assinatura da Empresa
                </div>
            </div>
        </div>

        <!-- Botão de impressão (oculto na impressão) -->
        <div style="text-align: center; margin-top: 30px;" class="no-print">
            <button onclick="window.print()" style="padding: 10px 20px; font-size: 14px; cursor: pointer; background: #2271b1; color: white; border: none; border-radius: 3px;">
                🖨️ Imprimir ou Salvar em PDF
            </button>
            <button onclick="window.close()" style="padding: 10px 20px; font-size: 14px; cursor: pointer; background: #666; color: white; border: none; border-radius: 3px; margin-left: 10px;">
                ✕ Fechar
            </button>
        </div>
    </div>
</body>
</html>
