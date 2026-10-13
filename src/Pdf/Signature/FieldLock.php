<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature;

/**
 * Form fields a signature locks (FieldMDP, ISO 32000-1 §12.8.2.4): once
 * signed, changing them invalidates the signature.
 */
final readonly class FieldLock
{
    /**
     * @param  'All'|'Include'|'Exclude'  $action
     * @param  list<string>  $fields  Fully qualified field names.
     */
    private function __construct(
        public string $action,
        public array $fields = [],
    ) {}

    public static function all(): self
    {
        return new self('All');
    }

    /** @param  list<string>  $fields */
    public static function only(array $fields): self
    {
        if ($fields === []) {
            throw new \InvalidArgumentException('FieldLock::only() needs at least one field');
        }

        return new self('Include', array_values($fields));
    }

    /** @param  list<string>  $fields */
    public static function allExcept(array $fields): self
    {
        return new self('Exclude', array_values($fields));
    }

    /** The /TransformParams (and /Lock) dictionary body. */
    public function parameters(): string
    {
        $fields = $this->fields === []
            ? ''
            : ' /Fields ['.implode(' ', array_map([SignatureDictionary::class, 'textString'], $this->fields)).']';

        return sprintf('/Action /%s%s', $this->action, $fields);
    }
}
