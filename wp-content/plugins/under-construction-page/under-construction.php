<?php
/*
  Plugin Name: Under Construction
  Plugin URI: https://underconstructionpage.com/
  Description: Put your site behind a great looking under construction, coming soon, maintenance mode or landing page.
  Author: Web factory Ltd
  Version: 2.85
  Author URI: http://www.webfactoryltd.com/
  Text Domain: under-construction-page
  Domain Path: lang

  Copyright 2015 - 2018  Web factory Ltd  (email: ucp@webfactoryltd.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License, version 2, as
  published by the Free Software Foundation.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


// this is an include only WP file
if (!defined('ABSPATH')) {
  die;
}


define('UCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('UCP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('UCP_OPTIONS_KEY', 'ucp_options');
define('UCP_META_KEY', 'ucp_meta');
define('UCP_POINTERS_KEY', 'ucp_pointers');
define('UCP_NOTICES_KEY', 'ucp_notices');
define('UCP_SURVEYS_KEY', 'ucp_surveys');


require_once UCP_PLUGIN_DIR . 'ucp-license.php';


// main plugin class
class UCP {
  static $version = 0;
  static $licensing_servers = array('https://license1.underconstructionpage.com/', 'https://license2.underconstructionpage.com/');


  // get plugin version from header
  static function get_plugin_version() {
    $plugin_data = get_file_data(__FILE__, array('version' => 'Version'), 'plugin');
    self::$version = $plugin_data['version'];

    return $plugin_data['version'];
  } // get_plugin_version


  // hook things up
  static function init() {
    // check if minimal required WP version is present
    if (false === self::check_wp_version(4.0)) {
      return false;
    }

    if (is_admin()) {
      // if the plugin was updated from ver < 1.20 upgrade settings array
      self::maybe_upgrade();

      // add UCP menu to admin tools menu group
      add_action('admin_menu', array(__CLASS__, 'admin_menu'));

      // settings registration
      add_action('admin_init', array(__CLASS__, 'register_settings'));

      // aditional links in plugin description
      add_filter('plugin_action_links_' . plugin_basename(__FILE__),
                            array(__CLASS__, 'plugin_action_links'));
      add_filter('plugin_row_meta', array(__CLASS__, 'plugin_meta_links'), 10, 2);
      add_filter('admin_footer_text', array(__CLASS__, 'admin_footer_text'));

      // manages admin header notifications
      add_action('admin_notices', array(__CLASS__, 'admin_notices'));
      add_action('admin_action_ucp_dismiss_notice', array(__CLASS__, 'dismiss_notice'));
      add_action('admin_action_ucp_change_status', array(__CLASS__, 'change_status'));
      add_action('admin_action_ucp_reset_settings', array(__CLASS__, 'reset_settings'));

      // enqueue admin scripts
      add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_enqueue_scripts'));

      // AJAX endpoints
      add_action('wp_ajax_ucp_dismiss_pointer', array(__CLASS__, 'dismiss_pointer_ajax'));
      add_action('wp_ajax_ucp_dismiss_survey', array(__CLASS__, 'dismiss_survey_ajax'));
      add_action('wp_ajax_ucp_submit_survey', array(__CLASS__, 'submit_survey_ajax'));
      add_action('wp_ajax_ucp_submit_earlybird', array(__CLASS__, 'submit_earlybird_ajax'));
      add_action('wp_ajax_ucp_submit_support_message', array(__CLASS__, 'submit_support_message_ajax'));

      // uninstall survey on plugins page
      add_action('admin_footer-plugins.php', array(__CLASS__, 'footer_plugins'));
    } else {
      // main plugin logic
      add_action('wp', array(__CLASS__, 'display_construction_page'), 0, 1);

      // show under construction notice on login form
      add_filter('login_message', array(__CLASS__, 'login_message'));

      // disable feeds
      add_action('do_feed_rdf', array(__CLASS__, 'disable_feed'), 0, 1);
      add_action('do_feed_rss', array(__CLASS__, 'disable_feed'), 0, 1);
      add_action('do_feed_rss2', array(__CLASS__, 'disable_feed'), 0, 1);
      add_action('do_feed_atom', array(__CLASS__, 'disable_feed'), 0, 1);

      add_action('wp_footer', array(__CLASS__, 'whitelisted_notice'));
    } // if not admin

    // admin bar notice for frontend & backend
    add_action('wp_before_admin_bar_render', array(__CLASS__, 'admin_bar'));
    add_action('wp_head', array(__CLASS__, 'admin_bar_style'));
    add_action('admin_head', array(__CLASS__, 'admin_bar_style'));

    UCP_license::init();
  } // init


  // check if user has the minimal WP version required by UCP
  static function check_wp_version($min_version) {
    if (!version_compare(get_bloginfo('version'), $min_version,  '>=')) {
        add_action('admin_notices', array(__CLASS__, 'notice_min_wp_version'));
      return false;
    } else {
      return true;
    }
  } // check_wp_version


  // display error message if WP version is too low
  static function notice_min_wp_version() {
    echo '<div class="error"><p>' . sprintf(__('UnderConstruction plugin <b>requires WordPress version 4.0</b> or higher to function properly. You are using WordPress version %s. Please <a href="%s">update it</a>.', 'under-construction-page'), get_bloginfo('version'), admin_url('update-core.php')) . '</p></div>';
  } // notice_min_wp_version_error


  // some things have to be loaded earlier
  static function plugins_loaded() {
    self::get_plugin_version();

    load_plugin_textdomain('under-construction-page');
  } // plugins_loaded


  // activate doesn't get fired on upgrades so we have to compensate
  public static function maybe_upgrade() {
    $meta = self::get_meta();
    $options = self::get_options();

    // added in v1.70 to rename roles to whitelisted_roles
    if (isset($options['roles'])) {
      $options['whitelisted_roles'] = $options['roles'];
      unset($options['roles']);
      update_option(UCP_OPTIONS_KEY, $options);
    }

    // check if we need to convert options from the old format to new, or maybe it is already done
    if (isset($meta['options_ver']) && $meta['options_ver'] == self::$version) {
      return;
    }

    if (get_option('set_size') || get_option('set_tweet') || get_option('set_fb') || get_option('set_font') || get_option('set_msg') || get_option('set_opt') || get_option('set_admin')) {
      // convert old options to new
      $options['status'] = (get_option('set_opt') === 'Yes')? '1': '0';
      $options['content'] = trim(get_option('set_msg'));
      $options['whitelisted_roles'] = (get_option('set_admin') === 'No')? array('administrator'): array();
      $options['social_facebook'] = trim(get_option('set_fb'));
      $options['social_twitter'] = trim(get_option('set_tweet'));
      update_option(UCP_OPTIONS_KEY, $options);

      delete_option('set_size');
      delete_option('set_tweet');
      delete_option('set_fb');
      delete_option('set_font');
      delete_option('set_msg');
      delete_option('set_opt');
      delete_option('set_admin');

      self::reset_pointers();
    }

    // we update only once
    $meta['options_ver'] = self::$version;
    update_option(UCP_META_KEY, $meta);
  } // maybe_upgrade


  // get plugin's options
  static function get_options() {
    $options = get_option(UCP_OPTIONS_KEY, array());

    if (!is_array($options)) {
      $options = array();
    }
    $options = array_merge(self::default_options(), $options);

    return $options;
  } // get_options


  // get plugin's meta data
  static function get_meta() {
    $meta = get_option(UCP_META_KEY, array());

    if (!is_array($meta) || empty($meta)) {
      $meta['first_version'] = self::get_plugin_version();
      $meta['first_install'] = time();
      update_option(UCP_META_KEY, $meta);
    }

    return $meta;
  } // get_meta


  // fetch and display the construction page if it's enabled or preview requested
  static function display_construction_page() {
    $options = self::get_options();
    $request_uri = trailingslashit(strtolower(@parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));

    // just to be on the safe side
    if (defined('DOING_CRON') && DOING_CRON) {
      return false;
    }
    if (defined('DOING_AJAX') && DOING_AJAX) {
      return false;
    }
    if (defined('WP_CLI') && WP_CLI) {
      return false;
    }

    // some URLs have to be accessible at all times
    if ($request_uri == '/wp-admin/' ||
        $request_uri == '/feed/' ||
        $request_uri == '/feed/rss/' ||
        $request_uri == '/feed/rss2/' ||
        $request_uri == '/feed/rdf/' ||
        $request_uri == '/feed/atom/' ||
        $request_uri == '/admin/' ||
        $request_uri == '/wp-login.php') {
      return;
    }

    if (true == self::is_construction_mode_enabled(false)
        || (is_user_logged_in() && isset($_GET['ucp_preview']))) {
      header(self::wp_get_server_protocol() . ' 200 OK');
      if ($options['end_date'] && $options['end_date'] != '0000-00-00 00:00') {
        header('Retry-After: ' . date('D, d M Y H:i:s T', strtotime($options['end_date'])));
      } else {
        header('Retry-After: ' . DAY_IN_SECONDS);
      }
      echo self::get_template($options['theme']);
      exit;
    }
  } // display_construction_page


  // keeping compatibility with WP < v4.4
  static function wp_get_server_protocol() {
    $protocol = $_SERVER['SERVER_PROTOCOL'];
    if (!in_array($protocol, array('HTTP/1.1', 'HTTP/2', 'HTTP/2.0'))) {
        $protocol = 'HTTP/1.0';
    }

    return $protocol;
  } // wp_get_server_protocol


  // disables feed if necessary
  static function disable_feed() {
    if (true == self::is_construction_mode_enabled(false)) {
      echo '<?xml version="1.0" encoding="UTF-8" ?><status>Service unavailable.</status>';
      exit;
    }
  } // disable_feed


  // enqueue CSS and JS scripts in admin
  static function admin_enqueue_scripts($hook) {
    $surveys = get_option(UCP_SURVEYS_KEY);
    $meta = self::get_meta();
    $pointers = get_option(UCP_POINTERS_KEY);

    // auto remove welcome pointer when options are opened
    if (isset($pointers['welcome']) && 'settings_page_ucp' == $hook) {
      unset($pointers['welcome']);
      update_option(UCP_POINTERS_KEY, $pointers);
    }

    // survey is shown min 5min after install
    // no active surveys at the moment
    if (0 && empty($surveys['usage']) && time() - $meta['first_install'] > 300) {
      $open_survey = true;
    } else {
      $open_survey = false;
    }

    $js_localize = array('undocumented_error' => __('An undocumented error has occured. Please refresh the page and try again.', 'under-construction-page'),
                         'plugin_name' => __('UnderConstructionPage', 'under-construction-page'),
                         'settings_url' => admin_url('options-general.php?page=ucp'),
                         'whitelisted_users_placeholder' => __('Select whitelisted user(s)', 'under-construction-page'),
                         'open_survey' => $open_survey,
                         'is_activated' => UCP_license::is_activated(),
                         'dialog_upsell_title' => '<img alt="' . __('UnderConstructionPage PRO', 'under-construction-page') . '" title="' . __('UnderConstructionPage PRO', 'under-construction-page') . '" src="' . UCP_PLUGIN_URL . 'images/ucp_pro_logo_white.png' . '">',
                         'nonce_dismiss_survey' => wp_create_nonce('ucp_dismiss_survey'),
                         'nonce_submit_survey' => wp_create_nonce('ucp_submit_survey'),
                         'nonce_submit_earlybird' => wp_create_nonce('ucp_submit_earlybird'),
                         'nonce_submit_support_message' => wp_create_nonce('ucp_submit_support_message'),
                         'deactivate_confirmation' => __('Are you sure you want to deactivate UnderConstruction plugin?' . "\n" . 'If you are removing it because of a problem please contact our support. They will be more than happy to help.', 'under-construction-page'));

    if ('settings_page_ucp' == $hook) {
      wp_enqueue_style('wp-jquery-ui-dialog');
      wp_enqueue_style('ucp-select2', UCP_PLUGIN_URL . 'css/select2.min.css', array(), self::$version);
      wp_enqueue_style('ucp-admin', UCP_PLUGIN_URL . 'css/ucp-admin.css', array(), self::$version);

      wp_enqueue_script('jquery-ui-tabs');
      wp_enqueue_script('jquery-ui-dialog');
      wp_enqueue_script('ucp-jquery-plugins', UCP_PLUGIN_URL . 'js/ucp-jquery-plugins.js', array('jquery'), self::$version, true);
      wp_enqueue_script('ucp-select2', UCP_PLUGIN_URL . 'js/select2.min.js', array(), self::$version, true);
      wp_enqueue_script('ucp-admin', UCP_PLUGIN_URL . 'js/ucp-admin.js', array('jquery'), self::$version, true);
      wp_localize_script('ucp-admin', 'ucp', $js_localize);

      // fix for agressive plugins
      wp_dequeue_style('uiStyleSheet');
      wp_dequeue_style('wpcufpnAdmin' );
      wp_dequeue_style('unifStyleSheet' );
      wp_dequeue_style('wpcufpn_codemirror');
      wp_dequeue_style('wpcufpn_codemirrorTheme');
      wp_dequeue_style('collapse-admin-css');
      wp_dequeue_style('jquery-ui-css');
      wp_dequeue_style('tribe-common-admin');
      wp_dequeue_style('file-manager__jquery-ui-css');
      wp_dequeue_style('file-manager__jquery-ui-css-theme');
      wp_dequeue_style('wpmegmaps-jqueryui');
      wp_dequeue_style('wp-botwatch-css');
    }

    // disabled - regular deactivation is back
    if (false && 'plugins.php' == $hook) {
      wp_enqueue_style('wp-jquery-ui-dialog');
      wp_enqueue_style('ucp-admin-plugins', UCP_PLUGIN_URL . 'css/ucp-admin-plugins.css', array(), self::$version);

      wp_enqueue_script('jquery-ui-dialog');
      wp_enqueue_script('ucp-admin-plugins', UCP_PLUGIN_URL . 'js/ucp-admin-plugins.js', array('jquery'), self::$version, true);
      wp_localize_script('ucp-admin-plugins', 'ucp', $js_localize);
    }

    if ($pointers) {
      $pointers['_nonce_dismiss_pointer'] = wp_create_nonce('ucp_dismiss_pointer');
      wp_enqueue_script('wp-pointer');
      wp_enqueue_script('ucp-pointers', plugins_url('js/ucp-admin-pointers.js', __FILE__), array('jquery'), self::$version, true);
      wp_enqueue_style('wp-pointer');
      wp_localize_script('wp-pointer', 'ucp_pointers', $pointers);
      wp_localize_script('jquery', 'ucp', $js_localize);
    }
  } // admin_enqueue_scripts


  // permanently dismiss a pointer
  static function dismiss_pointer_ajax() {
    check_ajax_referer('ucp_dismiss_pointer');

    $pointers = get_option(UCP_POINTERS_KEY);
    $pointer = trim($_POST['pointer']);

    if (empty($pointers) || empty($pointers[$pointer])) {
      wp_send_json_error();
    }

    unset($pointers[$pointer]);
    update_option(UCP_POINTERS_KEY, $pointers);

    wp_send_json_success();
  } // dismiss_pointer_ajax


  // permanently dismiss a survey
  static function dismiss_survey_ajax() {
    check_ajax_referer('ucp_dismiss_survey');

    $surveys = get_option(UCP_SURVEYS_KEY, array());
    $survey = trim($_POST['survey']);

    $surveys[$survey] = -1;
    update_option(UCP_SURVEYS_KEY, $surveys);

    wp_send_json_success();
  } // dismiss_survey_ajax


  // send support message
  static function submit_support_message_ajax() {
    check_ajax_referer('ucp_submit_support_message');

    $options = self::get_options();

    $email = sanitize_text_field($_POST['support_email']);
    if (!is_email($email)) {
      wp_send_json_error(__('Please double-check your email address.', 'under-construction-page'));
    }

    $message = stripslashes(sanitize_text_field($_POST['support_message']));
    $subject = 'UCP Support';
    $body = $message;
    if (!empty($_POST['support_info'])) {
      $theme = wp_get_theme();
      $body .= "\r\n\r\nSite details:\r\n";
      $body .= '  WordPress version: ' . get_bloginfo('version') . "\r\n";
      $body .= '  UCP version: ' . self::$version . "\r\n";
      $body .= '  PHP version: ' . PHP_VERSION . "\r\n";
      $body .= '  Site URL: ' . get_bloginfo('url') . "\r\n";
      $body .= '  WordPress URL: ' . get_bloginfo('wpurl') . "\r\n";
      $body .= '  Theme: ' . $theme->get('Name') . ' v' . $theme->get('Version') . "\r\n";
      $body .= '  Options: ' . "\r\n" . serialize($options) . "\r\n";
    }
    $headers = 'From: ' . $email . "\r\n" . 'Reply-To: ' . $email;

    if (true === wp_mail('ucp@webfactoryltd.com', $subject, $body, $headers)) {
      wp_send_json_success();
    } else {
      wp_send_json_error(__('Something is not right with your wp_mail() function. Please email as at ucp@webfactoryltd.com.', 'under-construction-page'));
    }
  } // submit_support_message


  // submit survey
  static function submit_survey_ajax() {
    check_ajax_referer('ucp_submit_survey');

    $options = self::get_options();
    $meta = self::get_meta();
    $surveys = get_option(UCP_SURVEYS_KEY);

    $vars = wp_parse_args($_POST, array('survey' => '', 'answers' => '', 'custom_answer' => $options['theme'], 'emailme' => ''));
    $vars['answers'] = trim($vars['answers'], ',');
    $vars['custom_answer'] = trim(strip_tags($vars['custom_answer']));

    $vars['custom_answer'] .= '; ' . date('Y-m-d H:i:s', $meta['first_install']);
    $vars['custom_answer'] = trim($vars['custom_answer'], ' ;');

    if (empty($vars['survey']) || empty($vars['answers'])) {
      wp_send_json_error();
    }

    $request_params = array('sslverify' => false, 'timeout' => 15, 'redirection' => 2);
    $request_args = array('action' => 'submit_survey',
                          'survey' => $vars['survey'],
                          'email' => $vars['emailme'],
                          'answers' => $vars['answers'],
                          'custom_answer' => $vars['custom_answer'],
                          'first_version' => $meta['first_version'],
                          'version' => UCP::$version,
                          'codebase' => 'free',
                          'site' => get_home_url());

    $url = add_query_arg($request_args, self::$licensing_servers[0]);
    $response = wp_remote_get(esc_url_raw($url), $request_params);

    if (is_wp_error($response) || !wp_remote_retrieve_body($response)) {
      $url = add_query_arg($request_args, self::$licensing_servers[1]);
      $response = wp_remote_get(esc_url_raw($url), $request_params);
    }

    $surveys[$vars['survey']] = time();
    update_option(UCP_SURVEYS_KEY, $surveys);

    wp_send_json_success();
  } // submit_survey_ajax


  // submit earlybird email
  static function submit_earlybird_ajax() {
    check_ajax_referer('ucp_submit_earlybird');

    $options = self::get_options();
    $meta = self::get_meta();

    $vars = wp_parse_args($_POST, array('type' => '', 'email' => ''));

    if (empty($vars['email']) || empty($vars['type'])) {
      wp_send_json_error(__('Please tell us your email and how you use UCP.', 'under-construction-page'));
    }

    $request_params = array('sslverify' => false, 'timeout' => 15, 'redirection' => 2);
    $request_args = array('action' => 'submit_survey',
                          'survey' => 'earlybird',
                          'email' => $vars['email'],
                          'answers' => $vars['type'],
                          'custom_answer' => $options['theme'] . '; ' . date('Y-m-d H:i:s', $meta['first_install']),
                          'first_version' => $meta['first_version'],
                          'version' => UCP::$version,
                          'codebase' => 'free',
                          'site' => get_home_url());

    $url = add_query_arg($request_args, self::$licensing_servers[0]);
    $response = wp_remote_get(esc_url_raw($url), $request_params);

    if (is_wp_error($response) || !wp_remote_retrieve_body($response)) {
      $url = add_query_arg($request_args, self::$licensing_servers[1]);
      $response = wp_remote_get(esc_url_raw($url), $request_params);
    }

    wp_send_json_success();
  } // submit_earlybird_ajax


  // encode email for frontend use
  static function encode_email($email) {
    $len = strlen($email);
    $out = '';

    for ($i = 0; $i < $len; $i++) {
      $out .= '&#'. ord($email[$i]) . ';';
    }

    return $out;
  } // encode_email


  // parse shortcode alike variables
  static function parse_vars($string) {
    $org_string = $string;

    $vars = array('site-title' => get_bloginfo('name'),
                  'site-tagline' => get_bloginfo('description'),
                  'site-description' => get_bloginfo('description'),
                  'site-url' => trailingslashit(get_home_url()),
                  'wp-url' => trailingslashit(get_site_url()),
                  'site-login-url' => get_site_url() . '/wp-login.php');

    foreach ($vars as $var_name => $var_value) {
      $var_name = '[' . $var_name . ']';
      $string = str_ireplace($var_name, $var_value, $string);
    }

    $string = apply_filters('ucp_parse_vars', $string, $org_string, $vars);

    return $string;
  } // parse_vars


  // generate HTML from social icons
  static function generate_social_icons($options, $template_id) {
    $out = '';

    if (!empty($options['social_facebook'])) {
      $out .= '<a title="Facebook" href="' . $options['social_facebook'] . '" target="_blank"><i class="fa fa-facebook-square fa-3x"></i></a>';
    }
    if (!empty($options['social_twitter'])) {
      $out .= '<a title="Twitter" href="' . $options['social_twitter'] . '" target="_blank"><i class="fa fa-twitter-square fa-3x"></i></a>';
    }
    if (!empty($options['social_google'])) {
      $out .= '<a title="Google+" href="' . $options['social_google'] . '" target="_blank"><i class="fa fa-google-plus-square fa-3x"></i></a>';
    }
    if (!empty($options['social_linkedin'])) {
      $out .= '<a title="LinkedIn" href="' . $options['social_linkedin'] . '" target="_blank"><i class="fa fa-linkedin-square fa-3x"></i></a>';
    }
    if (!empty($options['social_youtube'])) {
      $out .= '<a title="YouTube" href="' . $options['social_youtube'] . '" target="_blank"><i class="fa fa-youtube-square fa-3x"></i></a>';
    }
    if (!empty($options['social_vimeo'])) {
      $out .= '<a title="Vimeo" href="' . $options['social_vimeo'] . '" target="_blank"><i class="fa fa-vimeo-square fa-3x"></i></a>';
    }
    if (!empty($options['social_pinterest'])) {
      $out .= '<a title="Pinterest" href="' . $options['social_pinterest'] . '" target="_blank"><i class="fa fa-pinterest-square fa-3x"></i></a>';
    }
    if (!empty($options['social_dribbble'])) {
      $out .= '<a title="Dribbble" href="' . $options['social_dribbble'] . '" target="_blank"><i class="fa fa-dribbble fa-3x"></i></a>';
    }
    if (!empty($options['social_behance'])) {
      $out .= '<a title="Behance" href="' . $options['social_behance'] . '" target="_blank"><i class="fa fa-behance-square fa-3x"></i></a>';
    }
    if (!empty($options['social_instagram'])) {
      $out .= '<a title="Instagram" href="' . $options['social_instagram'] . '" target="_blank"><i class="fa fa-instagram fa-3x"></i></a>';
    }
    if (!empty($options['social_tumblr'])) {
      $out .= '<a title="Tumblr" href="' . $options['social_tumblr'] . '" target="_blank"><i class="fa fa-tumblr-square fa-3x"></i></a>';
    }
    if (!empty($options['social_skype'])) {
      $out .= '<a title="Skype" href="skype:' . $options['social_skype'] . '?chat"><i class="fa fa-skype fa-3x"></i></a>';
    }
    if (!empty($options['social_whatsapp'])) {
      $out .= '<a title="WhatsApp" href="whatsapp:' . $options['social_whatsapp'] . '"><i class="fa fa-whatsapp fa-3x"></i></a>';
    }
    if (!empty($options['social_telegram'])) {
      $out .= '<a title="Telegram" href="' . $options['social_telegram'] . '"><i class="fa fa-telegram fa-3x"></i></a>';
    }
    if (!empty($options['social_email'])) {
      $out .= '<a title="Email" href="mailto:' . self::encode_email($options['social_email']) . '"><i class="fa fa-envelope fa-3x"></i></a>';
    }
    if (!empty($options['social_phone'])) {
      $out .= '<a title="Phone" href="tel:' . $options['social_phone'] . '"><i class="fa fa-phone-square fa-3x"></i></a>';
    }

    return $out;
  } // generate_social_icons


  // shortcode for inserting things in header
  static function generate_head($options, $template_id) {
    $out = '';

    $out .= '<link rel="stylesheet" href="' . trailingslashit(UCP_PLUGIN_URL . 'themes/css') . 'bootstrap.min.css?v=' . self::$version . '" type="text/css">' . "\n";
    $out .= '<link rel="stylesheet" href="' . trailingslashit(UCP_PLUGIN_URL . 'themes/css') . 'common.css?v=' . self::$version . '" type="text/css">' . "\n";
    $out .= '<link rel="stylesheet" href="' . trailingslashit(UCP_PLUGIN_URL . 'themes/' . $template_id) . 'style.css?v=' . self::$version . '" type="text/css">' . "\n";
    $out .= '<link rel="stylesheet" href="' . trailingslashit(UCP_PLUGIN_URL . 'themes/css') . 'font-awesome.min.css?v=' . self::$version . '" type="text/css">' . "\n";

    $out .= '<link rel="shortcut icon" type="image/png" href="' . trailingslashit(UCP_PLUGIN_URL . 'themes/images') . 'favicon.png" />';

    if (!empty($options['ga_tracking_id'])) {
      $out .= "
      <script>
        (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
        (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
        m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
        })(window,document,'script','https://www.google-analytics.com/analytics.js','ga');
        ga('create', '{$options['ga_tracking_id']}', 'auto');
        ga('send', 'pageview');
      </script>";
    }

    if (!empty($options['custom_css'])) {
      $out .= "\n" . '<style type="text/css">' . $options['custom_css'] . '</style>';
    }

    $out = apply_filters('ucp_head', $out, $options, $template_id);

    return trim($out);
  } // generate_head


  // shortcode for inserting things in footer
  static function generate_footer($options, $template_id) {
    $out = '';

    // todo translate
    if ($options['linkback'] == '1') {
      $tmp = md5(get_site_url());
      if ($tmp[0] < '4') {
        $out .= '<p id="linkback">Create stunning <a href="' . self::generate_web_link('show-love-1')  . '" target="_blank">under construction pages for WordPress</a>. Completely free.</p>';
      } elseif ($tmp[0] < '8') {
        $out .= '<p id="linkback">Create a <a href="' . self::generate_web_link('show-love-2')  . '" target="_blank">free under construction page for WordPress</a> like this one in under a minute.</p>';
      } elseif ($tmp[0] < 'c') {
        $out .= '<p id="linkback">Join more than 100,000 happy people using the <a href="https://wordpress.org/plugins/under-construction-page/" target="_blank">free Under Construction Page plugin for WordPress</a>.</p>';
      } else {
        $out .= '<p id="linkback">Create free <a href="' . self::generate_web_link('show-love-3')  . '" target="_blank">maintenance mode pages for WordPress</a>.</p>';
      }
    }

    if ($options['login_button'] == '1') {
      if (is_user_logged_in()) {
        $out .= '<div id="login-button" class="loggedin">';
        $out .= '<a title="' . __('Open WordPress admin', 'under-construction-page') . '" href="' . get_site_url() . '/wp-admin/"><i class="fa fa-wordpress fa-2x" aria-hidden="true"></i></a>';
      } else {
        $out .= '<div id="login-button" class="loggedout">';
        $out .= '<a title="' . __('Log in to WordPress admin', 'under-construction-page') . '" href="' . get_site_url() . '/wp-login.php"><i class="fa fa-wordpress fa-2x" aria-hidden="true"></i></a>';
      }
      $out .= '</div>';
    }

    $out = apply_filters('ucp_footer', $out, $options, $template_id);

    return $out;
  } // generate_footer


  // returnes parsed template
  static function get_template($template_id) {
    $vars = array();
    $options = self::get_options();

    $vars['version'] = self::$version;
    $vars['site-url'] = trailingslashit(get_home_url());
    $vars['wp-url'] = trailingslashit(get_site_url());
    $vars['theme-url'] = trailingslashit(UCP_PLUGIN_URL . 'themes/' . $template_id);
    $vars['theme-url-common'] = trailingslashit(UCP_PLUGIN_URL . 'themes');
    $vars['title'] = self::parse_vars($options['title']);
    $vars['generator'] = __('Free UnderConstructionPage plugin for WordPress', 'under-construction-page');
    $vars['heading1'] = self::parse_vars($options['heading1']);
    $vars['content'] = nl2br(self::parse_vars($options['content']));
    $vars['description'] = self::parse_vars($options['description']);
    $vars['social-icons'] = self::generate_social_icons($options, $template_id);
    $vars['head'] = self::generate_head($options, $template_id);
    $vars['footer'] = self::generate_footer($options, $template_id);

    $vars = apply_filters('ucp_get_template_vars', $vars, $template_id, $options);

    ob_start();
    require UCP_PLUGIN_DIR . 'themes/' . $template_id . '/index.php';
    $template = ob_get_clean();

    foreach ($vars as $var_name => $var_value) {
      $var_name = '[' . $var_name . ']';
      $template = str_ireplace($var_name, $var_value, $template);
    }

    $template = apply_filters('ucp_get_template', $template, $vars, $options);

    return $template;
  } // get_template


  // checks if construction mode is enabled for the current visitor
  static function is_construction_mode_enabled($settings_only = false) {
    $options = self::get_options();
    $current_user = wp_get_current_user();

    $override_status = apply_filters('ucp_is_construction_mode_enabled', null, $options);
    if (is_bool($override_status)) {
      return $override_status;
    }

    // just check if it's generally enabled
    if ($settings_only) {
      if ($options['status']) {
        return true;
      } else {
        return false;
      }
    } else {
      // check if enabled for current user
      if (!$options['status']) {
        return false;
      } elseif (self::user_has_role($options['whitelisted_roles'])) {
        return false;
      } elseif (in_array($current_user->ID, $options['whitelisted_users'])) {
        return false;
      } elseif (strlen($options['end_date']) === 16 && $options['end_date'] !== '0000-00-00 00:00' && $options['end_date'] < current_time('mysql')) {
        return false;
      } else {
        return true;
      }
    }
  } // is_construction_mode_enabled


  // check if user has the specified role
  static function user_has_role($roles) {
    $current_user = wp_get_current_user();

    if ($current_user->roles) {
      $user_role = $current_user->roles[0];
    } else {
      $user_role = 'guest';
    }

    return in_array($user_role, $roles);
  } // user_has_role


  // frontend notification when UCP is enabled but current user is whitelisted
  static function whitelisted_notice() {
    $notices = get_option(UCP_NOTICES_KEY);
    $dismiss_url = add_query_arg(array('action' => 'ucp_dismiss_notice', 'notice' => 'whitelisted', 'redirect' => urlencode($_SERVER['REQUEST_URI'])), admin_url('admin.php'));

    if (empty($notices['dismiss_whitelisted']) &&
        is_user_logged_in() &&
        self::is_construction_mode_enabled(true) &&
        !self::is_construction_mode_enabled(false))
        // keeping everything inline due to minimal CSS
        echo '<div style="background-color: #333; line-height: 140%; font-size: 14px; position: fixed; display: block; top: 50px; z-index: 99999; color: #fefefe; padding: 20px 35px 20px 20px; width: 500px; border: thin solid #fefefe; left: -1px;"><a style="color: #ea1919; font-weight: 900; text-decoration: none; position: absolute; top: 7px; right: 10px;" href="' . $dismiss_url . '" alt="Dismiss notice" onclick="window.location.href = \'' . $dismiss_url . '\'; return false;" title="Dismiss notice">X</a><b>' . __('<b>Under Construction Mode is enabled</b> but you are whitelisted so you see the normal site.', 'under-construction-page') . '<br><a href="' . get_home_url() . '/?ucp_preview" style="text-decoration: underline; color: #fefefe;">' . __('Preview UnderConstructionPage', 'under-construction-page') . '</a><br><a href="' . admin_url('options-general.php?page=ucp') . '" style="text-decoration: underline; color: #fefefe;">' . __('Configure UnderConstructionPage', 'under-construction-page') . '</a></div>';
  } // whitelisted_notification


  // displays various notices in admin header
  static function admin_notices() {
    $notices = get_option(UCP_NOTICES_KEY);
    $options = self::get_options();
    $meta = self::get_meta();
    $current_user = wp_get_current_user();

    $name = '';
    if (!empty($current_user->user_firstname)) {
      $name = ' ' . $current_user->user_firstname;
    }

    // pro activated - update
    if (self::is_plugin_page() && UCP_license::is_activated()) {
      $plugin = plugin_basename(__FILE__);
      $update_url = wp_nonce_url(admin_url('update.php?action=upgrade-plugin&amp;plugin=' . urlencode($plugin)), 'upgrade-plugin_' . $plugin);
      echo '<div id="ucp_update_pro" class="notice-error notice">';
      echo '<p class="center">Thank you for purchasing UnderConstructionPage PRO! <b>Your license has been verified and activated.</b></p><p>Please <b>click the button below</b> to update plugin files to PRO version.</p>';
      echo '<p><a href="' . esc_url($update_url) . '" class="button button-primary">Update UnderConstructionPage files to PRO</a></p>';
      echo '</div>';

      return;
    }

    // todo translate
    // ask for rating - disabled
    if (false && empty($notices['dismiss_rate']) &&
        (time() - $meta['first_install']) > (DAY_IN_SECONDS * 1.0)) {
      $rate_url = 'https://wordpress.org/support/plugin/under-construction-page/reviews/?filter=5&rate=5#new-post';
      $dismiss_url = add_query_arg(array('action' => 'ucp_dismiss_notice', 'notice' => 'rate', 'redirect' => urlencode($_SERVER['REQUEST_URI'])), admin_url('admin.php'));

      echo '<div id="ucp_rate_notice" class="notice-info notice"><p>Hi' . $name . '!<br>We saw you\'ve been using the <b class="ucp-logo" style="font-weight: bold;">UnderConstructionPage</b> plugin for a few days (that\'s awesome!) and wanted to ask for your help to <b>make the plugin better</b>.<br>We just need a minute of your time to rate the plugin. It helps us out a lot!';

      echo '<br><a target="_blank" href="' . esc_url($rate_url) . '" style="vertical-align: baseline; margin-top: 15px;" class="button-primary">' . __('Help make the plugin better by rating it', 'under-construction-page') . '</a>';
      echo '&nbsp;&nbsp;&nbsp;&nbsp;<a href="' . esc_url($dismiss_url) . '">' . __('I\'ve already rated the plugin', 'under-construction-page') . '</a>';
      echo '<br><br><b>' . __('Thank you very much! The UCP team', 'under-construction-page') . '</b>';
      echo '</p></div>';
    }

    // end date in past
    if (self::is_plugin_page() && self::is_construction_mode_enabled(true) && !empty($options['end_date']) && $options['end_date'] != '0000-00-00 00:00' && $options['end_date'] < current_time('mysql')) {
      echo '<div id="ucp_end_date_notice" class="notice-error notice"><p>Under construction mode is enabled but the <a href="#end_date" class="change_tab" data-tab="0">end date</a> is set to a past date so the <b>under construction page will not be shown</b>. Either move the <a href="#end_date" class="change_tab" data-tab="0">end date</a> to a future date or disable it.</p></div>';
    }

    // ask for translation
    if (self::is_plugin_page() &&
        empty($notices['dismiss_translate']) &&
        (time() - $meta['first_install']) > (DAY_IN_SECONDS * 1)) {
      $translate_url = self::generate_web_link('translate-notification', 'translate-the-plugin/');
      $dismiss_url = add_query_arg(array('action' => 'ucp_dismiss_notice', 'notice' => 'translate', 'redirect' => urlencode($_SERVER['REQUEST_URI'])), admin_url('admin.php'));

      echo '<div id="ucp_rate_notice" class="notice-info notice"><p>Hi' . $name . ',<br>Help us translate UCP into your language and <b>get a PRO license for free</b>!<br>We want to make <b class="ucp-logo" style="font-weight: bold;">UnderConstructionPage</b> accessible to as many users as possible by translating it into their language. And we need your help!';

      echo '<br><a target="_blank" href="' . esc_url($translate_url) . '" style="vertical-align: baseline; margin-top: 15px;" class="button-primary">' . __('Translate UCP into your language &amp; get a PRO license for free', 'under-construction-page') . '</a>';
      echo '&nbsp;&nbsp;&nbsp;&nbsp;<a href="' . esc_url($dismiss_url) . '">' . __('I\'m not interested (remove this notice)', 'under-construction-page') . '</a>';
      echo '</p></div>';
    }
  } // notices


  // handle dismiss button for notices
  static function dismiss_notice() {
    if (empty($_GET['notice'])) {
      wp_redirect(admin_url());
      exit;
    }

    $notices = get_option(UCP_NOTICES_KEY, array());

    if ($_GET['notice'] == 'rate') {
      $notices['dismiss_rate'] = true;
    }

    if ($_GET['notice'] == 'translate') {
      $notices['dismiss_translate'] = true;
    }

    if ($_GET['notice'] == 'whitelisted') {
      $notices['dismiss_whitelisted'] = true;
    }

    update_option(UCP_NOTICES_KEY, $notices);

    if (!empty($_GET['redirect'])) {
      wp_redirect($_GET['redirect']);
    } else {
      wp_redirect(admin_url());
    }

    exit;
  } // dismiss_notice


  // reset all settings to default values
  static function reset_settings() {
    $options = self::default_options();
    update_option(UCP_OPTIONS_KEY, $options);

    if (!empty($_GET['redirect'])) {
      wp_redirect($_GET['redirect']);
    } else {
      wp_redirect(admin_url());
    }

    exit;
  } // reset_settings


  // change status via admin bar
  static function change_status() {
    if (empty($_GET['new_status'])) {
      wp_redirect(admin_url());
      exit;
    }

    $options = self::get_options();

    if ($_GET['new_status'] == 'enabled') {
      $options['status'] = '1';
    } else {
      $options['status'] = '0';
    }

    update_option(UCP_OPTIONS_KEY, $options);

    if (!empty($_GET['redirect'])) {
      wp_redirect($_GET['redirect']);
    } else {
      wp_redirect(admin_url());
    }

    exit;
  } // change_status


  static function admin_bar_style() {
    // admin bar has to be anabled, user an admin and custom filter true
    if (false === is_admin_bar_showing() || false === current_user_can('administrator') || false === apply_filters('ucp_show_admin_bar', true)) {
      return;
    }

    // no sense in loading a new CSS file for 2 lines of CSS
    $custom_css = '<style type="text/css">#wpadminbar ul li#wp-admin-bar-ucp-info { padding: 5px 0; } #wpadminbar ul li#wp-admin-bar-ucp-settings, #wpadminbar ul li#wp-admin-bar-ucp-status { padding-bottom: 2px; } #wpadminbar i.ucp-status-dot { font-size: 17px; margin-top: -7px; color: #02ca02; height: 17px; display: inline-block; } #wpadminbar i.ucp-status-dot-enabled { color: #87c826; } #wpadminbar i.ucp-status-dot-disabled { color: #ea1919; } #wpadminbar #ucp-status-wrapper { display: inline; border: 1px solid rgba(240,245,250,.7); padding: 0; margin: 0 0 0 5px; background: rgb(35, 40, 45); } #wpadminbar .ucp-status-btn { padding: 0 7px; color: #fff; } #wpadminbar #ucp-status-wrapper.off #ucp-status-off { background: #ea1919;} #wpadminbar #ucp-status-wrapper.on #ucp-status-on { background: #66b317; }#wp-admin-bar-under-construction-page img.logo { height: 17px; margin-bottom: 4px; padding-right: 3px; } body.wp-admin #wp-admin-bar-under-construction-page img.logo { margin-bottom: -4px; }</style>';

    echo $custom_css;
  } // admin_bar_style


  // add admin bar menu and status
  static function admin_bar() {
    global $wp_admin_bar;

    // only show to admins
    if (false === current_user_can('administrator') || false === apply_filters('ucp_show_admin_bar', true)) {
      return;
    }

    if (self::is_construction_mode_enabled(true)) {
      $main_label = '<img style="height: 17px; margin-bottom: -4px; padding-right: 3px;" src="' . UCP_PLUGIN_URL . '/images/ucp_icon.png" alt="' . __('Under construction mode is enabled', 'under-construction-page') . '" title="' . __('Under construction mode is enabled', 'under-construction-page') . '"> <span class="ab-label">' . __('UnderConstruction', 'under-construction-page') . ' <i class="ucp-status-dot ucp-status-dot-enabled">&#9679;</i></span>';
      $class = 'ucp-enabled';
      $action_url = add_query_arg(array('action' => 'ucp_change_status', 'new_status' => 'disabled', 'redirect' => urlencode($_SERVER['REQUEST_URI'])), admin_url('admin.php'));
      $action = __('Under Construction Mode', 'under-construction-page');
      $action .= '<a href="' . $action_url . '" id="ucp-status-wrapper" class="on"><span id="ucp-status-off" class="ucp-status-btn">OFF</span><span id="ucp-status-on" class="ucp-status-btn">ON</span></a>';
    } else {
      $main_label = '<img style="height: 17px; margin-bottom: -4px; padding-right: 3px;" src="' . UCP_PLUGIN_URL . '/images/ucp_icon.png" alt="' . __('Under construction mode is disabled', 'under-construction-page') . '" title="' . __('Under construction mode is disabled', 'under-construction-page') . '"> <span class="ab-label">' . __('UnderConstruction', 'under-construction-page') . ' <i class="ucp-status-dot ucp-status-dot-disabled">&#9679;</i></span>';
      $class = 'ucp-disabled';
      $action_url = add_query_arg(array('action' => 'ucp_change_status', 'new_status' => 'enabled', 'redirect' => urlencode($_SERVER['REQUEST_URI'])), admin_url('admin.php'));
      $action = __('Under Construction Mode', 'under-construction-page');
      $action .= '<a href="' . $action_url . '" id="ucp-status-wrapper" class="off"><span id="ucp-status-off" class="ucp-status-btn">OFF</span><span id="ucp-status-on" class="ucp-status-btn">ON</span></a>';
    }

    $wp_admin_bar->add_menu(array(
      'parent' => '',
      'id'     => 'under-construction-page',
      'title'  => $main_label,
      'href'   => admin_url('options-general.php?page=ucp'),
      'meta'   => array('class' => $class)
    ));
    $wp_admin_bar->add_node( array(
      'id'    => 'ucp-status',
      'title' => $action,
      'href'  => false,
      'parent'=> 'under-construction-page'
    ));
    $wp_admin_bar->add_node( array(
      'id'     => 'ucp-preview',
      'title'  => __('Preview', 'under-construction-page'),
      'meta'   => array('target' => 'blank'),
      'href'   => get_home_url() . '/?ucp_preview',
      'parent' => 'under-construction-page'
    ));
    $wp_admin_bar->add_node( array(
      'id'     => 'ucp-settings',
      'title'  => __('Settings', 'under-construction-page'),
      'href'   => admin_url('options-general.php?page=ucp'),
      'parent' => 'under-construction-page'
    ));
  } // admin_bar


  // show under construction notice on WP login form
  static function login_message($message) {
    if (self::is_construction_mode_enabled(true)) {
      $message .= '<div class="message">' . __('Under Construction Mode is <b>enabled</b>.', 'under-construction-page') . '</div>';
    }

    return $message;
  } // login_notice


  // add settings link to plugins page
  static function plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=ucp') . '" title="' . __('UnderConstruction Settings', 'under-construction-page') . '">' . __('Settings', 'under-construction-page') . '</a>';
    $pro_link = '<a target="_blank" href="' . self::generate_web_link('plugins-table-left') . '" title="' . __('Get PRO', 'under-construction-page') . '">' . __('Go <b>PRO</b>', 'under-construction-page') . '</a>';

    array_unshift($links, $pro_link);
    array_unshift($links, $settings_link);

    if (!empty($links['deactivate'])) {
      $links['deactivate'] = str_replace('href=',' data-under-construction-page="true" href=', $links['deactivate']);
    }

    return $links;
  } // plugin_action_links


  // add links to plugin's description in plugins table
  static function plugin_meta_links($links, $file) {
    $support_link = '<a target="_blank" href="https://wordpress.org/support/plugin/under-construction-page" title="' . __('Get help', 'under-construction-page') . '">' . __('Support', 'under-construction-page') . '</a>';
    $pro_link = '<a target="_blank" href="' . self::generate_web_link('plugins-table-right') . '" title="' . __('Get PRO', 'under-construction-page') . '">' . __('Get the <b>PRO</b> version', 'under-construction-page') . '</a>';


    if ($file == plugin_basename(__FILE__)) {
      $links[] = $support_link;
      $links[] = $pro_link;
    }

    return $links;
  } // plugin_meta_links


  // additional powered by text in admin footer; only on UCP page
  static function admin_footer_text($text) {
    if (!self::is_plugin_page()) {
      return $text;
    }

    $text = '<i><a href="' . self::generate_web_link('admin-footer') . '" title="' . __('Visit UCP\'s site for more info', 'under-construction-page') . '" target="_blank">' . __('UnderConstructionPage', 'under-construction-page') . '</a> v' . self::$version . ' by <a href="https://www.webfactoryltd.com/" title="' . __('Visit our site to get more great plugins', 'under-construction-page') . '" target="_blank">' . __('WebFactory Ltd', 'under-construction-page') . '</a>.</i> '. $text;

    return $text;
  } // admin_footer_text


  // test if we're on plugin's page
  static function is_plugin_page() {
    $current_screen = get_current_screen();

    if ($current_screen->id == 'settings_page_ucp') {
      return true;
    } else {
      return false;
    }
  } // is_plugin_page


  // create the admin menu item
  static function admin_menu() {
    add_options_page(__('UnderConstruction', 'under-construction-page'), __('UnderConstruction', 'under-construction-page'), 'manage_options', 'ucp', array(__CLASS__, 'main_page'));
  } // admin_menu


  // all settings are saved in one option
  static function register_settings() {
    register_setting(UCP_OPTIONS_KEY, UCP_OPTIONS_KEY, array(__CLASS__, 'sanitize_settings'));
  } // register_settings


  // set default settings
  static function default_options() {
    $defaults = array('status' => '0',
                      'license_key' => '',
                      'license_active' => false,
                      'license_expires' => '1900-01-01',
                      'license_type' => '',
                      'end_date' => '',
                      'ga_tracking_id' => '',
                      'theme' => 'mad_designer',
                      'custom_css' => '',
                      'title' => '[site-title] is under construction',
                      'description' => '[site-tagline]',
                      'heading1' => __('Sorry, we\'re doing some work on the site', 'under-construction-page'),
                      'content' => __('Thank you for being patient. We are doing some work on the site and will be back shortly.', 'under-construction-page'),
                      'social_facebook' => '',
                      'social_twitter' => '',
                      'social_google' => '',
                      'social_linkedin' => '',
                      'social_youtube' => '',
                      'social_vimeo' => '',
                      'social_pinterest' => '',
                      'social_dribbble' => '',
                      'social_behance' => '',
                      'social_instagram' => '',
                      'social_tumblr' => '',
                      'social_email' => '',
                      'social_phone' => '',
                      'social_skype' => '',
                      'social_telegram' => '',
                      'social_whatsapp' => '',
                      'login_button' => '1',
                      'linkback' => '0',
                      'whitelisted_roles' => array('administrator'),
                      'whitelisted_users' => array()
                      );

    return $defaults;
  } // default_options


  // sanitize settings on save
  static function sanitize_settings($options) {
    $old_options = self::get_options();

    foreach ($options as $key => $value) {
      switch ($key) {
        case 'title':
        case 'description':
        case 'heading1':
        case 'content':
        case 'custom_css':
        case 'social_facebook':
        case 'social_twitter':
        case 'social_google':
        case 'social_linkedin':
        case 'social_youtube':
        case 'social_vimeo':
        case 'social_pinterest':
        case 'social_dribbble':
        case 'social_behance':
        case 'social_instagram':
        case 'social_tumblr':
        case 'social_email':
        case 'social_phone':
        case 'social_telegram':
        case 'social_whatsapp':
        case 'license_key':
          $options[$key] = trim($value);
        break;
        case 'ga_tracking_id':
          $options[$key] = substr(strtoupper(trim($value)), 0, 15);
        break;
        case 'end_date':
          $options[$key] = substr(trim($value), 0, 16);
        break;
      } // switch
    } // foreach

    $options['whitelisted_roles'] = empty($options['whitelisted_roles'])? array(): $options['whitelisted_roles'];
    $options['whitelisted_users'] = empty($options['whitelisted_users'])? array(): $options['whitelisted_users'];
    $options = self::check_var_isset($options, array('status' => 0, 'linkback' => 0, 'login_button' => 0));

    if (empty($options['end_date_toggle'])) {
      $options['end_date'] = '';
    }
    if ($options['end_date'] == '0000-00-00 00:00') {
      $options['end_date'] = '';
    }
    unset($options['end_date_toggle']);

    if (empty($options['ga_tracking_toggle'])) {
      $options['ga_tracking_id'] = '';
    }
    if (!empty($options['ga_tracking_id']) && preg_match('/^UA-\d{3,}-\d{1,3}$/', $options['ga_tracking_id']) === 0) {
      add_settings_error('ucp', 'ga_tracking_id', __('Please enter a valid Google Analytics Tracking ID or disable tracking.', 'under-construction-page'));
    }
    unset($options['ga_tracking_toggle']);

    if (!empty($_POST['license-submit'])) {
      if (empty($options['license_key'])) {
        $options['license_type'] = '';
        $options['license_expires'] = '1900-01-01';
        $options['license_active'] = false;
        $options['license_key'] = '';
        add_settings_error(UCP_OPTIONS_KEY, 'license_key', __('License key saved.', 'under-construction-page'), 'updated');
      } else {
        $tmp = UCP_license::validate_license_key($options['license_key']);
        if ($tmp['success']) {
          $options['license_type'] = $tmp['license_type'];
          $options['license_expires'] = $tmp['license_expires'];
          $options['license_active'] = $tmp['license_active'];
          if ($tmp['license_active']) {
            add_settings_error(UCP_OPTIONS_KEY, 'license_key', __('License key saved and activated!', 'under-construction-page'), 'updated');
          } else {
            add_settings_error(UCP_OPTIONS_KEY, 'license_key', 'License not active. ' . $tmp['error'], 'error');
          }
        } else {
          add_settings_error(UCP_OPTIONS_KEY, 'license_key', 'Unable to contact licensing server. Please try again in a few moments.', 'error');
        }
      }
    } // update license

    // empty cache in 3rd party plugins
    if ($options != $old_options) {
      $notices = get_option(UCP_NOTICES_KEY);
      unset($notices['dismiss_whitelisted']);
      update_option(UCP_NOTICES_KEY, $notices);

      if (function_exists('w3tc_pgcache_flush')) {
        w3tc_pgcache_flush();
      }
      if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
      }
      if (class_exists('Endurance_Page_Cache')) {
        $epc = new Endurance_Page_Cache;
        $epc->purge_all();
      }
      if (class_exists('SG_CachePress_Supercacher') && method_exists('SG_CachePress_Supercacher', 'purge_cache')) {
        SG_CachePress_Supercacher::purge_cache(true);
      }
      if (isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) {
        $GLOBALS['wp_fastest_cache']->deleteCache(true);
      }
    }

    return array_merge($old_options, $options);
  } // sanitize_settings


  // checkbox helper function
  static function checked($value, $current, $echo = false) {
    $out = '';

    if (!is_array($current)) {
      $current = (array) $current;
    }

    if (in_array($value, $current)) {
      $out = ' checked="checked" ';
    }

    if ($echo) {
      echo $out;
    } else {
      return $out;
    }
  } // checked


  // helper function for saving options, mostly checkboxes
  static function check_var_isset($values, $variables) {
    foreach ($variables as $key => $value) {
      if (!isset($values[$key])) {
        $values[$key] = $value;
      }
    }

    return $values;
  } // check_var_isset


  // helper function for creating dropdowns
  static function create_select_options($options, $selected = null, $output = true) {
    $out = "\n";

    if(!is_array($selected)) {
      $selected = array($selected);
    }

    foreach ($options as $tmp) {
      $data = '';
      if (isset($tmp['disabled'])) {
        $data .= ' disabled="disabled" ';
      }
      if (in_array($tmp['val'], $selected)) {
        $out .= "<option selected=\"selected\" value=\"{$tmp['val']}\"{$data}>{$tmp['label']}&nbsp;</option>\n";
      } else {
        $out .= "<option value=\"{$tmp['val']}\"{$data}>{$tmp['label']}&nbsp;</option>\n";
      }
    } // foreach

    if ($output) {
      echo $out;
    } else {
      return $out;
    }
  } // create_select_options


  // helper function to generate tagged buy links
  static function generate_web_link($placement = '', $page = '/', $params = array(), $anchor = '') {
    $base_url = 'https://underconstructionpage.com';

    if ('/' != $page) {
      $page = '/' . trim($page, '/') . '/';
    }
    if ($page == '//') {
      $page = '/';
    }

    $parts = array_merge(array('utm_source' => 'ucp-free', 'utm_medium' => 'plugin', 'utm_content' => $placement, 'utm_campaign' => 'ucp-free-v' . self::$version), $params);

    if (!empty($anchor)) {
      $anchor = '#' . trim($anchor, '#');
    }

    $out = $base_url . $page . '?' . http_build_query($parts, '', '&amp;') . $anchor;

    return $out;
  } // generate_web_link


  // first, main tab content
  static function tab_main() {
    $options = self::get_options();
    $default_options = self::default_options();

    echo '<div class="ucp-tab-content">';
    echo '<table class="form-table">';

    echo '<tr valign="top">
    <th scope="row"><label for="status">' . __('Under Construction Mode', 'under-construction-page') . '</label></th>
    <td>';

    echo '<div class="toggle-wrapper" id="main-status">
      <input type="checkbox" id="status" ' . self::checked(1, $options['status']) . ' type="checkbox" value="1" name="' . UCP_OPTIONS_KEY . '[status]">
      <label for="status" class="toggle"><span class="toggle_handler"></span></label>
    </div>';

    echo '<p class="description">' . __('By enabling construction mode users will not be able to access the site\'s content. They will only see the under construction page. To configure exceptions set <a class="change_tab" data-tab="3" href="#whitelisted-roles">whitelisted user roles</a>.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="search_engines">' . __('Prevent Search Engines from Indexing the Temporary Site', 'under-construction-page') . '</label></th>
    <td>';
    echo '<div class="toggle-wrapper">
      <input type="checkbox" id="search_engines" type="checkbox" value="1" class="skip-save open-ucp-upsell">
      <label for="search_engines" class="toggle"><span class="toggle_handler"></span></label>
    </div>';

    echo '<p class="description">' . __('While performing maintenance or having any temporary content displayed, it is favorable for SEO to prevent search engines from indexing the temporaray site. This is a <a href="#" class="open-ucp-upsell">PRO feature</a>.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="end_date_toggle">' . __('Automatic End Date &amp; Time', 'under-construction-page') . '</label></th>
    <td>';
    echo '<div class="toggle-wrapper">
      <input type="checkbox" id="end_date_toggle" ' . self::checked(1, (empty($options['end_date']) || $options['end_date'] == '0000-00-00 00:00')? 0: 1) . ' type="checkbox" value="1" name="' . UCP_OPTIONS_KEY . '[end_date_toggle]">
      <label for="end_date_toggle" class="toggle"><span class="toggle_handler"></span></label>
    </div>';
    echo '<div id="end_date_wrapper"><input id="end_date" type="text" class="datepicker" name="' . UCP_OPTIONS_KEY . '[end_date]" value="' . esc_attr($options['end_date']) . '" placeholder="yyyy-mm-dd hh:mm"><span title="' . __('Open date & time picker', 'under-construction-page') . '" alt="' . __('Open date & time picker', 'under-construction-page') . '" class="show-datepicker dashicons dashicons-calendar-alt"></span>';
    echo '<p class="description">' . __('If enabled, construction mode will automatically stop showing on the selected date.
    This option will not "auto-enable" construction mode. Status has to be set to "On".', 'under-construction-page') . '</p></div>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="ga_tracking_id_toggle">' . __('Google Analytics Tracking', 'under-construction-page') . '</label></th>
    <td>';
    echo '<div class="toggle-wrapper">
      <input type="checkbox" id="ga_tracking_id_toggle" ' . self::checked(1, empty($options['ga_tracking_id'])? 0: 1) . ' type="checkbox" value="1" name="' . UCP_OPTIONS_KEY . '[ga_tracking_toggle]">
      <label for="ga_tracking_id_toggle" class="toggle"><span class="toggle_handler"></span></label>
    </div>';
    echo '<div id="ga_tracking_id_wrapper"><input id="ga_tracking_id" type="text" class="code" name="' . UCP_OPTIONS_KEY . '[ga_tracking_id]" value="' . esc_attr($options['ga_tracking_id']) . '" placeholder="UA-xxxxxx-xx">';
    echo '<p class="description">' . __('Enter the unique tracking ID found in your GA tracking profile settings to track visits to pages.', 'under-construction-page') . '</p></div>';
    echo '</td></tr>';

    $reset_url = add_query_arg(array('action' => 'ucp_reset_settings', 'redirect' => urlencode($_SERVER['REQUEST_URI'])), admin_url('admin.php'));
    echo '<tr valign="top">
    <th scope="row"><label for="">' . __('Reset Settings', 'under-construction-page') . '</label></th>
    <td>';
    echo '<a href="' . $reset_url . '" class="button button-secondary reset-settings">' . __('Reset all settings to default values', 'under-construction-page') . '</a>';
    echo '<p class="description">' . __('By resetting all settings to their default values any customizations you have done will be lost. There is no undo.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '</table>';
    echo '</div>';

    self::footer_buttons();
  } // tab_main


  static function tab_content() {
    $options = self::get_options();
    $default_options = self::default_options();

    echo '<div class="ucp-tab-content">';
    echo '<table class="form-table">';

    // todo translate
    echo '<tr valign="top">
    <th scope="row"><label for="title">' . __('Title', 'under-construction-page') . '</label></th>
    <td><input type="text" id="title" class="regular-text" name="' . UCP_OPTIONS_KEY . '[title]" value="' . esc_attr($options['title']) . '" />';
    echo '<p class="description">Page title. Default: ' . $default_options['title'] . '</p>';
    echo '<p><b>Available shortcodes:</b> (only active in UC themes, not on the rest of the site)</p>
    <ul class="ucp-list">
    <li><code>[site-title]</code> - blog title, as set in <a href="options-general.php">Options - General</a></li>
    <li><code>[site-tagline]</code> - blog tagline, as set in <a href="options-general.php">Options - General</a></li>
    <li><code>[site-url]</code> - site address (URL), as set in <a href="options-general.php">Options - General</a></li>
    <li><code>[wp-url]</code> - WordPress address (URL), as set in <a href="options-general.php">Options - General</a></li>
    <li><code>[site-login-url]</code> - URL to site login page</li>
    </ul>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="description">' . __('Description', 'under-construction-page') . '</label></th>
    <td><input id="description" type="text" class="large-text" name="' . UCP_OPTIONS_KEY . '[description]" value="' . esc_attr($options['description']) . '" />';
    echo '<p class="description">Description meta tag (see above for available <a href="#title">shortcodes</a>). Default: ' . $default_options['description'] . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="heading1">' . __('Headline', 'under-construction-page') . '</label></th>
    <td><input id="heading1" type="text" class="large-text" name="' . UCP_OPTIONS_KEY . '[heading1]" value="' . esc_attr($options['heading1']) . '" />';
    echo '<p class="description">Main heading/title (see above for available <a href="#title">shortcodes</a>). Default: ' . $default_options['heading1'] . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top" id="content_wrap">
    <th scope="row"><label for="content">' . __('Content', 'under-construction-page') . '</label></th>
    <td>';
    wp_editor($options['content'], 'content', array('tabfocus_elements' => 'insert-media-button,save-post', 'editor_height' => 250, 'resize' => 1, 'textarea_name' => UCP_OPTIONS_KEY . '[content]', 'drag_drop_upload' => 1));
    echo '<p class="description">All HTML elements are allowed. Shortcodes are not parsed except <a href="#title">UC template ones</a>. Default: ' . $default_options['content'] . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="external_shortcodes">' . __('3rd Party Shortcode Support in Content', 'under-construction-page') . '</label></th>
    <td>';
    echo '<div class="toggle-wrapper">
      <input type="checkbox" id="external_shortcodes" type="checkbox" value="1" class="skip-save open-ucp-upsell">
      <label for="external_shortcodes" class="toggle"><span class="toggle_handler"></span></label>
    </div>';
    echo '<p class="description">Enable if you have a 3rd party shortcode you\'d like to use on the under construction page. This is a <a href="#" class="open-ucp-upsell">PRO feature</a>.</p>';
    echo '</td></tr>';

    echo '<tr valign="top" id="login_button_wrap">
    <th scope="row"><label for="login_button">' . __('Login Button', 'under-construction-page') . '</label></th>
    <td>';
    echo '<div class="toggle-wrapper">
      <input type="checkbox" id="login_button" ' . self::checked(1, $options['login_button']) . ' type="checkbox" value="1" name="' . UCP_OPTIONS_KEY . '[login_button]">
      <label for="login_button" class="toggle"><span class="toggle_handler"></span></label>
    </div>';
    echo '<p class="description">Show a discrete link to the login form, or WP admin if you\'re logged in, in the lower right corner of the page.</p>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="linkback">' . __('Show Some Love', 'under-construction-page') . '</label></th>
    <td>';
    echo '<div class="toggle-wrapper">
      <input type="checkbox" id="linkback" ' . self::checked(1, $options['linkback']) . ' type="checkbox" value="1" name="' . UCP_OPTIONS_KEY . '[linkback]">
      <label for="linkback" class="toggle"><span class="toggle_handler"></span></label>
    </div>';
    echo '<p class="description">Please help others learn about this free plugin by placing a small link in the footer. Thank you very much!</p>';
    echo '</td></tr>';


    echo '</table>';

    self::footer_buttons();

    echo '<h2 class="title">' . __('Social &amp; Contact Icons', 'under-construction-page') . '</h2>';

    echo '<table class="form-table" id="ucp-social-icons">';
    echo '<tr valign="top">
    <th scope="row"><label for="social_facebook">' . __('Facebook Page', 'under-construction-page') . '</label></th>
    <td><input id="social_facebook" type="url" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_facebook]" value="' . esc_attr($options['social_facebook']) . '" placeholder="' . __('Facebook business or personal page URL', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Complete URL, with http prefix, to Facebook page.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="social_twitter">' . __('Twitter Profile', 'under-construction-page') . '</label></th>
    <td><input id="social_twitter" type="url" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_twitter]" value="' . esc_attr($options['social_twitter']) . '" placeholder="' . __('Twitter profile URL', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Complete URL, with http prefix, to Twitter profile page.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="social_google">' . __('Google Page', 'under-construction-page') . '</label></th>
    <td><input id="social_google" type="url" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_google]" value="' . esc_attr($options['social_google']) . '" placeholder="' . __('Google+ page URL', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Complete URL, with http prefix, to Google+ page.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="social_linkedin">' . __('LinkedIn Profile', 'under-construction-page') . '</label></th>
    <td><input id="social_linkedin" type="url" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_linkedin]" value="' . esc_attr($options['social_linkedin']) . '" placeholder="' . __('LinkedIn profile page URL', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Complete URL, with http prefix, to LinkedIn profile page.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="social_youtube">' . __('YouTube Profile Page or Video', 'under-construction-page') . '</label></th>
    <td><input id="social_youtube" type="url" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_youtube]" value="' . esc_attr($options['social_youtube']) . '" placeholder="' . __('YouTube page or video URL', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Complete URL, with http prefix, to YouTube page or video.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top" class="hidden">
    <th scope="row"><label for="social_vimeo">' . __('Vimeo Profile Page or Video', 'under-construction-page') . '</label></th>
    <td><input id="social_vimeo" type="url" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_vimeo]" value="' . esc_attr($options['social_vimeo']) . '" placeholder="' . __('Vimeo page or video URL', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Complete URL, with http prefix, to Vimeo page or video.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top" class="hidden">
    <th scope="row"><label for="social_pinterest">' . __('Pinterest Profile', 'under-construction-page') . '</label></th>
    <td><input id="social_pinterest" type="url" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_pinterest]" value="' . esc_attr($options['social_pinterest']) . '" placeholder="' . __('Pinterest profile URL', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Complete URL, with http prefix, to Pinterest profile.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top" class="hidden">
    <th scope="row"><label for="social_dribbble">' . __('Dribbble Profile', 'under-construction-page') . '</label></th>
    <td><input id="social_dribbble" type="url" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_dribbble]" value="' . esc_attr($options['social_dribbble']) . '" placeholder="' . __('Dribbble profile URL', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Complete URL, with http prefix, to Dribbble profile.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top" class="hidden">
    <th scope="row"><label for="social_behance">' . __('Behance Profile', 'under-construction-page') . '</label></th>
    <td><input id="social_behance" type="url" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_behance]" value="' . esc_attr($options['social_behance']) . '" placeholder="' . __('Behance profile URL', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Complete URL, with http prefix, to Behance profile.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top" class="hidden">
    <th scope="row"><label for="social_instagram">' . __('Instagram Profile', 'under-construction-page') . '</label></th>
    <td><input id="social_instagram" type="url" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_instagram]" value="' . esc_attr($options['social_instagram']) . '" placeholder="' . __('Instagram profile URL', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Complete URL, with http prefix, to Instagram profile.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top" class="hidden">
    <th scope="row"><label for="social_tumblr">' . __('Tumblr Blog', 'under-construction-page') . '</label></th>
    <td><input id="social_tumblr" type="url" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_tumblr]" value="' . esc_attr($options['social_tumblr']) . '" placeholder="' . __('Tumblr blog URL', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Complete URL, with http prefix, to Tumblr blog.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top" class="hidden">
    <th scope="row"><label for="social_telegram">' . __('Telegram Group, Channel or Account', 'under-construction-page') . '</label></th>
    <td><input id="social_telegram" type="text" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_telegram]" value="' . esc_attr($options['social_telegram']) . '" placeholder="' . __('Telegram group, channel or account URL', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Complete URL, with https prefix to Telegram group, channel or account.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top" class="hidden">
    <th scope="row"><label for="social_skype">' . __('Skype Username', 'under-construction-page') . '</label></th>
    <td><input id="social_skype" type="text" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_skype]" value="' . esc_attr($options['social_skype']) . '" placeholder="' . __('Skype username or account name', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Skype username or account name.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top" class="hidden">
    <th scope="row"><label for="social_whatsapp">' . __('WhatsApp Phone Number', 'under-construction-page') . '</label></th>
    <td><input id="social_whatsapp" type="text" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_whatsapp]" value="' . esc_attr($options['social_whatsapp']) . '" placeholder="' . __('+1-123-456-789', 'under-construction-page') . '">';
    echo '<p class="description">' . __('WhatsApp phone number in full international format.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top" class="hidden">
    <th scope="row"><label for="social_email">' . __('Email Address', 'under-construction-page') . '</label></th>
    <td><input id="social_email" type="email" class="regular-text code" name="' . UCP_OPTIONS_KEY . '[social_email]" value="' . esc_attr($options['social_email']) . '" placeholder="' . __('name@domain.com', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Email will be encoded on the page to protect it from email address harvesters.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top" class="hidden">
    <th scope="row"><label for="social_phone">' . __('Phone Number', 'under-construction-page') . '</label></th>
    <td><input id="social_phone" type="tel" class="regular-text" name="' . UCP_OPTIONS_KEY . '[social_phone]" value="' . esc_attr($options['social_phone']) . '" placeholder="' . __('+1-123-456-789', 'under-construction-page') . '">';
    echo '<p class="description">' . __('Phone number in full international format.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr><th colspan="2"><a id="show-social-icons" href="#" class="js-action">' . __('Show more Social &amp; Contact Icons', 'under-construction-page') . '</a></th></tr>';

    echo '</table>';
    echo '</div>';

    self::footer_buttons();
  } // tab_content


  static function get_themes() {
    $themes = array('_pro_video' => __('Fireworks Video Background', 'under-construction-page'),
                    'mad_designer' => __('Mad Designer', 'under-construction-page'),
                    'plain_text' => __('Plain Text', 'under-construction-page'),
                    '_pro_mountain' => __('Mountain Peak', 'under-construction-page'),
                    'under_construction' => __('Under Construction', 'under-construction-page'),
                    'dark' => __('Things Went Dark', 'under-construction-page'),
                    'forklift' => __('Forklift at Work', 'under-construction-page'),
                    '_pro_watch' => __('The Watch Store', 'under-construction-page'),
                    'under_construction_text' => __('Under Construction Text', 'under-construction-page'),
                    'cyber_chick' => __('Cyber Chick', 'under-construction-page'),
                    '_pro_custom' => __('Build Your Own Custom Theme', 'under-construction-page'),
                    'rocket' => __('Rocket Launch', 'under-construction-page'),
                    'loader' => __('Loader at Work', 'under-construction-page'),
                    'cyber_chick_dark' => __('Cyber Chick Dark', 'under-construction-page'),
                    'safe' => __('Safe', 'under-construction-page'),
                    'people' => __('People at Work', 'under-construction-page'),
                    '_pro_clouds' => __('White Clouds', 'under-construction-page'),
                    'windmill' => __('Windmill', 'under-construction-page'),
                    'sad_site' => __('Sad Site', 'under-construction-page'),
                    'lighthouse' => __('Lighthouse', 'under-construction-page'),
                    '_pro_smoothie' => __('Green Smoothie Webinar', 'under-construction-page'),
                    'hot_air_baloon' => __('Hot Air Balloon', 'under-construction-page'),
                    'people_2' => __('People at Work #2', 'under-construction-page'),
                    'rocket_2' => __('Rocket Launch #2', 'under-construction-page'),
                    'light_bulb' => __('Light Bulb', 'under-construction-page'),
                    'ambulance' => __('Ambulance', 'under-construction-page'),
                    'laptop' => __('Laptop', 'under-construction-page'),
                    'puzzles' => __('Puzzles', 'under-construction-page'),
                    'iot' => __('Internet of Things', 'under-construction-page'),
                    'setup' => __('Setup', 'under-construction-page'),
                    'stop' => __('Stop', 'under-construction-page'),
                    'clock' => __('Clock', 'under-construction-page'),
                    'bulldozer' => __('Bulldozer at Work', 'under-construction-page'),
                    'christmas' => __('Christmas Greetings', 'under-construction-page'));

    $themes = apply_filters('ucp_themes', $themes);

    return $themes;
  } // get_themes


  static function tab_design() {
    $options = self::get_options();
    $default_options = self::default_options();

    $img_path = UCP_PLUGIN_URL . 'images/thumbnails/';
    $themes = self::get_themes();

    echo '<table class="form-table">';
    echo '<tr valign="top">
    <td colspan="2"><b style="margin-bottom: 10px; display: inline-block;">' . __('Theme', 'under-construction-page') . '</b><br>';
    echo '<input type="hidden" id="theme_id" name="' . UCP_OPTIONS_KEY . '[theme]" value="' . $options['theme'] . '">';

    foreach ($themes as $theme_id => $theme_name) {
      if ($theme_id === $options['theme']) {
        $class = ' active';
      } else {
        $class = '';
      }
      if (substr($theme_id, 0, 4) == '_pro') {
        echo '<div class="ucp-thumb ucp-thumb-pro open-ucp-upsell" data-theme-id="' . $theme_id . '" data-tab="buy"><a href="#"><img src="' . $img_path . $theme_id . '.jpg" alt="' . $theme_name . '" title="' . $theme_name . '"></a><span>' . $theme_name . '</span><div class="ribbon"><i>PRO</i></div></div>';
      } else {
        echo '<div class="ucp-thumb' . $class . '" data-theme-id="' . $theme_id . '"><img src="' . $img_path . $theme_id . '.png" alt="' . $theme_name . '" title="' . $theme_name . '"><span>' . $theme_name . '</span></div>';
      }
    } // foreach

    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="custom_css">' . __('Custom CSS', 'under-construction-page') . '</label></th>
    <td>';
    echo '<textarea data-autoresize="1" rows="3" id="custom_css" class="code large-text" name="' . UCP_OPTIONS_KEY . '[custom_css]" placeholder=".selector { property-name: property-value; }">' . esc_textarea($options['custom_css']) . '</textarea>';
    echo '<p class="description">&lt;style&gt; tags will be added automatically. Do not include them in your code.<br>
    For RTL languages support add: <code>body { direction: rtl; }</code></p>';
    echo '</td></tr>';

    echo '</table>';

    self::footer_buttons();
  } // tab_design


  // markup & logic for access tab
  static function tab_access() {
    $options = self::get_options();
    $default_options = self::default_options();
    $roles = $users = array();

    $tmp_roles = get_editable_roles();
    foreach ($tmp_roles as $tmp_role => $details) {
      $name = translate_user_role($details['name']);
      $roles[] = array('val' => $tmp_role,  'label' => $name);
    }

    $tmp_users = get_users(array('fields' => array('id', 'display_name')));
    foreach ($tmp_users as $user) {
      $users[] = array('val' => $user->id, 'label' => $user->display_name);
    }

    echo '<div class="ucp-tab-content">';
    echo '<table class="form-table">';

    echo '<tr valign="top">
    <th scope="row"><label for="whitelisted_ips">' . __('Whitelisted IP Addresses', 'under-construction-page') . '</label></th>
    <td>';
    echo '<div class="toggle-wrapper">
      <input type="checkbox" id="whitelisted_ips" type="checkbox" value="1" class="skip-save open-ucp-upsell">
      <label for="whitelisted_ips" class="toggle"><span class="toggle_handler"></span></label>
    </div>';
    echo '<p>Listed IP addresses will not be affected by the under construction mode and their users will always see the "normal" site. This is a <a href="#" class="open-ucp-upsell">PRO feature</a>.';
    echo '<td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="access_links">' . __('Secret Direct Access Links', 'under-construction-page') . '</label></th>
    <td>';
    echo '<div class="toggle-wrapper">
      <input type="checkbox" id="access_links" type="checkbox" value="1" class="skip-save open-ucp-upsell">
      <label for="access_links" class="toggle"><span class="toggle_handler"></span></label>
    </div>';
    echo '<p>The most flexible and user-friendly way (especially when working with clients) to give only selected visitors access to the "normal" site. Simply generate a new link, configure expiration options (time, number of visits or unique IPs) and share it with users to allow them access to the site. This is a <a href="#" class="open-ucp-upsell">PRO feature</a>.';
    echo '<td></tr>';

    echo '<tr valign="top" id="whitelisted-roles">
    <th scope="row">' . __('Whitelisted User Roles', 'under-construction-page') . '</th>
    <td>';
    foreach ($roles as $tmp_role) {
      echo  '<input name="' . UCP_OPTIONS_KEY . '[whitelisted_roles][]" id="roles-' . $tmp_role['val'] . '" ' . self::checked($tmp_role['val'], $options['whitelisted_roles'], false) . ' value="' . $tmp_role['val'] . '" type="checkbox" /> <label for="roles-' . $tmp_role['val'] . '">' . $tmp_role['label'] . '</label><br />';
    }
    echo '<p class="description">' . __('Selected user roles will <b>not</b> be affected by the under construction mode and will always see the "normal" site. Default: administrator.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="whitelisted_users">' . __('Whitelisted Users', 'under-construction-page') . '</label></th>
    <td><select id="whitelisted_users" class="select2" style="width: 50%; max-width: 300px;" name="' . UCP_OPTIONS_KEY . '[whitelisted_users][]" multiple>';
    self::create_select_options($users, $options['whitelisted_users'], true);

    echo '</select><p class="description">' . __('Selected users (when logged in) will <b>not</b> be affected by the under construction mode and will always see the "normal" site.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr>';
    echo '<th><label for="url_rules">URL Based Rules</label></th>';
    echo '<td><select class="skip-save open-ucp-upsell" id="url_rules">';
    echo '<option value="0">Disabled</option>';
    echo '<option class="ucp-promo" value="-1">Listed URLs will NEVER be affected by UCP</option>';
    echo '<option class="ucp-promo" value="-1">ONLY listed URLs CAN BE affected by UCP</option>';
    echo '</select>';
    echo '<p class="description">Use this option to set per URL rules and lock down the entire site except selected pages; or lock down just some pages and leave all others accessible to visitors. If second option is used all other access rules still apply. This is a <a href="#" class="open-ucp-upsell">PRO feature</a>.</p>';
    echo '</td>';
    echo '</tr>';


    echo '</table>';
    echo '</div>';

    self::footer_buttons();
  } // tab_access


  // support tab - FAQ and links
  static function tab_support() {
    $user = wp_get_current_user();
    $theme = wp_get_theme();
    $options = self::get_options();

    echo '<div id="tabs_support" class="ui-tabs ucp-tabs-2nd-level">';
    echo '<ul>';
    echo '<li><a href="#tab_support_contact">' . __('Contact Support', 'under-construction-page') . '</a></li>';
    echo '<li><a href="#tab_support_faq">' . __('FAQ', 'under-construction-page') . '</a></li>';
    echo '</ul>';

    echo '<div style="display: none;" id="tab_support_faq" class="ucp-tab-content">';

    echo '<p><b>How can I build a custom page or customize themes?</b><br>If you know how to code custom CSS a lot can be accomplished by using the <a class="change_tab" data-tab="1" href="#custom_css">custom CSS</a> option. A much faster and easier solution is to check out <a href="' . self::generate_web_link('faq-custom-page') . '" target="_blank">UCP PRO</a> with an easy-to-use drag&amp;drop builder and dozens of pre-built themes.</p>';

    echo '<p><b>Do you have a video to help me get started?</b><br>We sure do! <a href="https://www.youtube.com/watch?v=RN4XABhK7_w" target="_blank">Getting started with the UnderConstructionPage plugin</a>. If that doesn\'t help we also have an <a href="https://www.youtube.com/watch?v=K3DF-NP6Fog" target="_blank">in-depth video walktrough</a>. In case you\'re still uncertain about something don\'t hesitate to contact our friendly support.</p>';

    echo '<p><b>How can I check if construction mode is really enabled on my site?</b><br>If the under construction status is green in the admin bar (the very top of the page, above this text), then it\'s enabled. But we made a tool specifically for these kinds of situations so you can double-check everything. <a href="' . self::generate_web_link('faq-tester', 'under-construction-tester/', array('url' => get_home_url())) . '" target="_blank">Run under construction mode tester</a>.</p>';

    echo '<p><b>How can I work on my site while construction mode is enabled?</b><br>Make sure your user role (probably admin) is selected under <a class="change_tab" data-tab="3" href="#whitelisted-roles">Access - Whitelisted User Roles</a> and open the site while logged in.</p>';

    echo '<p><b>How can I log in / access WordPress admin after construction mode has been enabled?</b><br>Enable the <a class="change_tab" data-tab="2" href="#login_button_wrap">Login Button</a> option under Content, and a login link will be shown in the lower right corner of the under construction page.</p>';

    echo '<p><b>How do I add my logo to the page?</b><br>Head over to <a class="change_tab" data-tab="2" href="#content_wrap">Content</a> and click "Add Media". Upload/select the logo, position it as you see fit and add other content.</p>';

    echo '<p><b>I\'ve made changes to UCP, but they are not visible. What do I do?</b><br>Click "Save Changes" one more time. Open your site and force refresh browser cache (Ctrl or Shift + F5). If that doesn\'t help it means you have a caching plugin installed. Purge/delete cache in that plugin or disable it.</p>';

    echo '<p><b>How can I get more designs? Where do I download them?</b><br>We update the plugin every 7-10 days and each update comes with at least one new theme/design. There is no other way of getting more designs nor a place to download them.</p>';

    echo '<p><b>How can I edit designs?</b><br>There is an option to add <a class="change_tab" data-tab="1" href="#custom_css">custom CSS</a>. If you want more than that you will have to edit the source files located in <code>/under-construction-page/themes/</code>.</p>';

    echo '<p><b>I have disabled UCP but the under construction page is still visible. How do I remove it?</b><br>Open your site and force refresh browser cache (Ctrl or Shift + F5). If that doesn\'t help it means you have a caching plugin installed. Purge/delete cache in that plugin or disable it. If that fails too contact your hosting provider and ask to empty the site cache for you.</p>';

    echo '<p><b>I have disabled UCP but the site\'s favicon is still the UCP logo. How do I change/remove it?</b><br>Make sure your theme has a favicon defined and empty all caches - browser and server ones. Open the site and force refresh browser cache (Ctrl or Shift + F5). If that doesn\'t help it means you have a caching plugin installed. Purge/delete cache in that plugin or disable it. If that fails too contact your hosting provider and ask to empty the site cache for you.</p>';
    echo '</div>'; // faq

    echo '<div style="display: none;" id="tab_support_contact" class="ucp-tab-content">';
    echo '<p>' . __('Something is not working the way it\'s suppose to? Having problems activating UCP? Contact our friendly support, they\'ll respond ASAP.<br>You can also contact us just to say hello ;)', 'under-construction-page') . '</p>';

    echo '<table class="form-table">';
    echo '<tr valign="top">
    <th scope="row"><label for="support_priority">Ticket Priority</label></th>
    <td>';
    echo '<select class="skip-save open-ucp-upsell" id="support_priority" name="support_priority">';
    echo '<option value="0" selected>Low</option>';
    echo '<option value="-1" class="ucp-promo">Normal</option>';
    echo '<option value="-1" class="ucp-promo">High</option>';
    echo '</select>';
    echo '<p class="description">' . __('We reply to all tickets as fast as possible. However, <a href="#" class="open-ucp-upsell">PRO users</a> get to jump the queue.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="support_email">' . __('Your Email Address', 'under-construction-page') . '</label></th>
      <td><input id="support_email" type="text" class="regular-text skip-save" name="support_email" value="' . $user->user_email . '" placeholder="name@domain.com">';
    echo '<p class="description">' . __('We will reply to this email, so please, double-check it.', 'under-construction-page') . '</p>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="support_message">' . __('Message', 'under-construction-page') . '</label></th>
    <td><textarea rows="5" cols="75" id="support_message" class="skip-save" name="support_message" placeholder="Hi, I just wanted to ..."></textarea>';
    echo '<p class="description">Please be as descriptive as possible. It will help us to provide faster &amp; better support.</p>';
    echo '</td></tr>';

    echo '<tr valign="top">
    <th scope="row"><label for="support_info">' . __('Send Extra Site Info to Support Agents', 'under-construction-page') . '</label></th>
    <td>';
    echo '<div class="toggle-wrapper">
      <input type="checkbox" id="support_info" checked type="checkbox" value="1" name="support_info">
      <label for="support_info" class="toggle"><span class="toggle_handler"></span></label>
    </div>';
    echo '<p class="description">Our support agents need this info to provide faster &amp; better support. The following data will be added to your message;</p>';
    echo '<p>WordPress version: <code>' . get_bloginfo('version') . '</code><br>';
    echo 'UCP Version: <code>' . self::$version . '</code><br>';
    echo 'PHP Version: <code>' . PHP_VERSION . '</code><br>';
    echo 'Site URL: <code>' . get_bloginfo('url') . '</code><br>';
    echo 'WordPress URL: <code>' . get_bloginfo('wpurl') . '</code><br>';
    echo 'Theme: <code>' . $theme->get('Name') . ' v' . $theme->get('Version') . '</code><br>';
    echo 'UCP Options: <i>all option values will be sent to support to ease debugging</i>';
    echo '</p></td></tr>';
    echo '</table>';
    echo '<a id="ucp-send-support-message" href="#" class="js-action button button-primary"><span class="dashicons dashicons-update"></span>' . __('Send Message to Support', 'under-construction-page') . '</a>';
    echo '</table>';
    echo '</div>'; // contact

    echo '</div>'; // tabs
  } // tab_support


  // tab PRO
  static function tab_pro() {
    $options = self::get_options();

    echo '<div class="ucp-tab-content">';
    echo '<h3 class="ucp-pro-logo"><a href="' . self::generate_web_link('pro-tab-logo') . '" target="_blank"><img src="' . UCP_PLUGIN_URL . 'images/ucp_pro_logo.png" alt="UnderConstructionPage PRO" title="UnderConstructionPage"></a></h3>';

    if (UCP_license::is_activated()) {
      $plugin = plugin_basename(__FILE__);
      $update_url = wp_nonce_url(admin_url('update.php?action=upgrade-plugin&amp;plugin=' . urlencode($plugin)), 'upgrade-plugin_' . $plugin);
      echo '<p style="text-align: center;"><a href="' . $update_url . '" class="button button-primary button-large">Update UnderConstructionPage files to PRO</a><br><br></p>';
    } else {
      echo '<div id="ucp-earlybird"><span>Build <b>landing pages, coming soon pages, maintenance &amp; under construction pages</b> faster &amp; easier!</span>';
      echo '<p class="textcenter"><a href="#" class="button button-primary button-large open-ucp-upsell">Get <b>PRO</b>   now with a LIMITED <b>20% discount</b>!</a></p>';
      echo '</div>';
    }


    echo '<div class="ucp-tab-content">';
    echo '<table class="form-table">';

    echo '<tr valign="top">
    <th scope="row"><label for="license_key">' . __('License Key', 'under-construction-page') . '</label></th>';
    echo '<td><input type="text" id="license_key" class="regular-text" name="' . UCP_OPTIONS_KEY . '[license_key]" value="' . esc_attr($options['license_key']) . '" placeholder="12345-12345-12345-12345" />';
    echo '<p class="description">License key is located in the confirmation email you received after purchasing.<br>In case of any problems, please contact <a href="#" data-tab="4" class="change_tab">support</a>. If you don\'t have a PRO license key - <a href="#" class="open-ucp-upsell">get it now</a>.</p>';
    echo '</td></tr>';

    if (!empty($options['license_key'])) {
      if (UCP_license::is_activated()) {
        if ($options['license_expires'] == '2035-01-01') {
          $valid = 'indefinitely';
        } else {
          $valid = 'until ' . date('F jS, Y', strtotime($options['license_expires']));
          if (date('Y-m-d') == $options['license_expires']) {
            $valid .= '; expires today';
          } elseif (date('Y-m-d', time() + 30 * DAY_IN_SECONDS) > $options['license_expires']) {
            $tmp = (strtotime($options['license_expires'] . date(' G:i:s')) - time()) / DAY_IN_SECONDS;
            $valid .= '; expires in ' . round($tmp) . ' days';
          }
        }
        echo '<tr>
            <th scope="row"><label for="">' . __('License Status', 'under-construction-page') . '</label></th>
            <td><b style="color: #66b317;">Active</b><br>
            Type: ' . str_replace('pro', 'PRO', $options['license_type']);
        echo '<br>Valid ' . $valid . '</td>
            </tr>';
      } else {
        echo '<tr>
            <th scope="row"><label for="">' . __('License Status', 'under-construction-page') . '</label></th>
            <td><b style="color: #ea1919;">Inactive</b>';
        if (!empty($options['license_type'])) {
          echo '<br>Type: ' . $options['license_type'];
        }
        if (!empty($options['license_expires']) && $options['license_expires'] != '1900-01-01' && $options['license_expires'] != '1970-01-01') {
          echo '<br>Expired on ' . date('F jS, Y', strtotime($options['license_expires']));
        }
        echo '</td></tr>';
      }
    }

    echo '</table>';
    echo '</div>';

    echo '<p class="submit">';
    echo get_submit_button(__('Save &amp; Validate License Key', 'under-construction-page'), 'primary large', 'license-submit', false);
    echo '</p>';
  } // tab_pro


  // output the whole options page
  static function main_page() {
    if (!current_user_can('manage_options'))  {
      wp_die('You do not have sufficient permissions to access this page.');
    }

    $options = self::get_options();
    $default_options = self::default_options();

    echo '<div class="wrap">
          <h1 class="ucp-logo"><a href="' . admin_url('options-general.php?page=ucp') . '"><img src="' . UCP_PLUGIN_URL . 'images/ucp_logo.png" class="rotate" alt="UnderConstructionPage" title="UnderConstructionPage"><img src="' . UCP_PLUGIN_URL . 'images/ucp_logo_2.png" class="ucp-logo-text" alt="UnderConstructionPage" title="UnderConstructionPage"></a></h1>';

    echo '<form action="options.php" method="post" id="ucp_form">';
    settings_fields(UCP_OPTIONS_KEY);

    $tabs = array();
    $tabs[] = array('id' => 'ucp_main', 'icon' => 'dashicons-admin-settings', 'class' => '', 'label' => __('Main', 'under-construction-page'), 'callback' => array(__CLASS__, 'tab_main'));
    $tabs[] = array('id' => 'ucp_design', 'icon' => 'dashicons-admin-customizer', 'class' => '', 'label' => __('Design', 'under-construction-page'), 'callback' => array(__CLASS__, 'tab_design'));
    $tabs[] = array('id' => 'ucp_content', 'icon' => 'dashicons-format-aside', 'class' => '', 'label' => __('Content', 'under-construction-page'), 'callback' => array(__CLASS__, 'tab_content'));
    $tabs[] = array('id' => 'ucp_access', 'icon' => 'dashicons-shield', 'class' => '', 'label' => __('Access', 'under-construction-page'), 'callback' => array(__CLASS__, 'tab_access'));
    $tabs[] = array('id' => 'ucp_support', 'icon' => 'dashicons-sos', 'class' => '', 'label' => __('Support', 'under-construction-page'), 'callback' => array(__CLASS__, 'tab_support'));
    $tabs[] = array('id' => 'ucp_pro', 'icon' => 'dashicons-star-filled', 'class' => '', 'label' => __('PRO', 'under-construction-page'), 'callback' => array(__CLASS__, 'tab_pro'));
    $tabs = apply_filters('ucp_tabs', $tabs);

    echo '<div id="ucp_tabs" class="ui-tabs" style="display: none;">';
    echo '<ul class="ucp-main-tab">';
    foreach ($tabs as $tab) {
      if(!empty($tab['label'])){
          echo '<li><a href="#' . $tab['id'] . '" class="' . $tab['class'] . '"><span class="icon"><span class="dashicons ' . $tab['icon'] . '"></span></span><span class="label">' . $tab['label'] . '</span></a></li>';
      }
    }
    echo '</ul>';

    foreach ($tabs as $tab) {
      if(is_callable($tab['callback'])) {
        echo '<div style="display: none;" id="' . $tab['id'] . '">';
        call_user_func($tab['callback']);
        echo '</div>';
      }
    } // foreach

    echo '</div>'; // ucp_tabs

    echo '</form>'; // ucp_tabs
    echo '</div>'; // wrap

    echo '<div id="features-survey-dialog" style="display: none;" title="Please help us make UCP better"><span class="ui-helper-hidden-accessible"><input type="text"/></span>';
    echo '<p>We continuously add new features to <span class="ucp-logo">UnderConstructionPage</span>. In order to know what features to add we need to understand who our users are.<br><b>In what situations do you most often use UCP?</b></p>';

    $questions = array();
    $questions[] = '<div class="question-wrapper" data-value="solo-short">' .
                   '<div class="question"><b>I need to hide my site for a short time</b><br>while I do some work on it, it\'s a one time thing</div>' .
                   '</div>';

    $questions[] = '<div class="question-wrapper" data-value="solo-long">' .
                   '<div class="question"><b>I\'m building a site &amp; need a coming soon page</b><br>while I finish it</div>' .
                   '</div>';

    $questions[] = '<div class="question-wrapper" data-value="agency">' .
                   '<div class="question"><b>I create / manage multiple sites for clients</b><br>&amp; use UCP on them</div>' .
                   '</div>';

    $questions[] = '<div class="question-wrapper" data-value="webmaster">' .
                   '<div class="question"><b>I own multiple sites</b><br>&amp; use UCP when working on them</div>' .
                   '</div>';

    shuffle($questions);
    echo implode(' ', $questions);

    $current_user = wp_get_current_user();
    echo '<div class="footer">';
    echo '<input id="emailme" type="checkbox" value="' . $current_user->user_email . '"> <label for="emailme">Email me on ' . $current_user->user_email . ' when new features are added</label><br>';
    echo '<a data-survey="usage" class="submit-survey button-primary button button-large" href="#">Cast my Vote</a>';
    echo '<a href="#" class="dismiss-survey" data-survey="usage"><small><i>Close survey &amp; never show it again</i></small></a>';
    echo '</div>';
    echo '</div>'; // features-survey-dialog

    // upsell dialog
    echo '<div id="upsell-dialog" style="display: none;" title="UnderConstructionPage PRO"><span class="ui-helper-hidden-accessible"><input type="text"/></span>';
    echo '<div id="tabs_upsell" class="ui-tabs ucp-tabs-2nd-level">';
    echo '<ul>';
    echo '<li><a href="#tab-upsell-buy">' . __('Get PRO', 'under-construction-page') . '</a></li>';
    echo '<li><a href="#tab-upsell-features">' . __('Features', 'under-construction-page') . '</a></li>';
    echo '</ul>';

    echo '<div class="upsell-tab" id="tab-upsell-features" style="display: none;">';
    echo '<div class="gmw-pro-feature">';
    echo '<span>Frictionless Drag &amp; Drop Builder</span>';
    echo '<p>Forget about complicated, cumbersome builders that have too many options! UCP builder was purpose-built for the task at hand. Simple, user-friendly &amp; has only the options you need to build pages fast!</p>';
    echo '</div>';

    echo '<div class="gmw-pro-feature">';
    echo '<span>400,000+ Stunning Searchable Images</span>';
    echo '<p>There\'s nothing worse than googling for hours just to find that the perfect image you need is either copyrighted or too small. Enjoy a vast library of 4K+ sized images - categorised &amp; copyright free!</p>';
    echo '</div>';

    echo '<div class="gmw-pro-feature">';
    echo '<span>100+ Templates</span>';
    echo '<p>Building your own page from scratch is fun, but often you don\'t have time to do it! Use one of our purpose-built templates, change a few lines of text and you\'re ready to rock!</p>';
    echo '</div>';

    echo '<div class="gmw-pro-feature">';
    echo '<span>Affiliate & Traffic Tracking</span>';
    echo '<p>Having traffic is nice. Having targeted traffic is better! Generate tracked inbound links &amp; share them on social media or with your affiliates to pinpoint the best traffic sources.</p>';
    echo '</div>';

    echo '<div class="gmw-pro-feature">';
    echo '<span>Unlimited 3rd Party Integrations</span>';
    echo '<p>With our unique universal autoresponder support, you can integrate any email autoresponder or webinar system in a page within seconds. Or push data to Zapier to more than 1,000 applications.</p>';
    echo '</div>';

    echo '<div class="gmw-pro-feature">';
    echo '<span>Suitable for Agencies &amp; Webmasters</span>';
    echo '<p>Creating sites for others? We have your back! Our support &amp; licensing options are optimised for agencies. Unlimited sites license &amp; in-house, USA based support guarantee your peace of mind.</p>';
    echo '</div>';

    echo '<p class="upsell-footer">For a complete list of features, demos and screenshots visit <a href="' . self::generate_web_link('features-more-info') . '" target="_blank">underconstructionpage.com</a>. Already have a PRO license? <a href="#" class="go-to-license-key">Activate it</a>.</p>';

    echo '</div>'; // features tab
    echo '<div class="upsell-tab" id="tab-upsell-buy" style="display: none;">';
    echo '<table id="ucp-pricing-table">
  <colgroup></colgroup>
  <colgroup></colgroup>
  <colgroup></colgroup>
  <tbody>
    <tr>
      <td>
        <h3>Lifetime Unlimited<br>Agency License</h3>
      </td>
      <td>
        <h3>Lifetime<br>PRO License</h3>
      </td>
      <td>
        <h3>Yearly<br>PRO License</h3>
      </td>
    </tr>
    <tr>
      <td>One Time Payment</td>
      <td><span class="dashicons dashicons-yes"></span> One Time Payment</td>
      <td>Yearly Payment</td>
    </tr>
    <tr>
      <td>Unlimited Client &amp; Personal Sites</td>
      <td><span class="dashicons dashicons-yes"></span> 1 Personal or Client Site</td>
      <td>3 Personal Sites</td>
    </tr>
    <tr>
      <td>Lifetime Priority Support &amp; Updates</td>
      <td><span class="dashicons dashicons-yes"></span> Lifetime Support &amp; Updates</td>
      <td>1 Year of Support &amp; Updates</td>
    </tr>
    <tr>
      <td>400,000+ Hi-Res Images</td>
      <td><span class="dashicons dashicons-yes"></span> 400,000+ Hi-Res Images</td>
      <td>400,000+ Hi-Res Images</td>
    </tr>
    <tr>
      <td>100+ Templates + Builder</td>
      <td><span class="dashicons dashicons-yes"></span> 50+ Templates + Builder</td>
      <td>50+ Templates + Builder</td>
    </tr>
    <tr>
      <td>Zapier Integration</td>
      <td><span class="dashicons dashicons-no"></td>
      <td><span class="dashicons dashicons-no"></td>
    </tr>
    <tr>
      <td>
        <a class="promo-button go-to-license-key" href="' . self::generate_web_link('pricing-table-20-off', 'buy/', array('p' => 'agency-lifetime-welcome', 'r' => 'UCP v' . self::$version)) . '" target="_blank">BUY NOW 20% OFF<br><del>$250</del> $200</a>
        <span class="instant-download"><span class="dashicons dashicons-yes"></span> Secure payment<br><span class="dashicons dashicons-yes"></span> Instant activation from WordPress admin<br><span class="dashicons dashicons-yes"></span> 100% No-Risk Money Back Guarantee</span>
      </td>
      <td>
        <a class="promo-button go-to-license-key" href="' . self::generate_web_link('pricing-table-20-off', 'buy/', array('p' => 'pro-lifetime-welcome', 'r' => 'UCP v' . self::$version)) . '" target="_blank">BUY NOW 20% OFF<br><del>$70</del> $56</a>
        <span class="instant-download"><span class="dashicons dashicons-yes"></span> Secure payment<br><span class="dashicons dashicons-yes"></span> Instant activation from WordPress admin<br><span class="dashicons dashicons-yes"></span> 100% No-Risk Money Back Guarantee</span>
      </td>
      <td>
        <a class="promo-button go-to-license-key" href="' . self::generate_web_link('pricing-table-20-off', 'buy/', array('p' => 'pro-yearly-welcome', 'r' => 'UCP v' . self::$version)) . '" target="_blank">BUY NOW 20% OFF<br><del>$39</del> $31<small>/year</small></a>
        <span class="instant-download"><span class="dashicons dashicons-yes"></span> Secure payment<br><span class="dashicons dashicons-yes"></span> Instant activation from WordPress admin<br><span class="dashicons dashicons-yes"></span> 100% No-Risk Money Back Guarantee</span>
      </td>
    </tr>
  </tbody>
</table>';
    echo '<p class="upsell-footer">More pricing options &amp; details about packages are available on <a href="' . self::generate_web_link('pricing-table-more-info') . '" target="_blank">underconstructionpage.com</a>. Already have a PRO license? <a href="#" class="go-to-license-key">Activate it</a>.</p>';
    echo '</div>'; // pricing tab
    echo '</div>';

    echo '</div>'; // upsell-dialog
  } // main_page


  // save and preview buttons
  static function footer_buttons() {
    echo '<p class="submit">';
    echo get_submit_button(__('Save Changes', 'under-construction-page'), 'primary large', 'submit', false);
    echo ' &nbsp; &nbsp; <a id="ucp_preview" href="' . get_home_url() . '/?ucp_preview" class="button button-large button-secondary" target="_blank">' . __('Preview', 'under-construction-page') . '</a>';
    echo '</p>';
  } // footer_buttons


  // markup for deactivate dialog
  static function footer_plugins() {
    $support_link = admin_url('options-general.php?page=ucp&urgent-support=true#support');

    echo '<div id="ucp-deactivate-survey" style="display: none;" title="Please help us make UCP better"><span class="ui-helper-hidden-accessible"><input type="text"/></span>';

    echo '<div class="question-wrapper-assistance" data-value="urgent">' .
         '<div class="question"><b>Something\'s not working? We offer URGENT assistance!</b><br><a href="' . $support_link . '" class="button">Send a priority ticket to our friendly support agents</a><br><small><i>average response time is under 20 minutes</i></small></div>' . '</div>';

    echo '<p class="textcenter"><br>We want to improve! Please tell us:<br><b>Why are you deactivating <span class="ucp-logo">UnderConstructionPage</span>?</b></p>';

    $questions = array();
    $questions[] = '<div class="question-wrapper" data-value="temporary">' .
                   '<div class="question">It\'s a temporary deactivation, I\'m debugging something</div>' .
                   '</div>';

    $questions[] = '<div class="question-wrapper" data-value="not-working">' .
                   '<div class="question">Plugin is not working<div class="details">Please tell us what exactly is not working: <input type="text" class="normal-text ucp-deactivation-details"></div></div>' .
                   '</div>';

    $questions[] = '<div class="question-wrapper" data-value="wrong-plugin">' .
                   '<div class="question">Plugin is not what I thought it is, I need a different plugin</div>' .
                   '</div>';

    $questions[] = '<div class="question-wrapper" data-value="site-live">' .
                   '<div class="question">It served its purpose - site is now live</div>' .
                   '</div>';

    $questions[] = '<div class="question-wrapper" data-value="missing-feature">' .
                   '<div class="question">It doesn\'t have all the features I need<div class="details">Please tell us what features are missing: <input type="text" class="normal-text ucp-deactivation-details"></div></div>' .
                   '</div>';

    shuffle($questions);
    $questions[] = '<div class="question-wrapper" data-value="other">' .
                   '<div class="question">Something else<div class="details">Please tell us the reason: <input type="text" class="normal-text ucp-deactivation-details"></div></div>' .
                   '</div>';
    echo implode(' ', $questions);


    $current_user = wp_get_current_user();
    echo '<div class="footer">';
    echo '<a class="ucp-cancel-deactivate js-action button-secondary button button-large" href="#">Cancel Deactivation</a> <a data-survey="deactivate" class="button-primary button button-large ucp-deactivate" href="#">Continue with Deactivation</a>';
    echo '<br><br><a href="#" class="js-action ucp-deactivate-direct"><small><i>Deactivate without providing feedback</i></small></a>';
    echo '</div>';

    echo '</div>';
  } // footer_plugins


  // reset all pointers to default state - visible
  static function reset_pointers() {
    $pointers = array();

    $pointers['welcome'] = array('target' => '#menu-settings', 'edge' => 'left', 'align' => 'right', 'content' => 'Thank you for installing the <b style="font-weight: 800; font-variant: small-caps;">UnderConstructionPage</b> plugin! Please open <a href="' . admin_url('options-general.php?page=ucp'). '">Settings - UnderConstruction</a> to create a beautiful under construction page.');
    $pointers['getting_started'] = array('target' => '.ucp-main-tab li:nth-child(2)', 'edge' => 'top', 'align' => 'left', 'content' => 'Watch the short <a href="https://www.youtube.com/watch?v=RN4XABhK7_w" target="_blank">getting started video</a> to get you up to speed with UCP in no time. If that doesn\'t answer your questions watch the longer <a href="https://www.youtube.com/watch?v=K3DF-NP6Fog" target="_blank">in-depth video walktrough</a>.<br>If you need the videos later, links are in the <a href="#" class="change_tab" data-tab="4">FAQ</a>.');

    update_option(UCP_POINTERS_KEY, $pointers);
  } // reset_pointers


  // reset pointers on activation
  static function activate() {
    self::reset_pointers();
  } // activate

  // clean up on deactivation
  static function deactivate() {
    delete_option(UCP_POINTERS_KEY);
    delete_option(UCP_NOTICES_KEY);
  } // deactivate


  // clean up on uninstall
  static function uninstall() {
    delete_option(UCP_OPTIONS_KEY);
    delete_option(UCP_META_KEY);
    delete_option(UCP_POINTERS_KEY);
    delete_option(UCP_NOTICES_KEY);
  } // uninstall
} // class UCP


// hook everything up
register_activation_hook(__FILE__, array('UCP', 'activate'));
register_deactivation_hook(__FILE__, array('UCP', 'deactivate'));
register_uninstall_hook(__FILE__, array('UCP', 'uninstall'));
add_action('init', array('UCP', 'init'));
add_action('plugins_loaded', array('UCP', 'plugins_loaded'));
