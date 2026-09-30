#!/usr/bin/env bash
#
# Start the test site's PHP built-in server when it is not already answering.
# Four workers, so loopback requests (Leak Check, Site Health) work.

DIR="${AUTHLIFY_TEST_DIR:-/tmp/claude-501/authlify-testsuite}"
PORT="${AUTHLIFY_TEST_PORT:-8919}"
SOCK="${AUTHLIFY_TEST_SOCKET:-/tmp/claude-501/mysql.sock}"
PHP="${PHP_BIN:-php}"

if curl -s -o /dev/null --max-time 5 "http://localhost:$PORT/wp-includes/images/blank.gif"; then
    exit 0
fi

cd "$DIR/site" || { echo "No test site at $DIR (run tests/bin/make-site.sh)" >&2; exit 2; }
PHP_CLI_SERVER_WORKERS=4 nohup "$PHP" -d mysqli.default_socket="$SOCK" -S "localhost:$PORT" router.php >"$DIR/server.log" 2>&1 </dev/null &
disown 2>/dev/null || true

for _ in 1 2 3 4 5 6 7 8 9 10; do
    sleep 0.5
    curl -s -o /dev/null --max-time 5 "http://localhost:$PORT/wp-includes/images/blank.gif" && exit 0
done
echo "The test server did not start on port $PORT" >&2
exit 2
