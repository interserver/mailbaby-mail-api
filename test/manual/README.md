# Manual smoke tests

Ad-hoc scripts that hit a live MailBaby API endpoint. They are **not** run
by `composer test` — they exist to poke a deployed server during development.

## Setup

Export an API key (or put it in the project root `.env`):

```bash
export MAILBABY_API_KEY='your-api-key-here'
export MAILBABY_BASE_URL='https://api.mailbaby.net'   # optional; defaults to prod
export MAILBABY_MAIL_ID='5658'                        # optional; mail order id
```

## Scripts

| Script | What it hits |
|---|---|
| `send_simple.php` | `POST /mail/send` via the SDK |
| `send_simple.sh` | `POST /mail/send` via curl |
| `send_adv_json.php` | `POST /mail/advsend` (JSON body) |
| `send_adv_form.php` | `POST /mail/advsend` (form-encoded body) |
| `send_adv_attachment.php` | `POST /mail/advsend` with base64 attachments |
| `send_adv_attachment.sh` | same, via curl |

Run from the repo root, e.g.:

```bash
php test/manual/send_simple.php
./test/manual/send_simple.sh
php test/manual/send_adv_attachment.php /path/to/file.png
```

## Do not commit API keys

These scripts read `MAILBABY_API_KEY` from the environment on purpose — never
hard-code real keys here. The repo ships to GitHub.
