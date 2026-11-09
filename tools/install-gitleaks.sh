#!/usr/bin/env bash
#
# Installs a pinned Gitleaks into tools/bin/ for the pre-commit hook, after checking the release checksum.
# Skips the download when tools/bin/gitleaks already has the pinned version.
# Works on Linux and macOS (bash 3.2), x64 and arm64. To upgrade, change GITLEAKS_VERSION.

set -euo pipefail

GITLEAKS_VERSION="8.30.1"
INSTALL_DIR="tools/bin"
TARGET="$INSTALL_DIR/gitleaks"

if [ -x "$TARGET" ] && [ "$("$TARGET" version 2>/dev/null)" = "$GITLEAKS_VERSION" ]; then
    echo "Gitleaks $GITLEAKS_VERSION is already installed in $INSTALL_DIR."
    exit 0
fi

case "$(uname -s)" in
    Linux) os="linux" ;;
    Darwin) os="darwin" ;;
    *) echo "Unsupported OS $(uname -s). Install Gitleaks $GITLEAKS_VERSION yourself and put it on PATH." >&2; exit 1 ;;
esac

case "$(uname -m)" in
    x86_64 | amd64) arch="x64" ;;
    arm64 | aarch64) arch="arm64" ;;
    *) echo "Unsupported CPU $(uname -m). Install Gitleaks $GITLEAKS_VERSION yourself and put it on PATH." >&2; exit 1 ;;
esac

if command -v sha256sum >/dev/null 2>&1; then
    sha256() { sha256sum "$1" | cut -d ' ' -f 1; }
elif command -v shasum >/dev/null 2>&1; then
    sha256() { shasum -a 256 "$1" | cut -d ' ' -f 1; }
else
    echo "Neither sha256sum nor shasum found; cannot verify the download." >&2
    exit 1
fi

archive="gitleaks_${GITLEAKS_VERSION}_${os}_${arch}.tar.gz"
base_url="https://github.com/gitleaks/gitleaks/releases/download/v${GITLEAKS_VERSION}"
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT

download() {
    if ! curl -fsSL --retry 3 --retry-all-errors -o "$1" "$2"; then
        echo "Could not download $2." >&2
        echo "Try again later, or install Gitleaks with your package manager (for example: brew install gitleaks);" >&2
        echo "the pre-commit hook also uses a gitleaks on PATH." >&2
        exit 1
    fi
}

echo "Downloading Gitleaks $GITLEAKS_VERSION ($os/$arch)..."
download "$work_dir/$archive" "$base_url/$archive"
download "$work_dir/checksums.txt" "$base_url/gitleaks_${GITLEAKS_VERSION}_checksums.txt"

expected="$(grep " ${archive}\$" "$work_dir/checksums.txt" | cut -d ' ' -f 1)"
actual="$(sha256 "$work_dir/$archive")"

if [ -z "$expected" ] || [ "$expected" != "$actual" ]; then
    echo "Checksum mismatch for $archive. Nothing was installed." >&2
    exit 1
fi

tar -xzf "$work_dir/$archive" -C "$work_dir" gitleaks
mkdir -p "$INSTALL_DIR"
mv "$work_dir/gitleaks" "$TARGET"
chmod +x "$TARGET"

echo "Gitleaks $GITLEAKS_VERSION installed in $INSTALL_DIR (checksum verified)."
