<?php
/*
Plugin Name: Tapfiliate
Description: Easily integrate the Tapfiliate tracking code.
Version: 2.1
Author: Tapfiliate
Author URI: https://tapfiliate.com/
*/

if (!defined('WP_CONTENT_URL'))
      define('WP_CONTENT_URL', get_option('siteurl').'/wp-content');
if (!defined('WP_CONTENT_DIR'))
      define('WP_CONTENT_DIR', ABSPATH.'wp-content');
if (!defined('WP_PLUGIN_URL'))
      define('WP_PLUGIN_URL', WP_CONTENT_URL.'/plugins');
if (!defined('WP_PLUGIN_DIR'))
      define('WP_PLUGIN_DIR', WP_CONTENT_DIR.'/plugins');

function activate_tapfiliate()
{
  add_option('tap_account_id', '1-123abc');
}

function deactive_tapfiliate()
{
  delete_option('tap_account_id');
}

function admin_init_tapfiliate()
{
  register_setting('tapfiliate', 'tap_account_id');
  register_setting('tapfiliate', 'thank_you_page');
  register_setting('tapfiliate', 'query_parameter_external_id');
  register_setting('tapfiliate', 'query_parameter_conversion_amount');
  register_setting('tapfiliate', 'integrate_for');
  register_setting('tapfiliate', 'program_group');
}

function admin_menu_tapfiliate()
{
  add_options_page('Tapfiliate', 'Tapfiliate', 'manage_options', 'tapfiliate', 'options_page_tapfiliate');
}

function options_page_tapfiliate()
{
  include(dirname(__FILE__) . '/options.php');
}

function tapfiliate_render_wordpress_code()
{
    global $post;
    $post_name = $post ? $post->post_name : null;
    $thank_you_page = get_option('thank_you_page');
    $query_parameter_external_id = get_option('query_parameter_external_id');
    $query_parameter_conversion_amount = get_option('query_parameter_conversion_amount');

    $is_converting = false;
    $external_id_arg = null;
    $amount_arg = null;
    $options_arg = null;
    if ($post_name === $thank_you_page) {
        $has_external_id_parameter_configured = !empty($query_parameter_external_id);
        $external_id = isset($_GET[$query_parameter_external_id]) ? $_GET[$query_parameter_external_id] : null;
        $amount = isset($_GET[$query_parameter_conversion_amount]) ? $_GET[$query_parameter_conversion_amount] : null;

        if ($external_id || !$has_external_id_parameter_configured) {
            $is_converting = true;
            $options = [];
            if ($program_group = get_option('program_group')) {
                $options = [
                    "program_group" => $program_group,
                ];
            }

            $external_id_arg = $external_id !== null ? "'$external_id'" : "null";
            $amount_arg = $amount !== null ? $amount : 'null';
            $options_arg = json_encode($options, JSON_FORCE_OBJECT);
        }
    }

    tapfiliate_output_inline_code($is_converting, $external_id_arg, $amount_arg, $options_arg);
}

function tapfiliate_render_woocommerce_code()
{
    $is_converting = false;
    $external_id_arg = null;
    $amount_arg = null;
    $options_arg = null;
    if (function_exists("is_order_received_page") && is_order_received_page() && isset($GLOBALS['order-received'])) {
        $is_converting = true;

        $isWoo3 = false;
        if (class_exists('WooCommerce')) {
            global $woocommerce;
            $isWoo3 = version_compare($woocommerce->version, "3.0", ">=");
        }

        $order_id  = apply_filters('woocommerce_thankyou_order_id', absint($GLOBALS['order-received']));
        $order_key = apply_filters('woocommerce_thankyou_order_key', empty($_GET['key']) ? '' : wc_clean($_GET['key']));

        if ($order_id <= 0) return;

        $order = new WC_Order($order_id);
        $order_key_check = $isWoo3 ? $order->get_order_key() : $order->order_key;

        if ($order_key_check !== $order_key) return;

        $options = [
            "meta_data" => [],
        ];

        $i = 1;
        foreach ($order->get_items() as $item) {
            $key = "product" . $i++;
            $line_item = "{$item['name']} - qty: {$item['qty']}";
            $options['meta_data'][$key] = $line_item;
        }

        if ($program_group = get_option('program_group')) {
          $options['program_group'] = $program_group;
        }

        $external_id_arg = $isWoo3 ? $order->get_id() : $order->id;
        $amount_arg = $order->get_subtotal() - $order->get_total_discount();
        $options_arg = json_encode($options, JSON_FORCE_OBJECT);
    }

    tapfiliate_output_inline_code($is_converting, $external_id_arg, $amount_arg, $options_arg);
}

function tapfiliate_render_wpeasycart_conversion_code($ec_order_id, $ec_order)
{
    $is_converting = true;

    if ($program_group = get_option('program_group')) {
        $options['program_group'] = $program_group;
    }

    $external_id_arg = $ec_order_id;
    $amount_arg = $ec_order->sub_total;
    $options_arg = json_encode($options, JSON_FORCE_OBJECT);

    tapfiliate_output_inline_code(true, $external_id_arg, $amount_arg, $options_arg);
}

function tapfiliate_output_inline_code($is_converting, $external_id_arg = null, $amount_arg = null, $options_arg = null)
{
    $tap_account_id = get_option('tap_account_id');

    ob_start();
    include(dirname(__FILE__) . '/tracking-snippet.php');
    $script = ob_get_contents();
    ob_end_clean();

    wp_add_inline_script("tapfiliate-js", $script);
}

function tapfiliate()
{
  wp_enqueue_script("tapfiliate-js", "https://script.tapfiliate.com/tapfiliate.js");

  $integrate_for = get_option('integrate_for');
  switch ($integrate_for) {
    case 'wp':
      tapfiliate_render_wordpress_code();
      break;

    case 'wc':
      tapfiliate_render_woocommerce_code();
      break;

    case 'ec':
      tapfiliate_output_inline_code(false);
      break;
  }
}

register_activation_hook(__FILE__, 'activate_tapfiliate');
register_deactivation_hook(__FILE__, 'deactive_tapfiliate');

if (is_admin()) {
  add_action('admin_init', 'admin_init_tapfiliate');
  add_action('admin_menu', 'admin_menu_tapfiliate');
}

if (!is_admin()) {
  add_action('wpeasycart_success_page_content_top', 'render_wpeasycart_conversion_code', 10, 2);
  add_action('wp_enqueue_scripts', 'tapfiliate');
}

?>
