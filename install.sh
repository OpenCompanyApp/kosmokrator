#!/usr/bin/env bash
set -euo pipefail

# KosmoKrator installer — detects OS/arch and downloads the right binary.
# Usage: curl -fsSL https://raw.githubusercontent.com/OpenCompanyApp/kosmokrator/main/install.sh | bash

REPO="OpenCompanyApp/kosmokrator"
INSTALL_DIR="/usr/local/bin"
BIN_NAME="kosmokrator"

# Detect platform
OS="$(uname -s)"
ARCH="$(uname -m)"

case "$OS" in
    Darwin) PLATFORM="macos" ;;
    Linux)  PLATFORM="linux" ;;
    *)
        echo "Error: Unsupported OS: $OS" >&2
        exit 1
        ;;
esac

case "$ARCH" in
    arm64|aarch64) ARCH_SUFFIX="aarch64" ;;
    x86_64|amd64)  ARCH_SUFFIX="x86_64" ;;
    *)
        echo "Error: Unsupported architecture: $ARCH" >&2
        exit 1
        ;;
esac

ASSET="${BIN_NAME}-${PLATFORM}-${ARCH_SUFFIX}"
URL="https://github.com/${REPO}/releases/latest/download/${ASSET}"

echo "Detected: ${PLATFORM}/${ARCH_SUFFIX}"
echo "Downloading: ${ASSET}"

# Check if we need sudo
NEED_SUDO=""
if [ ! -w "$INSTALL_DIR" ] 2>/dev/null; then
    NEED_SUDO="sudo"
    echo "Note: ${INSTALL_DIR} requires root — using sudo"
fi

$NEED_SUDO curl -fSL "$URL" -o "${INSTALL_DIR}/${BIN_NAME}"
$NEED_SUDO chmod +x "${INSTALL_DIR}/${BIN_NAME}"

echo ""
echo "Installed: ${INSTALL_DIR}/${BIN_NAME}"
"${INSTALL_DIR}/${BIN_NAME}" --version 2>/dev/null || true
