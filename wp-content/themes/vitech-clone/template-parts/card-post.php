<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<article <?php post_class('news-card'); ?>>
    <a href="<?php the_permalink(); ?>">
        <?php if (has_post_thumbnail()) : ?>
            <?php the_post_thumbnail('large'); ?>
        <?php else : ?>
            <span class="news-card__placeholder"><?php esc_html_e('Tin tức', 'vitech-clone'); ?></span>
        <?php endif; ?>
    </a>
    <div class="news-card__body">
        <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
        <time datetime="<?php echo esc_attr(get_the_date(DATE_W3C)); ?>"><?php echo esc_html(get_the_date()); ?></time>
        <p><?php echo esc_html(wp_trim_words(get_the_excerpt(), 18)); ?></p>
    </div>
</article>
