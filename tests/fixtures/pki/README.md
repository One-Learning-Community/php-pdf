# Test PKI fixtures

Throwaway keys and certificates for the test suite and the conformance
references. **Never use them for anything real** — the private keys are
public.

| File | What |
|---|---|
| `stamp-signer.crt` / `.key` | Self-signed RSA-2048 signer with a Cyrillic subject, INN and SNILS; fixed serial `5A17C0DE2026`, valid 2026-10-13 → 2036-10-10. Used by the signature-stamp visual reference, so the stamp text does not change between runs. |
