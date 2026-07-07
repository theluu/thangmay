<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<article <?php post_class('product-card'); ?>>
    <a class="product-card__image" href="<?php the_permalink(); ?>">
        <?php echo vitech_clone_post_image(get_the_ID(), 'medium_large'); ?>
    </a>
    <div class="product-card__body">
        <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
        <div class="price"><?php echo esc_html(vitech_clone_price(get_the_ID())); ?></div>
        <div class="card-actions">
            <a href="<?php the_permalink(); ?>"><?php esc_html_e('Chi tiết', 'vitech-clone'); ?></a>
            <a href="<?php echo esc_url(home_url('/#lien-he')); ?>"><?php esc_html_e('Báo giá', 'vitech-clone'); ?></a>
        </div>
    </div>
</article>
