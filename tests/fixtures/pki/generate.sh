#!/usr/bin/env bash
# Regenerates the test PKI: root CA → intermediate CA → RSA and ECDSA signers,
# plus a timestamp authority signed by the root. Throwaway keys — the private
# keys are public, never use them for anything real.
#
# Usage: tests/fixtures/pki/generate.sh   (needs the openssl CLI)
set -euo pipefail
cd "$(dirname "$0")"
DAYS=3650
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT

cat > "$tmp/ext.cnf" <<'CNF'
[ca]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
[signer]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, nonRepudiation
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
[tsa]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = critical, timeStamping
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
CNF

# Root CA.
openssl req -x509 -newkey rsa:2048 -nodes -keyout root-ca.key -out root-ca.crt -days $DAYS -sha256 \
    -subj "/C=RU/O=php-pdf test PKI/CN=php-pdf Test Root CA" \
    -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -addext "subjectKeyIdentifier=hash" 2>/dev/null

issue() { # name subj ext issuer keyargs...
    local name=$1 subj=$2 ext=$3 issuer=$4; shift 4
    openssl req -new -newkey "$@" -nodes -keyout "$name.key" -out "$tmp/$name.csr" -subj "$subj" 2>/dev/null
    openssl x509 -req -in "$tmp/$name.csr" -CA "$issuer.crt" -CAkey "$issuer.key" -set_serial "0x$(openssl rand -hex 8)" \
        -days $DAYS -sha256 -extfile "$tmp/ext.cnf" -extensions "$ext" -out "$name.crt" 2>/dev/null
}

issue intermediate-ca "/C=RU/O=php-pdf test PKI/CN=php-pdf Test Intermediate CA" ca root-ca rsa:2048
issue signer-rsa "/C=RU/O=php-pdf test PKI/CN=Test Signer RSA" signer intermediate-ca rsa:2048
issue signer-ec "/C=RU/O=php-pdf test PKI/CN=Test Signer ECDSA" signer intermediate-ca ec -pkeyopt ec_paramgen_curve:P-256
issue tsa "/C=RU/O=php-pdf test PKI/CN=php-pdf Test TSA" tsa root-ca rsa:2048

# PKCS#12 bundle of the RSA signer with its chain, password "test".
openssl pkcs12 -export -inkey signer-rsa.key -in signer-rsa.crt -certfile intermediate-ca.crt \
    -passout pass:test -out signer-rsa.p12 2>/dev/null

echo "generated in $(pwd)"
