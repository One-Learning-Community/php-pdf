<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Transport;

/**
 * HTTP(S) POST through PHP's stream wrapper. TLS certificates are verified;
 * redirects are followed for up to three hops.
 */
final class StreamHttpTransport implements HttpTransport
{
    public function post(string $url, string $body, array $headers, float $timeoutSeconds): HttpResponse
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $lines),
                'content' => $body,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'max_redirects' => 3,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $error = null;
        set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $stream = fopen($url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            throw new \RuntimeException("HTTP request to $url failed".($error !== null ? ": $error" : ''));
        }
        try {
            $responseBody = (string) stream_get_contents($stream);
            $meta = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }

        // wrapper_data holds the status lines and headers of every hop; the
        // last status line starts the final response.
        $raw = is_array($meta['wrapper_data']) ? array_map('strval', $meta['wrapper_data']) : [];
        $status = 0;
        $responseHeaders = [];
        foreach ($raw as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        return new HttpResponse($status, $responseBody, $responseHeaders);
    }
}
