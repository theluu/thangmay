<?php

if (!defined('ABSPATH')) {
    exit;
}

function vitech_clone_setup(): void
{
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('woocommerce');

    register_nav_menus([
        'primary' => __('Menu chính', 'vitech-clone'),
        'footer' => __('Menu chân trang', 'vitech-clone'),
    ]);
}
add_action('after_setup_theme', 'vitech_clone_setup');

function vitech_clone_assets(): void
{
    if (is_admin()) {
        return;
    }
}
add_action('wp_enqueue_scripts', 'vitech_clone_assets');

function vitech_clone_content_types(): void
{
    register_taxonomy('elevator_category', ['elevator'], [
        'labels' => [
            'name' => __('Danh mục thang máy', 'vitech-clone'),
            'singular_name' => __('Danh mục thang máy', 'vitech-clone'),
        ],
        'public' => true,
        'hierarchical' => true,
        'show_in_rest' => true,
        'rewrite' => ['slug' => 'danh-muc-thang-may'],
    ]);

    register_post_type('elevator', [
        'labels' => [
            'name' => __('Thang máy', 'vitech-clone'),
            'singular_name' => __('Thang máy', 'vitech-clone'),
            'add_new_item' => __('Thêm thang máy', 'vitech-clone'),
            'edit_item' => __('Sửa thang máy', 'vitech-clone'),
        ],
        'public' => true,
        'has_archive' => true,
        'menu_icon' => 'dashicons-building',
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'custom-fields'],
        'taxonomies' => ['elevator_category'],
        'rewrite' => ['slug' => 'thang-may'],
    ]);

    register_post_type('vitech_document', [
        'labels' => [
            'name' => __('Tài liệu', 'vitech-clone'),
            'singular_name' => __('Tài liệu', 'vitech-clone'),
            'menu_name' => __('Tài liệu', 'vitech-clone'),
            'add_new' => __('Thêm tài liệu', 'vitech-clone'),
            'add_new_item' => __('Thêm tài liệu mới', 'vitech-clone'),
            'edit_item' => __('Sửa tài liệu', 'vitech-clone'),
            'featured_image' => __('Ảnh bìa tài liệu', 'vitech-clone'),
            'set_featured_image' => __('Chọn ảnh bìa', 'vitech-clone'),
            'remove_featured_image' => __('Bỏ ảnh bìa', 'vitech-clone'),
        ],
        'public' => false,
        'show_ui' => true,
        'menu_icon' => 'dashicons-media-document',
        'menu_position' => 21,
        'supports' => ['title', 'thumbnail'],
    ]);

    register_post_type('vitech_submission', [
        'labels' => [
            'name' => __('Form submissions', 'vitech-clone'),
            'singular_name' => __('Form submission', 'vitech-clone'),
            'menu_name' => __('Form submissions', 'vitech-clone'),
            'edit_item' => __('Xem submission', 'vitech-clone'),
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'capability_type' => 'post',
        'capabilities' => [
            'create_posts' => 'do_not_allow',
        ],
        'map_meta_cap' => true,
        'menu_icon' => 'dashicons-email-alt2',
        'supports' => ['title', 'custom-fields'],
    ]);
}
add_action('init', 'vitech_clone_content_types');

function vitech_clone_option(string $key, string $fallback = ''): string
{
    $value = get_theme_mod($key, $fallback);

    return is_string($value) && $value !== '' ? $value : $fallback;
}

function vitech_clone_price(int $post_id): string
{
    $price = get_post_meta($post_id, '_vitech_price', true);

    return is_string($price) && $price !== '' ? $price : __('Liên hệ', 'vitech-clone');
}

function vitech_clone_disable_public_canonical(): void
{
    remove_action('template_redirect', 'redirect_canonical');
}
add_action('template_redirect', 'vitech_clone_disable_public_canonical', 1);

function vitech_clone_frozen_base(): string
{
    return wp_make_link_relative(get_template_directory_uri()) . '/frozen';
}

