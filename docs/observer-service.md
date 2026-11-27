# Observer service information

Last updated: 2026-11-26. Applies to the author's default API at https://api.shliapuzhnikov.com/v1/.

## Purpose and operator

Observer is operated by the author of Chekazoid Legal Entity Marks, Alexey Shliapuzhnikov (https://shliapuzhnikov.com/). It supplies public registry snapshots for online updates. It also offers the separately enabled Rosfinmonitoring people export, an optional anonymous installation counter, and voluntary site registration.

The author permits use of these API exports by Chekazoid Legal Entity Marks for local registry updates and editorial processing, including regularly refreshed lists. This permission concerns use of the service; the official source remains responsible for its registry statements.

Registry data is supplied as data, not executable plugin code. Availability and source freshness can vary. Editors remain responsible for reviewing registry status, ambiguous name matches and the suitability of marking rules for their publication.

## Registry requests

The four small plugin exports and the health check are public. The people export requires a valid token. It returns names and technical pagination/snapshot information; dates of birth and addresses are not supplied to WordPress by that export.

Requests do not include article text, article URLs, detected mentions or visitor records. As with any HTTPS service, the server necessarily receives the connecting network address and ordinary HTTP metadata. The user agent identifies the plugin and its version. A configured authentication token is sent to the protected people export when that feature is enabled.

Registry updates request a complete snapshot with its registry name, row count, version and snapshot date. The service checks that every exported row belongs to that snapshot. Historical vanished rows are omitted; explicit exclusion dates in rows still present in the source are retained. Snapshot dates identify the stored source state, and are not a promise of a new source publication on every poll. An incomplete snapshot returns an error and does not authorize the plugin to retire records.

## Optional installation counter

Starting with plugin version 1.15.1, counting requires explicit administrator consent. The administrator can disable it in plugin settings at any time. Earlier automatically enabled settings are not accepted as consent.

When consent is active, ordinary channel requests include a random installation identifier and the plugin version. The counter database records the identifier, first and most recent request time, plugin version and number of requests. It does not store an IP address, site URL, site title, article content, detected matches or contact information alongside an unregistered identifier.

Counter entries not seen for 365 days are removed by routine pruning. The identifier remains in the site's own WordPress settings until those plugin data are deleted. Disabling counting stops future statistics headers; it does not submit a new registration or automatically erase the previous server-side counter entry.

## Voluntary site registration

Registration happens only after an administrator presses the registration button. Before submission, the plugin shows the fields to be sent: site URL, site title, plugin version, WordPress version, PHP version, random installation identifier and an optional email address entered by the administrator.

Registration is intended to let the author identify participating sites and contact a registered operator about important plugin or registry problems. It is separate from anonymous counting. Repeated registration updates the same identifier's record. Registered sites and anonymous aggregates are exposed only through administration endpoints requiring an administrative token.

Registration records currently have no automatic expiry. A request to correct or delete a registration can be made to the author using the contact information at https://shliapuzhnikov.com/. Stopping use of the plugin does not itself send a deletion request. Operators should not include confidential article content or personal case details in a request.

## Choice of service

The plugin works without anonymous statistics or registration. All external requests require opt-in for the selected API address. Installations can continue using bundled, previously downloaded and manually imported registries with the channel disabled. The optional people list depends on the selected channel and its authorization token and is off by default.

If an administrator changes the API address, requests go to that selected service. This document describes the author's default service and does not describe an alternative operator's handling of requests.
