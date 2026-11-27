<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BoxBuilder_Cart {

    const ITEMS_KEY = 'boxbuilder_items';
    const REPLACEMENTS_KEY = 'boxbuilder_replacements';
    const STOCK_META_KEY = '_boxbuilder_components_stock';

    public function __construct() {

        add_action(
            'wp_ajax_boxbuilder_search_products',
            array( $this, 'ajax_search_products' )
        );
        add_action(
            'wp_ajax_nopriv_boxbuilder_search_products',
            array( $this, 'ajax_search_products' )
        );
        add_action(
            'wp_ajax_boxbuilder_get_extra_recommendations',
            array( $this, 'ajax_get_extra_recommendations' )
        );
        add_action(
            'wp_ajax_nopriv_boxbuilder_get_extra_recommendations',
            array( $this, 'ajax_get_extra_recommendations' )
        );
        // Guardar el contenido de la caja en la línea de carrito.
        add_filter(
            'woocommerce_add_cart_item_data',
            array( $this, 'add_cart_item_data' ),
            10,
            2
        );

        // Precio calculado en servidor (también al recuperar el carrito de la sesión).
        add_action(
            'woocommerce_before_calculate_totals',
            array( $this, 'apply_prices' ),
            10,
            1
        );

        add_action(
            'woocommerce_cart_loaded_from_session',
            array( $this, 'apply_prices' ),
            10,
            1
        );
        add_action(
            'woocommerce_check_cart_items',
            array( $this, 'validate_cart_selections' )
        );

        // Mostrar el contenido en carrito y checkout.
        add_filter(
            'woocommerce_get_item_data',
            array( $this, 'add_item_data_to_cart' ),
            10,
            2
        );

        // Conservar el contenido en el pedido.
        add_action(
            'woocommerce_checkout_create_order_line_item',
            array( $this, 'add_order_item_meta' ),
            10,
            3
        );

        add_action(
            'woocommerce_reduce_order_stock',
            array( $this, 'reduce_components_stock' )
        );
        add_action(
            'woocommerce_restore_order_stock',
            array( $this, 'restore_components_stock' )
        );

        // Volver a la página de origen tras añadir (evita reenviar el formulario).
        add_filter(
            'woocommerce_add_to_cart_redirect',
            array( $this, 'redirect_after_add' ),
            10,
            2
        );
    }

    public function add_cart_item_data( $cart_item_data, $product_id ) {

        $product = wc_get_product( $product_id );

        if ( ! $product || 'boxbuilder' !== $product->get_type() ) {
            return $cart_item_data;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $quantities = isset( $_REQUEST['boxbuilder_quantities'] )
            ? wp_unslash( $_REQUEST['boxbuilder_quantities'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : array();
        $replacements = isset( $_REQUEST['boxbuilder_replacements'] )
            ? wp_unslash( $_REQUEST['boxbuilder_replacements'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : array();

        // Si no es válida no se adjunta nada: la validación de añadir al carrito
        // ya la habrá rechazado y check_cart_items() bloquea cualquier caso residual.
        if ( ! empty( $product->validate_selection( $quantities, 1, $replacements ) ) ) {
            return $cart_item_data;
        }

        // Un orden estable hace que la misma selección genere la misma línea.
        $cart_item_data[ self::ITEMS_KEY ] = $product->normalize_selection( $quantities, $replacements );

        $normalized_replacements = $product->normalize_replacements( $quantities, $replacements );

        if ( ! empty( $normalized_replacements ) ) {
            $cart_item_data[ self::REPLACEMENTS_KEY ] = $normalized_replacements;
        }

        return $cart_item_data;
    }

    public function apply_prices( $cart ) {

        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_item ) {

            $selection = $this->get_selection( $cart_item );

            if ( null === $selection ) {
                continue;
            }

            // Producto "limpio": el objeto del carrito ya tiene el precio modificado.
            $box = wc_get_product( $cart_item['product_id'] );

            if ( ! $box || 'boxbuilder' !== $box->get_type() ) {
                continue;
            }

            $cart_item['data']->set_price(
                $box->calculate_price( $selection, $this->get_replacements( $cart_item ) )
            );
        }
    }

    public function validate_cart_selections() {

        if ( ! WC()->cart ) {
            return;
        }

        $stock_demand = array();

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $box = wc_get_product( isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0 );

            if ( ! $box instanceof WC_Product_BoxBuilder ) {
                $cart_product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

                if ( $cart_product instanceof WC_Product && $cart_product->managing_stock() ) {
                    $stock_id = absint( $cart_product->get_stock_managed_by_id() );
                    $quantity = isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 1;

                    if ( $stock_id && $quantity ) {
                        $stock_demand[ $stock_id ] = isset( $stock_demand[ $stock_id ] )
                            ? $stock_demand[ $stock_id ] + $quantity
                            : $quantity;
                    }
                }

                continue;
            }

            $selection = $this->get_selection( $cart_item );

            if ( null === $selection || empty( $selection ) ) {
                wc_add_notice(
                    __( 'La caja no contiene una selección válida. Elimínala del carrito y vuelve a configurarla.', 'boxbuilder-woocommerce' ),
                    'error'
                );
                continue;
            }

            $errors = $box->validate_selection(
                $this->get_selection_for_validation( $box, $selection, $this->get_replacements( $cart_item ) ),
                isset( $cart_item['quantity'] ) ? $cart_item['quantity'] : 1,
                $this->get_replacements( $cart_item )
            );

            foreach ( $errors as $error ) {
                wc_add_notice( $error, 'error' );
            }

            $box_quantity = isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 1;

            foreach ( $selection as $component_id => $quantity_per_box ) {
                $component = wc_get_product( $component_id );

                if ( ! $component ) {
                    wc_add_notice(
                        sprintf(
                            /* translators: %d: component id. */
                            __( 'El componente ID %d de una caja del carrito ya no existe. Elimina la caja y vuelve a configurarla.', 'boxbuilder-woocommerce' ),
                            $component_id
                        ),
                        'error'
                    );
                    continue;
                }

                if ( ! $component->managing_stock() ) {
                    continue;
                }

                $stock_id = absint( $component->get_stock_managed_by_id() );
                $quantity = absint( $quantity_per_box ) * max( 1, $box_quantity );

                if ( $stock_id && $quantity ) {
                    $stock_demand[ $stock_id ] = isset( $stock_demand[ $stock_id ] )
                        ? $stock_demand[ $stock_id ] + $quantity
                        : $quantity;
                }
            }
        }

        foreach ( $stock_demand as $stock_id => $quantity ) {
            $stock_product = wc_get_product( $stock_id );

            if ( $stock_product && ! $stock_product->has_enough_stock( $quantity ) ) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s: product name. */
                        __( 'No hay stock suficiente de «%s» para cubrir todas las unidades del carrito y sus cajas.', 'boxbuilder-woocommerce' ),
                        $stock_product->get_name()
                    ),
                    'error'
                );
            }
        }
    }

    public function add_item_data_to_cart( $item_data, $cart_item ) {

        $selection = $this->get_selection( $cart_item );

        if ( empty( $selection ) ) {
            return $item_data;
        }

        $lines = $this->get_lines( $selection );

        $item_data[] = array(
            'key'     => __( 'Contenido de la caja', 'boxbuilder-woocommerce' ),
            'value'   => implode( ', ', $lines ),
            'display' => implode( '<br>', array_map( 'esc_html', $lines ) ),
        );

        return $item_data;
    }

    public function add_order_item_meta( $item, $cart_item_key, $values ) {

        $selection = $this->get_selection( $values );

        if ( empty( $selection ) ) {
            return;
        }

        $snapshot         = array();
        $stock_quantities = array();

        foreach ( $selection as $item_id => $quantity ) {

            $item_product = wc_get_product( $item_id );

            $item_id = absint( $item_id );
            $quantity = absint( $quantity );

            if ( ! $item_id || ! $quantity ) {
                continue;
            }

            $snapshot[] = array(
                'id'    => $item_id,
                'name'  => $item_product ? $item_product->get_name() : '#' . $item_id,
                'qty'   => $quantity,
                'price' => $item_product ? (float) $item_product->get_price() : 0,
            );
            $stock_quantities[ $item_id ] = isset( $stock_quantities[ $item_id ] )
                ? $stock_quantities[ $item_id ] + $quantity
                : $quantity;
        }

        // Visible para el cliente y en los emails.
        $item->add_meta_data(
            __( 'Contenido de la caja', 'boxbuilder-woocommerce' ),
            implode( ', ', $this->get_lines( $selection ) ),
            true
        );

        // Snapshot histórico y datos independientes para la gestión del stock.
        $item->add_meta_data( '_boxbuilder_items', $snapshot, true );
        $item->add_meta_data( self::STOCK_META_KEY, $stock_quantities, true );

        $replacements = $this->get_replacements( $values );

        if ( ! empty( $replacements ) ) {
            $item->add_meta_data( '_boxbuilder_replacements', $replacements, true );
        }
    }

    public function redirect_after_add( $url, $adding_to_cart = null ) {

        if (
            $url
            || ! is_object( $adding_to_cart )
            || 'boxbuilder' !== $adding_to_cart->get_type()
            || 'yes' === get_option( 'woocommerce_cart_redirect_after_add' )
        ) {
            return $url;
        }

        $referer = wp_get_referer();

        return $referer ? $referer : $adding_to_cart->get_permalink();
    }

    public function ajax_search_products() {

        if ( ! check_ajax_referer( 'boxbuilder_add_to_cart', 'nonce', false ) ) {
            wp_send_json_error(
                array( 'message' => __( 'La sesión ha caducado. Recarga la página.', 'boxbuilder-woocommerce' ) ),
                403
            );
        }

        if ( null === WC()->cart ) {
            wc_load_cart();
        }

        $product_id = $this->get_posted_id( 'product_id' );
        $box        = wc_get_product( $product_id );

        if ( ! $box instanceof WC_Product_BoxBuilder || ! $box->get_allow_extras() ) {
            wp_send_json_error(
                array( 'message' => __( 'Esta caja no admite productos adicionales.', 'boxbuilder-woocommerce' ) ),
                403
            );
        }

        $term = isset( $_POST['term'] ) && is_scalar( $_POST['term'] )
            ? wc_clean( wp_unslash( $_POST['term'] ) )
            : '';

        $term_characters = array();
        $term_length     = preg_match_all( '/./us', $term, $term_characters );

        if ( false === $term_length || $term_length < 2 ) {
            wp_send_json_success( array( 'results' => array() ) );
        }

        $data_store = WC_Data_Store::load( 'product' );
        $found_ids  = $data_store->search_products( $term, '', true, false, 30 );
        $results    = array();

        foreach ( $found_ids as $found_id ) {

            $component = wc_get_product( $found_id );

            if ( ! $box->is_eligible_extra( $component ) ) {
                continue;
            }

            $image_url = wp_get_attachment_image_url(
                $component->get_image_id(),
                'woocommerce_thumbnail'
            );

            $results[] = array(
                'id'    => $component->get_id(),
                'name'  => $component->get_name(),
                'price' => (float) wc_get_price_to_display( $component ),
                'image' => $image_url ? $image_url : wc_placeholder_img_src( 'woocommerce_thumbnail' ),
                'max'   => $this->get_stock_cap( $component ),
            );

            if ( count( $results ) >= 10 ) {
                break;
            }
        }

        wp_send_json_success( array( 'results' => $results ) );
    }

    public function ajax_get_extra_recommendations() {

        if ( ! check_ajax_referer( 'boxbuilder_add_to_cart', 'nonce', false ) ) {
            wp_send_json_error(
                array( 'message' => __( 'La sesión ha caducado. Recarga la página.', 'boxbuilder-woocommerce' ) ),
                403
            );
        }

        $product_id = $this->get_posted_id( 'product_id' );
        $box        = wc_get_product( $product_id );

        if ( ! $box instanceof WC_Product_BoxBuilder || ! $box->get_allow_extras() ) {
            wp_send_json_error(
                array( 'message' => __( 'Esta caja no admite productos adicionales.', 'boxbuilder-woocommerce' ) ),
                403
            );
        }

        $source_id = $this->get_posted_id( 'source_id' );
        $source    = wc_get_product( $source_id );

        if ( ! $box->is_eligible_extra( $source ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Este producto ya no está disponible para intercambiar.', 'boxbuilder-woocommerce' ) ),
                400
            );
        }

        $recommendations = array();

        foreach ( $box->get_product_recommendations( $source->get_id(), 5 ) as $recommendation ) {

            $image_url = wp_get_attachment_image_url(
                $recommendation->get_image_id(),
                'woocommerce_thumbnail'
            );

            $recommendations[] = array(
                'id'    => $recommendation->get_id(),
                'name'  => $recommendation->get_name(),
                'price' => (float) wc_get_price_to_display( $recommendation ),
                'image' => $image_url ? $image_url : wc_placeholder_img_src( 'woocommerce_thumbnail' ),
                'url'   => $recommendation->get_permalink(),
                'max'   => $this->get_stock_cap( $recommendation ),
            );
        }

        wp_send_json_success( array( 'results' => $recommendations ) );
    }

    private function get_stock_cap( $component ) {

        if ( $component->managing_stock() && ! $component->backorders_allowed() ) {

            $stock = $component->get_stock_quantity();

            if ( null !== $stock ) {
                return max( 0, (int) $stock );
            }
        }

        return '';
    }

    private function get_selection( $cart_item ) {

        if ( ! isset( $cart_item[ self::ITEMS_KEY ] ) || ! is_array( $cart_item[ self::ITEMS_KEY ] ) ) {
            return null;
        }

        return $cart_item[ self::ITEMS_KEY ];
    }

    private function get_replacements( $cart_item ) {

        if (
            ! isset( $cart_item[ self::REPLACEMENTS_KEY ] )
            || ! is_array( $cart_item[ self::REPLACEMENTS_KEY ] )
        ) {
            return array();
        }

        return $cart_item[ self::REPLACEMENTS_KEY ];
    }

    private function get_posted_id( $key ) {

        if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
            return 0;
        }

        $value = wp_unslash( $_POST[ $key ] );

        return ctype_digit( (string) $value ) ? absint( $value ) : 0;
    }

    private function get_selection_for_validation( $box, $selection, $replacements ) {

        $quantities = array();
        $consumed   = array();

        foreach ( $box->get_box_items() as $slot_id => $settings ) {
            $product_id = isset( $replacements[ $slot_id ] )
                ? absint( $replacements[ $slot_id ] )
                : absint( $slot_id );

            if ( isset( $selection[ $product_id ] ) ) {
                $quantities[ $slot_id ] = absint( $selection[ $product_id ] );
                $consumed[ $product_id ] = true;
            }
        }

        foreach ( $selection as $product_id => $quantity ) {
            if ( ! isset( $consumed[ $product_id ] ) ) {
                $quantities[ $product_id ] = absint( $quantity );
            }
        }

        return $quantities;
    }

    private function get_lines( $selection ) {

        $lines = array();

        foreach ( $selection as $item_id => $quantity ) {

            $item_product = wc_get_product( $item_id );
            $name         = $item_product ? $item_product->get_name() : '#' . $item_id;

            $lines[] = sprintf( '%s × %d', $name, absint( $quantity ) );
        }

        return $lines;
    }

    public function reduce_components_stock( $order ) {

        $this->change_components_stock( $order, 'decrease' );
    }

    public function restore_components_stock( $order ) {

        $this->change_components_stock( $order, 'increase' );
    }

    private function change_components_stock( $order, $operation ) {

        $order = wc_get_order( $order );

        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items() as $order_item ) {
            if ( 'decrease' === $operation ) {
                $already_reduced = $order_item->get_meta( '_boxbuilder_components_stock_reduced', true );

                if ( is_array( $already_reduced ) ) {
                    continue;
                }

                $stock_map = $this->get_component_stock_quantities( $order_item );
                $reduced   = array();

                foreach ( $stock_map as $component_id => $quantity_per_box ) {
                    $component = wc_get_product( $component_id );
                    $quantity  = $quantity_per_box * max( 1, absint( $order_item->get_quantity() ) );

                    if ( ! $component || ! $component->managing_stock() ) {
                        continue;
                    }

                    if ( ! $component->has_enough_stock( $quantity ) ) {
                        $order->add_order_note(
                            sprintf(
                                /* translators: 1: component name, 2: component id. */
                                __( 'BoxBuilder ha detectado stock insuficiente de %1$s (ID %2$d) al reducir el pedido; se registrará el consumo igualmente.', 'boxbuilder-woocommerce' ),
                                $component->get_name(),
                                $component_id
                            )
                        );
                    }

                    $new_stock = wc_update_product_stock( $component, $quantity, 'decrease' );

                    if ( false === $new_stock || is_wp_error( $new_stock ) ) {
                        $order->add_order_note(
                            sprintf(
                                /* translators: %d: component id. */
                                __( 'BoxBuilder no pudo actualizar el stock del componente ID %d.', 'boxbuilder-woocommerce' ),
                                $component_id
                            )
                        );
                        continue;
                    }

                    $reduced[ $component_id ] = $quantity;
                }

                if ( ! empty( $reduced ) ) {
                    $order_item->update_meta_data( '_boxbuilder_components_stock_reduced', $reduced );
                    $order_item->delete_meta_data( '_boxbuilder_components_stock_restored' );
                    $order_item->save();
                }

                continue;
            }

            $reduced            = $order_item->get_meta( '_boxbuilder_components_stock_reduced', true );
            $is_legacy_stock_map = false;

            if ( ! is_array( $reduced ) ) {
                if (
                    'yes' === $order_item->get_meta( '_boxbuilder_components_stock_restored', true )
                    || ! $this->is_legacy_stock_map( $order_item )
                ) {
                    continue;
                }

                $reduced            = $this->get_component_stock_quantities( $order_item );
                $is_legacy_stock_map = true;

                foreach ( $reduced as $component_id => $quantity ) {
                    $reduced[ $component_id ] = $quantity * max( 1, absint( $order_item->get_quantity() ) );
                }
            }

            if ( empty( $reduced ) ) {
                continue;
            }

            $remaining = array();

            foreach ( $reduced as $component_id => $quantity ) {
                $component_id = absint( $component_id );
                $quantity     = absint( $quantity );
                $component    = wc_get_product( $component_id );

                if ( ! $component || ! $component->managing_stock() || ! $quantity ) {
                    $remaining[ $component_id ] = $quantity;

                    if ( ! $component || ! $component->managing_stock() ) {
                        $order->add_order_note(
                            sprintf(
                                /* translators: %d: component id. */
                                __( 'BoxBuilder no pudo restaurar el stock del componente ID %d porque el producto ya no está disponible para gestionar stock.', 'boxbuilder-woocommerce' ),
                                $component_id
                            )
                        );
                    }

                    continue;
                }

                $new_stock = wc_update_product_stock( $component, $quantity, 'increase' );

                if ( false === $new_stock || is_wp_error( $new_stock ) ) {
                    $remaining[ $component_id ] = $quantity;
                    $order->add_order_note(
                        sprintf(
                            /* translators: %d: component id. */
                            __( 'BoxBuilder no pudo restaurar el stock del componente ID %d.', 'boxbuilder-woocommerce' ),
                            $component_id
                        )
                    );
                }
            }

            if ( empty( $remaining ) ) {
                $order_item->delete_meta_data( '_boxbuilder_components_stock_reduced' );

                if ( $is_legacy_stock_map ) {
                    $order_item->update_meta_data( '_boxbuilder_components_stock_restored', 'yes' );
                }
            } else {
                $order_item->update_meta_data( '_boxbuilder_components_stock_reduced', $remaining );
            }

            $order_item->save();
        }
    }

    private function get_component_stock_quantities( $order_item ) {

        $stored = $order_item->get_meta( self::STOCK_META_KEY, true );

        if ( ! is_array( $stored ) || empty( $stored ) ) {
            $stored = $order_item->get_meta( '_boxbuilder_items', true );
        }

        if ( ! is_array( $stored ) ) {
            return array();
        }

        $quantities = array();

        foreach ( $stored as $key => $value ) {
            if ( is_array( $value ) ) {
                $component_id = isset( $value['id'] ) ? absint( $value['id'] ) : 0;
                $quantity     = isset( $value['qty'] ) ? absint( $value['qty'] ) : 0;
            } else {
                $component_id = is_scalar( $key ) && ctype_digit( (string) $key )
                    ? absint( $key )
                    : 0;
                $quantity = is_scalar( $value ) ? absint( $value ) : 0;
            }

            if ( ! $component_id || ! $quantity ) {
                continue;
            }

            $quantities[ $component_id ] = isset( $quantities[ $component_id ] )
                ? $quantities[ $component_id ] + $quantity
                : $quantity;
        }

        return $quantities;
    }

    private function is_legacy_stock_map( $order_item ) {

        if ( ! empty( $order_item->get_meta( self::STOCK_META_KEY, true ) ) ) {
            return false;
        }

        $legacy_items = $order_item->get_meta( '_boxbuilder_items', true );

        if ( ! is_array( $legacy_items ) || empty( $legacy_items ) ) {
            return false;
        }

        foreach ( $legacy_items as $component_id => $quantity ) {
            if (
                ! is_scalar( $component_id )
                || ! ctype_digit( (string) $component_id )
                || ! is_scalar( $quantity )
            ) {
                return false;
            }
        }

        return true;
    }

}
