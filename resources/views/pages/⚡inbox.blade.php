<?php

use App\Enums\InboxSuggestionStatus;
use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\InboxSuggestion;
use App\Models\Task;
use App\Support\Triage\InboxSuggestionApplier;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Inbox')] class extends Component
{
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

    #[Computed]
    public function recentAutoApplied(): Collection
    {
        return InboxSuggestion::where('status', InboxSuggestionStatus::AutoApplied->value)
            ->where('created_at', '>=', now()->subDays(7))
            ->with('inboxItem')
            ->orderByDesc('created_at')
            ->get();
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

    public function discard(int $itemId): void
    {
        InboxItem::findOrFail($itemId)->delete();

        unset($this->pendingItems);
    }
};
?>

<div>
    <x-screen-header treatment="work" title="Inbox">
        <x-slot:actions>
            <span class="font-mono text-sm font-semibold {{ $this->pendingItems->isEmpty() ? 'text-(--ok)' : 'text-(--warn)' }}">
                {{ $this->pendingItems->count() }}
            </span>
        </x-slot:actions>
    </x-screen-header>

    @error('acceptSuggestion')
        <p class="mt-4 rounded-[8px] border border-(--dgrbg) bg-(--dgrbg) px-3 py-2 text-sm text-(--dgr)">{{ $message }}</p>
    @enderror

    <div class="mt-6 divide-y divide-(--bd) rounded-[12px] border border-(--bd) bg-(--surf)">
        @forelse ($this->pendingItems as $item)
            @php $suggestion = $this->pendingSuggestions->get($item->id)?->first(); @endphp
            <div class="px-4 py-3.5" wire:key="inbox-{{ $item->id }}">
                <div class="flex items-center gap-3">
                    <p class="min-w-0 flex-1 text-sm text-(--tx)">{{ $item->content }}</p>

                    <div class="flex shrink-0 items-center gap-2">
                        <x-btn variant="secondary" wire:click="convertToTask({{ $item->id }})" class="!px-2.5 !py-1 text-xs">→ Tâche</x-btn>
                        <x-btn variant="secondary" wire:click="convertToEvent({{ $item->id }})" class="!px-2.5 !py-1 text-xs">→ Événement</x-btn>
                        <x-btn variant="secondary" wire:click="convertToGoal({{ $item->id }})" class="!px-2.5 !py-1 text-xs">→ Objectif</x-btn>
                        <button
                            type="button"
                            wire:click="discard({{ $item->id }})"
                            aria-label="Supprimer"
                            class="rounded-[9px] p-1.5 text-(--mut) hover:bg-(--dgrbg) hover:text-(--dgr)"
                        >
                            <flux:icon name="x-mark" class="size-4" />
                        </button>
                    </div>
                </div>

                @if ($suggestion)
                    <div class="mt-2.5 flex items-center gap-3 rounded-[9px] bg-(--aibg) px-3 py-2" wire:key="suggestion-{{ $suggestion->id }}">
                        <span class="text-(--ai)">◈</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-(--tx)">
                                <span class="font-semibold">→ {{ $suggestion->suggested_type->value }}</span>
                                — {{ $suggestion->reason }}
                            </p>
                        </div>
                        <x-badge type="status" :value="$suggestion->confidence->value === 'high' ? 'active' : 'paused'" :label="$suggestion->confidence->value" />
                        <div class="flex shrink-0 items-center gap-2">
                            <x-btn variant="secondary" class="!px-2.5 !py-1 text-xs" wire:click="rejectSuggestion({{ $suggestion->id }})">Refuser</x-btn>
                            <x-btn variant="agent" class="!px-2.5 !py-1 text-xs" wire:click="acceptSuggestion({{ $suggestion->id }})">Accepter</x-btn>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <p class="p-8 text-center text-sm text-(--mut)">✓ Inbox à zéro — rien ne traîne.</p>
        @endforelse
    </div>

    @if ($this->recentAutoApplied->isNotEmpty())
        <div class="mt-8">
            <h2 class="text-sm font-semibold text-(--tx)">Auto-classé récemment</h2>
            <div class="mt-3 space-y-2">
                @foreach ($this->recentAutoApplied as $suggestion)
                    <div class="flex items-center justify-between gap-4 rounded-[10px] border border-(--ai)/25 bg-(--aibg) px-3 py-2" wire:key="auto-{{ $suggestion->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-(--tx)">◈ {{ $suggestion->inboxItem->content }} → {{ $suggestion->suggested_type->value }}</p>
                            <p class="font-mono text-[10.5px] text-(--mut)">{{ $suggestion->reason }} — {{ $suggestion->created_at->translatedFormat('d M · H:i') }}</p>
                        </div>
                        <x-btn variant="ghost" class="!px-3 !py-1.5 text-xs" wire:click="cancelAutoApplied({{ $suggestion->id }})">Annuler</x-btn>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
