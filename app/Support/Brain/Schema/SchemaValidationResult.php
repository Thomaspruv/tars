<?php

namespace App\Support\Brain\Schema;

readonly class SchemaValidationResult
{
    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $normalized
     */
    public function __construct(
        public bool $isConformant,
        public ?int $schemaVersion,
        public array $errors,
        public array $normalized,
        public ?NoteType $noteType = null,
        public ?string $status = null,
    ) {}

    public function errorSentence(): string
    {
        return implode(' ', $this->errors);
    }
}
