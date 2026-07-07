<?php

if (!defined('ABSPATH')) {
    exit;
}

status_header(200);
nocache_headers();

$source_host = 'https://vitechlift.com';
$local_host = rtrim(home_url('/'), '/');
$request_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$request_query = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY);
$request_path = '/' . ltrim((string) $request_path, '/');

$asset_url = static function (string $url) use ($local_host, $source_host): string {
    if (str_starts_with($url, '//vitechlift.com/')) {
        $url = 'https:' . $url;
    }

    if (str_starts_with($url, '/wp-content/') || str_starts_with($url, '/wp-includes/')) {
        $url = $source_host . $url;
    }

    return $local_host . '/?vitech_asset=' . rawurlencode($url);
};

if (str_starts_with($request_path, '/wp-admin') || str_starts_with($request_path, '/wp-login.php')) {
    wp_safe_redirect(admin_url());
    exit;
}

// Chi tiết bài viết local không tồn tại trên trang nguồn: dùng một trang
// detail mẫu của nguồn làm template rồi bơm dữ liệu bài viết local vào.
$single_post = is_singular('post') ? get_queried_object() : null;
if ($single_post instanceof WP_Post) {
    $request_path = '/may-keo-fjt-new-version/';
    $request_query = null;
}
// Danh mục sản phẩm local dùng base /product-category/... không có trên nguồn:
// dùng một trang danh mục mẫu của nguồn làm template.
$product_term = is_tax('product_cat') ? get_queried_object() : null;
if ($product_term instanceof WP_Term) {
    $request_path = '/may-keo-thang-may/';
    $request_query = null;
}

// Chi tiết sản phẩm local (/product/...) cũng vậy: dùng trang sản phẩm mẫu.
$single_product = is_singular('product') ? get_queried_object() : null;
if ($single_product instanceof WP_Post) {
    $request_path = '/may-keo-fj100a/';
    $request_query = null;
}

$local_page = is_page() ? get_queried_object() : null;
$search_query = isset($_GET['s']) ? trim(sanitize_text_field(wp_unslash($_GET['s']))) : '';

$source_url = $source_host . $request_path;
if ($request_query) {
    $source_url .= '?' . $request_query;
}

$cache_key = 'vitech_clone_' . md5($source_url);
$html = get_transient($cache_key);

if (!is_string($html)) {
    $response = wp_remote_get($source_url, [
        'timeout' => 20,
        'redirection' => 5,
        'user-agent' => 'Mozilla/5.0 (compatible; ThangMayLocalClone/1.0)',
        'headers' => [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ],
    ]);

    if (is_wp_error($response)) {
        status_header(502);
        echo '<!doctype html><meta charset="utf-8"><title>Proxy error</title><p>Không lấy được trang nguồn.</p>';
        exit;
    }

    $content_type = wp_remote_retrieve_header($response, 'content-type');
    $html = wp_remote_retrieve_body($response);

    if (stripos((string) $content_type, 'text/html') === false || $html === '') {
        wp_safe_redirect($source_url);
        exit;
    }

    set_transient($cache_key, $html, 15 * MINUTE_IN_SECONDS);
}

$html = preg_replace_callback(
    '#(?:https?:)?//vitechlift\.com/(wp-content|wp-includes)/[^\s"\'<>),]+#',
    static fn(array $matches): string => $asset_url($matches[0]),
    $html
);

$html = preg_replace_callback(
    '#https?:\\\\/\\\\/vitechlift\.com\\\\/(wp-content|wp-includes)\\\\/[^"\\\\\s<>)]+#',
    static function (array $matches) use ($asset_url): string {
        $url = str_replace('\/', '/', $matches[0]);
        return str_replace('/', '\/', $asset_url($url));
    },
    $html
);

$html = str_replace([
    'https://vitechlift.com',
    'http://vitechlift.com',
    '//vitechlift.com',
    'https:\/\/vitechlift.com',
    'http:\/\/vitechlift.com',
], [
    $local_host,
    $local_host,
    '//' . parse_url($local_host, PHP_URL_HOST),
    str_replace('/', '\/', $local_host),
    str_replace('/', '\/', $local_host),
], $html);

$html = preg_replace_callback(
    '#(["\'])/(wp-content|wp-includes)/([^"\']+)#',
    static fn(array $matches): string => $matches[1] . $asset_url('/' . $matches[2] . '/' . $matches[3]),
    $html
);

$html = preg_replace_callback(
    '#url\((["\']?)/(wp-content|wp-includes)/([^)\'"]+)#',
    static fn(array $matches): string => 'url(' . $matches[1] . $asset_url('/' . $matches[2] . '/' . $matches[3]),
    $html
);

// Bỏ widget AI chat (Botpress) của trang nguồn.
$html = preg_replace('#<script[^>]*src="[^"]*(?:botpress\.cloud|bpcontent\.cloud)[^"]*"[^>]*>\s*</script>\s*#i', '', $html) ?? $html;

$html = vitech_clone_inject_dynamic_data($html);

if ($single_post instanceof WP_Post) {
    $html = vitech_clone_inject_single_post($html, $single_post, $local_host . '/may-keo-fjt-new-version/');
}
if ($product_term instanceof WP_Term) {
    $html = vitech_clone_inject_product_category($html, $product_term);
}
if ($single_product instanceof WP_Post) {
    $html = vitech_clone_inject_single_product($html, $single_product, $local_host . '/may-keo-fj100a/');
}
if ($local_page instanceof WP_Post) {
    $html = vitech_clone_inject_local_page($html, $local_page);
}
if ($search_query !== '') {
    $html = vitech_clone_inject_search_results($html, $search_query);
}

// Form có thể được render nhiều lần trong quá trình thay thế, nên script
// reCAPTCHA v3 chỉ chèn một lần ở đây khi form còn tồn tại trong HTML cuối.
if (str_contains($html, 'name="vitech_recaptcha_token"') && !str_contains($html, 'recaptcha/api.js')) {
    $recaptcha_site_key = vitech_clone_recaptcha_site_key();
    $recaptcha_scripts = '<script src="https://www.google.com/recaptcha/api.js?render=' . rawurlencode($recaptcha_site_key) . '"></script>'
        . '<script>(function(){var siteKey=' . wp_json_encode($recaptcha_site_key) . ';'
        . 'document.addEventListener("submit",function(event){'
        . 'var form=event.target;'
        . 'if(!form.classList||!form.classList.contains("vitech-local-form"))return;'
        . 'var tokenField=form.querySelector(\'input[name="vitech_recaptcha_token"]\');'
        . 'if(!tokenField||tokenField.value!==""||typeof grecaptcha==="undefined")return;'
        . 'event.preventDefault();'
        . 'grecaptcha.ready(function(){'
        . 'grecaptcha.execute(siteKey,{action:tokenField.getAttribute("data-recaptcha-action")||"vitech_contact"})'
        . '.then(function(token){tokenField.value=token;form.submit();})'
        . '.catch(function(){form.submit();});'
        . '});'
        . '},true);'
        . '})();</script>';
    $html = str_replace('</body>', $recaptcha_scripts . '</body>', $html);
}

// Nút "Xem nhanh": JS quick-view của theme nguồn gọi AJAX không tồn tại ở
// local nên click không phản hồi. Bắt click ở capture phase và điều hướng
// thẳng tới trang sản phẩm.
if (str_contains($html, 'class="quick-view"')) {
    $quickview_script = '<script>document.addEventListener("click",function(event){'
        . 'var link=event.target.closest?event.target.closest("a.quick-view"):null;'
        . 'if(!link||!link.getAttribute("href"))return;'
        . 'event.preventDefault();event.stopImmediatePropagation();'
        . 'window.location.href=link.getAttribute("href");'
        . '},true);</script>';
    $html = str_replace('</body>', $quickview_script . '</body>', $html);
}

echo $html;

function vitech_clone_inject_dynamic_data(string $html): string
{
    $products = vitech_clone_dynamic_products();
    $terms = vitech_clone_dynamic_terms();
    $posts = vitech_clone_dynamic_posts();
    $phone = vitech_clone_option('phone_primary', '0901 234 567');
    $phone_href = vitech_clone_proxy_phone_href($phone);

    $html = vitech_clone_replace_document_meta($html);

    $home_sections_replaced = false;
    if ($terms && is_front_page()) {
        $html = vitech_clone_replace_home_product_sections($html, $terms, $phone, $phone_href, $home_sections_replaced);
    }

    if ($products) {
        $product_index = 0;
        $html = preg_replace_callback(
            '#<div class="product-small col has-hover product\b.*?</div><!-- col -->#s',
            static function (array $matches) use ($products, &$product_index, $phone, $phone_href): string {
                $product = $products[$product_index % count($products)];
                ++$product_index;

                return vitech_clone_render_proxy_product_card($product, $product_index, $phone, $phone_href);
            },
            $html
        );
    }

    if ($posts) {
        $html = vitech_clone_replace_featured_news_menu($html, $posts);
        $html = vitech_clone_replace_news_titles($html, $posts);
    }

    if ($terms) {
        $html = vitech_clone_replace_header_menu($html, $terms);
        $html = vitech_clone_replace_product_menu($html, $terms);
        $html = vitech_clone_replace_sidebar_product_categories($html, $terms);
        if (!$home_sections_replaced) {
            $html = vitech_clone_replace_product_section_headings($html, $terms);
        }
        $html = vitech_clone_replace_mobile_product_menu($html, $terms);
    }

    // Trả lại marker chống ghi đè của các card đã render theo danh mục.
    $html = str_replace('<!-- vitech-col -->', '<!-- col -->', $html);

    $html = vitech_clone_replace_public_forms($html);
    $html = vitech_clone_replace_footer_contact($html, $phone);
    $html = preg_replace('/0973\s?294\s?588|0973294588|091\s?879\s?4898|0918794898|0867\s?192\s?588|0867192588/u', esc_html($phone), $html);
    $html = preg_replace('/tel:\+?84?0?973294588|tel:0973294588|tel:0918794898|tel:0867192588|tel:0867\s?192\s?588/u', esc_url($phone_href), $html);
    $html = preg_replace('/https:\/\/zalo\.me\/0867192588/u', 'https://zalo.me/' . preg_replace('/[^0-9]/', '', $phone), $html);
    $html = preg_replace('/tel:' . preg_quote($phone, '/') . '/u', esc_url($phone_href), $html);
    $html = preg_replace('/https:\/\/zalo\.me\/' . preg_quote($phone, '/') . '/u', 'https://zalo.me/' . preg_replace('/[^0-9]/', '', $phone), $html);
    $html = preg_replace('/\'' . preg_quote($phone, '/') . '\':/u', '\'' . preg_replace('/[^0-9]/', '', $phone) . '\':', $html);
    $html = vitech_clone_apply_site_config($html, $phone, $phone_href);
    $html = vitech_clone_inject_admin_toolbar($html);

    return $html;
}

