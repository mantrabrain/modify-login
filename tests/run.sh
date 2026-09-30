#!/usr/bin/env bash
#
# Authlify + Authlify Pro test suite: one command.
#
#   tests/run.sh                 # everything (free, and Pro when authlify-pro sits next to this plugin)
#   tests/run.sh --free          # free plugin only
#   tests/run.sh --pro           # Pro only
#   tests/run.sh --only limiter  # files whose path contains "limiter"
#   tests/run.sh --list          # list the test files
#   tests/run.sh --verbose       # print every test, not just failures
#
# Runs against a dedicated, disposable WordPress site (created on first use by
# tests/bin/make-site.sh): http://localhost:8919, WP-CLI /tmp/claude-501/authlify-testsuite/wp.sh.
# Override with AUTHLIFY_TEST_DIR / AUTHLIFY_TEST_PORT. Never point it at a real site:
# the suite changes settings, creates users and locks out test addresses (it puts
# everything back when it finishes).
#
# Exit status: 0 when every test passed, 1 on any failure, 2 on setup errors.

set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGINS="$(cd "$HERE/../.." && pwd)"
PRO_TESTS="$PLUGINS/authlify-pro/tests"
export AUTHLIFY_TEST_DIR="${AUTHLIFY_TEST_DIR:-/tmp/claude-501/authlify-testsuite}"
export AUTHLIFY_TEST_PORT="${AUTHLIFY_TEST_PORT:-8919}"
export AUTHLIFY_TEST_URL="http://localhost:$AUTHLIFY_TEST_PORT"
export AUTHLIFY_TEST_WPCLI="$AUTHLIFY_TEST_DIR/wp.sh"
export AUTHLIFY_TESTS_DIR="$HERE"
PHP="${PHP_BIN:-php}"

SCOPE="all"
ONLY=""
VERBOSE=0
LIST=0
while [ $# -gt 0 ]; do
    case "$1" in
        --free) SCOPE="free" ;;
        --pro) SCOPE="pro" ;;
        --only) ONLY="${2:-}"; shift ;;
        --verbose|-v) VERBOSE=1 ;;
        --list) LIST=1 ;;
        -h|--help) sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
    shift
done

if [ -t 1 ]; then
    GREEN=$'\033[32m'; RED=$'\033[31m'; YELLOW=$'\033[33m'; DIM=$'\033[2m'; BOLD=$'\033[1m'; RESET=$'\033[0m'
else
    GREEN=""; RED=""; YELLOW=""; DIM=""; BOLD=""; RESET=""
fi

