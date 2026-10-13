<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\Signature\Transport\StreamHttpTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The stream transport against PHP's built-in web server.
 */
final class StreamHttpTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir().'/phppdf-http-'.bin2hex(random_bytes(6));
        mkdir(self::$dir, 0700, true);
        file_put_contents(self::$dir.'/index.php', <<<'PHP'
            <?php
            header('Content-Type: application/x-echo');
            http_response_code($_SERVER['HTTP_X_STATUS'] ?? 200);
            echo json_encode([
                'method' => $_SERVER['REQUEST_METHOD'],
                'type' => $_SERVER['CONTENT_TYPE'] ?? null,
                'body' => base64_encode(file_get_contents('php://input')),
            ]);
            PHP);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return;
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$port = (int) substr($name, strrpos($name, ':') + 1);

        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.self::$port, '-t', self::$dir],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (! is_resource($proc)) {
            return;
        }
        self::$server = $proc;
        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', self::$port);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        @unlink(self::$dir.'/index.php');
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        if (! is_resource(self::$server)) {
            self::markTestSkipped('Could not start the built-in web server');
        }
    }

    #[Test]
    public function posts_the_body_and_reads_the_answer(): void
    {
        $response = (new StreamHttpTransport)->post(
            'http://127.0.0.1:'.self::$port.'/',
            "\x30\x03\x02\x01\x01",
            ['Content-Type' => 'application/timestamp-query'],
            5,
        );
        $echo = json_decode($response->body, true);

        self::assertSame(200, $response->status);
        self::assertSame('application/x-echo', $response->header('Content-Type'));
        self::assertSame('POST', $echo['method']);
        self::assertSame('application/timestamp-query', $echo['type']);
        self::assertSame("\x30\x03\x02\x01\x01", base64_decode($echo['body']));
    }

    #[Test]
    public function error_statuses_come_back_as_responses(): void
    {
        $response = (new StreamHttpTransport)->post('http://127.0.0.1:'.self::$port.'/', '', ['X-Status' => '503'], 5);

        self::assertSame(503, $response->status);
    }

    #[Test]
    public function an_unreachable_host_is_an_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        (new StreamHttpTransport)->post('http://127.0.0.1:1/', '', [], 2);
    }
}