function vitech_clone_replace_public_forms(string $html): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = '/' . trim((string) $path, '/') . '/';

    if ($path === '/lien-he/') {
        return vitech_clone_replace_first_cf7_form($html, vitech_clone_render_public_form('contact'));
    }

    if ($path === '/yeu-cau-bao-gia/') {
        return vitech_clone_replace_first_cf7_form($html, vitech_clone_render_public_form('quote'));
    }

    return $html;
}

function vitech_clone_replace_first_cf7_form(string $html, string $form): string
{
    return preg_replace(
        '#<div class="wpcf7\b.*?</form>\s*</div>#su',
        $form,
        $html,
        1
    ) ?? $html;
}

function vitech_clone_render_public_form(string $type): string
{
    $is_quote = $type === 'quote';
    $notice = vitech_clone_form_notice();
    $action = esc_url(home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? ($is_quote ? '/yeu-cau-bao-gia/' : '/lien-he/'))));
    $nonce = wp_create_nonce('vitech_form_submit_' . $type);
    $title = $is_quote ? 'Yêu cầu báo giá' : 'Liên hệ tư vấn';
    $message_placeholder = $is_quote ? 'Nhu cầu báo giá: loại thang, tải trọng, số tầng, địa điểm...' : 'Nội dung cần tư vấn';
    $button = $is_quote ? 'Gửi yêu cầu báo giá' : 'Gửi liên hệ';

    $product_options = '';
    if ($is_quote && function_exists('wc_get_products')) {
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => 20,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        foreach ($products as $product) {
            if ($product instanceof WC_Product) {
                $product_options .= '<option value="' . esc_attr($product->get_name()) . '">' . esc_html($product->get_name()) . '</option>';
            }
        }
    }

    $product_field = $is_quote
        ? '<span class="wpcf7-form-control-wrap" data-name="vitech_product"><select class="wpcf7-form-control wpcf7-select" name="vitech_product"><option value="">Chọn sản phẩm quan tâm</option>' . $product_options . '</select></span><br />'
        : '';

    $recaptcha_field = vitech_clone_recaptcha_enabled_for($type)
        ? '<input type="hidden" name="vitech_recaptcha_token" value="" data-recaptcha-action="' . esc_attr(vitech_clone_recaptcha_action($type)) . '" />'
        : '';

    return <<<HTML
