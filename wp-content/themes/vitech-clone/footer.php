<?php

if (!defined('ABSPATH')) {
    exit;
}

$phone_primary = vitech_clone_option('phone_primary', '0988 888 888');
$address = vitech_clone_option('company_address', 'TP. Hồ Chí Minh, Việt Nam');
$categories = get_terms([
    'taxonomy' => 'elevator_category',
    'hide_empty' => false,
    'number' => 5,
]);
?>
    <footer class="site-footer" id="lien-he">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col">
                    <h3><?php bloginfo('name'); ?></h3>
                    <p><?php echo esc_html($address); ?></p>
                    <p><a href="<?php echo esc_url(vitech_clone_phone_href($phone_primary)); ?>"><?php echo esc_html($phone_primary); ?></a></p>
                </div>
                <div class="footer-col">
                    <h3><?php esc_html_e('Sản phẩm', 'vitech-clone'); ?></h3>
                    <ul>
                        <?php if (!is_wp_error($categories) && $categories) : ?>
                            <?php foreach ($categories as $category) : ?>
                                <li><a href="<?php echo esc_url(get_term_link($category)); ?>"><?php echo esc_html($category->name); ?></a></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="footer-col">
                    <h3><?php esc_html_e('Liên kết', 'vitech-clone'); ?></h3>
                    <?php
                    wp_nav_menu([
                        'theme_location' => 'footer',
                        'container' => false,
                        'fallback_cb' => 'vitech_clone_footer_fallback',
                    ]);
                    ?>
                </div>
            </div>
            <div class="copyright">© <?php echo esc_html(gmdate('Y')); ?> <?php bloginfo('name'); ?>.</div>
        </div>
    </footer>

    <div class="floating-contact">
        <a href="<?php echo esc_url(vitech_clone_phone_href($phone_primary)); ?>"><?php esc_html_e('Gọi ngay', 'vitech-clone'); ?></a>
        <a href="<?php echo esc_url('https://zalo.me/' . preg_replace('/[^0-9]/', '', $phone_primary)); ?>">Zalo</a>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
