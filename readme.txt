=== DevDome Bot Protection ===
Contributors: devdome
Tags: bot protection, security, crawler control, ai crawlers, firewall
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detect and block harmful bots. Control crawler access and review traffic without CAPTCHAs.

== Description ==

DevDome Bot Protection detects scanners, exploit probes and unwanted automated traffic.

Start in Record Bots to review traffic before enabling blocking. Detection uses local rules and downloaded reference lists. No DevDome account is required.

= Three modes =

* Turned Off: disables the main protection engine.
* Record Bots: records detected bots without enforcing HTTP block actions.
* Block Bad Bots: blocks requests that match your blocking rules.

Known Bots robots.txt directives, the enabled honeypot and browser session collection operate independently of these modes.

= Five tabs =

* Overview: choose your mode, review traffic totals and check sample crawler access. Mode changes save on click.
* Activity: review caught requests, bot and people totals, recent events, countries and technical details. Add an Allow This Visitor exception when IP anonymization is off.
* Rules: set actions for built-in detection, rate limits, repeat offenders, automated browsers, the honeypot and Bot Radar. Add custom rules for user agents, paths, IP addresses, ranges, countries or network numbers. Action changes save on click.
* Known Bots: control AI crawlers, search engines, SEO crawlers, social previews and uptime monitors. Each switch saves immediately. OpenAI and Anthropic training, search and user-request agents have separate controls.
* Settings: manage privacy, time zone, feeds, account connection, import/export, Clear Statistics and Cloudflare rules.

Use Recommended on Rules and Known Bots asks for confirmation. On Rules, it restores recommended actions while preserving thresholds and feature switches.

Caught By Your Rules includes requests that would be blocked in Record Bots. Traffic totals count requests, not unique people.

= Detection and crawler controls =

Built-in rules cover scanner paths, scanner tools, Spamhaus DROP addresses, datacenter networks, empty user agents and automation tools.

Additional controls cover login, XML-RPC and REST request rates, rapid page requests, repeat offenders and automated browser sessions. A first-party browser beacon records interaction signals. A hidden honeypot link helps identify crawling networks after multiple distinct IPs reach it. You can unflag networks or exempt them from future honeypot flagging.

In the full engine, verified crawlers are exempt from automatic detection rules. Verification uses downloaded IP ranges or cached forward-confirmed reverse DNS. An explicit Known Bots block overrides that exemption. Custom Allow rules take precedence over the full engine's blocking rules.

Blocked crawlers receive directives in WordPress's virtual robots.txt and HTTP blocking in Block Bad Bots mode. Google-Extended and Applebot-Extended are robots.txt controls only. Physical robots.txt files are not edited; Known Bots provides a downloadable snippet.

Failed feed downloads keep previous lists. Unreadable event lists and counts are marked unavailable, with messages such as "could not be read" or unknown-value indicators instead of zero. Database failures also produce an error banner.

Import/export transfers supported settings, Known Bots choices and custom rules as JSON. Import applies supplied settings and adds missing custom rules. Statistics, downloaded feeds and connection secrets are not transferred.

= Optional DevDome account =

Local protection works without an account. Connect through Settings or the shared DevDome hub to see this site's bot activity and settings in the DevDome dashboard. Settings are changed only in this plugin, by an administrator of the site; the dashboard cannot change them.

Connected sites also send built-in rule actions, supported custom rules, time zone and daily traffic totals. External services below explains the exchange.

== Installation ==

1. Install and activate DevDome Bot Protection.
2. Open DevDome, then Bot Protection.
3. Review Activity while the plugin is in Record Bots.
4. Adjust Rules and Known Bots.
5. Select Block Bad Bots on Overview when ready.

== Privacy and data storage ==

The plugin stores data in your site's database:

* Bot events, including time, IP address, requested path without query strings, user agent, classification, action, reason and available Cloudflare signals.
* Browser sessions with a random identifier, IP address, hashed user agent, timestamps, page counts and interaction flags.
* Per-IP reputation with request counts, blocking decisions, timestamps and available country or network information.
* Daily and hourly totals without IP addresses.
* Settings, custom rules, learned offender lists and cached reference data.

