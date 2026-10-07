<?php
/**
 * Fixture helper for integration.py. Run only through WP-CLI as an administrator.
 * Never exposes an HTTP endpoint; only fixtures carrying the unique run marker
 * may be changed or removed.
 */
if (!defined('WP_CLI') || !WP_CLI || !current_user_can('manage_options')) {
    throw new RuntimeException('Run this helper through WP-CLI as an administrator.');
}
$request = json_decode(base64_decode($args[0] ?? '', true), true);
if (!is_array($request) || !preg_match('/^qa_[a-f0-9]{20}$/', $request['run'] ?? '')) {
    throw new RuntimeException('Invalid integration run marker.');
}
$run = $request['run'];
$key = '_soulmarke_integration_' . $run;
$action = $request['action'] ?? '';
$saved = get_option($key);
global $wpdb;
$table = Soulmarke_Forms::table();
$own_records = function () use ($wpdb, $table, $run) {
    $like = '%' . $wpdb->esc_like($run) . '%';
    return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE answers LIKE %s", $like)));
};
$result = array();
switch ($action) {
    case 'begin':
        if ($saved !== false) {
            throw new RuntimeException('This test marker already exists.');
        }
        $user_id = wp_insert_user(array(
            'user_login' => $run,
            'user_pass' => $request['password'],
            'user_email' => $run . '@example.invalid',
            'role' => 'subscriber',
        ));
        if (is_wp_error($user_id)) {
            throw new RuntimeException($user_id->get_error_message());
        }
        add_option($key, array(
            'settings' => get_option(Soulmarke_Forms::OPTION),
            'timezone_string' => get_option('timezone_string'),
            'gmt_offset' => get_option('gmt_offset'),
            'user_id' => $user_id,
        ), '', false);
        $result = array('settings' => Soulmarke_Forms::settings());
        break;
    case 'state':
        $records = array_map(array('Soulmarke_Forms', 'get_submission'), $own_records());
        $result = array('settings' => Soulmarke_Forms::settings(), 'records' => $records);
        break;
    case 'timezone':
        if (!is_array($saved)) {
            throw new RuntimeException('Begin the fixture run first.');
        }
        update_option('timezone_string', $request['timezone']);
        update_option('gmt_offset', $request['timezone'] === 'Asia/Manila' ? 8 : 0);
        break;
    case 'timestamp':
        $id = (int) $request['id'];
        if (!in_array($id, $own_records(), true)) {
            throw new RuntimeException('Only this run’s submissions may be modified.');
        }
        $wpdb->update($table, array('submitted_at' => $request['timestamp']), array('id' => $id));
        break;
    case 'analytics':
        $result = Soulmarke_Forms::analytics($request['filters'] ?? array('search' => $run));
        break;
    case 'cleanup':
        foreach ($own_records() as $id) {
            Soulmarke_Forms::delete_submission($id);
        }
        if (is_array($saved)) {
            update_option(Soulmarke_Forms::OPTION, $saved['settings'], false);
            update_option('timezone_string', $saved['timezone_string']);
            update_option('gmt_offset', $saved['gmt_offset']);
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user((int) $saved['user_id']);
            delete_option($key);
        }
        $result = array('remaining_records' => count($own_records()));
        break;
    default:
        throw new RuntimeException('Unknown fixture action.');
}
echo wp_json_encode($result);
