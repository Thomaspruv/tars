<?php

use App\Enums\GoalStatus;
use App\Enums\MilestoneStatus;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Task;
use App\Support\Brain\BrainSettings;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Objectif')] class extends Component
{
    public Goal $goal;

    public string $newMilestoneTitle = '';

    public bool $showEditModal = false;

    public string $editTitle = '';

    public string $editDescription = '';

    public ?string $editTargetDate = null;

    public function mount(): void
    {
        $this->goal->load(['milestones' => fn ($query) => $query->orderBy('position'), 'lifeArea', 'entity']);
    }

    #[Computed]
    public function tasks(): Collection
    {
        return $this->goal->tasks()->with('entity')->orderByRaw('status = "done"')->orderBy('due_date')->get();
    }

    #[Computed]
    public function brainConfigured(): bool
    {
        return app(BrainSettings::class)->isConfigured();
    }

    #[Computed]
    public function brainDocuments(): Collection
    {
        return $this->goal->brainDocuments()->latest('mtime')->get();
    }

    #[Computed]
    public function weeklyActivity(): array
    {
        $completed = $this->goal->tasks()
            ->where('status', 'done')
            ->where('completed_at', '>=', now()->subWeeks(6))
            ->get(['completed_at']);

        return collect(range(5, 0))->map(function (int $weeksAgo) use ($completed): int {
            $weekStart = now()->subWeeks($weeksAgo)->startOfWeek();
            $weekEnd = $weekStart->copy()->endOfWeek();

            return $completed->filter(fn ($task) => $task->completed_at->between($weekStart, $weekEnd))->count();
        })->all();
    }

    public function toggleTask(int $taskId): void
    {
        Task::findOrFail($taskId)->toggleCompletion();

        unset($this->tasks, $this->weeklyActivity);
    }

    public function addMilestone(): void
    {
        $this->validate(['newMilestoneTitle' => ['required', 'string', 'max:255']]);

        $this->goal->milestones()->create([
            'title' => $this->newMilestoneTitle,
            'position' => $this->goal->milestones->count(),
        ]);

        $this->newMilestoneTitle = '';
        $this->goal->load('milestones');
    }

    public function toggleMilestone(int $milestoneId): void
    {
        $milestone = Milestone::findOrFail($milestoneId);
        $milestone->update(['status' => $milestone->status === MilestoneStatus::Done ? 'pending' : 'done']);

        $this->goal->load('milestones');
    }

    public function deleteMilestone(int $milestoneId): void
    {
        Milestone::findOrFail($milestoneId)->delete();
        $this->goal->load('milestones');
    }

    public function updateStatus(string $status): void
    {
        $this->goal->update(['status' => $status]);
    }

    public function openEditModal(): void
    {
        $this->editTitle = $this->goal->title;
        $this->editDescription = (string) $this->goal->description;
        $this->editTargetDate = $this->goal->target_date?->toDateString();
        $this->showEditModal = true;
    }

    public function saveEdit(): void
    {
        $validated = $this->validate([
            'editTitle' => ['required', 'string', 'max:255'],
            'editDescription' => ['nullable', 'string'],
            'editTargetDate' => ['nullable', 'date'],
        ]);

        $this->goal->update([
            'title' => $validated['editTitle'],
            'description' => $validated['editDescription'],
            'target_date' => $validated['editTargetDate'],
        ]);

        $this->showEditModal = false;
    }

    public function delete()
    {
        $this->goal->delete();

        return $this->redirect(route('goals.index'), navigate: true);
    }
};
?>

