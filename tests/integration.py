#!/usr/bin/env python3
"""Exercise public and private WordPress workflows against an activated plugin.

Requires the local Docker WordPress runtime and its mail-interception MU plugin.
No external email is sent by the local runtime. Each run restores settings and
timezone, and removes only its own marked submissions and subscriber account.
Use --allow-settings-changes after other browser checks have finished.
"""

import argparse
import base64
import copy
import csv
import datetime
import http.cookiejar
import io
import json
import math
import re
import secrets
import shlex
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import zoneinfo
from html.parser import HTMLParser
from pathlib import Path


class Document(HTMLParser):
    def __init__(self, html):
        super().__init__(convert_charrefs=True)
        self.config = None
        self.inputs = {}
        self.links = []
        self.question_ids = []
        self.different_rows = 0
        self._config = False
        self._json = []
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        classes = attrs.get('class', '').split()
        if tag == 'script' and 'smf-config' in classes:
            self._config = True
        if tag == 'input' and 'name' in attrs:
            self.inputs[attrs['name']] = attrs.get('value', '')
        if tag == 'a' and 'href' in attrs:
            self.links.append(attrs['href'])
        if tag == 'fieldset' and 'data-question-id' in attrs:
            self.question_ids.append(attrs['data-question-id'])
        if tag == 'tr' and 'sm-different' in classes:
            self.different_rows += 1

    def handle_endtag(self, tag):
        if tag == 'script' and self._config:
            self.config = json.loads(''.join(self._json))
            self._config = False

    def handle_data(self, text):
        if self._config:
            self._json.append(text)


