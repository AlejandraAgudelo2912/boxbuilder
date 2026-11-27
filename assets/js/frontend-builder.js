
document.addEventListener('DOMContentLoaded', function () {

    const builders = document.querySelectorAll('.boxbuilder-builder[data-pricing-mode]');

    builders.forEach(function (builder) {

        const cards = Array.from(builder.querySelectorAll('.boxbuilder-product-card'));
        const summaryItems = builder.querySelector('.boxbuilder-summary-items');
        const emptyMessage = builder.querySelector('.boxbuilder-summary-empty');
        const totalElement = builder.querySelector('.boxbuilder-total-price');
        const addToCartButton = builder.querySelector('.boxbuilder-add-to-cart');
        const summaryCount = builder.querySelector('.boxbuilder-summary-count');
        const availableCount = builder.querySelector('.boxbuilder-section-heading > span');
        const recommendationDialog = builder.querySelector('.boxbuilder-recommendation-dialog');
        const recommendationList = builder.querySelector('.boxbuilder-recommendation-list');
        const recommendationEmpty = builder.querySelector('.boxbuilder-recommendation-empty');
        const recommendationEmptyMessage = recommendationEmpty.textContent;
        const searchInput = builder.querySelector('.boxbuilder-search-input');
        const searchStatus = builder.querySelector('.boxbuilder-search-status');
        const searchResults = builder.querySelector('.boxbuilder-search-results');

        const pricingMode = builder.dataset.pricingMode || 'dynamic';
        const fixedPrice = parseFloat(builder.dataset.fixedPrice) || 0;
        const currency = builder.dataset.currency || 'EUR';
        // Intl necesita el formato es-ES; WordPress puede entregar es_ES.
        const locale = (builder.dataset.locale || 'es-ES').replace('_', '-');

        // Máximo global de unidades de la caja (suma de todos los productos).
        const parsedMax = parseInt(builder.dataset.maxProducts, 10);
        const maxProducts = Number.isNaN(parsedMax) ? Infinity : parsedMax;
        let searchTimer = null;
        let searchController = null;

        // Límites generales de la caja.
        const minProducts = parseInt(builder.dataset.minProducts, 10) || 0;

        function formatPrice(price) {

            return new Intl.NumberFormat(locale, {
                style: 'currency',
                currency: currency
            }).format(price);

        }

        function readCard(card) {

            const input = card.querySelector('.boxbuilder-quantity-input');

            const individualMax = input.max === '' ? Infinity : parseInt(input.max, 10);
            const individualMin = parseInt(input.min, 10) || 0;

            return {
                input: input,
                quantity: parseInt(input.value, 10) || 0,
                min: individualMin,
                max: Math.min(
                    Number.isNaN(individualMax) ? Infinity : individualMax,
                    maxProducts
                )
            };

        }

        // Suma de las unidades de todos los productos seleccionados.
        function getTotalSelected() {

            let total = 0;

            cards.forEach(function (card) {
                total += readCard(card).quantity;
            });

            return total;

        }

        function getAvailableRecommendations(card, recommendations) {

            const alreadySelected = new Set(
                Array.from(cards)
                    .filter(function (otherCard) {
                        return otherCard !== card && !otherCard.hidden;
                    })
                    .map(function (otherCard) {
                        return otherCard.dataset.productId;
                    })
            );

            return (recommendations || JSON.parse(card.dataset.recommendations || '[]')).filter(function (recommendation) {
                return String(recommendation.id) !== card.dataset.productId
                    && !alreadySelected.has(String(recommendation.id));
            });

        }

        function updateButtons(card, totalSelected) {

            const state = readCard(card);

            const minusButton = card.querySelector('.boxbuilder-quantity-minus');
            const plusButton = card.querySelector('.boxbuilder-quantity-plus');
            const removeButton = card.querySelector('.boxbuilder-product-remove');
            const recommendButton = card.querySelector('.boxbuilder-product-recommend');

            // Respetar el mínimo individual.
            minusButton.disabled = state.quantity <= state.min;
            removeButton.disabled = state.min > 0;
            recommendButton.disabled = !card.classList.contains('is-extra')
                && getAvailableRecommendations(card).length === 0;

            // Respetar el máximo individual y el máximo global de la caja.
            plusButton.disabled =
                state.max < state.min
                || state.quantity >= state.max
                || totalSelected + 1 > maxProducts;
        }

        function updateBuilder() {

            summaryItems.innerHTML = '';

            let selectedCount = 0;
            let dynamicTotal = 0;
            let extrasTotal = 0;
            let selectionIsValid = true;

            cards.forEach(function (card) {

                const state = readCard(card);
                const quantity = state.quantity;
                const price = parseFloat(card.dataset.price) || 0;

                const nameElement = card.querySelector('.boxbuilder-product-name');
                const productName = nameElement.textContent.trim();

                card.classList.toggle('is-selected', quantity > 0);

                if (quantity < state.min || quantity > state.max) {
                    selectionIsValid = false;
                }

                if (quantity > 0) {

                    selectedCount += quantity;
                    dynamicTotal += price * quantity;

                    if (card.classList.contains('is-extra')) {
                        extrasTotal += price * quantity;
                    }

                    const summaryRow = document.createElement('div');
                    summaryRow.className = 'boxbuilder-summary-item';

                    const name = document.createElement('span');
                    name.textContent = productName + ' × ' + quantity;

                    const subtotal = document.createElement('strong');
                    subtotal.textContent = formatPrice(price * quantity);

                    summaryRow.appendChild(name);
                    summaryRow.appendChild(subtotal);

                    summaryItems.appendChild(summaryRow);

                }

            });

            emptyMessage.hidden = selectedCount > 0;

            const visibleProducts = Array.from(cards).filter(function (card) {
                return !card.hidden;
            }).length;

            availableCount.textContent = visibleProducts
                + (visibleProducts === 1 ? ' disponible' : ' disponibles');

            summaryCount.textContent = Number.isFinite(maxProducts)
                ? selectedCount + ' de ' + maxProducts + ' seleccionados'
                : selectedCount + ' seleccionado' + (selectedCount === 1 ? '' : 's');

            // Comprobar los límites generales de la caja.
            if (selectedCount < minProducts || selectedCount > maxProducts) {
                selectionIsValid = false;
            }

            // Actualizar los botones usando la cantidad total definitiva.
            cards.forEach(function (card) {
                updateButtons(card, selectedCount);
            });

            let total = dynamicTotal;

            if (pricingMode === 'fixed') {
                total = fixedPrice + extrasTotal;
            }

            totalElement.textContent = formatPrice(total);

            // El botón solo se activa con una selección válida (el servidor la vuelve a comprobar).
            addToCartButton.disabled = !(selectedCount > 0 && selectionIsValid);

        }

        function bindCard(card) {

            const minusButton = card.querySelector('.boxbuilder-quantity-minus');
            const plusButton = card.querySelector('.boxbuilder-quantity-plus');
            const removeButton = card.querySelector('.boxbuilder-product-remove');
            const recommendButton = card.querySelector('.boxbuilder-product-recommend');

            minusButton.addEventListener('click', function () {

                const state = readCard(card);

                if (state.quantity > state.min) {
                    state.input.value = state.quantity - 1;
                    updateBuilder();
                }

            });

            plusButton.addEventListener('click', function () {

                const state = readCard(card);

                if (state.quantity < state.max && getTotalSelected() + 1 <= maxProducts) {
                    state.input.value = state.quantity + 1;
                    updateBuilder();
                }

            });

            removeButton.addEventListener('click', function () {

                const state = readCard(card);

                if (state.min === 0) {
                    state.input.value = 0;
                    card.hidden = true;
                    updateBuilder();
                }

            });

            recommendButton.addEventListener('click', function () {
                openRecommendations(card);
            });

        }

        function createExtraCard(item) {

            const card = document.createElement('article');
            card.className = 'boxbuilder-product-card is-extra';
            card.dataset.slotId = String(item.id);
            card.dataset.productId = String(item.id);
            card.dataset.price = String(item.price);
            card.dataset.recommendations = '[]';

            const imageWrap = document.createElement('div');
            imageWrap.className = 'boxbuilder-product-image';

            const image = document.createElement('img');
            image.src = item.image;
            image.alt = item.name;
            image.loading = 'lazy';
            imageWrap.appendChild(image);

            const actions = document.createElement('div');
            actions.className = 'boxbuilder-product-actions';

            const recommendButton = document.createElement('button');
            recommendButton.type = 'button';
            recommendButton.className = 'boxbuilder-product-recommend';
            recommendButton.setAttribute('aria-label', 'Ver productos recomendados');
            recommendButton.textContent = '↔';

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'boxbuilder-product-remove';
            removeButton.setAttribute('aria-label', 'Quitar ' + item.name + ' de la caja');
            removeButton.textContent = '×';
            actions.append(recommendButton, removeButton);

            const info = document.createElement('div');
            info.className = 'boxbuilder-product-info';

            const heading = document.createElement('h4');
            const name = document.createElement('span');
            name.className = 'boxbuilder-product-name';
            name.textContent = item.name;
            heading.appendChild(name);

            const price = document.createElement('div');
            price.className = 'boxbuilder-product-price';
            price.textContent = formatPrice(item.price);

            const quantityWrap = document.createElement('div');
            quantityWrap.className = 'boxbuilder-quantity';

            const minus = document.createElement('button');
            minus.type = 'button';
            minus.className = 'boxbuilder-quantity-minus';
            minus.setAttribute('aria-label', 'Reducir cantidad');
            minus.textContent = '−';

            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'boxbuilder-quantity-input';
            input.name = 'boxbuilder_quantities[' + item.id + ']';
            input.min = '0';
            if (item.max !== '') {
                input.max = String(item.max);
            }
            input.value = '1';
            input.readOnly = true;

            const plus = document.createElement('button');
            plus.type = 'button';
            plus.className = 'boxbuilder-quantity-plus';
            plus.setAttribute('aria-label', 'Aumentar cantidad');
            plus.textContent = '+';

            quantityWrap.append(minus, input, plus);
            info.append(heading, price, quantityWrap);
            card.append(actions, imageWrap, info);

            return card;
        }

        function addExtra(item) {

            const existing = builder.querySelector(
                '.boxbuilder-product-card[data-product-id="' + item.id + '"]'
            );

            if (getTotalSelected() >= maxProducts) {
                setSearchStatus('La caja ya está completa. Quita algún producto para añadir otro.');
                return;
            }

            if (existing) {
                existing.hidden = false;
                existing.querySelector('.boxbuilder-quantity-plus').click();
                return;
            }

            const card = createExtraCard(item);
            let grid = builder.querySelector('.boxbuilder-products-grid');

            if (!grid) {
                grid = document.createElement('div');
                grid.className = 'boxbuilder-products-grid';
                builder.querySelector('.boxbuilder-empty').hidden = true;
                builder.querySelector('.boxbuilder-search').before(grid);
            }

            grid.appendChild(card);
            cards.push(card);
            bindCard(card);
            updateBuilder();
        }

        function setSearchStatus(text) {
            searchStatus.textContent = text;
            searchStatus.hidden = text === '';
        }

        function clearSearchResults() {
            searchResults.innerHTML = '';
            searchResults.hidden = true;
        }

        function renderSearchResults(results) {

            clearSearchResults();

            if (!results.length) {
                setSearchStatus('No se han encontrado productos.');
                return;
            }

            setSearchStatus('');

            results.forEach(function (item) {

                const row = document.createElement('li');
                row.className = 'boxbuilder-search-result';

                const thumb = document.createElement('img');
                thumb.src = item.image;
                thumb.alt = '';
                thumb.loading = 'lazy';

                const name = document.createElement('span');
                name.className = 'boxbuilder-search-result-name';
                name.textContent = item.name;

                const price = document.createElement('strong');
                price.textContent = formatPrice(item.price);

                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = 'Añadir';
                button.addEventListener('click', function () {
                    addExtra(item);
                });

                row.append(thumb, name, price, button);
                searchResults.appendChild(row);
            });

            searchResults.hidden = false;
        }

        function runSearch(term) {

            if (searchController) {
                searchController.abort();
            }

            const controller = new AbortController();
            searchController = controller;

            const formData = new FormData();
            formData.append('action', 'boxbuilder_search_products');
            formData.append('nonce', builder.dataset.nonce);
            formData.append('product_id', builder.dataset.productId);
            formData.append('term', term);

            setSearchStatus('Buscando…');

            fetch(builder.dataset.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
                signal: controller.signal
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (result) {

                    if (controller.signal.aborted) {
                        return;
                    }

                    if (!result || !result.success) {
                        throw new Error(
                            result && result.data && result.data.message
                                ? result.data.message
                                : 'No se ha podido realizar la búsqueda.'
                        );
                    }

                    renderSearchResults(result.data.results || []);
                })
                .catch(function (error) {

                    if (error.name === 'AbortError' || controller.signal.aborted) {
                        return;
                    }

                    clearSearchResults();
                    setSearchStatus(error.message);
                });
        }

        function renderRecommendations(card, recommendations) {

            const availableRecommendations = getAvailableRecommendations(card, recommendations);

            recommendationList.innerHTML = '';
            recommendationEmpty.textContent = recommendationEmptyMessage;
            recommendationEmpty.hidden = availableRecommendations.length > 0;

            availableRecommendations.forEach(function (recommendation) {

                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'boxbuilder-recommendation-option';

                const image = document.createElement('img');
                image.src = recommendation.image;
                image.alt = '';

                const name = document.createElement('span');
                name.textContent = recommendation.name;

                const price = document.createElement('strong');
                price.textContent = formatPrice(parseFloat(recommendation.price) || 0);

                option.append(image, name, price);
                option.addEventListener('click', function () {

                    const productName = recommendation.name;
                    const productImage = card.querySelector('.boxbuilder-product-image img');
                    const productLink = card.querySelector('.boxbuilder-product-link');
                    const replacementInput = card.querySelector('.boxbuilder-replacement-input');
                    const quantityInput = card.querySelector('.boxbuilder-quantity-input');
                    const removeButton = card.querySelector('.boxbuilder-product-remove');
                    const recommendButton = card.querySelector('.boxbuilder-product-recommend');
                    const isExtra = card.classList.contains('is-extra');

                    card.dataset.productId = String(recommendation.id);
                    card.dataset.price = String(recommendation.price);
                    card.querySelector('.boxbuilder-product-name').textContent = productName;
                    card.querySelector('.boxbuilder-product-price').textContent =
                        formatPrice(parseFloat(recommendation.price) || 0);
                    productImage.src = recommendation.image;
                    productImage.alt = productName;
                    productImage.removeAttribute('srcset');
                    productImage.removeAttribute('sizes');

                    if (isExtra) {
                        card.dataset.slotId = String(recommendation.id);
                        quantityInput.name = 'boxbuilder_quantities[' + recommendation.id + ']';
                    } else {
                        productLink.href = recommendation.url;
                        replacementInput.value = String(recommendation.id) === card.dataset.slotId
                            ? ''
                            : String(recommendation.id);
                    }

                    if (recommendation.max === '') {
                        quantityInput.removeAttribute('max');
                    } else if (recommendation.max !== undefined) {
                        quantityInput.max = String(recommendation.max);
                    }

                    const quantity = parseInt(quantityInput.value, 10) || 0;
                    const minimum = parseInt(quantityInput.min, 10) || 0;

                    if (quantityInput.max !== '' && parseInt(quantityInput.max, 10) < minimum) {
                        quantityInput.value = '0';
                    } else if (quantityInput.max !== '') {
                        quantityInput.value = Math.min(quantity, parseInt(quantityInput.max, 10));
                    }

                    removeButton.setAttribute('aria-label', 'Quitar ' + productName + ' de la caja');
                    recommendButton.setAttribute(
                        'aria-label',
                        'Cambiar ' + productName + ' por un producto recomendado'
                    );

                    recommendationDialog.close();
                    updateBuilder();

                });

                recommendationList.appendChild(option);
            });
        }

        function openRecommendations(card) {

            if (card.classList.contains('is-extra')) {
                recommendationList.innerHTML = '';
                recommendationEmpty.textContent = 'Buscando productos recomendados…';
                recommendationEmpty.hidden = false;
                recommendationDialog.showModal();

                const formData = new FormData();
                formData.append('action', 'boxbuilder_get_extra_recommendations');
                formData.append('nonce', builder.dataset.nonce);
                formData.append('product_id', builder.dataset.productId);
                formData.append('source_id', card.dataset.productId);

                fetch(builder.dataset.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (result) {

                        if (!result || !result.success) {
                            throw new Error(
                                result && result.data && result.data.message
                                    ? result.data.message
                                    : 'No se han podido cargar los productos recomendados.'
                            );
                        }

                        renderRecommendations(card, result.data.results || []);
                    })
                    .catch(function (error) {
                        recommendationList.innerHTML = '';
                        recommendationEmpty.textContent = error.message;
                        recommendationEmpty.hidden = false;
                    });

                return;
            }

            renderRecommendations(card, JSON.parse(card.dataset.recommendations || '[]'));
            recommendationDialog.showModal();
        }

        // Unidades ya asignadas al ajustar las cantidades iniciales.
        let initialTotal = 0;

        cards.forEach(function (card) {

            const initial = readCard(card);

            let quantity = initial.max < initial.min
                ? 0
                : Math.max(initial.min, Math.min(initial.quantity, initial.max));

            const remainingGlobal = Math.max(0, maxProducts - initialTotal);

            if (quantity > remainingGlobal) {
                quantity = remainingGlobal < initial.min ? 0 : remainingGlobal;
            }

            initial.input.value = quantity;
            initialTotal += quantity;
            bindCard(card);
        });

        // Despliegue del buscador con el botón «+».
        const searchToggle = builder.querySelector('.boxbuilder-search-toggle');
        const searchPanel = builder.querySelector('.boxbuilder-search-panel');

        if (searchToggle && searchPanel) {
            searchToggle.addEventListener('click', function () {

                const willOpen = searchPanel.hidden;

                searchPanel.hidden = !willOpen;
                searchToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                searchToggle.parentElement.classList.toggle('is-open', willOpen);

                if (willOpen) {
                    if (searchInput) {
                        searchInput.focus();
                    }
                } else {
                    // Al cerrar, limpiamos la búsqueda en curso.
                    window.clearTimeout(searchTimer);

                    if (searchController) {
                        searchController.abort();
                    }

                    if (searchInput) {
                        searchInput.value = '';
                    }

                    clearSearchResults();
                    setSearchStatus('');
                }
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {

                const term = searchInput.value.trim();

                window.clearTimeout(searchTimer);

                if (searchController) {
                    searchController.abort();
                }

                if (term.length < 2) {
                    clearSearchResults();
                    setSearchStatus('');
                    return;
                }

                searchTimer = window.setTimeout(function () {
                    runSearch(term);
                }, 300);
            });
        }

        recommendationDialog.querySelector('.boxbuilder-recommendation-close')
            .addEventListener('click', function () {
                recommendationDialog.close();
            });

        recommendationDialog.addEventListener('click', function (event) {
            if (event.target === recommendationDialog) {
                recommendationDialog.close();
            }
        });

        const cartForm = builder.querySelector('.boxbuilder-cart-form');
        const addToCartLabel = addToCartButton.textContent;

        if (cartForm) {
            cartForm.addEventListener('submit', function (event) {

                // Nunca enviar una selección no válida.
                if (addToCartButton.disabled) {
                    event.preventDefault();
                    return;
                }

                // Evitar envíos duplicados.
                addToCartButton.disabled = true;
                addToCartButton.textContent = 'Añadiendo…';

            });
        }

        // Al volver con el botón "atrás" el navegador puede restaurar el botón bloqueado.
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                addToCartButton.textContent = addToCartLabel;
                updateBuilder();
            }
        });

        updateBuilder();

    });

});