The browser beacon uses the first-party ddc_sid session cookie. It sends interaction flags to your own site, not form contents.

Anonymize IPs shortens addresses stored in new events and sessions to an IPv4 /24 or IPv6 /48 prefix. Reputation and enforcement records can still contain full addresses. This setting does not disable external ASN lookups or Bot Radar reporting.

Events, sessions and reputation records are kept for 90 days on every site. Daily totals remain for 3 years; hourly totals are pruned after about 2 days. Retention is not configurable.

The WordPress personal data exporter finds matching events using comment IP addresses associated with the requested email address or user. The eraser also removes matching sessions and reputation rows and updates offender lists. Only exact addresses are matched: rows shortened by Anonymize IPs cover several visitors and are neither exported nor erased. Without matching comment addresses, these tools cannot identify the person's records. Suggested privacy-policy text is provided.

Clear Statistics in Settings requires typing CLEAR. It permanently removes events, sessions, learned offender history, lifetime counters and daily and hourly totals. Settings, custom rules, Known Bots choices and the account connection remain. Connected sites also request clearing the dashboard's daily totals, retrying until acknowledged.

Deleting the plugin removes its local tables, settings, schedules and caches. Shared library data is removed when the last installed plugin using that library is deleted.

== External services ==

The feed and lookup services below are not contacted until Outside Lookups is turned on in Settings (off by default). The optional account connection and Bot Radar have their own consent switches, and an error report is sent only when you press its button. Scheduled requests use WordPress cron. With Outside Lookups on, reference feeds refresh daily; Settings also provides Refresh Now. Providers receive normal connection metadata, including the requesting server's IP address.

= api.devdome.com: reference feeds =

No account is required. Feed refreshes download:

* https://api.devdome.com/bot-protection/list for bot user-agent patterns.
* https://api.devdome.com/bot-protection/asns for datacenter network numbers.
* https://api.devdome.com/bot-protection/drop for Spamhaus DROP ranges.

These lists support local detection. GET requests send a DevDome-Bot-Protection/version user agent, without a site or visitor payload.

Terms: https://devdome.com/terms-of-service
Privacy: https://devdome.com/privacy-policy

= developers.google.com: crawler verification ranges =

Feed refreshes download https://developers.google.com/static/search/apis/ipranges/googlebot.json and https://developers.google.com/static/search/apis/ipranges/special-crawlers.json to verify crawler addresses. GET requests send a DevDome-Bot-Protection/version user agent, without a site or visitor payload.

Documentation: https://developers.google.com/crawling/docs/crawlers-fetchers/verify-google-requests
Terms: https://policies.google.com/terms
Privacy: https://policies.google.com/privacy

= www.bing.com: crawler verification ranges =

Feed refreshes download https://www.bing.com/toolbox/bingbot.json to verify Bingbot addresses. GET requests send a DevDome-Bot-Protection/version user agent, without a site or visitor payload.

Documentation: https://www.bing.com/webmasters/help/how-to-verify-bingbot-3905dc26
Terms: https://www.microsoft.com/servicesagreement
Privacy: https://privacy.microsoft.com/privacystatement

= search.developer.apple.com: crawler verification ranges =

Feed refreshes download https://search.developer.apple.com/applebot.json to verify Applebot addresses. GET requests send a DevDome-Bot-Protection/version user agent, without a site or visitor payload.

Documentation: https://support.apple.com/en-gb/119829
Terms: https://www.apple.com/legal/internet-services/terms/site.html
Privacy: https://www.apple.com/legal/privacy/en-ww/

= openai.com: crawler verification ranges =

Feed refreshes download https://openai.com/gptbot.json, https://openai.com/searchbot.json and https://openai.com/chatgpt-user.json to verify OpenAI crawler addresses. GET requests send a DevDome-Bot-Protection/version user agent, without a site or visitor payload.

Documentation: https://developers.openai.com/api/docs/bots
Terms: https://openai.com/policies/terms-of-use/
Privacy: https://openai.com/policies/privacy-policy/

= analytics.devdome.com: account connection =

After you press Connect, the shared library contacts https://analytics.devdome.com/api/plugin/connect/start and https://analytics.devdome.com/api/plugin/connect/claim.