<div class="wpcf7 vitech-local-form-wrap">
{$notice}
<form action="{$action}" method="post" class="wpcf7-form vitech-local-form" novalidate>
<input type="hidden" name="vitech_form_action" value="submit" />
<input type="hidden" name="vitech_form_type" value="{$type}" />
<input type="hidden" name="vitech_form_nonce" value="{$nonce}" />
<input type="hidden" name="vitech_source_url" value="{$action}" />
<input type="text" name="vitech_company" value="" tabindex="-1" autocomplete="off" class="vitech-hp" aria-hidden="true" />
<div class="vitech-local-form__fields">
<span class="wpcf7-form-control-wrap" data-name="vitech_name"><input size="40" class="wpcf7-form-control wpcf7-text wpcf7-validates-as-required" required aria-required="true" placeholder="Họ và tên *" value="" type="text" name="vitech_name" /></span><br />
<span class="wpcf7-form-control-wrap" data-name="vitech_email"><input size="40" class="wpcf7-form-control wpcf7-email wpcf7-validates-as-required wpcf7-text wpcf7-validates-as-email" required aria-required="true" placeholder="Email *" value="" type="email" name="vitech_email" /></span><br />
<span class="wpcf7-form-control-wrap" data-name="vitech_phone"><input size="40" class="wpcf7-form-control wpcf7-tel wpcf7-validates-as-required wpcf7-text wpcf7-validates-as-tel" required aria-required="true" placeholder="Số điện thoại *" value="" type="tel" name="vitech_phone" /></span><br />
{$product_field}<span class="wpcf7-form-control-wrap" data-name="vitech_message"><textarea cols="40" rows="10" class="wpcf7-form-control wpcf7-textarea wpcf7-validates-as-required" required aria-required="true" placeholder="{$message_placeholder} *" name="vitech_message"></textarea></span><br />
{$recaptcha_field}<input class="wpcf7-form-control wpcf7-submit has-spinner" type="submit" value="{$button}" />
</div>
</form>
</div>
<style>
.vitech-local-form-wrap{margin-top:20px;margin-bottom:0;}
.vitech-local-form .vitech-hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0;}
.vitech-local-form__fields{margin:0;}
.vitech-form-notice{border-radius:4px;margin:0 0 16px;padding:12px 14px;font-size:15px;line-height:1.5;}
.vitech-form-notice--success{background:#ecfdf3;border:1px solid #159158;color:#126c43;}
.vitech-form-notice--error{background:#fff1f0;border:1px solid #d93025;color:#a4261d;}
.vitech-form-notice ul{margin:0;padding-left:18px;}
.vitech-local-form select{width:100%;height:44px;margin-bottom:10px;border:1px solid #ddd;border-radius:3px;padding:0 10px;background:#fff;}
.vitech-local-form input[type="submit"]{margin-bottom:0;}
</style>
HTML;
}

function vitech_clone_form_notice(): string
{
    static $rendered_notice = null;

    if ($rendered_notice !== null) {
        return $rendered_notice;
    }

    $key = isset($_GET['vitech_form_notice']) ? sanitize_text_field(wp_unslash($_GET['vitech_form_notice'])) : '';
    if ($key === '') {
        $rendered_notice = '';
        return '';
    }

    $notice = get_transient('vitech_form_notice_' . $key);
    delete_transient('vitech_form_notice_' . $key);
    if (!is_array($notice) || empty($notice['messages'])) {
        $rendered_notice = '';
        return '';
    }

    $status = ($notice['status'] ?? '') === 'success' ? 'success' : 'error';
    $items = '';
    foreach ((array) $notice['messages'] as $message) {
        $items .= '<li>' . esc_html((string) $message) . '</li>';
    }

    $rendered_notice = '<div class="vitech-form-notice vitech-form-notice--' . esc_attr($status) . '"><ul>' . $items . '</ul></div>';

    return $rendered_notice;
}

function vitech_clone_inject_local_page(string $html, WP_Post $page): string
{
    // Trang Tài liệu: danh sách render từ CPT vitech_document (quản trị trong admin).
    if ($page->post_name === 'tai-lieu') {
        return vitech_clone_inject_documents($html);
    }

    $allowed_pages = ['tin-tuc', 'lien-he', 'yeu-cau-bao-gia'];
    // Trang Giới thiệu: mặc định hiển thị nguyên bản trang nguồn; khi admin
    // điền nội dung vào page local thì nội dung đó thay thế.
    if ($page->post_name === 'gioi-thieu' && trim($page->post_content) !== '') {
        $allowed_pages[] = 'gioi-thieu';
    }
    if (!in_array($page->post_name, $allowed_pages, true)) {
        return $html;
    }

    $html = vitech_clone_replace_page_meta($html, $page);
    $main = vitech_clone_render_local_page_main($page);

    return preg_replace(
        '#<p class="container rt-breadcrumbs">.*?(<footer id="footer")#su',
        $main . '$1',
        $html,
        1
    ) ?? $html;
}

// Trang Tài liệu: thay các item catalog của nguồn bằng tài liệu quản trị
// trong admin (CPT vitech_document): ảnh bìa + link PDF + tiêu đề.
function vitech_clone_inject_documents(string $html): string
{
    $docs = get_posts([
        'post_type' => 'vitech_document',
        'post_status' => 'publish',
        'numberposts' => 48,
        'orderby' => 'menu_order date',
        'order' => 'ASC',
    ]);

    if ($docs === []) {
        return $html;
    }

    $items = '';
    foreach ($docs as $doc) {
        $title = get_the_title($doc);
        $file = get_post_meta($doc->ID, '_vitech_doc_file', true);
        $file = is_string($file) && $file !== '' ? $file : '#';
        $cover = get_the_post_thumbnail_url($doc->ID, 'full');
        if (!$cover) {
            $meta_cover = get_post_meta($doc->ID, '_vitech_doc_cover', true);
            $cover = is_string($meta_cover) && $meta_cover !== ''
                ? $meta_cover
                : get_template_directory_uri() . '/assets/catalog-cover.svg';
        }

        $items .= '<div class="news-post-news"><div class="box__news__inner">'
            . '<div class="box__thumb__img"><a href="' . esc_url($file) . '" target="_blank" title="' . esc_attr($title) . '">'
            . '<img width="1810" height="2560" src="' . esc_url($cover) . '" class="attachment-full size-full wp-post-image" alt="' . esc_attr($title) . '" decoding="async" loading="lazy" />'
            . '</a></div>'
            . '<div class="box__content"><h3><a href="' . esc_url($file) . '" target="_blank" title="' . esc_attr($title) . '">' . esc_html($title) . '</a></h3>'
            . '<a class="more" href="' . esc_url($file) . '" target="_blank" >Tải file <i class="fa-solid fa-arrow-right-long"></i></a>'
            . '</div></div></div>';
    }

    $first = true;
    return preg_replace_callback(
        '#<div class="news-post-news">.*?</div>\s*</div>\s*</div>#s',
        static function () use (&$first, $items): string {
            if (!$first) {
                return '';
            }
            $first = false;

            return $items;
        },
        $html
    ) ?? $html;
}

function vitech_clone_replace_page_meta(string $html, WP_Post $page): string
{
    $title = get_the_title($page);
    $site_name = get_bloginfo('name') ?: 'Thang Máy';
    $description = wp_trim_words(wp_strip_all_tags($page->post_content), 28, '');

    $html = preg_replace('#<title>.*?</title>#su', '<title>' . esc_html($title . ' - ' . $site_name) . '</title>', $html, 1) ?? $html;
    $html = preg_replace('#<meta property="og:title" content="[^"]*"\s*/?>#su', '<meta property="og:title" content="' . esc_attr($title) . '" />', $html, 1) ?? $html;

    if ($description !== '') {
        $html = preg_replace('#<meta name="description" content="[^"]*"\s*/?>#su', '<meta name="description" content="' . esc_attr($description) . '" />', $html, 1) ?? $html;
        $html = preg_replace('#<meta property="og:description" content="[^"]*"\s*/?>#su', '<meta property="og:description" content="' . esc_attr($description) . '" />', $html, 1) ?? $html;
    }

    return $html;
}

function vitech_clone_render_local_page_main(WP_Post $page): string
{
    $title = get_the_title($page);
    $content = apply_filters('the_content', $page->post_content);
    $extra = '';

    if ($page->post_name === 'tin-tuc') {
        $extra = vitech_clone_render_news_archive();
    } elseif ($page->post_name === 'lien-he') {
        $extra = vitech_clone_render_public_form('contact') . vitech_clone_render_contact_map($content);
    } elseif ($page->post_name === 'yeu-cau-bao-gia') {
        $extra = vitech_clone_render_public_form('quote');
    }

    $edit_link = '';
    if (current_user_can('edit_post', $page->ID)) {
        $edit_link = '<a class="vitech-page-edit" href="' . esc_url(get_edit_post_link($page->ID, 'raw')) . '">Sửa trang này</a>';
    }

    return '<p class="container rt-breadcrumbs"><span><span><a href="' . esc_url(home_url('/')) . '">Trang chủ</a></span> &gt; <span class="breadcrumb_last" aria-current="page">' . esc_html($title) . '</span></span></p>'
        . '<main id="main" class="vitech-local-main"><div id="content" role="main" class="content-area">'
        . '<section class="section vitech-local-page-section"><div class="section-content relative"><div class="row"><div class="col small-12 large-12"><div class="col-inner">'
        . '<article class="vitech-local-page"><header class="vitech-local-page__header"><h1>' . esc_html($title) . '</h1>' . $edit_link . '</header>'
        . '<div class="vitech-local-page__content">' . $content . '</div>' . $extra . '</article>'
        . '</div></div></div></div></section></div></main>'
        . vitech_clone_local_page_styles();
}

function vitech_clone_render_contact_map(string $content): string
{
    if (stripos($content, '<iframe') !== false && stripos($content, 'google.com/maps') !== false) {
        return '';
    }

    $map_src = get_theme_mod('company_map_embed_url', '');
    if ($map_src === '') {
        $map_src = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3725.4221693584122!2d105.74721627432105!3d20.975707789600552!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x313453b756c93023%3A0x5b0d93fac58c87f7!2zQ8O0bmcgdHkgVE5ISCBYdeG6pXQgbmjhuq1wIGto4bqpdSBDw7RuZyBuZ2jhu4cgVklURUNI!5e0!3m2!1svi!2s!4v1694485208628!5m2!1svi!2s';
    }

    return '<div class="vitech-contact-map"><iframe src="' . esc_url($map_src) . '" width="100%" height="360" style="border:0;" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>';
}

function vitech_clone_render_news_archive(): string
{
    $query = new WP_Query([
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 12,
        'ignore_sticky_posts' => true,
    ]);

    if (!$query->have_posts()) {
        return '<div class="vitech-news-grid"><p>Chưa có bài viết.</p></div>';
    }

    $html = '<div class="vitech-news-grid">';
    while ($query->have_posts()) {
        $query->the_post();
        $image = get_the_post_thumbnail_url(get_the_ID(), 'large');
        if (!$image) {
            $image = vitech_clone_proxy_placeholder_image();
        }

        $html .= '<article class="vitech-news-card">'
            . '<a class="vitech-news-card__image" href="' . esc_url(get_permalink()) . '"><img src="' . esc_url($image) . '" alt="' . esc_attr(get_the_title()) . '" loading="lazy" /></a>'
            . '<div class="vitech-news-card__body"><time>' . esc_html(get_the_date('d/m/Y')) . '</time>'
            . '<h2><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h2>'
            . '<p>' . esc_html(wp_trim_words(get_the_excerpt(), 24)) . '</p>'
            . '<a class="vitech-news-card__more" href="' . esc_url(get_permalink()) . '">Xem chi tiết</a></div>'
            . '</article>';
    }
    wp_reset_postdata();

    return $html . '</div>';
}

function vitech_clone_inject_search_results(string $html, string $search_query): string
{
    $html = vitech_clone_replace_search_meta($html, $search_query);
    $main = vitech_clone_render_search_main($search_query);

    return preg_replace(
        '~<main id="main"[^>]*>.*?</main><!--\s*#main\s*-->~su',
        $main,
        $html,
        1
    ) ?? $html;
}

function vitech_clone_replace_search_meta(string $html, string $search_query): string
{
    $site_name = get_bloginfo('name') ?: 'Thang Máy';
    $title = 'Tìm kiếm: ' . $search_query;
    $description = 'Kết quả tìm kiếm cho "' . $search_query . '" trên ' . $site_name . '.';

    $html = preg_replace('#<title>.*?</title>#su', '<title>' . esc_html($title . ' - ' . $site_name) . '</title>', $html, 1) ?? $html;
    $html = preg_replace('#<meta name="description" content="[^"]*"\s*/?>#su', '<meta name="description" content="' . esc_attr($description) . '" />', $html, 1) ?? $html;
    $html = preg_replace('#<meta property="og:title" content="[^"]*"\s*/?>#su', '<meta property="og:title" content="' . esc_attr($title) . '" />', $html, 1) ?? $html;
    $html = preg_replace('#<meta property="og:description" content="[^"]*"\s*/?>#su', '<meta property="og:description" content="' . esc_attr($description) . '" />', $html, 1) ?? $html;

    return $html;
}

function vitech_clone_render_search_main(string $search_query): string
{
    $query = new WP_Query([
        's' => $search_query,
        'post_type' => ['product', 'post', 'page'],
        'post_status' => 'publish',
        'posts_per_page' => 24,
        'ignore_sticky_posts' => true,
    ]);

    $results = '';
    if ($query->have_posts()) {
        $results .= '<div class="vitech-search-grid">';
        while ($query->have_posts()) {
            $query->the_post();
            $results .= vitech_clone_render_search_card(get_post());
        }
        $results .= '</div>';
        wp_reset_postdata();
    } else {
        $results = '<div class="vitech-search-empty">Không tìm thấy kết quả phù hợp. Anh/chị thử từ khóa khác hoặc liên hệ để được tư vấn.</div>';
    }

    return '<main id="main" class="vitech-search-main"><div id="content" role="main" class="content-area">'
        . '<section class="section vitech-local-page-section"><div class="section-content relative"><div class="row"><div class="col small-12 large-12"><div class="col-inner">'
        . '<article class="vitech-local-page vitech-search-page"><header class="vitech-local-page__header"><h1>Kết quả tìm kiếm</h1></header>'
        . '<form role="search" method="get" class="vitech-search-form" action="' . esc_url(home_url('/')) . '">'
        . '<input type="search" name="s" value="' . esc_attr($search_query) . '" placeholder="Nhập từ khóa tìm kiếm" />'
        . '<button type="submit">Tìm kiếm</button>'
        . '</form>'
        . '<p class="vitech-search-summary">Từ khóa: <strong>' . esc_html($search_query) . '</strong></p>'
        . $results
        . '</article></div></div></div></div></section></div></main><!-- #main -->'
        . vitech_clone_local_page_styles();
}

function vitech_clone_render_search_card(WP_Post $post): string
{
    $post_type = get_post_type($post);
    $label = match ($post_type) {
        'product' => 'Sản phẩm',
        'page' => 'Trang',
        default => 'Tin tức',
    };

    $image = get_the_post_thumbnail_url($post, 'large');
    if (!$image && $post_type === 'product' && function_exists('wc_get_product')) {
        $product = wc_get_product($post->ID);
        if ($product instanceof WC_Product) {
            $image_id = $product->get_image_id();
            $image = $image_id ? wp_get_attachment_image_url($image_id, 'large') : '';
        }
    }
    if (!$image) {
        $image = vitech_clone_proxy_placeholder_image();
    }

    $excerpt = has_excerpt($post)
        ? get_the_excerpt($post)
        : wp_trim_words(wp_strip_all_tags((string) $post->post_content), 26);

    return '<article class="vitech-search-card">'
        . '<a class="vitech-search-card__image" href="' . esc_url(get_permalink($post)) . '"><img src="' . esc_url($image) . '" alt="' . esc_attr(get_the_title($post)) . '" loading="lazy" /></a>'
        . '<div class="vitech-search-card__body"><span>' . esc_html($label) . '</span>'
        . '<h2><a href="' . esc_url(get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a></h2>'
        . '<p>' . esc_html(wp_trim_words($excerpt, 24)) . '</p>'
        . '<a class="vitech-search-card__more" href="' . esc_url(get_permalink($post)) . '">Xem chi tiết</a></div>'
        . '</article>';
}

function vitech_clone_local_page_styles(): string
{
    return '<style id="vitech-local-page-style">'
        . '.vitech-local-page-section{padding:42px 0 58px;background:#fff;}'
        . '.vitech-local-page{max-width:1180px;margin:0 auto;color:#333;}'
        . '.vitech-local-page__header{display:flex;align-items:center;gap:16px;justify-content:space-between;border-bottom:2px solid #159158;margin-bottom:24px;padding-bottom:14px;}'
        . '.vitech-local-page__header h1{font-size:34px;line-height:1.25;margin:0;color:#159158;text-transform:uppercase;}'
        . '.vitech-page-edit{display:inline-block;background:#2271b1;color:#fff!important;border-radius:4px;padding:8px 12px;text-decoration:none;}'
        . '.vitech-local-page__content{font-size:17px;line-height:1.75;}'
        . '.vitech-local-page__content p{margin:0 0 18px;}'
        . '.vitech-news-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px;margin-top:28px;}'
        . '.vitech-news-card{border:1px solid #e5e5e5;border-radius:6px;overflow:hidden;background:#fff;}'
        . '.vitech-news-card__image{display:block;aspect-ratio:16/10;overflow:hidden;background:#f4f4f4;}'
        . '.vitech-news-card__image img{width:100%;height:100%;object-fit:cover;display:block;}'
        . '.vitech-news-card__body{padding:16px;}'
        . '.vitech-news-card time{font-size:13px;color:#777;}'
        . '.vitech-news-card h2{font-size:18px;line-height:1.35;margin:8px 0;}'
        . '.vitech-news-card h2 a{color:#222;text-decoration:none;}'
        . '.vitech-news-card p{font-size:14px;color:#555;margin:0 0 12px;}'
        . '.vitech-news-card__more{color:#159158;font-weight:600;text-decoration:none;}'
        . '.vitech-contact-map{margin-top:28px;border:1px solid #e5e5e5;border-radius:6px;overflow:hidden;background:#f5f5f5;}'
        . '.vitech-contact-map iframe{display:block;width:100%;height:360px;}'
        . '.vitech-search-form{display:flex;gap:10px;margin:0 0 18px;}'
        . '.vitech-search-form input{flex:1;min-width:0;height:44px;border:1px solid #ddd;border-radius:4px;padding:0 12px;}'
        . '.vitech-search-form button{height:44px;border:0;border-radius:4px;background:#159158;color:#fff;font-weight:600;padding:0 18px;}'
        . '.vitech-search-summary{margin:0 0 20px;color:#555;}'
        . '.vitech-search-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px;}'
        . '.vitech-search-card{border:1px solid #e5e5e5;border-radius:6px;overflow:hidden;background:#fff;}'
        . '.vitech-search-card__image{display:block;aspect-ratio:16/10;background:#f4f4f4;overflow:hidden;}'
        . '.vitech-search-card__image img{display:block;width:100%;height:100%;object-fit:cover;}'
        . '.vitech-search-card__body{padding:16px;}'
        . '.vitech-search-card__body span{display:inline-block;color:#159158;font-size:13px;font-weight:700;margin-bottom:6px;text-transform:uppercase;}'
        . '.vitech-search-card h2{font-size:18px;line-height:1.35;margin:0 0 8px;}'
        . '.vitech-search-card h2 a{color:#222;text-decoration:none;}'
        . '.vitech-search-card p{font-size:14px;color:#555;margin:0 0 12px;}'
        . '.vitech-search-card__more{color:#159158;font-weight:600;text-decoration:none;}'
        . '.vitech-search-empty{border:1px solid #e5e5e5;border-radius:6px;padding:18px;background:#fafafa;color:#555;}'
        . '@media(max-width:900px){.vitech-news-grid,.vitech-search-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.vitech-local-page__header{display:block;}.vitech-page-edit{margin-top:10px;}}'
        . '@media(max-width:600px){.vitech-news-grid,.vitech-search-grid{grid-template-columns:1fr;}.vitech-local-page__header h1{font-size:26px;}.vitech-contact-map iframe{height:280px;}.vitech-search-form{display:block;}.vitech-search-form button{width:100%;margin-top:8px;}}'
        . '</style>';
}

// Logo của trang nguồn là thương hiệu bên khác: thay bằng logo trung tính của theme.
function vitech_clone_replace_logo(string $html): string
{
    $site_name = get_bloginfo('name') ?: 'Thang Máy';
    $logo_url = get_template_directory_uri() . '/assets/logo-noname.svg';
    $inner = '<a href="' . esc_url(home_url('/')) . '" title="' . esc_attr($site_name) . '" rel="home">'
        . '<img width="264" height="66" src="' . esc_url($logo_url) . '" class="header_logo header-logo" alt="' . esc_attr($site_name) . '"/>'
        . '<img width="264" height="66" src="' . esc_url($logo_url) . '" class="header-logo-dark" alt="' . esc_attr($site_name) . '"/>'
        . '</a>';

    $html = preg_replace(
        '#(<div id="logo"[^>]*>).*?(</div>)#s',
        '$1' . vitech_clone_preg_replacement($inner) . '$2',
        $html
    ) ?? $html;

    // Favicon và tile icon của trang nguồn cũng là logo thương hiệu cũ.
    $favicon = esc_url(get_template_directory_uri() . '/assets/favicon.svg');
    $html = preg_replace('#(<link rel="icon" href=")[^"]*#', '$1' . $favicon, $html) ?? $html;
    $html = preg_replace('#(<link rel="apple-touch-icon" href=")[^"]*#', '$1' . $favicon, $html) ?? $html;
    $html = preg_replace('#(<meta name="msapplication-TileImage" content=")[^"]*#', '$1' . $favicon, $html) ?? $html;

    // CSS nền header còn trỏ tới ảnh logo cũ trên domain ngoài.
    $html = preg_replace("#background-image: url\\('[^']*Group-630490[^']*'\\);#", 'background-image: none;', $html) ?? $html;

    return $html;
}

// Các banner marketing của trang nguồn (slider, strip, banner phụ) mang thương
// hiệu bên khác: thay bằng banner trung tính của theme, slider có thể đổi ảnh
// qua trang Cấu hình (banner_image_1..3).
function vitech_clone_replace_source_banners(string $html): string
{
    $assets = get_template_directory_uri() . '/assets';
    $map = [
        'cover1' => vitech_clone_option('banner_image_1', '') ?: $assets . '/banner-hero-1.svg',
        'cover2' => vitech_clone_option('banner_image_2', '') ?: $assets . '/banner-hero-2.svg',
        'cover-hau-mai' => vitech_clone_option('banner_image_3', '') ?: $assets . '/banner-hero-3.svg',
        'banner2' => $assets . '/banner-strip.svg',
        'banner3-1' => $assets . '/banner-strip.svg',
        'banner\.jpg' => $assets . '/banner-strip.svg',
        'banner-\d+x\d+\.jpg' => $assets . '/banner-strip.svg',
        'giao-hang-mien-phi' => $assets . '/banner-side.svg',
    ];

    foreach ($map as $fragment => $url) {
        $html = preg_replace(
            '#[^"\'\s>(),]*' . $fragment . '[^"\'\s>(),]*#',
            vitech_clone_preg_replacement(esc_url($url)),
            $html
        ) ?? $html;
    }

    return $html;
}

// Trang chủ: mỗi section sản phẩm gắn với một danh mục local, hiển thị đúng
// sản phẩm thuộc danh mục đó. Section thừa (không còn danh mục) bị gỡ bỏ.
function vitech_clone_replace_home_product_sections(string $html, array $terms, string $phone, string $phone_href, bool &$replaced): string
{
    $index = 0;
    $card_pattern = '#<div class="product-small col has-hover product\b.*?</div><!-- col -->#s';

    $result = preg_replace_callback(
        '#<section class="section section_product".*?</section>#s',
        static function (array $matches) use ($terms, &$index, $card_pattern, $phone, $phone_href): string {
            if ($index >= count($terms)) {
                return '';
            }

            $term = $terms[$index++];
            $products = vitech_clone_term_products($term);
            if ($products === []) {
                return '';
            }

            $section = preg_replace(
                '#(<h2 class="heading clear">\s*<span>\s*).*?(\s*</span>\s*<a href=")[^"]*("[^>]*>\s*Xem tất cả(?:&gt;|>){2}\s*</a>)#su',
                '$1' . vitech_clone_preg_replacement(esc_html(vitech_clone_term_upper($term->name)))
                    . '$2' . vitech_clone_preg_replacement(esc_url(get_term_link($term))) . '$3',
                $matches[0],
                1
            ) ?? $matches[0];

            $cards = '';
            foreach ($products as $i => $product) {
                $cards .= vitech_clone_render_proxy_product_card($product, $i + 1, $phone, $phone_href);
            }

            $first = true;
            $section = preg_replace_callback(
                $card_pattern,
                static function () use (&$first, $cards): string {
                    if (!$first) {
                        return '';
                    }
                    $first = false;

                    return $cards;
                },
                $section
            ) ?? $section;

            // Đánh dấu để vòng thay card chung (xoay vòng mọi sản phẩm) bỏ qua
            // các card đã render đúng theo danh mục; marker được trả lại sau.
            return str_replace('</div><!-- col -->', '</div><!-- vitech-col -->', $section);
        },
        $html
    );

    if (is_string($result)) {
        $replaced = true;

        return $result;
    }

    return $html;
}

// Áp các giá trị từ trang "Cấu hình website" (wp-admin) lên phần HTML proxy
// còn dính thông tin liên hệ của trang nguồn: 3 số hotline header, địa chỉ,
// link fanpage và nút Zalo nổi.
function vitech_clone_apply_site_config(string $html, string $phone, string $phone_href): string
{
    $secondary = vitech_clone_option('phone_secondary', '');
    $secondary = $secondary !== '' ? $secondary : $phone;
    $secondary_href = vitech_clone_proxy_phone_href($secondary);

    $address = vitech_clone_option('company_address', '');
    if ($address !== '') {
        $html = str_replace(
            'A54 Khu tái định cư LK19A, LK19B, X7, Phường Dương Nội, Quận Hà Đông, TP Hà Nội',
            esc_html($address),
            $html
        );
    }

    $fanpage = vitech_clone_option('fanpage_url', '');
    if ($fanpage !== '') {
        $html = str_replace(
            ['https://www.facebook.com/vitechlift', 'https://facebook.com/vitechlift'],
            esc_url($fanpage),
            $html
        );
    }

    $zalo = preg_replace('/[^0-9]/', '', vitech_clone_option('zalo_phone', $phone));
    if ($zalo !== '') {
        $html = preg_replace('#https://zalo\.me/[0-9]+#', 'https://zalo.me/' . $zalo, $html) ?? $html;
    }

    $map = vitech_clone_option('company_map_embed_url', '');
    if ($map !== '') {
        $html = preg_replace(
            '#src="https://www\.google\.com/maps/embed\?pb=[^"]*"#',
            'src="' . vitech_clone_preg_replacement(esc_url($map)) . '"',
            $html
        ) ?? $html;
    }

    return $html;
}

function vitech_clone_inject_admin_toolbar(string $html): string
{
    if (!is_user_logged_in() || !current_user_can('edit_posts')) {
        return $html;
    }

    $edit_url = vitech_clone_current_edit_url();
    $items = [
        ['label' => 'Dashboard', 'url' => admin_url()],
        ['label' => 'Cấu hình', 'url' => admin_url('admin.php?page=vitech-config')],
        ['label' => 'Menu', 'url' => admin_url('nav-menus.php')],
        ['label' => 'Pages', 'url' => admin_url('edit.php?post_type=page')],
        ['label' => 'Products', 'url' => admin_url('edit.php?post_type=product')],
        ['label' => 'Categories', 'url' => admin_url('edit-tags.php?taxonomy=product_cat&post_type=product')],
    ];

    if ($edit_url !== '') {
        array_unshift($items, ['label' => 'Sửa trang hiện tại', 'url' => $edit_url, 'primary' => true]);
    }

    $links = '';
    foreach ($items as $item) {
        $class = !empty($item['primary']) ? ' class="vitech-adminbar-primary"' : '';
        $links .= '<a' . $class . ' href="' . esc_url($item['url']) . '">' . esc_html($item['label']) . '</a>';
    }

    $toolbar = '<div id="vitech-adminbar"><div class="vitech-adminbar-inner">'
        . '<strong>' . esc_html(get_bloginfo('name') ?: 'WordPress') . '</strong>'
        . $links
        . '<span>Đang xem frontend</span>'
        . '</div></div>';

    $styles = '<style id="vitech-adminbar-style">'
        . 'body{padding-top:40px!important;}'
        . '#vitech-adminbar{position:fixed;top:0;left:0;right:0;z-index:999999;background:#1d2327;color:#f0f0f1;font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}'
        . '#vitech-adminbar .vitech-adminbar-inner{height:40px;display:flex;align-items:center;gap:14px;padding:0 16px;}'
        . '#vitech-adminbar strong{font-weight:600;margin-right:8px;}'
        . '#vitech-adminbar a{color:#f0f0f1;text-decoration:none;padding:7px 8px;border-radius:3px;}'
        . '#vitech-adminbar a:hover{background:#2c3338;color:#72aee6;}'
        . '#vitech-adminbar .vitech-adminbar-primary{background:#2271b1;color:#fff;}'
        . '#vitech-adminbar span{margin-left:auto;color:#c3c4c7;}'
        . '@media(max-width:782px){body{padding-top:46px!important;}#vitech-adminbar .vitech-adminbar-inner{height:46px;overflow-x:auto;gap:8px;}#vitech-adminbar span{display:none;}}'
        . '</style>';

    $replacement = '$0' . $styles . $toolbar;
    return preg_replace('#<body\b[^>]*>#i', $replacement, $html, 1) ?? ($styles . $toolbar . $html);
}

function vitech_clone_current_edit_url(): string
{
    $request_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $request_path = '/' . trim((string) $request_path, '/') . '/';
    if ($request_path === '//') {
        $request_path = '/';
    }

    if ($request_path === '/') {
        $front_page_id = (int) get_option('page_on_front');
        if ($front_page_id && current_user_can('edit_post', $front_page_id)) {
            return get_edit_post_link($front_page_id, 'raw') ?: '';
        }

        return admin_url('nav-menus.php');
    }

    $post_id = url_to_postid(home_url($request_path));
    if ($post_id && current_user_can('edit_post', $post_id)) {
        return get_edit_post_link($post_id, 'raw') ?: '';
    }

    $woocommerce_permalinks = get_option('woocommerce_permalinks');
    $category_base = is_array($woocommerce_permalinks) && !empty($woocommerce_permalinks['category_base'])
        ? (string) $woocommerce_permalinks['category_base']
        : 'product-category';
    $product_category_base = '/' . trim($category_base, '/') . '/';
    if (str_starts_with($request_path, $product_category_base)) {
        $slug = trim(substr($request_path, strlen($product_category_base)), '/');
        $term = get_term_by('slug', $slug, 'product_cat');
        if ($term instanceof WP_Term && current_user_can('manage_product_terms')) {
            return get_edit_term_link($term->term_id, 'product_cat', 'product') ?: '';
        }
    }

    $page = get_page_by_path(trim($request_path, '/'));
    if ($page instanceof WP_Post && current_user_can('edit_post', $page->ID)) {
        return get_edit_post_link($page->ID, 'raw') ?: '';
    }

    return '';
}

function vitech_clone_dynamic_products(): array
{
    $products = vitech_clone_dynamic_woocommerce_products();
    if ($products !== []) {
        return $products;
    }

    $query = new WP_Query([
        'post_type' => 'elevator',
        'post_status' => 'publish',
        'posts_per_page' => 24,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    $products = [];
    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        $image = get_the_post_thumbnail_url($post_id, 'woocommerce_thumbnail');
        if (!$image) {
            $image = get_post_meta($post_id, '_vitech_image_url', true);
        }

        $products[] = [
            'id' => $post_id,
            'title' => get_the_title(),
            'url' => get_permalink(),
            'price' => vitech_clone_price($post_id),
            'image' => is_string($image) && $image !== '' ? $image : vitech_clone_proxy_placeholder_image(),
        ];
    }
    wp_reset_postdata();

    return $products;
}

function vitech_clone_dynamic_woocommerce_products(): array
{
    if (!function_exists('wc_get_products')) {
        return [];
    }

    $wc_products = wc_get_products([
        'status' => 'publish',
        'limit' => 24,
        'orderby' => 'date',
        'order' => 'DESC',
        'return' => 'objects',
    ]);

    $products = [];
    foreach ($wc_products as $wc_product) {
        if (!$wc_product instanceof WC_Product) {
            continue;
        }

        $image = wp_get_attachment_image_url($wc_product->get_image_id(), 'woocommerce_thumbnail');
        if (!$image) {
            $image = get_post_meta($wc_product->get_id(), '_vitech_image_url', true);
        }
        $price = $wc_product->get_price_html();
        if ($price === '') {
            $price = get_post_meta($wc_product->get_id(), '_vitech_price_label', true);
        }

        $products[] = [
            'id' => $wc_product->get_id(),
            'title' => $wc_product->get_name(),
            'url' => get_permalink($wc_product->get_id()),
            'price' => is_string($price) && $price !== '' ? wp_strip_all_tags($price) : __('Liên hệ báo giá', 'vitech-clone'),
            'image' => is_string($image) && $image !== '' ? $image : vitech_clone_proxy_placeholder_image(),
        ];
    }

    return $products;
}

function vitech_clone_replace_document_meta(string $html): string
{
    $site_name = get_bloginfo('name') ?: 'Thang Máy';
    $description = get_bloginfo('description') ?: vitech_clone_option('hero_text', 'Dữ liệu được quản trị từ WordPress backend.');
    $image = vitech_clone_option('hero_image', '');

    $html = preg_replace('#<title>.*?</title>#su', '<title>' . esc_html($site_name) . '</title>', $html, 1) ?? $html;
    $html = preg_replace('#<meta name="description" content="[^"]*"\s*/?>#su', '<meta name="description" content="' . esc_attr($description) . '" />', $html, 1) ?? $html;
    $html = preg_replace('#<meta property="og:title" content="[^"]*"\s*/?>#su', '<meta property="og:title" content="' . esc_attr($site_name) . '" />', $html, 1) ?? $html;
    $html = preg_replace('#<meta property="og:description" content="[^"]*"\s*/?>#su', '<meta property="og:description" content="' . esc_attr($description) . '" />', $html, 1) ?? $html;
    $html = preg_replace('#<meta property="og:site_name" content="[^"]*"\s*/?>#su', '<meta property="og:site_name" content="' . esc_attr($site_name) . '" />', $html, 1) ?? $html;

    if ($image !== '') {
        $html = preg_replace('#<meta property="og:image" content="[^"]*"\s*/?>#su', '<meta property="og:image" content="' . esc_url($image) . '" />', $html, 1) ?? $html;
    }

    return $html;
}

function vitech_clone_proxy_phone_href(string $phone): string
{
    return 'tel:' . preg_replace('/[^0-9+]/', '', $phone);
}

function vitech_clone_proxy_placeholder_image(): string
{
    if (function_exists('wc_placeholder_img_src')) {
        return wc_placeholder_img_src('woocommerce_thumbnail');
    }

    return includes_url('images/media/default.png');
}

function vitech_clone_dynamic_terms(): array
{
    $terms = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'exclude' => [get_option('default_product_cat')],
        'number' => 12,
    ]);

    if (!is_wp_error($terms) && $terms !== []) {
        return array_values($terms);
    }

    $terms = get_terms([
        'taxonomy' => 'elevator_category',
        'hide_empty' => false,
        'number' => 12,
    ]);

    if (is_wp_error($terms)) {
        return [];
    }

    return array_values($terms);
}

function vitech_clone_dynamic_posts(): array
{
    $query = new WP_Query([
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 8,
        'ignore_sticky_posts' => true,
    ]);

    $posts = [];
    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        $image = get_the_post_thumbnail_url($post_id, 'large');

        $posts[] = [
            'title' => get_the_title(),
            'url' => get_permalink(),
            'image' => is_string($image) && $image !== '' ? $image : vitech_clone_proxy_placeholder_image(),
            'day_month' => get_the_date('d/m'),
            'year' => get_the_date('Y'),
        ];
    }
    wp_reset_postdata();

    return $posts;
}

function vitech_clone_render_proxy_product_card(array $product, int $position, string $phone, string $phone_href): string
{
    $classes = 'product-small col has-hover product type-product post-' . (int) $product['id'] . ' status-publish instock has-post-thumbnail shipping-taxable product-type-simple';
    if ($position % 4 === 1) {
        $classes .= ' first';
    } elseif ($position % 4 === 0) {
        $classes .= ' last';
    }

    $title = esc_html($product['title']);
    $url = esc_url($product['url']);
    $image = esc_url($product['image']);
    $price = esc_html($product['price']);
    $phone_label = esc_html($phone);
    $phone_link = esc_url($phone_href);

    return <<<HTML
<div class="$classes">
	<div class="col-inner">
<div class="badge-container absolute left top z-1">
</div>
	<div class="product-small box ">
		<div class="box-image">
			<div class="image-zoom-fade">
				<a href="$url">
					<img width="600" height="600" src="$image" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" alt="$title" decoding="async" loading="lazy" />				</a>
			</div>
			<div class="image-tools is-small top right show-on-hover">
							</div>
			<div class="image-tools is-small hide-for-small bottom left show-on-hover">
							</div>
					</div><!-- box-image -->

		<div class="box-text">
            <div class="title-wrapper"><p class="name product-title woocommerce-loop-product__title"><a href="$url" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">$title</a></p></div><div class="price-wrapper"><p class="price"><span>Giá : $price</span></p></div>
                              <div class="rt_add_to_cart clearfix">
                <div class="hotline_sp_tv">
                  <a href="$phone_link"><i class="fa-solid fa-phone"></i> Gọi tư vấn</a>
                </div>
                <div class="cart_sp_tv">
                                          <a class="quick-view" data-prod="{$product['id']}" href="$url">Xem nhanh</a>                                                    </div>
              </div>

        </div><!-- box-text -->
	</div><!-- box -->
		</div><!-- .col-inner -->
</div><!-- col -->
HTML;
}

function vitech_clone_replace_product_menu(string $html, array $terms): string
{
    $items = '';
    foreach ($terms as $term) {
        $items .= sprintf(
            "\n\t<li class=\"menu-item menu-item-type-taxonomy menu-item-object-product_cat\"><a href=\"%s\">%s</a></li>",
            esc_url(get_term_link($term)),
            esc_html(vitech_clone_term_upper($term->name))
        );
    }

    return preg_replace(
        '#(<li[^>]+menu-item-object-product_cat[^>]*>\s*<a[^>]*>\s*SẢN PHẨM\s*<i[^>]*></i>\s*</a>\s*<ul[^>]*>).*?(</ul>\s*</li>)#su',
        '$1' . $items . "\n" . '$2',
        $html
    ) ?? $html;
}

function vitech_clone_replace_sidebar_product_categories(string $html, array $terms): string
{
    $items = '';
    $index = 0;

    foreach ($terms as $term) {
        $classes = [
            'menu-item',
            'menu-item-type-taxonomy',
            'menu-item-object-product_cat',
            'menu-item-' . (10000 + $index),
        ];

        $items .= sprintf(
            "\n<li class=\"%s\"><a href=\"%s\">%s</a></li>",
            esc_attr(implode(' ', $classes)),
            esc_url(get_term_link($term)),
            esc_html(vitech_clone_term_upper($term->name))
        );
        ++$index;
    }

    if ($items === '') {
        return $html;
    }

    return preg_replace(
        '#(<div class="menu-danh-muc-san-pham-container">\s*<ul id="menu-danh-muc-san-pham" class="menu">).*?(</ul>\s*</div>)#su',
        '$1' . $items . "\n" . '$2',
        $html
    ) ?? $html;
}

function vitech_clone_replace_featured_news_menu(string $html, array $posts): string
{
    $items = '';
    foreach (array_slice($posts, 0, 5) as $index => $post) {
        $items .= sprintf(
            "\n<li id=\"menu-item-news-%d\" class=\"menu-item menu-item-type-post_type menu-item-object-post menu-item-news-%d\"><a href=\"%s\">%s</a></li>",
            $index,
            $index,
            esc_url($post['url']),
            esc_html(mb_strtoupper($post['title']))
        );
    }

    if ($items === '') {
        return $html;
    }

    return preg_replace(
        '#(<div class="menu-tin-tuc-noi-bat-container">\s*<ul id="menu-tin-tuc-noi-bat" class="menu">).*?(</ul>\s*</div>)#su',
        '$1' . $items . "\n" . '$2',
        $html
    ) ?? $html;
}

function vitech_clone_replace_product_section_headings(string $html, array $terms): string
{
    $index = 0;

    return preg_replace_callback(
        '#(<h2 class="heading clear">\s*<span>\s*)(.*?)(\s*</span>\s*<a href=")([^"]+)("[^>]*>\s*Xem tất cả&gt;&gt;\s*</a>)#su',
        static function (array $matches) use ($terms, &$index): string {
            if ($terms === []) {
                return $matches[0];
            }

            $term = $terms[$index % count($terms)];
            ++$index;

            return $matches[1]
                . esc_html(vitech_clone_term_upper($term->name))
                . $matches[3]
                . esc_url(get_term_link($term))
                . $matches[5];
        },
        $html
    ) ?? $html;
}

function vitech_clone_replace_home_category_labels(string $html, array $terms): string
{
    $source_labels = [
        'MÁY KÉO THANG MÁY',
        'TỦ ĐIỀU KHIỂN THANG MÁY',
        'BOARD ĐIỀU KHIỂN THANG MÁY',
        'BẢNG ĐIỀU KHIỂN COP/LOP',
        'BỘ TRUYỀN ĐỘNG CỬA',
        'ĐỘNG CƠ &amp; BIẾN TẦN CỬA',
        'ĐỘNG CƠ & BIẾN TẦN CỬA',
        'CÁP THÉP &amp; CÁP ĐIỆN',
        'CÁP THÉP & CÁP ĐIỆN',
        'RAIL &amp; PHỤ KIỆN',
        'RAIL & PHỤ KIỆN',
        'BỘ MÃ HÓA VÒNG QUAY',
        'THIẾT BỊ AN TOÀN',
        'TỦ CỨU HỘ THANG MÁY',
        'LINH KIỆN VÀ THIẾT BỊ KHÁC',
    ];

    if ($terms === []) {
        return $html;
    }

    $index = 0;
    foreach ($source_labels as $label) {
        $term = $terms[$index % count($terms)];
        $html = str_replace($label, esc_html(vitech_clone_term_upper($term->name)), $html);
        ++$index;
    }

    return $html;
}

function vitech_clone_replace_header_menu(string $html, array $terms): string
{
    $menu = vitech_clone_render_header_menu($terms);

    $html = preg_replace(
        '#<ul class="nav nav-left medium-nav-center nav-small\s+nav-divided nav-uppercase">.*?</ul>\s*</div>\s*<div class="flex-col hide-for-medium flex-center">#su',
        $menu . "\n      </div>\n\n      <div class=\"flex-col hide-for-medium flex-center\">",
        $html,
        1
    ) ?? $html;

    return $html;
}

function vitech_clone_render_header_menu(array $terms): string
{
    return '<ul class="nav nav-left medium-nav-center nav-small nav-divided nav-uppercase">' . vitech_clone_render_header_menu_items($terms, false) . '</ul>';
}

function vitech_clone_render_header_menu_items(array $terms, bool $include_search = false): string
{
    $items = vitech_clone_primary_menu_items();
    $html = '';

    if ($include_search) {
        $html .= '<li class="header-search-form search-form html relative has-icon">'
            . '<div class="header-search-form-wrapper"><div class="searchform-wrapper ux-search-box relative is-normal">'
            . '<form role="search" method="get" class="searchform" action="' . esc_url(home_url('/')) . '">'
            . '<div class="flex-row relative"><div class="flex-col flex-grow">'
            . '<input type="search" class="search-field mb-0" name="s" value="" placeholder="Nhập từ khóa tìm kiếm" />'
            . '</div><div class="flex-col"><button type="submit" class="ux-search-submit submit-button secondary button icon mb-0"><i class="icon-search"></i></button></div></div>'
            . '</form></div></div></li>';
    }

    foreach ($items as $item) {
        $children = $item['slug'] === 'san-pham' ? vitech_clone_header_product_children($terms) : [];
        $has_children = $children !== [];
        $classes = 'menu-item menu-item-type-' . esc_attr($item['type']) . ' menu-item-object-' . esc_attr($item['object']);
        if ($has_children) {
            $classes .= ' menu-item-has-children has-dropdown';
        }
        if ($item['active']) {
            $classes .= ' current-menu-item active';
        }

        $html .= '<li class="' . $classes . '">';
        $html .= '<a href="' . esc_url($item['url']) . '" class="nav-top-link">' . esc_html($item['title']);
        if ($has_children) {
            $html .= '<i class="icon-angle-down"></i>';
        }
        $html .= '</a>';

        if ($has_children) {
            $html .= '<ul class="sub-menu nav-dropdown nav-dropdown-default">';
            foreach ($children as $child) {
                $html .= '<li class="menu-item menu-item-type-taxonomy menu-item-object-product_cat"><a href="' . esc_url($child['url']) . '">' . esc_html($child['title']) . '</a></li>';
            }
            $html .= '</ul>';
        }

        $html .= '</li>';
    }

    return $html;
}

function vitech_clone_primary_menu_items(): array
{
    $locations = get_nav_menu_locations();
    $menu_id = $locations['primary'] ?? 0;
    $menu_items = $menu_id ? wp_get_nav_menu_items($menu_id) : false;
    $items = [];

    if ($menu_items) {
        foreach ($menu_items as $menu_item) {
            if ((int) $menu_item->menu_item_parent !== 0) {
                continue;
            }

            $url = (string) $menu_item->url;
            $slug = trim(parse_url($url, PHP_URL_PATH) ?: '', '/');
            $items[] = [
                'title' => $menu_item->title,
                'url' => $url,
                'type' => $menu_item->type ?: 'custom',
                'object' => $menu_item->object ?: 'custom',
                'slug' => $slug === '' ? 'trang-chu' : $slug,
                'active' => untrailingslashit($url) === untrailingslashit(home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? '/'))),
            ];
        }
    }

    if ($items !== []) {
        return $items;
    }

    return [
        ['title' => 'TRANG CHỦ', 'url' => home_url('/'), 'type' => 'custom', 'object' => 'custom', 'slug' => 'trang-chu', 'active' => is_front_page()],
        ['title' => 'GIỚI THIỆU', 'url' => home_url('/gioi-thieu/'), 'type' => 'post_type', 'object' => 'page', 'slug' => 'gioi-thieu', 'active' => false],
        ['title' => 'SẢN PHẨM', 'url' => home_url('/san-pham/'), 'type' => 'custom', 'object' => 'custom', 'slug' => 'san-pham', 'active' => false],
        ['title' => 'TÀI LIỆU', 'url' => home_url('/tai-lieu/'), 'type' => 'post_type', 'object' => 'page', 'slug' => 'tai-lieu', 'active' => false],
        ['title' => 'YÊU CẦU BÁO GIÁ', 'url' => home_url('/yeu-cau-bao-gia/'), 'type' => 'post_type', 'object' => 'page', 'slug' => 'yeu-cau-bao-gia', 'active' => false],
        ['title' => 'TIN TỨC', 'url' => home_url('/tin-tuc/'), 'type' => 'custom', 'object' => 'custom', 'slug' => 'tin-tuc', 'active' => false],
        ['title' => 'LIÊN HỆ', 'url' => home_url('/lien-he/'), 'type' => 'post_type', 'object' => 'page', 'slug' => 'lien-he', 'active' => false],
    ];
}

