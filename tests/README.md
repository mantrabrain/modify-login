# Authlify tests

This folder is for developers. It is export-ignored, so it never ships in the plugin zip.

## Full suite (`run.sh`)

One command runs every automated test for Authlify and, when it sits next to this plugin, Authlify Pro:

```bash
tests/run.sh                 # everything (about 3 minutes)
tests/run.sh --free          # free plugin only
tests/run.sh --pro           # Pro only (also: authlify-pro/tests/run.sh)
tests/run.sh --only captcha  # files whose path contains "captcha"
tests/run.sh --verbose       # list every test, not just failures
```

It prints one line per file, the failures, and a summary, and exits `1` when anything failed (`2` on setup errors).

**Where it runs.** On a dedicated, disposable WordPress site: `http://localhost:8919`, WP-CLI `/tmp/claude-501/authlify-testsuite/wp.sh`. The first run creates it with `tests/bin/make-site.sh` (MySQL socket `/tmp/claude-501/mysql.sock`, root/root; both plugins symlinked from this checkout); `tests/bin/serve.sh` restarts its PHP server. Override with `AUTHLIFY_TEST_DIR`, `AUTHLIFY_TEST_PORT`, `AUTHLIFY_TEST_DB`. Never point it at a real site.

**How it works.**

- `unit/*.php` run inside WordPress (`wp eval-file tests/lib/unit.php <file>`). Each test is isolated: Authlify options, the lockout table, superglobals, the current user, filters added with `add_test_filter()` and users made with `make_user()` are restored afterwards.
- `http/*.php` run as plain PHP with curl against the site (`tests/lib/http.php`), and set it up with WP-CLI. Each file restores the settings, design, lockouts and permalinks, and deletes its users.
- `uninstall/uninstall.php` builds two throwaway sites with **copies** of the plugins and runs `wp plugin uninstall` with "Delete all data" off and on.
- `lib/harness-mu-plugin.php` is copied into the test site for the run and removed afterwards. It writes mail to `wp-content/authlify-test-mail.jsonl`, blocks outgoing HTTP (CAPTCHA `siteverify` and the licence store are answered from mocks), and lets the suite pick a request's client IP with a per-run secret header, so brute-force tests never lock out the machine running them.
- Assertions: `tests/lib/assert.php` (`t()`, `eq()`, `ok()`, `contains()`, `is_error_code()`, `skip()`...).
- Pro tests live in `authlify-pro/tests/{unit,http}` and use this runner.

After the run the site's settings are back to what they were, lockouts are cleared, the harness is removed and test users (`atest_*`) are deleted. The last results are in `/tmp/claude-501/authlify-testsuite/last-results.tsv`.

## Leak and bypass suite (`leak-suite.sh`)

`run.sh` runs it twice (pretty and plain permalinks, with a login and logout); it can also be run on its own against any site.

`leak-suite.sh` checks whether the hidden login URL leaks. It visits a live site as a logged-out visitor and fails if any response does one of these things:

- puts the login slug in the page, in a redirect `Location`, or in JSON;
- serves the login form (`id="loginform"`) anywhere other than the custom URL.

It needs only bash (3.2 or newer, so it runs on macOS) and curl. It contacts only the site you pass to it.

```bash
# Basic run
tests/leak-suite.sh http://adflow.local secret-door

# Also log in and out through the slug (use a test account)
tests/leak-suite.sh http://adflow.local secret-door --user mltest --pass 'Test-Pass-123!'

# Plain permalinks (Settings → Permalinks → Plain): the login URL is /?slug
tests/leak-suite.sh http://example.test secret-door --plain

# WordPress in its own folder (Site Address differs from WordPress Address)
tests/leak-suite.sh https://example.com secret-door --site-url https://example.com/wp

# Multisite: wp-signup.php and wp-activate.php are real pages there
tests/leak-suite.sh https://network.test secret-door --multisite

# Local HTTPS with a self-signed certificate
tests/leak-suite.sh https://adflow.local secret-door --insecure --verbose
```

The script prints a table and exits `0` when nothing leaks, `1` on any leak or failed check, and `2` on a usage error.

| Result | Meaning |
|---|---|
| `PASS` | No slug and no login form in the response. |
| `LEAK` | The slug or the login form was found. The note shows where. |
| `FAIL` | A functional check failed: the custom URL did not work, login or logout failed, or the server returned a 5xx error. A 5xx error can hide a leak, so it never counts as a pass. |
| `WARN` / `INFO` | Not a leak, but another way in: XML-RPC `system.multicall`, REST usernames, `?author=1`, or application passwords. |
| `SKIP` | Not applicable, for example the sign-up scripts on multisite or login without `--user`. |

### What it probes

The suite covers every vector in report 01 §2.2 and report 02 §3, grouped as follows.

- **Custom URL:** it shows the form, and the response carries `Cache-Control: no-store`.
- **Hidden scripts:**
  - `wp-login.php`, `//wp-login.php`, `/%77p-login.php`, `wp-login.php/x`, `/blah/wp-login.php`.
  - The `wp-login.php` actions `register`, `lostpassword`, `postpass` (GET, and POST with a post password; CVE-2024-2473), `confirmaction`, `interim-login=1` and `logout`.
  - `wp-register.php`, `wp-signup.php`, `wp-activate.php`.
