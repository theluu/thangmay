<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'Invalid request.' );
}

add_action('admin_menu', 'hotline_zalo_setup_menu');
function hotline_zalo_setup_menu(){
    add_menu_page( 
        'Hotline & Zalo Setting', 
        'Hotline & Zalo', 
        'manage_options', 
        'hotline-zalo-settings-page',
        'hotline_zalo_settings_template_callback', 
        'dashicons-whatsapp',
        null
    );
}

if (!function_exists('haidm_hotline_zalo')) {
    function haidm_hotline_zalo() {
    ?>
        <div class="zalo-ring-wrap">
            <div class="hotline-phone-ring">
                <div class="zalo-ring-circle"></div>
                <div class="zalo-ring-circle-fill"></div>
                <div class="zalo-ring-img-circle">
                    <a target="_blank" href="<?php if ( get_option( 'zalo_number_settings_input_field' ) ) { ?>https://zalo.me/<?php echo get_option( 'zalo_number_settings_input_field' ); }else{ ?>#<?php } ?>" class="pps-btn-img">
                        <img src="<?php echo plugin_dir_url( __FILE__ ) . '/img/icon-zalo.png'; ?>" alt="Zalo" width="50">
                    </a>
                </div>
            </div>
            <div class="zalo-bar">
                <a target="_blank" href="<?php if ( get_option( 'zalo_number_settings_input_field' ) ) { ?>https://zalo.me/<?php echo get_option( 'zalo_number_settings_input_field' ); }else{ ?>#<?php } ?>">
                    <span class="text-hotline"><?php if ( get_option( 'zalo_text_settings_input_field' ) ) { echo get_option( 'zalo_text_settings_input_field' ); }else{ ?> Gọi ngay <?php } ?></span>
                </a>
            </div>
        </div><!-- .zalo-ring-wrap -->

        <div class="hotline-phone-ring-wrap">
            <div class="hotline-phone-ring">
                <div class="hotline-phone-ring-circle"></div>
                <div class="hotline-phone-ring-circle-fill"></div>
                <div class="hotline-phone-ring-img-circle">
                    <a target="_blank" href="<?php if ( get_option( 'hotline_number_settings_input_field' ) ) { ?>tel:<?php echo get_option( 'hotline_number_settings_input_field' ); }else{ ?>#<?php } ?>" class="pps-btn-img">
                        <img src="<?php echo plugin_dir_url( __FILE__ ) . '/img/icon-call.png'; ?>" alt="Gọi điện thoại" width="50">
                    </a>
                </div>
            </div>
            <div class="hotline-bar">
                <a target="_blank" href="<?php if ( get_option( 'hotline_number_settings_input_field' ) ) { ?>tel:<?php echo get_option( 'hotline_number_settings_input_field' ); }else{ ?>#<?php } ?>">
                    <span class="text-hotline"><?php if ( get_option( 'hotline_text_settings_input_field' ) ) { echo get_option( 'hotline_text_settings_input_field' ); }else{ ?>Gọi ngay<?php } ?></span>
                </a>
            </div>
        </div><!-- .hotline-phone-ring-wrap -->
    <?php
    }
    add_action( 'wp_footer', 'haidm_hotline_zalo' );
}
 
function hotline_zalo_settings_template_callback(){
?>
    <h1>Cài đặt Hotline và Zalo</h1>

    <form method="post" action="options.php">
        <?php
            settings_fields('hotline-zalo-settings-page');

            do_settings_sections('hotline-zalo-settings-page');

            submit_button('Save Settings')
        ?>
    </form>
<?php
}

add_action('admin_init', 'hotline_zalo_settings_init');
function hotline_zalo_settings_init() {
    add_settings_section(
        'hotline_zalo_settings_section',
        '',
        '',
        'hotline-zalo-settings-page'
    );

    register_setting(
        'hotline-zalo-settings-page',
        'hotline_text_settings_input_field',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Call now'
        )
    );

    add_settings_field(
        'hotline_text_settings_input_field',
        __('Hotline text', 'hotline-text'),
        'haidm_hotline_text_callback',
        'hotline-zalo-settings-page',
        'hotline_zalo_settings_section',
    );

    register_setting(
        'hotline-zalo-settings-page',
        'hotline_number_settings_input_field',
        array(
            'type' => 'text',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        )
    );

    add_settings_field(
        'hotline_number_settings_input_field',
        __('Hotline number', 'hotline-text'),
        'haidm_hotline_number_callback',
        'hotline-zalo-settings-page',
        'hotline_zalo_settings_section',
    );
    
    register_setting(
        'hotline-zalo-settings-page',
        'zalo_text_settings_input_field',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Call now'
        )
    );

    add_settings_field(
        'zalo_text_settings_input_field',
        __('Zalo text', 'zalo-text'),
        'haidm_zalo_text_callback',
        'hotline-zalo-settings-page',
        'hotline_zalo_settings_section',
    );

    register_setting(
        'hotline-zalo-settings-page',
        'zalo_number_settings_input_field',
        array(
            'type' => 'text',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Call now'
        )
    );

    add_settings_field(
        'zalo_number_settings_input_field',
        __('Zalo number', 'zalo-text'),
        'haidm_zalo_number_callback',
        'hotline-zalo-settings-page',
        'hotline_zalo_settings_section',
    );
}

function haidm_hotline_text_callback() {
    $hotline_text_input_field = get_option('hotline_text_settings_input_field');
?>

    <input type="text" placeholder="Call now" name="hotline_text_settings_input_field" class="regular-text" value="<?php echo isset( $hotline_text_input_field ) ? esc_attr( $hotline_text_input_field ) : 'Call now'; ?>" />

<?php
}

function haidm_hotline_number_callback() {
    $hotline_number_input_field = get_option('hotline_number_settings_input_field');
?>

    <input type="text" placeholder="0123456789" name="hotline_number_settings_input_field" class="regular-text" value="<?php echo isset( $hotline_number_input_field ) ? esc_attr( $hotline_number_input_field ) : ''; ?>" />

<?php
}

function haidm_zalo_text_callback() {
    $zalo_text_input_field = get_option('zalo_text_settings_input_field');
?>

    <input type="text" placeholder="Call now" name="zalo_text_settings_input_field" class="regular-text" value="<?php echo isset( $zalo_text_input_field ) ? esc_attr( $zalo_text_input_field ) : 'Call now'; ?>" />

<?php
}

function haidm_zalo_number_callback() {
    $zalo_number_input_field = get_option('zalo_number_settings_input_field');
?>

    <input type="text" placeholder="0123456789" name="zalo_number_settings_input_field" class="regular-text" value="<?php echo isset( $zalo_number_input_field ) ? esc_attr( $zalo_number_input_field ) : ''; ?>" />

<?php
}
