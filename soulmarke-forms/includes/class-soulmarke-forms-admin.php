<?php
/**
 * Private survey management screens.
 *
 * @package Soulmarke_Forms
 */

if (!defined('ABSPATH')) {
    exit;
}

class Soulmarke_Forms_Admin {
    const CAPABILITY = 'manage_options';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_post_soulmarke_save_settings', array(__CLASS__, 'save'));
        add_action('admin_post_soulmarke_delete_submission', array(__CLASS__, 'delete'));
    }

    public static function menu() {
        add_menu_page('Holistic Collective Forms', 'Holistic Collective Forms', self::CAPABILITY, 'soulmarke-forms', array(__CLASS__, 'dashboard'), 'dashicons-feedback', 26);
        add_submenu_page('soulmarke-forms', 'Analytics', 'Analytics', self::CAPABILITY, 'soulmarke-forms', array(__CLASS__, 'dashboard'));
        add_submenu_page('soulmarke-forms', 'Submissions', 'Submissions', self::CAPABILITY, 'soulmarke-submissions', array(__CLASS__, 'submissions'));
        add_submenu_page('soulmarke-forms', 'Compare submissions', 'Compare', self::CAPABILITY, 'soulmarke-compare', array(__CLASS__, 'compare'));
        add_submenu_page('soulmarke-forms', 'Questions & settings', 'Questions & settings', self::CAPABILITY, 'soulmarke-settings', array(__CLASS__, 'settings'));
    }

    public static function assets($hook) {
        if (strpos($hook, 'soulmarke') === false || !current_user_can(self::CAPABILITY)) {
            return;
        }
        wp_enqueue_style('soulmarke-admin', SOULMARKE_FORMS_URL . 'assets/admin.css', array(), SOULMARKE_FORMS_VERSION);
        wp_enqueue_script('soulmarke-admin', SOULMARKE_FORMS_URL . 'assets/admin.js', array(), SOULMARKE_FORMS_VERSION, true);
        wp_localize_script('soulmarke-admin', 'SoulmarkeAdmin', array(
            'removeQuestion' => 'Remove this question? Existing submissions will keep their original questions and answers.',
            'deleteSubmission' => 'Permanently delete this submission? This cannot be undone.',
            'comparisonLimit' => 'Choose up to three submissions to compare.',
        ));
    }

    private static function authorize() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage these submissions.', 'soulmarke-forms'), '', array('response' => 403));
        }
    }

    private static function url($page, $args = array()) {
        return add_query_arg(array_merge(array('page' => $page), $args), admin_url('admin.php'));
    }

    private static function date_filter($key) {
        $value = isset($_GET[$key]) && is_string($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return '';
        }
        return $value;
    }

    private static function filters() {
        return array(
            'from' => self::date_filter('from'),
            'to' => self::date_filter('to'),
            'search' => isset($_GET['search']) && is_string($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '',
        );
    }

    private static function timestamp($value) {
        $timestamp = strtotime((string) $value . ' UTC');
        return $timestamp ? wp_date(get_option('date_format') . ' · ' . get_option('time_format'), $timestamp) : (string) $value;
    }

    private static function header($active, $title, $description) {
        self::authorize();
        echo '<div class="wrap soulmarke-admin"><header class="sm-admin-header"><div><p class="sm-eyebrow">HOLISTIC COLLECTIVE · PRACTITIONER DISCOVERY</p><h1>' . esc_html($title) . '</h1><p>' . esc_html($description) . '</p></div><span class="sm-private"><span class="dashicons dashicons-lock" aria-hidden="true"></span> Private workspace</span></header>';
        echo '<nav class="sm-admin-tabs" aria-label="Survey administration">';
        $pages = array('soulmarke-forms' => 'Analytics', 'soulmarke-submissions' => 'Submissions', 'soulmarke-compare' => 'Compare', 'soulmarke-settings' => 'Questions & settings');
        foreach ($pages as $page => $label) {
            echo '<a href="' . esc_url(self::url($page)) . '"' . ($active === $page ? ' class="is-active" aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        self::notices();
    }

    private static function footer() {
        echo '<footer class="sm-admin-footer">Results are visible only to administrators. Dates and times use your WordPress site timezone.</footer></div>';
    }

    private static function notices() {
        $notice = isset($_GET['sm_notice']) && is_string($_GET['sm_notice']) ? sanitize_key(wp_unslash($_GET['sm_notice'])) : '';
        $messages = array('saved' => 'Your questions and settings have been saved.', 'deleted' => 'The submission was deleted.');
        if (isset($messages[$notice])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
        }
        if ($notice === 'error') {
            $message = get_transient('soulmarke_admin_error_' . get_current_user_id());
            delete_transient('soulmarke_admin_error_' . get_current_user_id());
            echo '<div class="notice notice-error"><p>' . esc_html(is_string($message) && $message !== '' ? $message : 'The action could not be completed. Please try again.') . '</p></div>';
        }
    }

    private static function error_redirect($message, $page) {
        set_transient('soulmarke_admin_error_' . get_current_user_id(), $message, 120);
        wp_safe_redirect(self::url($page, array('sm_notice' => 'error')));
        exit;
    }

    private static function filter_form($page, $filters) {
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="sm-filter-form"><input type="hidden" name="page" value="' . esc_attr($page) . '"><div class="sm-filter-fields">';
        echo '<label>From<input type="date" name="from" value="' . esc_attr($filters['from']) . '"></label><label>To<input type="date" name="to" value="' . esc_attr($filters['to']) . '"></label><label class="sm-filter-search">Search answers<input type="search" name="search" value="' . esc_attr($filters['search']) . '" placeholder="Name, email, or answer"></label><button type="submit" class="button button-primary">Apply filters</button><a class="button" href="' . esc_url(self::url($page)) . '">Clear</a></div></form>';
        if ($filters['from'] || $filters['to'] || $filters['search']) {
            $summary = array();
            if ($filters['from']) {
                $summary[] = 'from ' . $filters['from'];
            }
            if ($filters['to']) {
                $summary[] = 'through ' . $filters['to'];
            }
            if ($filters['search']) {
                $summary[] = 'answers matching “' . $filters['search'] . '”';
            }
            echo '<p class="sm-filter-summary">Showing submissions ' . esc_html(implode(', ', $summary)) . '. Date filters use the WordPress site timezone (' . esc_html(wp_timezone_string()) . ').</p>';
        }
    }

    private static function export_link($filters) {
        $url = wp_nonce_url(add_query_arg(array_merge(array('action' => 'soulmarke_export'), $filters), admin_url('admin-post.php')), 'soulmarke_export');
        echo '<a class="button sm-export" href="' . esc_url($url) . '"><span class="dashicons dashicons-download" aria-hidden="true"></span> Export CSV</a>';
    }

    private static function empty_state($title, $text, $link = '') {
        echo '<div class="sm-empty"><span class="dashicons dashicons-feedback" aria-hidden="true"></span><h2>' . esc_html($title) . '</h2><p>' . esc_html($text) . '</p>';
        if ($link) {
            echo '<a class="button button-primary" href="' . esc_url($link) . '">Questions & settings</a>';
        }
        echo '</div>';
    }

    public static function dashboard() {
        self::header('soulmarke-forms', 'Your collective, at a glance', 'Explore practitioner responses and spot the patterns that matter.');
        $filters = self::filters();
        self::filter_form('soulmarke-forms', $filters);
        $analytics = Soulmarke_Forms::analytics($filters);
        $total = isset($analytics['total']) ? (int) $analytics['total'] : 0;
        $settings = Soulmarke_Forms::settings();
        echo '<div class="sm-stat-grid"><div class="sm-stat"><span>Total submissions' . (($filters['from'] || $filters['to'] || $filters['search']) ? ' · filtered' : '') . '</span><strong>' . esc_html(number_format_i18n($total)) . '</strong></div><div class="sm-stat"><span>Current survey questions</span><strong>' . esc_html(number_format_i18n(count($settings['questions']))) . '</strong></div><div class="sm-stat sm-stat-guide"><span>Share your survey</span><code>[soulmarke_form]</code><p>Add this shortcode to a WordPress page.</p></div></div>';
        echo '<div class="sm-section-heading"><h2>Submission activity</h2>';
        self::export_link($filters);
        echo '</div>';
        if (!$total) {
            self::empty_state('No submissions yet', ($filters['from'] || $filters['to'] || $filters['search']) ? 'No submissions match these filters. Try a wider date range or clear your search.' : 'Publish a page with [soulmarke_form] to start collecting responses.', self::url('soulmarke-settings'));
            self::footer();
            return;
        }
        $daily = isset($analytics['daily']) && is_array($analytics['daily']) ? $analytics['daily'] : array();
        $daily = array_slice($daily, -30);
        if ($daily) {
            $max = max(1, max(array_map(function ($day) { return (int) $day['count']; }, $daily)));
            echo '<section class="sm-card sm-activity" aria-label="Daily submission counts"><p class="sm-muted">Most recent ' . esc_html(count($daily)) . ' ' . (count($daily) === 1 ? 'day' : 'days') . ' in this result set · ' . esc_html(wp_timezone_string()) . '</p><div class="sm-daily-chart">';
            foreach ($daily as $day) {
                $count = max(0, (int) $day['count']);
                $height = max(2, (int) round(($count / $max) * 100));
                echo '<div class="sm-daily-column"><span class="sm-daily-count">' . esc_html($count) . '</span><div class="sm-daily-track"><span class="sm-daily-bar" style="height:' . esc_attr($height) . '%" aria-label="' . esc_attr($day['date'] . ': ' . $count . ' submissions') . '"></span></div><span class="sm-daily-date">' . esc_html(substr($day['date'], 5)) . '</span></div>';
            }
            echo '</div></section>';
        }
        echo '<div class="sm-section-heading"><h2>Question insights</h2><span class="sm-muted">Counts use submitted answers; skipped questions are excluded.</span></div><div class="sm-insights-grid">';
        foreach ((array) $analytics['questions'] as $question) {
            echo '<section class="sm-card sm-question-insight"><div class="sm-insight-heading"><h3>' . esc_html($question['title']) . '</h3><span class="sm-response-count">' . esc_html(number_format_i18n((int) $question['responses'])) . ' ' . ((int) $question['responses'] === 1 ? 'response' : 'responses') . '</span></div>';
            if (isset($question['average']) && $question['average'] !== null) {
                echo '<p class="sm-average"><strong>' . esc_html(number_format_i18n((float) $question['average'], 2)) . '</strong> average' . ($question['type'] === 'rating' ? ' out of 5' : '') . '</p>';
            }
            if (!empty($question['options'])) {
                foreach ($question['options'] as $option) {
                    $percentage = (int) $question['responses'] > 0 ? min(100, round(((int) $option['count'] / (int) $question['responses']) * 100)) : 0;
                    echo '<div class="sm-distribution"><div><span>' . esc_html($option['label']) . '</span><strong>' . esc_html(number_format_i18n((int) $option['count'])) . ' <span class="sm-muted">(' . esc_html($percentage) . '%)</span></strong></div><div class="sm-meter"><span style="width:' . esc_attr($percentage) . '%"></span></div></div>';
                }
                if ($question['type'] === 'checkbox') {
                    echo '<p class="sm-muted">Participants may select multiple answers.</p>';
                }
            }
            if (!empty($question['text_responses'])) {
                echo '<p class="sm-muted">' . (!empty($question['options']) ? 'Recent extra detail samples' : 'Recent response samples') . '</p><ul class="sm-text-samples">';
                foreach ($question['text_responses'] as $answer) {
                    echo '<li>' . esc_html($answer) . '</li>';
                }
                echo '</ul>';
            } elseif (empty($question['options']) && !(int) $question['responses']) {
                echo '<p class="sm-muted">This question has no answers in the selected submissions.</p>';
            }
            echo '</section>';
        }
        echo '</div><p class="sm-muted">Submissions retain the question wording used when they were completed. Renamed questions may appear as separate insights when their wording differs.</p>';
        self::footer();
    }

    private static function respondent($record) {
        foreach ((array) $record['questions'] as $question) {
            $value = isset($record['answers'][$question['id']]) ? $record['answers'][$question['id']] : '';
            if ($question['type'] === 'email' && is_string($value) && $value !== '') {
                return $value;
            }
        }
        return 'Submission #' . (int) $record['id'];
    }

    private static function answer($value) {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
        return is_scalar($value) ? (string) $value : '';
    }

    private static function record_answer($record, $id) {
        $value = isset($record['answers'][$id]) ? self::answer($record['answers'][$id]) : '';
        $other = isset($record['other_answers'][$id]) ? self::answer($record['other_answers'][$id]) : '';
        return $value . ($other !== '' ? "\nOther detail: " . $other : '');
    }

    private static function status($status) {
        $labels = array('sent' => 'Email accepted for delivery', 'failed' => 'Email delivery failed', 'not_configured' => 'Email not configured');
        $status = isset($labels[$status]) ? $status : 'not_configured';
        return '<span class="sm-status sm-status-' . esc_attr($status) . '">' . esc_html($labels[$status]) . '</span>';
    }

    public static function submissions() {
        self::header('soulmarke-submissions', 'Practitioner submissions', 'Read each response privately, compare perspectives, and export your results.');
        $id = isset($_GET['submission']) ? absint($_GET['submission']) : 0;
        if ($id) {
            self::submission_detail($id);
            self::footer();
            return;
        }
        $filters = self::filters();
        self::filter_form('soulmarke-submissions', $filters);
        $page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $per_page = 20;
        $count = Soulmarke_Forms::count_submissions($filters);
        $pages = max(1, (int) ceil($count / $per_page));
        $page = min($page, $pages);
        $records = Soulmarke_Forms::submissions(array_merge($filters, array('limit' => $per_page, 'offset' => ($page - 1) * $per_page)));
        echo '<div class="sm-section-heading"><h2>' . esc_html(number_format_i18n($count)) . ' ' . ((int) $count === 1 ? 'submission' : 'submissions') . '</h2>';
        self::export_link($filters);
        echo '</div>';
        if (!$records) {
            self::empty_state('No submissions found', $count ? 'This page has no submissions.' : 'Share a page containing [soulmarke_form], or clear the filters to see more responses.');
            self::footer();
            return;
        }
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="sm-comparison-form"><input type="hidden" name="page" value="soulmarke-compare"><div class="sm-table-wrap"><table class="widefat sm-submissions-table"><thead><tr><th scope="col"><span class="screen-reader-text">Select to compare</span></th><th scope="col">Submission</th><th scope="col">Received</th><th scope="col">Notifications</th><th scope="col">Details</th></tr></thead><tbody>';
        foreach ($records as $record) {
            $record_id = (int) $record['id'];
            echo '<tr><td><input class="sm-compare-check" type="checkbox" name="ids[]" value="' . esc_attr($record_id) . '" aria-label="' . esc_attr('Compare submission #' . $record_id) . '"></td><td><strong>' . esc_html(self::respondent($record)) . '</strong><span class="sm-row-id">#' . esc_html($record_id) . '</span></td><td>' . esc_html(self::timestamp($record['submitted_at'])) . '</td><td>' . self::status($record['notification_status']) . '</td><td><a class="button" href="' . esc_url(self::url('soulmarke-submissions', array('submission' => $record_id))) . '">View response</a></td></tr>';
        }
        echo '</tbody></table></div><div class="sm-compare-toolbar"><button type="submit" class="button button-primary">Compare selected</button><span class="sm-muted" data-compare-count>Choose up to three submissions.</span><span class="sm-form-feedback" role="status" aria-live="polite"></span></div></form>';
        if ($pages > 1) {
            echo '<nav class="sm-pagination" aria-label="Submission pages">';
            if ($page > 1) {
                echo '<a class="button" href="' . esc_url(self::url('soulmarke-submissions', array_merge($filters, array('paged' => $page - 1)))) . '">Previous</a>';
            }
            echo '<span>Page ' . esc_html($page) . ' of ' . esc_html($pages) . '</span>';
            if ($page < $pages) {
                echo '<a class="button" href="' . esc_url(self::url('soulmarke-submissions', array_merge($filters, array('paged' => $page + 1)))) . '">Next</a>';
            }
            echo '</nav>';
        }
        self::footer();
    }

    private static function submission_detail($id) {
        $record = Soulmarke_Forms::get_submission($id);
        echo '<p><a class="sm-back-link" href="' . esc_url(self::url('soulmarke-submissions')) . '">← All submissions</a></p>';
        if (!$record) {
            self::empty_state('Submission unavailable', 'This submission may have been deleted.');
            return;
        }
        echo '<section class="sm-card sm-detail-header"><div><p class="sm-eyebrow">SUBMISSION #' . esc_html($record['id']) . '</p><h2>' . esc_html(self::respondent($record)) . '</h2><p>Received ' . esc_html(self::timestamp($record['submitted_at'])) . '</p></div>' . self::status($record['notification_status']) . '</section><section class="sm-card sm-answers"><h2>Original questions & answers</h2><dl>';
        foreach ((array) $record['questions'] as $question) {
            $value = self::record_answer($record, $question['id']);
            echo '<div class="sm-answer-row"><dt>' . esc_html($question['title']) . '</dt><dd class="' . ($value === '' ? 'sm-muted' : '') . '">' . esc_html($value !== '' ? $value : 'No answer provided') . '</dd></div>';
        }
        echo '</dl></section><div class="sm-detail-actions"><a class="button" href="' . esc_url(self::url('soulmarke-compare', array('ids' => array($id)))) . '">Compare this response</a><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="sm-delete-form"><input type="hidden" name="action" value="soulmarke_delete_submission"><input type="hidden" name="submission_id" value="' . esc_attr($id) . '">';
        wp_nonce_field('soulmarke_delete_submission_' . $id);
        echo '<button type="submit" class="button sm-danger-button">Delete submission</button></form></div><p class="sm-muted">“Email accepted for delivery” means WordPress accepted the notification; it does not confirm arrival in the recipient’s inbox.</p>';
    }

    private static function comparison_ids() {
        $raw = isset($_GET['ids']) ? wp_unslash($_GET['ids']) : array();
        if (!is_array($raw)) {
            $raw = array($raw);
        }
        return array_slice(array_values(array_unique(array_filter(array_map('absint', $raw)))), 0, 3);
    }

    private static function canonical_answer($answers, $id, $other_answers = array()) {
        if (!array_key_exists($id, $answers)) {
            return 'missing';
        }
        $value = $answers[$id];
        if (is_array($value)) {
            $value = array_map('strval', $value);
            sort($value, SORT_STRING);
        }
        return wp_json_encode(array($value, isset($other_answers[$id]) ? (string) $other_answers[$id] : ''));
    }

    public static function compare() {
        self::header('soulmarke-compare', 'Compare perspectives', 'View up to three practitioner responses side by side. Different answers are highlighted.');
        $ids = self::comparison_ids();
        $records = array();
        foreach ($ids as $id) {
            $record = Soulmarke_Forms::get_submission($id);
            if ($record) {
                $records[] = $record;
            }
        }
        $choices = Soulmarke_Forms::submissions(array('limit' => 100));
        echo '<section class="sm-card sm-comparison-picker"><h2>Choose submissions</h2><form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="sm-comparison-form"><input type="hidden" name="page" value="soulmarke-compare"><div class="sm-picker-grid">';
        for ($index = 0; $index < 3; $index++) {
            $selected = isset($records[$index]) ? (int) $records[$index]['id'] : 0;
            $options = $choices;
            if ($selected && !in_array($selected, array_map(function ($choice) { return (int) $choice['id']; }, $options), true)) {
                array_unshift($options, $records[$index]);
            }
            echo '<label>Response ' . esc_html($index + 1) . '<select name="ids[]"><option value="">Choose a submission</option>';
            foreach ($options as $choice) {
                echo '<option value="' . esc_attr($choice['id']) . '"' . selected($selected, (int) $choice['id'], false) . '>' . esc_html('#' . $choice['id'] . ' · ' . self::respondent($choice) . ' · ' . self::timestamp($choice['submitted_at'])) . '</option>';
            }
            echo '</select></label>';
        }
        echo '</div><p class="sm-muted">The picker lists the 100 most recent submissions. Select older submissions from the submissions list.</p><button type="submit" class="button button-primary">Compare responses</button></form></section>';
        if (count($records) < 2) {
            self::empty_state('Choose two or three responses', 'Select submissions above to see common answers and differences.');
            self::footer();
            return;
        }
        $questions = array();
        $snapshots = array();
        foreach ($records as $record) {
            $map = array();
            foreach ((array) $record['questions'] as $question) {
                if (!isset($questions[$question['id']])) {
                    $questions[$question['id']] = $question;
                }
                $map[$question['id']] = $question;
            }
            $snapshots[(int) $record['id']] = $map;
        }
        echo '<div class="sm-table-wrap"><table class="widefat sm-comparison-table"><thead><tr><th scope="col">Question</th>';
        foreach ($records as $record) {
            echo '<th scope="col"><a href="' . esc_url(self::url('soulmarke-submissions', array('submission' => $record['id']))) . '">' . esc_html(self::respondent($record)) . '</a><span class="sm-row-id">#' . esc_html($record['id']) . ' · ' . esc_html(self::timestamp($record['submitted_at'])) . '</span></th>';
        }
        echo '</tr></thead><tbody>';
        $difference_count = 0;
        foreach ($questions as $id => $question) {
            $answers = array();
            foreach ($records as $record) {
                $answers[] = self::canonical_answer($record['answers'], $id, isset($record['other_answers']) ? $record['other_answers'] : array());
            }
            $different = count(array_unique($answers)) > 1;
            if ($different) {
                $difference_count++;
            }
            echo '<tr' . ($different ? ' class="sm-different"' : '') . '><th scope="row">' . esc_html($question['title']) . ($different ? '<span class="sm-difference-label">Different answers</span>' : '') . '</th>';
            foreach ($records as $record) {
                $map = $snapshots[(int) $record['id']];
                echo '<td>';
                if (!isset($map[$id])) {
                    echo '<span class="sm-muted">Question was not in this submission</span>';
                } else {
                    if ($map[$id]['title'] !== $question['title']) {
                        echo '<span class="sm-snapshot-label">Original wording: ' . esc_html($map[$id]['title']) . '</span>';
                    }
                    $answer = self::record_answer($record, $id);
                    echo '<span class="sm-comparison-answer' . ($answer === '' ? ' sm-muted' : '') . '">' . esc_html($answer !== '' ? $answer : 'No answer provided') . '</span>';
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div><p class="sm-muted">' . esc_html($difference_count) . ' of ' . esc_html(count($questions)) . ' questions have different answers. Question wording is preserved from each original submission.</p>';
        self::footer();
    }

    private static function field($name, $label, $value, $type = 'text', $help = '') {
        echo '<label class="sm-field"><span>' . esc_html($label) . '</span>';
        if ($type === 'textarea') {
            echo '<textarea name="settings[' . esc_attr($name) . ']" rows="3">' . esc_textarea($value) . '</textarea>';
        } else {
            echo '<input type="' . esc_attr($type) . '" name="settings[' . esc_attr($name) . ']" value="' . esc_attr($value) . '">';
        }
        if ($help) {
            echo '<small>' . esc_html($help) . '</small>';
        }
        echo '</label>';
    }

    private static function question_editor($question, $index) {
        $prefix = 'settings[questions][' . $index . ']';
        echo '<article class="sm-question-editor" data-question><div class="sm-question-toolbar"><span class="sm-question-number">Question ' . esc_html(is_numeric($index) ? (int) $index + 1 : '') . '</span><div><button type="button" class="button sm-move-up" aria-label="Move question up">↑</button><button type="button" class="button sm-move-down" aria-label="Move question down">↓</button><button type="button" class="button sm-remove-question">Remove</button></div></div><input type="hidden" data-field="id" name="' . esc_attr($prefix . '[id]') . '" value="' . esc_attr($question['id']) . '"><label class="sm-field"><span>Question text</span><input type="text" data-field="title" name="' . esc_attr($prefix . '[title]') . '" value="' . esc_attr($question['title']) . '" required maxlength="500"></label><label class="sm-field"><span>Description <span class="sm-muted">(optional)</span></span><textarea data-field="description" name="' . esc_attr($prefix . '[description]') . '" rows="2">' . esc_textarea($question['description']) . '</textarea></label><div class="sm-question-properties"><label class="sm-field"><span>Answer format</span><select data-field="type" name="' . esc_attr($prefix . '[type]') . '">';
        $types = array('text' => 'Short text', 'textarea' => 'Long text', 'email' => 'Email address', 'number' => 'Number', 'radio' => 'Choose one · buttons', 'checkbox' => 'Choose multiple', 'select' => 'Choose one · dropdown', 'rating' => 'Rating · 1 to 5');
        foreach ($types as $type => $label) {
            echo '<option value="' . esc_attr($type) . '"' . selected($question['type'], $type, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label><label class="sm-required"><input type="checkbox" data-field="required" name="' . esc_attr($prefix . '[required]') . '" value="1"' . checked(!empty($question['required']), true, false) . '> Required answer</label></div><div class="sm-options-field"' . (!in_array($question['type'], array('radio', 'checkbox', 'select'), true) ? ' hidden' : '') . '><label class="sm-field"><span>Answer choices</span><textarea data-field="options" name="' . esc_attr($prefix . '[options]') . '" rows="4">' . esc_textarea(implode("\n", (array) $question['options'])) . '</textarea><small>One choice per line. Changing choices affects future submissions.</small></label><label class="sm-field"><span>Choice that requests extra detail <span class="sm-muted">(optional)</span></span><input type="text" data-field="other_option" name="' . esc_attr($prefix . '[other_option]') . '" value="' . esc_attr(isset($question['other_option']) ? $question['other_option'] : '') . '"><small>Enter the exact choice label, such as “Other”, to show a follow-up text field when it is selected.</small></label></div><label class="sm-field sm-max-selections"' . ($question['type'] !== 'checkbox' ? ' hidden' : '') . '><span>Maximum selections</span><input type="number" min="0" max="100" data-field="max_selections" name="' . esc_attr($prefix . '[max_selections]') . '" value="' . esc_attr(isset($question['max_selections']) ? absint($question['max_selections']) : 0) . '"><small>Use 0 to allow any number of choices.</small></label><p class="sm-rating-help sm-muted"' . ($question['type'] !== 'rating' ? ' hidden' : '') . '>Participants choose a whole-number rating from 1 to 5.</p></article>';
    }

    public static function settings() {
        self::header('soulmarke-settings', 'Shape your survey', 'Edit your questions, introduction, and notification recipients. Changes apply to future submissions.');
        $settings = Soulmarke_Forms::settings();
        $pending = get_transient('soulmarke_admin_draft_' . get_current_user_id());
        if (is_array($pending)) {
            $settings = array_merge($settings, $pending);
            delete_transient('soulmarke_admin_draft_' . get_current_user_id());
        }
        echo '<section class="sm-card sm-shortcode-guide"><div><h2>Put the form on your website</h2><p>Add a Shortcode block to any WordPress page and paste <code>[soulmarke_form]</code>. Results stay private in this admin area.</p></div><span class="dashicons dashicons-editor-code" aria-hidden="true"></span></section><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="sm-settings-form"><input type="hidden" name="action" value="soulmarke_save_settings">';
        wp_nonce_field('soulmarke_save_settings');
        echo '<section class="sm-card sm-settings-section"><h2>Welcome & completion</h2>';
        self::field('title', 'Form title', $settings['title']);
        self::field('description', 'Welcome description', $settings['description'], 'textarea');
        self::field('success_message', 'Message after submitting', $settings['success_message'], 'textarea');
        echo '</section><section class="sm-card sm-settings-section"><h2>Email notifications</h2>';
        self::field('recipients', 'Send submissions to', implode("\n", (array) $settings['recipients']), 'textarea', 'One email address per line, or separate addresses with commas. Leave blank to disable email notifications. Configure WordPress email delivery for reliable notifications.');
        echo '</section><div class="sm-section-heading"><div><h2>Survey questions</h2><p class="sm-muted">Reorder with the arrows. Original questions and answers stay attached to existing submissions.</p></div><button type="button" class="button" id="sm-add-question">+ Add question</button></div><div id="sm-question-list">';
        foreach ($settings['questions'] as $index => $question) {
            self::question_editor($question, $index);
        }
        echo '</div><template id="sm-question-template">';
        self::question_editor(array('id' => '', 'title' => '', 'description' => '', 'type' => 'text', 'required' => false, 'options' => array(), 'max_selections' => 0, 'other_option' => ''), '__INDEX__');
        echo '</template><div class="sm-save-bar"><p>Save changes when you’re ready. Your edits are not saved automatically.</p><button type="submit" class="button button-primary button-hero">Save questions & settings</button><span class="sm-editor-status" role="status" aria-live="polite"></span></div></form>';
        self::footer();
    }

    public static function save() {
        self::authorize();
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die(esc_html__('This action requires a POST request.', 'soulmarke-forms'), '', array('response' => 405));
        }
        check_admin_referer('soulmarke_save_settings');
        $raw = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : array();
        $raw['recipients'] = isset($raw['recipients']) && is_string($raw['recipients']) ? preg_split('/[\r\n,]+/', $raw['recipients'], -1, PREG_SPLIT_NO_EMPTY) : array();
        if (isset($raw['questions']) && is_array($raw['questions'])) {
            foreach ($raw['questions'] as &$question) {
                if (!is_array($question)) {
                    continue;
                }
                $question['required'] = !empty($question['required']);
                $question['options'] = isset($question['options']) && is_string($question['options']) ? preg_split('/\r\n|\r|\n/', $question['options'], -1, PREG_SPLIT_NO_EMPTY) : array();
            }
            unset($question);
        }
        $settings = Soulmarke_Forms::normalize_settings($raw);
        if (is_wp_error($settings)) {
            // Preserve only sanitized form fields so validation does not discard edits.
            $draft = array();
            foreach (array('title', 'description', 'success_message') as $key) {
                $draft[$key] = isset($raw[$key]) && is_scalar($raw[$key]) ? sanitize_textarea_field($raw[$key]) : '';
            }
            $draft['recipients'] = array_map('sanitize_text_field', $raw['recipients']);
            $draft['questions'] = array();
            foreach (isset($raw['questions']) && is_array($raw['questions']) ? $raw['questions'] : array() as $question) {
                if (!is_array($question)) {
                    continue;
                }
                $draft['questions'][] = array(
                    'id' => isset($question['id']) && is_scalar($question['id']) ? sanitize_key($question['id']) : '',
                    'title' => isset($question['title']) && is_scalar($question['title']) ? sanitize_text_field($question['title']) : '',
                    'description' => isset($question['description']) && is_scalar($question['description']) ? sanitize_textarea_field($question['description']) : '',
                    'type' => isset($question['type']) && is_scalar($question['type']) && in_array($question['type'], array('text', 'textarea', 'email', 'number', 'radio', 'checkbox', 'select', 'rating'), true) ? $question['type'] : 'text',
                    'required' => !empty($question['required']),
                    'options' => array_map('sanitize_text_field', isset($question['options']) && is_array($question['options']) ? array_filter($question['options'], 'is_scalar') : array()),
                    'max_selections' => isset($question['max_selections']) ? absint($question['max_selections']) : 0,
                    'other_option' => isset($question['other_option']) && is_scalar($question['other_option']) ? sanitize_text_field($question['other_option']) : '',
                );
            }
            set_transient('soulmarke_admin_draft_' . get_current_user_id(), $draft, 300);
            self::error_redirect($settings->get_error_message(), 'soulmarke-settings');
        }
        $saved = Soulmarke_Forms::save_settings($settings);
        if (!$saved && Soulmarke_Forms::settings() !== $settings) {
            self::error_redirect('Your settings could not be saved. Please check your WordPress database connection and try again.', 'soulmarke-settings');
        }
        delete_transient('soulmarke_admin_draft_' . get_current_user_id());
        wp_safe_redirect(self::url('soulmarke-settings', array('sm_notice' => 'saved')));
        exit;
    }

    public static function delete() {
        self::authorize();
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die(esc_html__('This action requires a POST request.', 'soulmarke-forms'), '', array('response' => 405));
        }
        $id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
        check_admin_referer('soulmarke_delete_submission_' . $id);
        if (!$id || !Soulmarke_Forms::delete_submission($id)) {
            self::error_redirect('The submission could not be deleted. It may already have been removed.', 'soulmarke-submissions');
        }
        wp_safe_redirect(self::url('soulmarke-submissions', array('sm_notice' => 'deleted')));
        exit;
    }
}
