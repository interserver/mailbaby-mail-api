#!/usr/bin/env bash
# install-security-tools.sh
# Installs all runtimes and security-audit tools needed to generate and
# security-update API client samples across every supported language.
# Tested on Ubuntu 24.04 LTS.
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  SUDO="sudo"
else
  SUDO=""
fi

if [ "$(which gnatpp)" = "" ]; then
  wget https://github.com/alire-project/alire/releases/download/v2.1.0/alr-2.1.0-bin-x86_64-linux.zip
  unzip alr-2.1.0-bin-x86_64-linux.zip
  mv bin/alr /usr/local/bin/alr
  rm -f alr-2.1.0-bin-x86_64-linux.zip
  alr install
  alr install libadalang_tools
  source ~ /.profile
  echo 'export PATH="$HOME/.alire/bin:$PATH"' >> ~/.bashrc
fi

export DEBIAN_FRONTEND=noninteractive

echo "[install] updating package index"
$SUDO apt-get update -qq

echo "[install] installing base build tools and OS-level runtimes"
$SUDO apt-get install -y \
  curl wget git unzip zip ca-certificates gnupg lsb-release \
  build-essential pkg-config \
  libssl-dev libffi-dev zlib1g-dev libxml2-dev libxslt1-dev \
  libreadline-dev libyaml-dev libsqlite3-dev \
  openjdk-21-jdk maven \
  ruby-full ruby-dev \
  php-cli composer \
  python3 python3-pip python3-venv python3-dev \
  r-base r-base-dev \
  elixir erlang-dev \
  libbz2-dev libncurses-dev \
  || true

# ── Go (official installer, not the stale apt package) ──────────────────────
echo "[install] installing Go (official)"
GO_VERSION="1.23.5"
GO_ARCH="amd64"
GO_TAR="go${GO_VERSION}.linux-${GO_ARCH}.tar.gz"
if ! command -v go >/dev/null 2>&1 || [[ "$(go version 2>/dev/null | awk '{print $3}')" != "go${GO_VERSION}" ]]; then
  wget -q "https://go.dev/dl/${GO_TAR}" -O "/tmp/${GO_TAR}"
  $SUDO rm -rf /usr/local/go
  $SUDO tar -C /usr/local -xzf "/tmp/${GO_TAR}"
  rm -f "/tmp/${GO_TAR}"
  if ! grep -q '/usr/local/go/bin' /etc/environment 2>/dev/null; then
    echo 'PATH="/usr/local/go/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"' \
      | $SUDO tee /etc/environment > /dev/null
  fi
  export PATH="/usr/local/go/bin:$PATH"
fi
echo "  go: $(go version)"

# ── Node.js (via NodeSource – LTS) ──────────────────────────────────────────
echo "[install] installing Node.js LTS"
if ! command -v node >/dev/null 2>&1; then
  curl -fsSL https://deb.nodesource.com/setup_lts.x | $SUDO -E bash -
  $SUDO apt-get install -y nodejs
fi
echo "  node: $(node --version)  npm: $(npm --version)"

echo "[install] installing global Node tools"
$SUDO npm install -g npm@latest npm-check-updates audit-ci yarn pnpm || true

# ── Dart SDK ─────────────────────────────────────────────────────────────────
echo "[install] installing Dart SDK"
if ! command -v dart >/dev/null 2>&1; then
  $SUDO sh -c 'wget -qO- https://dl-ssl.google.com/linux/linux_signing_key.pub \
    | gpg --dearmor -o /usr/share/keyrings/dart.gpg'
  $SUDO sh -c 'echo "deb [signed-by=/usr/share/keyrings/dart.gpg arch=amd64] \
    https://storage.googleapis.com/download.dartlang.org/linux/debian stable main" \
    > /etc/apt/sources.list.d/dart_stable.list'
  $SUDO apt-get update -qq
  $SUDO apt-get install -y dart
fi
echo "  dart: $(dart --version 2>&1 | head -1)"

