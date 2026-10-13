# Test PKI fixtures

Throwaway keys and certificates for the test suite and the conformance
references. **Never use them for anything real** — the private keys are
public.

| File | What |
|---|---|
| `stamp-signer.crt` / `.key` | Self-signed RSA-2048 signer with a Cyrillic subject, INN and SNILS; fixed serial `5A17C0DE2026`, valid 2026-10-13 → 2036-10-10. Used by the signature-stamp visual reference, so the stamp text does not change between runs. |
| `root-ca.crt` / `.key` | Test root CA (RSA-2048). Trust it to validate the signatures below. |
| `intermediate-ca.crt` / `.key` | Intermediate CA under the root; issues the signers. |
| `signer-rsa.crt` / `.key` | RSA-2048 signer, digitalSignature + nonRepudiation. |
| `signer-rsa.p12` | The RSA signer with its chain as PKCS#12, password `test`. |
| `signer-ec.crt` / `.key` | ECDSA P-256 signer. |
| `tsa.crt` / `.key` | Timestamp authority (EKU timeStamping, critical) under the root; used by the test TSA (`openssl ts -reply`). |

| `root-ca.crl` / `intermediate-ca.crl` | CRLs of the two CAs, nothing revoked, valid ten years. |
| `ocsp-root.index` / `ocsp-intermediate.index` | `openssl ocsp -index` databases: the CAs answer OCSP for what they issued. |

Certificates name their endpoints under `http://pki.test/` — `ocsp/<ca>`,
`crl/<ca>.crl`, `ca/<ca>.crt` (AIA caIssuers). The tests serve them offline
(`tests/Pdf/Signature/Support/OfflinePkiTransport.php`).

`generate.sh` recreates all but `stamp-signer` (valid ten years from
generation). GOST certificates are not committed: `GostSignatureTest` makes
them on the fly, since it runs only where the gost engine is loaded.
