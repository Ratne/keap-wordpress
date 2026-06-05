=== Keap Connect ===
Contributors: ratne
Author URI: https://ratne.dev
Tags: keap, infusionsoft, crm, webhook, rest api
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receive leads on a generic REST endpoint and sync them to Keap (Infusionsoft) via the REST v2 API.

== Description ==

Keap Connect exposes a generic REST endpoint that receives a JSON body (e.g. first name, last name, email, phone, tags and extra fields) and syncs the contact to Keap using the REST v2 API.

Key features:

* Generic REST endpoint `POST /wp-json/keap-connect/v1/intake` (JSON), protected by an optional, rotatable Bearer token.
* Keap authentication via OAuth and/or PAT/SAK. In "Both" mode, if the OAuth call fails authentication it is automatically retried with the PAT/SAK.
* Contact flow: search by email (`GET /contacts?filter=email==...`); create it if missing (`POST /contacts`), otherwise update it (`PATCH /contacts/{id}`).
* Tagging from the body `Tag` field (Keap tag IDs, comma-separated) via `POST /tags/{tag_id}/contacts:applyTags`.
* Configurable mapping of body keys to standard fields, custom fields (fetched via `GET /contacts/model`) or address components.
* Full logging of incoming bodies and all outgoing Keap calls, with status, HTTP code and request correlation (secrets masked).
* Automatic OAuth token refresh every 6 hours via WP-Cron, plus an optional external cron endpoint.
* GitHub-based auto-updates and a multilingual UI (English + Italian).

== Configuration ==

1. Activate the plugin.
2. Go to "Keap Connect" > "Connection" and configure OAuth (Client ID/Secret) and/or the PAT/SAK token.
3. In "Endpoint", copy the URL and the Bearer token, and enable the options you need.
4. In "Mapping", click "Refresh fields from Keap" and define how to map the body keys.
5. Review requests under "Log".

== Changelog ==

= 1.0.0 =
* First release.
