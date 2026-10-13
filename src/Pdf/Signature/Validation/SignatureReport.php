<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Validation;

use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;

/**
 * The verdict on one signature or document timestamp of a PDF.
 *
 * `isValid()` is the bottom line: the bytes are intact, the cryptography
 * checks out, the signer chains to a trusted root, nothing revoked it before
 * signing, and nothing changed afterwards that it does not allow. The
 * individual checks explain a negative answer; `errors` says it in words,
 * `warnings` lists what weakens but does not break it.
 */
final class SignatureReport
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** The signed bytes are intact: /ByteRange well formed and its gap exactly the signature. */
    public bool $intact = false;

    /** The CMS signature (or timestamp token) verifies over the signed bytes. */
    public bool $cryptographicallyValid = false;

    /** The signer's certificate chains to a trust anchor. */
    public bool $trusted = false;

    /** @var list<Certificate> signer first, anchor last */
    public array $chain = [];

    public ?Certificate $signer = null;

    /** Time the signer claims (/M or signing-time). */
    public ?\DateTimeImmutable $claimedSigningTime = null;

    /** Time vouched for by a verified timestamp (signature or document timestamp). */
    public ?\DateTimeImmutable $timestampTime = null;

    public RevocationStatus $revocation = RevocationStatus::Unknown;

    /** Where revocation data came from: 'dss', 'fetched', or null. */
    public ?string $revocationSource = null;

    /** The signature is the last thing in the file. */
    public bool $coversWholeFile = false;

    public ModificationLevel $modifications = ModificationLevel::None;

    /** DocMDP level of a certification signature, else null. */
    public ?int $certificationLevel = null;

    public function __construct(
        public readonly string $fieldName,
        /** 'signature' or 'timestamp' (a document timestamp). */
        public readonly string $kind,
        public readonly ?string $subFilter,
        /** Revision: offset where the signed bytes end. */
        public readonly int $signedRevisionLength,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [] && $this->intact && $this->cryptographicallyValid && $this->trusted;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