function vitech_clone_header_product_children(array $terms): array
{
    $children = [];
    foreach ($terms as $term) {
        $children[] = [
            'title' => vitech_clone_term_upper($term->name),
            'url' => get_term_link($term),
        ];
    }

    return $children;
}

function vitech_clone_replace_mobile_product_menu(string $html, array $terms): string
{
    $items = '';
    foreach ($terms as $term) {
        $items .= sprintf(
            "\n\t<li class=\"menu-item menu-item-type-taxonomy menu-item-object-product_cat\"><a href=\"%s\">%s</a></li>",
            esc_url(get_term_link($term)),
            esc_html(vitech_clone_term_upper($term->name))
        );
    }

    return preg_replace(
        '#(<li class="menu-item menu-item-type-taxonomy menu-item-object-product_cat menu-item-has-children[^"]*"><a[^>]*>\s*SẢN PHẨM\s*</a>\s*<ul[^>]*>).*?(</ul>\s*</li>)#su',
        '$1' . $items . "\n" . '$2',
        $html
    ) ?? $html;
}

function vitech_clone_replace_news_titles(string $html, array $posts): string
{
    $index = 0;

    $html = preg_replace_callback(
        '#(<a[^>]+href=")([^"]+)("[^>]*>)([^<]{12,120})(</a>)#u',
        static function (array $matches) use ($posts, &$index): string {
            $text = wp_strip_all_tags($matches[4]);
            if (
                !str_contains(mb_strtolower($text), 'thang') &&
                !str_contains(mb_strtolower($text), 'vitech') &&
                !str_contains(mb_strtolower($text), 'tin') &&
                !str_contains(mb_strtolower($text), 'cabin')
            ) {
                return $matches[0];
            }

            if ($index >= count($posts)) {
                return $matches[0];
            }

            $post = $posts[$index++];
            return $matches[1] . esc_url($post['url']) . $matches[3] . esc_html($post['title']) . $matches[5];
        },
        $html,
        8
    ) ?? $html;

    foreach ($posts as $post) {
        $html = preg_replace(
            '#(<a[^>]+href=")[^"]*("[^>]*>)VITECH CÙNG TẬP ĐOÀN INOVANCE GIỚI THIỆU SẢN PHẨM, GIẢI PHÁP CÔNG NGHỆ THANG MÁY MỚI(</a>)#u',
            '$1' . esc_url($post['url']) . '$2' . esc_html($post['title']) . '$3',
            $html,
            1
        ) ?? $html;
        break;
    }

    return $html;
}

