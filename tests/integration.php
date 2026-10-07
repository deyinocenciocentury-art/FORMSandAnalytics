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
$capture_key = 'soulmarke_local_mail_capture_run';
global $wpdb;
$table = Soulmarke_Forms::table();
$own_records = function () use ($wpdb, $table, $run) {
    $like = '%' . $wpdb->esc_like($run) . '%';
    return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE answers LIKE %s", $like)));
};
$result = array();
switch ($action) {
    case 'begin':
        if (wp_get_environment_type() !== 'local') {
            throw new RuntimeException('Integration fixtures require the local WordPress runtime.');
        }
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
            'mail_capture_exists' => $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $capture_key)) !== null,
            'mail_capture_value' => get_option($capture_key),
        ), '', false);
        update_option($capture_key, $run, false);
        $result = array('settings' => Soulmarke_Forms::settings());
        break;
    case 'mail_probe':
        if (!is_array($saved) || get_option($capture_key) !== $run) {
            throw new RuntimeException('Begin the fixture run before testing local mail capture.');
        }
        wp_mail('capture-probe@example.invalid', 'Local integration capture probe ' . $run, 'Unmarked local probe body');
        clearstatcache(true, '/tmp/soulmarke-local-mail.jsonl');
        $result = array('mail_log_private' => (fileperms('/tmp/soulmarke-local-mail.jsonl') & 0777) === 0600,
            'site_timezone' => wp_timezone_string());
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
            if ($saved['mail_capture_exists']) {
                update_option($capture_key, $saved['mail_capture_value'], false);
            } else {
                delete_option($capture_key);
            }
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user((int) $saved['user_id']);
            delete_option($key);
        }
        // Keep normal metadata logs, but remove body fields belonging only to
        // this run. Lock the same file used by the local mail interceptor so a
        // concurrent unrelated message is preserved.
        $mail_path = '/tmp/soulmarke-local-mail.jsonl';
        $remaining_mail_bodies = 0;
        if (file_exists($mail_path)) {
            $mail_file = fopen($mail_path, 'c+');
            if (!$mail_file || !flock($mail_file, LOCK_EX)) {
                throw new RuntimeException('Cannot safely clean up this run’s local mail bodies.');
            }
            $lines = preg_split('/\r?\n/', stream_get_contents($mail_file));
            $retained = array();
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                $mail_record = json_decode($line, true);
                if (is_array($mail_record) && ($mail_record['capture_run'] ?? '') === $run) {
                    unset($mail_record['message'], $mail_record['capture_run']);
                    $line = wp_json_encode($mail_record);
                }
                $retained[] = $line;
            }
            rewind($mail_file);
            $mail_text = $retained ? implode("\n", $retained) . "\n" : '';
            $written = ftruncate($mail_file, 0) && fwrite($mail_file, $mail_text) === strlen($mail_text) && fflush($mail_file);
            flock($mail_file, LOCK_UN);
            fclose($mail_file);
            if (!$written) {
                throw new RuntimeException('Cannot remove this run’s local mail body fields.');
            }
            chmod($mail_path, 0600);
            foreach ($retained as $line) {
                $mail_record = json_decode($line, true);
                if (is_array($mail_record) && isset($mail_record['message']) && strpos($mail_record['message'], $run) !== false) {
                    $remaining_mail_bodies++;
                }
            }
        }
        $capture_exists = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $capture_key)) !== null;
        $capture_restored = !is_array($saved) || ($capture_exists === $saved['mail_capture_exists']
            && (!$capture_exists || get_option($capture_key) === $saved['mail_capture_value']));
        $result = array('remaining_records' => count($own_records()), 'remaining_mail_bodies' => $remaining_mail_bodies,
            'capture_restored' => $capture_restored);
        break;
    default:
        throw new RuntimeException('Unknown fixture action.');
}
echo wp_json_encode($result);
