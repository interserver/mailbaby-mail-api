---
name: mailbaby-mail-api
description: Send transactional or bulk email through the Mail.Baby relay (api.mailbaby.net), look up delivery status, manage block lists and deny rules, and pull usage / cost statistics. Use when the user wants to send email, audit what was sent, recover from a bounce, delist a sender, or check sending volume / cost. Works against any MailBaby account once you have an X-API-KEY.
license: GNU GPLv3
metadata:
  homepage: https://www.mail.baby/
  openapi: https://api.mailbaby.net/spec/openapi.yaml
  mcp-server: https://api.mailbaby.net/mcp
  version: "1.5.0"
---

# MailBaby Mail API

REST + MCP API for sending email through Mail.Baby (operated by InterServer).
Every operation is documented in the [OpenAPI 3 spec](https://api.mailbaby.net/spec/openapi.yaml)
and exposed as an MCP tool at `https://api.mailbaby.net/mcp`.

## Authentication

Every protected call requires an `X-API-KEY` header. Get the key from the
[Account Security page](https://my.interserver.net/account_security) on
my.interserver.net. Only `GET /ping` is unauthenticated.

## Choosing the right send endpoint

| You need | Use |
| --- | --- |
| One recipient, plain or HTML body, no attachments | `POST /mail/send` |
| Multiple recipients, CC/BCC, named contacts, attachments, custom Reply-To | `POST /mail/advsend` |
| A pre-built RFC 822 message (e.g. you already DKIM-signed it) | `POST /mail/rawsend` |

All three return `{"status":"ok","text":"<transaction-id>"}` on success. The
transaction id can be passed back to `GET /mail/log` as the `mailid` filter to
look up delivery status.

## Mail orders (the `id` parameter)

Every sending account is backed by one or more **mail orders**. The numeric
`id` of a mail order identifies which set of SMTP credentials the relay uses
to dispatch the message. Almost every endpoint accepts an optional `id` —
when omitted, the API auto-selects your **first active** order.

* `GET /mail` lists every mail order on your account (use this first if you
  don't know your `id`).
* `GET /mail/{id}` returns the order's full detail including its current SMTP
  password (useful only if you want to bypass the REST API and talk to
  `relay.mailbaby.net:25` directly).

## Sending a simple email

```bash
curl -X POST https://api.mailbaby.net/mail/send \
  -H "X-API-KEY: $MAILBABY_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "from": "alerts@yourdomain.com",
    "to": ["customer@example.com"],
    "subject": "Your order has shipped",
    "body": "Tracking number is..."
  }'
```

`from` is used as both the `From:` and `Reply-To:` headers. HTML detection is
automatic — if `body` contains any HTML tags the message is sent as
`text/html`, otherwise `text/plain`.

## Sending with attachments

`POST /mail/advsend` is the only endpoint that takes attachments. Each
attachment is `{filename, data}` with `data` base64-encoded. Address fields
(`from`, `to`, `cc`, `bcc`, `replyto`) accept either a flat email string,
an RFC 822 named string (`"Joe <joe@example.com>"`), or a structured
`{email, name}` object.

```bash
curl -X POST https://api.mailbaby.net/mail/advsend \
  -H "X-API-KEY: $MAILBABY_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "subject": "Your invoice",
    "body":    "<p>Attached.</p>",
    "from":    {"email": "billing@yourdomain.com", "name": "Billing"},
    "to":      [{"email": "customer@example.com"}],
    "attachments": [
      {"filename": "invoice.pdf", "data": "JVBERi0xLjQK..."}
    ]
  }'
```

## Looking up what was sent

`GET /mail/log` is the workhorse for delivery audits. All filters are optional
and combinable. The most useful ones:

* `mailid` — the transaction id returned by a send call.
* `from` / `to` — envelope addresses (exact match).
* `subject` — exact match on the `Subject:` header.
* `messageId` — substring (case-insensitive) match on `Message-ID:`.
* `delivered=1` — only successfully delivered; `delivered=0` — only queued/failed.
* `startDate` / `endDate` — Unix timestamps or `strtotime()`-parseable strings.
* `groupby=message` collapses multi-recipient messages to one row each;
  `groupby=recipient` (default) returns one row per delivery attempt.

Pagination is `skip`/`limit` (max `limit` is 10000). The response includes a
`total` count for page math.

## Block lists vs deny rules

Two independent layers suppress unwanted email:

* **Block lists** (`GET /mail/blocks`, `POST /mail/blocks/delete`) —
  addresses flagged automatically by spam filters (rspamd `LOCAL_BL_RCPT` /
  `MBTRAP` rules and suspicious-subject heuristics). To delist an address,
  send `POST /mail/blocks/delete` with `{"email": "..."}`.
* **Deny rules** (`GET /mail/rules`, `POST /mail/rules`,
  `DELETE /mail/rules/{ruleId}`) — custom rules **you** configure. Four types:
  * `email` — exact match on the SMTP envelope `MAIL FROM` address.
  * `domain` — match any address at this domain.
  * `destination` — exact match on the SMTP envelope `RCPT TO` address.
  * `startswith` — match any sender whose local-part starts with the given
    prefix (alphanumerics + `+_.-` only).

## Usage and cost

`GET /mail/stats?time=...` returns aggregate counts and an estimated billing
total. The `time` query param controls the window for `received`, `sent`, and
the `volume` breakdown (`1h`, `24h`, `7d`, `month`, `day`, `billing`, `all`).
The `usage` and `cost` fields are always for the current billing cycle.

## Errors

All errors return JSON with `code` and `message` fields and an HTTP status.

* `400` — invalid input (bad email, wrong type, missing required field).
* `401` — missing or wrong `X-API-KEY`.
* `404` — no mail order matches the supplied `id` for your account.

## Common workflows

### Resend after a bounce

1. `GET /mail/log?to=<bounced-address>&delivered=0&limit=10` to find recent
   failures.
2. If the address is in a block list, `POST /mail/blocks/delete` with that
   email.
3. Resend with `POST /mail/send` (or `/mail/advsend` if you need the original
   structure).

### Check whether a specific message was delivered

You sent a message and got back `{"text": "185caa69ff7000f47c"}`. To check
its delivery status:

```bash
curl -G "https://api.mailbaby.net/mail/log" \
  -H "X-API-KEY: $MAILBABY_API_KEY" \
  --data-urlencode "mailid=185caa69ff7000f47c"
```

The first row's `delivered` field is `1` for success, `0` for queued/failed,
`null` for not yet attempted. The `response` field carries the SMTP reply
(e.g. `"250 2.0.0 Ok"`) when the destination MX answered.