// Khớp hệt fz_slug() trong tools/freeze.php.
function vitech_clone_snapshot_slug(string $request_path, bool $is_search): string
{
    if ($is_search) return 'search';
    $slug = trim($request_path, '/');
    if ($slug === '') return 'home';
    $slug = preg_replace('#[^a-z0-9/_-]#i', '-', $slug);
    return str_replace('/', '-', strtolower($slug));
}

function vitech_clone_proxy_public_pages(): void
{
    if (is_admin() || wp_doing_ajax() || wp_is_json_request()) {
        return;
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = '/' . ltrim((string) $path, '/');

    if (
        str_starts_with($path, '/wp-admin') ||
        str_starts_with($path, '/wp-login.php') ||
        str_starts_with($path, '/wp-json') ||
        str_starts_with($path, '/wp-content') ||
        str_starts_with($path, '/wp-includes')
    ) {
        return;
    }

    require get_template_directory() . '/render.php';
    exit;
}
add_action('template_redirect', 'vitech_clone_proxy_public_pages', 2);

function vitech_clone_phone_href(string $phone): string
{
    return 'tel:' . preg_replace('/[^0-9+]/', '', $phone);
}

// ===== Quản trị Tài liệu (CPT vitech_document) =====
function vitech_clone_document_metabox(): void
{
    add_meta_box('vitech_document_file', 'File tài liệu (PDF)', 'vitech_clone_render_document_metabox', 'vitech_document', 'normal', 'high');
}
add_action('add_meta_boxes', 'vitech_clone_document_metabox');

function vitech_clone_render_document_metabox(WP_Post $post): void
{
    wp_nonce_field('vitech_document_save', 'vitech_document_nonce');
    $file = get_post_meta($post->ID, '_vitech_doc_file', true);
    echo '<p><input type="url" class="widefat" id="vitech_doc_file" name="vitech_doc_file" value="' . esc_attr(is_string($file) ? $file : '') . '" placeholder="URL file PDF" /></p>';
    echo '<p><button type="button" class="button" id="vitech_doc_file_upload">Tải lên / chọn file PDF</button> ';
    if (is_string($file) && $file !== '') {
        echo '<a href="' . esc_url($file) . '" target="_blank">Xem file hiện tại</a>';
    }
    echo '</p><p class="description">Bấm nút để tải PDF lên thư viện media hoặc dán URL trực tiếp. Ảnh bìa đặt ở khung "Ảnh bìa tài liệu" bên phải.</p>';
}

function vitech_clone_document_admin_assets(string $hook): void
{
    if (!in_array($hook, ['post.php', 'post-new.php'], true) || get_current_screen()?->post_type !== 'vitech_document') {
        return;
    }

    wp_enqueue_media();
    $script = <<<'JS'
jQuery(function ($) {
    var frame;
    $('#vitech_doc_file_upload').on('click', function (e) {
        e.preventDefault();
        if (frame) { frame.open(); return; }
        frame = wp.media({
            title: 'Chọn hoặc tải lên file PDF',
            button: { text: 'Dùng file này' },
            library: { type: 'application/pdf' },
            multiple: false
        });
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            $('#vitech_doc_file').val(att.url);
        });
        frame.open();
    });
});
JS;
    wp_add_inline_script('jquery', $script);
}
add_action('admin_enqueue_scripts', 'vitech_clone_document_admin_assets');

