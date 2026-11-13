#!/usr/bin/env bash
# tests/hold_flow_pg_test.sh
#
# Runs the hold sequences (place / debit / release / partial release /
# expire) against a THROWAWAY local PostgreSQL 16 and checks balance, held
# and available after every step. It also proves the held_balance
# migration on a copy of the old-shape schema: available money unchanged
# for every account, safe to run twice, and reversible.
#
#     bash tests/hold_flow_pg_test.sh
#
# Everything is made up and lives in a temp directory that is deleted at
# the end: its own database cluster (never an existing one), test-only
# certificates generated on the spot, and two PHP built-in servers on
# loopback ports. It refuses to run unless it can create that cluster.
# Needs: PostgreSQL 16 binaries (initdb, pg_ctl), php with pdo_pgsql and
# curl, openssl. See docs/HELD_BALANCE.md.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PGBIN="${PGBIN:-$(ls -d /usr/lib/postgresql/16/bin 2>/dev/null || true)}"
[ -x "$PGBIN/initdb" ] || PGBIN="$(dirname "$(command -v initdb || echo /nonexistent/initdb)")"
[ -x "$PGBIN/initdb" ] || { echo "PostgreSQL binaries not found (set PGBIN)"; exit 2; }

WORK="$(mktemp -d)"
chmod 755 "$WORK"
PGPORT_T=$(( 20000 + RANDOM % 20000 ))
WEBPORT=$(( 40001 + RANDOM % 20000 ))
DB=zuru_hold_test

# PostgreSQL will not run as root; use the postgres user when we are root.
if [ "$(id -u)" = "0" ]; then
    chown postgres "$WORK"
    as_pg() { runuser -u postgres -- "$@"; }
else
    as_pg() { "$@"; }
fi

cleanup() {
    [ -n "${WEBPID:-}" ] && kill "$WEBPID" 2>/dev/null || true
    [ -n "${WEBPID2:-}" ] && kill "$WEBPID2" 2>/dev/null || true
    as_pg "$PGBIN/pg_ctl" -D "$WORK/pgdata" -m immediate stop >/dev/null 2>&1 || true
    rm -rf "$WORK"
}
trap cleanup EXIT

echo "== throwaway PostgreSQL in $WORK (port $PGPORT_T)"
as_pg "$PGBIN/initdb" -D "$WORK/pgdata" -U postgres -A trust >/dev/null

# Test-only certificates: a CA, a VOUCHMORPH client and a ZURUBANK member,
# plus a server certificate so balance.php (sslmode=require) can connect.
C="$WORK/certs"; mkdir -p "$C"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=TEST ONLY CA" -keyout "$C/ca.key" -out "$C/ca.crt" 2>/dev/null
for who in VOUCHMORPH ZURUBANK pgserver; do
    openssl req -newkey rsa:2048 -nodes -subj "/CN=TEST ONLY $who" -keyout "$C/$who.key" -out "$C/$who.csr" 2>/dev/null
    openssl x509 -req -in "$C/$who.csr" -CA "$C/ca.crt" -CAkey "$C/ca.key" -CAcreateserial -days 2 -out "$C/$who.crt" 2>/dev/null
done
cp "$C/pgserver.crt" "$WORK/pgdata/server.crt"; cp "$C/pgserver.key" "$WORK/pgdata/server.key"
chmod 600 "$WORK/pgdata/server.key"
[ "$(id -u)" = "0" ] && chown postgres "$WORK/pgdata/server.crt" "$WORK/pgdata/server.key"

as_pg "$PGBIN/pg_ctl" -D "$WORK/pgdata" -l "$WORK/pg.log" -w \
    -o "-p $PGPORT_T -c listen_addresses=127.0.0.1 -c unix_socket_directories=$WORK -c ssl=on -c timezone=UTC" start >/dev/null
PSQL=("$PGBIN/psql" -X -q -h 127.0.0.1 -p "$PGPORT_T" -U postgres -v ON_ERROR_STOP=1)
"${PSQL[@]}" -d postgres -c "CREATE DATABASE $DB"
"${PSQL[@]}" -d "$DB" -f "$ROOT/tests/fixtures/hold_flow_schema.sql"

export DATABASE_URL="postgresql://postgres:unused@127.0.0.1:$PGPORT_T/$DB"
export PGTZ=UTC
export VOUCHMORPH_CA_CERT_CONTENT="$(cat "$C/ca.crt")"
export ZURUBANK_PRIVATE_KEY_CONTENT="$(cat "$C/ZURUBANK.key")"
export ZURUBANK_CERT_CONTENT="$(cat "$C/ZURUBANK.crt")"
export VOUCHMORPH_PRIVATE_KEY_CONTENT="$(cat "$C/VOUCHMORPH.key")"
export VOUCHMORPH_CERT_CONTENT="$(cat "$C/VOUCHMORPH.crt")"
export ZURUBANK_API_KEY="test-only-not-a-real-key"

# Two separate server processes: the check for two holds at the same moment
# sends one request to each, so both really run at once (one built-in server
# process runs one request at a time, even with PHP_CLI_SERVER_WORKERS).
WEBPORT2=$(( WEBPORT + 1 ))
start_web() {
    ( cd "$ROOT" && exec php -d error_log="$WORK/php-error.log" -d log_errors=1 -d display_errors=0 \
        -S "127.0.0.1:$1" -t . router.php >"$WORK/php-server-$1.log" 2>&1 ) &
}
echo "== PHP built-in servers on 127.0.0.1:$WEBPORT and :$WEBPORT2"
start_web "$WEBPORT";  WEBPID=$!
start_web "$WEBPORT2"; WEBPID2=$!
for port in "$WEBPORT" "$WEBPORT2"; do
    for _ in $(seq 1 50); do
        curl -s -o /dev/null "http://127.0.0.1:$port/" && break
        sleep 0.1
    done
done

export HOLD_TEST_BASE_URL="http://127.0.0.1:$WEBPORT"
export HOLD_TEST_BASE_URL_2="http://127.0.0.1:$WEBPORT2"
export HOLD_TEST_PSQL_BIN="$PGBIN/psql"
export HOLD_TEST_PGPORT="$PGPORT_T"
export HOLD_TEST_DB="$DB"
export HOLD_TEST_THROWAWAY=1
export HOLD_TEST_ERROR_LOG="$WORK/php-error.log"

set +e
php -d error_log="$WORK/php-error.log" -d log_errors=1 "$ROOT/tests/hold_flow_pg_test.php"
RC=$?
set -e
if [ "$RC" != "0" ] && [ -n "${HOLD_TEST_SHOW_LOG:-}" ]; then
    echo "---- last lines of the PHP error log ----"; tail -n 40 "$WORK/php-error.log" || true
fi
exit "$RC"
