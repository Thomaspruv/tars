<?php

use App\Models\Event;
use App\Models\InboxItem;
use App\Models\Task;
use App\Support\QuickAdd\QuickAddParser;
use App\Support\QuickAdd\QuickAddResult;
use App\Support\Search\GlobalSearch;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $query = '';

    /**
     * Le type que j'ai choisi à la main (⇥ ou le bouton). Vide = je laisse le
     * parseur décider : c'est l'état de départ de chaque capture.
     */
    public string $typeOverride = '';

    private const array KIND_LABELS = [
        'task' => 'Tâche',
        'event' => 'Événement',
        'note' => 'Note',
    ];

    #[Computed]
    public function parsed(): QuickAddResult
    {
        return app(QuickAddParser::class)->parse($this->query);
    }

    /**
     * Ce que le parseur a reconnu, en puces, pendant la frappe. L'abricot est
     * ce que *je* pose (quand, priorité), le neutre ce à quoi ça se rattache.
     *
     * @return list<array{kind: string, prefix: string, label: string, tone: string}>
     */
    #[Computed]
    public function chips(): array
    {
        $prefixes = [
            'date' => 'date',
            'time' => 'heure',
            'recurrence' => '↻',
            'entity' => '@',
            'goal' => '#',
            'priority' => 'priorité',
        ];

        $tones = [
            'date' => 'mine',
            'time' => 'mine',
            'priority' => 'mine',
            'recurrence' => 'neutral',
            'entity' => 'neutral',
            'goal' => 'neutral',
        ];

        return array_map(fn ($token): array => [
            'kind' => $token->kind,
            'prefix' => $prefixes[$token->kind] ?? '',
            'label' => $token->label,
            'tone' => $tones[$token->kind] ?? 'neutral',
        ], $this->parsed->tokens);
    }

    /**
     * La phrase que l'agent annonce sous le champ : titre · quand · ↻ ·
     * rattachements · priorité.
     */
    #[Computed]
    public function preview(): string
    {
        $result = $this->parsed;
        $parts = [];

        if ($result->title !== '') {
            $parts[] = $result->title;
        }

        $tokensByKind = collect($result->tokens)->keyBy(fn ($token): string => $token->kind);

        $when = collect(['date', 'time'])
            ->map(fn (string $kind): ?string => $tokensByKind->get($kind)?->label)
            ->filter()
            ->implode(' ');

        if ($when !== '') {
            $parts[] = $when;
        }

        if ($result->recurrence !== null) {
            $parts[] = '↻ '.($tokensByKind->get('recurrence')?->label ?? $result->recurrence);
        }

        if ($result->entity) {
            $parts[] = '@'.$result->entity->name;
        }

        if ($result->goal) {
            $parts[] = '#'.$result->goal->title;
        }

        if ($result->priority) {
            $parts[] = strtoupper($result->priority->value);
        }

        return implode(' · ', $parts);
    }

    #[Computed]
    public function kind(): string
    {
        return $this->typeOverride !== '' ? $this->typeOverride : $this->parsed->kind();
    }

    #[Computed]
    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? self::KIND_LABELS['task'];
    }

    /**
     * ⇥ fait tourner Tâche → Événement → Note. Le choix reste le mien jusqu'à
     * ce que la capture soit posée.
     */
    public function cycleKind(): void
    {
        $order = array_keys(self::KIND_LABELS);
        $position = array_search($this->kind, $order, true);

        $this->typeOverride = $order[((int) $position + 1) % count($order)];

        unset($this->kind, $this->kindLabel);
    }

    /**
     * La même frappe cherche et pose. C'est ce qui évite le doublon : avant
     * de créer « relancer le syndic », on voit qu'il existe déjà.
     *
     * @return Collection<int, \App\Support\Search\SearchHit>
     */
    #[Computed]
    public function hits(): Collection
    {
        return app(GlobalSearch::class)->for($this->query);
    }

    public function submit(): void
    {
        $trimmed = trim($this->query);

        if ($trimmed === '') {
            return;
        }

        $result = $this->parsed;
        $title = $result->title !== '' ? $result->title : $trimmed;

        match ($this->kind) {
            'event' => $this->createEvent($result, $title),
            'note' => InboxItem::create(['content' => $this->noteContent($trimmed)]),
            default => $this->createTask($result, $title, $trimmed),
        };

        $this->reset('query', 'typeOverride');

        $this->dispatch('quick-add-saved');
    }

    private function createTask(QuickAddResult $result, string $title, string $trimmed): void
    {
        // Ce que le parseur n'a pas compris part en inbox tel quel — sauf si
        // j'ai explicitement dit « c'est une tâche ».
        if (! $result->hasStructuredToken() && $this->typeOverride === '') {
            InboxItem::create(['content' => $trimmed]);

            return;
        }

        Task::create([
            'title' => $title,
            'scheduled_date' => $result->date,
            'goal_id' => $result->goal?->id,
            'entity_id' => $result->entity?->id,
            'priority' => $result->priority,
            'recurrence' => $result->recurrence,
            'status' => 'todo',
        ]);
    }

    private function createEvent(QuickAddResult $result, string $title): void
    {
        Event::create([
            'title' => $title,
            'starts_at' => $result->startsAt() ?? today()->startOfDay(),
            'goal_id' => $result->goal?->id,
            'entity_id' => $result->entity?->id,
        ]);
    }

    private function noteContent(string $trimmed): string
    {
        return preg_match('/^\s*(?:id[ée]e|note)\s*:/iu', $trimmed) === 1 ? $trimmed : 'Note : '.$trimmed;
    }
};
?>

