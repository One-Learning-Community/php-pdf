#!/bin/sh
# Switches the gost engine on in the stock openssl.cnf.
#
# `engines` is added to the EXISTING init section rather than replacing the
# config: a minimal config of one's own drops the [req] sections and the RNG
# settings, and then RSA breaks too. A second `openssl_conf` line is silently
# ignored when the file already has one (alpine's does).
set -e

CNF="$(openssl version -d | sed 's/OPENSSLDIR: //; s/"//g')/openssl.cnf"
grep -q 'phppdf_gost' "$CNF" && exit 0

INIT="$(grep -oE '^openssl_conf *= *[A-Za-z0-9_]+' "$CNF" | head -1 | sed 's/.*= *//')"
if [ -z "$INIT" ]; then
    echo "enable-gost-engine: no openssl_conf directive in $CNF" >&2
    exit 1
fi

awk -v sect="[$INIT]" '
    { print }
    $0 == sect && !done { print "engines = phppdf_engines"; done = 1 }
' "$CNF" > "$CNF.new"

cat >> "$CNF.new" <<'CFG'

[phppdf_engines]
gost = phppdf_gost

[phppdf_gost]
engine_id = gost
# ALL: the engine serves only its own algorithms; RSA and ECDSA stay with the
# default provider.
default_algorithms = ALL
CFG

mv "$CNF.new" "$CNF"
