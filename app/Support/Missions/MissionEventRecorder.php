<?php

namespace App\Support\Missions;

use App\Enums\MissionStatus;
use App\Models\Mission;
use App\Models\MissionEvent;
use Illuminate\Support\Carbon;

/**
 * Range le flux de la mission, ligne par ligne, au fil de l'arrivée.
 *
 * Un message d'assistant porte souvent plusieurs blocs (du texte, puis un appel
 * d'outil) : on en fait une ligne chacun, parce que c'est ainsi qu'on les lit à
 * l'écran. Le reste (l'init, les bornes de débit, le résultat) reste d'un bloc.
 *
 * Le `parent_tool_use_id` est recopié tel quel sur chaque ligne : c'est lui qui
 * dit « cet événement vient d'un sous-agent, engendré par tel appel Task ».
 */
class MissionEventRecorder
{
    private const int TEXT_LIMIT = 4000;

    private int $seq;

    public function __construct(private readonly Mission $mission)
    {
        $this->seq = (int) MissionEvent::where('mission_id', $mission->id)->max('seq');
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function record(array $event): void
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : 'unknown';
        $parent = is_string($event['parent_tool_use_id'] ?? null) ? $event['parent_tool_use_id'] : null;
        $occurredAt = $this->occurredAt($event);

        $blocks = $this->blocks($event);

        if ($blocks === []) {
            $this->store([
                'type' => $type,
                'subtype' => is_string($event['subtype'] ?? null) ? $event['subtype'] : null,
                'parent_tool_use_id' => $parent,
                'text' => $this->summarise($type, $event),
                'payload' => $event,
                'occurred_at' => $occurredAt,
            ]);
        }

        foreach ($blocks as $block) {
            $this->store([
                ...$block,
                'type' => $type,
                'parent_tool_use_id' => $parent,
                'occurred_at' => $occurredAt,
            ]);
        }

        if ($type === 'result') {
            $this->close($event);
        }
    }

    /**
     * Les blocs d'un message, mis à plat : un bloc de texte, un appel d'outil
     * ou un retour d'outil donnent chacun une ligne lisible.
     *
     * @param  array<string, mixed>  $event
     * @return list<array<string, mixed>>
     */
    private function blocks(array $event): array
    {
        $content = $event['message']['content'] ?? null;

        if (! is_array($content)) {
            return [];
        }

        $blocks = [];

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            $blocks[] = match ($block['type'] ?? null) {
                'text' => [
                    'subtype' => 'text',
                    'text' => $this->limit((string) ($block['text'] ?? '')),
                    'payload' => $block,
                ],
                'thinking' => [
                    'subtype' => 'thinking',
                    'text' => $this->limit((string) ($block['thinking'] ?? '')),
                    'payload' => ['type' => 'thinking'],
                ],
                'tool_use' => [
                    'subtype' => 'tool_use',
                    'tool_use_id' => is_string($block['id'] ?? null) ? $block['id'] : null,
                    'tool_name' => is_string($block['name'] ?? null) ? $block['name'] : null,
                    'text' => $this->limit($this->describeInput($block['input'] ?? null)),
                    'payload' => $block,
                ],
                'tool_result' => [
                    'subtype' => 'tool_result',
                    'tool_use_id' => is_string($block['tool_use_id'] ?? null) ? $block['tool_use_id'] : null,
                    'text' => $this->limit($this->flatten($block['content'] ?? null)),
                    'payload' => ['type' => 'tool_result', 'is_error' => (bool) ($block['is_error'] ?? false)],
                ],
                default => null,
            } ?? [];
        }

        return array_values(array_filter($blocks));
    }

    /**
     * Ce qu'un événement sans bloc a à dire en une ligne.
     *
     * @param  array<string, mixed>  $event
     */
    private function summarise(string $type, array $event): ?string
    {
        if ($type === 'result') {
            return $this->limit((string) ($event['result'] ?? ''));
        }

        if ($type === 'system' && ($event['subtype'] ?? null) === 'init') {
            $servers = array_values(array_filter(array_map(
                fn (mixed $server): ?string => is_array($server) && is_string($server['name'] ?? null) ? $server['name'] : null,
                is_array($event['mcp_servers'] ?? null) ? $event['mcp_servers'] : [],
            )));

            return 'Outils : '.(count($servers) > 0 ? implode(', ', $servers) : 'aucun serveur MCP');
        }

        return null;
    }

    /**
     * Les arguments d'un appel d'outil, en une ligne : le chemin, la commande
     * ou la consigne — ce qui identifie l'appel, pas son JSON complet.
     */
    private function describeInput(mixed $input): string
    {
        if (! is_array($input)) {
            return '';
        }

        foreach (['description', 'command', 'file_path', 'path', 'pattern', 'query', 'prompt', 'note_path', 'title'] as $key) {
            if (is_string($input[$key] ?? null) && $input[$key] !== '') {
                return (string) $input[$key];
            }
        }

        return (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function flatten(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $block) {
            if (is_string($block)) {
                $parts[] = $block;
            } elseif (is_array($block) && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function occurredAt(array $event): Carbon
    {
        $timestamp = $event['timestamp'] ?? null;

        if (is_string($timestamp)) {
            try {
                return Carbon::parse($timestamp);
            } catch (\Throwable) {
                // Un horodatage illisible ne vaut pas de perdre l'événement.
            }
        }

        return Carbon::now();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(array $attributes): void
    {
        $this->seq++;

        MissionEvent::create([
            'mission_id' => $this->mission->id,
            'seq' => $this->seq,
            'turn' => $this->mission->turn,
            'subtype' => null,
            'tool_use_id' => null,
            'tool_name' => null,
            'text' => null,
            'payload' => [],
            ...$attributes,
        ]);
    }

    /**
     * L'événement `result` clôt la mission : c'est lui qui porte le coût réel,
     * les tokens et le verdict.
     *
     * @param  array<string, mixed>  $event
     */
    private function close(array $event): void
    {
        $usage = is_array($event['usage'] ?? null) ? $event['usage'] : [];
        $isError = (bool) ($event['is_error'] ?? false) || ($event['subtype'] ?? null) !== 'success';

        // Le CLI compte ce que *ce* tour a coûté, pas la conversation entière :
        // on additionne, sinon un deuxième message effacerait le prix du
        // premier.
        $this->mission->forceFill([
            'status' => $isError ? MissionStatus::Failed : MissionStatus::Succeeded,
            'result' => $this->limit((string) ($event['result'] ?? ''), 100000),
            'error' => $isError ? $this->limit((string) ($event['result'] ?? ($event['subtype'] ?? 'échec')), 1000) : null,
            'cost_usd' => (float) $this->mission->cost_usd + (float) ($event['total_cost_usd'] ?? 0),
            'tokens_in' => (int) $this->mission->tokens_in + (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0),
            'tokens_out' => (int) $this->mission->tokens_out + (int) ($usage['output_tokens'] ?? 0),
            'num_turns' => (int) $this->mission->num_turns + (int) ($event['num_turns'] ?? 0),
            'finished_at' => now(),
        ])->save();
    }

    private function limit(string $value, int $limit = self::TEXT_LIMIT): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).'…' : $value;
    }
}