<div>
    <a href="{{ route('goals.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm text-(--mut) hover:text-(--tx)">
        <flux:icon name="arrow-left" class="size-4" /> Objectifs
    </a>

    <div class="mt-4 flex items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <x-badge-status :status="$goal->status" />
                @if ($goal->lifeArea)
                    <span class="text-xs text-(--mut)">{{ $goal->lifeArea->name }}</span>
                @endif
            </div>
            <h1 class="mt-2 text-[28px] font-bold tracking-[-0.02em] text-(--tx)">{{ $goal->title }}</h1>
            @if ($goal->description)
                <p class="mt-2 max-w-xl text-sm text-(--mut)">{{ $goal->description }}</p>
            @endif
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <select wire:change="updateStatus($event.target.value)" class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)">
                @foreach (GoalStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected($goal->status === $status)>{{ $status->value }}</option>
                @endforeach
            </select>
            <x-btn variant="secondary" wire:click="openEditModal">Modifier</x-btn>
            <x-btn variant="danger" wire:click="delete" wire:confirm="Supprimer cet objectif ?">Supprimer</x-btn>
        </div>
    </div>

    <div class="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-2">
        <div class="space-y-8">
            <div>
                <h2 class="text-base font-semibold text-(--tx)">Jalons</h2>
                <div class="mt-3 space-y-1.5">
                    @foreach ($goal->milestones as $milestone)
                        <div class="flex items-center gap-3 rounded-[10px] px-3 py-2 hover:bg-(--surf2)" wire:key="milestone-{{ $milestone->id }}">
                            <input
                                type="checkbox"
                                wire:click="toggleMilestone({{ $milestone->id }})"
                                @checked($milestone->status === MilestoneStatus::Done)
                                class="size-[16px] rounded-full border-(--bd2) text-(--ac) focus:ring-0"
                            />
                            <span class="flex-1 text-sm {{ $milestone->status === MilestoneStatus::Done ? 'text-(--mut) line-through opacity-45' : 'text-(--tx)' }}">
                                {{ $milestone->title }}
                            </span>
                            <button type="button" wire:click="deleteMilestone({{ $milestone->id }})" class="text-(--mut) hover:text-(--dgr)">
                                <flux:icon name="x-mark" class="size-3.5" />
                            </button>
                        </div>
                    @endforeach
                </div>
                <form wire:submit="addMilestone" class="mt-2 flex gap-2 px-3">
                    <input
                        type="text"
                        wire:model="newMilestoneTitle"
                        placeholder="Ajouter un jalon…"
                        class="flex-1 rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-1.5 text-sm text-(--tx)"
                    />
                    <x-btn variant="secondary" type="submit" class="!px-3 !py-1.5 text-xs">Ajouter</x-btn>
                </form>
            </div>

            <div>
                <h2 class="text-base font-semibold text-(--tx)">Tâches liées</h2>
                <div class="mt-3 divide-y divide-(--bd) rounded-[14px] border border-(--bd) bg-(--surf)">
                    @forelse ($this->tasks as $task)
                        <x-task-row :task="$task" wire:key="task-{{ $task->id }}" />
                    @empty
                        <p class="p-5 text-center text-sm text-(--mut)">Aucune tâche liée.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="space-y-8">
            <div>
                <h2 class="text-base font-semibold text-(--tx)">Avancement — tâches faites/semaine</h2>
                @php $max = max([1, ...$this->weeklyActivity]); @endphp
                <div class="mt-3 flex h-24 items-end gap-2 rounded-[14px] border border-(--bd) bg-(--surf) p-4">
                    @foreach ($this->weeklyActivity as $count)
                        <div class="flex flex-1 flex-col items-center gap-1">
                            <div
                                class="w-full rounded-t-[3px] bg-(--ac)"
                                style="height: {{ max(4, (int) round(($count / $max) * 64)) }}px; opacity: {{ $loop->last ? 1 : 0.35 }}"
                            ></div>
                            <span class="font-mono text-[10px] text-(--mut)">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                <h2 class="text-base font-semibold text-(--tx)">Extraits de revues</h2>
                <p class="mt-3 rounded-[14px] border border-(--bd) bg-(--surf) p-5 text-center text-sm text-(--mut)">
                    Aucune revue n'a encore mentionné cet objectif.
                </p>
            </div>

            @if ($this->brainConfigured)
                <x-brain-notes :documents="$this->brainDocuments" />
            @endif
        </div>
    </div>

    <flux:modal wire:model.self="showEditModal" class="md:w-[420px]">
        <div class="space-y-5">
            <flux:heading size="lg">Modifier l'objectif</flux:heading>

            <div>
                <label class="text-xs text-(--mut)">Titre</label>
                <input type="text" wire:model="editTitle" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)" />
                @error('editTitle') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-xs text-(--mut)">Échéance</label>
                <input type="date" wire:model="editTargetDate" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)" />
            </div>

            <div>
                <label class="text-xs text-(--mut)">Description</label>
                <textarea wire:model="editDescription" rows="3" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)"></textarea>
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showEditModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="saveEdit">Enregistrer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
