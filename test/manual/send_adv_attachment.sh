#!/bin/bash
# Smoke test for /mail/advsend with base64 attachments via curl.
# Usage: MAILBABY_API_KEY=... ./send_adv_attachment.sh <file1> [file2]

set -euo pipefail

: "${MAILBABY_API_KEY:?MAILBABY_API_KEY must be set}"
BASE="${MAILBABY_BASE_URL:-https://api.mailbaby.net}"

if [ "$#" -lt 1 ]; then
  echo "Usage: $0 <file1> [file2]" >&2
  exit 1
fi

ATTACHMENTS=""
for f in "$@"; do
  [ -f "$f" ] || { echo "Not a file: $f" >&2; exit 1; }
  data=$(base64 -w 0 "$f")
  if [ -n "$ATTACHMENTS" ]; then ATTACHMENTS="$ATTACHMENTS,"; fi
  ATTACHMENTS="$ATTACHMENTS{\"filename\":\"$(basename "$f")\",\"data\":\"$data\"}"
done

curl -sS -X POST "$BASE/mail/advsend" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: $MAILBABY_API_KEY" \
  -d "{
    \"subject\": \"advsend attachment smoke test\",
    \"body\": \"Manual smoke test\",
    \"from\": {\"email\": \"testing@interserver.net\", \"name\": \"The Man\"},
    \"to\": [{\"email\": \"detain@gmail.com\", \"name\": \"John Doe\"}],
    \"attachments\": [$ATTACHMENTS],
    \"id\": ${MAILBABY_MAIL_ID:-5658}
  }"
echo