function vitech_clone_replace_blog_cards(string $html, array $posts): string
{
    if ($posts === []) {
        return $html;
    }

    $index = 0;

    return preg_replace_callback(
        '#<div class="col post-item"\s*>.*?</div>\s*</div>\s*</div>#su',
        static function (array $matches) use ($posts, &$index): string {
            $post = $posts[$index % count($posts)];
            ++$index;

            return vitech_clone_render_blog_card($post);
        },
        $html
    ) ?? $html;
}

function vitech_clone_render_blog_card(array $post): string
{
    $title = esc_html($post['title']);
    $url = esc_url($post['url']);
    $image = esc_url($post['image'] ?? vitech_clone_proxy_placeholder_image());
    $day_month = esc_html($post['day_month'] ?? date_i18n('d/m'));
    $year = esc_html($post['year'] ?? date_i18n('Y'));

    return <<<HTML
<div class="col post-item" >
			<div class="col-inner">
				<div class="box box-shade dark box-text-bottom box-blog-post has-hover">
            					<div class="box-image" >
  						<div class="image-zoom image-cover" style="padding-top:61.5%;">
							<a href="$url" class="plain" aria-label="$title">
								<img width="900" height="550" src="$image" class="attachment-original size-original wp-post-image" alt="$title" decoding="async" loading="lazy" />							</a>
  							  							<div class="shade"></div>  						</div>
  						  					</div>
          					<div class="box-text text-left" >
					<div class="box-text-inner blog-post-inner">

					
										<h5 class="post-title is-large ">
						<a href="$url" class="plain">$title</a>
					</h5>
					<div class="post-meta is-small op-8"><span>$day_month</span><span>$year</span></div>					<div class="is-divider"></div>
					                    
					
					
					</div>
					</div>
									</div>
			</div>
		</div>
HTML;
}

