# Security Update Workflow

This project updates generated clients in `mailbaby-api-samples` across multiple language ecosystems.

## 1) Install security tooling

Run on Ubuntu/Debian:

```bash
./install-security-tools.sh
```

The installer sets up tooling used by `update-samples-security.sh`, including:

- Java/Maven: `mvn`, OWASP dependency-check plugin
- Node: `npm`, `audit-ci`, `npm-check-updates`
- PHP: `composer`
- Python: `pip-audit`, `safety`
- Ruby: `bundler-audit`
- Go: `govulncheck`
- Rust: `cargo-audit`
- .NET: `dotnet`, `dotnet-outdated`
- Dart: `dart pub`
- Elixir: `mix hex.audit`
- Swift: `swift package`
- R: `Rscript`

## 2) Run security updates

```bash
./update-samples-security.sh /home/sites/mailbaby-mail-api/mailbaby-api-samples
```

Environment variables:

- `SECURITY_UPDATE_MODE=update` (default): updates dependencies where possible and runs checks.
- `SECURITY_UPDATE_MODE=check`: audit/check only, no dependency upgrades.

Example check-only run:

```bash
SECURITY_UPDATE_MODE=check ./update-samples-security.sh /home/sites/mailbaby-mail-api/mailbaby-api-samples
```

## Notes

- The script is resilient: one ecosystem failure does not stop the whole run.
- Missing tools are skipped with a clear log message.
- Temporary dependency directories are cleaned when practical (`node_modules`, `vendor`, `target`).
