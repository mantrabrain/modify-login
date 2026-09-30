#!/usr/bin/env bash
#
# Authlify leak and bypass regression suite.
#
# Probes a live site as a logged-out visitor and fails when any response
# reveals the custom login slug or serves the login form where it should not.
# Runs the same probes as the built-in Leak Check (inc/Diagnostics/LeakCheck.php)
# plus a real login and logout through the slug.
#
# Usage:
#   tests/leak-suite.sh <site-url> <slug> [options]
#
# Options:
#   --plain            Site uses plain permalinks (login URL is /?slug).
#   --user <login>     Also log in through the slug and log out again.
#   --pass <password>  Password for --user.
#   --site-url <url>   WordPress address when it differs from the site URL
#                      (WordPress in its own folder, e.g. https://example.com/wp).
#   --multisite        wp-signup.php / wp-activate.php are real multisite pages; skip them.
#   --insecure         Do not verify TLS certificates (local HTTPS).
#   --verbose          Print the status line and redirect for every probe.
#
# Exit status: 0 when nothing leaks, 1 on any leak or failed check, 2 on usage errors.
#
# Only the given site is contacted. Requests carry no cookies (except the
# optional login test) and never submit wrong passwords, so they cannot
# trigger a lockout.

set -u

usage() {
    sed -n '2,27p' "$0" | sed 's/^# \{0,1\}//'
    exit 2
}

[ $# -lt 2 ] && usage

BASE="${1%/}"
SLUG="$2"
shift 2

PLAIN=0
USER_LOGIN=""
USER_PASS=""
SITE=""
MULTISITE=0
INSECURE=""
VERBOSE=0

while [ $# -gt 0 ]; do
    case "$1" in
        --plain) PLAIN=1 ;;
        --user) USER_LOGIN="${2:-}"; shift ;;
        --pass) USER_PASS="${2:-}"; shift ;;
        --site-url) SITE="${2%/}"; shift ;;
        --multisite) MULTISITE=1 ;;
        --insecure) INSECURE="-k" ;;
        --verbose) VERBOSE=1 ;;
        -h|--help) usage ;;
        *) echo "Unknown option: $1" >&2; usage ;;
    esac
    shift
done

[ -z "$SITE" ] && SITE="$BASE"
command -v curl >/dev/null 2>&1 || { echo "curl is required" >&2; exit 2; }

TMP="$(mktemp -d 2>/dev/null || mktemp -d -t authlify)"
trap 'rm -rf "$TMP"' EXIT
BODY="$TMP/body"
HEAD="$TMP/head"
JAR="$TMP/jar"

if [ "$PLAIN" -eq 1 ]; then
    LOGIN_URL="$BASE/?$SLUG"
else
    LOGIN_URL="$BASE/$SLUG/"
fi

if [ -t 1 ]; then
    GREEN=$'\033[32m'; RED=$'\033[31m'; YELLOW=$'\033[33m'; DIM=$'\033[2m'; RESET=$'\033[0m'
else
    GREEN=""; RED=""; YELLOW=""; DIM=""; RESET=""
fi

N=0
FAILED=0
PASSED=0
NOTES=0
SKIPPED=0

# Slug used as a URL: /slug, ?slug, \/slug (JSON) or %2Fslug, not followed by a slug character.
SLUG_RE="(/|\\?|%2[fF])$(printf '%s' "$SLUG" | sed 's/[.[\*^$()+?{|]/\\&/g')([^a-zA-Z0-9_-]|\$)"

row() {
    # row <result> <label> <code> <note>
    local result="$1" label="$2" code="$3" note="$4" color=""
    N=$((N + 1))
    case "$result" in
        PASS) color="$GREEN"; PASSED=$((PASSED + 1)) ;;
        LEAK|FAIL) color="$RED"; FAILED=$((FAILED + 1)) ;;
        WARN|INFO) color="$YELLOW"; NOTES=$((NOTES + 1)) ;;
        SKIP) color="$DIM"; SKIPPED=$((SKIPPED + 1)) ;;
    esac
    printf "%-3s %-52s %-5s %s%-5s%s %s\n" "$N" "$label" "$code" "$color" "$result" "$RESET" "$note"
}