function vitech_clone_document_save(int $post_id): void
{
    $nonce = isset($_POST['vitech_document_nonce']) ? sanitize_text_field(wp_unslash($_POST['vitech_document_nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'vitech_document_save') || !current_user_can('edit_post', $post_id)) {
        return;
    }

    $file = isset($_POST['vitech_doc_file']) ? esc_url_raw(trim((string) wp_unslash($_POST['vitech_doc_file']))) : '';
    update_post_meta($post_id, '_vitech_doc_file', $file);
}
add_action('save_post_vitech_document', 'vitech_clone_document_save');

function vitech_clone_document_columns(array $columns): array
{
    return [
        'cb' => $columns['cb'] ?? '<input type="checkbox" />',
        'cover' => __('Ảnh bìa', 'vitech-clone'),
        'title' => __('Tên tài liệu', 'vitech-clone'),
        'doc_file' => __('File PDF', 'vitech-clone'),
        'date' => $columns['date'] ?? __('Date'),
    ];
}
add_filter('manage_vitech_document_posts_columns', 'vitech_clone_document_columns');

function vitech_clone_document_column_content(string $column, int $post_id): void
{
    if ($column === 'cover') {
        $thumb = get_the_post_thumbnail_url($post_id, 'thumbnail');
        if (!$thumb) {
            $meta_cover = get_post_meta($post_id, '_vitech_doc_cover', true);
            $thumb = is_string($meta_cover) ? $meta_cover : '';
        }
        echo $thumb ? '<img src="' . esc_url($thumb) . '" style="width:44px;height:60px;object-fit:cover;border-radius:3px;" />' : '—';
    }

    if ($column === 'doc_file') {
        $file = get_post_meta($post_id, '_vitech_doc_file', true);
        echo is_string($file) && $file !== ''
            ? '<a href="' . esc_url($file) . '" target="_blank">' . esc_html(basename(parse_url($file, PHP_URL_PATH) ?: 'file')) . '</a>'
            : '<span style="color:#d63638">Chưa có file</span>';
    }
}
add_action('manage_vitech_document_posts_custom_column', 'vitech_clone_document_column_content', 10, 2);

function vitech_clone_config_fields(): array
{
    return [
        'contact_email' => ['label' => 'Email liên hệ', 'type' => 'email', 'help' => 'Hiển thị ở footer. Bỏ trống sẽ dùng email quản trị.'],
        'phone_primary' => ['label' => 'Hotline', 'type' => 'text', 'help' => 'Số hotline chính, hiển thị ở header, card sản phẩm, footer.'],
        'phone_secondary' => ['label' => 'Điện thoại (phụ)', 'type' => 'text', 'help' => 'Số phụ, hiển thị ở các vị trí liên hệ còn lại trên header và footer.'],
        'company_address' => ['label' => 'Địa chỉ', 'type' => 'text', 'help' => 'Hiển thị ở header và footer.'],
        'company_map_embed_url' => ['label' => 'Google Maps (embed URL)', 'type' => 'url', 'help' => 'URL trong src của iframe Google Maps (Chia sẻ → Nhúng bản đồ), hiển thị ở trang Liên hệ.'],
        'fanpage_url' => ['label' => 'Fanpage Facebook', 'type' => 'url', 'help' => 'Link fanpage cho icon Facebook nổi và footer.'],
        'messenger_username' => ['label' => 'Messenger (username/ID fanpage)', 'type' => 'text', 'help' => 'Username hoặc ID trang Facebook cho nút chat Messenger nổi (m.me/...). Bỏ trống sẽ ẩn nút Messenger.'],
        'zalo_phone' => ['label' => 'Số Zalo', 'type' => 'text', 'help' => 'Dùng cho nút Zalo nổi (zalo.me/...). Bỏ trống sẽ dùng Hotline.'],
        'tiktok_url' => ['label' => 'Kênh TikTok', 'type' => 'url', 'help' => 'Link kênh TikTok cho nút nổi (vd: https://www.tiktok.com/@kenh). Bỏ trống sẽ ẩn nút TikTok.'],
        'banner_image_1' => ['label' => 'Banner slider 1', 'type' => 'image', 'help' => 'Ảnh banner slider trang chủ (tỷ lệ ~2560x1181). Bỏ trống giữ banner hiện tại của template.'],
        'banner_image_2' => ['label' => 'Banner slider 2', 'type' => 'image', 'help' => ''],
        'banner_image_3' => ['label' => 'Banner slider 3', 'type' => 'image', 'help' => ''],
        'recaptcha_site_key' => ['label' => 'reCAPTCHA v3 Site key', 'type' => 'text', 'help' => 'Bỏ trống để tắt reCAPTCHA cho form liên hệ / báo giá / đánh giá.'],
        'recaptcha_secret_key' => ['label' => 'reCAPTCHA v3 Secret key', 'type' => 'text', 'help' => ''],
    ];
}

function vitech_clone_config_admin_assets(string $hook): void
{
    if ($hook !== 'toplevel_page_vitech-config') {
        return;
    }

    wp_enqueue_media();
    $script = <<<'JS'
jQuery(function ($) {
    $('.vitech-config-upload').on('click', function (e) {
        e.preventDefault();
        var target = $(this).data('target');
        var frame = wp.media({
            title: 'Chọn hoặc tải ảnh banner',
            button: { text: 'Dùng ảnh này' },
            library: { type: 'image' },
            multiple: false
        });
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            $('#' + target).val(att.url);
            $('[data-preview="' + target + '"]').html(
                '<img src="' + att.url + '" style="max-width:320px;height:auto;margin-top:8px;border:1px solid #ddd;border-radius:3px;" />'
            );
        });
        frame.open();
    });
});
JS;
    wp_add_inline_script('jquery', $script);
}
add_action('admin_enqueue_scripts', 'vitech_clone_config_admin_assets');

