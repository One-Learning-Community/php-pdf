<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Tsa;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Asn1Exception;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Asn1Node;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Der;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpTransport;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\StreamHttpTransport;

/**
 * RFC 3161 timestamp authority client.
 *
 * Sends a `TimeStampReq` over HTTP and checks the answer: granted status,
 * the token is SignedData over a `TSTInfo`, and the TSTInfo carries the
 * imprint and nonce that were asked for. The token's own signature is the
 * validator's business, as for any CMS signature.
 */
final class TsaClient
{
    private readonly HttpTransport $transport;

    /**
     * @param  array<string, string>  $headers  Extra request headers (an API key, say).
     * @param  ?string  $policyOid  TSA policy to request; null — the TSA's default.
     */
    public function __construct(
        public readonly string $url,
        private readonly DigestAlgorithm $digest = DigestAlgorithm::Sha256,
        ?HttpTransport $transport = null,
        private readonly ?string $username = null,
        #[\SensitiveParameter] private readonly ?string $password = null,
        private readonly array $headers = [],
        private readonly ?string $policyOid = null,
        private readonly float $timeoutSeconds = 15.0,
    ) {
        if (! preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('TSA URL must be http(s)');
        }
        $this->transport = $transport ?? new StreamHttpTransport;
    }

    /** Timestamps `$data` (for a signature: the signature value). */
    public function timestamp(string $data): TimeStampToken
    {
        $imprint = $this->digest->digest($data);
        $nonce = random_bytes(8);
        $request = $this->request($imprint, $nonce);

        $headers = [
            'Content-Type' => 'application/timestamp-query',
            'Accept' => 'application/timestamp-reply',
            ...$this->headers,
        ];
        if ($this->username !== null) {
            $headers['Authorization'] = 'Basic '.base64_encode($this->username.':'.($this->password ?? ''));
        }

        try {
            $response = $this->transport->post($this->url, $request, $headers, $this->timeoutSeconds);
        } catch (\RuntimeException $e) {
            throw new TsaException("TSA {$this->url} unreachable: ".$e->getMessage(), previous: $e);
        }
        if ($response->status !== 200) {
            throw new TsaException(sprintf('TSA %s answered HTTP %d', $this->url, $response->status));
        }

        return $this->parseResponse($response->body, $imprint, $nonce);
    }

    /** DER `TimeStampReq`. */
    public function request(string $imprint, string $nonce): string
    {
        $fields = [
            Der::integer(1),
            Der::sequence(Der::algorithm($this->digest->oid()), Der::octetString($imprint)),
        ];
        if ($this->policyOid !== null) {
            $fields[] = Der::oid($this->policyOid);
        }
        $fields[] = Der::integerFromBytes($nonce);
        // certReq: the TSA certificate goes into the token.
        $fields[] = Der::boolean(true);

        return Der::sequence(...$fields);
    }

    public function parseResponse(string $der, string $imprint, string $nonce): TimeStampToken
    {
        try {
            $resp = DerReader::parse($der)->expect(Der::TAG_SEQUENCE);
            $statusInfo = $resp->child(0)->expect(Der::TAG_SEQUENCE);
            $status = $statusInfo->child(0)->int();
            // 0 granted, 1 grantedWithMods.
            if ($status !== 0 && $status !== 1) {
                throw new TsaException(sprintf('TSA refused the request (status %d%s)', $status, self::statusText($statusInfo)));
            }
            $token = $resp->children[1] ?? throw new TsaException('TSA granted the request but sent no token');

            $tstInfo = self::tstInfo($token);
            $messageImprint = $tstInfo->child(2)->expect(Der::TAG_SEQUENCE);
            if ($messageImprint->child(1)->expect(Der::TAG_OCTET_STRING)->content !== $imprint) {
                throw new TsaException('The timestamp covers different data than requested');
            }
            $genTime = $tstInfo->child(4)->time();
            $tokenNonce = null;
            foreach (array_slice($tstInfo->children, 5) as $field) {
                if ($field->tag === Der::TAG_INTEGER) {
                    $tokenNonce = $field->content;

                    break;
                }
            }
            if ($tokenNonce === null || ltrim($tokenNonce, "\x00") !== ltrim($nonce, "\x00")) {
                throw new TsaException('The timestamp nonce does not match the request');
            }
        } catch (Asn1Exception $e) {
            throw new TsaException('Unreadable TSA response: '.$e->getMessage(), previous: $e);
        }

        return new TimeStampToken($token->der, $genTime);
    }

    /** The TSTInfo inside a timestamp token (ContentInfo → SignedData → eContent). */
    public static function tstInfo(Asn1Node $token): Asn1Node
    {
        $token->expect(Der::TAG_SEQUENCE);
        if ($token->child(0)->oid() !== Oid::SIGNED_DATA) {
            throw new TsaException('The timestamp token is not SignedData');
        }
        $signedData = $token->child(1)->child(0)->expect(Der::TAG_SEQUENCE);
        $encap = $signedData->child(2)->expect(Der::TAG_SEQUENCE);
        if ($encap->child(0)->oid() !== Oid::TST_INFO) {
            throw new TsaException('The timestamp token does not hold a TSTInfo');
        }
        $eContent = $encap->child(1)->child(0)->expect(Der::TAG_OCTET_STRING);

        return DerReader::parse($eContent->content)->expect(Der::TAG_SEQUENCE);
    }

    private static function statusText(Asn1Node $statusInfo): string
    {
        $free = $statusInfo->children[1] ?? null;
        if ($free === null || $free->tag !== Der::TAG_SEQUENCE) {
            return '';
        }
        $texts = array_map(static fn (Asn1Node $n): string => $n->content, $free->children);

        return $texts === [] ? '' : ': '.implode('; ', $texts);
    }
}
