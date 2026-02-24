<?php
/**
 * Página de Gerenciamento de Feriados
 * 
 * @package SISTUR
 * @since 1.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$holidays_table = $wpdb->prefix . 'sistur_holidays';

// Obter mês/ano atual ou do filtro
$current_month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$current_year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// Validar mês e ano
if ($current_month < 1 || $current_month > 12) $current_month = date('m');
if ($current_year < 2020 || $current_year > 2030) $current_year = date('Y');

// Primeiro e último dia do mês
$first_day = sprintf('%04d-%02d-01', $current_year, $current_month);
$last_day = date('Y-m-t', strtotime($first_day));

// Buscar feriados do mês
$holidays = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $holidays_table WHERE holiday_date BETWEEN %s AND %s ORDER BY holiday_date ASC",
    $first_day, $last_day
), OBJECT_K);

// Mapear por data para fácil acesso
$holidays_by_date = array();
foreach ($holidays as $h) {
    $holidays_by_date[$h->holiday_date] = $h;
}

// Nomes dos meses em português
$months = array(
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
);

// Dias da semana
$weekdays = array('Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb');

// Calcular navegação
$prev_month = $current_month - 1;
$prev_year = $current_year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }

$next_month = $current_month + 1;
$next_year = $current_year;
if ($next_month > 12) { $next_month = 1; $next_year++; }
?>

<div class="wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-calendar-alt" style="font-size: 28px; vertical-align: middle;"></span>
        <?php _e('Gerenciamento de Feriados', 'sistur'); ?>
    </h1>
    
    <hr class="wp-header-end">

    <!-- Navegação do Mês -->
    <div class="sistur-calendar-nav" style="display: flex; align-items: center; justify-content: space-between; margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px;">
        <a href="<?php echo admin_url("admin.php?page=sistur-holidays&month=$prev_month&year=$prev_year"); ?>" class="button">
            <span class="dashicons dashicons-arrow-left-alt2"></span> 
            <?php echo $months[$prev_month] . ' ' . $prev_year; ?>
        </a>
        
        <h2 style="margin: 0; font-size: 24px; font-weight: 600;">
            <?php echo $months[$current_month] . ' ' . $current_year; ?>
        </h2>
        
        <a href="<?php echo admin_url("admin.php?page=sistur-holidays&month=$next_month&year=$next_year"); ?>" class="button">
            <?php echo $months[$next_month] . ' ' . $next_year; ?> 
            <span class="dashicons dashicons-arrow-right-alt2"></span>
        </a>
    </div>

    <!-- Legenda -->
    <div class="sistur-calendar-legend" style="margin-bottom: 20px; padding: 15px; background: #f0f6fc; border-radius: 8px;">
        <strong>Legenda:</strong>
        <span style="margin-left: 20px;">
            <span style="display: inline-block; width: 20px; height: 20px; background: #dc3545; border-radius: 4px; vertical-align: middle;"></span>
            Feriado Nacional
        </span>
        <span style="margin-left: 20px;">
            <span style="display: inline-block; width: 20px; height: 20px; background: #fd7e14; border-radius: 4px; vertical-align: middle;"></span>
            Feriado Estadual/Municipal
        </span>
        <span style="margin-left: 20px;">
            <span style="display: inline-block; width: 20px; height: 20px; background: #6c757d; border-radius: 4px; vertical-align: middle;"></span>
            Ponto Facultativo
        </span>
    </div>

    <!-- Calendário -->
    <div class="sistur-calendar" style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; overflow: hidden;">
        <!-- Cabeçalho -->
        <div class="sistur-calendar-header" style="display: grid; grid-template-columns: repeat(7, 1fr); background: #2271b1; color: #fff;">
            <?php foreach ($weekdays as $day): ?>
                <div style="padding: 12px; text-align: center; font-weight: 600;">
                    <?php echo $day; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Dias do mês -->
        <div class="sistur-calendar-body" style="display: grid; grid-template-columns: repeat(7, 1fr);">
            <?php
            $first_day_of_week = date('w', strtotime($first_day));
            $days_in_month = date('t', strtotime($first_day));
            $today = date('Y-m-d');

            // Dias vazios antes do primeiro dia do mês
            for ($i = 0; $i < $first_day_of_week; $i++): ?>
                <div class="sistur-calendar-day empty" style="padding: 15px; min-height: 80px; background: #f9f9f9; border: 1px solid #eee;"></div>
            <?php endfor;

            // Dias do mês
            for ($day = 1; $day <= $days_in_month; $day++):
                $date = sprintf('%04d-%02d-%02d', $current_year, $current_month, $day);
                $is_today = ($date === $today);
                $is_weekend = in_array(date('w', strtotime($date)), [0, 6]);
                $holiday = isset($holidays_by_date[$date]) ? $holidays_by_date[$date] : null;
                
                $bg_color = '#fff';
                if ($holiday) {
                    switch ($holiday->holiday_type) {
                        case 'nacional': $bg_color = '#dc3545'; break;
                        case 'estadual': case 'municipal': $bg_color = '#fd7e14'; break;
                        case 'ponto_facultativo': $bg_color = '#6c757d'; break;
                    }
                } elseif ($is_weekend) {
                    $bg_color = '#f0f0f1';
                }
                
                $text_color = $holiday ? '#fff' : '#1d2327';
                $border_color = $is_today ? '#2271b1' : '#eee';
                $border_width = $is_today ? '3px' : '1px';
            ?>
                <div class="sistur-calendar-day" 
                     data-date="<?php echo $date; ?>"
                     data-holiday-id="<?php echo $holiday ? $holiday->id : ''; ?>"
                     style="padding: 10px; min-height: 80px; background: <?php echo $bg_color; ?>; border: <?php echo $border_width; ?> solid <?php echo $border_color; ?>; cursor: pointer; transition: all 0.2s;"
                     onclick="openHolidayModal('<?php echo $date; ?>', <?php echo $holiday ? json_encode($holiday) : 'null'; ?>)">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <span style="font-size: 18px; font-weight: <?php echo $is_today || $holiday ? '700' : '400'; ?>; color: <?php echo $text_color; ?>;">
                            <?php echo $day; ?>
                        </span>
                        <?php if ($holiday): ?>
                            <span class="dashicons dashicons-flag" style="color: #fff; font-size: 14px;"></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($holiday): ?>
                        <div style="font-size: 11px; color: #fff; margin-top: 5px; line-height: 1.3;">
                            <?php echo esc_html($holiday->description); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endfor;

            // Dias vazios após o último dia do mês
            $remaining = 7 - (($first_day_of_week + $days_in_month) % 7);
            if ($remaining < 7):
                for ($i = 0; $i < $remaining; $i++): ?>
                    <div class="sistur-calendar-day empty" style="padding: 15px; min-height: 80px; background: #f9f9f9; border: 1px solid #eee;"></div>
                <?php endfor;
            endif;
            ?>
        </div>
    </div>

    <!-- Lista de Feriados do Mês -->
    <div class="sistur-holidays-list" style="margin-top: 30px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px;">
        <h3 style="margin-top: 0;">
            <span class="dashicons dashicons-list-view"></span>
            Feriados em <?php echo $months[$current_month] . ' ' . $current_year; ?>
        </h3>
        
        <?php if (empty($holidays)): ?>
            <p style="color: #646970;">Nenhum feriado cadastrado para este mês.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Descrição</th>
                        <th>Tipo</th>
                        <th style="width: 100px;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($holidays as $h): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($h->holiday_date)); ?></td>
                            <td><?php echo esc_html($h->description); ?></td>
                            <td>
                                <?php 
                                $type_labels = array(
                                    'nacional' => '<span style="background:#dc3545;color:#fff;padding:2px 8px;border-radius:3px;">Nacional</span>',
                                    'estadual' => '<span style="background:#fd7e14;color:#fff;padding:2px 8px;border-radius:3px;">Estadual</span>',
                                    'municipal' => '<span style="background:#fd7e14;color:#fff;padding:2px 8px;border-radius:3px;">Municipal</span>',
                                    'ponto_facultativo' => '<span style="background:#6c757d;color:#fff;padding:2px 8px;border-radius:3px;">Ponto Facultativo</span>'
                                );
                                echo isset($type_labels[$h->holiday_type]) ? $type_labels[$h->holiday_type] : $h->holiday_type;
                                ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small" onclick="openHolidayModal('<?php echo $h->holiday_date; ?>', <?php echo json_encode($h); ?>)">
                                    <span class="dashicons dashicons-edit" style="vertical-align: middle;"></span>
                                </button>
                                <button type="button" class="button button-small" style="color: #dc3545;" onclick="deleteHoliday(<?php echo $h->id; ?>, '<?php echo esc_js($h->description); ?>')">
                                    <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de Feriado -->
<div id="sistur-holiday-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; align-items: center; justify-content: center;">
    <div style="background: #fff; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h2 id="modal-title" style="margin-top: 0;">
            <span class="dashicons dashicons-calendar-alt"></span> 
            Adicionar Feriado
        </h2>
        
        <form id="holiday-form">
            <input type="hidden" id="holiday_id" name="holiday_id" value="">
            
            <p>
                <label for="holiday_date"><strong>Data:</strong></label><br>
                <input type="date" id="holiday_date" name="holiday_date" class="regular-text" required style="width: 100%;">
            </p>
            
            <p>
                <label for="description"><strong>Descrição:</strong></label><br>
                <input type="text" id="description" name="description" class="regular-text" required style="width: 100%;" placeholder="Ex: Natal, Dia do Trabalho, etc.">
            </p>
            
            <p>
                <label for="holiday_type"><strong>Tipo:</strong></label><br>
                <select id="holiday_type" name="holiday_type" style="width: 100%;">
                    <option value="nacional">Feriado Nacional</option>
                    <option value="estadual">Feriado Estadual</option>
                    <option value="municipal">Feriado Municipal</option>
                    <option value="ponto_facultativo">Ponto Facultativo</option>
                </select>
            </p>
            
            <p>
                <label for="multiplicador_adicional"><strong>Multiplicador de Hora Extra:</strong></label><br>
                <input type="number" id="multiplicador_adicional" name="multiplicador_adicional" value="2.00" step="0.1" min="1" max="3" style="width: 100%;">
                <span class="description">Multiplicador aplicado às horas trabalhadas neste dia (2.0 = 100% adicional)</span>
            </p>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-yes"></span> Salvar
                </button>
                <button type="button" class="button" onclick="closeHolidayModal()">
                    Cancelar
                </button>
                <button type="button" id="btn-delete-holiday" class="button" style="color: #dc3545; margin-left: auto; display: none;" onclick="deleteCurrentHoliday()">
                    <span class="dashicons dashicons-trash"></span> Excluir
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.sistur-calendar-day:hover {
    transform: scale(1.02);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    z-index: 1;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Form submit
    $('#holiday-form').on('submit', function(e) {
        e.preventDefault();
        
        var data = {
            action: 'sistur_save_holiday',
            nonce: '<?php echo wp_create_nonce('sistur_timebank_nonce'); ?>',
            holiday_id: $('#holiday_id').val(),
            holiday_date: $('#holiday_date').val(),
            description: $('#description').val(),
            holiday_type: $('#holiday_type').val(),
            multiplicador_adicional: $('#multiplicador_adicional').val(),
            status: 'active'
        };
        
        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Erro: ' + (response.data.message || 'Erro desconhecido'));
            }
        });
    });
});

function openHolidayModal(date, holiday) {
    var modal = document.getElementById('sistur-holiday-modal');
    var title = document.getElementById('modal-title');
    var btnDelete = document.getElementById('btn-delete-holiday');
    
    if (holiday) {
        title.innerHTML = '<span class="dashicons dashicons-edit"></span> Editar Feriado';
        document.getElementById('holiday_id').value = holiday.id;
        document.getElementById('holiday_date').value = holiday.holiday_date;
        document.getElementById('description').value = holiday.description;
        document.getElementById('holiday_type').value = holiday.holiday_type;
        document.getElementById('multiplicador_adicional').value = holiday.multiplicador_adicional;
        btnDelete.style.display = 'inline-block';
    } else {
        title.innerHTML = '<span class="dashicons dashicons-calendar-alt"></span> Adicionar Feriado';
        document.getElementById('holiday_id').value = '';
        document.getElementById('holiday_date').value = date;
        document.getElementById('description').value = '';
        document.getElementById('holiday_type').value = 'nacional';
        document.getElementById('multiplicador_adicional').value = '2.00';
        btnDelete.style.display = 'none';
    }
    
    modal.style.display = 'flex';
}

function closeHolidayModal() {
    document.getElementById('sistur-holiday-modal').style.display = 'none';
}

function deleteCurrentHoliday() {
    var id = document.getElementById('holiday_id').value;
    var description = document.getElementById('description').value;
    deleteHoliday(id, description);
}

function deleteHoliday(id, description) {
    if (!confirm('Tem certeza que deseja excluir o feriado "' + description + '"?')) {
        return;
    }
    
    jQuery.post(ajaxurl, {
        action: 'sistur_delete_holiday',
        nonce: '<?php echo wp_create_nonce('sistur_timebank_nonce'); ?>',
        holiday_id: id
    }, function(response) {
        if (response.success) {
            location.reload();
        } else {
            alert('Erro ao excluir: ' + (response.data.message || 'Erro desconhecido'));
        }
    });
}

// Fechar modal ao clicar fora
document.getElementById('sistur-holiday-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeHolidayModal();
    }
});
</script>
