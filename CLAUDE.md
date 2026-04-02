# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

MailBaby Mail API is a REST-based email service API built with **PHP + Webman framework** (which uses Workerman as the underlying async event loop). It handles email sending, routing rules, block lists, statistics, and account management for the Mail.Baby platform.

## Common Commands

**Start the server:**
```bash
php start.php
```

**CLI user/config management (via webman console):**
```bash
php webman user:add <username> <password>
php webman user:list
php webman user:show <username>
php webman user:pass <username> <new_password>
php webman user:remove <username>
php webman config:sql
```

**API spec management:**
```bash
./update-spec.sh           # Rebuild public/spec/openapi.{json,yaml}
./update-ui.sh             # Refresh documentation viewer HTML files
./openapi-generator-cli.sh # Regenerate language client libraries
```

**Lint the OpenAPI spec:**
```bash
# Uses .spectral.yaml config
npx @stoplight/spectral-cli lint public/spec/openapi.yaml
```

**Manual API testing:**
```bash
./test_send.sh
./test_attachment.sh
```

## Architecture

### Request Flow
```
HTTP Request
  → CORS middleware (webman/cors plugin)
  → AuthCheck middleware (validates X-API-KEY header against account_security table)
  → Router (config/route.php)
  → Controller
  → Illuminate Database (MySQL / MongoDB)
  → JSON response
```

### Key Files
| Path | Purpose |
|------|---------|
| `config/route.php` | All API endpoint definitions |
| `config/database.php` | Multi-database connection configs |
| `config/server.php` | HTTP server (port 8787, 4 workers, 10MB max packet) |
| `app/middleware/AuthCheck.php` | API key validation |
| `app/controller/Mail.php` | Core email send logic (send, rawsend, advsend, log, view) |
| `app/controller/Mail/Rules.php` | Email routing rule CRUD |
| `app/controller/Mail/Stats.php` | Usage/billing stats from ZoneMTA DB |
| `app/controller/Mail/Blocks.php` | Blocklist management |
| `app/controller/BaseController.php` | `jsonResponse()` / `jsonErrorResponse()` helpers |
| `app/command/` | CLI commands registered with webman console |
| `public/spec/openapi.yaml` | Canonical OpenAPI 3.0 spec (source of truth) |

### Multi-Database Design
The service deliberately queries multiple databases for different concerns:
- **Primary MySQL** (`DB_*` env vars) — customer accounts, mail orders, routing rules, blocks
- **ZoneMTA MySQL** (`ZONEMTA_*`) — email send/receive statistics and message tracking
- **Rspamd MySQL** (`RSPAMD_*`) — spam scores and malware detection results
- **MongoDB** (`MONGO_*`) — user account data (supplementary)
- **Redis** — session storage and caching

### Authentication
All `/mail/*` routes require an `X-API-KEY` header. The middleware resolves it to an account via the `account_security` table on the primary MySQL connection.

### OpenAPI Spec
`public/spec/openapi.yaml` is the canonical spec. The JSON version at `public/spec/openapi.json` is generated from it. Documentation UIs (Swagger UI, ReDoc, RapiDoc, Stoplight Elements, OpenAPI Explorer) all serve from `public/`.

### Response Pattern
Controllers use helpers from `BaseController`:
- `$this->jsonResponse($data)` — 200 success
- `$this->jsonErrorResponse($message, $code)` — error responses

### Environment
Copy `.env.example` to `.env` and configure the database connection strings before starting. The server listens on `0.0.0.0:8787` with 4 worker processes.
