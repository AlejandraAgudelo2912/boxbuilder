<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Product_BoxBuilder extends WC_Product {

    public function get_type() {
        return 'boxbuilder';
    }

    public function is_purchasable() {
        return apply_filters(
            'woocommerce_is_purchasable',
            $this->exists() && ( 'publish' === $this->get_status() || current_user_can( 'edit_post', $this->get_id() ) ),
            $this
        );
    }

    public function add_to_cart_url() {
        return apply_filters( 'woocommerce_product_add_to_cart_url', $this->get_permalink(), $this );
    }

    public function add_to_cart_text() {
        return apply_filters(
            'woocommerce_product_add_to_cart_text',
            __( 'Personalizar caja', 'boxbuilder-woocommerce' ),
            $this
        );
    }

    public function get_min_products() {
        return absint( $this->get_meta( '_boxbuilder_min_products', true ) );
    }

    public function get_max_products() {
        $max = $this->get_meta( '_boxbuilder_max_products', true );

        return '' === $max || null === $max ? '' : absint( $max );
    }

    public function get_box_mode() {

        return 'custom' === $this->get_meta( '_boxbuilder_mode', true )
            ? 'custom'
            : 'fixed';
    }

    public function get_allow_extras() {

        return 'no' !== $this->get_meta( '_boxbuilder_allow_extras', true );
    }

    public function get_reference_value( $display_prices = false ) {

        $value = 0.0;

        foreach ( $this->get_box_items() as $item_id => $settings ) {
            if ( $settings['default'] < 1 ) {
                continue;
            }

            $item_product = wc_get_product( $item_id );

            if ( $item_product ) {
                $item_price = $display_prices
                    ? wc_get_price_to_display( $item_product )
                    : $item_product->get_price();

                $value += (float) $item_price * $settings['default'];
            }
        }

        return $value;
    }

    public function get_box_min() {

        return $this->get_min_products();
    }

    public function get_box_max() {

        return $this->get_max_products();
    }

    public function get_pricing_mode() {
        $mode = $this->get_meta( '_boxbuilder_pricing', true );

        return in_array( $mode, array( 'fixed', 'dynamic', 'adjusted' ), true ) ? $mode : 'dynamic';
    }

    public function get_box_items() {

        $saved_products = $this->get_meta( '_boxbuilder_products', true );

        if ( ! is_array( $saved_products ) ) {
            return array();
        }

        $global_max = $this->get_max_products();
        $items      = array();

        foreach ( $saved_products as $key => $settings ) {
            if ( is_array( $settings ) ) {
                $item_id = absint( $key );
            } else {
                $item_id  = absint( $settings );
                $settings = array();
            }

            if ( ! $item_id || $item_id === $this->get_id() ) {
                continue;
            }

            $min = isset( $settings['min'] ) ? absint( $settings['min'] ) : 0;
            $required = isset( $settings['required'] ) && 'yes' === $settings['required'];

            if ( $required ) {
                $min = max( 1, $min );
            }

            $max = isset( $settings['max'] ) && '' !== $settings['max']
                ? absint( $settings['max'] )
                : '';

            if ( '' !== $max && $max < $min ) {
                $max = $min;
            }

            if ( '' !== $global_max ) {
                $max = '' === $max ? $global_max : min( $max, $global_max );
            }

            $default = isset( $settings['default'] ) ? absint( $settings['default'] ) : 0;
            $default = max( $min, $default );

            if ( '' !== $max ) {
                $default = min( $default, $max );
            }

            $items[ $item_id ] = array(
                'min'      => $min,
                'max'      => $max,
                'default'  => $default,
                'required' => $required ? 'yes' : 'no',
            );
        }

        return $items;
    }

    public function get_recommended_replacements( $source_id, $limit = 6 ) {

        $source_id = absint( $source_id );
        $items     = $this->get_box_items();

        if ( ! isset( $items[ $source_id ] ) ) {
            return array();
        }

        if ( $items[ $source_id ]['min'] > 0 ) {
            return array();
        }

        return $this->get_product_recommendations( $source_id, $limit );
    }

    public function get_product_recommendations( $source_id, $limit = 5 ) {

        $source_id     = absint( $source_id );
        $source_product = wc_get_product( $source_id );

        if ( ! $source_product ) {
            return array();
        }

        $tag_source_id = $source_product->is_type( 'variation' )
            ? $source_product->get_parent_id()
            : $source_id;
        $terms         = wp_get_post_terms( $tag_source_id, 'product_tag' );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array();
        }

        $tag_slugs = wp_list_pluck( $terms, 'slug' );
        $exclude   = array( $source_id, $this->get_id() );

        if ( $source_product->is_type( 'variation' ) ) {
            $exclude[] = $source_product->get_parent_id();
        }

        $products = wc_get_products(
            array(
                'status'       => 'publish',
                'type'         => 'simple',
                'tag'          => $tag_slugs,
                'exclude'      => array_unique( array_map( 'absint', $exclude ) ),
                'limit'        => max( 1, absint( $limit ) ),
                'orderby'      => 'date',
                'order'        => 'DESC',
                'return'       => 'objects',
            )
        );

        return array_values(
            array_filter(
                $products,
                function ( $candidate ) {
                    return $candidate->is_purchasable()
                        && $this->is_item_available( $candidate );
                }
            )
        );
    }

    public function is_recommended_replacement( $source_id, $replacement_id ) {

        $source_id      = absint( $source_id );
        $replacement_id = absint( $replacement_id );
        $items          = $this->get_box_items();

        if (
            ! isset( $items[ $source_id ] )
            || $items[ $source_id ]['min'] > 0
            || ! $replacement_id
            || $source_id === $replacement_id
        ) {
            return false;
        }

        $source_product      = wc_get_product( $source_id );
        $replacement_product = wc_get_product( $replacement_id );

        if (
            ! $source_product
            || ! $replacement_product
            || ! $replacement_product->is_type( 'simple' )
            || ! $replacement_product->is_purchasable()
            || ! $this->is_item_available( $replacement_product )
        ) {
            return false;
        }

        $tag_source_id = $source_product->is_type( 'variation' )
            ? $source_product->get_parent_id()
            : $source_id;
        $source_tags = wp_get_post_terms( $tag_source_id, 'product_tag', array( 'fields' => 'ids' ) );
        $target_tags = wp_get_post_terms( $replacement_id, 'product_tag', array( 'fields' => 'ids' ) );

        if ( is_wp_error( $source_tags ) || is_wp_error( $target_tags ) ) {
            return false;
        }

        return ! empty( array_intersect( $source_tags, $target_tags ) );
    }

    private static function parse_quantity( $value ) {
        return filter_var(
            $value,
            FILTER_VALIDATE_INT,
            array( 'options' => array( 'min_range' => 0 ) )
        );
    }

    public static function validate_limits( $max_products, $items ) {

        $errors = array();

        if ( '' === $max_products || ! is_array( $items ) ) {
            return $errors;
        }

        $max_products  = absint( $max_products );
        $product_count = count( $items );
        $min_total     = 0;
        $default_total = 0;

        foreach ( $items as $item ) {
            $min_total     += isset( $item['min'] ) ? absint( $item['min'] ) : 0;
            $default_total += isset( $item['default'] ) ? absint( $item['default'] ) : 0;
        }

        if ( $product_count > $max_products ) {
            $errors[] = sprintf(
                /* translators: 1: productos configurados, 2: máximo de productos. */
                __( 'El número de productos configurados (%1$d) supera el máximo de productos de la caja (%2$d).', 'boxbuilder-woocommerce' ),
                $product_count,
                $max_products
            );
        }

        if ( $min_total > $max_products ) {
            $errors[] = sprintf(
                /* translators: 1: suma de mínimos, 2: máximo global. */
                __( 'La suma de los mínimos individuales (%1$d) supera la cantidad máxima de la caja (%2$d).', 'boxbuilder-woocommerce' ),
                $min_total,
                $max_products
            );
        }

        if ( $default_total > $max_products ) {
            $errors[] = sprintf(
                /* translators: 1: suma de predeterminados, 2: máximo global. */
                __( 'La suma de las cantidades predeterminadas (%1$d) supera la cantidad máxima de la caja (%2$d).', 'boxbuilder-woocommerce' ),
                $default_total,
                $max_products
            );
        }

        return $errors;
    }

    public function normalize_selection( $quantities, $replacements = array() ) {

        $selection = array();
        $items     = $this->get_box_items();

        if ( ! is_array( $quantities ) ) {
            return $selection;
        }

        $replacements = $this->normalize_replacements( $quantities, $replacements );

        foreach ( $quantities as $slot_id => $quantity ) {

            $quantity = self::parse_quantity( $quantity );

            if ( false === $quantity || ! ctype_digit( (string) $slot_id ) || 0 === $quantity ) {
                continue;
            }

            $slot_id = absint( $slot_id );

            if ( isset( $items[ $slot_id ] ) ) {
                $replacement_id = isset( $replacements[ $slot_id ] )
                    ? absint( $replacements[ $slot_id ] )
                    : $slot_id;

                if ( $replacement_id ) {
                    $selection[ $replacement_id ] = isset( $selection[ $replacement_id ] )
                        ? $selection[ $replacement_id ] + $quantity
                        : $quantity;
                }

                continue;
            }

            $extra = wc_get_product( $slot_id );

            if (
                ! isset( $replacements[ $slot_id ] )
                && $this->is_eligible_extra( $extra )
            ) {
                $selection[ $slot_id ] = isset( $selection[ $slot_id ] )
                    ? $selection[ $slot_id ] + $quantity
                    : $quantity;
            }
        }

        $ordered = array();

        foreach ( array_keys( $items ) as $slot_id ) {
            $product_id = isset( $replacements[ $slot_id ] )
                ? absint( $replacements[ $slot_id ] )
                : $slot_id;

            if ( isset( $selection[ $product_id ] ) ) {
                $ordered[ $product_id ] = $selection[ $product_id ];
                unset( $selection[ $product_id ] );
            }
        }

        return $ordered + $selection;
    }

    public function normalize_replacements( $quantities, $replacements ) {

        $normalized = array();

        if ( ! is_array( $quantities ) || ! is_array( $replacements ) ) {
            return $normalized;
        }

        foreach ( $this->get_box_items() as $slot_id => $settings ) {
            $quantity = isset( $quantities[ $slot_id ] )
                ? self::parse_quantity( $quantities[ $slot_id ] )
                : 0;

            if (
                false !== $quantity
                && $quantity > 0
                && isset( $replacements[ $slot_id ] )
                && ( is_string( $replacements[ $slot_id ] ) || is_int( $replacements[ $slot_id ] ) )
                && ctype_digit( (string) $replacements[ $slot_id ] )
                && absint( $replacements[ $slot_id ] ) > 0
                && absint( $replacements[ $slot_id ] ) !== $slot_id
            ) {
                $normalized[ $slot_id ] = absint( $replacements[ $slot_id ] );
            }
        }

        return $normalized;
    }

    public function validate_selection( $quantities, $box_quantity = 1, $replacements = array() ) {

        if ( ! is_array( $quantities ) ) {
            return array(
                __( 'No se han recibido las cantidades de la caja. Vuelve a configurarla e inténtalo de nuevo.', 'boxbuilder-woocommerce' ),
            );
        }

        if ( ! is_array( $replacements ) ) {
            return array(
                __( 'Se han recibido reemplazos no válidos para la caja.', 'boxbuilder-woocommerce' ),
            );
        }

        $box_quantity  = max( 1, absint( $box_quantity ) );
        $errors        = array();
        $items         = $this->get_box_items();
        $selected      = array();
        $selected_slots = array();
        $extra_total   = 0;

        $label = function ( $item_id ) {
            $item_product = wc_get_product( $item_id );

            return $item_product ? $item_product->get_name() : '#' . $item_id;
        };

        foreach ( $quantities as $slot_id => $quantity ) {

            $quantity = self::parse_quantity( $quantity );

            if ( ! ctype_digit( (string) $slot_id ) || false === $quantity ) {
                $errors[] = __( 'Se han recibido cantidades no válidas para la caja.', 'boxbuilder-woocommerce' );
                continue;
            }

            $slot_id = absint( $slot_id );

            if ( 0 === $quantity ) {
                continue;
            }

            if ( ! isset( $items[ $slot_id ] ) ) {
                $extra_product = wc_get_product( $slot_id );

                if (
                    ( isset( $replacements[ $slot_id ] ) && '' !== $replacements[ $slot_id ] )
                    || ! $this->is_eligible_extra( $extra_product )
                ) {
                    $errors[] = sprintf(
                        /* translators: %s: nombre del producto. */
                        __( 'El producto «%s» no forma parte de esta caja o no admite productos adicionales.', 'boxbuilder-woocommerce' ),
                        $label( $slot_id )
                    );
                    continue;
                }

                if ( isset( $selected[ $slot_id ] ) ) {
                    $errors[] = __( 'No se puede seleccionar el mismo producto en más de una posición de la caja.', 'boxbuilder-woocommerce' );
                    continue;
                }

                $selected[ $slot_id ] = $quantity;
                $extra_total         += $quantity;
                continue;
            }

            $product_id = $slot_id;

            if ( isset( $replacements[ $slot_id ] ) && '' !== $replacements[ $slot_id ] ) {
                if (
                    ( ! is_string( $replacements[ $slot_id ] ) && ! is_int( $replacements[ $slot_id ] ) )
                    || ! ctype_digit( (string) $replacements[ $slot_id ] )
                    || ! $this->is_recommended_replacement( $slot_id, $replacements[ $slot_id ] )
                ) {
                    $errors[] = __( 'Uno de los productos de reemplazo ya no está disponible o no está recomendado para esta caja.', 'boxbuilder-woocommerce' );
                    continue;
                }

                $product_id = absint( $replacements[ $slot_id ] );
            }

            if ( isset( $selected[ $product_id ] ) ) {
                $errors[] = __( 'No se puede seleccionar el mismo producto en más de una posición de la caja.', 'boxbuilder-woocommerce' );
                continue;
            }

            $selected_slots[ $slot_id ] = $quantity;
            $selected[ $product_id ]    = $quantity;
        }

        $stock_demand   = array();
        $stock_products = array();

        foreach ( $selected as $item_id => $quantity ) {

            $item_product = wc_get_product( $item_id );

            if ( ! $item_product || ! $this->is_item_available( $item_product ) ) {
                $errors[] = sprintf(
                    /* translators: %s: nombre del producto. */
                    __( 'El producto «%s» ya no está disponible.', 'boxbuilder-woocommerce' ),
                    $label( $item_id )
                );
                continue;
            }

            if ( ! $item_product->managing_stock() ) {
                continue;
            }

            $stock_id = absint( $item_product->get_stock_managed_by_id() );

            if ( ! $stock_id ) {
                continue;
            }

            if ( ! isset( $stock_demand[ $stock_id ] ) ) {
                $stock_demand[ $stock_id ] = 0;
                $stock_products[ $stock_id ] = wc_get_product( $stock_id );

                if ( ! ( $stock_products[ $stock_id ] instanceof WC_Product ) ) {
                    $stock_products[ $stock_id ] = $item_product;
                }
            }

            $stock_demand[ $stock_id ] += $quantity * $box_quantity;
        }

        foreach ( $stock_demand as $stock_id => $quantity ) {
            $stock_product = $stock_products[ $stock_id ];

            if ( ! $stock_product->has_enough_stock( $quantity ) ) {
                $errors[] = sprintf(
                    /* translators: %s: nombre del producto. */
                    __( 'No hay stock suficiente de «%s».', 'boxbuilder-woocommerce' ),
                    $stock_products[ $stock_id ]->get_name()
                );
            }
        }

        $total = $extra_total;

        foreach ( $items as $item_id => $item ) {

            $quantity = isset( $selected_slots[ $item_id ] ) ? $selected_slots[ $item_id ] : 0;
            $total   += $quantity;

            $minimum = $item['min'];

            if ( $quantity < $minimum ) {
                $errors[] = sprintf(
                    /* translators: 1: nombre del producto, 2: cantidad mínima. */
                    __( 'La cantidad mínima de «%1$s» es %2$d.', 'boxbuilder-woocommerce' ),
                    $label( $item_id ),
                    $minimum
                );
            }

            if ( '' !== $item['max'] && $quantity > $item['max'] ) {
                $errors[] = sprintf(
                    /* translators: 1: nombre del producto, 2: cantidad máxima. */
                    __( 'La cantidad máxima de «%1$s» es %2$d.', 'boxbuilder-woocommerce' ),
                    $label( $item_id ),
                    $item['max']
                );
            }
        }

        $min_products = $this->get_min_products();
        $max_products = $this->get_max_products();

        if ( 0 === $total ) {
            $errors[] = __( 'Selecciona al menos un producto para la caja.', 'boxbuilder-woocommerce' );
        } elseif ( $total < $min_products ) {
            $errors[] = sprintf(
                /* translators: 1: mínimo global, 2: unidades seleccionadas. */
                __( 'La caja debe contener al menos %1$d unidades en total y has seleccionado %2$d.', 'boxbuilder-woocommerce' ),
                $min_products,
                $total
            );
        }

        if ( '' !== $max_products && $total > $max_products ) {
            $errors[] = sprintf(
                /* translators: 1: máximo global, 2: unidades seleccionadas. */
                __( 'La caja admite como máximo %1$d unidades en total y has seleccionado %2$d.', 'boxbuilder-woocommerce' ),
                $max_products,
                $total
            );
        }

        return array_values( array_unique( $errors ) );
    }

    public function calculate_price( $selection, $replacements = array() ) {

        $pricing    = $this->get_pricing_mode();
        $fixed      = 'fixed' === $pricing;
        $configured = $this->get_box_items();
        $replaced   = array_map( 'absint', array_values( (array) $replacements ) );
        $total      = $fixed ? (float) $this->get_price() : 0.0;

        foreach ( (array) $selection as $item_id => $quantity ) {

            if ( $fixed && ( isset( $configured[ $item_id ] ) || in_array( absint( $item_id ), $replaced, true ) ) ) {
                continue;
            }

            $item_product = wc_get_product( $item_id );

            if ( ! $item_product ) {
                continue;
            }

            $total += (float) $item_product->get_price() * absint( $quantity );
        }

        if ( 'adjusted' === $pricing ) {
            $total = max(
                0,
                (float) $this->get_price() + $total - $this->get_reference_value()
            );
        }

        return round( $total, wc_get_price_decimals() );
    }

    private function is_item_available( $item_product ) {

        if (
            'publish' !== $item_product->get_status()
            || ! $item_product->is_purchasable()
            || ! $item_product->is_in_stock()
        ) {
            return false;
        }

        if (
            $item_product->managing_stock()
            && ! $item_product->backorders_allowed()
            && ! $item_product->has_enough_stock( 1 )
        ) {
            return false;
        }

        if ( $item_product->is_type( 'variation' ) ) {
            $parent = wc_get_product( $item_product->get_parent_id() );

            return $parent && 'publish' === $parent->get_status();
        }

        return true;
    }

    private function is_eligible_extra( $item_product ) {

        return $this->get_allow_extras()
            && $item_product instanceof WC_Product
            && $item_product->get_id() !== $this->get_id()
            && in_array( $item_product->get_type(), array( 'simple', 'variation' ), true )
            && $item_product->is_purchasable()
            && $this->is_item_available( $item_product );
    }

}
