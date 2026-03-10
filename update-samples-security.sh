#!/usr/bin/env bash
# update-samples-security.sh
# Runs security audits (and optionally dependency updates) across every
# language ecosystem found under ROOT_DIR.
#
# Usage:
#   ./update-samples-security.sh [ROOT_DIR]
#
# Environment:
#   SECURITY_UPDATE_MODE=update  (default) – update deps, then audit
#   SECURITY_UPDATE_MODE=check   – audit only, no writes
set -uo pipefail
#set -x

ROOT_DIR="${1:-/home/sites/mailbaby-mail-api/mailbaby-api-samples}"
MODE="${SECURITY_UPDATE_MODE:-update}"
export MODE
LOG_PREFIX="[security-update]"

if [ ! -d "$ROOT_DIR" ]; then
  echo "$LOG_PREFIX ERROR: root directory not found: $ROOT_DIR"
  exit 1
fi

# ── NVM / Node path setup ────────────────────────────────────────────────────
#if [ -n "${NVM_DIR:-}" ] && [ -s "$NVM_DIR/nvm.sh" ]; then
#  # shellcheck disable=SC1090
#  . "$NVM_DIR/nvm.sh" --no-use
#  nvm use --lts >/dev/null 2>&1 \
#    || nvm use 20 >/dev/null 2>&1 \
#    || true
#fi

# ── SDKMAN / Kotlin / Scala path setup ──────────────────────────────────────
if [ -s "$HOME/.sdkman/bin/sdkman-init.sh" ]; then
  # shellcheck disable=SC1090
  source "$HOME/.sdkman/bin/sdkman-init.sh" >/dev/null 2>&1 || true
fi

# ── Go path setup ────────────────────────────────────────────────────────────
export PATH="/usr/local/go/bin:$HOME/go/bin:$HOME/.cargo/bin:$HOME/.dotnet/tools:$PATH"

echo "$LOG_PREFIX root: $ROOT_DIR"
echo "$LOG_PREFIX mode: $MODE"
echo ""

# ── Helpers ──────────────────────────────────────────────────────────────────
PASS=0
FAIL=0
SKIP=0

_ok()   { PASS=$((PASS+1)); echo "$LOG_PREFIX [OK]   $*"; }
_fail() { FAIL=$((FAIL+1)); echo "$LOG_PREFIX [FAIL] $*"; }
_skip() { SKIP=$((SKIP+1)); echo "$LOG_PREFIX [SKIP] $*"; }

run_in_dir() {
  local dir="$1" label="$2"
  shift 2
  (
    cd "$dir" || exit 1
    "$@"
  )
  local rc=$?
  if [ $rc -ne 0 ]; then
    _fail "[$label] $dir (rc=$rc)"
  else
    _ok  "[$label] $dir"
  fi
  return 0   # never propagate failure – we track it ourselves
}

find_manifest_dirs() {
  find "$ROOT_DIR" -type f -name "$1" -print 2>/dev/null \
    | sed 's|/[^/]*$||' | sort -u
}

# ── Maven / Java ─────────────────────────────────────────────────────────────
update_maven() {
  if ! command -v mvn >/dev/null 2>&1; then
    _skip "maven (mvn not installed)"; return
  fi
  export MAVEN_OPTS="--enable-native-access=ALL-UNNAMED"
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "maven" bash -c '
      set -uo pipefail
      if [ "$MODE" = "check" ]; then
        mvn -B -q -DskipTests org.owasp:dependency-check-maven:check 2>&1
      else
        mvn -B -q versions:use-latest-releases 2>&1 \
          || mvn -B -q versions:use-latest-versions 2>&1 || true
        mvn -B -q -DskipTests org.owasp:dependency-check-maven:check 2>&1 || true
        rm -rf target pom.xml.versionsBackup
      fi
    '
  done < <(find_manifest_dirs "pom.xml")
}

# ── Gradle / Kotlin (Android, kotlin-server, etc.) ────────────────────────────
update_gradle() {
  if ! command -v gradle >/dev/null 2>&1 && \
     ! find "$ROOT_DIR" -name "gradlew" -type f -maxdepth 4 | read -r; then
    _skip "gradle (neither gradle nor gradlew found)"; return
  fi
  while IFS= read -r wrapper; do
    dir="$(dirname "$wrapper")"
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "gradle" bash -c '
      set -uo pipefail
      GRADLE_CMD="./gradlew"
      [ -f "./gradlew" ] || GRADLE_CMD="gradle"
      if [ "$MODE" = "check" ]; then
        $GRADLE_CMD dependencyUpdates --no-daemon -q 2>&1 || true
      else
        $GRADLE_CMD dependencies --no-daemon -q 2>&1 || true
      fi
    '
  done < <(find "$ROOT_DIR" -name "gradlew" -type f 2>/dev/null | sed 's|/[^/]*$||' | sort -u)
}

