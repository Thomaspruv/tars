<?php

namespace App\Support\Journal;

use App\Models\JournalEntry;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\Schema\FrontmatterBuilder;
use App\Support\Brain\Schema\NoteType;
use App\Support\Brain\VaultWriter;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class JournalWriter
{
    /**
     * A write between midnight and this hour is attached to the previous day —
     * a late-night voice entry is still "yesterday's" recap, not a new day.
     */
    public const int PIVOT_HOUR = 4;

    public function __construct(
        private readonly BrainSettings $settings,
        private readonly VaultWriter $writer,
        private readonly FrontmatterBuilder $frontmatter,
    ) {}

    /**
     * The single write path for the journal — used by the MCP tools, the UI,
     * and nothing else. Merges into the same day's entry instead of
     * overwriting when one already exists.
     *
     * @param  list<string>  $highlights
     */
    public function write(
        string $summary,
        ?int $mood,
        ?string $moodLabel,
        array $highlights,
        ?CarbonInterface $date,
        string $source,
        bool $clearMood = false,
    ): JournalEntry {
        $date = $this->resolveDate($date);
        $existing = JournalEntry::whereDate('date', $date->toDateString())->first();

        $vaultPath = rtrim($this->settings->localPath(), '/');
        $relativePath = "TARS/Journal/{$date->format('Y')}/{$date->format('Y-m-d')}.md";
        $absolutePath = "{$vaultPath}/{$relativePath}";

        [$frontmatter, $body] = $existing && File::exists($absolutePath)
            ? $this->appendEntry(File::get($absolutePath), $date, $summary, $highlights, $mood, $moodLabel, $clearMood)
            : $this->buildEntry($date, $summary, $highlights, $mood, $moodLabel, $source);

        $this->writer->write($relativePath, $frontmatter, $body, "journal {$date->toDateString()}");

        $mergedHighlights = $existing
            ? array_values(array_unique([...($existing->highlights ?? []), ...$highlights]))
            : $highlights;

        $attributes = [
            'mood' => $clearMood ? null : ($mood ?? $existing?->mood),
            'mood_label' => $moodLabel ?? $existing?->mood_label,
            'summary' => $existing ? trim($existing->summary."\n\n".$summary) : $summary,
            'highlights' => $mergedHighlights,
            'source' => $source,
            'note_path' => $relativePath,
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return JournalEntry::create([...$attributes, 'date' => $date]);
    }

    public function resolveDate(?CarbonInterface $date): CarbonInterface
    {
        if ($date !== null) {
            return $date->copy()->startOfDay();
        }

        return now()->hour < self::PIVOT_HOUR ? today()->subDay() : today();
    }

    /**
     * @param  list<string>  $highlights
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function buildEntry(CarbonInterface $date, string $summary, array $highlights, ?int $mood, ?string $moodLabel, string $source): array
    {
        $frontmatter = $this->frontmatter->build(
            type: NoteType::Journal,
            title: "Journal du {$date->toDateString()}",
            date: $date,
            source: $this->contractSource($source),
            extra: ['mood' => $mood, 'mood_label' => $moodLabel],
        );

        $body = "## Résumé\n{$summary}\n";

        if ($highlights !== []) {
            $body .= "\n## Moments\n".$this->bulletList($highlights)."\n";
        }

        return [$frontmatter, $body];
    }

    /**
     * The journal's own `source` column keeps its own vocabulary; the vault's
     * frontmatter speaks the contract's.
     */
    private function contractSource(string $source): string
    {
        return $source === 'hermes' ? 'hermes' : 'thomas';
    }

    /**
     * @param  list<string>  $highlights
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function appendEntry(string $raw, CarbonInterface $date, string $summary, array $highlights, ?int $mood, ?string $moodLabel, bool $clearMood): array
    {
        [$frontmatter, $body] = $this->splitFrontmatter($raw);

        if ($clearMood) {
            unset($frontmatter['mood']);
        } elseif ($mood !== null) {
            $frontmatter['mood'] = $mood;
        }

        if ($moodLabel !== null) {
            $frontmatter['mood_label'] = $moodLabel;
        }

        $addition = "\n## Ajout — ".now()->format('H:i')."\n{$summary}\n";

        if ($highlights !== []) {
            $addition .= "\n".$this->bulletList($highlights)."\n";
        }

        $frontmatter = $this->frontmatter->upgrade(
            frontmatter: $frontmatter,
            relativePath: "Journal/{$date->format('Y')}/{$date->format('Y-m-d')}.md",
            fallbackTitle: "Journal du {$date->toDateString()}",
            source: 'thomas',
            patch: ['type' => NoteType::Journal->value, 'date' => $date->toDateString()],
        );

        return [$frontmatter, rtrim($body)."\n{$addition}"];
    }

    /**
     * @param  list<string>  $items
     */
    private function bulletList(array $items): string
    {
        return collect($items)->map(fn (string $item): string => "- {$item}")->implode("\n");
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
}