# ── .NET SDK ──────────────────────────────────────────────────────────────────
echo "[install] installing .NET SDK"
if ! command -v dotnet >/dev/null 2>&1; then
  wget -q https://dot.net/v1/dotnet-install.sh -O /tmp/dotnet-install.sh
  chmod +x /tmp/dotnet-install.sh
  /tmp/dotnet-install.sh --channel 8.0 --install-dir /usr/local/dotnet
  $SUDO ln -sf /usr/local/dotnet/dotnet /usr/local/bin/dotnet
  rm -f /tmp/dotnet-install.sh
fi
echo "  dotnet: $(dotnet --version)"

# ── Kotlin & SBT (for Scala) via SDKMAN ──────────────────────────────────────
echo "[install] installing SDKMAN, Kotlin, Scala, SBT"
if [ ! -d "$HOME/.sdkman" ]; then
  curl -fsSL "https://get.sdkman.io" | bash || true
fi
if [ -s "$HOME/.sdkman/bin/sdkman-init.sh" ]; then
  # shellcheck disable=SC1090
  source "$HOME/.sdkman/bin/sdkman-init.sh" || true
  sdk install kotlin  || sdk update kotlin  || true
  sdk install scala   || sdk update scala   || true
  sdk install sbt     || sdk update sbt     || true
fi

# ── Rust via rustup ───────────────────────────────────────────────────────────
echo "[install] installing Rust via rustup"
if ! command -v cargo >/dev/null 2>&1; then
  curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \
    | sh -s -- -y --no-modify-path
fi
# shellcheck disable=SC1090
source "$HOME/.cargo/env" 2>/dev/null || export PATH="$HOME/.cargo/bin:$PATH"
echo "  rustc: $(rustc --version)"

# ── Swift (snap – requires --classic) ────────────────────────────────────────
echo "[install] installing Swift via swiftly"
if ! command -v swift >/dev/null 2>&1; then
  curl -O https://download.swift.org/swiftly/linux/swiftly-$(uname -m).tar.gz && \
    tar zxf swiftly-$(uname -m).tar.gz && \
    ./swiftly init --quiet-shell-followup && \
    . "${SWIFTLY_HOME_DIR:-$HOME/.local/share/swiftly}/env.sh" && \
    hash -r
fi
command -v swift >/dev/null 2>&1 && echo "  swift: $(swift --version 2>&1 | head -1)" || true

# ── Python security tools ─────────────────────────────────────────────────────
echo "[install] installing Python security tools"
python3 -m pip install --break-system-packages --upgrade pip pip-audit safety || true

# ── Go security tools ─────────────────────────────────────────────────────────
echo "[install] installing Go security tools"
export PATH="/usr/local/go/bin:$HOME/go/bin:$PATH"
go install golang.org/x/vuln/cmd/govulncheck@latest  || true

# ── Rust security tools ───────────────────────────────────────────────────────
echo "[install] installing Rust security tools"
cargo install cargo-audit --locked || cargo install cargo-audit || true

# ── Ruby security tools ───────────────────────────────────────────────────────
echo "[install] installing Ruby security tools"
# Ensure native extension dependencies are present
$SUDO apt-get install -y libpq-dev || true
gem install bundler bundler-audit --no-document || true

# ── .NET security tools ───────────────────────────────────────────────────────
echo "[install] installing .NET security tools"
dotnet tool install --global dotnet-outdated-tool \
  || dotnet tool update --global dotnet-outdated-tool \
  || true
# Add dotnet tools to PATH
export PATH="$HOME/.dotnet/tools:$PATH"

# ── Elixir / Mix audit ────────────────────────────────────────────────────────
echo "[install] installing Elixir hex audit tools"
mix local.hex --force || true
mix local.rebar --force || true
mix archive.install hex mix_audit --force || true

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo "[install] ── Installed tool versions ──────────────────────────────────"
for cmd in java mvn node npm yarn pnpm dart dotnet go rustc cargo ruby gem \
           bundle python3 pip3 Rscript elixir mix swift kotlin sbt scala; do
  if command -v "$cmd" >/dev/null 2>&1; then
    ver=$("$cmd" --version 2>&1 | head -1 | sed 's/^[[:space:]]*//')
    printf "  %-12s %s\n" "$cmd" "$ver"
  else
    printf "  %-12s %s\n" "$cmd" "(not found)"
  fi
done
echo "[install] done"
