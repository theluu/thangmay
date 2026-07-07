<?php

if (!defined('ABSPATH')) {
    exit;
}

$phone_primary = vitech_clone_option('phone_primary', '0988 888 888');
$phone_secondary = vitech_clone_option('phone_secondary', '0909 999 999');
$address = vitech_clone_option('company_address', 'TP. Hồ Chí Minh, Việt Nam');
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="site-shell">
    <div class="top-strip">
        <div class="container">
            <div class="top-strip__address"><?php echo esc_html($address); ?></div>
            <div class="top-strip__phones">
                <a href="<?php echo esc_url(vitech_clone_phone_href($phone_primary)); ?>"><?php echo esc_html($phone_primary); ?></a>
                <a href="<?php echo esc_url(vitech_clone_phone_href($phone_secondary)); ?>"><?php echo esc_html($phone_secondary); ?></a>
            </div>
        </div>
    </div>

    <header class="main-header">
        <div class="container">
            <a class="brand" href="<?php echo esc_url(home_url('/')); ?>">
                <span class="brand__logo">TM</span>
                <span class="brand__name">
                    <strong><?php bloginfo('name'); ?></strong>
                    <span><?php bloginfo('description'); ?></span>
                </span>
            </a>
            <form class="search-box" action="<?php echo esc_url(home_url('/')); ?>" method="get">
                <input type="search" name="s" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="<?php esc_attr_e('Tìm kiếm thang máy, linh kiện...', 'vitech-clone'); ?>">
                <button type="submit"><?php esc_html_e('Tìm kiếm', 'vitech-clone'); ?></button>
            </form>
            <a class="header-hotline" href="<?php echo esc_url(vitech_clone_phone_href($phone_primary)); ?>">
                <span><?php esc_html_e('Hotline tư vấn', 'vitech-clone'); ?></span>
                <strong><?php echo esc_html($phone_primary); ?></strong>
            </a>
        </div>
    </header>

    <nav class="nav-bar">
        <div class="container">
            <a class="category-toggle" href="<?php echo esc_url(home_url('/#san-pham')); ?>">
                <span><i></i><i></i><i></i></span>
                <?php esc_html_e('Danh mục sản phẩm', 'vitech-clone'); ?>
            </a>
            <?php
            wp_nav_menu([
                'theme_location' => 'primary',
                'container' => false,
                'menu_class' => 'main-menu',
                'fallback_cb' => 'vitech_clone_default_menu',
            ]);
            ?>
        </div>
    </nav>
