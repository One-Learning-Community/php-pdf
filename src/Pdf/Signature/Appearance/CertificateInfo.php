<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Appearance;

/**
 * What a signature stamp shows about the signing certificate.
 *
 * Read from an X.509 certificate through `openssl_x509_parse()`. Besides the
 * common subject fields it picks up the identifiers Russian qualified
 * certificates carry in the subject (INN, INN of a legal entity, OGRN,
 * OGRNIP, SNILS) — OpenSSL knows them by short name, and older builds report
 * them by OID, so both spellings are looked up.
 */
final readonly class CertificateInfo
{
    /** Subject attribute → [OpenSSL short name, dotted OID]. */
    private const RU_IDENTIFIERS = [
        'inn' => ['INN', '1.2.643.3.131.1.1'],
        'innle' => ['INNLE', '1.2.643.100.4'],
        'ogrn' => ['OGRN', '1.2.643.100.1'],
        'ogrnip' => ['OGRNIP', '1.2.643.100.5'],
        'snils' => ['SNILS', '1.2.643.100.3'],
    ];

    public function __construct(
        public string $commonName = '',
        public string $organization = '',
        public string $email = '',
        public string $serialHex = '',
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $validTo = null,
        public string $issuerCommonName = '',
        public string $inn = '',
        public string $innLe = '',
        public string $ogrn = '',
        public string $ogrnip = '',
        public string $snils = '',
    ) {}

    public static function fromPem(string $certificatePem): self
    {
        if (! function_exists('openssl_x509_parse')) {
            throw new \RuntimeException('Reading a certificate requires the openssl extension');
        }
        $parsed = openssl_x509_parse($certificatePem);
        if ($parsed === false) {
            throw new \InvalidArgumentException('Not a readable X.509 certificate');
        }

        return self::fromParsed($parsed);
    }

    /**
     * @param  array<string, mixed>  $parsed  The result of openssl_x509_parse()
     */
    public static function fromParsed(array $parsed): self
    {
        $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $issuer = is_array($parsed['issuer'] ?? null) ? $parsed['issuer'] : [];

        $ru = [];
        foreach (self::RU_IDENTIFIERS as $key => [$shortName, $oid]) {
            $ru[$key] = self::attribute($subject, $shortName, $oid);
        }

        return new self(
            commonName: self::attribute($subject, 'CN', '2.5.4.3'),
            organization: self::attribute($subject, 'O', '2.5.4.10'),
            email: self::attribute($subject, 'emailAddress', '1.2.840.113549.1.9.1'),
            serialHex: strtoupper((string) ($parsed['serialNumberHex'] ?? '')),
            validFrom: self::timestamp($parsed['validFrom_time_t'] ?? null),
            validTo: self::timestamp($parsed['validTo_time_t'] ?? null),
            issuerCommonName: self::attribute($issuer, 'CN', '2.5.4.3'),
            inn: $ru['inn'],
            innLe: $ru['innle'],
            ogrn: $ru['ogrn'],
            ogrnip: $ru['ogrnip'],
            snils: $ru['snils'],
        );
    }

    /**
     * Stamp placeholders this certificate fills, without the braces.
     *
     * @return array<string, string>
     */
    public function placeholders(string $dateFormat): array
    {
        return [
            'cn' => $this->commonName,
            'org' => $this->organization,
            'email' => $this->email,
            'serial' => $this->serialHex,
            'validFrom' => $this->validFrom?->format($dateFormat) ?? '',
            'validTo' => $this->validTo?->format($dateFormat) ?? '',
            'issuer' => $this->issuerCommonName,
            'inn' => $this->inn,
            'innle' => $this->innLe,
            'ogrn' => $this->ogrn,
            'ogrnip' => $this->ogrnip,
            'snils' => $this->snils,
        ];
    }

    /**
     * A subject attribute by short name or OID; a repeated attribute comes
     * back as a list and is joined.
     *
     * @param  array<string, mixed>  $name
     */
    private static function attribute(array $name, string $shortName, string $oid): string
    {
        $value = $name[$shortName] ?? $name[$oid] ?? '';
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }

        return trim((string) $value);
    }

    private static function timestamp(mixed $value): ?\DateTimeImmutable
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        return (new \DateTimeImmutable('@'.$value))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
