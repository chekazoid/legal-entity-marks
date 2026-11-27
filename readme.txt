=== Chekazoid Legal Entity Marks ===
Contributors: shliapuzhnikov
Tags: editorial, registry, compliance, media, russian
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.16.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Review registry mentions and links in Russian-language articles, with optional footnotes and editorial reports.

== Description ==

Chekazoid Legal Entity Marks helps editors review mentions of people and organizations listed in Russian public registries. It finds names and their grammatical forms, records mentions in an editorial report, and can add footnotes and disclosure text when an editor enables the relevant categories.

The interface is intended for Russian-language editorial work. See the [Russian manual](https://github.com/chekazoid/legal-entity-marks/blob/main/docs/readme-ru.md).

Features:

* Separate marking and monitoring settings for each registry category.
* Profiles for media outlets and other websites, plus manual settings.
* Optional matching limited to quotations, links and embedded posts for foreign agents.
* Name matching with grammatical forms, aliases and safeguards for ambiguous surnames.
* Per-article overrides and a report with filters and CSV export.
* Detection of links to listed resources, including individual accounts on shared platforms. Link removal is a separate, explicit editorial action.
* Background registry updates and archive scans in batches, with progress and source diagnostics.
* Optional people list from Rosfinmonitoring through the Observer service. It is disabled by default and requires a service token. All recorded parts of a person's name must match. Surname-only matches and initials are insufficient. Identical full names still require editorial review.

Automatic footnotes are rendered through the content filter and do not rewrite stored articles. Stored content changes only when an authorized user explicitly runs link removal.

The plugin supports editorial review. Registry status and matching results need human verification; the plugin does not determine a person's identity or replace legal advice.

= Registry data and freshness =

Bundled JSON files provide the initial registries. After an administrator enables Observer in the Marking settings, the plugin can download and verify registry snapshots manually or through WP-Cron (weekly by default). The dashboard shows the source and any update failures. A failed or incomplete update leaves the previous registry in place.

Complete Observer snapshots retire missing imported records and reactivate returning records. Historical entries retain their data and explicit exclusion dates. The plugin keeps records added manually in current versions separate. Review older custom entries before refreshing because older releases did not record their import origin.

= Optional anonymous statistics =

The installation counter is disabled by default. To enable it, an administrator must tick the consent checkbox and save the settings. The counter also requires an enabled Observer connection. On upgrades to 1.16.0, online access and counting require consent in the current settings.

When enabled, ordinary Observer requests carry a random installation identifier and the plugin version in two HTTP headers. Article content, matches, visitor information, the site URL and contact information are not included in these headers. Disabling the checkbox stops these headers immediately. The identifier is stored locally and survives reinstallation when plugin data is retained.

= Optional site registration =

A separate registration button sends the site URL, site title, plugin version, WordPress version, PHP version, random installation identifier and an optional email address entered by the administrator. These exact fields are shown before the button is pressed. Registration is never automatic and does not enable the anonymous counter.

The plugin works without statistics or registration. The Observer service's data handling and usage terms are included in `docs/observer-service.md` and in the [service information](https://github.com/chekazoid/legal-entity-marks/blob/main/docs/observer-service.md).

== External services ==

The only external service used by this plugin is **Observer**, operated by plugin author Alexey Shliapuzhnikov at `https://api.shliapuzhnikov.com/v1/`. It supplies snapshots of the four public registries, a health check, and optional exports and support features. Initial installation and offline use make no requests to Observer. An administrator can enable requests under Marking > Settings, and can revoke that permission there at any time. Upgrading from earlier versions requires opting in.

* **Registry updates and health check:** The selected service receives the server IP address, ordinary HTTP metadata (including the plugin version in the user agent), and the registry or health endpoint requested. Requests occur when an administrator checks or refreshes the channel, or when scheduled updates run after opt-in.
* **Optional people list:** After the administrator enables people monitoring or marking and configures a token, paginated requests include the token and pagination/snapshot information. WordPress receives names, with no dates of birth or addresses.
* **Optional statistics:** If separately enabled, registry and health requests also carry the installation identifier and version described above.
* **Optional site registration:** A registration request includes the fields listed above and happens only after the administrator confirms that action.

The plugin never sends visitor records, article text, article URLs or detected matches. It downloads registry data without executable code.
[Observer terms of use](https://github.com/chekazoid/legal-entity-marks/blob/main/docs/observer-service.md#purpose-and-operator) and [Observer privacy information](https://github.com/chekazoid/legal-entity-marks/blob/main/docs/observer-service.md#registry-requests) describe the default service. A different HTTPS address needs separate consent and is subject to that operator's terms and privacy policy.

== Installation ==

1. Upload the `chekazoid-legal-entity-marks` folder to `/wp-content/plugins/`, or install the ZIP from Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Open the Marking menu (shown as "Маркировка" in the Russian interface) and select the categories and editorial profile appropriate for the site.
4. For online updates, review the service information and enable the Observer connection in settings. Scan the archive and check the report before enabling broad marking rules.
5. Leave the optional people list off unless it is needed. To use it, configure an Observer token and choose monitoring or marking explicitly.

Statistics and site registration are optional. They do not need to be enabled to use the plugin.

For an existing GitHub installation, upload the GitHub ZIP over the installed copy. When switching from the GitHub package to the WordPress.org package, deactivate the GitHub installation before activating the directory version. Activation migrates its existing data. Keep the old installation in place until the migration succeeds.

== Frequently Asked Questions ==

= Does the plugin rewrite articles? =

Footnotes are generated when content is displayed. Explicit link removal changes stored content and should be reviewed and backed up by the editor.

= Can I mark an individual article with automatic marking turned off? =

For one of the four main registries, enable monitoring and leave automatic marking off. In the article editor, choose Always mark for a verified match and save the article. This choice applies to that article only.

= Does it send articles to Observer? =

No. Matching runs inside WordPress. Registry downloads do not send article content, lists of matches or visitor information.

= Can I use the plugin without connecting to Observer? =

Yes. The plugin works with the bundled registries, previously downloaded snapshots and local imports. Online updates and the optional people list require a configured Observer connection.

= Does a complete name prove that the person is the listed individual? =

No. Complete names can coincide. The people feature requires all available name parts, and the editor must resolve identical-name cases.

= What happens when a source is unavailable? =

The dashboard reports update failures and the plugin keeps the existing list for any category that fails to load. The people list switches to a new active set only after all consistent pages are saved.

= Are settings deleted on uninstall? =

By default, data is retained for reinstallation. Enable the explicit data-deletion setting before uninstalling if all plugin tables, settings and article metadata should be removed. Deactivation stops scheduled plugin tasks.

= Where is the Russian documentation? =

The [Russian manual](https://github.com/chekazoid/legal-entity-marks/blob/main/docs/readme-ru.md) describes the editorial settings, reports and WP-CLI commands in detail.

== Screenshots ==

1. Registry overview, update status and background archive checks.
2. Editorial profiles and separate marking and monitoring controls.
3. Archive scanner with progress and matching settings.
4. Resource list with search, pagination and explicit link removal controls.

== Changelog ==

= 1.16.0 =
* Renamed the plugin for the WordPress.org directory and migrated existing settings, registry records and article metadata to the new prefix.
* Online registry updates use Observer with an explicit opt-in. Bundled and previously downloaded data work offline.
* Loaded scripts and styles through WordPress on the relevant screens, and checked dependencies before activation.
* Prevented older aliases from matching common words as organization names without the required quotation marks. The same rules apply to saved mentions.
* Match specific MAX and VK accounts instead of flagging unrelated links on the same platform.

= 1.15.2 =
* Always mark now works for an individual article when a main registry is monitored with automatic marking turned off.
* Prevented false matches for locality names and sports teams extracted from local organisations and supporters' club names. Older saved matches are checked against the same rules when displayed.

= 1.15.1 =
* Prepared the package for WordPress.org review.
* Added complete-snapshot reconciliation in every registry category, with rollback on incomplete or failed updates.
* Stopped retired entries and their linked resources from affecting old scan results. Preserved full official names without truncation.
* Statistics now require explicit opt-in, including on upgrades from earlier defaults.
* Improved request sanitization, output escaping and database query handling.
* Enabled TLS certificate verification for registry downloads.
* Added service and privacy documentation and a reproducible distribution build.

= 1.15.0 =
* Added optional background import and full-name matching for people in the Rosfinmonitoring list.
* Added the Observer fallback for extremist organizations.

= 1.14.4 =
* Fixed misleading update warnings after a successful Observer fallback.

== Upgrade Notice ==

= 1.16.0 =
Deactivate the previous installation before switching to the new directory package. Existing markings and settings are migrated. Enable Observer in Marking settings if you want online registry updates.

= 1.15.2 =
Use Always mark for individual articles while automatic marking is off. Keep monitoring enabled for the registry. Improved matching of locality names and sports teams applies to older scan results as well.

= 1.15.1 =
Statistics require new explicit consent. Complete updates deactivate missing imported names while retaining their data. Review historical custom additions: older versions did not record their origin. Existing marking settings are retained.
