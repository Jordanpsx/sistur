/**
 * SISTUR Stock Transfer - JavaScript Module
 * 
 * Handles stock transfer between locations.
 * 
 * @package SISTUR
 * @since 2.10.0
 */

const SisturStockTransfer = (function ($) {
    'use strict';

    // State
    let products = [];
    let locations = [];
    let locationsHierarchy = [];
    let locationProducts = {};
    let sourceLocationId = null;

    /**
     * Initialize
     */
    function init() {
        console.log('SISTUR Stock Transfer Initialized');
        bindEvents();

        if (typeof sisturStockData !== 'undefined') {
            products = sisturStockData.products || [];
            locations = sisturStockData.locations || [];
            locationsHierarchy = sisturStockData.locationsHierarchy || [];
            locationProducts = sisturStockData.locationProducts || {};

            console.log('📦 locationProducts:', locationProducts);
            console.log('📍 locationsHierarchy:', locationsHierarchy);

            initLocationSelects();
        }
    }

    /**
     * Bind global events (modal open/close, add/remove product, submit)
     */
    function bindEvents() {
        // Open Modal
        $('#btn-open-transfer').on('click', function (e) {
            e.preventDefault();
            openModal();
        });

        // Close Modal
        $(document).on('click', '#modal-transfer .modal-close, #modal-transfer .btn-cancel', function (e) {
            e.preventDefault();
            closeModal();
        });

        // Add Product Row
        $('#btn-add-transfer-product').on('click', function (e) {
            e.preventDefault();
            addProductRow();
        });

        // Remove Product Row
        $(document).on('click', '.btn-remove-transfer-product', function (e) {
            e.preventDefault();
            $(this).closest('tr').remove();
        });

        // Submit Transfer
        $('#btn-confirm-transfer').on('click', function (e) {
            e.preventDefault();
            submitTransfer();
        });
    }

    /**
     * Init Location Selects - populates both source and dest dropdowns
     */
    function initLocationSelects() {
        const $sourceLoc = $('#transfer-source-location');
        const $destLoc = $('#transfer-dest-location');

        let options = '<option value="">Selecione o Local...</option>';

        $.each(locationsHierarchy, function (idx, loc) {
            const hasSectors = (loc.sectors && loc.sectors.length > 0) ? '1' : '0';
            options += '<option value="' + loc.id + '" data-has-sectors="' + hasSectors + '">' + loc.name + '</option>';
        });

        $sourceLoc.html(options);
        $destLoc.html(options);

        // Bind cascading change events
        bindLocationChangeEvents('source');
        bindLocationChangeEvents('dest');
    }

    /**
     * Bind Location Change Events for cascading sector selects
     */
    function bindLocationChangeEvents(type) {
        const $locSelect = $('#transfer-' + type + '-location');
        const $sectorSelect = $('#transfer-' + type + '-sector');
        const $sectorWrapper = $('#wrapper-' + type + '-sector');

        // Parent Location Change
        $locSelect.on('change', function () {
            const locationId = $(this).val();
            const hasSectors = $(this).find(':selected').data('has-sectors');

            // Reset sector
            $sectorSelect.empty().append('<option value="">Selecione o Setor...</option>');

            // Update sourceLocationId for product filtering
            if (type === 'source') {
                sourceLocationId = locationId;
                console.log('🔄 Source location changed to:', sourceLocationId);
                refreshProductRows();
            }

            // Check if location has sectors (data attribute is '1' or '0')
            if (hasSectors === 1 || hasSectors === '1') {
                const locData = locationsHierarchy.find(function (l) {
                    return String(l.id) === String(locationId);
                });

                if (locData && locData.sectors) {
                    locData.sectors.forEach(function (sec) {
                        $sectorSelect.append('<option value="' + sec.id + '">' + sec.name + '</option>');
                    });
                }

                $sectorWrapper.show();
            } else {
                $sectorWrapper.hide();
            }
        });

        // Sector Change
        $sectorSelect.on('change', function () {
            if (type === 'source') {
                const sectorId = $(this).val();
                if (sectorId) {
                    sourceLocationId = sectorId;
                } else {
                    sourceLocationId = $locSelect.val();
                }
                console.log('🔄 Source sector changed, final sourceLocationId:', sourceLocationId);
                refreshProductRows();
            }
        });
    }

    /**
     * Get filtered product options based on sourceLocationId
     */
    function getProductOptions() {
        let options = '';
        let validProductIds = null;

        // If a source location is selected, check if we have products mapped to it
        if (sourceLocationId && locationProducts) {
            const key = String(sourceLocationId);
            if (locationProducts[key]) {
                validProductIds = locationProducts[key].map(function (id) {
                    return parseInt(id);
                });
            }
        }

        console.log('🔍 Filtering products for location:', sourceLocationId, 'validProductIds:', validProductIds);

        products.forEach(function (p) {
            if (validProductIds !== null) {
                if (validProductIds.indexOf(parseInt(p.id)) === -1) {
                    return; // Skip - not in this location
                }
            }
            options += '<option value="' + p.id + '">' + p.name + ' (SKU: ' + (p.sku || '-') + ')</option>';
        });

        if (validProductIds !== null && options === '') {
            options = '<option value="" disabled>Nenhum produto neste local</option>';
        }

        return options;
    }

    /**
     * Add Product Row to the transfer table
     */
    function addProductRow() {
        const rowId = Date.now();
        const productOpts = getProductOptions();
        const html =
            '<tr data-row-id="' + rowId + '">' +
            '<td>' +
            '<select class="transfer-product-select" name="products[' + rowId + '][id]" style="width: 100%; max-width: 300px;">' +
            '<option value="">Selecione o produto...</option>' +
            productOpts +
            '</select>' +
            '</td>' +
            '<td>' +
            '<input type="number" class="transfer-product-qty" name="products[' + rowId + '][qty]" step="0.001" min="0.001" style="width: 100px;">' +
            '</td>' +
            '<td>' +
            '<button type="button" class="button btn-remove-transfer-product" title="Remover">' +
            '<span class="dashicons dashicons-trash"></span>' +
            '</button>' +
            '</td>' +
            '</tr>';

        $('#transfer-products-tbody').append(html);
    }

    /**
     * Refresh all product select dropdowns (called when source location changes)
     */
    function refreshProductRows() {
        var newOptions = '<option value="">Selecione o produto...</option>' + getProductOptions();

        $('.transfer-product-select').each(function () {
            var currentVal = $(this).val();
            $(this).html(newOptions);

            // Try to restore previous selection
            if (currentVal) {
                var $option = $(this).find('option[value="' + currentVal + '"]');
                if ($option.length) {
                    $(this).val(currentVal);
                } else {
                    $(this).val(''); // Product no longer available in this location
                }
            }
        });
    }

    /**
     * Open Modal
     */
    function openModal() {
        // Reset state
        sourceLocationId = null;

        $('#transfer-source-location').val('');
        $('#transfer-dest-location').val('');
        $('#wrapper-source-sector').hide();
        $('#wrapper-dest-sector').hide();
        $('#transfer-products-tbody').empty();

        $('#modal-transfer').fadeIn();
        addProductRow();
    }

    /**
     * Close Modal
     */
    function closeModal() {
        $('#modal-transfer').fadeOut();
    }

    /**
     * Submit Transfer
     */
    function submitTransfer() {
        // Helper to get final location ID (sector has priority over parent)
        function getFinalLocationId(type) {
            var $loc = $('#transfer-' + type + '-location');
            var $sector = $('#transfer-' + type + '-sector');
            var $wrapper = $('#wrapper-' + type + '-sector');

            // If sector wrapper is visible
            if ($wrapper.is(':visible')) {
                if ($sector.val()) {
                    return $sector.val();
                }
                return null; // Sector required but not selected
            }

            return $loc.val();
        }

        var sourceId = getFinalLocationId('source');
        var destId = getFinalLocationId('dest');

        if (!sourceId || !destId) {
            alert('Selecione origem e destino (e o setor, se aplicável).');
            return;
        }

        if (sourceId === destId) {
            alert('Origem e destino devem ser diferentes.');
            return;
        }

        var items = [];
        $('#transfer-products-tbody tr').each(function () {
            var productId = $(this).find('.transfer-product-select').val();
            var qty = $(this).find('.transfer-product-qty').val();

            if (productId && qty > 0) {
                items.push({
                    product_id: parseInt(productId),
                    quantity: parseFloat(qty)
                });
            }
        });

        if (items.length === 0) {
            alert('Adicione pelo menos um produto e quantidade.');
            return;
        }

        var btn = $('#btn-confirm-transfer');
        var originalText = btn.text();
        btn.prop('disabled', true).text('Processando...');

        $.ajax({
            url: sisturPublic.api_root + 'sistur/v1/stock/transfer',
            type: 'POST',
            contentType: 'application/json',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', sisturPublic.api_nonce);
            },
            data: JSON.stringify({
                from_location_id: parseInt(sourceId),
                to_location_id: parseInt(destId),
                products: items
            }),
            success: function (response) {
                if (response.success) {
                    alert('Transferência realizada com sucesso!');
                    closeModal();
                    location.reload();
                } else {
                    alert('Erro: ' + (response.data ? response.data.message : 'Desconhecido'));
                }
            },
            error: function (xhr) {
                var msg = 'Erro de conexão com o servidor.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = 'Erro: ' + xhr.responseJSON.message;
                }
                alert(msg);
            },
            complete: function () {
                btn.prop('disabled', false).text(originalText);
            }
        });
    }

    return {
        init: init
    };

})(jQuery);

jQuery(document).ready(function () {
    SisturStockTransfer.init();
});
