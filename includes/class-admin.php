<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BoxBuilder_Admin {

    const STYLE_OPTION_NAME = 'boxbuilder_style_options';
    const STYLE_SETTINGS_GROUP = 'boxbuilder_style_settings';

    private $product_selector;

    public function __construct() {

        $this->product_selector = new BoxBuilder_Product_Selector();

        add_action(
            'admin_enqueue_scripts',
            array( $this, 'enqueue_admin_assets' )
        );
        add_action(
            'admin_menu',
            array( $this, 'add_style_settings_page' )
        );
        add_action(
            'admin_init',
            array( $this, 'register_style_settings' )
        );

        add_filter(
            'woocommerce_product_data_tabs',
            array( $this, 'add_product_tab' )
        );

        add_action(
            'woocommerce_product_data_panels',
            array( $this, 'render_product_panel' )
        );

        add_action(
            'woocommerce_admin_process_product_object',
            array( $this, 'save_product_data' )
        );
    }

    public static function get_default_style_options() {

        return array(
            'primary'      => '#6d5bd0',
            'primary_dark' => '#5542b2',
            'primary_light' => '#f1efff',
            'border'        => '#e8e7ef',
            'text'          => '#24232b',
            'muted'         => '#777584',
            'surface'       => '#ffffff',
            'background'    => '#f7f7fb',
        );
    }

    public static function get_style_options() {

        $options  = self::get_default_style_options();
        $stored   = get_option( self::STYLE_OPTION_NAME, array() );

        if ( ! is_array( $stored ) ) {
            return $options;
        }

        foreach ( $options as $key => $default ) {
            if ( isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) ) {
                $color = sanitize_hex_color( $stored[ $key ] );

                if ( $color ) {
                    $options[ $key ] = $color;
                }
            }
        }

        return $options;
    }

    public function add_style_settings_page() {

        add_submenu_page(
            'woocommerce',
            __( 'Estilos de BoxBuilder', 'boxbuilder-woocommerce' ),
            __( 'Estilos BoxBuilder', 'boxbuilder-woocommerce' ),
            'manage_woocommerce',
            'boxbuilder-style-settings',
            array( $this, 'render_style_settings_page' )
        );
    }

    public function register_style_settings() {

        register_setting(
            self::STYLE_SETTINGS_GROUP,
            self::STYLE_OPTION_NAME,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_style_options' ),
                'default'           => self::get_default_style_options(),
            )
        );

        add_filter(
            'option_page_capability_' . self::STYLE_SETTINGS_GROUP,
            function () {
                return 'manage_woocommerce';
            }
        );
    }

    public function sanitize_style_options( $input ) {

        $defaults = self::get_default_style_options();
        $input    = is_array( $input ) ? $input : array();
        $options  = array();

        foreach ( $defaults as $key => $default ) {
            $color = isset( $input[ $key ] ) && is_string( $input[ $key ] )
                ? sanitize_hex_color( $input[ $key ] )
                : false;

            if ( $color ) {
                $options[ $key ] = $color;
                continue;
            }

            $options[ $key ] = $default;

            if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
                add_settings_error(
                    self::STYLE_OPTION_NAME,
                    'invalid_' . $key,
                    sprintf(
                        /* translators: %s: etiqueta del color. */
                        __( 'El valor del color «%s» no es válido. Se ha restaurado su valor predeterminado.', 'boxbuilder-woocommerce' ),
                        $key
                    ),
                    'error'
                );
            }
        }

        return $options;
    }

    public function render_style_settings_page() {

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No tienes permisos para cambiar estos ajustes.', 'boxbuilder-woocommerce' ) );
        }

        $options = self::get_style_options();
        $labels  = array(
            'primary'       => __( 'Color principal', 'boxbuilder-woocommerce' ),
            'primary_dark'  => __( 'Color principal oscuro', 'boxbuilder-woocommerce' ),
            'primary_light' => __( 'Color principal claro', 'boxbuilder-woocommerce' ),
            'border'        => __( 'Color de bordes', 'boxbuilder-woocommerce' ),
            'text'          => __( 'Color de texto', 'boxbuilder-woocommerce' ),
            'muted'         => __( 'Color de texto secundario', 'boxbuilder-woocommerce' ),
            'surface'       => __( 'Color de las tarjetas', 'boxbuilder-woocommerce' ),
            'background'    => __( 'Color de fondo', 'boxbuilder-woocommerce' ),
        );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Estilos de BoxBuilder', 'boxbuilder-woocommerce' ); ?></h1>
            <p><?php esc_html_e( 'Personaliza los colores del constructor de cajas. Los cambios se aplican a todos los productos BoxBuilder.', 'boxbuilder-woocommerce' ); ?></p>
            <?php settings_errors(); ?>
            <form action="options.php" method="post">
                <?php settings_fields( self::STYLE_SETTINGS_GROUP ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <?php foreach ( $labels as $key => $label ) : ?>
                            <tr>
                                <th scope="row">
                                    <label for="boxbuilder-style-<?php echo esc_attr( $key ); ?>">
                                        <?php echo esc_html( $label ); ?>
                                    </label>
                                </th>
                                <td>
                                    <input
                                        type="color"
                                        id="boxbuilder-style-<?php echo esc_attr( $key ); ?>"
                                        name="<?php echo esc_attr( self::STYLE_OPTION_NAME . '[' . $key . ']' ); ?>"
                                        value="<?php echo esc_attr( $options[ $key ] ); ?>"
                                    >
                                    <code><?php echo esc_html( $options[ $key ] ); ?></code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button( __( 'Guardar colores', 'boxbuilder-woocommerce' ) ); ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_admin_assets( $hook ) {

        // Solo necesitamos el script en las pantallas de productos.
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        $screen = get_current_screen();

        if ( ! $screen || 'product' !== $screen->post_type ) {
            return;
        }

        wp_enqueue_script(
            'boxbuilder-admin-product-selector',
            BOXBUILDER_URL . 'assets/js/admin-product-selector.js',
            array( 'jquery', 'selectWoo', 'jquery-ui-sortable' ),
            BOXBUILDER_VERSION,
            true
        );
    }

    public function add_product_tab( $tabs ) {

        $tabs['boxbuilder'] = array(
            'label'    => __( 'BoxBuilder', 'boxbuilder-woocommerce' ),
            'target'   => 'boxbuilder_product_data',
            'class'    => array( 'show_if_boxbuilder' ),
            'priority' => 80,
        );

        return $tabs;
    }

    public function render_product_panel() {

        global $post;

        $product = wc_get_product( $post->ID );

        if ( ! $product ) {
            return;
        }

        $current_mode = 'custom' === $product->get_meta( '_boxbuilder_mode', true )
            ? 'custom'
            : 'fixed';
        $current_pricing = in_array(
            $product->get_meta( '_boxbuilder_pricing', true ),
            array( 'fixed', 'adjusted' ),
            true
        )
            ? $product->get_meta( '_boxbuilder_pricing', true )
            : 'dynamic';
        $allow_extras = 'no' === $product->get_meta( '_boxbuilder_allow_extras', true )
            ? 'no'
            : 'yes';

        ?>
        <div id="boxbuilder_product_data"
            class="panel woocommerce_options_panel hidden show_if_boxbuilder">

            <div class="options_group">

                <?php
                woocommerce_wp_select(
                    array(
                        'id'          => '_boxbuilder_mode',
                        'label'       => __( 'Modalidad de caja', 'boxbuilder-woocommerce' ),
                        'description' => __( 'Prediseñada: comienza con las cantidades predeterminadas. Personalizada: comienza desde cero o desde los mínimos obligatorios.', 'boxbuilder-woocommerce' ),
                        'desc_tip'    => true,
                        'value'       => $current_mode,
                        'options'     => array(
                            'fixed'  => __( 'Caja prediseñada modificable', 'boxbuilder-woocommerce' ),
                            'custom' => __( 'Caja personalizada desde cero', 'boxbuilder-woocommerce' ),
                        ),
                    )
                );
                ?>

            </div>

            <div class="options_group">

                <?php
                woocommerce_wp_select(
                    array(
                        'id'          => '_boxbuilder_pricing',
                        'label'       => __( 'Sistema de precios', 'boxbuilder-woocommerce' ),
                        'description' => __( 'El precio fijo usa el precio base; el dinámico suma los productos; el ajustado aplica al precio base la diferencia respecto al contenido predeterminado.', 'boxbuilder-woocommerce' ),
                        'desc_tip'    => true,
                        'value'       => $current_pricing,
                        'options'     => array(
                            'fixed'    => __( 'Precio fijo', 'boxbuilder-woocommerce' ),
                            'dynamic'  => __( 'Precio dinámico', 'boxbuilder-woocommerce' ),
                            'adjusted' => __( 'Precio base ± diferencia', 'boxbuilder-woocommerce' ),
                        ),
                    )
                );

                woocommerce_wp_checkbox(
                    array(
                        'id'          => '_boxbuilder_allow_extras',
                        'label'       => __( 'Productos adicionales', 'boxbuilder-woocommerce' ),
                        'description' => __( 'Permitir que el comprador busque y añada otros productos simples o variaciones.', 'boxbuilder-woocommerce' ),
                        'value'       => $allow_extras,
                        'cbvalue'     => 'yes',
                    )
                );
                ?>

            </div>

            <div class="options_group">

                <?php
                woocommerce_wp_text_input(
                    array(
                        'id'                => '_boxbuilder_min_products',
                        'label'             => __( 'Cantidad mínima', 'boxbuilder-woocommerce' ),
                        'description'       => __( 'Número mínimo de productos que deberá contener la caja.', 'boxbuilder-woocommerce' ),
                        'desc_tip'          => true,
                        'type'              => 'number',
                        'custom_attributes' => array(
                            'min'  => '0',
                            'step' => '1',
                        ),
                    )
                );

                woocommerce_wp_text_input(
                    array(
                        'id'                => '_boxbuilder_max_products',
                        'label'             => __( 'Cantidad máxima', 'boxbuilder-woocommerce' ),
                        'description'       => __( 'Limita las unidades totales de la caja y el número de productos distintos configurados. Déjalo vacío si no hay límite.', 'boxbuilder-woocommerce' ),
                        'desc_tip'          => true,
                        'type'              => 'number',
                        'custom_attributes' => array(
                            'min'  => '0',
                            'step' => '1',
                        ),
                    )
                );
                ?>

            </div>

            <?php
            // Buscador de productos y variaciones.
            $this->product_selector->render_field( $product );
            ?>

        </div>
        <?php
    }


    public function save_product_data( $product ) {

        if ( 'boxbuilder' !== $product->get_type() ) {
            return;
        }

        $nonce = isset( $_POST['woocommerce_meta_nonce'] ) && is_scalar( $_POST['woocommerce_meta_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) )
            : '';

        if (
            ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || ! current_user_can( 'edit_post', $product->get_id() )
            || ! wp_verify_nonce( $nonce, 'woocommerce_save_data' )
        ) {
            return;
        }

        $errors = array();
        $min    = $product->get_min_products();
        $max = $product->get_max_products();

        if ( isset( $_POST['_boxbuilder_min_products'] ) ) {
            $raw_min = wp_unslash( $_POST['_boxbuilder_min_products'] );

            if ( is_scalar( $raw_min ) && '' === (string) $raw_min ) {
                $min = 0;
            } elseif ( ! is_scalar( $raw_min ) || ! ctype_digit( (string) $raw_min ) ) {
                $errors[] = __( 'La cantidad mínima global debe ser un entero no negativo.', 'boxbuilder-woocommerce' );
            } else {
                $min = absint( $raw_min );
            }
        }

        if ( isset( $_POST['_boxbuilder_max_products'] ) ) {
            $raw_max = wp_unslash( $_POST['_boxbuilder_max_products'] );

            if ( ! is_scalar( $raw_max ) ) {
                $errors[] = __( 'La cantidad máxima global no es válida.', 'boxbuilder-woocommerce' );
            } else {
                $raw_max = sanitize_text_field( (string) $raw_max );

                if ( '' === $raw_max ) {
                    $max = '';
                } elseif ( ctype_digit( $raw_max ) ) {
                    $max = absint( $raw_max );
                } else {
                    $errors[] = __( 'La cantidad máxima global debe ser un entero no negativo o estar vacía.', 'boxbuilder-woocommerce' );
                }
            }
        }

        $items = $this->product_selector->get_submitted_products( $product->get_id() );

        $errors = array_merge( $errors, WC_Product_BoxBuilder::validate_limits( $max, $items ) );

        if ( '' !== $max && $min > $max ) {
            $errors[] = __( 'La cantidad mínima global no puede superar la cantidad máxima global.', 'boxbuilder-woocommerce' );
        }

        if ( ! empty( $errors ) ) {
            if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
                foreach ( array_unique( $errors ) as $error ) {
                    WC_Admin_Meta_Boxes::add_error( $error );
                }

                WC_Admin_Meta_Boxes::add_error(
                    __( 'BoxBuilder: no se ha guardado la configuración. Corrige los valores e inténtalo de nuevo.', 'boxbuilder-woocommerce' )
                );
            }

            return;
        }

        if ( isset( $_POST['_boxbuilder_mode'] ) && is_scalar( $_POST['_boxbuilder_mode'] ) ) {
            $mode = sanitize_key( wp_unslash( $_POST['_boxbuilder_mode'] ) );

            if ( in_array( $mode, array( 'fixed', 'custom' ), true ) ) {
                $product->update_meta_data( '_boxbuilder_mode', $mode );
            }
        }

        if ( isset( $_POST['_boxbuilder_pricing'] ) && is_scalar( $_POST['_boxbuilder_pricing'] ) ) {
            $pricing = sanitize_key( wp_unslash( $_POST['_boxbuilder_pricing'] ) );

            if ( in_array( $pricing, array( 'fixed', 'dynamic', 'adjusted' ), true ) ) {
                $product->update_meta_data( '_boxbuilder_pricing', $pricing );
            }
        }

        $product->update_meta_data(
            '_boxbuilder_allow_extras',
            isset( $_POST['_boxbuilder_allow_extras'] )
                && is_scalar( $_POST['_boxbuilder_allow_extras'] )
                && 'yes' === sanitize_key( wp_unslash( $_POST['_boxbuilder_allow_extras'] ) )
                    ? 'yes'
                    : 'no'
        );
        $product->update_meta_data( '_boxbuilder_min_products', $min );
        $product->update_meta_data( '_boxbuilder_max_products', $max );
        $product->update_meta_data( '_boxbuilder_products', $items );
    }
}
