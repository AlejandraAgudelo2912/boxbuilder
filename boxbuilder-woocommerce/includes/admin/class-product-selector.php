
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BoxBuilder_Product_Selector {

    public function render_field( $product ) {

        $current_product_id = absint( $product->get_id() );
        $saved_products     = $product->get_meta(
            '_boxbuilder_products',
            true
        );

        if ( ! is_array( $saved_products ) ) {
            $saved_products = array();
        }

        $selected_ids = array();
        $product_settings = array();

        // Compatibilidad con los IDs guardados anteriormente.
        foreach ( $saved_products as $key => $saved_item ) {

            if ( is_array( $saved_item ) ) {
                $product_id = absint( $key );
                $product_settings[ $product_id ] = $saved_item;
            } else {
                $product_id = absint( $saved_item );
            }

            if ( $product_id && $product_id !== $current_product_id ) {
                $selected_ids[] = $product_id;
            }
        }

        ?>

        <div class="options_group">

            <p class="form-field">
                <label for="boxbuilder_products">
                    Productos de la caja
                </label>

                <select
                    id="boxbuilder_products"
                    name="boxbuilder_products[]"
                    class="wc-product-search"
                    multiple="multiple"
                    style="width: 50%;"
                    data-placeholder="Busca productos o variaciones..."
                    data-action="woocommerce_json_search_products_and_variations"
                    data-exclude="<?php echo esc_attr( $current_product_id ); ?>"
                >

                    <?php foreach ( $selected_ids as $product_id ) :

                        $selected_product = wc_get_product( $product_id );

                        if ( ! $selected_product ) {
                            continue;
                        }
                    ?>

                        <option
                            value="<?php echo esc_attr( $product_id ); ?>"
                            selected="selected"
                        >
                            <?php
                            echo esc_html(
                                $selected_product->get_formatted_name()
                            );
                            ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <span class="description">
                    Selecciona productos simples o variaciones concretas.
                </span>
            </p>

        </div>


            <div class="options_group">

                <h4 style="padding: 0 12px;">
                    Configuración individual de productos
                </h4>

                <input
                    type="hidden"
                    id="boxbuilder_product_order"
                    name="boxbuilder_product_order"
                    value="<?php echo esc_attr( implode( ',', $selected_ids ) ); ?>"
                >

                <table id="boxbuilder-products-table" class="widefat striped" style="margin: 12px; width: calc(100% - 24px);">

                    <thead>
                        <tr>
                            <th></th>
                            <th>Producto</th>
                            <th>Mínimo</th>
                            <th>Máximo</th>
                            <th>Predeterminado</th>
                        </tr>
                    </thead>

                    <tbody id="boxbuilder-products-table-body">
                        

                    <?php foreach ( $selected_ids as $product_id ) :

                        $selected_product = wc_get_product( $product_id );

                        if ( ! $selected_product ) {
                            continue;
                        }

                        $settings = isset( $product_settings[ $product_id ] )
                            ? $product_settings[ $product_id ]
                            : array();

                        $min = isset( $settings['min'] )
                            ? absint( $settings['min'] )
                            : 0;

                        // Conservamos el mínimo de productos que antes eran obligatorios.
                        if ( isset( $settings['required'] ) && 'yes' === $settings['required'] ) {
                            $min = max( 1, $min );
                        }

                        $max = isset( $settings['max'] )
                            ? $settings['max']
                            : '';

                        $default = isset( $settings['default'] )
                            ? absint( $settings['default'] )
                            : 0;

                    ?>

                        <tr class="boxbuilder-product-row"
                            data-product-id="<?php echo esc_attr( $product_id ); ?>">
                            <td class="boxbuilder-drag-handle" style="cursor: move; width: 30px;">
                                <span class="dashicons dashicons-menu"></span>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $selected_product->get_formatted_name()
                                );
                                ?>
                            </td>

                            <td>
                                <input
                                    type="number"
                                    name="boxbuilder_settings[<?php echo esc_attr( $product_id ); ?>][min]"
                                    value="<?php echo esc_attr( $min ); ?>"
                                    min="0"
                                    step="1"
                                    style="width: 75px;"
                                >
                            </td>

                            <td>
                                <input
                                    type="number"
                                    name="boxbuilder_settings[<?php echo esc_attr( $product_id ); ?>][max]"
                                    value="<?php echo esc_attr( $max ); ?>"
                                    min="0"
                                    step="1"
                                    placeholder="∞"
                                    style="width: 75px;"
                                >
                            </td>

                            <td>
                                <input
                                    type="number"
                                    name="boxbuilder_settings[<?php echo esc_attr( $product_id ); ?>][default]"
                                    value="<?php echo esc_attr( $default ); ?>"
                                    min="0"
                                    step="1"
                                    style="width: 75px;"
                                >
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

                <p class="description" style="padding: 0 12px;">
                    El máximo vacío significa que no existe un límite individual. En cualquier caso, la suma de todas las unidades nunca podrá superar la cantidad máxima de la caja.
                </p>

            </div>

        

        <?php
    }


    public function get_submitted_products( $excluded_product_id = 0 ) {

        $excluded_product_id = absint( $excluded_product_id );

        $selected_products = isset( $_POST['boxbuilder_products'] )
            ? wp_unslash( $_POST['boxbuilder_products'] )
            : array();

        $submitted_settings = isset( $_POST['boxbuilder_settings'] )
            ? wp_unslash( $_POST['boxbuilder_settings'] )
            : array();

        if ( ! is_array( $selected_products ) ) {
            $selected_products = array();
        }

        if ( ! is_array( $submitted_settings ) ) {
            $submitted_settings = array();
        }

        $valid_products = array();

        foreach ( $selected_products as $product_id ) {
            if ( ! is_scalar( $product_id ) || ! ctype_digit( (string) $product_id ) ) {
                continue;
            }

            $product_id = absint( $product_id );

            if ( ! $product_id || $product_id === $excluded_product_id ) {
                continue;
            }

            $selected_product = wc_get_product( $product_id );

            if ( ! $selected_product ) {
                continue;
            }

            $product_type = $selected_product->get_type();

            // Productos simples.
            $is_valid = 'simple' === $product_type;

            // Variaciones concretas.
            if ( 'variation' === $product_type ) {

                $parent_product = wc_get_product(
                    $selected_product->get_parent_id()
                );

                $is_valid = $parent_product
                    && 'variable' === $parent_product->get_type();
            }

            if ( ! $is_valid ) {
                continue;
            }

            $settings = isset( $submitted_settings[ $product_id ] )
                && is_array( $submitted_settings[ $product_id ] )
                ? $submitted_settings[ $product_id ]
                : array();

            $min = $this->normalize_quantity(
                isset( $settings['min'] ) ? $settings['min'] : 0
            );

            $raw_max = isset( $settings['max'] ) && is_scalar( $settings['max'] )
                ? sanitize_text_field( (string) $settings['max'] )
                : '';

            $max = '' === $raw_max
                ? ''
                : $this->normalize_quantity( $raw_max );

            $default = $this->normalize_quantity(
                isset( $settings['default'] ) ? $settings['default'] : 0
            );

            $required = isset( $settings['required'] )
                && 'yes' === $settings['required']
                ? 'yes'
                : 'no';

            if ( 'yes' === $required ) {
                $min = max( 1, $min );
            }

            // Evitamos que el máximo sea inferior al mínimo.
            if ( '' !== $max && $max < $min ) {
                $max = $min;
            }

            // Ajustamos la cantidad predeterminada a los límites.
            $default = max( $min, $default );

            if ( '' !== $max ) {
                $default = min( $default, $max );
            }

            $valid_products[ $product_id ] = array(
                'min'     => $min,
                'max'     => $max,
                'default' => $default,
                'required' => $required,
            );
        }

        // Recuperamos el orden enviado por JavaScript.
        $raw_order = isset( $_POST['boxbuilder_product_order'] ) && is_scalar( $_POST['boxbuilder_product_order'] )
            ? sanitize_text_field(
                wp_unslash( $_POST['boxbuilder_product_order'] )
            )
            : '';

        $ordered_ids = array_filter(
            array_map(
                'absint',
                array_filter(
                    explode( ',', $raw_order ),
                    function ( $product_id ) {
                        return ctype_digit( $product_id );
                    }
                )
            )
        );

        // Reordenamos únicamente los productos que han sido validados.
        $ordered_products = array();

        foreach ( $ordered_ids as $product_id ) {

            if ( isset( $valid_products[ $product_id ] ) ) {

                $ordered_products[ $product_id ] = $valid_products[ $product_id ];

            }
        }

        // Añadimos cualquier producto válido que no aparezca en el orden recibido.
        foreach ( $valid_products as $product_id => $settings ) {

            if ( ! isset( $ordered_products[ $product_id ] ) ) {

                $ordered_products[ $product_id ] = $settings;

            }
        }

        return $ordered_products;
    }

    private function normalize_quantity( $value ) {

        if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) ) {
            return 0;
        }

        return absint( $value );
    }
}
