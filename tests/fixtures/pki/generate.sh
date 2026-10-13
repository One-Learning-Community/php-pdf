#!/usr/bin/env bash
# Regenerates the test PKI: root CA → intermediate CA → RSA and ECDSA signers,
# plus a timestamp authority signed by the root. Certificates name OCSP and CRL
# locations under http://pki.test/ (served offline by the tests); the CRLs and
# the OCSP index are generated too. Throwaway keys — the private keys are
# public, never use them for anything real.
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
authorityInfoAccess = OCSP;URI:http://pki.test/ocsp/root, caIssuers;URI:http://pki.test/ca/root-ca.crt
crlDistributionPoints = URI:http://pki.test/crl/root.crl
[signer]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, nonRepudiation
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
authorityInfoAccess = OCSP;URI:http://pki.test/ocsp/intermediate, caIssuers;URI:http://pki.test/ca/intermediate-ca.crt
crlDistributionPoints = URI:http://pki.test/crl/intermediate.crl
[tsa]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = critical, timeStamping
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
authorityInfoAccess = OCSP;URI:http://pki.test/ocsp/root, caIssuers;URI:http://pki.test/ca/root-ca.crt
crlDistributionPoints = URI:http://pki.test/crl/root.crl
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

# CRLs (nothing revoked, valid ten years) and the OCSP responder index of
# each CA, for openssl ocsp -index.
crl() { # ca
    local ca=$1 d="$tmp/$1-db"
    mkdir -p "$d"; : > "$d/index.txt"; echo 01 > "$d/crlnumber"
    cat > "$d/ca.cnf" <<CNF
[ca]
default_ca = ca_default
[ca_default]
database = $d/index.txt
crlnumber = $d/crlnumber
default_md = sha256
default_crl_days = $DAYS
CNF
    openssl ca -gencrl -config "$d/ca.cnf" -keyfile "$ca.key" -cert "$ca.crt" -outdir "$d" -out "$tmp/$ca.crl.pem" 2>/dev/null
    openssl crl -in "$tmp/$ca.crl.pem" -outform DER -out "$ca.crl"
}
crl root-ca
crl intermediate-ca

index_line() { # cert
    local end serial subject
    # notAfter in ISO 8601 → the index's YYMMDDHHMMSSZ.
    end=$(openssl x509 -in "$1" -noout -enddate -dateopt iso_8601 | sed -E 's/notAfter=[0-9]{2}([0-9]{2})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})Z/\1\2\3\4\5\6Z/')
    serial=$(openssl x509 -in "$1" -noout -serial | sed 's/serial=//')
    subject=$(openssl x509 -in "$1" -noout -subject -nameopt compat | sed 's/subject=//')
    printf 'V\t%s\t\t%s\tunknown\t%s\n' "$end" "$serial" "$subject"
}
{ index_line intermediate-ca.crt; index_line tsa.crt; } > ocsp-root.index
{ index_line signer-rsa.crt; index_line signer-ec.crt; } > ocsp-intermediate.index

# PKCS#12 bundle of the RSA signer with its chain, password "test".
openssl pkcs12 -export -inkey signer-rsa.key -in signer-rsa.crt -certfile intermediate-ca.crt \
    -passout pass:test -out signer-rsa.p12 2>/dev/null

echo "generated in $(pwd)"