# fetch <curl args...>: fills $CODE, $LOCATION, $CACHE, $BODY.
fetch() {
    : >"$BODY"; : >"$HEAD"
    CODE="$(curl -s $INSECURE -o "$BODY" -D "$HEAD" -w '%{http_code}' --max-time 20 "$@" 2>/dev/null)"
    [ -z "$CODE" ] && CODE="000"
    LOCATION="$(grep -i '^location:' "$HEAD" | tail -1 | cut -d' ' -f2- | tr -d '\r')"
    CACHE="$(grep -i '^cache-control:' "$HEAD" | tail -1 | cut -d' ' -f2- | tr -d '\r')"
}

has_form() { grep -Eq "id=[\"']loginform[\"']" "$BODY"; }
body_leaks() { grep -Eq "$SLUG_RE" "$BODY"; }
location_leaks() { printf '%s' "$LOCATION" | grep -Eq "$SLUG_RE"; }

describe() {
    if [ -n "$LOCATION" ]; then
        printf '%s' "-> ${LOCATION#$BASE}"
    fi
}

# probe <label> <curl args...>: leak test.
probe() {
    local label="$1"; shift
    fetch "$@"
    if [ "$CODE" = "000" ]; then
        row FAIL "$label" "$CODE" "request failed (couldn't test)"
    elif location_leaks; then
        row LEAK "$label" "$CODE" "slug in redirect $(describe)"
    elif has_form; then
        row LEAK "$label" "$CODE" "login form served"
    elif body_leaks; then
        row LEAK "$label" "$CODE" "slug in page: $(grep -Eo ".{0,40}$SLUG_RE" "$BODY" | head -1 | sed "s/$SLUG/[slug]/g")"
    elif [ "${CODE:0:1}" = "5" ]; then
        row FAIL "$label" "$CODE" "server error (couldn't test)"
    else
        row PASS "$label" "$CODE" "$( [ "$VERBOSE" -eq 1 ] && describe )"
    fi
}

printf "Authlify leak suite: %s (slug: %s%s)\n\n" "$BASE" "$SLUG" "$( [ "$PLAIN" -eq 1 ] && echo ', plain permalinks')"
printf "%-3s %-52s %-5s %-5s %s\n" "#" "Probe" "HTTP" "Result" "Note"
printf "%s\n" "---------------------------------------------------------------------------------------------"

# --- The custom URL itself -----------------------------------------------------
fetch "$LOGIN_URL"
if [ "$CODE" = "200" ] && has_form; then
    if printf '%s' "$CACHE" | grep -qi 'no-store'; then
        row PASS "Custom login URL shows the form (no-store)" "$CODE" ""
    else
        row FAIL "Custom login URL shows the form (no-store)" "$CODE" "Cache-Control lacks no-store: $CACHE"
    fi
else
    row FAIL "Custom login URL shows the form (no-store)" "$CODE" "no login form $(describe)"
fi

# --- Hidden scripts and actions ------------------------------------------------
probe "GET wp-login.php" "$SITE/wp-login.php"
probe "GET //wp-login.php" "$SITE//wp-login.php"
probe "GET /%77p-login.php" "$SITE/%77p-login.php"
probe "GET /wp-login.php/x" "$SITE/wp-login.php/x"
probe "GET /blah/wp-login.php" "$BASE/blah/wp-login.php"
probe "GET wp-login.php?action=register" "$SITE/wp-login.php?action=register"
probe "GET wp-login.php?action=lostpassword" "$SITE/wp-login.php?action=lostpassword"
probe "GET wp-login.php?action=postpass (CVE-2024-2473)" "$SITE/wp-login.php?action=postpass"
probe "POST wp-login.php?action=postpass" -e "$BASE/" --data "post_password=authlify-leak-suite" "$SITE/wp-login.php?action=postpass"
probe "POST postpass smuggling ?checkemail=" -e "$BASE/" --data "action=postpass&post_password=authlify-leak-suite" "$SITE/wp-login.php?checkemail=confirm"
probe "POST postpass smuggling ?key=" -e "$BASE/" --data "action=postpass&post_password=authlify-leak-suite" "$SITE/wp-login.php?key=x&login=admin"
probe "GET wp-login.php?action=confirmaction" "$SITE/wp-login.php?action=confirmaction&confirm_key=x"
probe "GET wp-login.php?interim-login=1" "$SITE/wp-login.php?interim-login=1"
probe "GET wp-login.php?action=logout" "$SITE/wp-login.php?action=logout"
probe "GET wp-register.php" "$SITE/wp-register.php"
if [ "$MULTISITE" -eq 1 ]; then
    row SKIP "GET wp-signup.php" "-" "multisite sign-up page"
    row SKIP "GET wp-activate.php" "-" "multisite activation page"
