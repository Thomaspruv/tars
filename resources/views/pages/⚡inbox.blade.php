<?php

use App\Enums\InboxSuggestionStatus;
use App\Jobs\RunAgentCommandJob;
use App\Models\AgentRun;
use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\InboxSuggestion;
use App\Models\Note;
use App\Models\Task;
use App\Support\FuzzyMatcher;
use App\Support\Triage\InboxSuggestionApplier;
use App\Support\Triage\TriageAgent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('À trier')] class extends Component
{
    public ?string $triageMessage = null;

    /**
     * Le trieur passe tout seul en arrivant sur l'écran — mais au plus une
     * fois par dix minutes : l'inbox n'est pas un bouton de rafraîchissement.
     */
    private const string AUTO_TRIAGE_KEY = 'inbox:last-auto-triage';

    public function mount(): void
    {
        $this->autoTriage();
    }

    #[Computed]
    public function pendingItems(): Collection
    {
        return InboxItem::whereNull('processed_at')
            ->with(['suggestions' => fn ($query) => $query->where('status', InboxSuggestionStatus::Pending->value)])
            ->latest()
            ->get();
    }

    /**
     * Une proposition par item — la plus récente. Deux ✓ sur le même item
     * créeraient deux fiches pour une seule phrase.
     *
     * @return \Illuminate\Support\Collection<int, InboxSuggestion>
     */
    #[Computed]
    public function acceptableSuggestions(): \Illuminate\Support\Collection
    {
        return $this->pendingItems
            ->map(fn (InboxItem $item): ?InboxSuggestion => $item->suggestions->first())
            ->filter(fn (?InboxSuggestion $suggestion): bool => $suggestion !== null && $suggestion->confidence->isActionable())
            ->values();
    }

    /**
     * Ce qui a été posé cette semaine, qu'Hermès l'ait classé tout seul ou que
     * je l'aie validé : la même ligne, annulable 24 h.
     *
     * @return Collection<int, InboxSuggestion>
     */
    #[Computed]
    public function settledThisWeek(): Collection
    {
        return InboxSuggestion::whereIn('status', [InboxSuggestionStatus::Accepted->value, InboxSuggestionStatus::AutoApplied->value])
            ->where('created_at', '>=', now()->startOfWeek())
            ->with('inboxItem')
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function lastTriageRun(): ?AgentRun
    {
        return AgentRun::where('agent_name', 'triage')->latest('started_at')->first();
    }

    /**
     * Le trieur est-il en vol ? La pastille du bouton agent le dit, et c'est
     * la seule chose qui bouge pendant qu'il passe.
     */
    #[Computed]
    public function triageRunning(): bool
    {
        return $this->triageMessage !== null
            || AgentRun::where('agent_name', 'triage')->whereNull('finished_at')->exists();
    }

    #[Computed]
    public function kicker(): string
    {
        $count = $this->pendingItems->count();

        if ($count === 0) {
            return 'rien en attente';
        }

        $base = $count.' '.Str::plural('item', $count);
        $lastRun = $this->lastTriageRun?->started_at;

        if ($lastRun === null) {
            return $base.' · le plus vieux date de '.$this->oldestPendingLabel;
        }

        return $base.' · pré-qualifiés par Hermès il y a '.$lastRun->diffForHumans([
            'short' => true,
            'parts' => 1,
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
        ]);
    }

    #[Computed]
    public function oldestPendingLabel(): ?string
    {
        return $this->pendingItems->last()?->created_at?->translatedFormat('l j F');
    }

    /**
     * @return Collection<int, Goal>
     */
    #[Computed]
    public function goals(): Collection
    {
        return Goal::all();
    }

    /**
     * Les champs de la proposition, en puces neutres : quand, à qui, pour quel
     * objectif. L'objectif porte sa pastille de teinte, comme partout.
     *
     * @return list<array{prefix: string, label: string, goal: ?Goal}>
     */
    public function chipsFor(InboxSuggestion $suggestion): array
    {
        $fields = $suggestion->suggested_fields;
        $chips = [];

        $when = $fields['starts_at'] ?? $fields['due_date'] ?? $fields['scheduled_date'] ?? null;

        if (is_string($when) && $when !== '') {
            try {
                $date = \Illuminate\Support\Carbon::parse($when);

                $chips[] = ['prefix' => 'date', 'label' => $date->translatedFormat('D j M'), 'goal' => null];

                if ($date->format('H:i') !== '00:00') {
                    $chips[] = [
                        'prefix' => 'heure',
                        'label' => $date->minute === 0 ? $date->format('G').'h' : $date->format('G\hi'),
                        'goal' => null,
                    ];
                }
            } catch (\Throwable) {
                // Une date que l'agent a mal écrite ne doit pas casser l'écran.
            }
        }

        if (is_string($fields['entity'] ?? null) && $fields['entity'] !== '') {
            $chips[] = ['prefix' => '@', 'label' => $fields['entity'], 'goal' => null];
        }

        if (is_string($fields['goal'] ?? null) && $fields['goal'] !== '') {
            $chips[] = ['prefix' => '#', 'label' => $fields['goal'], 'goal' => $this->resolveGoal($fields['goal'])];
        }

        if (is_string($fields['recurrence'] ?? null) && $fields['recurrence'] !== '') {
            $chips[] = ['prefix' => '↻', 'label' => $fields['recurrence'], 'goal' => null];
        }

        return $chips;
    }

    /**
     * « ✓ Relancer le syndic → Tâche · @Appart Lilas · mardi » : le
     * rattachement de la ligne du bas.
     */
    public function attachmentFor(InboxSuggestion $suggestion): ?string
    {
        $fields = $suggestion->suggested_fields;

        $parts = array_filter([
            is_string($fields['goal'] ?? null) ? '#'.$fields['goal'] : null,
            is_string($fields['entity'] ?? null) ? '@'.$fields['entity'] : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public function triageNow(): void
    {
        if (! app(TriageAgent::class)->isConfigured()) {
            $this->addError('triage', "Le trieur d'inbox n'est pas configuré. Il s'active sur l'écran Agents.");

            return;
        }

        RunAgentCommandJob::dispatch('triage:run');

        $this->triageMessage = 'Le trieur passe sur l\'inbox, ça arrive.';
        $this->resetErrorBag('triage');
    }

    /**
     * Tout valider : les propositions dont Hermès est sûr, en bloc. Celles où
     * il hésite restent là — c'est tout l'intérêt du seuil.
     */
    public function acceptAll(): void
    {
        $suggestions = $this->acceptableSuggestions;

        if ($suggestions->isEmpty()) {
            return;
        }

        try {
            DB::transaction(function () use ($suggestions): void {
                $applier = app(InboxSuggestionApplier::class);

                foreach ($suggestions as $suggestion) {
                    $applier->apply($suggestion);
                }
            });

            $this->resetErrorBag('acceptSuggestion');
        } catch (\Throwable $e) {
            $this->addError('acceptSuggestion', $e->getMessage());
        }

        unset($this->pendingItems, $this->acceptableSuggestions, $this->settledThisWeek);
    }

    public function acceptSuggestion(int $suggestionId): void
    {
        $suggestion = InboxSuggestion::findOrFail($suggestionId);

        try {
            app(InboxSuggestionApplier::class)->apply($suggestion);
            $this->resetErrorBag('acceptSuggestion');
        } catch (\Throwable $e) {
            $this->addError('acceptSuggestion', $e->getMessage());

            return;
        }

        unset($this->pendingItems, $this->acceptableSuggestions, $this->settledThisWeek);
    }

    /**
     * Écarter la proposition sans toucher à l'item : il reste en inbox, et le
     * trieur ne repropose pas le même type pendant trente jours.
     */
    public function dismissSuggestion(int $suggestionId): void
    {
        InboxSuggestion::where('id', $suggestionId)->update(['status' => InboxSuggestionStatus::Rejected->value]);

        unset($this->pendingItems, $this->acceptableSuggestions);
    }

    public function cancelAutoApplied(int $suggestionId): void
    {
        $suggestion = InboxSuggestion::findOrFail($suggestionId);

        app(InboxSuggestionApplier::class)->revert($suggestion);

        unset($this->pendingItems, $this->acceptableSuggestions, $this->settledThisWeek);
    }

    public function convertToTask(int $itemId): void
    {
        $item = InboxItem::findOrFail($itemId);

        $task = Task::create(['title' => $item->content, 'status' => 'todo']);

        $this->markConverted($item, 'task', $task->id);
    }

    public function convertToEvent(int $itemId): void
    {
        $item = InboxItem::findOrFail($itemId);

        $event = Event::create(['title' => $item->content, 'starts_at' => now()->addDay()]);

        $this->markConverted($item, 'event', $event->id);
    }

    public function convertToGoal(int $itemId): void
    {
        $item = InboxItem::findOrFail($itemId);

        $goal = Goal::create(['title' => $item->content]);

        $this->markConverted($item, 'goal', $goal->id);
    }

    public function convertToNote(int $itemId): void
    {
        $item = InboxItem::findOrFail($itemId);

        $note = Note::create(['content' => $item->content]);

        $this->markConverted($item, 'note', $note->id);
    }

    public function discard(int $itemId): void
    {
        InboxItem::findOrFail($itemId)->delete();

        unset($this->pendingItems, $this->acceptableSuggestions);
    }

    private function markConverted(InboxItem $item, string $type, int $recordId): void
    {
        $item->update(['processed_at' => now(), 'converted_to_type' => $type, 'converted_to_id' => $recordId]);

        // Le choix manuel tranche : la proposition encore en attente n'a plus
        // de raison d'être.
        $item->suggestions()
            ->where('status', InboxSuggestionStatus::Pending->value)
            ->update(['status' => InboxSuggestionStatus::Rejected->value]);

        unset($this->pendingItems, $this->acceptableSuggestions);
    }

    private function autoTriage(): void
    {
        $hasPendingSuggestion = InboxSuggestion::where('status', InboxSuggestionStatus::Pending->value)->exists();

        if ($hasPendingSuggestion || Cache::has(self::AUTO_TRIAGE_KEY)) {
            return;
        }

        if (InboxItem::whereNull('processed_at')->doesntExist() || ! app(TriageAgent::class)->isConfigured()) {
            return;
        }

        Cache::put(self::AUTO_TRIAGE_KEY, now()->toDateTimeString(), now()->addMinutes(10));

        $this->triageNow();
    }

    private function resolveGoal(string $name): ?Goal
    {
        return app(FuzzyMatcher::class)->bestMatch($this->goals, $name, fn (Goal $goal): string => $goal->title);
    }
};
?>

<div>
    <x-space-tabs space="trier" class="mb-3.5 -ml-3.5" />

    <x-screen-header :kicker="$this->kicker" title="À trier" class="mb-3">
        <x-slot:actions>
            @if ($this->acceptableSuggestions->isNotEmpty())
                <x-btn
                    variant="agent"
                    size="md"
                    wire:click="acceptAll"
                    wire:loading.attr="disabled"
                    wire:target="acceptAll"
                    :loading="$this->triageRunning"
                >Tout valider ({{ $this->acceptableSuggestions->count() }})</x-btn>
            @endif

            <x-btn variant="secondary" size="md" wire:click="triageNow" wire:loading.attr="disabled" wire:target="triageNow">
                Repasser le trieur
            </x-btn>
        </x-slot:actions>
    </x-screen-header>

    {{-- L'écran dit lui-même ce que chaque geste fait : c'est ce qui rend le ✓
         acceptable, parce qu'on sait d'avance qu'il ne pose rien d'autre. --}}
    <p class="mb-[18px] max-w-[760px] text-[13px] leading-[1.6] text-(--mut)">
        Hermès propose un type et un rattachement pour chaque item.
        <strong class="font-semibold text-(--tx)">✓</strong> pose l'item tel quel,
        <strong class="font-semibold text-(--tx)">Changer</strong> ouvre les autres types. Rien ne part sans toi.
    </p>

    @if ($triageMessage)
        <p class="mb-4 text-[13px] text-(--ai)">{{ $triageMessage }}</p>
    @endif

    @error('triage')
        <p class="mb-4 text-[13px] text-(--dgr)">{{ $message }}</p>
    @enderror

    @error('acceptSuggestion')
        <p class="mb-4 text-[13px] text-(--dgr)">{{ $message }}</p>
    @enderror

    @forelse ($this->pendingItems as $item)
        @php $suggestion = $item->suggestions->first(); @endphp

        <x-card
            padding="18"
            class="mb-3 flex flex-col gap-3"
            wire:key="inbox-{{ $item->id }}"
            x-data="{ changing: false }"
        >
            <div class="flex items-baseline gap-3">
                <p class="min-w-0 flex-1 text-[16px] leading-[1.45] font-medium text-(--tx)">{{ $item->content }}</p>
                <time class="shrink-0 text-[12px] text-(--mut)">{{ $item->created_at->translatedFormat('D j M · H:i') }}</time>
            </div>

            @if ($suggestion && $suggestion->confidence->isActionable())
                {{-- Un item pré-qualifié n'a qu'une issue sous la main : ✓. Le
                     reste est derrière « Changer ». --}}
                <div class="flex flex-wrap items-center gap-2">
                    <x-badge type="pill" tone="agent">
                        <span class="size-[6px] shrink-0 rounded-full bg-(--ai)" aria-hidden="true"></span>{{ $suggestion->suggested_type->label() }}
                    </x-badge>

                    @foreach ($this->chipsFor($suggestion) as $index => $chip)
                        <x-badge type="pill" tone="neutral" wire:key="chip-{{ $suggestion->id }}-{{ $index }}">
                            @if ($chip['goal'])
                                <span class="size-[8px] shrink-0 rounded-full" style="background: var(--goal-{{ $chip['goal']->color_slot }})" aria-hidden="true"></span>
                            @endif
                            <span class="opacity-75">{{ $chip['prefix'] }}</span>{{ $chip['label'] }}
                        </x-badge>
                    @endforeach

                    @if ($suggestion->reason)
                        <span class="text-[12px] leading-[1.45] text-(--mut)">{{ $suggestion->reason }}</span>
                    @endif

                    <div class="ml-auto flex shrink-0 items-center gap-2">
                        <x-btn variant="secondary" size="xs" x-on:click="changing = ! changing">Changer</x-btn>
                        <x-btn
                            variant="agent"
                            size="xs"
                            class="min-h-[32px] min-w-[44px] !px-0"
                            aria-label="Valider"
                            wire:click="acceptSuggestion({{ $suggestion->id }})"
                            wire:target="acceptSuggestion({{ $suggestion->id }})"
                            wire:loading.attr="disabled"
                        >✓</x-btn>
                    </div>
                </div>

                <div
                    x-show="changing"
                    x-cloak
                    x-transition:enter="transition-[opacity,translate] duration-(--dur-swap) ease-(--ease)"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    class="border-t border-(--bd) pt-3"
                >
                    <x-inbox-type-row :item="$item" :suggested="$suggestion->suggested_type" />
                </div>
            @else
                {{-- L'agent a le droit de ne pas savoir : il le dit, et il rend
                     la main au lieu de deviner. --}}
                <div class="flex flex-wrap items-center gap-2">
                    <x-badge type="pill" tone="warn">Je ne suis pas sûr</x-badge>

                    @if ($suggestion?->reason)
                        <span class="text-[12px] leading-[1.45] text-(--mut)">{{ $suggestion->reason }}</span>
                    @endif
                </div>

                <x-inbox-type-row :item="$item" :suggested="$suggestion?->suggested_type" />
            @endif
        </x-card>
    @empty
        <x-card padding="22" class="mb-3">
            <p class="text-[16px] text-(--ai)">Rien à trier — rien ne traîne.</p>
        </x-card>
    @endforelse

    {{-- Ce qui est déjà posé passe dans le creux : c'est de l'historique, plus
         du travail. Annulable 24 h — c'est ce qui rend l'auto-classement
         acceptable. --}}
    @if ($this->settledThisWeek->isNotEmpty())
        <x-card tone="sunk" padding="18" class="mt-6">
            <p class="mb-3 text-[11px] text-(--mut) kicker">
                Cette semaine · {{ $this->settledThisWeek->count() }} {{ Str::plural('posé', $this->settledThisWeek->count()) }}
            </p>

            <div class="flex flex-col">
                @foreach ($this->settledThisWeek as $settled)
                    <div
                        class="flex items-baseline gap-2.5 py-[7px] text-[13px] leading-[1.5] text-(--mut) {{ $loop->first ? '' : 'border-t border-(--bd)' }}"
                        wire:key="settled-{{ $settled->id }}"
                    >
                        <span class="shrink-0 text-(--ai)" aria-hidden="true">✓</span>

                        <p class="min-w-0 flex-1">
                            {{ $settled->inboxItem?->content }} → {{ $settled->suggested_type->label() }}
                            @if ($this->attachmentFor($settled)) · {{ $this->attachmentFor($settled) }} @endif
                            · {{ $settled->created_at->translatedFormat('l') }}
                        </p>

                        @if ($settled->created_at->greaterThanOrEqualTo(now()->subDay()))
                            <x-btn variant="ghost" size="xs" class="shrink-0" wire:click="cancelAutoApplied({{ $settled->id }})">Annuler</x-btn>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif
</div>
