jQuery(function ($) {

    // Buscador de productos de BoxBuilder.
    const productSelector = $('#boxbuilder_products');

    // Tabla donde aparecerán los productos seleccionados.
    const productsTable = $('#boxbuilder-products-table tbody');

    const orderField = $('#boxbuilder_product_order');

    // Campo que contiene el máximo global de unidades de la caja.
    const maxProductsField = $('#_boxbuilder_max_products');

    // Si no estamos en la pantalla de BoxBuilder, no hacemos nada.
    if (!productSelector.length || !productsTable.length) {
        return;
    }

    // Obtener el máximo global configurado.
    function getMaxProducts() {

        const value = $.trim(maxProductsField.val() || '');

        // Si está vacío, no existe límite.
        if (value === '') {
            return Infinity;
        }

        const max = parseInt(value, 10);

        return Number.isNaN(max) ? Infinity : max;
    }

    function updateProductOrder() {

        const productIds = [];

        productsTable.find('tr').each(function () {

            const productId = $(this).attr('data-product-id');

            if (productId) {
                productIds.push(productId);
            }

        });

        orderField.val(productIds.join(','));
    }

    // Aviso para combinaciones imposibles de límites.
    const limitsNotice = $('<div>', {
        id: 'boxbuilder-limits-notice',
        class: 'notice notice-error inline',
        css: { margin: '0 12px 12px' }
    }).hide();

    productsTable.closest('table').before(limitsNotice);

    function readRow(row) {

        function readInt(selector) {
            const value = parseInt($.trim(row.find(selector).val() || ''), 10);

            return Number.isNaN(value) || value < 0 ? 0 : value;
        }

        const rawMax = $.trim(row.find('input[name$="[max]"]').val() || '');

        let min = readInt('input[name$="[min]"]');
        let max = rawMax === '' ? Infinity : readInt('input[name$="[max]"]');
        let defaultQuantity = readInt('input[name$="[default]"]');

        if (max < min) {
            max = min;
        }

        defaultQuantity = Math.max(min, defaultQuantity);

        if (max !== Infinity) {
            defaultQuantity = Math.min(defaultQuantity, max);
        }

        return { min: min, defaultQuantity: defaultQuantity };
    }

    /**
     * Comprueba que el número de productos y sus cantidades respeten el máximo global.
     */
    function validateLimits() {

        const max = getMaxProducts();
        const messages = [];

        if (max !== Infinity) {

            const productCount = productsTable.find('tr').length;
            let minTotal = 0;
            let defaultTotal = 0;

            if (productCount > max) {
                messages.push(
                    'El número de productos configurados (' + productCount +
                    ') supera el máximo de productos de la caja (' + max + ').'
                );
            }

            productsTable.find('tr').each(function () {

                const values = readRow($(this));

                minTotal += values.min;
                defaultTotal += values.defaultQuantity;

            });

            if (minTotal > max) {
                messages.push(
                    'La suma de los mínimos individuales (' + minTotal +
                    ') supera la cantidad máxima de la caja (' + max + ').'
                );
            }

            if (defaultTotal > max) {
                messages.push(
                    'La suma de las cantidades predeterminadas (' + defaultTotal +
                    ') supera la cantidad máxima de la caja (' + max + ').'
                );
            }
        }

        limitsNotice.empty();

        messages.forEach(function (message) {
            limitsNotice.append($('<p>').text(message));
        });

        if (messages.length) {
            limitsNotice.append(
                $('<p>').text(
                    'Mientras la combinación sea imposible, los límites y los productos no se guardarán.'
                )
            );
        }

        limitsNotice.toggle(messages.length > 0);
    }

    productSelector.on('select2:selecting', function (event) {

        const max = getMaxProducts();
        const productCount = productsTable.find('tr').length;

        if (max !== Infinity && productCount >= max) {
            limitsNotice
                .empty()
                .append(
                    $('<p>').text(
                        'Ya hay ' + productCount + ' productos configurados y el máximo de la caja es ' + max + '. Quita uno antes de añadir más.'
                    )
                )
                .show();

            event.preventDefault();
        }
    });

    // Activamos el sistema de arrastrar y soltar.
    productsTable.sortable({

        items: '> tr',

        handle: '.boxbuilder-drag-handle',

        axis: 'y',

        update: function () {
            updateProductOrder();
        }

    });

    function createProductRow(productId, productName) {

        // Evitamos crear dos filas para el mismo producto.
        if (
            productsTable.find(
                '[data-product-id="' + productId + '"]'
            ).length
        ) {
            return;
        }

        const row = $('<tr>', {
            class: 'boxbuilder-product-row',
            'data-product-id': productId
        });

        const handleCell = $('<td>', {
            class: 'boxbuilder-drag-handle',
            css: {
                cursor: 'move',
                width: '30px'
            }
        });

        handleCell.append(
            $('<span>', {
                class: 'dashicons dashicons-menu'
            })
        );

        // Nombre del producto.
        const nameCell = $('<td>').text(productName);

        // Cantidad mínima.
        const minCell = $('<td>');
        const minInput = $('<input>', {
            type: 'number',
            name: 'boxbuilder_settings[' + productId + '][min]',
            value: 0,
            min: 0,
            step: 1,
            css: { width: '75px' }
        });

        minCell.append(minInput);

        // Cantidad máxima.
        const maxCell = $('<td>');
        const maxInput = $('<input>', {
            type: 'number',
            name: 'boxbuilder_settings[' + productId + '][max]',
            value: '',
            min: 0,
            step: 1,
            placeholder: '∞',
            css: { width: '75px' }
        });

        maxCell.append(maxInput);

        // Cantidad predeterminada.
        const defaultCell = $('<td>');
        const defaultInput = $('<input>', {
            type: 'number',
            name: 'boxbuilder_settings[' + productId + '][default]',
            value: 0,
            min: 0,
            step: 1,
            css: { width: '75px' }
        });

        defaultCell.append(defaultInput);

        // Montamos todas las celdas.
        row.append(
            handleCell,
            nameCell,
            minCell,
            maxCell,
            defaultCell
        );

        productsTable.append(row);
        updateProductOrder();
        validateLimits();
    }

    productSelector.on('select2:select', function (event) {

        const selectedProduct = event.params.data;

        createProductRow(
            selectedProduct.id,
            selectedProduct.text
        );
    });


    productSelector.on('select2:unselect', function (event) {

        const selectedProduct = event.params.data;

        productsTable
            .find(
                '[data-product-id="' + selectedProduct.id + '"]'
            )
            .remove();
        updateProductOrder();
        validateLimits();
    });

    // Validar en vivo al cambiar el máximo global o cualquier valor de la tabla.
    maxProductsField.on('input change', validateLimits);
    productsTable.on('input change', 'input', validateLimits);

    // Mostrar también para las cajas los campos de precio de WooCommerce
    // (precio de la caja en el sistema de precio fijo).
    $('#general_product_data .options_group.pricing').addClass('show_if_boxbuilder');
    $('select#product-type').trigger('change');

    validateLimits();

});
