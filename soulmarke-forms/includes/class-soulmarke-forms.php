<?php
/** Persistence, validation, and submission endpoints. */
if (!defined('ABSPATH')) {
    exit;
}

class Soulmarke_Forms
{
    const OPTION = 'soulmarke_forms_settings';
    const DB_VERSION = '1';

    public static function init()
    {
        if (get_option('soulmarke_forms_db_version') !== self::DB_VERSION) {
            self::install();
        }
        add_action('wp_ajax_soulmarke_submit', array(__CLASS__, 'submit'));
        add_action('wp_ajax_nopriv_soulmarke_submit', array(__CLASS__, 'submit'));
        add_action('admin_post_soulmarke_export', array(__CLASS__, 'export'));
        Soulmarke_Forms_Frontend::init();
        Soulmarke_Forms_Admin::init();
    }

    public static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'soulmarke_submissions';
    }

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            submitted_at datetime NOT NULL,
            answers longtext NOT NULL,
            other_answers longtext NOT NULL,
            questions longtext NOT NULL,
            notification_status varchar(20) NOT NULL DEFAULT 'not_configured',
            PRIMARY KEY  (id),
            KEY submitted_at (submitted_at)
        ) $charset;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
            update_option('soulmarke_forms_db_version', self::DB_VERSION, false);
        }
        add_option(self::OPTION, self::defaults(), '', false);
    }

    public static function defaults()
    {
        return require SOULMARKE_FORMS_DIR . 'includes/survey-defaults.php';
    }

    public static function settings()
    {
        $saved = get_option(self::OPTION, array());
        return array_merge(self::defaults(), is_array($saved) ? $saved : array());
    }

    public static function save_settings($settings)
    {
        return update_option(self::OPTION, $settings, false);
    }

    public static function normalize_settings($raw)
    {
        if (!is_array($raw)) {
            return new WP_Error('invalid_settings', 'The settings could not be read.');
        }
        $result = array();
        foreach (array('title' => 200, 'description' => 3000, 'success_message' => 1000) as $key => $limit) {
            $value = isset($raw[$key]) && is_scalar($raw[$key]) ? (string) $raw[$key] : '';
            $result[$key] = $key === 'title' ? sanitize_text_field($value) : sanitize_textarea_field($value);
            if (self::length($result[$key]) > $limit) {
                return new WP_Error('invalid_settings', 'The form ' . str_replace('_', ' ', $key) . ' is too long.');
            }
        }
        if ($result['title'] === '' || $result['success_message'] === '') {
            return new WP_Error('invalid_settings', 'Enter a form title and a thank-you message.');
        }
        $recipients = isset($raw['recipients']) ? $raw['recipients'] : array();
        if (is_string($recipients)) {
            $recipients = preg_split('/[\r\n,;]+/', $recipients);
        }
        if (!is_array($recipients)) {
            return new WP_Error('invalid_recipients', 'Enter email addresses separated by commas or new lines.');
        }
        $result['recipients'] = array();
        foreach ($recipients as $email) {
            if (!is_scalar($email)) {
                return new WP_Error('invalid_recipients', 'A notification address is invalid.');
            }
            $email = trim((string) $email);
            if ($email === '') {
                continue;
            }
            if (!is_email($email)) {
                return new WP_Error('invalid_recipients', 'A notification address is invalid. Check each address and try again.');
            }
            $result['recipients'][] = sanitize_email($email);
        }
        $result['recipients'] = array_values(array_unique($result['recipients']));
        if (count($result['recipients']) > 20) {
            return new WP_Error('invalid_recipients', 'Use no more than 20 notification recipients.');
        }
        $questions = isset($raw['questions']) ? $raw['questions'] : array();
        if (!is_array($questions) || count($questions) > 100) {
            return new WP_Error('invalid_questions', 'Use no more than 100 questions.');
        }
        $result['questions'] = array();
        $ids = array();
        $types = array('text', 'textarea', 'email', 'number', 'radio', 'checkbox', 'select', 'rating');
        foreach ($questions as $question) {
            if (!is_array($question)) {
                return new WP_Error('invalid_questions', 'A question could not be read.');
            }
            $id = isset($question['id']) && is_scalar($question['id']) ? sanitize_key((string) $question['id']) : '';
            if ($id === '') {
                $id = 'q_' . str_replace('-', '', wp_generate_uuid4());
            }
            if (strlen($id) > 64 || isset($ids[$id])) {
                return new WP_Error('invalid_questions', 'Question identifiers must be unique. Reload the editor and try again.');
            }
            $ids[$id] = true;
            $title = isset($question['title']) && is_scalar($question['title']) ? sanitize_text_field((string) $question['title']) : '';
            $description = isset($question['description']) && is_scalar($question['description']) ? sanitize_textarea_field((string) $question['description']) : '';
            $type = isset($question['type']) && is_string($question['type']) ? $question['type'] : '';
            if ($title === '' || self::length($title) > 500 || self::length($description) > 3000 || !in_array($type, $types, true)) {
                return new WP_Error('invalid_questions', 'Each question needs a title, a supported type, and text within the length limits.');
            }
            $options = isset($question['options']) ? $question['options'] : array();
            if (is_string($options)) {
                $options = preg_split('/\r\n|\r|\n/', $options);
            }
            if (!is_array($options)) {
                return new WP_Error('invalid_questions', 'Answer choices must be entered one per line.');
            }
            $clean_options = array();
            foreach ($options as $option) {
                if (!is_scalar($option)) {
                    return new WP_Error('invalid_questions', 'An answer choice could not be read.');
                }
                $option = sanitize_text_field((string) $option);
                if ($option !== '') {
                    if (self::length($option) > 500) {
                        return new WP_Error('invalid_questions', 'An answer choice is too long.');
                    }
                    $clean_options[] = $option;
                }
            }
            if (count($clean_options) > 50 || count($clean_options) !== count(array_unique($clean_options))) {
                return new WP_Error('invalid_questions', 'Use up to 50 distinct choices per question.');
            }
            $choice_type = in_array($type, array('radio', 'checkbox', 'select'), true);
            if ($choice_type && !$clean_options) {
                return new WP_Error('invalid_questions', 'Choice questions need at least one answer choice.');
            }
            $other = isset($question['other_option']) && is_scalar($question['other_option']) ? sanitize_text_field((string) $question['other_option']) : '';
            if (!$choice_type) {
                $other = '';
            }
            if ($other !== '' && !in_array($other, $clean_options, true)) {
                return new WP_Error('invalid_questions', 'The Other choice label must match one of the answer choices exactly.');
            }
            $max = isset($question['max_selections']) && is_scalar($question['max_selections']) ? absint($question['max_selections']) : 0;
            $result['questions'][] = array(
                'id' => $id, 'title' => $title, 'description' => $description, 'type' => $type,
                'required' => !empty($question['required']), 'options' => $choice_type ? $clean_options : array(),
                'other_option' => $other, 'max_selections' => $type === 'checkbox' ? min($max, count($clean_options)) : 0,
            );
        }
        return $result;
    }

    private static function length($value)
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /** Shared validation keeps browser and direct requests under the same rules. */
    public static function validate_answers($input, $other_input, $questions)
    {
        if (!is_array($input) || !is_array($other_input)) {
            return new WP_Error('invalid_answers', 'Your answers could not be read. Please refresh the page and try again.');
        }
        $answers = array();
        $others = array();
        $errors = array();
        foreach ($questions as $question) {
            $id = $question['id'];
            $type = $question['type'];
            $value = array_key_exists($id, $input) ? $input[$id] : ($type === 'checkbox' ? array() : '');
            if ($type === 'checkbox') {
                if (!is_array($value) || count($value) > count($question['options'])) {
                    $errors[$id] = 'Choose from the listed answers.';
                    continue;
                }
                $clean = array();
                foreach ($value as $item) {
                    if (!is_string($item) || !in_array($item, $question['options'], true)) {
                        $errors[$id] = 'Choose from the listed answers.';
                        continue 2;
                    }
                    $clean[] = $item;
                }
                $value = array_values(array_unique($clean));
                if (!empty($question['max_selections']) && count($value) > $question['max_selections']) {
                    $errors[$id] = 'Choose up to ' . $question['max_selections'] . ' answers.';
                }
            } else {
                if (!is_scalar($value) || is_bool($value)) {
                    $errors[$id] = 'Enter a valid answer.';
                    continue;
                }
                $value = trim((string) $value);
                if (in_array($type, array('radio', 'select'), true)) {
                    if ($value !== '' && !in_array($value, $question['options'], true)) {
                        $errors[$id] = 'Choose from the listed answers.';
                    }
                } elseif ($type === 'rating') {
                    if ($value !== '' && !preg_match('/^[1-5]$/', $value)) {
                        $errors[$id] = 'Choose a rating from 1 to 5.';
                    }
                } elseif ($type === 'email') {
                    if ($value !== '' && !is_email($value)) {
                        $errors[$id] = 'Enter a valid email address.';
                    }
                    $value = sanitize_email($value);
                } elseif ($type === 'number') {
                    if ($value !== '' && (!is_numeric($value) || !is_finite((float) $value))) {
                        $errors[$id] = 'Enter a valid number.';
                    }
                    $value = sanitize_text_field($value);
                } else {
                    $value = $type === 'textarea' ? sanitize_textarea_field($value) : sanitize_text_field($value);
                    if (self::length($value) > 5000) {
                        $errors[$id] = 'Keep this answer under 5,000 characters.';
                    }
                }
            }
            $empty = is_array($value) ? count($value) === 0 : $value === '';
            if (!empty($question['required']) && $empty) {
                $errors[$id] = 'Please answer this question.';
            }
            $other_option = isset($question['other_option']) ? $question['other_option'] : '';
            $selected_other = $other_option !== '' && (is_array($value) ? in_array($other_option, $value, true) : $value === $other_option);
            if ($selected_other) {
                $other = isset($other_input[$id]) && is_string($other_input[$id]) ? sanitize_textarea_field($other_input[$id]) : '';
                if (trim($other) === '' || self::length($other) > 1000) {
                    $errors[$id] = 'Please describe your Other answer in up to 1,000 characters.';
                } else {
                    $others[$id] = $other;
                }
            }
            $answers[$id] = $value;
        }
        if ($errors) {
            return new WP_Error('invalid_answers', 'Please check your answers.', $errors);
        }
        return array('answers' => $answers, 'other_answers' => $others);
    }

    public static function submit()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(array('message' => 'Submit the form using POST.'), 405);
        }
        if (!check_ajax_referer('soulmarke_submit', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Your form session expired. Refresh the page and try again.'), 403);
        }
        $honeypot = isset($_POST['website']) ? wp_unslash($_POST['website']) : '';
        if (!is_string($honeypot) || $honeypot !== '') {
            wp_send_json_error(array('message' => 'Your submission could not be accepted.'), 400);
        }
        $raw = isset($_POST['answers']) && is_string($_POST['answers']) ? wp_unslash($_POST['answers']) : '';
        $raw_other = isset($_POST['other_answers']) && is_string($_POST['other_answers']) ? wp_unslash($_POST['other_answers']) : '{}';
        if (strlen($raw) + strlen($raw_other) > 131072) {
            wp_send_json_error(array('message' => 'Your submission is too large.'), 413);
        }
        $settings = self::settings();
        if (!$settings['questions']) {
            wp_send_json_error(array('message' => 'This form is being prepared. Please check back soon.'), 503);
        }
        $result = self::validate_answers(json_decode($raw, true), json_decode($raw_other, true), $settings['questions']);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message(), 'errors' => $result->get_error_data()), 422);
        }
        $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $rate_key = 'smf_rate_' . hash_hmac('sha256', $remote, wp_salt('nonce'));
        $rate = get_transient($rate_key);
        $now = time();
        if (!is_array($rate) || !isset($rate['until']) || $rate['until'] <= $now) {
            $rate = array('count' => 0, 'until' => $now + 600);
        }
        if ($rate['count'] >= 20) {
            wp_send_json_error(array('message' => 'Too many submissions were received. Please try again in a few minutes.'), 429);
        }
        global $wpdb;
        $inserted = $wpdb->insert(self::table(), array(
            'submitted_at' => current_time('mysql', true),
            'answers' => wp_json_encode($result['answers']),
            'other_answers' => wp_json_encode($result['other_answers']),
            'questions' => wp_json_encode($settings['questions']),
            'notification_status' => 'not_configured',
        ), array('%s', '%s', '%s', '%s', '%s'));
        if (!$inserted) {
            wp_send_json_error(array('message' => 'Your answers could not be saved. Please try again.'), 500);
        }
        $id = (int) $wpdb->insert_id;
        $rate['count']++;
        set_transient($rate_key, $rate, max(1, $rate['until'] - $now));
        $status = self::notify($id, $settings);
        $wpdb->update(self::table(), array('notification_status' => $status), array('id' => $id), array('%s'), array('%d'));
        wp_send_json_success(array('message' => $settings['success_message']));
    }

    private static function notify($id, $settings)
    {
        if (empty($settings['recipients'])) {
            return 'not_configured';
        }
        $subject = '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] New survey submission #' . $id;
        $url = add_query_arg(array('page' => 'soulmarke-submissions', 'submission' => $id), admin_url('admin.php'));
        $message = 'A new submission was received for ' . $settings['title'] . ".\n\n"
            . 'View the private submission in WordPress (administrator login required):' . "\n" . $url . "\n\n"
            . 'Notification recipients can be changed in Soulmarke Forms settings.';
        $sent = true;
        foreach ($settings['recipients'] as $recipient) {
            if (!wp_mail($recipient, $subject, $message, array('Content-Type: text/plain; charset=UTF-8'))) {
                $sent = false;
            }
        }
        return $sent ? 'sent' : 'failed';
    }

    private static function where($args)
    {
        global $wpdb;
        $where = array('1=1');
        foreach (array('from', 'to') as $field) {
            $date = isset($args[$field]) && is_string($args[$field]) ? $args[$field] : '';
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $stamp = get_gmt_from_date($date . ($field === 'from' ? ' 00:00:00' : ' 23:59:59'));
                $where[] = $wpdb->prepare('submitted_at ' . ($field === 'from' ? '>=' : '<=') . ' %s', $stamp);
            }
        }
        if (isset($args['search']) && is_string($args['search']) && $args['search'] !== '') {
            // Match JSON's escaped representation as well as literal text. This
            // keeps accented characters, quotes, and backslashes searchable.
            $encoded = wp_json_encode($args['search']);
            $escaped = is_string($encoded) ? substr($encoded, 1, -1) : $args['search'];
            $term = '%' . $wpdb->esc_like($escaped) . '%';
            $literal = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = $wpdb->prepare('(answers LIKE %s OR other_answers LIKE %s OR answers LIKE %s OR other_answers LIKE %s)', $term, $term, $literal, $literal);
        }
        return implode(' AND ', $where);
    }

    private static function decode($row)
    {
        if (!$row) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        foreach (array('answers', 'other_answers', 'questions') as $key) {
            $value = json_decode($row[$key], true);
            $row[$key] = is_array($value) ? $value : array();
        }
        return $row;
    }

    public static function submissions($args = array())
    {
        global $wpdb;
        $limit = isset($args['limit']) ? max(1, min(1000, absint($args['limit']))) : 20;
        $offset = isset($args['offset']) ? absint($args['offset']) : 0;
        $query = 'SELECT * FROM ' . self::table() . ' WHERE ' . self::where($args) . ' ORDER BY submitted_at DESC, id DESC';
        $rows = $wpdb->get_results($wpdb->prepare($query . ' LIMIT %d OFFSET %d', $limit, $offset), ARRAY_A);
        return array_map(array(__CLASS__, 'decode'), is_array($rows) ? $rows : array());
    }

    public static function count_submissions($args = array())
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . self::where($args));
    }

    public static function get_submission($id)
    {
        global $wpdb;
        return self::decode($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', absint($id)), ARRAY_A));
    }

    public static function delete_submission($id)
    {
        global $wpdb;
        return (bool) $wpdb->delete(self::table(), array('id' => absint($id)), array('%d'));
    }

    public static function analytics($args = array())
    {
        $questions = array();
        foreach (self::settings()['questions'] as $question) {
            $questions[self::metric_key($question)] = self::empty_metric($question);
        }
        $daily = array();
        $total = 0;
        $offset = 0;
        do {
            $rows = self::submissions(array_merge($args, array('limit' => 1000, 'offset' => $offset)));
            foreach ($rows as $row) {
                $total++;
                $date = get_date_from_gmt($row['submitted_at'], 'Y-m-d');
                $daily[$date] = isset($daily[$date]) ? $daily[$date] + 1 : 1;
                foreach ($row['questions'] as $question) {
                    $id = $question['id'];
                    $key = self::metric_key($question);
                    if (!isset($questions[$key])) {
                        $questions[$key] = self::empty_metric($question);
                    }
                    $value = isset($row['answers'][$id]) ? $row['answers'][$id] : '';
                    if ($value === '' || $value === array()) {
                        continue;
                    }
                    $metric = &$questions[$key];
                    $metric['responses']++;
                    if (in_array($question['type'], array('radio', 'checkbox', 'select', 'rating'), true)) {
                        foreach (is_array($value) ? $value : array($value) as $label) {
                            if (!isset($metric['_options'][$label])) {
                                $metric['_options'][$label] = 0;
                            }
                            $metric['_options'][$label]++;
                        }
                    }
                    if (in_array($question['type'], array('number', 'rating'), true) && is_numeric($value)) {
                        $number = (float) $value;
                        $scale = abs($number);
                        if ($scale > $metric['_scale']) {
                            $metric['_scaled_sum'] = $metric['_scaled_sum'] * ($metric['_scale'] / $scale) + $number / $scale;
                            $metric['_scale'] = $scale;
                        } elseif ($metric['_scale'] > 0) {
                            $metric['_scaled_sum'] += $number / $metric['_scale'];
                        }
                        $metric['_numeric_count']++;
                    }
                    if (!in_array($question['type'], array('radio', 'checkbox', 'select', 'rating', 'number'), true) && count($metric['text_responses']) < 5) {
                        $metric['text_responses'][] = is_array($value) ? implode(', ', $value) : $value;
                    }
                    if (isset($row['other_answers'][$id]) && count($metric['text_responses']) < 5) {
                        $metric['text_responses'][] = $row['other_answers'][$id];
                    }
                    unset($metric);
                }
            }
            $offset += count($rows);
        } while (count($rows) === 1000);
        foreach ($questions as &$metric) {
            foreach ($metric['_options'] as $label => $count) {
                $metric['options'][] = array('label' => (string) $label, 'count' => $count);
            }
            // Scaled accumulation avoids infinity when finite large numbers are
            // added; the normalized mean remains between -1 and 1.
            $mean = $metric['_numeric_count'] ? max(-1, min(1, $metric['_scaled_sum'] / $metric['_numeric_count'])) : 0;
            $metric['average'] = $metric['_numeric_count'] ? round($mean * $metric['_scale'], 2) : null;
            unset($metric['_options'], $metric['_scale'], $metric['_scaled_sum'], $metric['_numeric_count']);
        }
        unset($metric);
        ksort($daily);
        $days = array();
        foreach ($daily as $date => $count) {
            $days[] = array('date' => $date, 'count' => $count);
        }
        return array('total' => $total, 'daily' => $days, 'questions' => array_values($questions));
    }

    private static function empty_metric($question)
    {
        $choices = $question['type'] === 'rating' ? array('1', '2', '3', '4', '5') : $question['options'];
        return array(
            'id' => $question['id'], 'title' => $question['title'], 'type' => $question['type'],
            'responses' => 0, 'options' => array(), 'average' => null, 'text_responses' => array(),
            '_options' => array_fill_keys($choices, 0), '_scale' => 0.0, '_scaled_sum' => 0.0, '_numeric_count' => 0,
        );
    }

    private static function metric_key($question)
    {
        return $question['id'] . ':' . md5($question['title'] . '|' . $question['type']);
    }

    /** Neutralize spreadsheet formulas without altering stored submission text. */
    private static function csv_cell($value)
    {
        $value = (string) $value;
        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'" . $value : $value;
    }

    public static function export()
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to export submissions.', '', array('response' => 403));
        }
        check_admin_referer('soulmarke_export');
        $args = array();
        foreach (array('from', 'to', 'search') as $key) {
            $args[$key] = isset($_GET[$key]) && is_string($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
        }
        $columns = array();
        $offset = 0;
        do {
            $rows = self::submissions(array_merge($args, array('limit' => 1000, 'offset' => $offset)));
            foreach ($rows as $row) {
                foreach ($row['questions'] as $question) {
                    $key = self::metric_key($question);
                    if (!isset($columns[$key])) {
                        $columns[$key] = $question;
                    }
                }
            }
            $offset += count($rows);
        } while (count($rows) === 1000);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="soulmarke-submissions-' . gmdate('Y-m-d') . '.csv"');
        $stream = fopen('php://output', 'w');
        $headers = array('Submission ID', 'Submitted at (site time)', 'Notification status');
        foreach ($columns as $question) {
            $suffix = ' [' . $question['id'] . ' · ' . $question['type'] . ']';
            $headers[] = self::csv_cell($question['title'] . $suffix);
            $headers[] = self::csv_cell($question['title'] . ' — Other details' . $suffix);
        }
        fputcsv($stream, $headers, ',', '"', '');
        $offset = 0;
        do {
            $rows = self::submissions(array_merge($args, array('limit' => 1000, 'offset' => $offset)));
            foreach ($rows as $row) {
                $cells = array($row['id'], get_date_from_gmt($row['submitted_at']), $row['notification_status']);
                $versions = array();
                foreach ($row['questions'] as $snapshot) {
                    $versions[self::metric_key($snapshot)] = true;
                }
                foreach ($columns as $key => $question) {
                    $id = $question['id'];
                    $value = isset($versions[$key], $row['answers'][$id]) ? $row['answers'][$id] : '';
                    $cells[] = self::csv_cell(is_array($value) ? implode(' | ', $value) : $value);
                    $cells[] = self::csv_cell(isset($versions[$key], $row['other_answers'][$id]) ? $row['other_answers'][$id] : '');
                }
                fputcsv($stream, $cells, ',', '"', '');
            }
            $offset += count($rows);
        } while (count($rows) === 1000);
        fclose($stream);
        exit;
    }
}
