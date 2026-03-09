#!/usr/bin/env bash
set -u

ROOT_DIR="${1:-/home/sites/mailbaby-mail-api/mailbaby-api-samples}"
MODE="${SECURITY_UPDATE_MODE:-update}" # update|check
export MODE
LOG_PREFIX="[security-update]"

if [ ! -d "$ROOT_DIR" ]; then
  echo "$LOG_PREFIX root directory not found: $ROOT_DIR"
  exit 1
fi

if [ -n "${NVM_DIR:-}" ] && [ -s "$NVM_DIR/nvm.sh" ]; then
  . "$NVM_DIR/nvm.sh"
fi
if command -v nvm >/dev/null 2>&1; then
  nvm use 20 >/dev/null 2>&1 || true
fi

echo "$LOG_PREFIX root: $ROOT_DIR"
echo "$LOG_PREFIX mode: $MODE"

run_in_dir() {
  local dir="$1"
  local label="$2"
  shift 2

  echo "$LOG_PREFIX [$label] $dir"
  (
    cd "$dir" || exit 1
    "$@"
  )
  local rc=$?
  if [ $rc -ne 0 ]; then
    echo "$LOG_PREFIX [$label] failed rc=$rc"
  fi
  return 0
}

run_cmd() {
  if [ "$MODE" = "check" ]; then
    "$@"
    return $?
  fi
  "$@"
}

find_manifest_dirs() {
  local pattern="$1"
  find "$ROOT_DIR" -type f -name "$pattern" -print 2>/dev/null | sed 's|/[^/]*$||' | sort -u
}

update_maven() {
  if ! command -v mvn >/dev/null 2>&1; then
    echo "$LOG_PREFIX skipping maven (mvn not installed)"
    return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "maven" bash -lc '
      if [ "$MODE" = "check" ]; then
        mvn -B -q -DskipTests org.owasp:dependency-check-maven:check
      else
        mvn -B -q versions:use-latest-releases || mvn -B -q versions:use-latest-versions
        mvn -B -q -DskipTests org.owasp:dependency-check-maven:check
        rm -rf target pom.xml.versionsBackup
      fi
    '
  done < <(find_manifest_dirs "pom.xml")
}

update_node() {
  if ! command -v npm >/dev/null 2>&1; then
    echo "$LOG_PREFIX skipping node (npm not installed)"
    return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "node" bash -lc '
      if [ -f package-lock.json ] || [ ! -f yarn.lock ]; then
        npm install --package-lock-only --ignore-scripts --no-audit >/dev/null 2>&1 || true
        if [ "$MODE" = "check" ]; then
          npm audit --omit=dev || npm audit || true
        else
          npm update || true
          npm audit fix --force || true
        fi
      fi

      if [ -f yarn.lock ] && command -v yarn >/dev/null 2>&1; then
        if [ "$MODE" = "check" ]; then
          yarn npm audit --all || yarn audit || true
        else
          yarn up --mode=update-lockfile || yarn upgrade || true
        fi
      fi

      if [ -f pnpm-lock.yaml ] && command -v pnpm >/dev/null 2>&1; then
        if [ "$MODE" = "check" ]; then
          pnpm audit || true
        else
          pnpm up --latest || true
        fi
      fi

      rm -rf node_modules
    '
  done < <(find_manifest_dirs "package.json")
}

update_php() {
  if ! command -v composer >/dev/null 2>&1; then
    echo "$LOG_PREFIX skipping php (composer not installed)"
    return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "php" bash -lc '
      if [ "$MODE" = "check" ]; then
        composer audit --no-interaction || true
      else
        composer update -W -o --no-interaction || true
        composer audit --no-interaction || true
      fi
      rm -rf vendor
    '
  done < <(find_manifest_dirs "composer.json")
}

update_python() {
  if ! command -v python3 >/dev/null 2>&1; then
    echo "$LOG_PREFIX skipping python (python3 not installed)"
    return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "python" bash -lc '
      if [ "$MODE" = "check" ]; then
        if command -v pip-audit >/dev/null 2>&1; then
          pip-audit || true
        fi
      else
        if [ -f requirements.txt ]; then
          python3 -m pip install -U -r requirements.txt >/dev/null 2>&1 || true
        fi
        if command -v pip-audit >/dev/null 2>&1; then
          pip-audit --fix || true
        fi
      fi
    '
  done < <(find "$ROOT_DIR" -type f \( -name "requirements.txt" -o -name "pyproject.toml" -o -name "Pipfile" \) -print 2>/dev/null | sed 's|/[^/]*$||' | sort -u)
}

