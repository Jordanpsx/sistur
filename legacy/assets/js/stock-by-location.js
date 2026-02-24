jQuery(document).ready(function ($) {
    console.log('SISTUR Stock by Location script loaded');
    var $modal = $('#modal-stock-by-location');
    var $content = $('#stock-by-location-content');
    var $loading = $('#stock-by-location-loading');

    $(document).on('click', '#btn-view-by-location', function (e) {
        console.log('Button clicked');
        e.preventDefault();
        $modal.fadeIn(200);
        loadStockByLocation();
    });

    $(document).on('click', '.modal-close', function () {
        $modal.fadeOut(200);
    });

    function loadStockByLocation() {
        $content.hide();
        $loading.show();

        $.ajax({
            url: sisturAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'sistur_get_stock_by_location',
                nonce: sisturAdmin.nonce
            },
            success: function (response) {
                $loading.hide();
                if (response.success) {
                    renderStockByLocation(response.data);
                    $content.fadeIn(200);
                } else {
                    alert(response.data.message || sisturAdmin.strings.error);
                }
            },
            error: function () {
                $loading.hide();
                alert(sisturAdmin.strings.error);
            }
        });
    }

    function renderStockByLocation(groupedData) {
        var html = '';

        if (!groupedData || groupedData.length === 0) {
            html = '<p style="text-align:center; color:#64748b;">Nenhum item em estoque encontrado.</p>';
            $content.html(html);
            return;
        }

        groupedData.forEach(function (location) {
            html += '<div class="location-group" style="margin-bottom: 20px; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">';
            html += '<div class="location-header" style="background: #f8fafc; padding: 12px 15px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">';
            html += '<h4 style="margin: 0; color: #334155; font-size: 1.1em;">' +
                '<span class="dashicons dashicons-location" style="color: #64748b; margin-right: 5px;"></span>' +
                escapeHtml(location.name) + '</h4>';
            html += '<span class="location-count" style="background: #e2e8f0; padding: 2px 8px; border-radius: 10px; font-size: 0.85em; color: #475569;">' +
                location.items.length + ' itens</span>';
            html += '</div>';

            html += '<div class="location-items" style="padding: 0;">';
            html += '<table class="wp-list-table widefat fixed striped" style="border: none; box-shadow: none;">';
            html += '<thead><tr>';
            html += '<th style="padding-left: 20px;">Produto</th>';
            html += '<th>SKU</th>';
            html += '<th>Qtd Total</th>';
            html += '<th>Lotes</th>';
            html += '</tr></thead>';
            html += '<tbody>';

            if (location.items.length === 0) {
                html += '<tr><td colspan="4" style="padding: 15px 20px; color: #94a3b8;">Nenhum item neste local.</td></tr>';
            } else {
                location.items.forEach(function (item) {
                    html += '<tr>';
                    html += '<td style="padding-left: 20px;"><strong>' + escapeHtml(item.name) + '</strong></td>';
                    html += '<td>' + (item.sku ? escapeHtml(item.sku) : '-') + '</td>';
                    html += '<td><span style="font-weight:bold; color: #2563eb;">' +
                        formatNumber(item.total_quantity) + '</span> ' + escapeHtml(item.unit || '') + '</td>';

                    html += '<td>';
                    if (item.batches && item.batches.length > 0) {
                        html += '<div style="font-size: 0.85em; color: #64748b;">';
                        item.batches.forEach(function (batch, index) {
                            html += '<div>';
                            if (batch.batch_code) html += '<span style="background:#f1f5f9; padding:0 4px; border-radius:3px;">' + escapeHtml(batch.batch_code) + '</span> ';
                            html += formatNumber(batch.quantity) + (item.unit ? ' ' + item.unit : '');
                            if (batch.expiry) html += ' <span style="color:#ef4444;">(Val: ' + formatDate(batch.expiry) + ')</span>';
                            html += '</div>';
                        });
                        html += '</div>';
                    } else {
                        html += '-';
                    }
                    html += '</td>';
                    html += '</tr>';
                });
            }

            html += '</tbody></table>';
            html += '</div></div>';
        });

        $content.html(html);
    }

    function escapeHtml(text) {
        if (!text) return '';
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.toString().replace(/[&<>"']/g, function (m) { return map[m]; });
    }

    function formatNumber(num) {
        return parseFloat(num).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
    }

    function formatDate(dateString) {
        if (!dateString) return '';
        var parts = dateString.split('-');
        if (parts.length === 3) return parts[2] + '/' + parts[1] + '/' + parts[0]; // YYYY-MM-DD to DD/MM/YYYY
        return dateString;
    }
});
