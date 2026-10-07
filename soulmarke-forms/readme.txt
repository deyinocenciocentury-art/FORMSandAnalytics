=== Soulmarke Forms & Analytics ===
Contributors: soulmarke
Tags: survey, forms, analytics, notifications, shortcode
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A one-question-at-a-time practitioner survey with editable questions, private submissions, comparisons, analytics, and email notifications.

== Description ==

Soulmarke Forms & Analytics includes the 14 questions from the supplied Holistic Collective Practitioner Discovery Survey. The introduction, thank-you message, questions, answer choices, required fields, and notification recipients are editable in WordPress administration.

The frontend uses the supplied brand colors #4dafd8, #2a5e6f, and white. Visitors answer one question at a time, move back to revise answers, and review their answers before submitting. Questions that ask respondents to choose “Other” or “Something else” can request extra detail. Multiple-choice questions can have a maximum number of selections.

All results, analytics, settings, comparisons, CSV exports, and deletion actions require the WordPress manage_options capability, which administrators have by default. No public results endpoint is provided.

== Installation ==

1. In WordPress, go to Plugins > Add New Plugin > Upload Plugin.
2. Upload soulmarke-forms.zip, install it, and activate Soulmarke Forms & Analytics.
3. Open Soulmarke Forms > Questions & settings to review your survey and notification recipients.
4. Edit or create a page and add a Shortcode block containing [soulmarke_form].
5. Publish the page and complete a test submission. Check the private submissions screen and your notification inboxes.

You can also copy the soulmarke-forms directory into wp-content/plugins and activate the plugin through WordPress administration.

No build step, third-party form service, or frontend account is required. Install on a WordPress site using HTTPS.

== Using the plugin ==

= Questions and recipients =

The default notification recipients are andrea@soulmarke.com and Coral@soulmarke.com. Change them under Soulmarke Forms > Questions & settings, using one address per line or comma-separated addresses. Leave the field empty to disable notifications.

You can add, edit, reorder, and remove questions. Supported formats are short text, long text, email, number, choose one, choose multiple, dropdown, and a rating from 1 to 5. Set a question as required when it must be answered. For a multiple-choice question, set maximum selections to 0 to allow any number of choices.

To ask for more detail on a choice, enter its exact label in “Choice that requests extra detail”. The label must also be one of that question’s answer choices. Save changes explicitly; the editor does not autosave.

Existing submissions keep a snapshot of the questions and answer choices used when they were completed. Editing the current survey does not rewrite those records. Analytics show historical question wording separately when wording or answer format changes.

= Private results and analytics =

Soulmarke Forms > Submissions lists saved responses and their notification status. Read a submission to see its original questions and answers. Use date and answer-search filters to find responses; dates use the WordPress site timezone.

Soulmarke Forms > Compare displays two or three submissions side by side and highlights differing answers, including extra detail for Other choices.

The analytics dashboard shows submission totals, daily activity, answer distributions, recent text samples, and averages for numeric and rating questions. Multiple-choice percentages use the number of respondents who answered that question, so percentages may total more than 100% when multiple answers are allowed.

Export CSV downloads private results for the selected date and answer-search filters. Treat exported files as private because they can contain respondent details.

= Notifications =

Notifications are sent using WordPress wp_mail. “Email accepted for delivery” means wp_mail accepted the message; it does not confirm inbox delivery. Configure and test a suitable WordPress SMTP or transactional mail service on your production website. A failed email does not erase the saved submission; the results screen shows the failure.

== Data and privacy ==

Submissions are stored in a WordPress database table named with the site’s configured table prefix followed by soulmarke_submissions. Survey settings are stored in WordPress options.

The plugin does not send survey answers to analytics vendors, load external chart libraries, or store in-progress answers in browser local storage. Email notifications go only to the configured recipients.

Deactivating or uninstalling the plugin retains its settings and submissions. Administrators can permanently delete individual submissions from their private detail screen; deletion requires confirmation and cannot be undone. Include these records in your normal site backup and retention procedures.

The plugin does not provide a consent policy for your organization. Add any consent wording you require to the editable survey introduction or a question, and use your site’s normal privacy policy.

== Fonts ==

Bebas Neue is included locally under the SIL Open Font License. The license is included in assets/fonts/OFL.txt. The plugin does not contact Google Fonts.

Descriptions and interface body text request Avenir or Avenir Next when installed on the visitor’s device, with system-font fallbacks. Avenir is not bundled. If you require Avenir on every device, provide appropriately licensed webfont files and load them through your site’s theme or stylesheet.

== Frequently Asked Questions ==

= Where do I find submissions? =

In WordPress administration, open Soulmarke Forms > Submissions. Access requires manage_options. The public shortcode renders the form only.

= Can I change the questions after collecting responses? =

Yes. Changes apply to new submissions. Existing submissions retain their original questions and answers.

= Can I use the form on several pages? =

Yes. Each page with [soulmarke_form] displays the same configured survey and submits to the same private results database.

= Do email failures lose a submission? =

No. The plugin saves a submission before sending notifications. Check its status in the private results screen and troubleshoot WordPress email delivery if needed.

== Changelog ==

= 1.0.0 =

* Initial release with the supplied 14-question survey, a one-question-at-a-time frontend, editable settings, private results, comparisons, CSV export, and analytics.
