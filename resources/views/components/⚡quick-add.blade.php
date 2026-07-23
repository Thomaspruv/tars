<?php

use App\Models\InboxItem;
use App\Models\Task;
use App\Support\QuickAdd\QuickAddParser;
use App\Support\QuickAdd\QuickAddResult;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $query = '';

    #[Computed]
    public function parsed(): QuickAddResult
    {
        return app(QuickAddParser::class)->parse($this->query);
    }

    public function submit(): void
    {
        $trimmed = trim($this->query);

        if ($trimmed === '') {
            return;
        }

        $result = $this->parsed;

        if ($result->hasStructuredToken()) {
            Task::create([
                'title' => $result->title !== '' ? $result->title : $trimmed,
                'scheduled_date' => $result->date,
                'goal_id' => $result->goal?->id,
                'entity_id' => $result->entity?->id,
                'priority' => $result->priority,
                'status' => 'todo',
            ]);
        } else {
            InboxItem::create(['content' => $trimmed]);
        }

        $this->reset('query');
    }
};
?>

<div class="relative flex-1 max-w-xl">
    <form wire:submit="submit" class="relative">
        <flux:icon name="magnifying-glass" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-(--ac)" />
        <input
            id="quick-add-input"
            type="text"
            wire:model.live.debounce.300ms="query"
            wire:keydown.escape="$set('query', '')"
            placeholder="Capture rapide : &quot;préparer bilan vendredi #500k-ca p1&quot;"
            autocomplete="off"
            class="w-full rounded-[10px] border border-(--bd2) bg-(--in) py-2 pr-24 pl-9 font-mono text-sm text-(--tx) placeholder:text-(--mut) focus:border-(--ac) focus:outline-none"
        />
        <span class="absolute top-1/2 right-3 -translate-y-1/2 rounded-[6px] border border-(--bd2) px-1.5 py-0.5 font-mono text-[10.5px] text-(--mut)">⌘K</span>
    </form>

    @if ($query !== '')
        <div class="absolute top-full left-0 z-10 mt-1.5 flex w-full flex-wrap items-center gap-1.5 rounded-[10px] border border-(--bd2) bg-(--surf) p-2.5 shadow-lg">
            @foreach ($this->parsed->tokens as $token)
                @php
                    $styles = [
                        'date' => 'bg-(--acbg) text-(--ac)',
                        'entity' => 'border border-(--bd2) text-(--mut)',
                        'goal' => 'border border-(--ac)/35 text-(--ac)',
                        'priority' => match ($token->raw) {
                            'p1' => 'bg-(--dgrbg) text-(--dgr)',
                            'p2' => 'bg-(--warnbg) text-(--warn)',
                            default => 'bg-(--surf2) text-(--mut)',
                        },
                    ];
                @endphp
                <span class="rounded-[5px] px-1.5 py-0.5 font-mono text-[11px] {{ $styles[$token->type] }}">{{ $token->raw }}</span>
            @endforeach

            @if ($this->parsed->title !== '')
                <span class="text-sm text-(--tx)">{{ $this->parsed->title }}</span>
            @endif

            <span class="ml-auto rounded-[5px] px-1.5 py-0.5 font-mono text-[10.5px] {{ $this->parsed->hasStructuredToken() ? 'bg-(--acbg) text-(--ac)' : 'bg-(--surf2) text-(--mut)' }}">
                ↵ {{ $this->parsed->hasStructuredToken() ? 'tâche' : 'inbox' }}
            </span>
        </div>
    @endif
</div>