function vitech_clone_inject_single_post(string $html, WP_Post $post, string $template_url): string
{
    $title = get_the_title($post);
    $title_upper = mb_strtoupper($title);
    $permalink = get_permalink($post);
    $site_name = get_bloginfo('name') ?: 'Thang Máy';
    $excerpt = wp_strip_all_tags(get_the_excerpt($post));

    // Canonical, og:url, fb-comments data-href... đều trỏ về URL template.
    $html = str_replace([$template_url, str_replace('/', '\/', $template_url)], [$permalink, str_replace('/', '\/', $permalink)], $html);

    // Thay mọi chỗ xuất hiện tiêu đề bài template (title tag, JSON-LD, og:title...).
    if (preg_match('#<h1 class="heading_td">\s*(.*?)\s*</h1>#su', $html, $matches)) {
        $source_title = trim(wp_strip_all_tags($matches[1]));
        if ($source_title !== '') {
            $html = str_replace($source_title, $title_upper, $html);
        }
    }

    $html = preg_replace('#<title>.*?</title>#su', '<title>' . esc_html($title . ' - ' . $site_name) . '</title>', $html, 1) ?? $html;
    if ($excerpt !== '') {
        $html = preg_replace('#<meta name="description" content="[^"]*"\s*/?>#su', '<meta name="description" content="' . esc_attr($excerpt) . '" />', $html, 1) ?? $html;
        $html = preg_replace('#<meta property="og:description" content="[^"]*"\s*/?>#su', '<meta property="og:description" content="' . esc_attr($excerpt) . '" />', $html, 1) ?? $html;
    }
    $html = preg_replace('#<meta property="og:title" content="[^"]*"\s*/?>#su', '<meta property="og:title" content="' . esc_attr($title) . '" />', $html, 1) ?? $html;

    $thumbnail = get_the_post_thumbnail_url($post, 'full');
    if (is_string($thumbnail) && $thumbnail !== '') {
        $html = preg_replace('#<meta property="og:image" content="[^"]*"\s*/?>#su', '<meta property="og:image" content="' . esc_url($thumbnail) . '" />', $html, 1) ?? $html;
    }

    $html = preg_replace(
        '#(<span class="breadcrumb_last"[^>]*>).*?(</span>)#su',
        '$1' . esc_html($title_upper) . '$2',
        $html,
        1
    ) ?? $html;

    $html = preg_replace(
        '#(<h1 class="heading_td">).*?(</h1>)#su',
        '$1' . esc_html($title_upper) . '$2',
        $html,
        1
    ) ?? $html;

    $html = preg_replace(
        '#<div><em>Ngày đăng:.*?</em></div>#su',
        '<div><em>Ngày đăng: ' . esc_html(vitech_clone_post_date_label($post)) . '</em></div>',
        $html,
        1
    ) ?? $html;

    $content = apply_filters('the_content', $post->post_content);
    $html = preg_replace(
        '#<div class="boxx__content__single">.*?<div id="fb-root">#su',
        '<div class="boxx__content__single"><div class="box__nth_2">' . vitech_clone_preg_replacement($content) . '</div></div></div><div id="fb-root">',
        $html,
        1
    ) ?? $html;

    return vitech_clone_replace_related_news($html, $post);
}

