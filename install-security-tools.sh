#!/usr/bin/env bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  SUDO="sudo"
else
  SUDO=""
fi

echo "[install-security-tools] installing OS packages"
$SUDO apt-get update
$SUDO apt-get install -y \
  curl \
  wget \
  jq \
  git \
  unzip \
  zip \
  build-essential \
  openjdk-17-jdk \
  maven \
  golang-go \
  rustc cargo \
  ruby-full \
  php-cli composer \
  python3 python3-pip python3-venv \
  dotnet-sdk-8.0 \
  nodejs npm \
  r-base \
  elixir \
  swiftlang || true

echo "[install-security-tools] installing language-specific security tools"

python3 -m pip install --upgrade pip pip-audit safety || true
npm install -g npm-check-updates audit-ci || true
go install golang.org/x/vuln/cmd/govulncheck@latest || true
cargo install cargo-audit || true
gem install bundler-audit || true
dotnet tool install --global dotnet-outdated-tool || dotnet tool update --global dotnet-outdated-tool || true

echo "[install-security-tools] done"