The handshake sends the site domain and identifier, site token, admin return URL and temporary credentials to link your account. The site also exposes a one-way token hash for connection verification.

Terms: https://devdome.com/terms-of-service
Privacy: https://devdome.com/privacy-policy

= devdome.com: connection authorization =

After Connect starts the handshake, your browser opens https://devdome.com/connect/ with a temporary request token so you can authorize the connection.

Terms: https://devdome.com/terms-of-service
Privacy: https://devdome.com/privacy-policy

= api.devdome.com: account checks =

After connection consent, https://api.devdome.com/plugin/account receives the site identifier and authentication token to verify connection and retrieve account and plan information. Successful checks are cached for 15 minutes.

Connecting through the disclosed inventory form also authorizes sending active DevDome plugin names and versions, plus library, WordPress and PHP versions. Other plugins are not inventoried.

Disconnect sends the site identifier and token to https://api.devdome.com/plugin/disconnect to unlink the site.

Terms: https://devdome.com/terms-of-service
Privacy: https://devdome.com/privacy-policy

= analytics.devdome.com: dashboard synchronization =

Connected sites send their settings to https://analytics.devdome.com/api/plugin/protection on connection, local changes, manual synchronization, plugin-screen checks and hourly cron. The synchronization is one way, from the site to the dashboard.

Authenticated requests send the site identifier, blocked-bot IDs, protection mode and enabled state, pause expiry, built-in rule actions, supported custom rules and time zone. They also send plugin version, policy revision and aggregate caught-request counts.

Custom rules include names, fields, operators, values, actions and enabled states. IP rules therefore send their addresses. Lists exceeding 200 custom rules remain local.

The plugin reads one value from the response: the revision number of the dashboard's copy, which the next upload refers to. No setting, rule or list on the site is changed by the response. If the dashboard's copy differs, the site's settings are sent again.

Daily totals upload to https://analytics.devdome.com/api/plugin/protection/days on connection and hourly. Requests carry the site identifier, authentication token and daily caught/bots/people counts, without visitor addresses or page data. The initial upload covers the last 90 days; later uploads cover the last three days. Clear Statistics sends a clear instruction through this endpoint.

Terms: https://devdome.com/terms-of-service
Privacy: https://devdome.com/privacy-policy

= api.devdome.com: Bot Radar =

Bot Radar is optional and off by default. Enable it on Rules to share detection signals and receive a community list.

Daily reports to https://api.devdome.com/bot-protection/fleet/report contain a random site ID, public IPs recorded as repeat offenders or automated browsers, their categories, flagged network numbers and honeypot IP/ASN pairs from logged events. Pairs can be included before a network crosses the local flagging threshold. Reports identify the plugin version through its user agent.

While enabled, feed refreshes download https://api.devdome.com/bot-protection/fleet for local IP and network matching. This GET sends the plugin/version user agent without a site or visitor payload.

Terms: https://devdome.com/terms-of-service
Privacy: https://devdome.com/privacy-policy

= api.devdome.com: geolocation and network resolution =

With Outside Lookups on, daily batches send up to 100 public addresses from recent logged non-human events lacking country data to https://api.devdome.com/geo-resolve/classify. Verified-crawler events are excluded. Events can qualify even when the request was only recorded. The response enriches events with country and ASN information.

Also with Outside Lookups on, honeypot detection and matching against flagged networks can send public request IPs to the same endpoint when ASN information is unavailable. Results are cached for 24 hours. They can include requests not yet classified as bots. With Outside Lookups off, no address is sent for lookup.

Both request types send IP lists and the DevDome-Bot-Protection/version user agent.

Terms: https://devdome.com/terms-of-service
Privacy: https://devdome.com/privacy-policy

= devdome.com: plugin catalog =

After account connection, the shared hub fetches https://devdome.com/wp-plugins/catalog.json for plugin descriptions and availability. Successful downloads are cached for 12 hours; failed requests may retry after an hour.

The GET identifies the bundled core version through its user agent, without a site or visitor payload.

Terms: https://devdome.com/terms-of-service
Privacy: https://devdome.com/privacy-policy

= devdome.com: error reports =

Pressing Report this error sends a support report to https://devdome.com/api/plugin/error-report.

