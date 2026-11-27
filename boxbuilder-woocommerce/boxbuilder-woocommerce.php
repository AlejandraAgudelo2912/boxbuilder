
<?php
/**
 * Plugin Name: BoxBuilder for WooCommerce
 * Description: Crea paquetes y cajas de productos personalizables para WooCommerce.
 * Version: 0.3.0
 * Author: BoxBuilder
 * Text Domain: boxbuilder-woocommerce
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BOXBUILDER_VERSION', '0.3.0' );
define( 'BOXBUILDER_PATH', plugin_dir_path( __FILE__ ) );
define( 'BOXBUILDER_URL', plugin_dir_url( __FILE__ ) );

function boxbuilder_init() {

    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    // Clase del producto personalizado.
    require_once BOXBUILDER_PATH . 'includes/class-wc-product-boxbuilder.php';

    // Clases administrativas.
    require_once BOXBUILDER_PATH . 'includes/admin/class-product-selector.php';
    require_once BOXBUILDER_PATH . 'includes/class-admin.php';

    // Clase del frontend.
    require_once BOXBUILDER_PATH . 'includes/frontend/class-frontend.php';
    require_once BOXBUILDER_PATH . 'includes/frontend/class-cart.php';

    // Inicializar administración.
    new BoxBuilder_Admin();

    // Inicializar interfaz pública.
    new BoxBuilder_Frontend();

    // Carrito, precios y pedidos.
    new BoxBuilder_Cart();
}

add_action( 'woocommerce_loaded', 'boxbuilder_init', 20 );

function boxbuilder_add_product_type( $types ) {

    $types['boxbuilder'] = 'BoxBuilder Bundle';

    return $types;
}

add_filter( 'product_type_selector', 'boxbuilder_add_product_type' );

function boxbuilder_product_class( $classname, $product_type, $product_id ) {

    if ( 'boxbuilder' === $product_type ) {
        return 'WC_Product_BoxBuilder';
    }

    return $classname;
}

add_filter(
    'woocommerce_product_class',
    'boxbuilder_product_class',
    10,
    3
);
