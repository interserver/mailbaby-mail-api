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
  elixir || true

snap install -y swift-lang || true

echo "[install-security-tools] installing language-specific security tools"

mkdir -p ~/miniconda3;
wget https://repo.anaconda.com/miniconda/Miniconda3-latest-Linux-x86_64.sh -O ~/miniconda3/miniconda.sh;
bash ~/miniconda3/miniconda.sh -b -u -p ~/miniconda3;
rm -rf ~/miniconda3/miniconda.sh;
~/miniconda3/bin/conda init bash;
conda create -n dev python=3.12 -y
conda activate dev
python3 -m pip install --upgrade pip pip-audit safety || true

#curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.4/install.sh | bash
npm install -g npm-check-updates audit-ci || true

go install golang.org/x/vuln/cmd/govulncheck@latest || true

cargo install cargo-audit || true

gem install bundler-audit || true

dotnet tool install --global dotnet-outdated-tool || dotnet tool update --global dotnet-outdated-tool || true

echo "[install-security-tools] done"