update_ruby() {
  if ! command -v bundle >/dev/null 2>&1; then
    echo "$LOG_PREFIX skipping ruby (bundle not installed)"
    return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "ruby" bash -lc '
      if [ "$MODE" = "check" ]; then
        bundle audit check --update || true
      else
        bundle update || true
        bundle audit check --update || true
      fi
      rm -rf vendor/bundle
    '
  done < <(find_manifest_dirs "Gemfile")
}

update_go() {
  if ! command -v go >/dev/null 2>&1; then
    echo "$LOG_PREFIX skipping go (go not installed)"
    return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "go" bash -lc '
      if [ "$MODE" = "check" ]; then
        go list -m -u all >/dev/null 2>&1 || true
        if command -v govulncheck >/dev/null 2>&1; then
          govulncheck ./... || true
        fi
      else
        go get -u ./... || true
        go mod tidy || true
        if command -v govulncheck >/dev/null 2>&1; then
          govulncheck ./... || true
        fi
      fi
    '
  done < <(find_manifest_dirs "go.mod")
}

update_rust() {
  if ! command -v cargo >/dev/null 2>&1; then
    echo "$LOG_PREFIX skipping rust (cargo not installed)"
    return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "rust" bash -lc '
      if [ "$MODE" = "check" ]; then
        if command -v cargo-audit >/dev/null 2>&1; then
          cargo audit || true
        fi
      else
        cargo update || true
        if command -v cargo-audit >/dev/null 2>&1; then
          cargo audit || true
        fi
      fi
      rm -rf target
    '
  done < <(find_manifest_dirs "Cargo.toml")
}

update_dotnet() {
  if ! command -v dotnet >/dev/null 2>&1; then
    echo "$LOG_PREFIX skipping dotnet (dotnet not installed)"
    return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "dotnet" bash -lc '
      shopt -s nullglob
      files=( *.sln *.csproj *.fsproj )
      if [ ${#files[@]} -eq 0 ]; then
        exit 0
      fi
      for f in "${files[@]}"; do
        if [ "$MODE" = "check" ]; then
          dotnet list "$f" package --vulnerable || true
        else
          dotnet list "$f" package --outdated || true
        fi
      done
      if [ "$MODE" != "check" ] && command -v dotnet-outdated >/dev/null 2>&1; then
        dotnet outdated -u:Auto || true
      fi
    '
  done < <(find "$ROOT_DIR" -type f \( -name "*.sln" -o -name "*.csproj" -o -name "*.fsproj" \) -print 2>/dev/null | sed 's|/[^/]*$||' | sort -u)
}

update_dart() {
  if ! command -v dart >/dev/null 2>&1; then
    echo "$LOG_PREFIX skipping dart (dart not installed)"
    return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "dart" bash -lc '
      if [ "$MODE" = "check" ]; then
        dart pub outdated || true
      else
        dart pub upgrade --major-versions || true
      fi
    '
  done < <(find_manifest_dirs "pubspec.yaml")
}

update_elixir() {
  if ! command -v mix >/dev/null 2>&1; then
    echo "$LOG_PREFIX skipping elixir (mix not installed)"
    return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "elixir" bash -lc '
      if [ "$MODE" = "check" ]; then
        mix hex.audit || true
      else
        mix deps.update --all || true
        mix hex.audit || true
      fi
    '
  done < <(find_manifest_dirs "mix.exs")
}

update_swift() {
  if ! command -v swift >/dev/null 2>&1; then
    echo "$LOG_PREFIX skipping swift (swift not installed)"
    return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "swift" bash -lc '
      if [ "$MODE" = "check" ]; then
        swift package show-dependencies >/dev/null 2>&1 || true
      else
        swift package update || true
      fi
    '
  done < <(find_manifest_dirs "Package.swift")
}

update_r() {
  if ! command -v Rscript >/dev/null 2>&1; then
    echo "$LOG_PREFIX skipping r (Rscript not installed)"
    return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "r" bash -lc '
      if [ "$MODE" = "check" ]; then
        Rscript -e "old.packages()" || true
      else
        Rscript -e "update.packages(ask=FALSE, checkBuilt=TRUE)" || true
      fi
    '
  done < <(find "$ROOT_DIR" -type f \( -name "DESCRIPTION" -o -name "renv.lock" \) -print 2>/dev/null | sed 's|/[^/]*$||' | sort -u)
}

update_maven
update_node
update_php
update_python
update_ruby
update_go
update_rust
update_dotnet
update_dart
update_elixir
update_swift
update_r

echo "$LOG_PREFIX done"


