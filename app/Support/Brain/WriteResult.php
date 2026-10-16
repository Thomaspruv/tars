<?php

namespace App\Support\Brain;

use App\Enums\VaultWriteStatus;

readonly class WriteResult
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $errors
     */
    public function __construct(
        public VaultWriteStatus $status,
        public array $paths = [],
        public array $errors = [],
        public ?string $sasPath = null,
    ) {}

    /**
     * @param  list<string>  $paths
     */
    public static function written(array $paths): self
    {
        return new self(VaultWriteStatus::Written, paths: $paths);
    }

    public static function conflict(?string $sasPath): self
    {
        return new self(VaultWriteStatus::Conflict, sasPath: $sasPath);
    }

    /**
     * @param  list<string>  $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(VaultWriteStatus::Invalid, errors: $errors);
    }

    public static function unconfigured(): self
    {
        return new self(VaultWriteStatus::Unconfigured);
    }

    public function isWritten(): bool
    {
        return $this->status === VaultWriteStatus::Written;
    }

    public function path(): ?string
    {
        return $this->paths[0] ?? null;
    }

    /**
     * One sentence, in French, sayable by Hermes.
     */
    public function message(): string
    {
        return match ($this->status) {
            VaultWriteStatus::Written => 'Écrit dans le vault.',
            VaultWriteStatus::Conflict => "Je n'ai pas pu écrire, TARS a gardé le contenu dans le sas.",
            VaultWriteStatus::Invalid => 'Frontmatter non conforme au contrat v3 : '.implode(' ', $this->errors),
            VaultWriteStatus::Unconfigured => "Le vault du cerveau n'est pas configuré.",
        };
    }
}