function vitech_clone_post_date_label(WP_Post $post): string
{
    $meridiem = get_the_date('A', $post) === 'PM' ? 'Chiều' : 'Sáng';

    return get_the_date('d/m/Y g:i', $post) . ' ' . $meridiem;
}

function vitech_clone_replace_related_news(string $html, WP_Post $current): string
{
    $query = new WP_Query([
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 6,
        'post__not_in' => [$current->ID],
        'ignore_sticky_posts' => true,
    ]);

    $related = [];
    while ($query->have_posts()) {
        $query->the_post();
        $image = get_the_post_thumbnail_url(get_the_ID(), 'full');

        $related[] = [
            'title' => mb_strtoupper(get_the_title()),
            'url' => get_permalink(),
            'image' => is_string($image) && $image !== '' ? $image : vitech_clone_proxy_placeholder_image(),
        ];
    }
    wp_reset_postdata();

    if ($related === []) {
        // Không có bài liên quan: bỏ nguyên khối "Tin tức liên quan" của template.
        return preg_replace('#<div id="related-post1">.*?</div>\s*</div>\s*</div>\s*</div>#su', '', $html, 1) ?? $html;
    }

    $index = 0;

    return preg_replace_callback(
        '#<div class="new-list-post">\s*<div>.*?</div>\s*</div>\s*</div>#su',
        static function (array $matches) use ($related, &$index): string {
            if ($index >= count($related)) {
                return '';
            }

            return vitech_clone_render_related_news_item($related[$index++]);
        },
        $html
    ) ?? $html;
}

function vitech_clone_render_related_news_item(array $post): string
{
    $title = esc_html($post['title']);
    $url = esc_url($post['url']);
    $image = esc_url($post['image']);

    return <<<HTML
<div class="new-list-post">
	<div>
		<div class="post-image">
			<a href="$url" title="$title">
				<img width="900" height="550" src="$image" class="attachment-full size-full wp-post-image" alt="$title" decoding="async" loading="lazy" />
			</a>
		</div>
		<div class="post-content">
			<h3><a class="title" href="$url" title="$title">$title</a></h3>
			<a class="more" href="$url" >Xem chi tiết <i class="fa-solid fa-arrow-right-long"></i></a>
		</div>
	</div>
</div>
HTML;
}

// Escape nội dung động trước khi dùng làm replacement của preg_replace
// để '$' hoặc '\' trong nội dung không bị hiểu là backreference.
// Tên term trong DB có thể chứa entity (&amp;) — decode trước khi viết hoa
// để tránh ra "&AMP;" rồi bị esc_html encode kép.
function vitech_clone_term_upper(string $name): string
{
    return mb_strtoupper(wp_specialchars_decode($name, ENT_QUOTES));
}

function vitech_clone_preg_replacement(string $text): string
{
    return strtr($text, ['\\' => '\\\\', '$' => '\\$']);
}

