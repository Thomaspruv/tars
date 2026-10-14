<?php

use App\Enums\InboxSuggestionStatus;
use App\Jobs\RunAgentCommandJob;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\InboxSuggestion;
use App\Models\Note;
use App\Models\Task;
use App\Support\Triage\InboxSuggestionApplier;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Inbox')] class extends Component
{
    public ?string $triageMessage = null;

    #[Computed]
    public function pendingItems(): Collection
    {
        return InboxItem::whereNull('processed_at')->latest()->get();
    }

    #[Computed]
    public function pendingSuggestions(): \Illuminate\Support\Collection
    {
        return InboxSuggestion::where('status', InboxSuggestionStatus::Pending->value)->get()->groupBy('inbox_item_id');
    }

    /**
     * Ce que l'agent a classé tout seul reste annulable pendant 24 h : c'est
     * ce qui rend l'auto-classement acceptable.
     */
    #[Computed]
    public function recentAutoApplied(): Collection
    {
        return InboxSuggestion::where('status', InboxSuggestionStatus::AutoApplied->value)
            ->where('created_at', '>=', now()->subDay())
            ->with('inboxItem')
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function capturedThisWeek(): int
    {
        return InboxItem::where('created_at', '>=', now()->startOfWeek())->count();
    }

    #[Computed]
    public function sortedByAgentThisWeek(): int
    {
        return InboxSuggestion::whereIn('status', [InboxSuggestionStatus::Accepted->value, InboxSuggestionStatus::AutoApplied->value])
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();
    }

    #[Computed]
    public function lastTriageRun(): ?AgentRun
    {
        return AgentRun::where('agent_name', 'triage')->latest('started_at')->first();
    }

    #[Computed]
    public function oldestPendingLabel(): ?string
    {
        $oldest = $this->pendingItems->last();

        return $oldest?->created_at?->translatedFormat('l j F');
    }

    public function triageNow(): void
    {
        if (! AgentConfig::where('agent_name', 'triage')->where('enabled', true)->exists()) {
            $this->addError('triage', "Le trieur d'inbox n'est pas configuré. Il s'active sur l'écran Agents.");

            return;
        }

        RunAgentCommandJob::dispatch('triage:run');

        $this->triageMessage = 'Le trieur passe sur l\'inbox, ça arrive.';
        $this->resetErrorBag('triage');
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

        unset($this->pendingItems, $this->pendingSuggestions);
    }

    public function rejectSuggestion(int $suggestionId): void
    {
        InboxSuggestion::where('id', $suggestionId)->update(['status' => InboxSuggestionStatus::Rejected->value]);

        unset($this->pendingSuggestions);
    }

    public function cancelAutoApplied(int $suggestionId): void
    {
        $suggestion = InboxSuggestion::findOrFail($suggestionId);

        app(InboxSuggestionApplier::class)->revert($suggestion);

        unset($this->pendingItems, $this->recentAutoApplied);
    }

    public function convertToTask(int $itemId): void
    {
        $item = InboxItem::findOrFail($itemId);

        $task = Task::create(['title' => $item->content, 'status' => 'todo']);

        $item->update(['processed_at' => now(), 'converted_to_type' => 'task', 'converted_to_id' => $task->id]);

        unset($this->pendingItems);
    }

    public function convertToEvent(int $itemId): void
    {
        $item = InboxItem::findOrFail($itemId);

        $event = Event::create(['title' => $item->content, 'starts_at' => now()->addDay()]);

        $item->update(['processed_at' => now(), 'converted_to_type' => 'event', 'converted_to_id' => $event->id]);

        unset($this->pendingItems);
    }

    public function convertToGoal(int $itemId): void
    {
        $item = InboxItem::findOrFail($itemId);

        $goal = Goal::create(['title' => $item->content]);

        $item->update(['processed_at' => now(), 'converted_to_type' => 'goal', 'converted_to_id' => $goal->id]);

        unset($this->pendingItems);
    }

    public function convertToNote(int $itemId): void
    {
        $item = InboxItem::findOrFail($itemId);

        $note = Note::create(['content' => $item->content]);

        $item->update(['processed_at' => now(), 'converted_to_type' => 'note', 'converted_to_id' => $note->id]);

        unset($this->pendingItems);
    }

    public function discard(int $itemId): void
    {
        InboxItem::findOrFail($itemId)->delete();

        unset($this->pendingItems);
    }
};
?>

<div>
    <x-screen-header
        :kicker="$this->pendingItems->isEmpty()
            ? 'rien en attente'
            : $this->pendingItems->count().' '.Str::plural('item', $this->pendingItems->count()).' · le plus vieux date de '.$this->oldestPendingLabel"
        title="Inbox"
        class="mb-[18px]"
    >
        <x-slot:actions>
            <x-btn variant="agent" size="md" wire:click="triageNow" wire:loading.attr="disabled">Trier pour moi</x-btn>
        </x-slot:actions>
    </x-screen-header>

    @if ($triageMessage)
        <p class="mb-4 text-[13px] text-(--ai)">{{ $triageMessage }}</p>
    @endif

    @error('triage')
        <p class="mb-4 text-[13px] text-(--dgr)">{{ $message }}</p>
    @enderror

    @error('acceptSuggestion')
        <p class="mb-4 text-[13px] text-(--dgr)">{{ $message }}</p>
    @enderror

    <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[1fr_306px]">
        <div>
            @forelse ($this->pendingItems as $item)
                @php $suggestion = $this->pendingSuggestions->get($item->id)?->first(); @endphp

                @if ($suggestion)
                    {{-- Quand l'agent a une proposition, elle *remplace* les
                         quatre issues : impossible de confondre ce que je
                         décide et ce qu'il propose. --}}
                    <x-agent-block padding="22" class="mb-3" wire:key="inbox-{{ $item->id }}">
                        <div class="mb-3 flex items-baseline gap-3">
                            <p class="min-w-0 flex-1 text-[16px] leading-[1.5] text-(--tx)">{{ $item->content }}</p>
                            <time class="shrink-0 text-[12px] text-(--mut)">{{ $item->created_at->translatedFormat('D j M · H:i') }}</time>
                        </div>

                        <div class="mb-3.5 flex flex-wrap items-center gap-2.5">
                            <span class="text-[13px] font-bold text-(--ai)">Hermès propose</span>
                            <span class="rounded-full bg-(--aibg2) px-3 py-[5px] text-[13px] text-(--tx)">{{ ucfirst($suggestion->suggested_type->value) }}</span>
                            @foreach ($suggestion->suggested_fields as $field => $value)
                                @if (is_scalar($value) && $value !== '' && $value !== null)
                                    <span class="rounded-full bg-(--aibg2) px-3 py-[5px] text-[13px] text-(--tx)">{{ $value }}</span>
                                @endif
                            @endforeach
                            @if ($suggestion->confidence->value !== 'high')
                                <span class="text-[12px] text-(--mut)">confiance moyenne</span>
                            @endif
                        </div>

                        @if ($suggestion->reason)
                            <p class="mb-3.5 text-[13px] leading-[1.55] text-(--mut)">{{ $suggestion->reason }}</p>
                        @endif

                        <div class="flex flex-wrap gap-2.5">
                            <x-btn variant="agent" wire:click="acceptSuggestion({{ $suggestion->id }})">Valider</x-btn>
                            <x-btn variant="secondary" wire:click="rejectSuggestion({{ $suggestion->id }})">Corriger</x-btn>
                            <x-btn variant="ghost" wire:click="convertToTask({{ $item->id }})">Je m'en occupe</x-btn>
                        </div>
                    </x-agent-block>
                @else
                    <x-card padding="22" class="mb-3" wire:key="inbox-{{ $item->id }}">
                        <div class="mb-3.5 flex items-baseline gap-3">
                            <p class="min-w-0 flex-1 text-[16px] leading-[1.5] text-(--tx)">{{ $item->content }}</p>
                            <time class="shrink-0 text-[12px] text-(--mut)">{{ $item->created_at->translatedFormat('D j M · H:i') }}</time>
                        </div>

                        {{-- Les quatre issues, en pilules : l'item brut ne
                             propose rien d'autre, et surtout rien de destructif
                             sous la main. --}}
                        <div class="flex flex-wrap items-center gap-2">
                            <x-btn variant="secondary" class="!font-semibold" wire:click="convertToTask({{ $item->id }})">Tâche</x-btn>
                            <x-btn variant="secondary" class="!font-semibold" wire:click="convertToEvent({{ $item->id }})">Événement</x-btn>
                            <x-btn variant="secondary" class="!font-semibold" wire:click="convertToGoal({{ $item->id }})">Objectif</x-btn>
                            <x-btn variant="secondary" class="!font-semibold" wire:click="convertToNote({{ $item->id }})">Note</x-btn>
                            <x-btn variant="ghost" class="ml-auto" wire:click="discard({{ $item->id }})">Supprimer</x-btn>
                        </div>
                    </x-card>
                @endif
            @empty
                <x-card padding="22" class="mb-3">
                    <p class="text-[16px] text-(--ai)">Inbox à zéro — rien ne traîne.</p>
                </x-card>
            @endforelse

            @if ($this->recentAutoApplied->isNotEmpty())
                <div class="mt-6 mb-3 flex items-center gap-3 px-1">
                    <h2 class="font-display text-[17px] font-semibold text-(--tx)">Auto-classé cette nuit</h2>
                    <span class="text-[13px] text-(--mut)">{{ $this->recentAutoApplied->count() }} · annulable jusqu'à demain</span>
                </div>

                <x-card tone="sunk" padding="0" class="overflow-hidden rounded-[16px]">
                    @foreach ($this->recentAutoApplied as $suggestion)
                        <div
                            class="flex items-center gap-3.5 px-[22px] py-[13px] {{ $loop->first ? '' : 'border-t border-(--bd)' }}"
                            wire:key="auto-{{ $suggestion->id }}"
                        >
                            <span class="size-[6px] shrink-0 rounded-full bg-(--ai)" aria-hidden="true"></span>
                            <p class="min-w-0 flex-1 truncate text-[14px] text-(--tx)">
                                « {{ $suggestion->inboxItem?->content }} » → {{ $suggestion->suggested_type->value }}
                            </p>
                            <button
                                type="button"
                                wire:click="cancelAutoApplied({{ $suggestion->id }})"
                                class="shrink-0 text-[13px] text-(--ai) hover:underline"
                            >Annuler</button>
                        </div>
                    @endforeach
                </x-card>
            @endif
        </div>

        <div class="space-y-3.5">
            <x-card padding="20">
                <p class="mb-3.5 text-[11px] tracking-[.12em] text-(--mut) uppercase">Cette semaine</p>
                <div class="mb-1.5 flex items-baseline gap-2.5">
                    <span class="font-display text-[26px] leading-none font-bold text-(--tx)">{{ $this->capturedThisWeek }}</span>
                    <span class="text-[13px] text-(--mut)">{{ Str::plural('item', $this->capturedThisWeek) }} {{ Str::plural('capturé', $this->capturedThisWeek) }}</span>
                </div>
                <p class="text-[13px] leading-[1.6] text-(--mut)">
                    {{ $this->sortedByAgentThisWeek }} {{ Str::plural('classé', $this->sortedByAgentThisWeek) }} par Hermès. Aucun perdu.
                </p>
            </x-card>

            <x-card padding="20">
                <p class="mb-3 text-[11px] tracking-[.12em] text-(--mut) uppercase">Comment ça marche</p>
                <p class="text-[13px] leading-[1.7] text-(--mut)">
                    Tout ce que le quick-add ne comprend pas arrive ici, tel quel. Hermès classe ce dont il est sûr,
                    propose le reste, et ne supprime jamais rien.
                </p>
            </x-card>

            @if ($this->lastTriageRun)
                <x-agent-block title="Dernier passage">
                    <p class="text-[14px] leading-[1.5] text-(--tx)">
                        {{ $this->lastTriageRun->started_at->translatedFormat('D j M à H:i') }}
                        @if ($this->lastTriageRun->finished_at)
                            · {{ (int) $this->lastTriageRun->started_at->diffInSeconds($this->lastTriageRun->finished_at) }} s
                        @endif
                        @if ($this->lastTriageRun->tokens_in || $this->lastTriageRun->tokens_out)
                            · {{ number_format(($this->lastTriageRun->tokens_in ?? 0) + ($this->lastTriageRun->tokens_out ?? 0), 0, ',', ' ') }} tokens
                        @endif
                    </p>
                </x-agent-block>
            @endif
        </div>
    </div>
</div>
