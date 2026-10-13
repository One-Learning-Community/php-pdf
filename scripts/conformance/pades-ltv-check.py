# Usage: ltv_classic.py <pdf> <root.pem> <tsa-ocsp.der>
# Classic pyHanko PAdES-LT / LTA validation, offline: the bootstrap context
# gets only the TSA's current OCSP status (what a validator fetches live);
# everything about the signer must come from the document's DSS.
import sys
from asn1crypto import ocsp
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign.validation import validate_pdf_ltv_signature, RevocationInfoValidationType
from pyhanko.keys import load_cert_from_pemder
from pyhanko_certvalidator import ValidationContext

root = load_cert_from_pemder(sys.argv[2])
tsa_ocsp = ocsp.OCSPResponse.load(open(sys.argv[3], 'rb').read())
with open(sys.argv[1], 'rb') as f:
    r = PdfFileReader(f)
    for sig in r.embedded_regular_signatures:
        for profile in (RevocationInfoValidationType.PADES_LT, RevocationInfoValidationType.PADES_LTA):
            boot = ValidationContext(trust_roots=[root], ocsps=[tsa_ocsp], allow_fetching=False, revocation_mode='hard-fail')
            try:
                st = validate_pdf_ltv_signature(sig, profile, validation_context_kwargs={'trust_roots': [root], 'allow_fetching': False}, bootstrap_validation_context=boot)
                print(f'{profile.value}: {"VALID" if st.bottom_line else "INVALID"} {st.summary()}')
            except Exception as e:
                print(f'{profile.value}: ERROR {type(e).__name__}: {str(e)[:150]}')
