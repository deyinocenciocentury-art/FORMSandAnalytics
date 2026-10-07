<?php
/**
 * Plugin Name: Holistic Collective Forms & Analytics
 * Description: One-question-at-a-time surveys with editable questions, private results, comparisons, analytics, and email notifications.
 * Version: 1.0.1
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Text Domain: soulmarke-forms
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SOULMARKE_FORMS_VERSION', '1.0.1');
define('SOULMARKE_FORMS_FILE', __FILE__);
define('SOULMARKE_FORMS_DIR', plugin_dir_path(__FILE__));
define('SOULMARKE_FORMS_URL', plugin_dir_url(__FILE__));

require_once SOULMARKE_FORMS_DIR . 'includes/class-soulmarke-forms.php';
require_once SOULMARKE_FORMS_DIR . 'includes/class-soulmarke-forms-frontend.php';
require_once SOULMARKE_FORMS_DIR . 'includes/class-soulmarke-forms-admin.php';

register_activation_hook(__FILE__, array('Soulmarke_Forms', 'install'));
add_action('plugins_loaded', array('Soulmarke_Forms', 'init'));
