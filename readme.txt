=== Legal Entity Marks ===
Contributors: shliapuzhnikov
Tags: editorial, registry, compliance, media, russian
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.15.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Review registry mentions and links in Russian-language articles, with optional footnotes and editorial reports.

== Description ==

Legal Entity Marks helps editors review mentions of people and organizations listed in Russian public registries. It finds names and their grammatical forms, records mentions in an editorial report, and can add footnotes and disclosure text when an editor enables the relevant categories.

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

Bundled JSON files are an initial snapshot and an offline fallback. Updates try the official source first, then the documented Observer channel, then a repository mirror where available, and finally the bundled snapshot. Source dates and failures are shown in the dashboard. Only a complete, consistent official or Observer snapshot can retire missing registry entries or reactivate returning ones. Missing names remain in history without an invented legal exclusion date. Once a verified snapshot exists, bundled files, mirrors and partial imports only supplement aliases of known entries and cannot replace its statuses. New manually added entries are tracked separately. Older versions did not store import origin, so earlier custom additions absent from a complete source snapshot can also become inactive; their stored data remain available.

Updates run through WP-Cron, weekly by default. A working WordPress scheduler is needed for background jobs. No executable code is downloaded from external services.

= Optional anonymous statistics =

The installation counter is disabled by default. To enable it, an administrator must tick the consent checkbox and save the settings. Existing enabled settings from versions before 1.15.1 do not count as consent and are disabled until the administrator opts in again.

When enabled, ordinary Observer requests carry a random installation identifier and the plugin version in two HTTP headers. Article content, matches, visitor information, the site URL and contact information are not included in these headers. Disabling the checkbox stops these headers immediately. The identifier is stored locally and survives reinstallation when plugin data is retained.

= Optional site registration =

A separate registration button sends the site URL, site title, plugin version, WordPress version, PHP version, random installation identifier and an optional email address entered by the administrator. These exact fields are shown before the button is pressed. Registration is never automatic and does not enable the anonymous counter.

The plugin works without statistics or registration. The Observer service's data handling and usage terms are included in `docs/observer-service.md` and in the [service information](https://github.com/chekazoid/legal-entity-marks/blob/main/docs/observer-service.md).

== External services ==

External requests are needed to retrieve the selected public registries. Receiving servers see the network address and normal HTTP request metadata. Article text, article URLs and lists of matches are not sent for registry downloads. Authentication tokens, when configured, are sent only to the configured Observer channel.

* **Russian Ministry of Justice:** `reestrs.minjust.gov.ru` and `minjust.gov.ru`. Retrieves the foreign-agent, undesirable-organization and extremist-organization registries. Requests contain registry identifiers, pagination and an empty search, where applicable. Runs on scheduled or manual registry updates. [Official website and published policies](https://minjust.gov.ru/).
* **Federal Security Service:** `fsb.ru`. Retrieves the public terrorist-organization list during scheduled or manual updates. [Official website and published policies](https://www.fsb.ru/).
* **GitHub:** `raw.githubusercontent.com`. Retrieves JSON data mirrors from the public plugin repository after the official source and Observer channel fail. No scripts or updates to plugin code are loaded from these mirrors. [Terms](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service) and [privacy statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement).
* **Observer, operated by the plugin author:** `https://api.shliapuzhnikov.com/v1/`. Supplies fallback registry data and a channel health check when the channel is enabled. The four organization/foreign-agent exports are public. The optional Rosfinmonitoring people export requires a token and an explicitly selected people mode; WordPress receives names, not dates of birth or addresses. Statistics headers are added only after consent. A registration request is sent only when its separate button is pressed. Disabling the channel stops scheduled channel requests. Manual channel checks and site registration are explicit requests made by the administrator. [Service terms and privacy information](https://github.com/chekazoid/legal-entity-marks/blob/main/docs/observer-service.md).

The Observer address can be changed by the administrator. The policy above describes the author's default service; an alternative service has its own terms.

== Installation ==

1. Upload the `legal-entity-marks` folder to `/wp-content/plugins/`, or install the ZIP from Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Open the Marking menu (shown as "Маркировка" in the Russian interface) and select the categories and editorial profile appropriate for the site.
4. Review registry source status and scan the archive. Check the report before enabling broad marking rules.
5. Leave the optional people list off unless it is needed. To use it, configure an Observer token and choose monitoring or marking explicitly.

Statistics and site registration are optional. They do not need to be enabled to use the plugin.

== Frequently Asked Questions ==

= Does the plugin rewrite articles? =

Footnotes are generated when content is displayed. Explicit link removal changes stored content and should be reviewed and backed up by the editor.

= Does it send articles to Observer? =

No. Matching runs inside WordPress. Registry downloads do not send article content, lists of matches or visitor information.

= Can I use official registries without Observer? =

Yes. Disable the channel in settings. Official sources and bundled snapshots remain available. The optional people list requires the configured Observer channel and its token.

= Does a complete name prove that the person is the listed individual? =

No. Complete names can coincide. The people feature requires all available name parts, and the editor must resolve identical-name cases.

= What happens when a source is unavailable? =

The plugin tries the documented fallback sources and reports the resulting source and its freshness. An incomplete or failed update keeps the previous verified statuses in every category. The people list switches to a new active set only after all consistent pages are saved.

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

= 1.15.1 =
Statistics require new explicit consent. Complete updates deactivate missing imported names while retaining their data. Review historical custom additions: older versions did not record their origin. Existing marking settings are retained.
