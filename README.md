# Holistic Collective Forms & Analytics

A WordPress plugin for the Holistic Collective Practitioner Discovery Survey. The public form presents one question at a time. Results, side-by-side comparisons, question distributions, date/search filters, and CSV exports are available only to WordPress administrators.

The supplied PDF's 14 questions and 93 answer choices are included. “Something else” and “Other” show a follow-up text field, and question 10 allows up to three selections. Questions begin as optional because the supplied survey does not mark them required. Administrators can edit the questions, choices, order, required answers, introduction, completion message, and notification recipients.

## Install on your WordPress website

1. [Download the ready-to-install plugin ZIP](https://github.com/deyinocenciocentury-art/FORMSandAnalytics/raw/refs/heads/main/soulmarke-forms.zip). The file is also available as `soulmarke-forms.zip` in this repository; open it on GitHub and choose **Download raw file**. Do not use GitHub's **Code → Download ZIP**, which downloads the whole development repository.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, select the ZIP, install, and activate it.
3. Add a **Shortcode** block to a page containing `[soulmarke_form]`, then publish the page.
4. Open **Holistic Collective Forms → Questions & settings** to review the survey and recipients.
5. Read results under **Holistic Collective Forms → Submissions**, **Compare**, and **Analytics**.

Notification recipients default to `andrea@soulmarke.com` and `Coral@soulmarke.com`. Emails include every original question and its submitted answer, including multiple-choice selections, Other details, and unanswered questions. They also contain a link to the private WordPress submission; administrator access is required to open that link. WordPress's `wp_mail` handles delivery. The plugin records whether WordPress accepted each notification, but inbox delivery depends on the site's mail service. Leave recipients blank to disable notifications.

The plugin requires WordPress 6.3 or later and PHP 7.4 or later. It works without paid form plugins or external analytics services. Use a staging site to check compatibility with your active theme, caching configuration, and mail service before collecting real submissions. Full-page caching of a form page must not serve expired WordPress nonces; exclude that page from long-lived caching if submissions report an expired session.

## Design and stored data

The form uses `#4dafd8`, `#2a5e6f`, and white. Bebas Neue is bundled with its SIL Open Font License. Body text requests Avenir Next or Avenir when available on the visitor's device and otherwise uses a system sans-serif. Avenir webfont files are not bundled; licensed files can be added separately to use that font across all devices.

Submissions store answers, submission time, original question snapshots, supplementary Other answers, and email status in a dedicated WordPress database table. Editing or deleting a question does not rewrite previous answers. Analytics separates questions whose wording or type changed; comparisons show the original wording for each response. Exported text is escaped to prevent spreadsheet formulas from executing. Deactivating or removing the plugin preserves the database records and settings. Administrators can delete individual submissions through the submissions screen. Public pages and submission responses do not expose the stored records or notification addresses.

## Develop in this cloud workspace

Each cloud task is already isolated. Work in the existing checkout; no additional Git worktree is needed.

```bash
cd /workspace/FORMSandAnalytics
bash scripts/dev/install.sh
bash scripts/dev/start.sh
```

The development environment runs official, digest-pinned WordPress/PHP and MariaDB Docker images. State and generated local passwords are kept outside the checkout in `/workspace/.soulmarke-dev`, with persistent named Docker volumes. Source files are copied into the development WordPress container so the private checkout's permissions remain unchanged. Run `bash scripts/dev/sync-plugin.sh` after editing the plugin.

The local development mail capture intercepts all WordPress notifications. It normally logs only recipient and subject metadata to `/tmp/soulmarke-local-mail.jsonl` inside `soulmarke-wp`. During integration tests, full messages are captured only for that test run’s marker and removed during cleanup. The log is private, outside the web root, and the helper does not send email externally. The capture helper is excluded from the installable plugin ZIP.

Run the integration and browser checks after startup:

```bash
python3 tests/integration.py --allow-settings-changes
node tests/frontend.mjs
```

Run these suites sequentially because the integration suite temporarily edits the local survey and restores it afterward. The browser check uses Playwright and a Chromium executable. The cloud runtime already provides them. Test fixtures are confined to the local development site. The complete integration run passed 78 checks, including permissions, actual submission storage, editable recipients, historical snapshots, Unicode search, CSV protection, numeric averages, and full question-and-answer notification contents. Browser checks passed the 14-question flow, validation, error recovery, mobile layout, removal of the public Soulmarke logo/name, and button colors under theme overrides. Validation used WordPress 7.1.2 with PHP 8.3.35 and MariaDB 11.4.13; other supported versions were not separately tested. Build both the GitHub download file `soulmarke-forms.zip` and the local `dist/soulmarke-forms.zip` copy with `bash scripts/package.sh`.