It contains plugin, library, WordPress and PHP versions, multisite status, locale, redacted error text, screen identifier and diagnostic excerpts describing plugin state, counts and scheduling. It also includes the site domain, site ID, available account ID and site administrator email address. Reports are not automatic.

The separate Report a bug link opens https://devdome.com/report-bug with the plugin identifier and version.

Terms: https://devdome.com/terms-of-service
Privacy: https://devdome.com/privacy-policy

== Frequently Asked Questions ==

= Does this require an account? =

No. Local detection, rules, Known Bots controls and reference feeds work without an account. Connecting adds dashboard access and synchronization.

= Does it protect cached pages? =

PHP protection runs when requests reach WordPress. Pages served entirely from a CDN or server cache can bypass it.

Settings generates two copy-paste Cloudflare rules locally: scanner and exploit paths, and optional XML-RPC blocking. Both exempt Cloudflare-verified crawlers. You deploy them yourself; the plugin makes no Cloudflare API call.

= Can I control AI training separately from search? =

Yes. Known Bots separates supported training, search and user-request agents. Review each bot's description before changing its action.

= Will crawler access checks guarantee search rankings? =

No. Crawler Access evaluates sample requests. It checks access, not rankings.

== WordPress Abilities API ==

On WordPress 6.9 or later, the plugin registers ten abilities, also exposed as MCP tools when the WordPress MCP Adapter is installed:

* devdome-bot-protection/get-status
* devdome-bot-protection/get-settings
* devdome-bot-protection/update-settings
* devdome-bot-protection/list-rules
* devdome-bot-protection/add-rule
* devdome-bot-protection/delete-rule
* devdome-bot-protection/list-recent-events
* devdome-bot-protection/pause-protection
* devdome-bot-protection/resume-protection
* devdome-bot-protection/clear-statistics

Access requires manage_options. Pass confirm: true after user agreement for turning protection off, switching Live to Watch, turning Anonymize IPs off, adding an Allow rule, deleting a rule, pausing protection or clearing statistics.

Pause lasts 30 minutes. Event output masks IP addresses and omits query strings; responses and errors pass through redaction.

== Bundled libraries ==

Chart.js 4.5.1 (MIT, source at https://github.com/chartjs/Chart.js, license in assets/LICENSE-chartjs.txt) draws the Activity chart. The admin stylesheet is compiled from Tailwind CSS 3.4 utility classes (MIT). The country flag images in assets/flags are the 20 px set from Flagpedia (https://flagpedia.net, public domain). The Roboto font files in assets/fonts are from Google Fonts (Apache License 2.0).

== Changelog ==

= 1.0.4 =

* The personal data exporter and eraser match exact addresses only. A row shortened by Anonymize IPs covers several visitors and is no longer included in one person's export.
* Database error texts shown on screen or sent with an error report also redact secrets and server paths.
* The Outside Lookups hint states that the good-bot check still uses this server's own DNS resolver.

= 1.0.3 =

* The plugin header now states Requires at least 6.2 and Requires PHP 7.4, so WordPress refuses to activate it on older versions (WordPress.org review).
* The reverse-DNS check for good bots answers "unverified" instead of failing on hosts where the DNS functions are disabled.

= 1.0.2 =

* The DevDome dashboard can no longer change anything on the site. Settings go one way, from the site to the dashboard; the remote sync endpoint and every remotely applied setting (mode, on or off, pause, rules, Known Bots, blocked countries, time zone) were removed.
* Shared DevDome library 1.7.10.

= 1.0.1 =

* Outside Lookups: one switch, off by default, for the bot list downloads and the country and network lookups. Nothing is contacted on activation.
* The early-block must-use file and the Safe Mode flag file are no longer written; Safe Mode is a setting.
* The Safety Link (a no-login pause URL) was removed.
* Events, sessions and reputation records are kept 90 days on every site.
* Chart.js 4.5.1.
* DevDome dashboard: paused, off or watching only are no longer listed as issues.
* Shared DevDome library 1.7.9: the DevDome dashboard lists only real problems (a feature that is off, paused or not connected is no longer an issue) and no longer says Not monitored.

= 1.0.0 =

* First public release.