- **wp-admin while logged out:**
  - `/wp-admin/`.
  - `options.php` with a `wp-login.php` Referer (CVE-2021-24917).
  - `customize.php` and `profile.php`.
  - `?gf_page=` (the auth_redirect vector from CVE-2024-6289).
- **Core shortcuts:** `/login`, `/admin`, `/dashboard`.
- **2.x back doors:** `/?modify_login_endpoint=1`, and `POST /` with `using_custom_endpoint=1`.
- **Public output:**
  - The homepage and the first internal page linked from it (or `?p=1`).
  - A 404 page.
  - `robots.txt`, `wp-sitemap.xml`, the RSS feed and the REST index.
- **Other ways in (informational):**
  - XML-RPC and `system.multicall`.
  - `/wp/v2/users`.
  - `?author=1`.
  - Application passwords, as advertised in the REST index.
- **Session (with `--user`):**
  - Log in through the slug.
  - Open wp-admin.
  - Log out using the real logout link. The redirect must not point at `wp-login.php`.
  - Confirm that wp-admin is closed again and does not reveal the slug.

The suite never submits a wrong password, so it cannot lock you out. On a site that logs hidden-URL requests, its probes do appear in the activity log. The built-in Leak Check avoids that with a signed `X-Authlify-Leak-Check` header, but this script runs from outside the site and has no way to sign one.

### Checks that need a mailbox or a browser

Some leaks can't be tested with curl alone. Check these by hand after any change to the router:

1. **Password-reset email:** request a reset for a test user. The link goes to the slug (`/secret-door/?action=rp…`). This is expected, because only the account's own mailbox receives it.
2. **Privacy confirmation email:** go to Tools → Export Personal Data and send a request to an address that is **not** a user. The link must point at `wp-login.php?action=confirmaction…`, not the slug. It must open the confirmation screen when the key is valid, and a 404 when the key is wrong. The built-in Leak Check also runs this filter as a unit test.
3. **Multisite welcome email:** create a site. The email must link to the working custom URL.
4. **Comment "log in to reply" link and Meta widget:** turn on "Users must be registered and logged in to comment" or add the Meta widget. Both leaks are expected, and the suite should report them on the homepage or post. Turn them off again afterwards.
5. **Interim login:** let a wp-admin session expire (or delete the auth cookie in devtools). The re-login modal must load from the slug and log you back in.

## Host matrix (plan §9)

The suite must be 100% green (no `LEAK` or `FAIL`) on each row below before a release.

| Environment | How to set it up | Command / notes |
|---|---|---|
| **Apache** (mod_php or PHP-FPM, `.htaccess`) | A stock LAMP stack, or Local with "Apache" chosen as the web server. Pretty permalinks. | `tests/leak-suite.sh http://apache.test secret-door --user … --pass …` |
| **Nginx** | Local's default web server, or any `try_files $uri $uri/ /index.php?$args;` config. | Same command. `//wp-login.php` and `/%77p-login.php` behave differently from Apache, so check both. |
| **Plain permalinks** | Settings → Permalinks → Plain. | Add `--plain`. The login URL is `/?secret-door`. |
| **Subdirectory install** | WordPress Address `https://site.test/wp`, Site Address `https://site.test` (core "Giving WordPress its own directory"). | Add `--site-url https://site.test/wp`. Also install the whole site under `/blog/` and run with `https://site.test/blog`. |
| **Multisite, subdirectory** | `define( 'MULTISITE', true ); define( 'SUBDOMAIN_INSTALL', false );`, network-activate Authlify. | Run once for the main site and once for `/site2`, with `--multisite`. Also check the welcome email (manual check 3). |
| **Multisite, subdomain** | `SUBDOMAIN_INSTALL` set to `true`, with wildcard DNS or hosts entries for `site2.network.test`. | Run against each subdomain with `--multisite`. |
| **WP Engine-style `?wpe-login=true`** | On WP Engine, or simulate it with a mu-plugin that redirects `/?wpe-login=true` to `wp-login.php`. | Probe `curl -sI "https://site/?wpe-login=true"` by hand. The final page must be the 404 or the blocked response, never the form or the slug. Also exclude the slug from the WPE cache (Site Health shows the steps). |
| **Cloudflare (proxied, optionally APO)** | Orange-cloud the DNS record and set the IP source to Cloudflare. For APO, install the Cloudflare plugin and turn APO on. | Run the suite against the public hostname **twice**, because the second run hits the edge cache. The custom URL must still show `no-store` and must not be `cf-cache-status: HIT`. Site Health → "Authlify IP source" must be green. |

Record each run's table in the release checklist (the PR description is enough).

## Built-in Leak Check

The same probes, minus the login and logout session, run inside WordPress:

- **Dashboard:** Authlify → Dashboard → Leak Check → *Run Leak Check*. This is rate-limited to one run every 30 seconds.
- **WP-CLI:** `wp authlify leak-check` (`--format=json` is also available). It exits non-zero when something leaks.
- **Automatically:** a few seconds after the slug or hiding settings change, and weekly from the daily cron.
- **Site Health:** the result appears under Tools → Site Health as "Authlify Leak Check".

If the host blocks loopback requests, the built-in check reports "couldn't test", not a pass. In that case, use this script from your own machine.
