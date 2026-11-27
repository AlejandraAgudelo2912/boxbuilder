
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<?php
    $pricing_mode = $product->get_pricing_mode();

    $fixed_price = wc_get_price_to_display( $product );
?>

<div
    class="boxbuilder-builder"
    data-pricing-mode="<?php echo esc_attr( $pricing_mode ); ?>"
    data-fixed-price="<?php echo esc_attr( $fixed_price ); ?>"
    data-currency="<?php echo esc_attr( get_woocommerce_currency() ); ?>"
    data-locale="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>"
    data-mode="<?php echo esc_attr( $box_mode ); ?>"
    data-min-products="<?php echo esc_attr( $min_products ); ?>"
    data-max-products="<?php echo esc_attr( $max_products ); ?>"
    data-allow-extras="<?php echo esc_attr( $allow_extras ? 'yes' : 'no' ); ?>"
    data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
    data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
    data-nonce="<?php echo esc_attr( wp_create_nonce( 'boxbuilder_add_to_cart' ) ); ?>"
>
    <header class="boxbuilder-header">
        <span class="boxbuilder-eyebrow">Crea tu selección</span>
        <h2>Personaliza tu caja</h2>
        <p>Elige los productos que más te gusten y ajusta la cantidad de cada uno.</p>

        <?php if ( '' !== $max_products ) : ?>

            <?php
            // Aviso del máximo GLOBAL: suma de unidades de todos los productos.
            $max_label = '<strong>' . esc_html( number_format_i18n( $max_products ) ) . '</strong>';

            if ( 1 === $max_products ) {
                $limit_message = sprintf(
                    /* translators: %s: número máximo de productos. */
                    esc_html__( 'Esta caja admite un máximo de %s producto en total.', 'boxbuilder-woocommerce' ),
                    $max_label
                );
            } else {
                $limit_message = sprintf(
                    /* translators: %s: número máximo de productos. */
                    esc_html__( 'Esta caja admite un máximo de %s productos en total. Puedes combinar los productos como quieras, siempre que no superes este límite.', 'boxbuilder-woocommerce' ),
                    $max_label
                );
            }
            ?>

            <p class="boxbuilder-limit-notice" role="note">
                <span class="boxbuilder-limit-notice-icon" aria-hidden="true">i</span>
                <span><?php echo wp_kses( $limit_message, array( 'strong' => array() ) ); ?></span>
            </p>

        <?php endif; ?>
    </header>

    <form
        class="boxbuilder-cart-form"
        method="post"
        action="<?php echo esc_url( $product->get_permalink() ); ?>"
    >

    <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>">
    <input type="hidden" name="quantity" value="1">

    <div class="boxbuilder-layout">

        <section class="boxbuilder-products">

            <div class="boxbuilder-section-heading">
                <h3>Elige tus productos</h3>
                <span><?php echo esc_html( count( $box_products ) ); ?> disponibles</span>
            </div>

            <?php if ( empty( $box_products ) ) : ?>

                <p class="boxbuilder-empty">
                    Esta caja todavía no tiene productos disponibles.
                </p>

            <?php else : ?>

                <div class="boxbuilder-products-grid">

                    <?php foreach ( $box_products as $item ) : ?>

                        <?php
                        $item_product = $item['product'];
                        $settings     = $item['settings'];
                        $recommendations = $item['recommendations'];
                        $has_alternatives = $item['has_alternatives'];
                        $has_minimum = absint( $settings['min'] ) > 0;
                        $can_replace = ! $has_minimum && $has_alternatives;
                        $replace_title = __( 'Ver productos recomendados', 'boxbuilder-woocommerce' );

                        if ( $has_minimum ) {
                            $replace_title = __( 'Los productos con cantidad mínima no se pueden intercambiar.', 'boxbuilder-woocommerce' );
                        } elseif ( ! $has_alternatives ) {
                            $replace_title = __( 'Añade etiquetas compartidas a otros productos para ver recomendaciones.', 'boxbuilder-woocommerce' );
                        }
                        ?>

                        <article
                            class="boxbuilder-product-card"
                            data-slot-id="<?php echo esc_attr( $item_product->get_id() ); ?>"
                            data-product-id="<?php echo esc_attr( $item_product->get_id() ); ?>"
                            data-price="<?php echo esc_attr( wc_get_price_to_display( $item_product ) ); ?>"
                            data-recommendations="<?php echo esc_attr( wp_json_encode( $recommendations ) ); ?>"
                        >

                            <div class="boxbuilder-product-image">
                                <?php
                                echo $item_product->get_image(
                                    'woocommerce_thumbnail'
                                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                ?>
                            </div>

                            <div class="boxbuilder-product-actions">
                                <button
                                    type="button"
                                    class="boxbuilder-product-recommend"
                                    aria-label="<?php echo esc_attr( sprintf( __( 'Cambiar %s por un producto recomendado', 'boxbuilder-woocommerce' ), $item_product->get_name() ) ); ?>"
                                    title="<?php echo esc_attr( $replace_title ); ?>"
                                    <?php disabled( ! $can_replace ); ?>
                                ><span aria-hidden="true">↔</span></button>

                                <button
                                    type="button"
                                    class="boxbuilder-product-remove"
                                    aria-label="<?php echo esc_attr( sprintf( __( 'Quitar %s de la caja', 'boxbuilder-woocommerce' ), $item_product->get_name() ) ); ?>"
                                ><span aria-hidden="true">×</span></button>
                            </div>

                            <div class="boxbuilder-product-info">

                                <h4>
                                    <a
                                        class="boxbuilder-product-link"
                                        href="<?php echo esc_url( $item_product->get_permalink() ); ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <span class="boxbuilder-product-name"><?php echo esc_html( $item_product->get_name() ); ?></span>
                                        <span class="boxbuilder-sr-only"> (se abre en una pestaña nueva)</span>
                                    </a>
                                </h4>

                                <div class="boxbuilder-product-price">
                                    <?php echo wp_kses_post( $item_product->get_price_html() ); ?>
                                </div>

                                <div class="boxbuilder-quantity">

                                    <button
                                        type="button"
                                        class="boxbuilder-quantity-minus"
                                        aria-label="Reducir cantidad"
                                    >−</button>

                                    <input
                                        type="number"
                                        class="boxbuilder-quantity-input"
                                        name="boxbuilder_quantities[<?php echo esc_attr( $item_product->get_id() ); ?>]"
                                        value="<?php echo esc_attr( $settings['default'] ); ?>"
                                        min="<?php echo esc_attr( $settings['min'] ); ?>"
                                        <?php if ( '' !== $settings['max'] ) : ?>
                                            max="<?php echo esc_attr( $settings['max'] ); ?>"
                                        <?php endif; ?>
                                        readonly
                                    >

                                    <input
                                        type="hidden"
                                        class="boxbuilder-replacement-input"
                                        name="boxbuilder_replacements[<?php echo esc_attr( $item_product->get_id() ); ?>]"
                                        value=""
                                    >

                                    <button
                                        type="button"
                                        class="boxbuilder-quantity-plus"
                                        aria-label="Aumentar cantidad"
                                    >+</button>

                                </div>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

            <?php if ( $allow_extras ) : ?>
                <?php $search_id = wp_unique_id( 'boxbuilder-search-' ); ?>

                <div class="boxbuilder-search">
                    <button
                        type="button"
                        class="boxbuilder-search-toggle"
                        aria-expanded="false"
                        aria-controls="<?php echo esc_attr( $search_id ); ?>-panel"
                    >
                        <span class="boxbuilder-search-toggle-icon" aria-hidden="true">+</span>
                        <span><?php esc_html_e( 'Añadir otros productos de la tienda', 'boxbuilder-woocommerce' ); ?></span>
                    </button>

                    <div
                        class="boxbuilder-search-panel"
                        id="<?php echo esc_attr( $search_id ); ?>-panel"
                        hidden
                    >
                        <label for="<?php echo esc_attr( $search_id ); ?>">
                            <?php esc_html_e( 'Busca un producto', 'boxbuilder-woocommerce' ); ?>
                        </label>

                        <input
                            type="search"
                            id="<?php echo esc_attr( $search_id ); ?>"
                            class="boxbuilder-search-input"
                            placeholder="<?php esc_attr_e( 'Busca por nombre…', 'boxbuilder-woocommerce' ); ?>"
                            autocomplete="off"
                        >

                        <p class="boxbuilder-search-status" role="status" hidden></p>

                        <ul class="boxbuilder-search-results" hidden></ul>
                    </div>
                </div>
            <?php endif; ?>

        </section>

        <aside class="boxbuilder-summary">

            <div class="boxbuilder-summary-heading">
                <h3>Tu selección</h3>
                <span class="boxbuilder-summary-count">0 seleccionados</span>
            </div>

            <p class="boxbuilder-summary-empty">
                Todavía no has seleccionado ningún producto.
            </p>

            <div class="boxbuilder-summary-items"></div>

            <div class="boxbuilder-summary-total">
                <span>Total</span>
                <strong class="boxbuilder-total-price">
                    <?php echo wp_kses_post( wc_price( 0 ) ); ?>
                </strong>
            </div>

            <button type="submit" class="boxbuilder-add-to-cart" disabled>
                Añadir caja al carrito
            </button>

        </aside>

    </div>

    <dialog class="boxbuilder-recommendation-dialog" aria-label="<?php esc_attr_e( 'Productos recomendados', 'boxbuilder-woocommerce' ); ?>">
        <div class="boxbuilder-recommendation-dialog-content">
            <button type="button" class="boxbuilder-recommendation-close" aria-label="<?php esc_attr_e( 'Cerrar recomendaciones', 'boxbuilder-woocommerce' ); ?>">×</button>
            <h2><?php esc_html_e( 'Productos recomendados', 'boxbuilder-woocommerce' ); ?></h2>
            <p><?php esc_html_e( 'Elige un producto relacionado por etiquetas para sustituir el actual.', 'boxbuilder-woocommerce' ); ?></p>
            <div class="boxbuilder-recommendation-list"></div>
            <p class="boxbuilder-recommendation-empty" hidden>
                <?php esc_html_e( 'No hay más productos recomendados disponibles.', 'boxbuilder-woocommerce' ); ?>
            </p>
        </div>
    </dialog>

    </form>

</div>
