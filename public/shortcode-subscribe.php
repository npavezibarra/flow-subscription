<?php
function flow_shortcode_subscribe($atts) {
    $atts = shortcode_atts([
        'plan' => ''
    ], $atts);

    if (!$atts['plan']) return '';

    $plan = sanitize_text_field($atts['plan']);
    $button_id = $plan . "-button";

    ob_start(); ?>
    
    <button 
        id="<?php echo esc_attr($button_id); ?>"
        class="flow-subscribe-button"
        data-plan="<?php echo esc_attr($plan); ?>"
    >Suscribir</button>

    <?php return ob_get_clean();
}
add_shortcode('flow_subscribe', 'flow_shortcode_subscribe');

function flow_enqueue_scripts() {
    wp_enqueue_script(
        'flow-subscribe',
        plugin_dir_url(__FILE__) . 'js/flow-subscribe.js',
        [],
        false,
        true
    );
    wp_localize_script('flow-subscribe', 'flow_ajax', [
        'ajax_url' => admin_url('admin-ajax.php')
    ]);
}
add_action('wp_enqueue_scripts', 'flow_enqueue_scripts');