function vitech_clone_config_menu(): void
{
    add_menu_page(
        'Cấu hình website',
        'Cấu hình',
        'manage_options',
        'vitech-config',
        'vitech_clone_render_config_page',
        'dashicons-admin-generic',
        61
    );
}
add_action('admin_menu', 'vitech_clone_config_menu');

function vitech_clone_render_config_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Bạn không có quyền truy cập trang này.');
    }

    $fields = vitech_clone_config_fields();
    $saved = false;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && check_admin_referer('vitech_config_save', 'vitech_config_nonce')) {
        foreach ($fields as $key => $field) {
            $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
            $value = match ($field['type']) {
                'email' => sanitize_email($raw),
                'url', 'image' => esc_url_raw(trim((string) $raw)),
                default => sanitize_text_field($raw),
            };
            set_theme_mod($key, $value);
        }
        $saved = true;
    }

    echo '<div class="wrap"><h1>Cấu hình website</h1>';
    if ($saved) {
        echo '<div class="notice notice-success is-dismissible"><p>Đã lưu cấu hình.</p></div>';
    }
    echo '<form method="post"><table class="form-table" role="presentation">';
    wp_nonce_field('vitech_config_save', 'vitech_config_nonce');

    foreach ($fields as $key => $field) {
        $value = get_theme_mod($key, '');
        $value = is_string($value) ? $value : '';
        echo '<tr><th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($field['label']) . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
        if ($field['type'] === 'image') {
            echo ' <button type="button" class="button vitech-config-upload" data-target="' . esc_attr($key) . '">Chọn / tải ảnh</button>';
            echo '<div class="vitech-config-preview" data-preview="' . esc_attr($key) . '">'
                . ($value !== '' ? '<img src="' . esc_url($value) . '" style="max-width:320px;height:auto;margin-top:8px;border:1px solid #ddd;border-radius:3px;" />' : '')
                . '</div>';
        }
        if ($field['help'] !== '') {
            echo '<p class="description">' . esc_html($field['help']) . '</p>';
        }
        echo '</td></tr>';
    }

    echo '</table>';
    submit_button('Lưu cấu hình');
    echo '</form></div>';
}

