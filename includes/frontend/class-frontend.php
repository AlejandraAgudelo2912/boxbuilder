
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BoxBuilder_Frontend {

    private $rendered_products = array();

    public function __construct() {

        // Registrar los shortcodes de BoxBuilder.
        add_shortcode(
            'boxbuilder',
            array( $this, 'render_shortcode' )
        );

        // Mostrar el constructor completo debajo de la descripción corta.
        add_action(
            'woocommerce_single_product_summary',
            array( $this, 'render_product_builder' ),
            25
        );
        add_filter(
            'render_block_core/post-excerpt',
            array( $this, 'append_builder_to_product_excerpt' ),
            10,
            3
        );
        add_action(
            'wp_enqueue_scripts',
            array( $this, 'enqueue_frontend_assets' )
        );

        // Validar en el servidor la caja antes de añadirla al carrito.
        add_filter(
            'woocommerce_add_to_cart_validation',
            array( $this, 'validate_add_to_cart' ),
            10,
            3
        );

    }

    public function enqueue_frontend_assets() {

        $should_load = is_product();

        global $post;

        if (
            ! $should_load &&
            $post &&
            has_shortcode( $post->post_content, 'boxbuilder' )
        ) {
            $should_load = true;
        }

        if ( ! $should_load ) {
            return;
        }

        wp_enqueue_style(
            'boxbuilder-frontend',
            BOXBUILDER_URL . 'assets/css/frontend-builder.css',
            array(),
            filemtime( BOXBUILDER_PATH . 'assets/css/frontend-builder.css' )
        );

        $style_options = BoxBuilder_Admin::get_style_options();
        $css_variables = '';

        foreach ( $style_options as $key => $color ) {
            $css_variable  = '--bb-' . str_replace( '_', '-', $key );
            $css_variables .= $css_variable . ':' . esc_attr( $color ) . ';';
        }

        wp_add_inline_style(
            'boxbuilder-frontend',
            '.boxbuilder-builder{' . $css_variables . '}'
        );

        wp_enqueue_style(
            'boxbuilder-frontend-extras',
            BOXBUILDER_URL . 'assets/css/frontend-builder-extras.css',
            array( 'boxbuilder-frontend' ),
            filemtime( BOXBUILDER_PATH . 'assets/css/frontend-builder-extras.css' )
        );

        wp_enqueue_script(
            'boxbuilder-frontend',
            BOXBUILDER_URL . 'assets/js/frontend-builder.js',
            array(),
            filemtime( BOXBUILDER_PATH . 'assets/js/frontend-builder.js' ),
            true
        );
    }

    public function render_shortcode( $atts ) {

        $atts = shortcode_atts(
            array(
                'id' => 0,
            ),
            $atts,
            'boxbuilder'
        );

        $product_id = is_scalar( $atts['id'] ) ? absint( $atts['id'] ) : 0;

        if ( ! $product_id ) {
            return '<p>Debes indicar el ID de una caja BoxBuilder.</p>';
        }

        $product = wc_get_product( $product_id );

        if (
            ! ( $product instanceof WC_Product_BoxBuilder )
            || ! $product->is_purchasable()
        ) {
            return '<p>La caja seleccionada no es válida.</p>';
        }

        if ( isset( $this->rendered_products[ $product_id ] ) ) {
            return '';
        }

        $this->rendered_products[ $product_id ] = true;

        return $this->render_builder( $product );
    }

    public function render_product_builder() {

        if ( $this->template_has_product_excerpt_block() ) {
            return;
        }

        echo $this->get_product_section( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function append_builder_to_product_excerpt( $block_content, $parsed_block, $block ) {

        $product_id = isset( $block->context['postId'] ) && is_scalar( $block->context['postId'] )
            ? absint( $block->context['postId'] )
            : get_the_ID();

        return $block_content . $this->get_product_section( $product_id );
    }

    private function template_has_product_excerpt_block() {

        global $_wp_current_template_content;

        return is_string( $_wp_current_template_content )
            && has_block( 'core/post-excerpt', $_wp_current_template_content );
    }

    private function get_product_section( $product_id ) {

        $product_id = is_scalar( $product_id ) ? absint( $product_id ) : 0;

        if ( ! $product_id || isset( $this->rendered_products[ $product_id ] ) ) {
            return '';
        }

        $product = wc_get_product( $product_id );

        if (
            ! ( $product instanceof WC_Product_BoxBuilder )
            || ! $product->is_purchasable()
        ) {
            return '';
        }

        $this->rendered_products[ $product_id ] = true;

        return '<div class="boxbuilder-product-section">'
            . $this->render_builder( $product )
            . '</div>';
    }

    public function validate_add_to_cart( $passed, $product_id, $quantity = 1 ) {

        $product_id = is_scalar( $product_id ) ? absint( $product_id ) : 0;
        $product    = wc_get_product( $product_id );

        if ( ! ( $product instanceof WC_Product_BoxBuilder ) ) {
            return $passed;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $quantities = isset( $_REQUEST['boxbuilder_quantities'] )
            ? wp_unslash( $_REQUEST['boxbuilder_quantities'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : array();
        $replacements = isset( $_REQUEST['boxbuilder_replacements'] )
            ? wp_unslash( $_REQUEST['boxbuilder_replacements'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : array();

        $errors = $product->validate_selection( $quantities, $quantity, $replacements );

        if ( empty( $errors ) ) {
            return $passed;
        }

        foreach ( $errors as $error ) {
            wc_add_notice( $error, 'error' );
        }

        return false;
    }

    private function render_builder( $product ) {

        // Recuperar los límites generales de unidades de la caja.
        $min_products = $product->get_min_products();
        $max_products = $product->get_max_products();
        $box_mode     = $product->get_box_mode();
        $allow_extras = $product->get_allow_extras();

        $box_products    = array();
        $reference_total = (float) $product->get_reference_value( true );
        $stock_cap       = function ( $candidate ) {
            if ( ! $candidate->managing_stock() || $candidate->backorders_allowed() ) {
                return '';
            }

            $stock = $candidate->get_stock_quantity();

            return null === $stock ? '' : max( 0, (int) $stock );
        };

        // Preparar productos, respetando disponibilidad y stock en el selector.
        foreach ( $product->get_box_items() as $item_id => $settings ) {

            $item_product = wc_get_product( $item_id );

            if ( ! $item_product ) {
                continue;
            }

            $minimum  = absint( $settings['min'] );
            $required = $minimum > 0
                || ( isset( $settings['required'] ) && 'yes' === $settings['required'] );
            $backorders_allowed = $item_product->backorders_allowed();
            $available = $item_product->is_purchasable()
                && ( $item_product->is_in_stock() || $backorders_allowed );

            if ( $available && $item_product->managing_stock() && ! $backorders_allowed ) {
                $stock = $stock_cap( $item_product );

                if ( '' !== $stock ) {
                    $settings['max'] = '' === $settings['max']
                        ? $stock
                        : min( $settings['max'], $stock );

                    if ( 0 === $stock || $settings['max'] < $minimum ) {
                        $available = false;
                    }
                }
            }

            if ( ! $available && ! $required ) {
                continue;
            }

            $settings['required'] = $required ? 'yes' : 'no';

            if ( ! $available ) {
                $settings['max']     = 0;
                $settings['default'] = 0;
            } else {
                $initial_quantity = 'custom' === $box_mode
                    ? $minimum
                    : absint( $settings['default'] );
                $initial_quantity = max( $minimum, $initial_quantity );

                if ( '' !== $settings['max'] ) {
                    $initial_quantity = min( $initial_quantity, $settings['max'] );
                }

                $settings['default'] = $initial_quantity;
            }

            $recommendations = array();

            $source_image_url = wp_get_attachment_image_url(
                $item_product->get_image_id(),
                'woocommerce_thumbnail'
            );
            $recommendations[] = array(
                'id'    => $item_product->get_id(),
                'name'  => $item_product->get_name(),
                'price' => wc_get_price_to_display( $item_product ),
                'image' => $source_image_url ? $source_image_url : wc_placeholder_img_src( 'woocommerce_thumbnail' ),
                'url'   => $item_product->get_permalink(),
                'max'   => $stock_cap( $item_product ),
            );

            $alternatives = $product->get_recommended_replacements( $item_id, 5 );

            foreach ( $alternatives as $recommendation ) {
                $image_url = wp_get_attachment_image_url(
                    $recommendation->get_image_id(),
                    'woocommerce_thumbnail'
                );

                $recommendations[] = array(
                    'id'    => $recommendation->get_id(),
                    'name'  => $recommendation->get_name(),
                    'price' => wc_get_price_to_display( $recommendation ),
                    'image' => $image_url ? $image_url : wc_placeholder_img_src( 'woocommerce_thumbnail' ),
                    'url'   => $recommendation->get_permalink(),
                    'max'   => $stock_cap( $recommendation ),
                );
            }

            $box_products[] = array(
                'product'         => $item_product,
                'settings'        => $settings,
                'recommendations' => $recommendations,
                'has_alternatives' => ! empty( $alternatives ),
            );
        }

        // Cargar la plantilla.
        ob_start();

        include BOXBUILDER_PATH . 'templates/frontend/builder.php';

        return ob_get_clean();
    }

}
