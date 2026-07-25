<?php

namespace App\Support\Brain;

use Symfony\Component\Yaml\Yaml;

class VaultParser
{
    public function parse(string $raw, string $fallbackTitle): ParsedDocument
    {
        [$frontmatter, $body] = $this->splitFrontmatter($raw);

        $title = $frontmatter['title'] ?? $this->firstHeading($body) ?? $fallbackTitle;

        return new ParsedDocument(
            title: (string) $title,
            frontmatter: $frontmatter,
            content: $body,
            wikilinks: $this->extractWikilinks($body),
            hash: hash('sha256', $raw),
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function splitFrontmatter(string $raw): array
    {
        if (! preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?(.*)$/us', $raw, $matches)) {
            return [[], $raw];
        }

        $parsed = Yaml::parse($matches[1]);

        return [is_array($parsed) ? $parsed : [], $matches[2]];
    }

    private function firstHeading(string $body): ?string
    {
        if (preg_match('/^#\s+(.+)$/mu', $body, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractWikilinks(string $body): array
    {
        preg_match_all('/\[\[([^\]]+)\]\]/u', $body, $matches);

        $targets = array_map(function (string $raw): string {
            $target = explode('|', $raw)[0];
            $target = explode('#', $target)[0];

            return trim($target);
        }, $matches[1]);

        return array_values(array_unique(array_filter($targets)));
    }
}
