/**
 * SISTUR Stock Management - JavaScript Module
 * 
 * Handles all frontend interactions for the stock management admin page.
 * Uses vanilla JavaScript with Fetch API.
 * 
 * @package SISTUR
 * @since 2.1.0
 */

const SisturStock = (function () {
    'use strict';

    // State
    let currentPage = 1;
    let currentSearch = '';
    let currentType = '';
    let productsCache = [];
    let unitsCache = [];
    let locationsCache = [];
    let currentProductId = null;
    let ingredientsCache = [];

    /**
     * Initialize the module
     */
    function init() {
        loadUnits();
        loadLocations();
        loadResourceProducts(); // v2.4 - Load RESOURCE products for dropdown
        loadProducts();
        bindEvents();
    }

    /**
     * Show a notification message to the user
     * @param {string} message - Message to display
     * @param {string} type - 'success', 'error', or 'info'
     */
    function showNotice(message, type = 'info') {
        // Remove existing notices
        const existing = document.querySelectorAll('.sistur-notice');
        existing.forEach(el => el.remove());

        // Create notice element
        const notice = document.createElement('div');
        notice.className = `sistur-notice notice notice-${type === 'error' ? 'error' : type === 'success' ? 'success' : 'info'} is-dismissible`;
        notice.innerHTML = `
            <p>${message}</p>
            <button type="button" class="notice-dismiss" onclick="this.parentElement.remove()">
                <span class="screen-reader-text">Fechar</span>
            </button>
        `;
        notice.style.cssText = `
            position: fixed;
            top: 40px;
            right: 20px;
            z-index: 100001;
            min-width: 300px;
            max-width: 500px;
            box-shadow: 0 3px 5px rgba(0,0,0,0.2);
            animation: slideIn 0.3s ease;
        `;

        // Add animation keyframes if not exists
        if (!document.getElementById('sistur-notice-style')) {
            const style = document.createElement('style');
            style.id = 'sistur-notice-style';
            style.textContent = `
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
            `;
            document.head.appendChild(style);
        }

        document.body.appendChild(notice);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (notice.parentElement) {
                notice.style.animation = 'slideIn 0.3s ease reverse';
                setTimeout(() => notice.remove(), 300);
            }
        }, 5000);
    }

    /**
     * Bind all event handlers
     */
    function bindEvents() {
        // Filter button
        document.getElementById('btn-filter')?.addEventListener('click', function () {
            currentSearch = document.getElementById('filter-search').value;
            currentType = document.getElementById('filter-type').value;
            currentPage = 1;
            loadProducts();
        });

        // Search on Enter
        document.getElementById('filter-search')?.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                document.getElementById('btn-filter').click();
            }
        });

        // New Product button
        document.getElementById('btn-new-product')?.addEventListener('click', function (e) {
            e.preventDefault();
            openProductModal();
        });

        // Save Product button
        document.getElementById('btn-save-product')?.addEventListener('click', saveProduct);

        // New Movement button
        document.getElementById('btn-new-movement')?.addEventListener('click', function (e) {
            e.preventDefault();
            openMovementModal();
        });

        // Save Movement button
        document.getElementById('btn-save-movement')?.addEventListener('click', saveMovement);

        // Movement type change
        document.getElementById('movement-type')?.addEventListener('change', function () {
            const entryFields = document.querySelectorAll('.entry-fields');
            const isEntry = this.value === 'IN';
            entryFields.forEach(el => {
                el.style.display = isEntry ? '' : 'none';
            });
        });

        // Movement product change
        document.getElementById('movement-product')?.addEventListener('change', function () {
            const productId = this.value;
            const product = productsCache.find(p => p.id == productId);
            const infoEl = document.getElementById('product-stock-info');

            if (product && infoEl) {
                const stockClass = parseFloat(product.real_stock) < parseFloat(product.min_stock) ? 'stock-low' : 'stock-ok';
                infoEl.innerHTML = `Estoque atual: <strong class="${stockClass}">${formatNumber(product.real_stock)} ${product.unit_symbol || ''}</strong>`;
            } else if (infoEl) {
                infoEl.innerHTML = '';
            }

            // v2.13.0 - Perishability Handling
            // Check inherited status (effective_perishable) or own status
            // If effective_perishable is 0, disable expiry date
            const isPerishable = (product && product.effective_perishable !== undefined)
                ? (parseInt(product.effective_perishable) === 1)
                : (product && product.is_perishable !== undefined ? parseInt(product.is_perishable) === 1 : true);

            const expiryInput = document.getElementById('movement-expiry');
            if (expiryInput) {
                if (!isPerishable) {
                    expiryInput.disabled = true;
                    expiryInput.value = '';
                    expiryInput.placeholder = 'Não perecível';
                    expiryInput.title = 'Produto marcado como Não Perecível (via Generalização)';
                } else {
                    expiryInput.disabled = false;
                    expiryInput.placeholder = '';
                    expiryInput.title = '';
                }
            }
        });

        // [v2.11.0] Default Location Change -> Populate Sectors
        document.getElementById('product-default-location')?.addEventListener('change', function () {
            const locId = parseInt(this.value);
            const sectorSelect = document.getElementById('product-default-sector');
            if (!sectorSelect) return;

            sectorSelect.innerHTML = '<option value="">Selecione o setor...</option>';

            if (!locId) return;

            const location = locationsCache.find(l => l.id === locId);
            if (location && location.sectors) {
                location.sectors.forEach(sec => {
                    sectorSelect.innerHTML += `<option value="${sec.id}">${sec.name}</option>`;
                });
            }
        });

        // Modal close buttons
        document.querySelectorAll('.sistur-modal-close, .sistur-modal-cancel').forEach(btn => {
            btn.addEventListener('click', function () {
                this.closest('.sistur-modal').style.display = 'none';
            });
        });

        // Close modal on background click
        document.querySelectorAll('.sistur-modal').forEach(modal => {
            modal.addEventListener('click', function (e) {
                if (e.target === this) {
                    this.style.display = 'none';
                }
            });
        });

        // ESC key closes modals
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.sistur-modal').forEach(modal => {
                    modal.style.display = 'none';
                });
            }
        });

        // ===== TABS =====
        document.querySelectorAll('.sistur-tab').forEach(tab => {
            tab.addEventListener('click', function () {
                const tabId = this.dataset.tab;
                switchTab(tabId);
            });
        });

        // v2.6 - Product type checkboxes change (show/hide Composição tab)
        document.querySelectorAll('input[name="types[]"]').forEach(checkbox => {
            checkbox.addEventListener('change', function () {
                const selectedTypes = getSelectedProductTypes();
                updateCompositionTabVisibility(selectedTypes.join(','));
            });
        });

        // ===== RECIPE MANAGEMENT =====
        document.getElementById('btn-add-ingredient')?.addEventListener('click', openIngredientModal);
        document.getElementById('btn-save-ingredient')?.addEventListener('click', saveIngredient);

        // Ingredient search
        const searchInput = document.getElementById('ingredient-search');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => searchIngredients(this.value), 300);
            });

            // Hide dropdown on blur with delay
            searchInput.addEventListener('blur', function () {
                setTimeout(() => {
                    document.getElementById('ingredient-search-results').style.display = 'none';
                }, 200);
            });
        }

        // Auto-calculate gross weight
        ['ingredient-net', 'ingredient-factor'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', calculateGrossWeight);
        });
    }

    /**
     * API call helper
     */
    async function apiCall(endpoint, options = {}) {
        const url = SisturStockConfig.apiBase + endpoint;
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': SisturStockConfig.nonce
            }
        };

        const response = await fetch(url, { ...defaultOptions, ...options });
        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || SisturStockConfig.i18n.error);
        }

        return data;
    }

    /**
     * API call helper for recipes (uses different base URL)
     */
    async function recipesApiCall(endpoint, options = {}) {
        const url = SisturStockConfig.recipesApiBase + endpoint;
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': SisturStockConfig.nonce
            }
        };

        const response = await fetch(url, { ...defaultOptions, ...options });
        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || SisturStockConfig.i18n.error);
        }

        return data;
    }

    // ===== TABS SYSTEM =====

    function switchTab(tabId) {
        // Update tab buttons
        document.querySelectorAll('.sistur-tab').forEach(t => t.classList.remove('active'));
        document.querySelector(`.sistur-tab[data-tab="${tabId}"]`)?.classList.add('active');

        // Update tab contents
        document.querySelectorAll('.sistur-tab-content').forEach(c => c.classList.remove('active'));
        document.getElementById(tabId)?.classList.add('active');

        // Load recipe if switching to composition
        if (tabId === 'tab-composicao' && currentProductId) {
            loadRecipeIngredients(currentProductId);
        }
    }

    function updateCompositionTabVisibility(productType) {
        const tabBtn = document.getElementById('tab-btn-composicao');
        if (tabBtn) {
            // Show for MANUFACTURED or BASE (both have recipes/composition)
            const hasRecipe = productType && (productType.includes('MANUFACTURED') || productType.includes('BASE'));
            tabBtn.style.display = hasRecipe ? '' : 'none';
        }
    }

    // ===== RECIPE MANAGEMENT =====

    async function loadRecipeIngredients(productId) {
        const tbody = document.getElementById('recipe-ingredients-tbody');
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;"><span class="spinner is-active" style="float: none;"></span></td></tr>';

        try {
            // Use recipes endpoint (goes to root of API)
            const ingredients = await recipesApiCall(`recipes/${productId}`);
            ingredientsCache = ingredients;
            renderRecipeTable(ingredients);
            await loadRecipeCost(productId);
        } catch (error) {
            console.error('Error loading recipe:', error);
            tbody.innerHTML = '<tr class="no-ingredients"><td colspan="6" style="text-align: center;">Erro ao carregar ingredientes.</td></tr>';
        }
    }

    function renderRecipeTable(ingredients) {
        const tbody = document.getElementById('recipe-ingredients-tbody');
        const badge = document.getElementById('recipe-count-badge');

        if (!ingredients || ingredients.length === 0) {
            tbody.innerHTML = `
                <tr class="no-ingredients">
                    <td colspan="6" style="text-align: center; padding: 20px;">
                        <span class="dashicons dashicons-info-outline" style="color: #999;"></span>
                        Nenhum ingrediente cadastrado.
                    </td>
                </tr>
            `;
            badge.textContent = '0';
            return;
        }

        badge.textContent = ingredients.length;

        tbody.innerHTML = ingredients.map(ing => {
            const unitCost = parseFloat(ing.average_cost) || parseFloat(ing.cost_price) || 0;
            const cost = (parseFloat(ing.quantity_gross) * unitCost).toFixed(4);

            return `
                <tr data-id="${ing.id}">
                    <td>
                        <strong>${escapeHtml(ing.ingredient_name)}</strong>
                        ${ing.ingredient_sku ? `<br><small><code>${escapeHtml(ing.ingredient_sku)}</code></small>` : ''}
                    </td>
                    <td>${formatNumber(ing.quantity_net)} ${ing.unit_symbol || ''}</td>
                    <td>×${formatNumber(ing.yield_factor, 2)}</td>
                    <td><strong>${formatNumber(ing.quantity_gross)}</strong> ${ing.unit_symbol || ''}</td>
                    <td>R$ ${formatNumber(cost, 4)}</td>
                    <td>
                        <button type="button" class="button button-small" onclick="SisturStock.deleteIngredient(${ing.id})" title="Remover">
                            <span class="dashicons dashicons-trash" style="font-size: 14px; width: 14px; height: 14px;"></span>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    async function loadRecipeCost(productId) {
        try {
            const cost = await recipesApiCall(`recipes/${productId}/cost`);
            document.getElementById('recipe-total-cost').textContent =
                'R$ ' + formatNumber(cost.total_cost || 0, 2);
        } catch (error) {
            console.error('Error loading cost:', error);
        }
    }

    function openIngredientModal() {
        const modal = document.getElementById('modal-ingredient');
        const form = document.getElementById('form-ingredient');

        form.reset();
        document.getElementById('ingredient-product-id').value = '';
        document.getElementById('ingredient-selected').textContent = '';
        document.getElementById('calculated-gross').textContent = '0.000';
        document.getElementById('ingredient-factor').value = '1.00';

        // Populate unit dropdown
        const unitSelect = document.getElementById('ingredient-unit');
        unitSelect.innerHTML = '<option value="">Unidade</option>';
        unitsCache.forEach(u => {
            unitSelect.innerHTML += `<option value="${u.id}">${u.symbol}</option>`;
        });

        modal.style.display = 'flex';
        document.getElementById('ingredient-search').focus();
    }

    async function searchIngredients(query) {
        const resultsDiv = document.getElementById('ingredient-search-results');

        if (!query || query.length < 2) {
            resultsDiv.style.display = 'none';
            return;
        }

        try {
            // v2.9.2 - Buscar apenas produtos RESOURCE (tipos genéricos) para receitas FEFO
            const response = await apiCall(`products?search=${encodeURIComponent(query)}&has_type=RESOURCE&per_page=10`);
            const products = response.products || [];

            // Excluir o produto atual (não pode ser ingrediente de si mesmo)
            const filteredProducts = products.filter(p => p.id != currentProductId);

            if (filteredProducts.length === 0) {
                resultsDiv.innerHTML = '<div class="search-dropdown-item"><em>Nenhum produto encontrado</em></div>';
            } else {
                resultsDiv.innerHTML = filteredProducts.map(p => {
                    const typeLabel = getTypeLabel(p.type);
                    return `
                    <div class="search-dropdown-item" onclick="SisturStock.selectIngredient(${p.id}, '${escapeHtml(p.name)}', '${escapeHtml(p.sku || '')}')">
                        <div class="item-name">${escapeHtml(p.name)} <span class="type-badge type-${p.type}" style="font-size: 10px; padding: 1px 5px;">${typeLabel}</span></div>
                        <div class="item-details">${p.sku || 'Sem SKU'} | Estoque: ${formatNumber(p.real_stock)} | Custo: R$ ${formatNumber(p.average_cost || p.cost_price || 0, 4)}</div>
                    </div>
                `}).join('');
            }

            resultsDiv.style.display = 'block';
        } catch (error) {
            console.error('Search error:', error);
            resultsDiv.style.display = 'none';
        }
    }

    function selectIngredient(id, name, sku) {
        document.getElementById('ingredient-product-id').value = id;
        document.getElementById('ingredient-search').value = name;
        document.getElementById('ingredient-selected').innerHTML = `<strong>✓ Selecionado:</strong> ${name} ${sku ? `(${sku})` : ''}`;
        document.getElementById('ingredient-search-results').style.display = 'none';
        document.getElementById('ingredient-net').focus();
    }

    function calculateGrossWeight() {
        const net = parseFloat(document.getElementById('ingredient-net').value) || 0;
        const factor = parseFloat(document.getElementById('ingredient-factor').value) || 1;
        const gross = factor > 0 ? (net / factor).toFixed(3) : '0.000';
        document.getElementById('calculated-gross').textContent = gross;
    }

    async function saveIngredient() {
        const childId = document.getElementById('ingredient-product-id').value;
        const quantityNet = document.getElementById('ingredient-net').value;
        const yieldFactor = document.getElementById('ingredient-factor').value;
        const unitId = document.getElementById('ingredient-unit').value;

        if (!childId || !quantityNet) {
            showNotice('Selecione um ingrediente e informe o peso líquido', 'error');
            return;
        }

        console.log('SISTUR saveIngredient: currentProductId =', currentProductId, 'childId =', childId);

        if (!currentProductId) {
            showNotice('Erro: produto não identificado. Por favor, feche e abra novamente o modal de edição.', 'error');
            return;
        }

        const btn = document.getElementById('btn-save-ingredient');
        btn.disabled = true;
        btn.textContent = 'Adicionando...';

        try {
            await recipesApiCall(`recipes/${currentProductId}`, {
                method: 'POST',
                body: JSON.stringify({
                    child_product_id: childId,
                    quantity_net: quantityNet,
                    yield_factor: yieldFactor || 1,
                    unit_id: unitId || null
                })
            });

            closeModal('modal-ingredient');
            loadRecipeIngredients(currentProductId);
            showNotice('Ingrediente adicionado!', 'success');
        } catch (error) {
            showNotice(error.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Adicionar';
        }
    }

    async function deleteIngredient(id) {
        if (!confirm('Remover este ingrediente da receita?')) return;

        try {
            await recipesApiCall(`recipes/ingredient/${id}`, { method: 'DELETE' });
            loadRecipeIngredients(currentProductId);
            showNotice('Ingrediente removido!', 'success');
        } catch (error) {
            showNotice(error.message, 'error');
        }
    }

    // ===== PRODUCTS =====

    async function loadProducts() {
        const tbody = document.getElementById('products-tbody');
        tbody.innerHTML = `<tr class="loading-row"><td colspan="7" style="text-align: center; padding: 40px;"><span class="spinner is-active" style="float: none;"></span> ${SisturStockConfig.i18n.loading}</td></tr>`;

        try {
            const params = new URLSearchParams({
                page: currentPage,
                per_page: 20,
                search: currentSearch,
                type: currentType,
                exclude_type: 'RESOURCE' // v2.4 - RESOURCE products are managed in Tipos de Produtos
            });

            const response = await apiCall('products?' + params.toString());
            productsCache = response.products;

            renderProductsTable(response.products);
            renderPagination(response.total, response.total_pages, response.page);
            updateSummary(response.total);

        } catch (error) {
            console.error('Error loading products:', error);
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: #d63638;">${error.message}</td></tr>`;
        }
    }

    function renderProductsTable(products) {
        const tbody = document.getElementById('products-tbody');

        if (!products || products.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 20px;">${SisturStockConfig.i18n.noProducts}</td></tr>`;
            return;
        }

        tbody.innerHTML = products.map(product => {
            const stock = parseFloat(product.real_stock) || 0;
            const minStock = parseFloat(product.min_stock) || 0;
            const stockClass = stock < minStock ? 'stock-low' : 'stock-ok';
            const typeLabel = getTypeLabel(product.type);

            return `
                <tr data-id="${product.id}">
                    <td class="column-sku"><code>${escapeHtml(product.sku || '-')}</code></td>
                    <td class="column-name">
                        <strong>${escapeHtml(product.name)}</strong>
                        ${product.category_name ? `<br><small class="description">${escapeHtml(product.category_name)}</small>` : ''}
                    </td>
                    <td class="column-type"><span class="type-badge type-${product.type}">${typeLabel}</span></td>
                    <td class="column-unit">${escapeHtml(product.unit_symbol || '-')}</td>
                    <td class="column-stock" style="text-align: right;">
                        <span class="${stockClass}">${formatNumber(stock)}</span>
                    </td>
                    <td class="column-cost" style="text-align: right;">
                        ${product.average_cost ? 'R$ ' + formatNumber(product.average_cost, 4) : '-'}
                    </td>
                    <td class="column-actions">
                        <button type="button" class="button button-small" onclick="SisturStock.editProduct(${product.id})" title="Editar">
                            <span class="dashicons dashicons-edit" style="font-size: 14px; width: 14px; height: 14px;"></span>
                        </button>
                        <button type="button" class="button button-small" onclick="SisturStock.viewBatches(${product.id}, '${escapeHtml(product.name)}')" title="Ver Lotes">
                            <span class="dashicons dashicons-archive" style="font-size: 14px; width: 14px; height: 14px;"></span>
                        </button>
                        <button type="button" class="button button-small" onclick="SisturStock.quickMovement(${product.id})" title="Movimentar">
                            <span class="dashicons dashicons-update" style="font-size: 14px; width: 14px; height: 14px;"></span>
                        </button>
                        <button type="button" class="button button-small" onclick="SisturStock.openLossModal(${product.id}, '${escapeHtml(product.name)}', ${product.real_stock || 0}, '${escapeHtml(product.unit_symbol || '')}')" title="Registrar Perda" style="background: #d63638; border-color: #d63638; color: white;">
                            <span class="dashicons dashicons-warning" style="font-size: 14px; width: 14px; height: 14px;"></span>
                        </button>
                        ${product.type && product.type.includes('MANUFACTURED') ? `
                        <button type="button" class="button button-small" onclick="SisturStock.openProductionModal(${product.id}, '${escapeHtml(product.name)}', '${escapeHtml(product.unit_symbol || '')}')" title="Produzir" style="background: #46b450; border-color: #46b450; color: white;">
                            <span class="dashicons dashicons-hammer" style="font-size: 14px; width: 14px; height: 14px;"></span>
                        </button>
                        ` : ''}
                    </td>
                </tr>
            `;
        }).join('');
    }

    function renderPagination(total, totalPages, page) {
        const container = document.getElementById('pagination-container');

        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }

        let html = `<span class="displaying-num">${total} itens</span>`;
        html += '<span class="pagination-links">';

        if (page > 1) {
            html += `<a class="first-page button" href="#" onclick="SisturStock.goToPage(1); return false;">«</a> `;
            html += `<a class="prev-page button" href="#" onclick="SisturStock.goToPage(${page - 1}); return false;">‹</a> `;
        } else {
            html += `<span class="tablenav-pages-navspan button disabled">«</span> `;
            html += `<span class="tablenav-pages-navspan button disabled">‹</span> `;
        }

        html += `<span class="paging-input">${page} de <span class="total-pages">${totalPages}</span></span>`;

        if (page < totalPages) {
            html += ` <a class="next-page button" href="#" onclick="SisturStock.goToPage(${page + 1}); return false;">›</a>`;
            html += ` <a class="last-page button" href="#" onclick="SisturStock.goToPage(${totalPages}); return false;">»</a>`;
        } else {
            html += ` <span class="tablenav-pages-navspan button disabled">›</span>`;
            html += ` <span class="tablenav-pages-navspan button disabled">»</span>`;
        }

        html += '</span>';
        container.innerHTML = html;
    }

    function updateSummary(total) {
        const el = document.getElementById('stock-summary');
        if (el) {
            el.textContent = `${total} produto(s) encontrado(s)`;
        }
    }

    function goToPage(page) {
        currentPage = page;
        loadProducts();
    }

    async function loadUnits() {
        try {
            const units = await apiCall('units');
            unitsCache = units;

            // Populate base unit dropdown
            const selects = document.querySelectorAll('#product-unit');
            selects.forEach(select => {
                const currentValue = select.value;
                select.innerHTML = '<option value="">Selecione...</option>';
                units.forEach(unit => {
                    select.innerHTML += `<option value="${unit.id}">${unit.name} (${unit.symbol})</option>`;
                });
                if (currentValue) select.value = currentValue;
            });

            // Populate content unit dropdown (v2.3 - Conteúdo da Embalagem)
            const contentUnitSelect = document.getElementById('product-content-unit');
            if (contentUnitSelect) {
                const currentValue = contentUnitSelect.value;
                contentUnitSelect.innerHTML = '<option value="">Unidade...</option>';
                // Filter to only dimensional units (Litros, kg, g, ml) for content
                units.filter(u => u.type === 'dimensional').forEach(unit => {
                    contentUnitSelect.innerHTML += `<option value="${unit.id}">${unit.name} (${unit.symbol})</option>`;
                });
                if (currentValue) contentUnitSelect.value = currentValue;
            }
        } catch (error) {
            console.error('Error loading units:', error);
        }
    }

    /**
     * Load RESOURCE products for parent dropdown (v2.4)
     */
    async function loadResourceProducts() {
        try {
            const response = await apiCall('products?type=RESOURCE&per_page=100');
            const resources = response.products || [];

            const select = document.getElementById('product-resource-parent');
            if (select) {
                const currentValue = select.value;
                select.innerHTML = '<option value="">Nenhum (produto independente)</option>';
                resources.forEach(res => {
                    select.innerHTML += `<option value="${res.id}">${res.name}</option>`;
                });
                if (currentValue) select.value = currentValue;
            }
        } catch (error) {
            console.error('Error loading resource products:', error);
        }
    }

    async function loadLocations() {
        try {
            const locations = await apiCall('locations');
            locationsCache = locations;

            const selects = document.querySelectorAll('#movement-location');
            selects.forEach(select => {
                select.innerHTML = '<option value="">Padrão</option>';
                locations.forEach(loc => {
                    if (loc.sectors && loc.sectors.length > 0) {
                        // Local com setores: mostrar setores como opções agrupadas
                        const group = document.createElement('optgroup');
                        group.label = loc.name;
                        loc.sectors.forEach(sector => {
                            const opt = document.createElement('option');
                            opt.value = sector.id;
                            opt.textContent = sector.name;
                            group.appendChild(opt);
                        });
                        select.appendChild(group);
                    } else {
                        // Local sem setores: mostrar diretamente
                        select.innerHTML += `<option value="${loc.id}">${loc.name}</option>`;
                    }
                });
            });

            // [v2.11.0] Popular select de local padrão do produto
            const defaultLocSelect = document.getElementById('product-default-location');
            if (defaultLocSelect) {
                defaultLocSelect.innerHTML = '<option value="">Selecione o local...</option>';
                locations.forEach(loc => {
                    defaultLocSelect.innerHTML += `<option value="${loc.id}">${loc.name}</option>`;
                });
            }
        } catch (error) {
            console.error('Error loading locations:', error);
        }
    }

    function openProductModal(product = null) {
        const modal = document.getElementById('modal-product');
        const form = document.getElementById('form-product');
        const title = document.getElementById('modal-product-title');

        form.reset();
        document.getElementById('product-id').value = '';
        currentProductId = null;

        // Reset tabs
        switchTab('tab-dados');

        if (product) {
            title.textContent = 'Editar Produto';
            currentProductId = parseInt(product.id, 10);
            console.log('SISTUR: Editando produto ID:', currentProductId, 'Nome:', product.name);

            document.getElementById('product-id').value = product.id;
            document.getElementById('product-name').value = product.name || '';
            document.getElementById('product-sku').value = product.sku || '';
            document.getElementById('product-barcode').value = product.barcode || '';

            // v2.13.0 - Perishability (RESOURCE only)
            const isPerishableCb = document.getElementById('product-is-perishable');
            const rowPerishable = document.getElementById('row-is-perishable');
            if (isPerishableCb) {
                // Default true
                isPerishableCb.checked = product.is_perishable !== undefined ? (parseInt(product.is_perishable) === 1) : true;

                // Show only if RESOURCE
                if (product.type && product.type.includes('RESOURCE')) {
                    rowPerishable.style.display = 'table-row';
                } else {
                    rowPerishable.style.display = 'none';
                }
            }

            // v2.6 - Múltiplos tipos: marcar checkboxes com base em product.type (SET string)
            setProductTypeCheckboxes(product.type || 'RAW');

            document.getElementById('product-unit').value = product.base_unit_id || '';
            document.getElementById('product-cost').value = product.cost_price || '';
            document.getElementById('product-price').value = product.selling_price || '';
            document.getElementById('product-min-stock').value = product.min_stock || '';
            document.getElementById('product-description').value = product.description || '';

            // Campos de conteúdo da embalagem (v2.3)
            const contentQtyField = document.getElementById('product-content-qty');
            const contentUnitField = document.getElementById('product-content-unit');
            if (contentQtyField) contentQtyField.value = product.content_quantity || '';
            if (contentUnitField) contentUnitField.value = product.content_unit_id || '';

            // Hierarquia de RESOURCE (v2.4)
            const resourceParentField = document.getElementById('product-resource-parent');
            if (resourceParentField) resourceParentField.value = product.resource_parent_id || '';

            // [v2.11.0] Localização padrão (setor -> local pai)
            const defaultLocSelect = document.getElementById('product-default-location');
            const defaultSecSelect = document.getElementById('product-default-sector');

            if (defaultLocSelect && defaultSecSelect) {
                if (product.default_sector_id) {
                    // Encontrar o local pai do setor (iterando cache que contém hierarquia)
                    let foundLoc = null;
                    for (const loc of locationsCache) {
                        const sector = (loc.sectors || []).find(s => s.id == product.default_sector_id);
                        if (sector) {
                            foundLoc = loc;
                            break;
                        }
                    }

                    if (foundLoc) {
                        defaultLocSelect.value = foundLoc.id;
                        // Disparar change para popular setores
                        defaultLocSelect.dispatchEvent(new Event('change'));
                        // Selecionar setor (timeout para garantir que change processou)
                        setTimeout(() => { defaultSecSelect.value = product.default_sector_id; }, 0);
                    }
                } else {
                    defaultLocSelect.value = '';
                    defaultSecSelect.innerHTML = '<option value="">Selecione o setor...</option>';
                }
            }

            // v2.6 - Show/hide composition tab based on MANUFACTURED being in types
            updateCompositionTabVisibility(product.type);

            // Hide unsaved warning and enable add button (product is saved)
            const unsavedWarning = document.getElementById('recipe-unsaved-warning');
            const addIngredientBtn = document.getElementById('btn-add-ingredient');
            if (unsavedWarning) unsavedWarning.style.display = 'none';
            if (addIngredientBtn) addIngredientBtn.disabled = false;

            // Clear recipe table
            document.getElementById('recipe-ingredients-tbody').innerHTML = '';
            document.getElementById('recipe-count-badge').textContent = '0';
            document.getElementById('recipe-total-cost').textContent = 'R$ 0,00';
        } else {
            title.textContent = 'Novo Produto';

            // v2.13.0 - Hide perishable option for new products until type is selected (or default to hidden)
            const rowPerishable = document.getElementById('row-is-perishable');
            const isPerishableCb = document.getElementById('product-is-perishable');
            if (rowPerishable) rowPerishable.style.display = 'none';
            if (isPerishableCb) isPerishableCb.checked = true;

            updateCompositionTabVisibility('RESALE');

            // Show unsaved warning and disable add button (product is NOT saved)
            const unsavedWarning = document.getElementById('recipe-unsaved-warning');
            const addIngredientBtn = document.getElementById('btn-add-ingredient');
            if (unsavedWarning) unsavedWarning.style.display = 'block';
            if (addIngredientBtn) addIngredientBtn.disabled = true;
        }

        modal.style.display = 'flex';
        document.getElementById('product-name').focus();
    }

    async function editProduct(productId) {
        try {
            const product = await apiCall('products/' + productId);
            openProductModal(product);
        } catch (error) {
            showNotice(error.message, 'error');
        }
    }

    async function saveProduct() {
        const form = document.getElementById('form-product');
        const productId = document.getElementById('product-id').value;
        const formData = new FormData(form);

        const data = {};
        formData.forEach((value, key) => {
            // Ignorar types[] pois vamos coletar separadamente
            if (key !== 'types[]' && value !== '') data[key] = value;
        });

        // v2.6 - Coletar tipos selecionados dos checkboxes
        const selectedTypes = getSelectedProductTypes();
        if (selectedTypes.length === 0) {
            showNotice('Selecione pelo menos um tipo para o produto', 'error');
            return;
        }
        data.type = selectedTypes; // Será convertido para 'RAW,MANUFACTURED' pela API

        // v2.13.0 - Is Perishable (checkbox)
        const isPerishableCb = document.getElementById('product-is-perishable');
        if (isPerishableCb) {
            data.is_perishable = isPerishableCb.checked ? 1 : 0;
        }

        const btn = document.getElementById('btn-save-product');
        btn.disabled = true;
        btn.textContent = 'Salvando...';

        try {
            let result;
            const isNew = !productId;
            // Verificar se o produto tem receita (MANUFACTURED ou BASE)
            const isManufactured = selectedTypes.includes('MANUFACTURED') || selectedTypes.includes('BASE');

            if (productId) {
                result = await apiCall('products/' + productId, {
                    method: 'PUT',
                    body: JSON.stringify(data)
                });
            } else {
                result = await apiCall('products', {
                    method: 'POST',
                    body: JSON.stringify(data)
                });
            }

            await loadProducts();

            // Para novos produtos MANUFACTURED, reabrir para adicionar ingredientes
            if (isNew && isManufactured && result.id) {
                showNotice('Produto criado! Agora você pode adicionar os ingredientes da receita.', 'success');
                // Buscar o produto atualizado e reabrir o modal
                const newProduct = await apiCall('products/' + result.id);
                openProductModal(newProduct);
                // Mudar para aba de composição
                switchTab('tab-composicao');
            } else {
                closeModal('modal-product');
                showNotice(SisturStockConfig.i18n.success, 'success');
            }

        } catch (error) {
            showNotice(error.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Salvar Produto';
        }
    }

    function openMovementModal(productId = null) {
        const modal = document.getElementById('modal-movement');
        const form = document.getElementById('form-movement');
        const productSelect = document.getElementById('movement-product');

        form.reset();
        document.getElementById('product-stock-info').innerHTML = '';

        productSelect.innerHTML = '<option value="">Selecione o produto...</option>';
        productsCache.forEach(product => {
            productSelect.innerHTML += `<option value="${product.id}">${product.name} (${product.sku || 'sem SKU'})</option>`;
        });

        if (productId) {
            productSelect.value = productId;
            productSelect.dispatchEvent(new Event('change'));
        }

        document.getElementById('movement-type').value = 'IN';
        document.querySelectorAll('.entry-fields').forEach(el => {
            el.style.display = '';
        });

        modal.style.display = 'flex';
        productSelect.focus();
    }

    function quickMovement(productId) {
        openMovementModal(productId);
    }

    async function saveMovement() {
        const form = document.getElementById('form-movement');
        const formData = new FormData(form);

        const data = {};
        formData.forEach((value, key) => {
            if (value !== '') data[key] = value;
        });

        // Normalizar campos numéricos (converter vírgula para ponto - BR locale)
        const numericFields = ['quantity', 'cost_price'];
        numericFields.forEach(field => {
            if (data[field]) {
                // "6,50" → "6.50" | "6.500,00" → "6500.00"
                data[field] = normalizeDecimal(data[field]);
            }
        });

        if (!data.product_id || !data.type || !data.quantity) {
            showNotice('Preencha todos os campos obrigatórios', 'error');
            return;
        }

        // Confirmação para custo zero em entradas
        if (data.type === 'IN' && (!data.cost_price || parseFloat(data.cost_price) === 0)) {
            const confirmZeroCost = confirm(
                '⚠️ Atenção: O custo unitário está zerado!\n\n' +
                'Produtos com custo zero NÃO serão considerados no cálculo do custo médio.\n\n' +
                'Deseja realmente inserir um produto com custo R$ 0,00?'
            );
            if (!confirmZeroCost) {
                return;
            }
        }

        const btn = document.getElementById('btn-save-movement');
        btn.disabled = true;
        btn.textContent = 'Registrando...';

        try {
            const response = await apiCall('movements', {
                method: 'POST',
                body: JSON.stringify(data)
            });

            closeModal('modal-movement');
            loadProducts();

            let msg = 'Movimentação registrada com sucesso!';
            if (response.details && response.details.batches_affected) {
                const batches = response.details.batches_affected;
                msg += ` (${batches.length} lote(s) afetado(s))`;
            }
            showNotice(msg, 'success');

        } catch (error) {
            showNotice(error.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Registrar Movimentação';
        }
    }

    async function viewBatches(productId, productName) {
        const modal = document.getElementById('modal-batches');
        const title = document.getElementById('modal-batches-title');
        const tbody = document.getElementById('batches-tbody');

        title.textContent = `Lotes: ${productName}`;
        tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 20px;"><span class="spinner is-active" style="float: none;"></span></td></tr>`;
        modal.style.display = 'flex';

        try {
            const batches = await apiCall('batches/' + productId);

            if (!batches || batches.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">Nenhum lote encontrado para este produto.</td></tr>';
                return;
            }

            tbody.innerHTML = batches.map(batch => {
                const statusClass = batch.status === 'active' ? 'batch-active' :
                    (batch.status === 'expired' ? 'batch-expired' : 'batch-depleted');
                const statusLabel = batch.status === 'active' ? 'Ativo' :
                    (batch.status === 'expired' ? 'Vencido' : 'Esgotado');

                return `
                    <tr>
                        <td>${escapeHtml(batch.batch_code || '-')}</td>
                        <td>${escapeHtml(batch.full_location_name || batch.sector_name || batch.location_name || '-')}</td>
                        <td>${batch.expiry_date ? formatDate(batch.expiry_date) : '-'}</td>
                        <td>R$ ${formatNumber(batch.cost_price, 4)}</td>
                        <td><strong>${formatNumber(batch.quantity)}</strong> / ${formatNumber(batch.initial_quantity)}</td>
                        <td><span class="${statusClass}">${statusLabel}</span></td>
                        <td>${formatDateTime(batch.created_at)}</td>
                    </tr>
                `;
            }).join('');

        } catch (error) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: #d63638;">${error.message}</td></tr>`;
        }
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) modal.style.display = 'none';
    }

    function showNotice(message, type = 'success') {
        if (typeof sisturToast !== 'undefined') {
            sisturToast.show(message, type);
            return;
        }

        const wrap = document.querySelector('.wrap');
        const notice = document.createElement('div');
        notice.className = `notice notice-${type} is-dismissible`;
        notice.innerHTML = `<p>${message}</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Fechar</span></button>`;

        document.querySelectorAll('.wrap > .notice').forEach(n => n.remove());
        wrap.insertBefore(notice, wrap.querySelector('hr.wp-header-end'));

        setTimeout(() => notice.remove(), 5000);
        notice.querySelector('.notice-dismiss')?.addEventListener('click', () => notice.remove());
    }

    // Utility functions
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Normaliza números no formato BR (1.500,00) para formato padrão (1500.00)
     * Trata: "6,50" → "6.50" | "1.500,00" → "1500.00" | "1500.00" → "1500.00"
     */
    function normalizeDecimal(value) {
        if (typeof value !== 'string') return value;

        // Se tem vírgula como decimal e ponto como milhar (BR): 1.500,00 → 1500.00
        if (value.includes(',') && value.includes('.')) {
            // Formato BR: 1.500,00 - remove pontos, troca vírgula por ponto
            return value.replace(/\./g, '').replace(',', '.');
        }

        // Se tem apenas vírgula (decimal BR): 6,50 → 6.50
        if (value.includes(',')) {
            return value.replace(',', '.');
        }

        // Já está no formato correto
        return value;
    }

    function formatNumber(value, decimals = 3) {
        return parseFloat(value || 0).toLocaleString('pt-BR', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
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

    function getTypeLabel(type) {
        // v2.6 - Suporte a múltiplos tipos (SET string "RAW,MANUFACTURED")
        const labels = {
            'RAW': 'Insumo',
            'RESALE': 'Revenda',
            'MANUFACTURED': 'Produzido',
            'BASE': 'Prato Base',
            'RESOURCE': 'Genérico'
        };

        if (!type) return '-';

        // Se for multi-tipo, mostrar todos separados por /
        const types = type.split(',');
        return types.map(t => labels[t.trim()] || t).join(' / ');
    }

    // ========== TIPOS MÚLTIPLOS (v2.6) ==========

    /**
     * Marca os checkboxes de tipo com base na string SET do produto
     * @param {string} typeString - Ex: "RAW,MANUFACTURED" ou "RAW"
     */
    function setProductTypeCheckboxes(typeString) {
        // Desmarcar todos primeiro
        document.querySelectorAll('input[name="types[]"]').forEach(cb => cb.checked = false);

        if (!typeString) return;

        const types = typeString.split(',').map(t => t.trim().toUpperCase());
        types.forEach(type => {
            const checkbox = document.querySelector(`input[name="types[]"][value="${type}"]`);
            if (checkbox) checkbox.checked = true;
        });
    }

    /**
     * Obtém os tipos selecionados dos checkboxes como array
     * @returns {string[]} Array de tipos selecionados
     */
    function getSelectedProductTypes() {
        const checked = document.querySelectorAll('input[name="types[]"]:checked');
        return Array.from(checked).map(cb => cb.value);
    }

    // ========== PRODUÇÃO (v2.5) ==========

    async function openProductionModal(productId, productName, unitSymbol) {
        const modal = document.getElementById('modal-production');

        document.getElementById('production-product-id').value = productId;
        document.getElementById('production-product-name').textContent = productName;
        document.getElementById('production-unit').textContent = unitSymbol || 'unidades';
        document.getElementById('production-quantity').value = 1;
        document.getElementById('production-notes').value = '';

        const previewContainer = document.getElementById('production-ingredients-preview');
        previewContainer.innerHTML = '<p class="description">Carregando receita...</p>';

        modal.style.display = 'flex';

        // Carregar receita do produto
        try {
            // API retorna array diretamente, não {ingredients: [...]}
            const ingredients = await recipesApiCall(`recipes/${productId}`);

            if (!ingredients || !Array.isArray(ingredients) || ingredients.length === 0) {
                previewContainer.innerHTML = '<p class="description" style="color: #d63638;">⚠️ Nenhum ingrediente cadastrado. Adicione ingredientes à receita primeiro.</p>';
                document.getElementById('btn-execute-production').disabled = true;
                return;
            }

            document.getElementById('btn-execute-production').disabled = false;

            let html = '<table class="widefat" style="font-size: 12px;"><thead><tr><th>Ingrediente</th><th style="text-align:right;">Por unidade</th></tr></thead><tbody>';
            ingredients.forEach(ing => {
                html += `<tr>
                    <td>${escapeHtml(ing.ingredient_name)}</td>
                    <td style="text-align:right;">${formatNumber(ing.quantity_gross || ing.quantity_net)} ${escapeHtml(ing.unit_symbol || '')}</td>
                </tr>`;
            });
            html += '</tbody></table>';
            previewContainer.innerHTML = html;

        } catch (error) {
            previewContainer.innerHTML = `<p class="description" style="color: #d63638;">Erro ao carregar receita: ${error.message}</p>`;
            document.getElementById('btn-execute-production').disabled = true;
        }
    }

    async function executeProduction() {
        const productId = document.getElementById('production-product-id').value;
        const quantity = parseFloat(document.getElementById('production-quantity').value);
        const notes = document.getElementById('production-notes').value;

        if (!productId || !quantity || quantity <= 0) {
            showNotice('Informe a quantidade a produzir', 'error');
            return;
        }

        const btn = document.getElementById('btn-execute-production');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner is-active" style="float:none; margin:0;"></span> Produzindo...';

        try {
            const result = await recipesApiCall(`recipes/${productId}/produce`, {
                method: 'POST',
                body: JSON.stringify({ quantity, notes })
            });

            closeModal('modal-production');
            loadProducts();

            // Mostrar resultado detalhado
            const msg = `✅ Produção concluída!\n\n` +
                `Produto: ${result.product_name}\n` +
                `Quantidade: ${result.quantity_produced} ${result.unit}\n` +
                `Custo unitário: R$ ${formatNumber(result.unit_cost, 4)}\n` +
                `Custo total: R$ ${formatNumber(result.total_cost, 2)}\n` +
                `Ingredientes consumidos: ${result.ingredients_consumed}\n` +
                `Novo estoque: ${formatNumber(result.new_stock)}`;

            showNotice(`Produção concluída! ${result.quantity_produced} ${result.unit} de ${result.product_name} adicionado ao estoque.`, 'success');

        } catch (error) {
            showNotice(`Erro na produção: ${error.message}`, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<span class="dashicons dashicons-hammer" style="vertical-align: middle;"></span> Produzir Agora';
        }
    }

    // Bind production button
    document.getElementById('btn-execute-production')?.addEventListener('click', executeProduction);

    // ========== PERDAS / LOSS TRACKING (v2.7) ==========

    /**
     * Abre o modal de registro de perda para um produto
     * @param {number} productId - ID do produto
     * @param {string} productName - Nome do produto
     * @param {number} currentStock - Estoque atual
     * @param {string} unitSymbol - Símbolo da unidade
     */
    function openLossModal(productId, productName, currentStock, unitSymbol) {
        const modal = document.getElementById('modal-loss');
        const form = document.getElementById('form-loss');

        // Reset form
        form.reset();

        // Populate product info
        document.getElementById('loss-product-id').value = productId;
        document.getElementById('loss-product-name').textContent = productName;
        document.getElementById('loss-current-stock').textContent = formatNumber(currentStock || 0);
        document.getElementById('loss-unit-symbol').textContent = unitSymbol || '';
        document.getElementById('loss-unit-display').textContent = unitSymbol || '';

        // Populate locations select
        const locationSelect = document.getElementById('loss-location');
        if (locationSelect) {
            locationSelect.innerHTML = '<option value="">' + (SisturStockConfig.i18n?.defaultLocation || 'Padrão') + '</option>';
            locationsCache.forEach(loc => {
                locationSelect.innerHTML += `<option value="${loc.id}">${escapeHtml(loc.name)}</option>`;
            });
        }

        modal.style.display = 'flex';
        document.getElementById('loss-quantity').focus();
    }

    /**
     * Submete o registro de perda para a API
     */
    async function submitLoss() {
        const productId = document.getElementById('loss-product-id').value;
        const quantity = document.getElementById('loss-quantity').value;
        const reason = document.getElementById('loss-reason').value;
        const reasonDetails = document.getElementById('loss-details').value;
        const locationId = document.getElementById('loss-location').value;

        // Validação
        if (!productId || !quantity || !reason) {
            showNotice('Preencha a quantidade e o motivo da perda', 'error');
            return;
        }

        const btn = document.getElementById('btn-submit-loss');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner is-active" style="float:none; margin:0;"></span> Registrando...';

        try {
            const data = {
                product_id: parseInt(productId),
                quantity: parseFloat(normalizeDecimal(quantity)),
                reason: reason,
                reason_details: reasonDetails || '',
                location_id: locationId ? parseInt(locationId) : null
            };

            const result = await apiCall('losses', {
                method: 'POST',
                body: JSON.stringify(data)
            });

            closeModal('modal-loss');
            loadProducts();

            // Mostrar resultado
            const lossValue = result.loss_data?.total_loss_value || 0;
            showNotice(
                `⚠️ Perda registrada! Quantidade: ${formatNumber(data.quantity)}, Custo: R$ ${formatNumber(lossValue, 2)}`,
                'success'
            );

        } catch (error) {
            showNotice(`Erro ao registrar perda: ${error.message}`, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<span class="dashicons dashicons-warning" style="vertical-align: middle;"></span> Registrar Perda';
        }
    }

    // Bind loss button
    document.getElementById('btn-submit-loss')?.addEventListener('click', submitLoss);

    // Public API
    return {
        init,
        loadProducts,
        goToPage,
        editProduct,
        viewBatches,
        quickMovement,
        closeModal,
        selectIngredient,
        deleteIngredient,
        openProductionModal,  // v2.5 - Produção
        openLossModal,        // v2.7 - Perdas CMV
        submitLoss            // v2.7 - Perdas CMV
    };
})();

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', SisturStock.init);