// reCAPTCHA v3: đăng ký key loại "reCAPTCHA v3" (thêm cả domain thang-may.ddev.site)
// tại https://www.google.com/recaptcha/admin rồi lưu bằng:
// set_theme_mod('recaptcha_site_key', '...'); set_theme_mod('recaptcha_secret_key', '...');
// Chưa cấu hình key thì form hoạt động bình thường, không chạy captcha.
function vitech_clone_recaptcha_site_key(): string
{
    return vitech_clone_option('recaptcha_site_key', '');
}

function vitech_clone_recaptcha_secret_key(): string
{
    return vitech_clone_option('recaptcha_secret_key', '');
}

function vitech_clone_recaptcha_min_score(): float
{
    $score = (float) vitech_clone_option('recaptcha_min_score', '0.5');

    return $score > 0 && $score <= 1 ? $score : 0.5;
}

function vitech_clone_recaptcha_enabled_for(string $form_type): bool
{
    return in_array($form_type, ['contact', 'quote', 'review'], true)
        && vitech_clone_recaptcha_site_key() !== ''
        && vitech_clone_recaptcha_secret_key() !== '';
}

function vitech_clone_recaptcha_action(string $form_type): string
{
    return 'vitech_' . $form_type;
}

function vitech_clone_verify_recaptcha(string $form_type): string
{
    $token = isset($_POST['vitech_recaptcha_token']) ? trim((string) wp_unslash($_POST['vitech_recaptcha_token'])) : '';
    if ($token === '') {
        return 'Không xác minh được reCAPTCHA. Vui lòng tải lại trang và thử lại.';
    }

    $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
        'timeout' => 10,
        'body' => [
            'secret' => vitech_clone_recaptcha_secret_key(),
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ],
    ]);

    if (is_wp_error($response)) {
        return 'Không xác minh được reCAPTCHA. Vui lòng thử lại.';
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['success'])) {
        return 'Xác minh reCAPTCHA thất bại. Vui lòng tải lại trang và thử lại.';
    }

    if (($body['action'] ?? '') !== vitech_clone_recaptcha_action($form_type)) {
        return 'Xác minh reCAPTCHA không hợp lệ. Vui lòng tải lại trang và thử lại.';
    }

    if ((float) ($body['score'] ?? 0) < vitech_clone_recaptcha_min_score()) {
        return 'Hệ thống nghi ngờ truy cập tự động. Vui lòng thử lại hoặc liên hệ trực tiếp qua hotline.';
    }

    return '';
}

// Chặn spam form đánh giá sản phẩm (comment/review) bằng reCAPTCHA v3.
function vitech_clone_verify_review_recaptcha(array $commentdata): array
{
    if (is_admin() || wp_doing_ajax() || is_user_logged_in()) {
        return $commentdata;
    }

    if (!vitech_clone_recaptcha_enabled_for('review')) {
        return $commentdata;
    }

    $error = vitech_clone_verify_recaptcha('review');
    if ($error !== '') {
        wp_die(
            esc_html($error),
            'Xác minh reCAPTCHA',
            ['response' => 403, 'back_link' => true]
        );
    }

    return $commentdata;
}
add_filter('preprocess_comment', 'vitech_clone_verify_review_recaptcha');