# ── SBT / Scala ───────────────────────────────────────────────────────────────
update_sbt() {
  if ! command -v sbt >/dev/null 2>&1; then
    _skip "sbt (not installed)"; return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "sbt" bash -c '
      set -uo pipefail
      if [ "$MODE" = "check" ]; then
        sbt -batch dependencyUpdates 2>&1 | tail -20 || true
      else
        sbt -batch reload "set dependencyUpdates / onlyEval := false" \
          dependencyUpdates 2>&1 | tail -20 || true
      fi
    '
  done < <(find_manifest_dirs "build.sbt")
}

# ── Node / npm / yarn / pnpm ─────────────────────────────────────────────────
update_node() {
  if ! command -v npm >/dev/null 2>&1; then
    _skip "node (npm not installed)"; return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    # skip if it's a node_modules directory itself
    [[ "$dir" == */node_modules* ]] && continue
    run_in_dir "$dir" "node:npm" bash -c '
      set -uo pipefail
      if [ -f yarn.lock ] && command -v yarn >/dev/null 2>&1; then
        if [ "$MODE" = "check" ]; then
          yarn npm audit --all 2>&1 || yarn audit 2>&1 || true
        else
          yarn upgrade 2>&1 || true
          yarn npm audit --all 2>&1 || yarn audit 2>&1 || true
        fi
      elif [ -f pnpm-lock.yaml ] && command -v pnpm >/dev/null 2>&1; then
        if [ "$MODE" = "check" ]; then
          pnpm audit 2>&1 || true
        else
          pnpm up --latest 2>&1 || true
          pnpm audit 2>&1 || true
        fi
      else
        # npm (default / fallback)
        npm install --package-lock-only --ignore-scripts --no-audit >/dev/null 2>&1 || true
        if [ "$MODE" = "check" ]; then
          npm audit --omit=dev 2>&1 || npm audit 2>&1 || true
        else
          npm update 2>&1 || true
          npm audit fix --force 2>&1 || true
        fi
      fi
      rm -rf node_modules
    '
  done < <(find_manifest_dirs "package.json")
}

# ── PHP / Composer ────────────────────────────────────────────────────────────
update_php() {
  if ! command -v composer >/dev/null 2>&1; then
    _skip "php (composer not installed)"; return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "php" bash -c '
      set -uo pipefail
      if [ "$MODE" = "check" ]; then
        composer audit --no-interaction 2>&1 || true
      else
        composer update -W -o --no-interaction 2>&1 || true
        composer audit --no-interaction 2>&1 || true
      fi
      rm -rf vendor
    '
  done < <(find_manifest_dirs "composer.json")
}

# ── Python ────────────────────────────────────────────────────────────────────
update_python() {
  if ! command -v python3 >/dev/null 2>&1; then
    _skip "python (python3 not installed)"; return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "python" bash -c '
      set -uo pipefail
      if [ "$MODE" != "check" ] && [ -f requirements.txt ]; then
        python3 -m pip install --break-system-packages -q -U -r requirements.txt 2>&1 || true
      fi
      if command -v pip-audit >/dev/null 2>&1; then
        if [ "$MODE" = "check" ]; then
          pip-audit -r requirements.txt 2>&1 || true
        else
          pip-audit --fix -r requirements.txt 2>&1 || true
        fi
      fi
    '
  done < <(find "$ROOT_DIR" -type f \
    \( -name "requirements.txt" -o -name "pyproject.toml" -o -name "Pipfile" \) \
    -print 2>/dev/null | sed 's|/[^/]*$||' | sort -u)
}

# ── Ruby / Bundler ────────────────────────────────────────────────────────────
update_ruby() {
  if ! command -v bundle >/dev/null 2>&1; then
    _skip "ruby (bundle not installed)"; return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "ruby" bash -c '
      set -uo pipefail
      # Ensure bundler-audit is available
      gem list bundler-audit -i >/dev/null 2>&1 \
        || gem install bundler-audit --no-document 2>&1 || true
      if [ "$MODE" = "check" ]; then
        bundle-audit check --update 2>&1 || true
      else
        bundle update 2>&1 || true
        bundle-audit check --update 2>&1 || true
      fi
      rm -rf vendor/bundle
    '
  done < <(find_manifest_dirs "Gemfile")
}

