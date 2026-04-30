#!/usr/bin/env bash
set -euo pipefail

# KosmoKrator installer — detects OS/arch and downloads the right binary.
# Usage: curl -fsSL https://raw.githubusercontent.com/OpenCompanyApp/kosmokrator/main/install.sh | bash

REPO="OpenCompanyApp/kosmokrator"
INSTALL_DIR="${INSTALL_DIR:-/usr/local/bin}"
CANONICAL_BIN="kosmo"
LEGACY_BIN="kosmokrator"
ASSET_PREFIX="kosmokrator"

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

ASSET="${ASSET_PREFIX}-${PLATFORM}-${ARCH_SUFFIX}"
URL="https://github.com/${REPO}/releases/latest/download/${ASSET}"

echo "Detected: ${PLATFORM}/${ARCH_SUFFIX}"
echo "Downloading: ${ASSET}"

# Check if we need sudo
NEED_SUDO=""
if [ ! -w "$INSTALL_DIR" ] 2>/dev/null; then
    NEED_SUDO="sudo"
    echo "Note: ${INSTALL_DIR} requires root — using sudo"
fi

$NEED_SUDO mkdir -p "$INSTALL_DIR"
$NEED_SUDO curl -fSL "$URL" -o "${INSTALL_DIR}/${CANONICAL_BIN}"
$NEED_SUDO chmod +x "${INSTALL_DIR}/${CANONICAL_BIN}"

# Keep the old command working. Prefer a symlink, but fall back to a tiny
# wrapper on filesystems that do not allow symlinks.
if ! $NEED_SUDO ln -sfn "${CANONICAL_BIN}" "${INSTALL_DIR}/${LEGACY_BIN}" 2>/dev/null; then
    tmp_wrapper="$(mktemp)"
    cat > "$tmp_wrapper" <<'WRAPPER'
#!/usr/bin/env sh
exec "$(dirname "$0")/kosmo" "$@"
WRAPPER
    $NEED_SUDO mv "$tmp_wrapper" "${INSTALL_DIR}/${LEGACY_BIN}"
    $NEED_SUDO chmod +x "${INSTALL_DIR}/${LEGACY_BIN}"
fi

echo ""
echo "Installed: ${INSTALL_DIR}/${CANONICAL_BIN}"
echo "Compatibility command: ${INSTALL_DIR}/${LEGACY_BIN}"
"${INSTALL_DIR}/${CANONICAL_BIN}" --version 2>/dev/null || true
