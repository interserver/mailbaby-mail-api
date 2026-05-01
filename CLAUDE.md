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

**Run PHPUnit tests:**
```bash
php vendor/bin/phpunit
```

**Manual API testing:**
```bash
test/manual/send_simple.sh
test/manual/send_adv_attachment.sh
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
| `app/controller/Mcp.php` | MCP server endpoint + agent-readiness well-known routes |
| `app/Mcp/OpenApiParser.php` | Parses `public/spec/openapi.yaml` into MCP tool definitions (cached under `runtime/mcp/cache/`) |
| `app/Mcp/McpServerFactory.php` | Builds the MCP server instance from parsed tools |
| `app/Mcp/Bridge.php` | Dispatches MCP tool calls back into Webman controllers |
| `app/Mcp/Skills/` | Agent Skills 0.2.0 SKILL.md files served via `/.well-known/agent-skills/` |
| `app/controller/BaseController.php` | `jsonResponse()` / `jsonErrorResponse()` helpers |
| `app/command/` | CLI commands registered with webman console |
| `public/spec/openapi.yaml` | Canonical OpenAPI 3.0 spec (source of truth) |
| `public/webmcp.js` | WebMCP browser-side manifest helper |
| `phpunit.xml.dist` | PHPUnit config — points at `test/` (singular), bootstrap `test/bootstrap.php` |

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

### MCP Server
The MCP (Model Context Protocol) server is mounted at `/mcp` (Streamable HTTP) and exposes the OpenAPI spec as MCP tools. Agent-readiness discovery endpoints are served from `app/controller/Mcp.php`: `/.well-known/mcp/server-card.json` (canonical), `/.well-known/mcp.json`, `/.well-known/mcp`, `/.well-known/mcp/server.json` (legacy aliases), `/.well-known/webmcp{,.json}`, `/.well-known/agent-skills/index.json`, `/.well-known/oauth-protected-resource`, and `/.well-known/api-catalog`. Public assets `public/llms.txt`, `public/robots.txt`, and `public/sitemap.xml` complement the discovery surface.

### Response Pattern
Controllers use helpers from `BaseController`:
- `$this->jsonResponse($data)` — 200 success
- `$this->jsonErrorResponse($message, $code)` — error responses

### Environment
Copy `.env.example` to `.env` and configure the database connection strings before starting. The server listens on `0.0.0.0:8787` with 4 worker processes.

<!-- caliber:managed:pre-commit -->
## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/copilot-instructions.md .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->

<!-- caliber:managed:model-config -->
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

<!-- /caliber:managed:model-config -->

<!-- caliber:managed:sync -->
## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
<!-- /caliber:managed:sync -->