# ---------------------------------------------------------------- Test files.
FILES=()
add_files() {
    # add_files <dir> <kind>
    local f
    for f in "$1"/*.php; do
        [ -e "$f" ] || continue
        if [ -n "$ONLY" ] && [[ "$f" != *"$ONLY"* ]]; then continue; fi
        FILES+=("$2:$f")
    done
}
if [ "$SCOPE" != "pro" ]; then
    add_files "$HERE/unit" unit
    add_files "$HERE/http" http
fi
if [ "$SCOPE" != "free" ] && [ -d "$PRO_TESTS" ]; then
    add_files "$PRO_TESTS/unit" unit
    add_files "$PRO_TESTS/http" http
fi
if [ "$SCOPE" != "pro" ]; then
    # Slow, destructive-on-a-copy: last.
    add_files "$HERE/uninstall" http
fi

if [ "$LIST" -eq 1 ]; then
    printf '%s\n' "${FILES[@]}"
    exit 0
fi
[ ${#FILES[@]} -eq 0 ] && { echo "No test files match." >&2; exit 2; }

# ---------------------------------------------------------------- Test site.
if [ ! -x "$AUTHLIFY_TEST_WPCLI" ]; then
    echo "Creating the test site in ${AUTHLIFY_TEST_DIR}…"
    "$HERE/bin/make-site.sh" || { echo "Could not create the test site." >&2; exit 2; }
fi
"$HERE/bin/serve.sh" || exit 2
W="$AUTHLIFY_TEST_WPCLI"

# Harness (mail capture, HTTP mocks, test IPs) and a per-run secret.
MU="$AUTHLIFY_TEST_DIR/site/wp-content/mu-plugins/authlify-test-harness.php"
cp "$HERE/lib/harness-mu-plugin.php" "$MU"
rm -f "$AUTHLIFY_TEST_DIR/site/wp-content/authlify-test-mail.jsonl"
: > "$AUTHLIFY_TEST_DIR/debug.log"

SETUP="$($W eval '
    if (!is_plugin_active("modify-login/modify-login.php")) { activate_plugin("modify-login/modify-login.php"); }
    if (file_exists(WP_PLUGIN_DIR . "/authlify-pro/authlify-pro.php") && !is_plugin_active("authlify-pro/authlify-pro.php")) { activate_plugin("authlify-pro/authlify-pro.php"); }
    update_option("authlify_test_secret", wp_generate_password(24, false));
    update_option("authlify_testsuite_baseline", get_option("authlify_settings"), false);
    \Authlify\Install\Installer::create_tables();
    if (class_exists("\AuthlifyPro\Install")) { \AuthlifyPro\Install::maybe_upgrade(); }
    \Authlify\Security\Limiter::unlock();
    echo "ok";
' 2>&1)"
if [ "$(printf '%s' "$SETUP" | tail -1)" != "ok" ]; then
    echo "Test site setup failed:" >&2
    printf '%s\n' "$SETUP" >&2
    exit 2
fi
# One front-end request so the site finishes any upgrade routine.
curl -s -o /dev/null "$AUTHLIFY_TEST_URL/"

cleanup() {
    $W eval '
        $base = get_option("authlify_testsuite_baseline");
        if (is_array($base)) { update_option("authlify_settings", $base); }
        delete_option("authlify_testsuite_baseline");
        delete_option("authlify_test_secret");
        delete_option("authlify_test_http");
        \Authlify\Security\Limiter::unlock();
        require_once ABSPATH . "wp-admin/includes/user.php";
        foreach (get_users(array("search" => "atest_*", "search_columns" => array("user_login"), "fields" => "ID")) as $id) { wp_delete_user($id); }
    ' >/dev/null 2>&1
    rm -f "$MU"
}
trap cleanup EXIT

# ---------------------------------------------------------------- Run.
RESULTS="$(mktemp -t authlify-tests.XXXXXX)"
START=$(date +%s)
printf "%sAuthlify test suite%s: %s (%d files)\n\n" "$BOLD" "$RESET" "$AUTHLIFY_TEST_URL" "${#FILES[@]}"

for entry in "${FILES[@]}"; do
    kind="${entry%%:*}"
    file="${entry#*:}"
    label="$(basename "$(dirname "$(dirname "$(dirname "$file")")")")/$(basename "$(dirname "$file")")/$(basename "$file" .php)"
    t0=$(date +%s)
    if [ "$kind" = "unit" ]; then
        out="$($W eval-file "$HERE/lib/unit.php" "$file" 2>&1)"
    else
        out="$("$PHP" "$HERE/lib/http.php" "$file" 2>&1)"
    fi
    # Anything that is not a result line (PHP notices, fatals) is reported as a failure.
    stray="$(printf '%s\n' "$out" | grep -Ev '^(PASS|FAIL|SKIP)	' | grep -v '^$' || true)"
    lines="$(printf '%s\n' "$out" | grep -E '^(PASS|FAIL|SKIP)	' || true)"
    if [ -z "$lines" ]; then
        lines="$(printf 'FAIL\t%s\t(no results)\t%s\t0' "$label" "$(printf '%s' "$stray" | head -3 | tr '\n\t' '  ')")"
    elif [ -n "$stray" ]; then
        lines="$lines"$'\n'"$(printf 'FAIL\t%s\t(unexpected output)\t%s\t0' "$label" "$(printf '%s' "$stray" | head -3 | tr '\n\t' '  ')")"
    fi
    printf '%s\n' "$lines" | awk -F'\t' -v L="$label" 'BEGIN { OFS = "\t" } { $2 = L; print }' >> "$RESULTS"

    p=$(printf '%s\n' "$lines" | grep -c '^PASS' || true)
    f=$(printf '%s\n' "$lines" | grep -c '^FAIL' || true)
    s=$(printf '%s\n' "$lines" | grep -c '^SKIP' || true)
    status="${GREEN}ok${RESET}"
    [ "$f" -gt 0 ] && status="${RED}FAIL${RESET}"
    printf "%-44s %s  %3d passed%s%s  %s%ss%s\n" "$label" "$status" "$p" \
        "$( [ "$f" -gt 0 ] && printf ', %s%d failed%s' "$RED" "$f" "$RESET" )" \
        "$( [ "$s" -gt 0 ] && printf ', %s%d skipped%s' "$YELLOW" "$s" "$RESET" )" "$DIM" "$(( $(date +%s) - t0 ))" "$RESET"
    if [ "$VERBOSE" -eq 1 ]; then
        printf '%s\n' "$lines" | awk -F'\t' '{ printf "    %-4s %s%s\n", $1, $3, ($4 != "" ? "  — " $4 : "") }'
    else
        printf '%s\n' "$lines" | awk -F'\t' '$1 != "PASS" { printf "    %-4s %s%s\n", $1, $3, ($4 != "" ? "  — " $4 : "") }'
    fi
done

# PHP errors the plugins logged while the suite ran.
FATALS="$(grep -E 'PHP (Fatal|Parse) error' "$AUTHLIFY_TEST_DIR/debug.log" 2>/dev/null | grep -v 'authlify-uninstall' | head -5 || true)"
if [ -n "$FATALS" ]; then
    printf 'FAIL\tsuite/debug-log\tno PHP fatal errors while the suite ran\t%s\t0\n' "$(printf '%s' "$FATALS" | head -1 | tr '\t' ' ')" >> "$RESULTS"
    printf "%-44s %sFAIL%s\n%s\n" "suite/debug-log" "$RED" "$RESET" "$FATALS"
else
    printf 'PASS\tsuite/debug-log\tno PHP fatal errors while the suite ran\t\t0\n' >> "$RESULTS"
fi
WARNINGS="$(grep -cE 'PHP (Warning|Deprecated|Notice)' "$AUTHLIFY_TEST_DIR/debug.log" 2>/dev/null || true)"
# Warnings and notices that come from (or name) the plugins count as failures.
OURS="$(grep -E 'PHP (Warning|Deprecated|Notice)' "$AUTHLIFY_TEST_DIR/debug.log" 2>/dev/null | grep -E 'plugins/(modify-login|authlify-pro)/|<code>(modify-login|authlify-pro)</code>' | grep -v '/tests/' | sed -E 's/^\[[^]]*\] //' | sort | uniq -c | sort -rn | head -5 || true)"
if [ -n "$OURS" ]; then
    printf 'FAIL\tsuite/debug-log\tno PHP warnings or notices from Authlify while the suite ran\t%s\t0\n' "$(printf '%s' "$OURS" | head -1 | cut -c1-400 | tr '\t' ' ')" >> "$RESULTS"
    printf "%-44s %sFAIL%s (warnings from Authlify)\n%s\n" "suite/debug-log" "$RED" "$RESET" "$(printf '%s' "$OURS" | cut -c1-300)"
else
    printf 'PASS\tsuite/debug-log\tno PHP warnings or notices from Authlify while the suite ran\t\t0\n' >> "$RESULTS"
fi

PASSED=$(grep -c '^PASS' "$RESULTS" || true)
FAILED=$(grep -c '^FAIL' "$RESULTS" || true)
SKIPPED=$(grep -c '^SKIP' "$RESULTS" || true)
TOTAL=$((PASSED + FAILED + SKIPPED))

echo
echo "------------------------------------------------------------------------"
if [ "$FAILED" -gt 0 ]; then
    echo "${RED}${BOLD}Failures:${RESET}"
    awk -F'\t' '$1 == "FAIL" { printf "  %s › %s\n      %s\n", $2, $3, $4 }' "$RESULTS"
    echo
fi
printf "%s%d tests%s: %s%d passed%s, %s%d failed%s, %d skipped  (%ds; %s PHP warnings/notices in debug.log)\n" \
    "$BOLD" "$TOTAL" "$RESET" "$GREEN" "$PASSED" "$RESET" "$( [ "$FAILED" -gt 0 ] && echo "$RED" )" "$FAILED" "$RESET" "$SKIPPED" "$(( $(date +%s) - START ))" "${WARNINGS:-0}"
cp "$RESULTS" "$AUTHLIFY_TEST_DIR/last-results.tsv" 2>/dev/null || true
rm -f "$RESULTS"

[ "$FAILED" -gt 0 ] && exit 1
exit 0