function vitech_clone_inject_product_category(string $html, WP_Term $term): string
{
    $name_upper = vitech_clone_term_upper($term->name);
    $site_name = get_bloginfo('name') ?: 'Thang Máy';

    $html = preg_replace('#<title>.*?</title>#su', '<title>' . esc_html(wp_specialchars_decode($term->name, ENT_QUOTES) . ' - ' . $site_name) . '</title>', $html, 1) ?? $html;
    $html = preg_replace('#<meta property="og:title" content="[^"]*"\s*/?>#su', '<meta property="og:title" content="' . esc_attr(wp_specialchars_decode($term->name, ENT_QUOTES)) . '" />', $html, 1) ?? $html;

    $html = preg_replace(
        '#(<h1 class="shop-page-title is-xlarge">).*?(</h1>)#su',
        '$1' . esc_html($name_upper) . '$2',
        $html,
        1
    ) ?? $html;

    $html = preg_replace(
        '#(<nav class="woocommerce-breadcrumb breadcrumbs uppercase">.*?<span class="divider">[^<]*</span>).*?(</nav>)#su',
        '$1 ' . esc_html($name_upper) . '$2',
        $html,
        1
    ) ?? $html;

    $products = vitech_clone_term_products($term);
    $count = count($products);
    $count_label = $count === 0
        ? 'Chưa có sản phẩm trong danh mục này'
        : ($count === 1 ? 'Hiển thị kết quả duy nhất' : 'Hiển thị tất cả ' . $count . ' kết quả');
    $html = preg_replace(
        '#(<p class="woocommerce-result-count[^"]*">).*?(</p>)#su',
        '$1' . esc_html($count_label) . '$2',
        $html
    ) ?? $html;

    $phone = vitech_clone_option('phone_primary', '0901 234 567');
    $phone_href = vitech_clone_proxy_phone_href($phone);
    $cards = '';
    foreach ($products as $index => $product) {
        $cards .= vitech_clone_render_proxy_product_card($product, $index + 1, $phone, $phone_href);
    }
    if ($cards === '') {
        $cards = '<div class="col"><p>Chưa có sản phẩm trong danh mục này.</p></div>';
    }

    return preg_replace(
        '#(<div class="products row[^"]*">).*</div><!-- col -->#s',
        '$1' . vitech_clone_preg_replacement($cards),
        $html,
        1
    ) ?? $html;
}

function vitech_clone_term_products(WP_Term $term): array
{
    $query = new WP_Query([
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => 48,
        'orderby' => 'date',
        'order' => 'DESC',
        'tax_query' => [[
            'taxonomy' => $term->taxonomy,
            'field' => 'term_id',
            'terms' => $term->term_id,
        ]],
    ]);

    $products = [];
    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        $image = get_the_post_thumbnail_url($post_id, 'woocommerce_thumbnail');
        if (!$image) {
            $image = get_post_meta($post_id, '_vitech_image_url', true);
        }

        $price = '';
        if (function_exists('wc_get_product')) {
            $wc_product = wc_get_product($post_id);
            if ($wc_product instanceof WC_Product) {
                $price = wp_strip_all_tags($wc_product->get_price_html());
            }
        }
        if ($price === '') {
            $price = vitech_clone_price($post_id);
        }

        $products[] = [
            'id' => $post_id,
            'title' => get_the_title(),
            'url' => get_permalink(),
            'price' => $price,
            'image' => is_string($image) && $image !== '' ? $image : vitech_clone_proxy_placeholder_image(),
        ];
    }
    wp_reset_postdata();

    return $products;
}

function vitech_clone_inject_single_product(string $html, WP_Post $post, string $template_url): string
{
    $title = get_the_title($post);
    $title_upper = mb_strtoupper($title);
    $permalink = get_permalink($post);
    $site_name = get_bloginfo('name') ?: 'Thang Máy';

    $html = str_replace([$template_url, str_replace('/', '\/', $template_url)], [$permalink, str_replace('/', '\/', $permalink)], $html);

    // Thay mọi chỗ xuất hiện tên sản phẩm template (title tag, JSON-LD, og:title...).
    if (preg_match('#<h1 class="product-title entry-title">\s*(.*?)\s*</h1>#su', $html, $matches)) {
        $source_title = trim(wp_strip_all_tags($matches[1]));
        if ($source_title !== '') {
            $html = str_replace($source_title, $title_upper, $html);
        }
    }
    $html = preg_replace('#<title>.*?</title>#su', '<title>' . esc_html($title . ' - ' . $site_name) . '</title>', $html, 1) ?? $html;

    $wc_product = function_exists('wc_get_product') ? wc_get_product($post->ID) : null;

    $image_full = get_the_post_thumbnail_url($post, 'full');
    $image_thumb = get_the_post_thumbnail_url($post, 'woocommerce_thumbnail');
    if (!$image_full) {
        $meta_image = get_post_meta($post->ID, '_vitech_image_url', true);
        $image_full = is_string($meta_image) && $meta_image !== '' ? $meta_image : vitech_clone_proxy_placeholder_image();
    }
    if (!$image_thumb) {
        $image_thumb = $image_full;
    }

    $html = preg_replace('#<meta property="og:image" content="[^"]*"\s*/?>#su', '<meta property="og:image" content="' . esc_url($image_full) . '" />', $html, 1) ?? $html;

    $slide = '<div data-thumb="' . esc_url($image_thumb) . '" data-thumb-alt="' . esc_attr($title) . '" class="woocommerce-product-gallery__image slide first">'
        . '<a href="' . esc_url($image_full) . '">'
        . '<img width="600" height="600" src="' . esc_url($image_thumb) . '" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" alt="' . esc_attr($title) . '" decoding="async" />'
        . '</a></div>';
    $gallery_slide_pattern = '#<div data-thumb="[^"]*"[^>]*woocommerce-product-gallery__image[^>]*>.*?</a></div>#s';
    $html = preg_replace($gallery_slide_pattern, '<!-- vitech-gallery-slide -->', $html, 1) ?? $html;
    $html = preg_replace($gallery_slide_pattern, '', $html) ?? $html;
    $html = str_replace('<!-- vitech-gallery-slide -->', $slide, $html);
    $html = preg_replace('#<div class="product-thumbnails.*?</div><!-- \.product-thumbnails -->#s', '', $html, 1) ?? $html;

    $price = '';
    if ($wc_product instanceof WC_Product) {
        $price = wp_strip_all_tags($wc_product->get_price_html());
    }
    if ($price === '') {
        $price = vitech_clone_price($post->ID);
    }
    $html = preg_replace(
        '#(<p class="price2"><span>).*?(</span></p>)#su',
        '$1' . esc_html('Giá : ' . $price) . '$2',
        $html,
        1
    ) ?? $html;

    $short_description = trim((string) $post->post_excerpt);
    $html = preg_replace(
        '#(<div class="product-short-description">).*?(</div>)#su',
        '$1' . vitech_clone_preg_replacement($short_description !== '' ? wpautop(esc_html($short_description)) : '') . '$2',
        $html,
        1
    ) ?? $html;

    $term_links = [];
    $terms = get_the_terms($post, 'product_cat');
    if (is_array($terms)) {
        foreach ($terms as $term) {
            $term_links[] = '<a href="' . esc_url(get_term_link($term)) . '" rel="tag">' . esc_html(vitech_clone_term_upper($term->name)) . '</a>';
        }
    }
    if ($term_links !== []) {
        $html = preg_replace(
            '#(<div class="brand">\s*<span>DANH MỤC: </span>).*?(</div>)#su',
            '$1' . implode(', ', $term_links) . '$2',
            $html,
            1
        ) ?? $html;
    } else {
        $html = preg_replace('#<div class="brand">.*?</div>#su', '', $html, 1) ?? $html;
    }

    $sku = $wc_product instanceof WC_Product ? (string) $wc_product->get_sku() : '';
    if ($sku !== '') {
        $html = preg_replace(
            '#(<div class="model">\s*<span>MÃ HÀNG: </span>).*?(</div>)#su',
            '$1' . esc_html($sku) . '$2',
            $html,
            1
        ) ?? $html;
    } else {
        $html = preg_replace('#<div class="model">.*?</div>#su', '', $html, 1) ?? $html;
    }

    // Tài liệu download và lượt xem là dữ liệu riêng của sản phẩm template.
    $html = preg_replace('#<div class="document">.*?</div>#su', '', $html, 1) ?? $html;
    $html = preg_replace('#<div class="view">.*?</div>#su', '', $html, 1) ?? $html;

    $content = trim(apply_filters('the_content', $post->post_content));
    if ($content === '') {
        $content = '<p>Nội dung đang được cập nhật.</p>';
    }
    $html = preg_replace(
        '#(<div class="woocommerce-Tabs-panel woocommerce-Tabs-panel--description[^>]*>).*?<div id="danh-gia">#su',
        '$1' . vitech_clone_preg_replacement($content) . '</div></div><div id="danh-gia">',
        $html,
        1
    ) ?? $html;

    // Form đánh giá: trỏ comment về sản phẩm local thay vì ID của trang nguồn,
    // và gắn reCAPTCHA v3 (dùng chung script submit với form liên hệ/báo giá).
    $html = preg_replace(
        "#(<input type='hidden' name='comment_post_ID' value=')[0-9]+(')#",
        '${1}' . $post->ID . '$2',
        $html
    ) ?? $html;

    if (vitech_clone_recaptcha_enabled_for('review')) {
        $token_input = '<input type="hidden" name="vitech_recaptcha_token" value="" data-recaptcha-action="'
            . esc_attr(vitech_clone_recaptcha_action('review')) . '" />';
        $html = preg_replace(
            '#(<form action="[^"]*wp-comments-post\.php"[^>]*class="comment-form)("[^>]*>)#',
            '$1 vitech-local-form$2' . $token_input,
            $html,
            1
        ) ?? $html;
    }

    return $html;
}

function vitech_clone_replace_footer_contact(string $html, string $phone): string
{
    $company = get_bloginfo('name') ?: 'Thang Máy';
    $address = vitech_clone_option('company_address', '');
    $secondary = vitech_clone_option('phone_secondary', '');
    $email = vitech_clone_option('contact_email', get_option('admin_email') ?: '');
    $phone_text = $secondary !== '' ? $secondary . ' - Hotline: ' . $phone : $phone;

    $footer = '<ul class="sidebar-wrapper ul-reset"><li id="custom_html-3" class="widget_text widget widget_custom_html"><h2 class="widgettitle">'
        . esc_html($company)
        . '</h2><div class="textwidget custom-html-widget"><p><i class="fa-solid fa-location-dot"></i> Địa chỉ : '
        . esc_html($address)
        . '</p><p><i class="fa-solid fa-phone"></i> Điện thoại: '
        . esc_html($phone_text)
        . '</p><p><i class="fa-regular fa-envelope"></i> Email: '
        . esc_html($email)
        . '</p></div></li></ul>';

    $html = preg_replace(
        '#<ul class="sidebar-wrapper ul-reset"><li id="custom_html-3" class="widget_text widget widget_custom_html">.*?</ul>#su',
        $footer,
        $html,
        1
    ) ?? $html;

    return preg_replace(
        '#<p>© Copyright .*?</p>#su',
        '<p>© Copyright ' . esc_html(date_i18n('Y') . ' ' . $company) . ' . All rights reserved.</p>',
        $html,
        1
    ) ?? $html;
}
