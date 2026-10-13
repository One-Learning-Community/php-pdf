<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

/**
 * Encrypts one object body as written in PDF syntax: its strings — literal
 * and hex — in the dictionary part, its stream data with the stream cipher,
 * and /Length restated for the grown stream. Shared by the writer and by
 * incremental updates of encrypted files.
 *
 * @internal
 */
final class ObjectEncryptor
{
    /**
     * @param  \Closure(string, bool): string  $cipher  Encrypts data; the flag says whether it is a stream.
     * @param  bool  $keepContents  Leave /Contents plain (a signature dictionary, §7.6.1).
     */
    public static function encryptBody(string $body, \Closure $cipher, bool $keepContents = false): string
    {
        // Strings live in the dictionary; what follows `stream` is bytes, and
        // a `(` or `<` among them is not a string. Scanning the stream data
        // too used to "encrypt" pieces of compressed fonts and images and
        // broke them.
        $streamAt = str_starts_with($body, "stream\n") ? 0 : strpos($body, "\nstream\n");
        $head = $streamAt === false ? $body : substr($body, 0, $streamAt);
        $tail = $streamAt === false ? '' : substr($body, $streamAt);
        $body = self::encryptStrings($head, static fn (string $data): string => $cipher($data, false), $keepContents).$tail;

        $encryptedLength = null;
        $result = preg_replace_callback(
            '@stream\n(.*?)\nendstream@s',
            function (array $m) use ($cipher, &$encryptedLength): string {
                $encrypted = $cipher($m[1], true);
                $encryptedLength = strlen($encrypted);

                return 'stream'."\n".$encrypted."\n".'endstream';
            },
            $body,
        ) ?? $body;

        // Encryption grows the stream (IV + padding), so the /Length written
        // for the plaintext no longer describes what is on disk. A reader that
        // believes /Length — and per §7.3.8.2 it is entitled to — reads a
        // truncated stream; one that falls back to scanning for `endstream`
        // has to guess where the preceding EOL ends, and guesses wrong
        // whenever the last ciphertext byte is CR (~1 file in 256).
        if ($encryptedLength !== null) {
            $result = preg_replace('@/Length \d+@', '/Length '.$encryptedLength, $result, 1) ?? $result;
        }

        return $result;
    }

    /**
     * Encrypts the strings of a dictionary or array — literal `(...)` and
     * hexadecimal `<...>` alike — writing them back as hex; /Contents is left
     * alone when asked to.
     *
     * @param  \Closure(string): string  $cipher
     */
    private static function encryptStrings(string $body, \Closure $cipher, bool $keepContents): string
    {
        $out = '';
        $len = strlen($body);
        $i = 0;
        while ($i < $len) {
            $ch = $body[$i];
            if ($ch === '(') {
                // Find the matching closing paren, respecting nesting and escapes.
                $depth = 1;
                $j = $i + 1;
                $strStart = $j;
                while ($j < $len) {
                    $cj = $body[$j];
                    if ($cj === '\\' && $j + 1 < $len) {
                        $j += 2;

                        continue;
                    }
                    if ($cj === '(') {
                        $depth++;
                    } elseif ($cj === ')') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                    $j++;
                }
                if ($depth !== 0) {
                    // Malformed — keep as-is.
                    $out .= substr($body, $i);

                    break;
                }
                $decoded = self::unescapeLiteral(substr($body, $strStart, $j - $strStart));
                $out .= '<'.bin2hex($cipher($decoded)).'>';
                $i = $j + 1;

                continue;
            }
            if ($ch === '<' && ($body[$i + 1] ?? '') !== '<' && ($i === 0 || $body[$i - 1] !== '<')) {
                $end = strpos($body, '>', $i + 1);
                $hex = $end === false ? null : substr($body, $i + 1, $end - $i - 1);
                if ($hex !== null && preg_match('/^[0-9A-Fa-f\s]*$/', $hex) === 1) {
                    $hex = (string) preg_replace('/\s+/', '', $hex);
                    if ($keepContents && str_ends_with($out, '/Contents ')) {
                        $out .= '<'.$hex.'>';
                    } else {
                        $bytes = (string) hex2bin(strlen($hex) % 2 === 1 ? $hex.'0' : $hex);
                        $out .= '<'.bin2hex($cipher($bytes)).'>';
                    }
                    $i = (int) $end + 1;

                    continue;
                }
            }
            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /** The bytes of a literal string body, its escapes resolved (§7.3.4.2). */
    private static function unescapeLiteral(string $raw): string
    {
        return (string) preg_replace_callback(
            '/\\\\(?:([0-7]{1,3})|(\r\n|\r|\n)|(.))/s',
            static function (array $m): string {
                if ($m[1] !== '') {
                    return chr(octdec($m[1]) & 0xFF);
                }
                if (($m[2] ?? '') !== '') {
                    return '';
                }

                return match ($m[3]) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
                    default => $m[3],
                };
            },
            $raw,
        );
    }
}