{{-- La capture est le geste central de l'app : elle est donc une palette
     plein écran, atteignable de partout en ⌘K, jamais un champ coincé dans
     une barre. Ce que le parseur ne comprend pas part en inbox tel quel —
     rien n'est jamais perdu, c'est ce qui rend la capture sans friction. --}}
<div
    x-data="{
        open: false,
        // -1 : c'est la capture qui a la main. C'est toujours l'état de départ.
        sel: -1,
        hitCount() { return this.$refs.hits ? this.$refs.hits.children.length : 0 },
        openHit() { this.$refs.hits?.children[this.sel]?.querySelector('a')?.click() },
    }"
    x-on:open-quick-add.window="open = true; sel = -1; $nextTick(() => $refs.field?.focus())"
    x-on:keydown.window.prevent.cmd.k="open = true; sel = -1; $nextTick(() => $refs.field?.focus())"
    x-on:keydown.window.prevent.ctrl.k="open = true; sel = -1; $nextTick(() => $refs.field?.focus())"
    x-on:keydown.escape.window="open = false"
    x-on:quick-add-saved="open = false"
>
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-start justify-center px-5 pt-[14vh]">
        <div class="animate-veil-in absolute inset-0 bg-black/60" x-on:click="open = false"></div>

        <div class="animate-palette-in relative w-full max-w-[640px] rounded-[22px] bg-(--surf) p-5 shadow-[0_2px_0_var(--bd2)]">
            <p class="mb-2.5 px-1 text-[11px] text-(--ac) kicker">Poser quelque chose</p>

            <form wire:submit="submit">
                <input
                    id="quick-add-input"
                    x-ref="field"
                    type="text"
                    wire:model.live.debounce.150ms="query"
                    placeholder="Poser ou chercher — « relancer le syndic vendredi p1 »"
                    autocomplete="off"
                    x-on:input="sel = -1"
                    x-on:keydown.tab.prevent="$wire.cycleKind()"
                    x-on:keydown.arrow-down.prevent="sel = Math.min(sel + 1, hitCount() - 1)"
                    x-on:keydown.arrow-up.prevent="sel = Math.max(sel - 1, -1)"
                    x-on:keydown.enter="if (sel >= 0) { $event.preventDefault(); openHit() }"
                    class="w-full rounded-full bg-(--surf2) px-5 py-3.5 text-[16px] text-(--tx) placeholder:text-(--mut) focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ac)"
                />
            </form>

            {{-- Les fragments reconnus, pendant la frappe. La rangée garde sa
                 hauteur même vide : rien ne saute sous le champ au premier
                 caractère reconnu. --}}
            <div class="mt-3 flex min-h-[28px] flex-wrap items-center gap-2 px-1">
                @forelse ($this->chips as $index => $chip)
                    <x-badge type="pill" :tone="$chip['tone']" wire:key="chip-{{ $index }}">
                        <span class="opacity-75">{{ $chip['prefix'] }}</span>{{ $chip['label'] }}
                    </x-badge>
                @empty
                    <span class="text-[12px] leading-[1.45] text-(--mut)">
                        Tape une date, @entité, #objectif ou p1–p3 : je les reconnais pendant que tu écris.
                    </span>
                @endforelse
            </div>

            {{-- L'annonce est de l'agent : elle passe donc par son bloc, et
                 elle ne décide rien — ⇥ et le bouton restent à moi. --}}
            <x-agent-block rail="3" padding="14" class="mt-3">
                <p class="mb-0.5 text-[12px] text-(--mut)">Ça va créer</p>
                <p class="text-[14px] leading-[1.45] text-(--tx)">
                    <strong class="text-(--ai)">{{ $this->kindLabel }}</strong>@if ($this->preview !== '') · {{ $this->preview }}@endif
                </p>
            </x-agent-block>

            <div class="mt-3 flex flex-wrap items-center gap-3 px-1 [&_button]:min-h-[44px] sm:[&_button]:min-h-0">
                <span class="text-[12px] leading-[1.45] text-(--mut)">
                    Entrée pour poser · Échap pour fermer · ⇥ pour changer le type
                </span>

                <div class="ml-auto flex items-center gap-2">
                    <x-btn variant="secondary" size="xs" wire:click="cycleKind">{{ $this->kindLabel }} ▾</x-btn>
                    <x-btn variant="primary" wire:click="submit">Poser</x-btn>
                </div>
            </div>

            {{-- Ce qui existe déjà, sous ce qu'on est en train d'écrire. La
                 capture garde Entrée : il faut descendre d'un cran pour ouvrir
                 un résultat, jamais l'inverse. --}}
            @if ($this->hits->isNotEmpty())
                <ul x-ref="hits" role="listbox" class="mt-3 max-h-[44vh] overflow-y-auto border-t border-(--bd) pt-2">
                    @foreach ($this->hits as $index => $hit)
                        <li role="option" :aria-selected="sel === {{ $index }}">
                            <a
                                href="{{ $hit->url }}"
                                wire:navigate
                                x-on:click="open = false"
                                :class="sel === {{ $index }} ? 'bg-(--surf2)' : ''"
                                class="flex items-center gap-3 rounded-[14px] px-3 py-2.5 hover:bg-(--surf2)"
                            >
                                <span class="w-[68px] shrink-0 text-[11px] text-(--mut) kicker">{{ $hit->kind }}</span>
                                <span class="min-w-0 flex-1 truncate text-[14px] text-(--tx)">{{ $hit->label }}</span>
                                @if ($hit->hint)
                                    <span class="max-w-[38%] shrink-0 truncate text-[12px] text-(--mut)">{{ $hit->hint }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
