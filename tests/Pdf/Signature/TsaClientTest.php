<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Der;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpResponse;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpTransport;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaException;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\OpenSslTsaTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TsaClientTest extends TestCase
{
    private function requireTsa(): void
    {
        if (! OpenSslTsaTransport::available()) {
            self::markTestSkipped('openssl ts not available');
        }
    }

    /**
     * A transport answering with a fixed response, recording the request.
     */
    private static function fixed(HttpResponse $response): HttpTransport
    {
        return new class($response) implements HttpTransport
        {
            public ?string $body = null;

            /** @var array<string, string> */
            public array $headers = [];

            public function __construct(private readonly HttpResponse $response) {}

            public function post(string $url, string $body, array $headers, float $timeoutSeconds): HttpResponse
            {
                $this->body = $body;
                $this->headers = $headers;

                return $this->response;
            }
        };
    }

    #[Test]
    public function the_request_is_a_timestamp_query(): void
    {
        $client = new TsaClient('https://tsa.example/', DigestAlgorithm::Sha384, policyOid: '1.2.3.4');
        $req = DerReader::parse($client->request(str_repeat("\x01", 48), "\x80\x01"));

        self::assertSame(1, $req->child(0)->int());
        self::assertSame('2.16.840.1.101.3.4.2.2', $req->child(1)->child(0)->child(0)->oid());
        self::assertSame(48, strlen($req->child(1)->child(1)->content));
        self::assertSame('1.2.3.4', $req->child(2)->oid());
        // The nonce keeps its high bit positive.
        self::assertSame("\x00\x80\x01", $req->child(3)->content);
        self::assertSame("\xFF", $req->child(4)->content);
    }

    #[Test]
    public function a_granted_token_is_returned(): void
    {
        $this->requireTsa();
        $transport = new OpenSslTsaTransport;
        $token = (new TsaClient('http://tsa.test/', transport: $transport, username: 'u', password: 'p'))->timestamp('signature');

        self::assertSame('1.2.840.113549.1.7.2', DerReader::parse($token->token)->child(0)->oid());
        self::assertEqualsWithDelta(time(), $token->genTime->getTimestamp(), 120);
        self::assertSame('application/timestamp-query', $transport->requests[0]['headers']['Content-Type']);
        self::assertSame('Basic '.base64_encode('u:p'), $transport->requests[0]['headers']['Authorization']);
    }

    #[Test]
    public function a_refusal_is_reported_with_its_text(): void
    {
        $refusal = Der::sequence(Der::sequence(Der::integer(2), Der::sequence(Der::utf8String('bad policy'))));
        $client = new TsaClient('http://tsa.test/', transport: self::fixed(new HttpResponse(200, $refusal)));

        $this->expectException(TsaException::class);
        $this->expectExceptionMessageMatches('/status 2: bad policy/');
        $client->timestamp('x');
    }

    #[Test]
    public function an_http_error_is_reported(): void
    {
        $client = new TsaClient('http://tsa.test/', transport: self::fixed(new HttpResponse(503, 'busy')));

        $this->expectException(TsaException::class);
        $this->expectExceptionMessageMatches('/HTTP 503/');
        $client->timestamp('x');
    }

    #[Test]
    public function a_token_for_other_data_is_refused(): void
    {
        $this->requireTsa();
        // A real response, but to a different request.
        $client = new TsaClient('http://tsa.test/', transport: new OpenSslTsaTransport);
        $nonce = random_bytes(8);
        $other = (new OpenSslTsaTransport)->post('http://tsa.test/', $client->request(hash('sha256', 'other', true), $nonce), [], 5)->body;

        try {
            $client->parseResponse($other, hash('sha256', 'mine', true), $nonce);
            self::fail('Accepted a token over other data');
        } catch (TsaException $e) {
            self::assertStringContainsString('different data', $e->getMessage());
        }

        $this->expectException(TsaException::class);
        $this->expectExceptionMessageMatches('/nonce/');
        $client->parseResponse($other, hash('sha256', 'other', true), random_bytes(8));
    }

    #[Test]
    public function garbage_is_an_unreadable_response(): void
    {
        $client = new TsaClient('http://tsa.test/', transport: self::fixed(new HttpResponse(200, 'not der')));

        $this->expectException(TsaException::class);
        $client->timestamp('x');
    }

    #[Test]
    public function only_http_urls_are_accepted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TsaClient('ftp://tsa.example/');
    }
}