# ── Go ────────────────────────────────────────────────────────────────────────
update_go() {
  if ! command -v go >/dev/null 2>&1; then
    _skip "go (not installed)"; return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "go" bash -c '
      set -uo pipefail
      if [ "$MODE" = "check" ]; then
        go list -m -u all 2>/dev/null | grep -v "^\[" | head -20 || true
        command -v govulncheck >/dev/null 2>&1 \
          && govulncheck ./... 2>&1 || true
      else
        go get -u ./... 2>&1 || true
        go mod tidy 2>&1 || true
        command -v govulncheck >/dev/null 2>&1 \
          && govulncheck ./... 2>&1 || true
      fi
    '
  done < <(find_manifest_dirs "go.mod")
}

# ── Rust / Cargo ──────────────────────────────────────────────────────────────
update_rust() {
  if ! command -v cargo >/dev/null 2>&1; then
    _skip "rust (cargo not installed)"; return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "rust" bash -c '
      set -uo pipefail
      if [ "$MODE" = "check" ]; then
        command -v cargo-audit >/dev/null 2>&1 \
          && cargo audit 2>&1 || true
      else
        cargo update 2>&1 || true
        command -v cargo-audit >/dev/null 2>&1 \
          && cargo audit 2>&1 || true
      fi
      rm -rf target
    '
  done < <(find_manifest_dirs "Cargo.toml")
}

# ── .NET / C# ─────────────────────────────────────────────────────────────────
update_dotnet() {
  if ! command -v dotnet >/dev/null 2>&1; then
    _skip "dotnet (not installed)"; return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "dotnet" bash -c '
      set -uo pipefail
      shopt -s nullglob
      files=( *.sln *.csproj *.fsproj )
      [ ${#files[@]} -eq 0 ] && exit 0
      for f in "${files[@]}"; do
        if [ "$MODE" = "check" ]; then
          dotnet list "$f" package --vulnerable 2>&1 || true
        else
          dotnet list "$f" package --outdated 2>&1 || true
        fi
      done
      if [ "$MODE" != "check" ] && command -v dotnet-outdated >/dev/null 2>&1; then
        dotnet-outdated -u:Auto 2>&1 || true
      fi
    '
  done < <(find "$ROOT_DIR" -type f \
    \( -name "*.sln" -o -name "*.csproj" -o -name "*.fsproj" \) \
    -print 2>/dev/null | sed 's|/[^/]*$||' | sort -u)
}

# ── Dart / Pub ────────────────────────────────────────────────────────────────
update_dart() {
  if ! command -v dart >/dev/null 2>&1; then
    _skip "dart (not installed)"; return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "dart" bash -c '
      set -uo pipefail
      if [ "$MODE" = "check" ]; then
        dart pub outdated 2>&1 || true
      else
        dart pub upgrade --major-versions 2>&1 || true
      fi
    '
  done < <(find_manifest_dirs "pubspec.yaml")
}

# ── Elixir / Mix ─────────────────────────────────────────────────────────────
update_elixir() {
  if ! command -v mix >/dev/null 2>&1; then
    _skip "elixir (mix not installed)"; return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "elixir" bash -c '
      set -uo pipefail
      mix local.hex --force >/dev/null 2>&1 || true
      if [ "$MODE" = "check" ]; then
        mix hex.audit 2>&1 || true
      else
        mix deps.update --all 2>&1 || true
        mix hex.audit 2>&1 || true
      fi
    '
  done < <(find_manifest_dirs "mix.exs")
}

# ── Swift / SPM ───────────────────────────────────────────────────────────────
update_swift() {
  if ! command -v swift >/dev/null 2>&1; then
    _skip "swift (not installed)"; return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "swift" bash -c '
      set -uo pipefail
      if [ "$MODE" = "check" ]; then
        swift package show-dependencies 2>&1 || true
      else
        swift package update 2>&1 || true
      fi
    '
  done < <(find_manifest_dirs "Package.swift")
}

# ── R ─────────────────────────────────────────────────────────────────────────
update_r() {
  if ! command -v Rscript >/dev/null 2>&1; then
    _skip "R (Rscript not installed)"; return
  fi
  while IFS= read -r dir; do
    [ -z "$dir" ] && continue
    run_in_dir "$dir" "r" bash -c '
      set -uo pipefail
      if [ "$MODE" = "check" ]; then
        Rscript -e "old.packages()" 2>&1 || true
      else
        Rscript -e "update.packages(ask=FALSE, checkBuilt=TRUE)" 2>&1 || true
      fi
    '
  done < <(find "$ROOT_DIR" -type f \
    \( -name "DESCRIPTION" -o -name "renv.lock" \) \
    -print 2>/dev/null | sed 's|/[^/]*$||' | sort -u)
}

# ── Run all updaters ──────────────────────────────────────────────────────────
update_maven
update_gradle
update_sbt
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

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo "$LOG_PREFIX ── Summary ────────────────────────────────────────────────"
echo "$LOG_PREFIX   PASS=$PASS  FAIL=$FAIL  SKIP=$SKIP"
echo "$LOG_PREFIX done"
