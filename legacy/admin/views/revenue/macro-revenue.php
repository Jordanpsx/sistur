<?php
/**
 * Página: Fechar Caixa / Registrar Faturamento (Modo Macro)
 *
 * Permite registrar o faturamento bruto diário por categoria de receita.
 * A receita lançada aqui é usada pelo DRE quando sistur_sales_mode = 'MACRO'.
 *
 * @package SISTUR
 * @since   2.16.0
 */

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}

$current_sales_mode = get_option('sistur_sales_mode', 'MICRO');
$api_base = rest_url('sistur/v1/stock/revenue');
$nonce    = wp_create_nonce('wp_rest');
?>

<div class="wrap">
    <h1><?php _e('Fechar Caixa — Registrar Faturamento', 'sistur'); ?></h1>

    <?php if ($current_sales_mode !== 'MACRO') : ?>
        <div class="notice notice-warning">
            <p>
                <?php _e('<strong>Atenção:</strong> O sistema está em modo <strong>Micro</strong>. Os lançamentos registrados aqui <em>não</em> aparecem no DRE até você ativar o <strong>Modo Macro</strong> em', 'sistur'); ?>
                <a href="<?php echo admin_url('admin.php?page=sistur-settings'); ?>"><?php _e('Configurações', 'sistur'); ?></a>.
            </p>
        </div>
    <?php endif; ?>

    <!-- ── Formulário de lançamento ─────────────────────────────────────── -->
    <div class="card" style="max-width:600px; padding:20px; margin-bottom:24px;">
        <h2 style="margin-top:0;"><?php _e('Novo Lançamento', 'sistur'); ?></h2>

        <table class="form-table" style="margin:0;">
            <tbody>
                <tr>
                    <th><label for="mr-date"><?php _e('Data', 'sistur'); ?></label></th>
                    <td>
                        <input type="date" id="mr-date" value="<?php echo esc_attr(date('Y-m-d')); ?>"
                               style="width:180px;" required />
                    </td>
                </tr>
                <tr>
                    <th><label for="mr-category"><?php _e('Categoria', 'sistur'); ?></label></th>
                    <td>
                        <select id="mr-category" style="width:220px;">
                            <option value="Restaurante"><?php _e('Restaurante', 'sistur'); ?></option>
                            <option value="Bar"><?php _e('Bar', 'sistur'); ?></option>
                            <option value="Eventos"><?php _e('Eventos', 'sistur'); ?></option>
                            <option value="Delivery"><?php _e('Delivery', 'sistur'); ?></option>
                            <option value="Geral"><?php _e('Geral', 'sistur'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="mr-amount"><?php _e('Valor (R$)', 'sistur'); ?></label></th>
                    <td>
                        <input type="number" id="mr-amount" min="0.01" step="0.01" placeholder="0,00"
                               style="width:180px;" required />
                    </td>
                </tr>
                <tr>
                    <th><label for="mr-notes"><?php _e('Observações', 'sistur'); ?></label></th>
                    <td>
                        <textarea id="mr-notes" rows="2" style="width:100%; max-width:360px;"
                                  placeholder="<?php esc_attr_e('Opcional — ex: evento especial, feriado...', 'sistur'); ?>"></textarea>
                    </td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top:16px;">
            <button id="mr-submit" class="button button-primary">
                <?php _e('Registrar Faturamento', 'sistur'); ?>
            </button>
            <span id="mr-feedback" style="margin-left:12px; display:none;"></span>
        </div>
    </div>

    <!-- ── Tabela de lançamentos recentes ───────────────────────────────── -->
    <h2><?php _e('Lançamentos Recentes (últimos 30 dias)', 'sistur'); ?></h2>

    <div id="mr-period-bar" style="margin-bottom:12px;">
        <label><?php _e('Período:', 'sistur'); ?>
            <input type="date" id="mr-filter-start" value="<?php echo esc_attr(date('Y-m-01')); ?>" />
        </label>
        &nbsp;<?php _e('até', 'sistur'); ?>&nbsp;
        <label>
            <input type="date" id="mr-filter-end" value="<?php echo esc_attr(date('Y-m-d')); ?>" />
        </label>
        <button id="mr-filter-btn" class="button" style="margin-left:8px;"><?php _e('Filtrar', 'sistur'); ?></button>
        <strong id="mr-total-label" style="margin-left:20px; font-size:15px;"></strong>
    </div>

    <table class="wp-list-table widefat fixed striped" id="mr-table">
        <thead>
            <tr>
                <th><?php _e('Data', 'sistur'); ?></th>
                <th><?php _e('Categoria', 'sistur'); ?></th>
                <th><?php _e('Valor', 'sistur'); ?></th>
                <th><?php _e('Observações', 'sistur'); ?></th>
                <th><?php _e('Registrado por', 'sistur'); ?></th>
                <th><?php _e('Ações', 'sistur'); ?></th>
            </tr>
        </thead>
        <tbody id="mr-tbody">
            <tr><td colspan="6" style="text-align:center; padding:20px;"><?php _e('Carregando...', 'sistur'); ?></td></tr>
        </tbody>
    </table>
</div>

<script>
(function () {
    const API   = <?php echo json_encode($api_base); ?>;
    const NONCE = <?php echo json_encode($nonce); ?>;

    const headers = {
        'Content-Type': 'application/json',
        'X-WP-Nonce': NONCE
    };

    function formatCurrency(v) {
        return 'R$ ' + parseFloat(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatDate(d) {
        if (!d) return '-';
        const [y, m, day] = d.split('-');
        return `${day}/${m}/${y}`;
    }

    // ── Carregar tabela ────────────────────────────────────────────────────
    async function loadRevenue() {
        const start = document.getElementById('mr-filter-start').value;
        const end   = document.getElementById('mr-filter-end').value;
        const tbody = document.getElementById('mr-tbody');
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:16px;">Carregando...</td></tr>';

        try {
            const res  = await fetch(`${API}?start_date=${start}&end_date=${end}`, { headers });
            const data = await res.json();

            if (!data.success || !data.rows.length) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:16px;">Nenhum lançamento encontrado.</td></tr>';
                document.getElementById('mr-total-label').textContent = '';
                return;
            }

            tbody.innerHTML = data.rows.map(row => `
                <tr id="mr-row-${row.id}">
                    <td>${formatDate(row.revenue_date)}</td>
                    <td>${escHtml(row.category)}</td>
                    <td><strong>${formatCurrency(row.total_amount)}</strong></td>
                    <td>${escHtml(row.notes || '-')}</td>
                    <td>${escHtml(row.user_id || '-')}</td>
                    <td>
                        <button class="button button-small button-link-delete"
                                onclick="mrDelete(${row.id})">Excluir</button>
                    </td>
                </tr>
            `).join('');

            document.getElementById('mr-total-label').textContent =
                'Total do período: ' + formatCurrency(data.total);

        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="6" style="color:#d63638;padding:16px;">Erro ao carregar: ${e.message}</td></tr>`;
        }
    }

    // ── Registrar faturamento ──────────────────────────────────────────────
    document.getElementById('mr-submit').addEventListener('click', async function () {
        const date     = document.getElementById('mr-date').value;
        const category = document.getElementById('mr-category').value;
        const amount   = parseFloat(document.getElementById('mr-amount').value);
        const notes    = document.getElementById('mr-notes').value;
        const feedback = document.getElementById('mr-feedback');

        if (!date || !amount || amount <= 0) {
            showFeedback('Por favor, preencha Data e Valor.', 'error');
            return;
        }

        this.disabled = true;
        showFeedback('Salvando...', 'info');

        try {
            const res  = await fetch(API, {
                method: 'POST',
                headers,
                body: JSON.stringify({ revenue_date: date, total_amount: amount, category, notes })
            });
            const data = await res.json();

            if (data.success) {
                document.getElementById('mr-amount').value = '';
                document.getElementById('mr-notes').value  = '';
                showFeedback('Faturamento registrado com sucesso!', 'success');
                loadRevenue();
            } else {
                showFeedback('Erro: ' + (data.message || 'Falha desconhecida.'), 'error');
            }
        } catch (e) {
            showFeedback('Erro de conexão: ' + e.message, 'error');
        } finally {
            this.disabled = false;
        }
    });

    // ── Excluir ────────────────────────────────────────────────────────────
    window.mrDelete = async function (id) {
        if (!confirm('Excluir este lançamento? Esta ação não pode ser desfeita.')) return;

        try {
            const res  = await fetch(`${API}/${id}`, { method: 'DELETE', headers });
            const data = await res.json();
            if (data.success) {
                const row = document.getElementById(`mr-row-${id}`);
                if (row) row.remove();
                loadRevenue(); // recarrega para atualizar total
            } else {
                alert('Erro ao excluir: ' + (data.message || ''));
            }
        } catch (e) {
            alert('Erro de conexão: ' + e.message);
        }
    };

    // ── Helpers ────────────────────────────────────────────────────────────
    function showFeedback(msg, type) {
        const el = document.getElementById('mr-feedback');
        const colors = { success: '#46b450', error: '#d63638', info: '#999' };
        el.textContent = msg;
        el.style.color = colors[type] || '#333';
        el.style.display = 'inline';
    }

    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }

    // ── Init ───────────────────────────────────────────────────────────────
    document.getElementById('mr-filter-btn').addEventListener('click', loadRevenue);
    loadRevenue();
})();
</script>
