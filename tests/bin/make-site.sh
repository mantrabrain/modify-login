#!/usr/bin/env bash
#
# Create (or re-create) the dedicated WordPress site the test suite runs against.
#
#   tests/bin/make-site.sh            # default: /tmp/claude-501/authlify-testsuite, port 8919
#   AUTHLIFY_TEST_DIR=... AUTHLIFY_TEST_PORT=... tests/bin/make-site.sh
#
# The site is disposable: its database is dropped and created again. Both plugins are
# symlinked from this checkout (so the suite always tests the working copy).
#
# Needs: php (with mysqli), WP-CLI at /usr/local/bin/wp (or $WP_CLI), a MySQL server
# (socket $AUTHLIFY_TEST_SOCKET, root/root by default) and a WordPress core copy in
# $AUTHLIFY_WP_SRC (downloaded with `wp core download` when missing).

set -eu

HERE="$(cd "$(dirname "$0")/.." && pwd)"
PLUGINS="$(cd "$HERE/../.." && pwd)"
DIR="${AUTHLIFY_TEST_DIR:-/tmp/claude-501/authlify-testsuite}"
PORT="${AUTHLIFY_TEST_PORT:-8919}"
DB="${AUTHLIFY_TEST_DB:-authlify_testsuite}"
SOCK="${AUTHLIFY_TEST_SOCKET:-/tmp/claude-501/mysql.sock}"
SRC="${AUTHLIFY_WP_SRC:-/tmp/claude-501/authlify-clean}"
WPCLI="${WP_CLI:-/usr/local/bin/wp}"
PHP="${PHP_BIN:-php}"

[ -z "${AUTHLIFY_TEST_NO_SERVER:-}" ] && { pkill -f "localhost:$PORT router.php" 2>/dev/null || true; }
rm -rf "$DIR"
mkdir -p "$DIR/site"

if [ -d "$SRC/wp-includes" ]; then
    rsync -a --exclude='wp-content/plugins/*' --exclude='wp-content/uploads' --exclude='wp-content/mu-plugins' --exclude='wp-config.php' "$SRC/" "$DIR/site/"
else
    "$PHP" "$WPCLI" core download --path="$DIR/site" --quiet
fi
mkdir -p "$DIR/site/wp-content/plugins" "$DIR/site/wp-content/mu-plugins"

# php -S router: static files, real PHP scripts, else WordPress.
cat > "$DIR/site/router.php" <<'PHPR'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && substr($file, -4) !== '.php') { return false; }
if ($path !== '/' && is_file($file)) { $_SERVER['SCRIPT_NAME'] = $path; require $file; return; }
if (is_dir($file) && is_file($file . '/index.php')) { $_SERVER['SCRIPT_NAME'] = rtrim($path, '/') . '/index.php'; require $file . '/index.php'; return; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
PHPR

"$PHP" -r "\$m = new mysqli('localhost', 'root', 'root', '', 0, '$SOCK'); \$m->query('DROP DATABASE IF EXISTS $DB'); \$m->query('CREATE DATABASE $DB');"

printf '#!/bin/bash\nexec %s -d mysqli.default_socket=%s %s --path=%s/site "$@"\n' "$PHP" "$SOCK" "$WPCLI" "$DIR" > "$DIR/wp.sh"
chmod +x "$DIR/wp.sh"
W="$DIR/wp.sh"

$W config create --dbname="$DB" --dbuser=root --dbpass=root --dbhost="localhost:$SOCK" --skip-check --force >/dev/null
$W config set WP_DEBUG true --raw >/dev/null
$W config set WP_DEBUG_LOG "'$DIR/debug.log'" --raw >/dev/null
$W config set WP_DEBUG_DISPLAY false --raw >/dev/null
$W config set DISABLE_WP_CRON true --raw >/dev/null
# Application passwords need HTTPS or a local environment.
$W config set WP_ENVIRONMENT_TYPE local >/dev/null
$W core install --url="http://localhost:$PORT" --title="Authlify Test Suite" --admin_user=admin --admin_password='Qa-Pass-123!' --admin_email=admin@example.com --skip-email >/dev/null
$W rewrite structure '/%postname%/' >/dev/null

if [ -n "${AUTHLIFY_TEST_COPY_PLUGINS:-}" ]; then
    # Real copies (uninstall tests delete things; never touch the working copy).
    for p in modify-login authlify-pro; do
        [ -d "$PLUGINS/$p" ] && rsync -a --exclude=node_modules --exclude=.git --exclude=tests "$PLUGINS/$p/" "$DIR/site/wp-content/plugins/$p/"
    done
else
    ln -s "$PLUGINS/modify-login" "$DIR/site/wp-content/plugins/modify-login"
    [ -d "$PLUGINS/authlify-pro" ] && ln -s "$PLUGINS/authlify-pro" "$DIR/site/wp-content/plugins/authlify-pro"
fi

if [ -n "${AUTHLIFY_TEST_NO_SERVER:-}" ]; then
    $W plugin activate modify-login >/dev/null
    $W eval '\Authlify\Install\Upgrader::maybe_upgrade();' >/dev/null
    [ -d "$DIR/site/wp-content/plugins/authlify-pro" ] && $W plugin activate authlify-pro >/dev/null
    echo "Site ready (no server): $DIR, WP-CLI: $W"
    exit 0
fi

"$HERE/bin/serve.sh"
$W plugin activate modify-login >/dev/null
# First request runs Authlify's first-run setup (tables, settings).
curl -s -o /dev/null "http://localhost:$PORT/"
[ -d "$PLUGINS/authlify-pro" ] && $W plugin activate authlify-pro >/dev/null
curl -s -o /dev/null "http://localhost:$PORT/"

echo "Test site ready: http://localhost:$PORT (admin / Qa-Pass-123!), WP-CLI: $W"
