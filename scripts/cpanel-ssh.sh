#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: scripts/cpanel-ssh.sh [remote-command ...]

Connects through the existing SSH config alias and converts the configured
PuTTY key to a temporary OpenSSH key for this process only.

Environment:
  CPANEL_SSH_TARGET       SSH config alias (default: community-production)
  CPANEL_PPK              PuTTY private key path
  CPANEL_PASSPHRASE_FILE  file containing the key passphrase
EOF
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

target="${CPANEL_SSH_TARGET:-community-production}"
ppk="${CPANEL_PPK:-}"
passphrase_file="${CPANEL_PASSPHRASE_FILE:-}"

if ! command -v puttygen >/dev/null 2>&1; then
    printf 'Error: puttygen is required to convert the PuTTY key.\n' >&2
    exit 127
fi

if ! command -v ssh >/dev/null 2>&1; then
    printf 'Error: ssh is required.\n' >&2
    exit 127
fi

if [[ -z "$ppk" || ! -r "$ppk" ]]; then
    printf 'Error: set CPANEL_PPK to a readable local PuTTY key path.\n' >&2
    printf 'Do not copy the key into the repository.\n' >&2
    exit 2
fi

if [[ -z "$passphrase_file" || ! -r "$passphrase_file" ]]; then
    printf 'Error: set CPANEL_PASSPHRASE_FILE to a readable owner-protected file.\n' >&2
    exit 2
fi

umask 077
keyfile="$(mktemp "${TMPDIR:-/tmp}/community-cpanel-key.XXXXXX")"
trap 'rm -f -- "$keyfile"' EXIT

puttygen "$ppk" \
    --old-passphrase "$passphrase_file" \
    --new-passphrase /dev/null \
    -O private-openssh \
    -o "$keyfile"

ssh -i "$keyfile" \
    -o IdentitiesOnly=yes \
    -o BatchMode=yes \
    "$target" "$@"
