<?php

$pages = [
    'Giới thiệu' => ['slug' => 'gioi-thieu', 'content' => 'Nội dung giới thiệu có thể chỉnh sửa trong WordPress admin.'],
    'Sản phẩm' => ['slug' => 'san-pham', 'content' => 'Trang tổng hợp sản phẩm thang máy.'],
    'Tài liệu' => ['slug' => 'tai-lieu', 'content' => 'Tài liệu kỹ thuật và hồ sơ sản phẩm.'],
    'Yêu cầu báo giá' => ['slug' => 'yeu-cau-bao-gia', 'content' => 'Gửi yêu cầu báo giá để được tư vấn.'],
    'Tin tức' => ['slug' => 'tin-tuc', 'content' => 'Tin tức và tư vấn về thang máy.'],
    'Liên hệ' => ['slug' => 'lien-he', 'content' => 'Thông tin liên hệ và hỗ trợ khách hàng.'],
];

$page_ids = [];
foreach ($pages as $title => $page) {
    $existing = get_page_by_path($page['slug'], OBJECT, 'page');
    if ($existing) {
        $page_ids[$page['slug']] = (int) $existing->ID;
        continue;
    }

    $page_ids[$page['slug']] = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $page['slug'],
        'post_content' => '<p>' . esc_html($page['content']) . '</p>',
    ]);
}

$menu_name = 'Menu chính';
$menu = wp_get_nav_menu_object($menu_name);
$menu_id = $menu ? (int) $menu->term_id : wp_create_nav_menu($menu_name);

if ($menu_id && !is_wp_error($menu_id)) {
    $existing_items = wp_get_nav_menu_items($menu_id);
    if ($existing_items) {
        foreach ($existing_items as $item) {
            wp_delete_post((int) $item->ID, true);
        }
    }

    wp_update_nav_menu_item($menu_id, 0, [
        'menu-item-title' => 'TRANG CHỦ',
        'menu-item-url' => home_url('/'),
        'menu-item-status' => 'publish',
    ]);

    foreach (['gioi-thieu', 'san-pham', 'tai-lieu', 'yeu-cau-bao-gia', 'tin-tuc', 'lien-he'] as $slug) {
        if (empty($page_ids[$slug])) {
            continue;
        }

        wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-object-id' => (int) $page_ids[$slug],
            'menu-item-object' => 'page',
            'menu-item-type' => 'post_type',
            'menu-item-status' => 'publish',
        ]);
    }

    $locations = get_theme_mod('nav_menu_locations', []);
    $locations['primary'] = $menu_id;
    $locations['footer'] = $menu_id;
    set_theme_mod('nav_menu_locations', $locations);
}

flush_rewrite_rules();

echo "Header menu seeded.\n";
