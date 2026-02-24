<?php
/**
 * Página de Gerenciamento de Pagamentos
 *
 * @package SISTUR
 */

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}

$employees = SISTUR_Employees::get_all_employees(array('status' => 1));
?>

<div class="wrap">
    <h1>
        <?php _e('Pagamentos de Funcionários', 'sistur'); ?>
        <button class="button button-primary" id="sistur-add-payment">
            <?php _e('Registrar Pagamento', 'sistur'); ?>
        </button>
    </h1>

    <div style="background: white; padding: 20px; margin-top: 20px;">
        <h2><?php _e('Histórico de Pagamentos', 'sistur'); ?></h2>
        <div id="sistur-payments-list">
            <p><?php _e('Selecione um funcionário para ver o histórico de pagamentos.', 'sistur'); ?></p>
        </div>
    </div>
</div>

<!-- Modal de Pagamento -->
<div id="sistur-payment-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 8px; width: 90%; max-width: 500px;">
        <h2><?php _e('Registrar Pagamento', 'sistur'); ?></h2>
        <form id="sistur-payment-form">
            <p>
                <label><?php _e('Funcionário *', 'sistur'); ?></label>
                <select name="employee_id" id="payment-employee-id" required style="width: 100%;">
                    <option value=""><?php _e('Selecione...', 'sistur'); ?></option>
                    <?php foreach ($employees as $emp) : ?>
                        <option value="<?php echo $emp['id']; ?>"><?php echo esc_html($emp['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label><?php _e('Tipo de Pagamento *', 'sistur'); ?></label>
                <select name="payment_type" id="payment-type" required style="width: 100%;">
                    <option value="daily"><?php _e('Diário', 'sistur'); ?></option>
                    <option value="weekly"><?php _e('Semanal', 'sistur'); ?></option>
                    <option value="monthly"><?php _e('Mensal', 'sistur'); ?></option>
                </select>
            </p>

            <p>
                <label><?php _e('Valor (R$) *', 'sistur'); ?></label>
                <input type="number" name="amount" id="payment-amount" step="0.01" required style="width: 100%;" />
            </p>

            <p>
                <label><?php _e('Data do Pagamento *', 'sistur'); ?></label>
                <input type="date" name="payment_date" id="payment-date" required style="width: 100%;" value="<?php echo date('Y-m-d'); ?>" />
            </p>

            <p>
                <label><?php _e('Observações', 'sistur'); ?></label>
                <textarea name="notes" id="payment-notes" rows="3" style="width: 100%;"></textarea>
            </p>

            <p style="text-align: right; margin-top: 20px;">
                <button type="button" class="button" id="sistur-cancel-payment"><?php _e('Cancelar', 'sistur'); ?></button>
                <button type="submit" class="button button-primary"><?php _e('Salvar Pagamento', 'sistur'); ?></button>
            </p>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var modal = $('#sistur-payment-modal');

    $('#sistur-add-payment').on('click', function() {
        modal.css('display', 'flex');
    });

    $('#sistur-cancel-payment').on('click', function() {
        modal.hide();
    });

    $('#sistur-payment-form').on('submit', function(e) {
        e.preventDefault();

        var formData = $(this).serializeArray();
        formData.push({name: 'action', value: 'save_payment'});
        formData.push({name: 'nonce', value: sisturEmployees.nonce});

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: $.param(formData),
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    modal.hide();
                    $('#sistur-payment-form')[0].reset();
                } else {
                    alert(response.data.message || 'Erro ao salvar pagamento.');
                }
            }
        });
    });
});
</script>