class Client:
    def __init__(self, base):
        self.base = base.rstrip('/')
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self, path, fields=None):
        url = path if path.startswith('http') else self.base + path
        data = None if fields is None else urllib.parse.urlencode(fields, doseq=True).encode()
        try:
            response = self.opener.open(urllib.request.Request(url, data=data), timeout=25)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.read().decode('utf-8', errors='replace'), dict(response.headers), response.url

    def login(self, username, password):
        self.request('/wp-login.php')
        status, html, _, url = self.request('/wp-login.php', {
            'log': username, 'pwd': password, 'wp-submit': 'Log In',
            'redirect_to': self.base + '/wp-admin/', 'testcookie': '1',
        })
        if status != 200 or 'wp-login.php' in url:
            raise RuntimeError('WordPress login did not reach the admin area.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', default='http://127.0.0.1:8080')
    parser.add_argument('--wp-container', default='soulmarke-wp')
    parser.add_argument('--page', default='/?p=4')
    parser.add_argument('--admin-user', default='soulmarke_admin')
    parser.add_argument('--secrets-file', default='/workspace/.soulmarke-dev/secrets.env')
    parser.add_argument('--allow-settings-changes', action='store_true')
    options = parser.parse_args()
    run = 'qa_' + secrets.token_hex(10)
    helper_path = '/tmp/soulmarke-' + run + '.php'
    count = 0
    begun = False

    def command(parts):
        result = subprocess.run(parts, text=True, capture_output=True, check=False)
        if result.returncode:
            raise RuntimeError('Local WordPress fixture command failed: ' + result.stderr.strip()[:600])
        return result.stdout

    def helper(action, **kwargs):
        payload = base64.b64encode(json.dumps(dict(run=run, action=action, **kwargs)).encode()).decode()
        out = command(['docker', 'exec', '--user', 'www-data', options.wp_container,
                       'wp', '--path=/var/www/html', '--user=' + options.admin_user,
                       'eval-file', helper_path, payload])
        return json.loads(out or '{}')

    def check(condition, label):
        nonlocal count
        if not condition:
            raise AssertionError(label)
        count += 1
        print('PASS ' + label, flush=True)

    def mail():
        result = command(['docker', 'exec', options.wp_container, 'sh', '-c',
                          'if [ -f /tmp/soulmarke-local-mail.jsonl ]; then cat /tmp/soulmarke-local-mail.jsonl; fi'])
        return [json.loads(line) for line in result.splitlines() if line.strip()]

    anonymous = Client(options.base_url)
    admin = Client(options.base_url)
    subscriber = Client(options.base_url)
    try:
        command(['docker', 'cp', str(Path(__file__).with_suffix('.php')), options.wp_container + ':' + helper_path])
        command(['docker', 'exec', options.wp_container, 'chmod', '644', helper_path])
        secret_lines = Path(options.secrets_file).read_text().splitlines()
        bindings = {line.split('=', 1)[0]: shlex.split(line.split('=', 1)[1])[0]
                    for line in secret_lines if '=' in line and not line.startswith('#')}
        admin.login(options.admin_user, bindings['WP_ADMIN_PASSWORD'])
        del bindings, secret_lines
        subscriber_password = secrets.token_urlsafe(24)
        settings = helper('begin', password=subscriber_password)['settings']
        begun = True
        subscriber.login(run, subscriber_password)
        del subscriber_password

        status, page, _, _ = anonymous.request(options.page)
        public = Document(page)
        check(status == 200 and public.config is not None, 'Public shortcode renders usable submission configuration')
        check(len(public.question_ids) == 14 and len(set(public.question_ids)) == 14,
              'Public survey contains the 14 supplied questions with stable identifiers')
        check(not any(address.lower() in page.lower() for address in settings['recipients']),
              'Public form does not disclose notification recipients')
        nonce = public.config['nonce']
        endpoint = public.config['endpoint']
        questions = {question['id']: question for question in settings['questions']}
        answers = {}
        for question in settings['questions']:
            answers[question['id']] = (question['options'][:2] if question['type'] == 'checkbox'
                                      else question['options'][0] if question['options'] else '=1+2 ' + run)
        human_search_terms = ('José practices Reiki', '"calm healing"', r'C:\holistic\practice')
        # Cover an optional blank and preserve real respondent text, including
        # Unicode, quotes, backslashes and line breaks, through HTTP and email.
        answers['q_02'] = ''
        answers['q_14'] += ' · ' + ' · '.join(human_search_terms) + '\nSecond line: "whole person" and C:\\care\\notes'

        def notification_messages(submission_id):
            return [item for item in mail()
                    if re.search(r'#' + str(submission_id) + r'\b', item['subject'])
                    and item.get('capture_run') == run]

        def complete_results(messages, record, form_title):
            # Test the actual outgoing wp_mail payload, rather than calling or
            # mirroring the notification formatter. Never print private bodies.
            zone_name = probe_state['site_timezone']
            offset = re.fullmatch(r'([+-])(\d{2}):(\d{2})', zone_name)
            if offset:
                minutes = (int(offset[2]) * 60 + int(offset[3])) * (-1 if offset[1] == '-' else 1)
                site_zone = datetime.timezone(datetime.timedelta(minutes=minutes))
            else:
                site_zone = zoneinfo.ZoneInfo(zone_name)
            stamp = datetime.datetime.fromisoformat(record['submitted_at']).replace(tzinfo=datetime.timezone.utc).astimezone(site_zone)
            expected_time = (stamp.strftime('%B ') + str(stamp.day) + stamp.strftime(', %Y ')
                             + str(stamp.hour % 12 or 12) + stamp.strftime(':%M ')
                             + ('am' if stamp.hour < 12 else 'pm') + ' (' + zone_name + ')')
            for message in messages:
                body = message.get('message', '')
                if not body.startswith(form_title + '\n') or 'Submission #' + str(record['id']) not in body:
                    return False
                if 'Submitted: ' + expected_time not in body or '/wp-admin/admin.php?page=soulmarke-submissions' not in body:
                    return False
                if 'submission=' + str(record['id']) not in body:
                    return False
                for index, question in enumerate(record['questions']):
                    start = str(index + 1) + '. ' + question['title'] + '\n'
                    if start not in body:
                        return False
                    section = body.split(start, 1)[1]
                    if index + 1 < len(record['questions']):
                        next_question = record['questions'][index + 1]
                        section = section.split(str(index + 2) + '. ' + next_question['title'] + '\n', 1)[0]
                    value = record['answers'].get(question['id'], '')
                    if not value:
                        if 'Answer: No answer provided' not in section:
                            return False
                    elif isinstance(value, list):
                        if 'Answers:\n' not in section or not all('\n- ' + option + '\n' in '\n' + section for option in value):
                            return False
                    elif 'Answer: ' + str(value) not in section:
                        return False
                    other_values = record['other_answers'] if isinstance(record['other_answers'], dict) else {}
                    other = other_values.get(question['id'])
                    if other and 'Additional detail: ' + other not in section:
                        return False
            return bool(messages)

        probe_state = helper('mail_probe')
        probes = [item for item in mail() if item['subject'] == 'Local integration capture probe ' + run]
        check(len(probes) == 1 and 'message' not in probes[0] and 'capture_run' not in probes[0],
              'Opt-in local capture excludes unrelated mail bodies even during an active integration run')
        check(probe_state['mail_log_private'], 'Local mail capture remains private outside the web root with mode 0600')

        def submit(values=None, others=None, override=None, client=anonymous):
            fields = {'action': 'soulmarke_submit', 'nonce': nonce, 'website': '',
                      'answers': json.dumps(answers if values is None else values),
                      'other_answers': json.dumps(others or {})}
            fields.update(override or {})
            return client.request(endpoint, fields)

        for token in ('', 'invalid'):
            status, body, _, _ = submit(override={'nonce': token})
            check(status == 403 and json.loads(body)['success'] is False,
                  'Submission rejects ' + ('missing' if not token else 'invalid') + ' nonce')
        invalid = copy.deepcopy(answers)
        invalid['q_01'] = 'A forged answer outside the editor choices'
        status, body, _, _ = submit(invalid)
        check(status == 422 and 'q_01' in json.loads(body)['data']['errors'], 'Server rejects forged choice values')
        invalid = copy.deepcopy(answers)
        invalid['q_10'] = questions['q_10']['options'][:4]
        status, body, _, _ = submit(invalid)
        check(status == 422 and 'q_10' in json.loads(body)['data']['errors'], 'Server enforces question 10 maximum of three selections')
        invalid = copy.deepcopy(answers)
        invalid['q_01'] = questions['q_01']['other_option']
        status, body, _, _ = submit(invalid)
        check(status == 422 and 'q_01' in json.loads(body)['data']['errors'], 'Other choice requires explanatory text')
        status, _, _, _ = submit(override={'website': 'spam.example'})
        check(status == 400, 'Populated honeypot is rejected')
        status, _, _, _ = submit(override={'answers': 'malformed-json'})
        check(status == 422, 'Malformed answer JSON is rejected')
        check(helper('state')['records'] == [], 'Rejected requests did not create submissions')

        status, body, _, _ = submit()
        check(status == 200 and json.loads(body)['success'] is True, 'Anonymous respondent can submit valid answers')
        state = helper('state')
        first = state['records'][0]
        check(len(state['records']) == 1 and first['answers'] == answers and first['notification_status'] == 'sent',
              'Submission persists all answers with notification delivery status')
        notifications = [item for item in mail() if re.search(r'#' + str(first['id']) + r'\b', item['subject'])]
        recipients = [address for item in notifications for address in item['recipients']]
        check(sorted(recipients, key=str.lower) == sorted(settings['recipients'], key=str.lower),
              'Notification sent separately to both configured recipients through intercepted wp_mail')
        original_notifications = notification_messages(first['id'])
        check(len(original_notifications) == len(settings['recipients'])
              and complete_results(original_notifications, first, settings['title']),
              'Each default recipient receives the form title, submission details and all 14 original questions with readable answers')
        check(all('Answer: No answer provided' in item['message'] and answers['q_14'] in item['message']
                  for item in original_notifications),
              'Notification includes an explicit optional blank and preserves Unicode, quotes, backslashes and multiline answers')
        # Search uses what administrators read, rather than the encoded JSON
        # stored in the database. Exercise Unicode, quotes, and backslashes
        # through the actual listing and its linked filtered CSV export.
        for term in human_search_terms:
            status, listing, _, _ = admin.request('/wp-admin/admin.php?' + urllib.parse.urlencode({
                'page': 'soulmarke-submissions', 'search': term}))
            document = Document(listing)
            matching_links = [link for link in document.links if urllib.parse.parse_qs(
                urllib.parse.urlparse(link).query).get('submission') == [str(first['id'])]]
            check(status == 200 and matching_links, 'Submission search finds human-readable term ' + repr(term))
            analytics = helper('analytics', filters={'search': term})
            check(analytics['total'] == 1, 'Filtered analytics finds human-readable term ' + repr(term))
            export_link = next(link for link in document.links if 'action=soulmarke_export' in link)
            status, csv_text, headers, _ = admin.request(export_link)
            csv_rows = list(csv.reader(io.StringIO(csv_text)))
            check(status == 200 and 'text/csv' in headers.get('Content-Type', '') and len(csv_rows) == 2
                  and csv_rows[1][0] == str(first['id']) and "'" + answers['q_14'] in csv_rows[1],
                  'Filtered CSV finds human-readable term and preserves formula protection ' + repr(term))

        for client, role in ((anonymous, 'anonymous'), (subscriber, 'subscriber')):
            for route in ('soulmarke-forms', 'soulmarke-submissions', 'soulmarke-compare', 'soulmarke-settings'):
                status, body, _, url = client.request('/wp-admin/admin.php?' + urllib.parse.urlencode({
                    'page': route, 'submission': first['id'], 'ids[]': first['id']}))
                check((status >= 400 or 'wp-login.php' in url) and run not in body,
                      role.capitalize() + ' cannot access private ' + route + ' screen')
            for action in ('soulmarke_export', 'soulmarke_save_settings', 'soulmarke_delete_submission'):
                status, body, _, url = client.request('/wp-admin/admin-post.php', {
                    'action': action, '_wpnonce': nonce, 'submission_id': first['id']})
                check((status >= 400 or 'wp-login.php' in url) and run not in body,
                      role.capitalize() + ' cannot execute ' + action)
        status, body, _, _ = admin.request('/wp-admin/admin.php?' + urllib.parse.urlencode({
            'page': 'soulmarke-submissions', 'submission': first['id']}))
        check(status == 200 and run in body, 'Administrator can view stored submission detail')
        check(questions['q_01']['title'] in body, 'Administrator detail displays original question snapshot')
        for token in ('', 'invalid'):
            status, _, _, _ = admin.request('/wp-admin/admin-post.php?' + urllib.parse.urlencode({
                'action': 'soulmarke_export', '_wpnonce': token}))
            check(status == 403, 'Authenticated CSV export rejects ' + ('missing' if not token else 'invalid') + ' nonce')
        for action in ('soulmarke_save_settings', 'soulmarke_delete_submission'):
            status, _, _, _ = admin.request('/wp-admin/admin-post.php', {
                'action': action, 'submission_id': first['id'], '_wpnonce': 'invalid'})
            check(status == 403, 'Administrator ' + action + ' rejects an invalid action nonce')
        check(helper('state')['settings'] == settings and len(helper('state')['records']) == 1,
              'Denied administrator actions preserve settings and stored submissions')

        alternate = copy.deepcopy(answers)
        alternate['q_01'] = questions['q_01']['options'][1]
        alternate['q_03'] = list(reversed(alternate['q_03']))
        status, _, _, _ = submit(alternate)
        check(status == 200, 'A second distinct respondent submission succeeds')
        second = next(record for record in helper('state')['records'] if record['id'] != first['id'])
        status, body, _, _ = admin.request('/wp-admin/admin.php?' + urllib.parse.urlencode([
            ('page', 'soulmarke-compare'), ('ids[]', first['id']), ('ids[]', second['id'])]))
        check(status == 200 and Document(body).different_rows == 1,
              'Comparison highlights different answers and treats reordered checkbox choices as equivalent')
        analytics = helper('analytics')
        metric = next(question for question in analytics['questions'] if question['id'] == 'q_01')
        check(analytics['total'] == 2 and metric['responses'] == 2
              and [option['count'] for option in metric['options'][:2]] == [1, 1],
              'Filtered analytics counts both submissions and their distinct first-choice answers')
        status, body, _, _ = admin.request('/wp-admin/admin.php?' + urllib.parse.urlencode({'page': 'soulmarke-forms', 'search': run}))
        check(status == 200 and body.count('(50%)') >= 2 and 'Total submissions · filtered' in body,
              'Admin dashboard renders filtered percentages based on actual respondents')
        export_url = next(link for link in Document(body).links if 'action=soulmarke_export' in link)
        status, exported, headers, _ = admin.request(export_url)
        rows = list(csv.reader(io.StringIO(exported)))
        check(status == 200 and 'text/csv' in headers.get('Content-Type', '') and len(rows) == 3,
              'Authenticated nonce-protected CSV respects the active search filter')
        check(any(cell == "'" + answers['q_14'] for row in rows[1:] for cell in row),
              'CSV neutralizes formula-leading respondent text without changing stored text')
        for client, role in ((anonymous, 'anonymous'), (subscriber, 'subscriber')):
            status, body, _, url = client.request(export_url)
            check((status >= 400 or 'wp-login.php' in url) and run not in body,
                  role.capitalize() + ' cannot reuse administrator export nonce')

        if options.allow_settings_changes:
            _, settings_html, _, _ = admin.request('/wp-admin/admin.php?page=soulmarke-settings')
            settings_nonce = Document(settings_html).inputs['_wpnonce']
            updated = copy.deepcopy(settings)
            updated['recipients'] = ['editable-' + run + '@example.invalid']
            updated['questions'][0]['title'] = 'Updated wording ' + run
            updated['questions'][-1]['required'] = True

            def save_fields(value):
                fields = {'action': 'soulmarke_save_settings', '_wpnonce': settings_nonce}
                for key in ('title', 'description', 'success_message'):
                    fields['settings[' + key + ']'] = value[key]
                fields['settings[recipients]'] = '\n'.join(value['recipients'])
                for index, question in enumerate(value['questions']):
                    prefix = 'settings[questions][' + str(index) + ']'
                    for key in ('id', 'title', 'description', 'type', 'other_option', 'max_selections'):
                        fields[prefix + '[' + key + ']'] = question.get(key, '')
                    if question.get('required'):
                        fields[prefix + '[required]'] = '1'
                    fields[prefix + '[options]'] = '\n'.join(question['options'])
                return fields

            status, _, _, url = admin.request('/wp-admin/admin-post.php', save_fields(updated))
            check(status == 200 and 'sm_notice=saved' in url and helper('state')['settings'] == updated,
                  'Administrator can edit notification recipients and question wording through the real settings form')
            _, public_html, _, _ = anonymous.request(options.page)
            check(updated['questions'][0]['title'] in public_html, 'Future public forms use updated question wording')
            missing_required = copy.deepcopy(answers)
            del missing_required['q_14']
            status, invalid_body, _, _ = submit(missing_required)
            check(status == 422 and 'q_14' in json.loads(invalid_body)['data']['errors'],
                  'A question made required in wp-admin is enforced against direct submissions')
            third_answers = copy.deepcopy(answers)
            third_answers['q_10'] = ['Other']
            third_others = {'q_10': '+1+2 ' + run + '\nJosé "care" C:\\practice\\notes'}
            status, _, _, _ = submit(third_answers, third_others)
            check(status == 200, 'Submission succeeds after administrator settings changes')
            records = helper('state')['records']
            third = next(record for record in records if record['id'] not in (first['id'], second['id']))
            old_record = next(record for record in records if record['id'] == first['id'])
            check(old_record['questions'][0]['title'] == questions['q_01']['title']
                  and third['questions'][0]['title'] == updated['questions'][0]['title'],
                  'Question edits preserve historical snapshots while new submissions capture new wording')
            check(third['answers']['q_10'] == ['Other'] and third['other_answers'] == third_others,
                  'Other selections persist their explanatory text separately from answer choices')
            status, comparison_body, _, _ = admin.request('/wp-admin/admin.php?' + urllib.parse.urlencode([
                ('page', 'soulmarke-compare'), ('ids[]', first['id']), ('ids[]', third['id'])]))
            check(status == 200 and 'Original wording: ' + updated['questions'][0]['title'] in comparison_body,
                  'Cross-version comparison displays the original wording of edited questions')
            notifications = [item for item in mail() if re.search(r'#' + str(third['id']) + r'\b', item['subject'])]
            check([address for item in notifications for address in item['recipients']] == updated['recipients'], 'Future notifications use the edited recipient list')
            third_notifications = notification_messages(third['id'])
            check(len(third_notifications) == len(updated['recipients'])
                  and complete_results(third_notifications, third, updated['title'])
                  and all(updated['questions'][0]['title'] in item['message']
                          and 'Additional detail: ' + third_others['q_10'] in item['message']
                          for item in third_notifications),
                  'Edited recipients receive updated question wording and the complete Other selection detail')
            historical_notifications = notification_messages(first['id'])
            check(historical_notifications == original_notifications
                  and complete_results(historical_notifications, old_record, settings['title'])
                  and all(updated['questions'][0]['title'] not in item['message']
                          for item in historical_notifications),
                  'Later question edits leave previously sent notification wording and answers unchanged')
            metrics = helper('analytics')['questions']
            q1_metrics = [metric for metric in metrics if metric['id'] == 'q_01']
            check(len(q1_metrics) == 2 and sorted(metric['responses'] for metric in q1_metrics) == [1, 2],
                  'Renamed question analytics retain separate historical and current question groups')
            _, historical_dashboard, _, _ = admin.request('/wp-admin/admin.php?' + urllib.parse.urlencode({
                'page': 'soulmarke-forms', 'search': run}))
            historical_export_url = next(link for link in Document(historical_dashboard).links
                                         if 'action=soulmarke_export' in link)
            status, historical_csv, _, _ = admin.request(historical_export_url)
            historical_rows = list(csv.reader(io.StringIO(historical_csv)))
            original_column = questions['q_01']['title'] + ' [q_01 · radio]'
            edited_column = updated['questions'][0]['title'] + ' [q_01 · radio]'
            check(status == 200 and original_column in historical_rows[0] and edited_column in historical_rows[0],
                  'CSV exports separate columns for original and edited question wording')
            original_index = historical_rows[0].index(original_column)
            edited_index = historical_rows[0].index(edited_column)
            historical_by_id = {int(row[0]): row for row in historical_rows[1:]}
            check(historical_by_id[first['id']][original_index] == answers['q_01']
                  and historical_by_id[first['id']][edited_index] == ''
                  and historical_by_id[third['id']][original_index] == ''
                  and historical_by_id[third['id']][edited_index] == third_answers['q_01'],
                  'CSV places each answer under its own captured question version only')
            status, _, _, _ = admin.request('/wp-admin/admin-post.php', save_fields(settings))
            check(status == 200 and helper('state')['settings'] == settings, 'Administrator can restore original questions and recipients')

            # Date bounds use the WordPress site timezone. Both UTC timestamps
            # below fall on October 8 in Asia/Manila and must be included.
            helper('timestamp', id=first['id'], timestamp='2026-10-07 22:30:00')
            helper('timestamp', id=second['id'], timestamp='2026-10-08 02:30:00')
            helper('timestamp', id=third['id'], timestamp='2026-10-09 12:00:00')
            helper('timezone', timezone='Asia/Manila')
            analytics = helper('analytics', filters={'search': run, 'from': '2026-10-08', 'to': '2026-10-08'})
            check(analytics['total'] == 2 and analytics['daily'] == [{'date': '2026-10-08', 'count': 2}],
                  'Site-timezone date filtering and daily analytics agree across UTC midnight'
                  + ' (actual total=' + str(analytics['total']) + ', daily=' + json.dumps(analytics['daily']) + ')')
            status, filtered_html, _, _ = admin.request('/wp-admin/admin.php?' + urllib.parse.urlencode({
                'page': 'soulmarke-forms', 'search': run, 'from': '2026-10-08', 'to': '2026-10-08'}))
            check(status == 200 and 'site timezone' in filtered_html and '(UTC)' not in filtered_html,
                  'Date filters accurately describe the WordPress site timezone in the admin interface')

            numeric_settings = copy.deepcopy(settings)
            numeric_settings['questions'][-1]['type'] = 'number'
            numeric_settings['questions'].append({
                'id': 'q_qa_marker', 'title': 'Local integration fixture marker', 'description': '',
                'type': 'text', 'required': False, 'options': [], 'other_option': '', 'max_selections': 0,
            })
            status, _, _, url = admin.request('/wp-admin/admin-post.php', save_fields(numeric_settings))
            check(status == 200 and 'sm_notice=saved' in url,
                  'Administrator can configure a numeric question through the real settings editor')
            numeric_answers = copy.deepcopy(answers)
            numeric_answers['q_14'] = '1e308'
            numeric_answers['q_qa_marker'] = run + ' number-overflow'
            for index in range(2):
                status, numeric_body, _, _ = submit(numeric_answers)
                check(status == 200 and json.loads(numeric_body)['success'] is True,
                      'Finite large numeric response ' + str(index + 1) + ' can be submitted')
            numeric_analytics = helper('analytics', filters={'search': run + ' number-overflow'})
            numeric_metric = next(metric for metric in numeric_analytics['questions']
                                  if metric['id'] == 'q_14' and metric['type'] == 'number')
            check(numeric_analytics['total'] == 2 and numeric_metric['responses'] == 2
                  and math.isfinite(numeric_metric['average'])
                  and abs(numeric_metric['average'] / 1e308 - 1) < 1e-12,
                  'Analytics averages large finite numbers without overflowing or breaking JSON encoding')
            status, numeric_dashboard, _, _ = admin.request('/wp-admin/admin.php?' + urllib.parse.urlencode({
                'page': 'soulmarke-forms', 'search': run + ' number-overflow'}))
            displayed_average = re.search(r'class="sm-average"><strong>([^<]+)</strong>', numeric_dashboard)
            check(status == 200 and displayed_average
                  and math.isfinite(float(displayed_average.group(1).replace(',', ''))),
                  'Admin dashboard renders a finite average for very large numeric submissions')

        _, body, _, _ = admin.request('/wp-admin/admin.php?' + urllib.parse.urlencode({
            'page': 'soulmarke-submissions', 'submission': first['id']}))
        delete_nonce = Document(body).inputs['_wpnonce']
        status, _, _, url = admin.request('/wp-admin/admin-post.php', {
            'action': 'soulmarke_delete_submission', 'submission_id': first['id'], '_wpnonce': delete_nonce})
        check(status == 200 and 'sm_notice=deleted' in url
              and not any(record['id'] == first['id'] for record in helper('state')['records']),
              'Administrator can delete a submission using its specific confirmation nonce')
        print('PASS: ' + str(count) + ' live WordPress workflow and security checks.', flush=True)
        return 0
    except Exception as error:
        print('FAIL after ' + str(count) + ' checks: ' + str(error), file=sys.stderr, flush=True)
        return 1
    finally:
        cleanup_failed = False
        if begun:
            try:
                cleanup = helper('cleanup')
                if cleanup['remaining_records'] or cleanup['remaining_mail_bodies'] or not cleanup['capture_restored']:
                    cleanup_failed = True
                    print('Fixture cleanup did not fully restore this run’s records, mail bodies or capture option.', file=sys.stderr)
                else:
                    print('Cleanup: original settings/timezone/capture option restored; only this run’s fixtures and mail bodies removed.', flush=True)
            except Exception as error:
                cleanup_failed = True
                print('Fixture cleanup failed: ' + str(error), file=sys.stderr)
        subprocess.run(['docker', 'exec', options.wp_container, 'rm', '-f', helper_path], capture_output=True)
        if cleanup_failed:
            return 1


if __name__ == '__main__':
    sys.exit(main())