else
    probe "GET wp-signup.php" "$SITE/wp-signup.php"
    probe "GET wp-activate.php" "$SITE/wp-activate.php"
fi

# --- wp-admin while logged out ---------------------------------------------------
probe "GET /wp-admin/" "$SITE/wp-admin/"
probe "GET wp-admin/options.php + Referer (CVE-2021-24917)" -e "$SITE/wp-login.php" "$SITE/wp-admin/options.php"
probe "GET wp-admin/customize.php" "$SITE/wp-admin/customize.php"
probe "GET wp-admin/profile.php" "$SITE/wp-admin/profile.php"
probe "GET wp-admin/?gf_page=x (CVE-2024-6289 style)" "$SITE/wp-admin/?gf_page=x"

# --- Core shortcuts ----------------------------------------------------------------
probe "GET /login" "$BASE/login"
probe "GET /admin" "$BASE/admin"
probe "GET /dashboard" "$BASE/dashboard"

# --- 2.x back doors --------------------------------------------------------------
probe "GET /?modify_login_endpoint=1 (2.x)" "$BASE/?modify_login_endpoint=1"
probe "POST / using_custom_endpoint=1 (2.x)" --data "using_custom_endpoint=1" "$BASE/"

# --- Public pages ----------------------------------------------------------------
probe "Homepage HTML" "$BASE/"
fetch "$BASE/"
POST_URL="$(grep -Eo "href=[\"']$BASE/[^\"'#?]+[\"']" "$BODY" | sed -E "s/^href=[\"']//; s/[\"']$//" | grep -Ev "/(wp-|feed|comments|category|tag|author|page/|$SLUG)" | grep -E '/[^/]+/?$' | head -1)"
if [ -n "$POST_URL" ]; then
    probe "A linked page (${POST_URL#$BASE})" "$POST_URL"
else
    probe "A post (?p=1, redirects followed)" -L "$BASE/?p=1"
fi
probe "404 page" "$BASE/authlify-leak-suite-$$-404/"
probe "robots.txt" "$BASE/robots.txt"
probe "wp-sitemap.xml" "$BASE/wp-sitemap.xml"
probe "RSS feed" "$BASE/feed/"
if [ "$PLAIN" -eq 1 ]; then
    probe "REST API index" "$BASE/?rest_route=/"
else
    probe "REST API index" "$BASE/wp-json/"
fi

# --- Other ways in (informational: not leaks) -------------------------------------
fetch -H 'Content-Type: text/xml' --data '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName><params></params></methodCall>' "$SITE/xmlrpc.php"
if [ "${CODE:0:1}" = "5" ] || [ "$CODE" = "000" ]; then
    row FAIL "XML-RPC" "$CODE" "server error (couldn't test)"
elif [ "$CODE" = "200" ] && grep -q '<methodResponse' "$BODY"; then
    if grep -q 'system.multicall' "$BODY"; then
        row WARN "XML-RPC system.multicall" "$CODE" "on: many passwords per request, no slug needed"
    else
        row INFO "XML-RPC" "$CODE" "on, multicall blocked"
    fi
else
    row PASS "XML-RPC" "$CODE" "off"
fi

if [ "$PLAIN" -eq 1 ]; then USERS_URL="$BASE/?rest_route=/wp/v2/users"; else USERS_URL="$BASE/wp-json/wp/v2/users"; fi
fetch "$USERS_URL"
if [ "$CODE" = "200" ] && grep -q '"slug"' "$BODY"; then
    row WARN "REST /wp/v2/users" "$CODE" "usernames are public"
