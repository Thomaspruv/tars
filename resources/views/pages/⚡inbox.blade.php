<?php

use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\Task;
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
    <div class="flex items-center justify-between">
        <h1 class="text-[28px] font-bold tracking-[-0.02em] text-(--tx)">Inbox</h1>
        <span class="font-mono text-sm font-semibold {{ $this->pendingItems->isEmpty() ? 'text-(--ok)' : 'text-(--warn)' }}">
            {{ $this->pendingItems->count() }}
        </span>
    </div>

    <div class="mt-6 divide-y divide-(--bd) rounded-[14px] border border-(--bd) bg-(--surf)">
        @forelse ($this->pendingItems as $item)
            <div class="flex items-center gap-3 px-4 py-3.5" wire:key="inbox-{{ $item->id }}">
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
        @empty
            <p class="p-8 text-center text-sm text-(--mut)">✓ Inbox à zéro — rien ne traîne.</p>
        @endforelse
    </div>
</div>
