<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\SignatureConfig;

/**
 * The signature dictionary (ISO 32000-1 §12.8.1) with its /ByteRange and
 * /Contents placeholders, for a fresh document and for an incremental update
 * alike.
 *
 * @internal
 */
final class SignatureDictionary
{
    public static function body(SignatureConfig $cfg): string
    {
        $optional = '';
        foreach (['Name' => $cfg->signerName, 'Reason' => $cfg->reason, 'Location' => $cfg->location, 'ContactInfo' => $cfg->contactInfo] as $key => $value) {
            if ($value !== null) {
                $optional .= " /$key ".self::textString($value);
            }
        }

        $references = [];
        if ($cfg->certification !== null) {
            $references[] = sprintf(
                '<< /Type /SigRef /TransformMethod /DocMDP /TransformParams << /Type /TransformParams /P %d /V /1.2 >> >>',
                $cfg->certification->value,
            );
        }
        if ($cfg->lockFields !== null) {
            $references[] = sprintf(
                '<< /Type /SigRef /TransformMethod /FieldMDP /TransformParams << /Type /TransformParams %s /V /1.2 >> >>',
                $cfg->lockFields->parameters(),
            );
        }
        $referencePart = $references === [] ? '' : ' /Reference ['.implode(' ', $references).']';

        // ByteRange: 4 entries padded to 10 digits each plus one extra pad
        // space, so a post-emit substr_replace can fit the updated values.
        $byteRange = '/ByteRange [0          0          0          0         ]';
        // Contents: hex zeros, room for the DER signature sized from the
        // certificates, the key and the timestamp authority.
        $contents = '/Contents <'.str_repeat('0', 2 * $cfg->contentsReserve()).'>';

        return sprintf(
            '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /%s %s %s /M %s%s%s >>',
            $cfg->subFilter->value, $byteRange, $contents, self::literal($cfg->pdfSignedAt()), $optional, $referencePart,
        );
    }

    /** The /Lock dictionary of a signature field. */
    public static function lock(FieldLock $lock): string
    {
        return '<< /Type /SigFieldLock '.$lock->parameters().' >>';
    }

    /**
     * A text string (§7.9.2.2): ASCII as a literal, anything else UTF-16BE
     * with a byte order mark — UTF-8 bytes in a literal read as mojibake.
     */
    public static function textString(string $s): string
    {
        if (preg_match('/[^\x20-\x7E]/', $s) !== 1) {
            return self::literal($s);
        }
        $utf16 = mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');

        return '<FEFF'.strtoupper(bin2hex($utf16)).'>';
    }

    public static function literal(string $s): string
    {
        return '('.strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']).')';
    }
}