else
    row PASS "REST /wp/v2/users" "$CODE" ""
fi

fetch "$BASE/?author=1"
if printf '%s' "$LOCATION" | grep -q '/author/'; then
    row WARN "?author=1" "$CODE" "redirects to a username $(describe)"
else
    row PASS "?author=1" "$CODE" ""
fi

if [ "$PLAIN" -eq 1 ]; then fetch "$BASE/?rest_route=/"; else fetch "$BASE/wp-json/"; fi
if grep -q 'application-passwords' "$BODY"; then
    row INFO "Application passwords" "$CODE" "available: REST/XML-RPC logins without the slug"
else
    row PASS "Application passwords" "$CODE" "not advertised"
fi

# --- Login and logout through the slug --------------------------------------------
if [ -n "$USER_LOGIN" ] && [ -n "$USER_PASS" ]; then
    : >"$JAR"
    fetch -c "$JAR" -b "$JAR" "$LOGIN_URL"
fi
if [ -n "$USER_LOGIN" ] && [ -n "$USER_PASS" ] && grep -Eq 'h-captcha|cf-turnstile|g-recaptcha|altcha-widget|authlify-captcha' "$BODY"; then
    row SKIP "Log in / log out through the slug" "-" "a CAPTCHA is on the login form; test by hand"
elif [ -n "$USER_LOGIN" ] && [ -n "$USER_PASS" ]; then
    fetch -c "$JAR" -b "$JAR" --data-urlencode "log=$USER_LOGIN" --data-urlencode "pwd=$USER_PASS" \
        --data "wp-submit=Log+In&testcookie=1" --data-urlencode "redirect_to=$SITE/wp-admin/" "$LOGIN_URL"
    if grep -q 'wordpress_logged_in' "$JAR" && [ "${CODE:0:1}" = "3" ]; then
        row PASS "Log in through the slug" "$CODE" "$(describe)"

        fetch -c "$JAR" -b "$JAR" "$SITE/wp-admin/"
        if [ "$CODE" = "200" ]; then
            row PASS "wp-admin works when logged in" "$CODE" ""
        else
            row FAIL "wp-admin works when logged in" "$CODE" "$(describe)"
        fi

        LOGOUT="$(grep -Eo "href=[\"'][^\"']*action=logout[^\"']*" "$BODY" | head -1 | sed -E "s/^href=[\"']//; s/&amp;/\\&/g; s/&#038;/\\&/g")"
        if [ -n "$LOGOUT" ]; then
            fetch -c "$JAR" -b "$JAR" "$LOGOUT"
            if [ "${CODE:0:1}" = "3" ] && ! printf '%s' "$LOCATION" | grep -q 'wp-login.php'; then
                row PASS "Log out" "$CODE" "$(describe)"
            else
                row FAIL "Log out" "$CODE" "redirect should not point at wp-login.php $(describe)"
            fi
            fetch -b "$JAR" "$SITE/wp-admin/"
            if [ "$CODE" = "200" ] && grep -q 'wp-admin-bar-my-account' "$BODY"; then
                row FAIL "Logged out for real" "$CODE" "wp-admin still open"
            elif location_leaks || has_form; then
                row LEAK "Logged out for real" "$CODE" "wp-admin reveals the slug after logout"
            else
                row PASS "Logged out for real" "$CODE" "$(describe)"
            fi
        else
            row FAIL "Log out" "-" "no logout link found in wp-admin"
        fi
    else
        row FAIL "Log in through the slug" "$CODE" "no auth cookie $(describe)"
    fi
else
    row SKIP "Log in / log out through the slug" "-" "pass --user and --pass"
fi

printf "%s\n" "---------------------------------------------------------------------------------------------"
printf "%s passed, %s%s failed%s, %s notes, %s skipped\n" "$PASSED" "$( [ "$FAILED" -gt 0 ] && echo "$RED" )" "$FAILED" "$RESET" "$NOTES" "$SKIPPED"

[ "$FAILED" -gt 0 ] && exit 1
exit 0
