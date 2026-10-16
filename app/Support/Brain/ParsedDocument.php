<?php

namespace App\Support\Brain;

use App\Support\Brain\Schema\SchemaValidationResult;

readonly class ParsedDocument
{
    /**
     * @param  array<string, mixed>  $frontmatter
     * @param  list<string>  $wikilinks
     */
    public function __construct(
        public string $title,
        public array $frontmatter,
        public string $content,
        public array $wikilinks,
        public string $hash,
        public SchemaValidationResult $schema,
    ) {}
}
