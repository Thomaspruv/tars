<?php

namespace App\Support\Brain;

/**
 * One file to put in the vault, optionally replacing a file that lived
 * somewhere else (a move, an archive). A VaultWriter call takes a list of
 * these so that a batch — a merge, a whole set of `_index.md` — lands in a
 * single commit.
 */
readonly class VaultOperation
{
    /**
     * @param  array<string, mixed>  $frontmatter
     */
    private function __construct(
        public string $relativePath,
        public array $frontmatter,
        public string $body,
        public ?string $movedFrom = null,
        public ?string $rawContents = null,
    ) {}

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    public static function write(string $relativePath, array $frontmatter, string $body): self
    {
        return new self($relativePath, $frontmatter, $body);
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    public static function move(string $from, string $to, array $frontmatter, string $body): self
    {
        return new self($to, $frontmatter, $body, movedFrom: $from);
    }

    /**
     * Thomas's own text, byte for byte: a file he edits in `/cerveau` is never
     * re-serialised (his YAML, his comments, his order) and never held to the
     * contract — the contract binds agents, not him.
     */
    public static function raw(string $relativePath, string $contents): self
    {
        return new self($relativePath, [], '', rawContents: $contents);
    }

    public function isRaw(): bool
    {
        return $this->rawContents !== null;
    }

    public function isMove(): bool
    {
        return $this->movedFrom !== null && $this->movedFrom !== $this->relativePath;
    }
}