function vitech_clone_handle_form_submission(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }

    $action = isset($_POST['vitech_form_action']) ? sanitize_key(wp_unslash($_POST['vitech_form_action'])) : '';
    if ($action !== 'submit') {
        return;
    }

    $form_type = isset($_POST['vitech_form_type']) ? sanitize_key(wp_unslash($_POST['vitech_form_type'])) : 'contact';
    if (!in_array($form_type, ['contact', 'quote', 'order'], true)) {
        $form_type = 'contact';
    }

    $default_page = match ($form_type) {
        'quote' => '/yeu-cau-bao-gia/',
        'order' => '/gio-hang/',
        default => '/lien-he/',
    };
    $redirect = wp_get_referer() ?: home_url($default_page);
    $redirect = remove_query_arg(['vitech_form_notice'], $redirect);

    $nonce = isset($_POST['vitech_form_nonce']) ? sanitize_text_field(wp_unslash($_POST['vitech_form_nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'vitech_form_submit_' . $form_type)) {
        vitech_clone_redirect_with_form_notice($redirect, 'error', ['Phiên gửi form đã hết hạn. Vui lòng thử lại.']);
    }

    $name = isset($_POST['vitech_name']) ? sanitize_text_field(wp_unslash($_POST['vitech_name'])) : '';
    $email = isset($_POST['vitech_email']) ? sanitize_email(wp_unslash($_POST['vitech_email'])) : '';
    $phone = isset($_POST['vitech_phone']) ? sanitize_text_field(wp_unslash($_POST['vitech_phone'])) : '';
    $message = isset($_POST['vitech_message']) ? sanitize_textarea_field(wp_unslash($_POST['vitech_message'])) : '';
    $product = isset($_POST['vitech_product']) ? sanitize_text_field(wp_unslash($_POST['vitech_product'])) : '';
    $cart_items = $form_type === 'order' && isset($_POST['vitech_cart_items'])
        ? vitech_clone_parse_cart_items(wp_unslash($_POST['vitech_cart_items']))
        : [];
    $source_url = isset($_POST['vitech_source_url']) ? esc_url_raw(wp_unslash($_POST['vitech_source_url'])) : $redirect;
    $honeypot = isset($_POST['vitech_company']) ? trim((string) wp_unslash($_POST['vitech_company'])) : '';

    if ($honeypot !== '') {
        vitech_clone_redirect_with_form_notice($redirect, 'success', ['Cảm ơn anh/chị. Thông tin đã được ghi nhận.']);
    }

    $errors = [];
    if ($name === '') {
        $errors[] = 'Vui lòng nhập họ và tên.';
    }
    if ($email === '' || !is_email($email)) {
        $errors[] = 'Vui lòng nhập email hợp lệ.';
    }
    if ($phone === '' || strlen(preg_replace('/[^0-9]/', '', $phone)) < 8) {
        $errors[] = 'Vui lòng nhập số điện thoại hợp lệ.';
    }
    if ($form_type === 'order') {
        if ($cart_items === []) {
            $errors[] = 'Giỏ hàng đang trống. Vui lòng thêm sản phẩm trước khi đặt hàng.';
        }
    } elseif ($message === '') {
        $errors[] = $form_type === 'quote' ? 'Vui lòng nhập nhu cầu báo giá.' : 'Vui lòng nhập nội dung liên hệ.';
    }
    if (vitech_clone_recaptcha_enabled_for($form_type)) {
        $captcha_error = vitech_clone_verify_recaptcha($form_type);
        if ($captcha_error !== '') {
            $errors[] = $captcha_error;
        }
    }

    if ($errors !== []) {
        vitech_clone_redirect_with_form_notice($redirect, 'error', $errors);
    }

    $type_label = match ($form_type) {
        'quote' => 'Yêu cầu báo giá',
        'order' => 'Đơn đặt hàng',
        default => 'Liên hệ',
    };

    $content = $message;
    if ($form_type === 'order') {
        $summary_lines = [];
        $names = [];
        foreach ($cart_items as $item) {
            $names[] = $item['name'];
            $summary_lines[] = sprintf(
                '- %s x%d%s',
                $item['name'],
                $item['qty'],
                $item['price'] !== '' ? ' (' . $item['price'] . ')' : ''
            );
        }
        $content = "SẢN PHẨM ĐẶT MUA:\n" . implode("\n", $summary_lines);
        if ($message !== '') {
            $content .= "\n\nGhi chú: " . $message;
        }
        if ($product === '') {
            $product = implode(', ', $names);
        }
    }

    $submission_id = wp_insert_post([
        'post_type' => 'vitech_submission',
        'post_status' => 'private',
        'post_title' => sprintf('%s - %s - %s', $type_label, $name, current_time('Y-m-d H:i')),
        'post_content' => $content,
    ], true);

    if (is_wp_error($submission_id)) {
        vitech_clone_redirect_with_form_notice($redirect, 'error', ['Không lưu được dữ liệu. Vui lòng thử lại sau.']);
    }

    update_post_meta($submission_id, '_vitech_form_type', $form_type);
    update_post_meta($submission_id, '_vitech_name', $name);
    update_post_meta($submission_id, '_vitech_email', $email);
    update_post_meta($submission_id, '_vitech_phone', $phone);
    update_post_meta($submission_id, '_vitech_product', $product);
    update_post_meta($submission_id, '_vitech_message', $content);
    update_post_meta($submission_id, '_vitech_source_url', $source_url);
    update_post_meta($submission_id, '_vitech_ip', sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''));
    update_post_meta($submission_id, '_vitech_user_agent', sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($form_type === 'order') {
        update_post_meta($submission_id, '_vitech_cart', wp_json_encode($cart_items, JSON_UNESCAPED_UNICODE));
    }

    $success_message = $form_type === 'order'
        ? 'Đặt hàng thành công! Đơn của anh/chị đã được ghi nhận, đội ngũ sẽ liên hệ xác nhận sớm.'
        : 'Cảm ơn anh/chị. Thông tin đã được lưu, đội ngũ tư vấn sẽ liên hệ sớm.';
    vitech_clone_redirect_with_form_notice($redirect, 'success', [$success_message]);
}

// Parse JSON giỏ hàng gửi từ client thành mảng item đã làm sạch.
function vitech_clone_parse_cart_items(string $raw): array
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = isset($entry['name']) ? sanitize_text_field((string) $entry['name']) : '';
        if ($name === '') {
            continue;
        }
        $qty = isset($entry['qty']) ? (int) $entry['qty'] : 1;
        $qty = max(1, min(999, $qty));
        $items[] = [
            'name' => $name,
            'price' => isset($entry['price']) ? sanitize_text_field((string) $entry['price']) : '',
            'qty' => $qty,
            'url' => isset($entry['url']) ? esc_url_raw((string) $entry['url']) : '',
        ];
        if (count($items) >= 100) {
            break;
        }
    }

    return $items;
}
add_action('template_redirect', 'vitech_clone_handle_form_submission', 0);

