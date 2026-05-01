#!/bin/bash
# Smoke test for /mail/send via curl.
# Usage: MAILBABY_API_KEY=... ./send_simple.sh

set -euo pipefail

: "${MAILBABY_API_KEY:?MAILBABY_API_KEY must be set}"
BASE="${MAILBABY_BASE_URL:-https://api.mailbaby.net}"
FROM="${MAILBABY_FROM:-my@interserver.net}"
TO="${MAILBABY_TO:-detain@interserver.net}"

curl -sS -X POST "$BASE/mail/send" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: $MAILBABY_API_KEY" \
  -d "{
    \"from\": \"$FROM\",
    \"to\": [\"$TO\"],
    \"subject\": \"Smoke test\",
    \"body\": \"This is a smoke test from send_simple.sh.\"
  }"
echo
