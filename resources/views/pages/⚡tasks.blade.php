<?php

use App\Models\Goal;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Tâches')] class extends Component
{
    #[Url]
    public string $filter = 'all';

    #[Url]
    public ?int $goalId = null;

    #[Computed]
    public function tasks(): Collection
    {
        $query = Task::open()->with(['goal', 'entity'])
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderBy('priority');

        match ($this->filter) {
            'today' => $query->whereDate('scheduled_date', today()),
            'week' => $query->whereBetween('scheduled_date', [today(), today()->addDays(7)]),
            'overdue' => $query->whereDate('due_date', '<', today()),
            'orphan' => $query->orphan(),
            default => null,
        };

        if ($this->goalId) {
            $query->where('goal_id', $this->goalId);
        }

        return $query->get();
    }

    #[Computed]
    public function orphanRatio(): array
    {
        $total = Task::open()->count();
        $orphan = Task::open()->orphan()->count();

        return [
            'orphan' => $orphan,
            'total' => $total,
            'percent' => $total > 0 ? (int) round(($orphan / $total) * 100) : 0,
        ];
    }

    #[Computed]
    public function allGoals(): Collection
    {
        return Goal::where('status', 'active')->orderBy('title')->get();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function toggleTask(int $taskId): void
    {
        Task::findOrFail($taskId)->toggleCompletion();

        unset($this->tasks, $this->orphanRatio);
    }
};
?>

<div>
    <x-screen-header
        treatment="work"
        title="Tâches"
    >
        <x-slot:subtitle>
            <span class="font-mono">{{ $this->orphanRatio['orphan'] }}/{{ $this->orphanRatio['total'] }}</span> sans objectif · <span class="font-mono">{{ $this->orphanRatio['percent'] }}%</span>
        </x-slot:subtitle>
    </x-screen-header>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        @foreach (['all' => 'Toutes', 'today' => "Aujourd'hui", 'week' => 'Cette semaine', 'overdue' => 'En retard', 'orphan' => 'Sans objectif'] as $value => $label)
            <button
                type="button"
                wire:click="setFilter('{{ $value }}')"
                class="rounded-full px-3 py-1.5 text-xs font-medium {{ $filter === $value ? 'bg-(--acbg) text-(--ac)' : 'bg-(--surf2) text-(--mut) hover:text-(--tx)' }}"
            >
                {{ $label }}
            </button>
        @endforeach

        <select wire:model.live="goalId" class="ml-auto rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)">
            <option value="">Tous les objectifs</option>
            @foreach ($this->allGoals as $goal)
                <option value="{{ $goal->id }}">{{ $goal->title }}</option>
            @endforeach
        </select>
    </div>

    <div class="mt-5 divide-y divide-(--bd) rounded-[12px] border border-(--bd) bg-(--surf)">
        @forelse ($this->tasks as $task)
            <x-task-row :task="$task" wire:key="task-{{ $task->id }}" />
        @empty
            <p class="p-8 text-center text-sm text-(--mut)">Aucune tâche ici.</p>
        @endforelse
    </div>
</div>