function vitech_clone_redirect_with_form_notice(string $redirect, string $status, array $messages): void
{
    $key = wp_generate_uuid4();
    set_transient('vitech_form_notice_' . $key, [
        'status' => $status,
        'messages' => array_values($messages),
    ], 10 * MINUTE_IN_SECONDS);

    wp_safe_redirect(add_query_arg('vitech_form_notice', rawurlencode($key), $redirect));
    exit;
}

function vitech_clone_submission_columns(array $columns): array
{
    return [
        'cb' => $columns['cb'] ?? '<input type="checkbox" />',
        'title' => __('Submission', 'vitech-clone'),
        'form_type' => __('Loại form', 'vitech-clone'),
        'contact' => __('Liên hệ', 'vitech-clone'),
        'date' => $columns['date'] ?? __('Date'),
    ];
}
add_filter('manage_vitech_submission_posts_columns', 'vitech_clone_submission_columns');

function vitech_clone_submission_column_content(string $column, int $post_id): void
{
    if ($column === 'form_type') {
        $type = get_post_meta($post_id, '_vitech_form_type', true);
        echo esc_html(match ($type) {
            'quote' => 'Yêu cầu báo giá',
            'order' => 'Đơn đặt hàng',
            default => 'Liên hệ',
        });
    }

    if ($column === 'contact') {
        $email = get_post_meta($post_id, '_vitech_email', true);
        $phone = get_post_meta($post_id, '_vitech_phone', true);
        echo esc_html(trim($email . ' / ' . $phone, ' /'));
    }
}
add_action('manage_vitech_submission_posts_custom_column', 'vitech_clone_submission_column_content', 10, 2);
