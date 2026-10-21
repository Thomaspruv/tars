<?php

use App\Enums\GoalStatus;
use App\Enums\QuestionnaireRunStatus;
use App\Enums\TaskStatus;
use App\Models\Goal;
use App\Models\JournalEntry;
use App\Models\Questionnaire;
use App\Models\QuestionnaireRun;
use App\Models\Review;
use App\Models\Task;
use App\Support\Journal\JournalWriter;
use App\Support\Recurrence\RecurrenceCalculator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::reading')] #[Title('Bilan de vie')] class extends Component
{
    #[Url(as: 'run')]
    public ?int $runId = null;

    public bool $showEntryModal = false;

    public bool $entryDateHasExistingEntry = false;

    public string $entryDate = '';

    public ?int $entryMood = null;

    public bool $entryMoodCleared = false;

    public string $entryMoodLabel = '';

    public string $entrySummary = '';

    /** @var list<string> */
    public array $entryHighlights = [];

    public string $newHighlight = '';

    #[Computed]
    public function moodAverage7d(): ?float
    {
        return $this->averageMood(today()->subDays(6), today());
    }

    #[Computed]
    public function moodAveragePrior7d(): ?float
    {
        return $this->averageMood(today()->subDays(13), today()->subDays(7));
    }

    #[Computed]
    public function moodDelta(): ?float
    {
        if ($this->moodAverage7d === null || $this->moodAveragePrior7d === null) {
            return null;
        }

        return round($this->moodAverage7d - $this->moodAveragePrior7d, 1);
    }

    /**
     * @return array{filled: int, total: int}
     */
    #[Computed]
    public function regularity30d(): array
    {
        $filled = JournalEntry::whereBetween('date', [today()->subDays(29)->toDateString(), today()->toDateString()])->count();

        return ['filled' => $filled, 'total' => 30];
    }

    #[Computed]
    public function pendingRun(): ?QuestionnaireRun
    {
        return QuestionnaireRun::where('status', 'pending')->orderBy('due_date')->with('questionnaire')->first();
    }

    /**
     * A read-only preview of the next questionnaire occurrence when nothing
     * is pending yet — no run is created here, that's the scheduler's job.
     *
     * @return array{name: string, date: Carbon}|null
     */
    #[Computed]
    public function nextBilanPreview(): ?array
    {
        if ($this->pendingRun) {
            return null;
        }

        $calculator = new RecurrenceCalculator;

        return Questionnaire::where('is_active', true)->get()
            ->map(function (Questionnaire $questionnaire) use ($calculator): array {
                $lastRun = $questionnaire->runs()->orderByDesc('due_date')->first();
                $from = $lastRun !== null ? $lastRun->due_date : $questionnaire->created_at;
                $next = $calculator->nextOccurrence("{$questionnaire->frequency->value}:{$questionnaire->anchor}", $from);

                return ['name' => $questionnaire->name, 'date' => $next];
            })
            ->sortBy('date')
            ->first();
    }

    #[Computed]
    public function todayEntry(): ?JournalEntry
    {
        return JournalEntry::whereDate('date', today())->first();
    }

    /**
     * L'humeur mois par mois, sur neuf mois. C'est *ma* donnée : elle
     * s'affiche en abricot, jamais en vert.
     *
     * @return list<array{label: string, average: float|null, isCurrent: bool}>
     */
    #[Computed]
    public function monthlyMood(): array
    {
        $start = today()->startOfMonth()->subMonths(8);

        $entries = JournalEntry::whereNotNull('mood')
            ->where('date', '>=', $start->toDateString())
            ->get()
            ->groupBy(fn (JournalEntry $entry): string => $entry->date->format('Y-m'));

        $months = [];

        for ($index = 0; $index < 9; $index++) {
            $month = $start->copy()->addMonths($index);
            $moods = $entries->get($month->format('Y-m'));

            $months[] = [
                'label' => $month->translatedFormat('M'),
                'average' => $moods?->avg('mood'),
                'isCurrent' => $month->isSameMonth(today()),
            ];
        }

        return $months;
    }

    /**
     * Les bilans, du plus récent au plus ancien — c'est la colonne de gauche.
     *
     * @return Collection<int, QuestionnaireRun>
     */
    #[Computed]
    public function runHistory(): Collection
    {
        return QuestionnaireRun::with(['questionnaire', 'answers'])
            ->orderByDesc('due_date')
            ->limit(24)
            ->get();
    }

    #[Computed]
    public function selectedRun(): ?QuestionnaireRun
    {
        if ($this->runId !== null) {
            return $this->runHistory->firstWhere('id', $this->runId);
        }

        return $this->pendingRun ?? $this->runHistory->firstWhere('status', QuestionnaireRunStatus::Completed);
    }

    public function selectRun(int $runId): void
    {
        $this->runId = $runId;

        unset($this->selectedRun);
    }

    /**
     * La saison du bilan : les douze semaines qui finissent avec lui. Le doc
     * de refonte parlait de « domaines de vie » côte à côte ; ils n'existent
     * pas en base (le modèle `LifeArea` a été retiré en juillet 2026), donc la
     * ligne est un objectif, avec sa teinte. La question reste la même :
     * est-ce que j'y mets ce que je voulais y mettre ?
     */
    #[Computed]
    public function periodEnd(): CarbonInterface
    {
        return ($this->selectedRun?->due_date ?? today())->copy()->endOfWeek();
    }

    #[Computed]
    public function periodStart(): CarbonInterface
    {
        return $this->periodEnd->copy()->subWeeks(11)->startOfWeek();
    }

    #[Computed]
    public function periodLabel(): string
    {
        $start = $this->periodStart;
        $end = $this->periodEnd;

        return $start->year === $end->year
            ? $start->translatedFormat('F').' – '.$end->translatedFormat('F Y')
            : $start->translatedFormat('F Y').' – '.$end->translatedFormat('F Y');
    }

    /**
     * Une ligne par objectif qui a bougé sur la saison : douze cases (une case
     * = une semaine avec au moins une tâche cochée) et sa part du total.
     *
     * @return \Illuminate\Support\Collection<int, array{goal: Goal, weeks: list<bool>, share: int, done: int}>
     */
    #[Computed]
    public function goalSeason(): \Illuminate\Support\Collection
    {
        $completed = Task::withArchived()
            ->where('status', TaskStatus::Done)
            ->whereNotNull('goal_id')
            ->whereBetween('completed_at', [$this->periodStart, $this->periodEnd])
            ->get(['goal_id', 'completed_at']);

        if ($completed->isEmpty()) {
            return collect();
        }

        $total = $completed->count();
        $byGoal = $completed->groupBy('goal_id');

        return Goal::whereIn('id', $byGoal->keys())
            ->get()
            ->map(function (Goal $goal) use ($byGoal, $total): array {
                $tasks = $byGoal[$goal->id];

                $weeks = [];

                for ($index = 0; $index < 12; $index++) {
                    $weekStart = $this->periodStart->copy()->addWeeks($index);
                    $weekEnd = $weekStart->copy()->endOfWeek();

                    $weeks[] = $tasks->contains(fn ($task): bool => $task->completed_at->between($weekStart, $weekEnd));
                }

                return [
                    'goal' => $goal,
                    'weeks' => $weeks,
                    'done' => $tasks->count(),
                    'share' => (int) round($tasks->count() / $total * 100),
                ];
            })
            ->sortByDesc('done')
            ->values();
    }

    /**
     * Les chiffres de la saison, en une ligne — douze semaines, ce qui a été
     * coché, les revues tenues, les objectifs atteints.
     */
    #[Computed]
    public function seasonStats(): string
    {
        $done = Task::withArchived()
            ->where('status', TaskStatus::Done)
            ->whereBetween('completed_at', [$this->periodStart, $this->periodEnd])
            ->count();

        $reviews = Review::whereNotNull('completed_at')
            ->whereBetween('period_end', [$this->periodStart, $this->periodEnd])
            ->count();

        $reached = Goal::where('status', GoalStatus::Done)
            ->whereBetween('updated_at', [$this->periodStart, $this->periodEnd])
            ->count();

        $parts = [
            '12 semaines',
            $done.' '.Str::plural('tâche', $done).' '.Str::plural('cochée', $done),
            $reviews.' '.Str::plural('revue', $reviews).' sur 12',
        ];

        if ($reached > 0) {
            $parts[] = $reached.' '.Str::plural('objectif', $reached).' '.Str::plural('atteint', $reached);
        }

        return implode(' · ', $parts);
    }

    #[Computed]
    public function timeline(): Collection
    {
        return JournalEntry::orderByDesc('date')->limit(200)->get();
    }

    #[Computed]
    public function questionnaires(): Collection
    {
        return Questionnaire::withCount('runs')->orderByDesc('is_active')->orderBy('name')->get();
    }

    public function openEntryModal(?string $date = null): void
    {
        $entry = $date ? JournalEntry::whereDate('date', $date)->first() : null;

        $this->entryDate = $date ?? today()->toDateString();
        $this->entryDateHasExistingEntry = $entry !== null;
        $this->entryMood = $entry?->mood;
        $this->entryMoodCleared = false;
        $this->entryMoodLabel = (string) $entry?->mood_label;
        $this->entrySummary = '';
        $this->entryHighlights = [];
        $this->newHighlight = '';
        $this->showEntryModal = true;
    }

    public function setMood(int $mood): void
    {
        if ($this->entryMood === $mood) {
            $this->entryMood = null;
            $this->entryMoodCleared = true;
        } else {
            $this->entryMood = $mood;
            $this->entryMoodCleared = false;
        }
    }

    public function addHighlight(): void
    {
        $value = trim($this->newHighlight);

        if ($value === '') {
            return;
        }

        $this->entryHighlights[] = $value;
        $this->newHighlight = '';
    }

    public function removeHighlight(int $index): void
    {
        unset($this->entryHighlights[$index]);
        $this->entryHighlights = array_values($this->entryHighlights);
    }

    public function saveEntry(JournalWriter $writer): void
    {
        $validated = $this->validate([
            'entryDate' => ['required', 'date', 'before_or_equal:today'],
            'entrySummary' => ['required', 'string'],
            'entryMood' => ['nullable', 'integer', 'between:1,10'],
            'entryMoodLabel' => ['nullable', 'string'],
        ]);

        $writer->write(
            summary: $validated['entrySummary'],
            mood: $validated['entryMood'],
            moodLabel: $validated['entryMoodLabel'] !== '' ? $validated['entryMoodLabel'] : null,
            highlights: $this->entryHighlights,
            date: Carbon::parse($validated['entryDate']),
            source: 'manual',
            clearMood: $this->entryMoodCleared,
        );

        $this->showEntryModal = false;

        unset(
            $this->timeline, $this->todayEntry,
            $this->regularity30d, $this->moodAverage7d, $this->moodAveragePrior7d, $this->moodDelta,
            $this->monthlyMood,
        );
    }

    public function toggleQuestionnaire(int $questionnaireId): void
    {
        $questionnaire = Questionnaire::findOrFail($questionnaireId);
        $questionnaire->update(['is_active' => ! $questionnaire->is_active]);

        unset($this->questionnaires, $this->nextBilanPreview);
    }

    public function createQuestionnaire(): void
    {
        $questionnaire = Questionnaire::create([
            'name' => 'Nouveau questionnaire',
            'frequency' => 'monthly',
            'anchor' => '1',
            'questions' => [],
            'is_active' => true,
        ]);

        $this->redirect(route('bilan.questionnaire.edit', $questionnaire), navigate: true);
    }

    private function averageMood(CarbonInterface $start, CarbonInterface $end): ?float
    {
        $moods = JournalEntry::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('mood')
            ->pluck('mood');

        return $moods->isNotEmpty() ? round((float) $moods->avg(), 1) : null;
    }
};
?>

{{-- Même gabarit de lecture que la Revue. Différence de fond : le journal est
     *mon* texte, il ne porte donc aucun marqueur vert — seules les questions
     posées par l'agent en portent. La courbe d'humeur est en abricot, pas en
     vert : c'est ma donnée, pas la sienne. --}}
<div>
<div class="flex min-h-screen flex-col">
    {{-- Comme la Revue : la barre du haut remplace la sidebar de l'app. Ce
         qu'on quitte à gauche, la saison qu'on lit au milieu, de quoi changer
         de bilan à droite. --}}
    <div class="sticky top-0 z-10 flex flex-wrap items-center gap-4 border-b border-(--bd) bg-(--bg) px-5 py-3 lg:px-8">
        <a href="{{ route('today') }}" wire:navigate class="press shrink-0 rounded-full px-3 py-1.5 text-[14px] text-(--mut) hover:text-(--tx)">← Quitter le bilan</a>

        <p class="order-last w-full text-[11px] tracking-[.1em] text-(--mut) uppercase lg:order-none lg:w-auto lg:flex-1 lg:text-center">
            Bilan de vie · {{ $this->periodLabel }}
        </p>

        <div class="ml-auto flex shrink-0 items-center gap-2">
            @if ($this->runHistory->isNotEmpty())
                <flux:dropdown position="bottom" align="end">
                    <button type="button" class="press rounded-full bg-(--surf2) px-3 py-1.5 text-[13px] text-(--tx) hover:bg-(--bd2)">Autre bilan</button>

                    <flux:menu>
                        @foreach ($this->runHistory as $run)
                            <flux:menu.item as="button" wire:click="selectRun({{ $run->id }})">
                                {{ ucfirst($run->due_date->translatedFormat('F Y')) }}
                                @if ($run->status === \App\Enums\QuestionnaireRunStatus::Pending) · à remplir @endif
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            @endif

            <flux:dropdown position="bottom" align="end">
                <button type="button" class="press rounded-full bg-(--surf2) px-3 py-1.5 text-[13px] text-(--tx) hover:bg-(--bd2)">Questionnaires</button>

                <flux:menu>
                    @foreach ($this->questionnaires as $questionnaire)
                        <flux:menu.item
                            :href="route('bilan.questionnaire.edit', $questionnaire)"
                            wire:navigate
                            icon="{{ $questionnaire->is_active ? 'check-circle' : 'minus-circle' }}"
                        >{{ $questionnaire->name }}</flux:menu.item>
                    @endforeach

                    <flux:menu.separator />

                    <flux:menu.item as="button" wire:click="createQuestionnaire" icon="plus">Nouveau questionnaire</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="flex min-w-0 flex-1 justify-center px-5 pt-6 pb-16 lg:px-10 lg:pt-10">
        <div class="w-full max-w-[680px]">
            <p class="mb-3 text-[12px] tracking-[.12em] text-(--mut) uppercase">
                @if ($this->selectedRun)
                    {{ $this->selectedRun->questionnaire->name }} · {{ count($this->selectedRun->questionnaire->questions) }} questions
                @else
                    Journal &amp; humeur
                @endif
            </p>

            <h1 class="font-display mb-2.5 text-[42px] leading-[1.1] font-bold tracking-[-.03em] text-(--tx)">
                {{ ucfirst(($this->selectedRun?->due_date ?? today())->translatedFormat('F Y')) }}
            </h1>

            <p class="mb-[30px] text-[15px] leading-[1.6] text-(--mut)">{{ $this->seasonStats }}</p>

            {{-- La saison, objectif par objectif : une case = une semaine avec
                 au moins une tâche cochée. C'est le seul endroit de l'app où
                 toutes les teintes sont ensemble, et c'est voulu — c'est la
                 seule question du bilan : est-ce que j'y mets ce que je
                 voulais y mettre ? --}}
            @if ($this->goalSeason->isNotEmpty())
                <x-card padding="18" class="mb-8">
                    <div class="flex flex-col gap-2.5">
                        @foreach ($this->goalSeason as $row)
                            <div class="flex items-center gap-3.5" wire:key="season-{{ $row['goal']->id }}">
                                <span class="flex w-[120px] shrink-0 items-center gap-2 text-[13px] font-semibold text-(--tx)">
                                    <span class="size-[8px] shrink-0 rounded-[2px]" style="background: var(--goal-{{ $row['goal']->color_slot }})" aria-hidden="true"></span>
                                    <span class="truncate">{{ $row['goal']->title }}</span>
                                </span>

                                <span class="flex flex-1 gap-[3px]">
                                    @foreach ($row['weeks'] as $active)
                                        <span
                                            class="h-[14px] flex-1 rounded-[3px] {{ $active ? '' : 'bg-(--bd2)' }}"
                                            @if ($active) style="background: var(--goal-{{ $row['goal']->color_slot }})" @endif
                                            aria-hidden="true"
                                        ></span>
                                    @endforeach
                                </span>

                                <span class="w-[42px] shrink-0 text-right text-[13px] font-semibold tabular-nums text-(--tx)">{{ $row['share'] }} %</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3 flex flex-wrap items-baseline justify-between gap-2 text-[12px] text-(--mut)">
                        <span>{{ $this->periodStart->translatedFormat('F') }}</span>
                        <span>une case = une semaine avec au moins une tâche cochée</span>
                        <span>{{ $this->periodEnd->translatedFormat('F') }}</span>
                    </div>
                </x-card>
            @else
                <x-card padding="18" class="mb-8">
                    <p class="text-[15px] leading-[1.6] text-(--mut)">
                        Aucune tâche rattachée à un objectif n'a été cochée sur ces douze semaines : il n'y a
                        rien à répartir.
                    </p>
                </x-card>
            @endif

            {{-- L'humeur du jour : dix pavés, un seul allumé, en abricot. --}}
            <div class="mb-4 flex items-baseline gap-3">
                <h2 class="font-display text-[22px] font-semibold text-(--tx)">Humeur du jour</h2>
                <span class="text-[15px] text-(--mut)">de 1 à 10</span>
                @if ($this->moodAverage7d !== null)
                    <span class="ml-auto text-[13px] text-(--mut)">
                        moyenne sur 7 jours : {{ $this->moodAverage7d }}@if ($this->moodDelta !== null) ({{ $this->moodDelta >= 0 ? '+' : '' }}{{ $this->moodDelta }}) @endif
                    </span>
                @endif
            </div>

            @php $todayMood = $this->todayEntry?->mood; @endphp
            <div class="mb-4 flex gap-[7px]">
                @for ($i = 1; $i <= 10; $i++)
                    <button
                        type="button"
                        wire:click="openEntryModal"
                        wire:key="mood-{{ $i }}"
                        @class([
                            'press flex h-[44px] flex-1 items-center justify-center rounded-[10px] text-[14px]',
                            'font-display bg-(--ac) text-[16px] font-bold text-(--acT)' => $todayMood === $i,
                            'bg-(--surf) text-(--mut)' => $todayMood !== $i,
                        ])
                    >{{ $i }}</button>
                @endfor
            </div>

            <x-card padding="18" class="mb-8">
                <div class="mb-2.5 flex h-[70px] items-end gap-2">
                    @php $maxMood = 10; @endphp
                    @foreach ($this->monthlyMood as $month)
                        <div
                            class="flex-1 rounded-t-[4px] transition-[height,background-color] duration-(--dur-enter) ease-(--ease) {{ $month['isCurrent'] ? 'bg-(--ac)' : 'bg-(--bd2)' }}"
                            style="height: {{ $month['average'] !== null ? max(8, (int) round(($month['average'] / $maxMood) * 100)) : 4 }}%"
                            aria-hidden="true"
                        ></div>
                    @endforeach
                </div>
                <div class="flex justify-between text-[12px] text-(--mut)">
                    @foreach ($this->monthlyMood as $month)
                        <span wire:key="month-{{ $loop->index }}">{{ $month['label'] }}</span>
                    @endforeach
                </div>
            </x-card>

            {{-- Les questions du mois portent le vert : c'est l'agent qui les
                 pose. Les réponses, elles, sont les miennes. --}}
            @if ($this->selectedRun)
                <div class="mb-[18px] flex flex-wrap items-baseline gap-3">
                    <h2 class="font-display text-[22px] font-semibold text-(--tx)">Les questions du mois</h2>
                    @if ($this->selectedRun->status === \App\Enums\QuestionnaireRunStatus::Pending)
                        <x-btn variant="agent" class="ml-auto" :href="route('bilan.run.fill', $this->selectedRun)" wire:navigate>Remplir ce bilan</x-btn>
                    @endif
                </div>

                <div class="mb-[34px] flex flex-col gap-[22px]">
                    @foreach ($this->selectedRun->questionnaire->questions as $question)
                        @php
                            $label = is_array($question) ? ($question['text'] ?? '') : (string) $question;
                            $answer = $this->selectedRun->answers->firstWhere('question_text', $label);
                        @endphp
                        <div wire:key="question-{{ $loop->index }}">
                            <div class="mb-2.5 flex items-center gap-2.5">
                                <span class="size-[7px] rounded-full bg-(--ai)" aria-hidden="true"></span>
                                <span class="text-[13px] text-(--ai)">Question d'Hermès</span>
                            </div>
                            <p class="mb-3 text-[17px] leading-[1.6] text-(--tx)">{{ $label }}</p>
                            <x-card padding="16" class="!rounded-[14px]">
                                <p class="reading-body {{ $answer ? 'text-(--tx)' : 'text-(--mut)' }}">
                                    @if ($answer)
                                        {{ $answer->answer_numeric !== null ? $answer->answer_numeric.' / 10' : $answer->answer_text }}
                                    @else
                                        Pas encore répondu.
                                    @endif
                                </p>
                            </x-card>
                        </div>
                    @endforeach
                </div>
            @elseif ($this->nextBilanPreview)
                <x-card padding="18" class="mb-[34px]">
                    <p class="text-[15px] text-(--mut)">
                        Prochain bilan : <span class="text-(--tx)">{{ $this->nextBilanPreview['name'] }}</span>,
                        le {{ $this->nextBilanPreview['date']->translatedFormat('j F Y') }}.
                    </p>
                </x-card>
            @endif

            <div class="mb-[30px] h-px bg-(--bd)"></div>

            <div class="mb-1.5 flex flex-wrap items-baseline gap-3">
                <h2 class="font-display text-[22px] font-semibold text-(--tx)">Journal</h2>
                <span class="text-[14px] text-(--mut)">mon texte, jamais touché par un agent</span>
            </div>

            <p class="mb-5 text-[15px] text-(--mut)">
                @if ($this->timeline->isEmpty())
                    Aucune entrée pour l'instant.
                @else
                    {{ $this->timeline->count() }} {{ Str::plural('entrée', $this->timeline->count()) }} ·
                    dernière {{ $this->timeline->first()->date->diffForHumans() }}
                @endif
            </p>

            <div class="mb-[30px] flex flex-col gap-4">
                @forelse ($this->timeline->take(20) as $entry)
                    <button
                        type="button"
                        wire:click="openEntryModal('{{ $entry->date->toDateString() }}')"
                        wire:key="entry-{{ $entry->id }}"
                        class="press-row -mx-3 rounded-[14px] px-3 py-2 text-left"
                    >
                        <p class="mb-2 flex items-center gap-2.5 text-[13px] text-(--mut)">
                            {{ ucfirst($entry->date->translatedFormat('l j F')) }}
                            @if ($entry->mood !== null)
                                <span class="rounded-full bg-(--surf) px-2.5 py-0.5 text-[12px] text-(--tx)">{{ $entry->mood }}</span>
                            @endif
                            @if ($entry->source === 'hermes')
                                <span class="text-[12px] text-(--ai)">noté avec Hermès</span>
                            @endif
                        </p>
                        <p class="reading-body text-(--tx)">{{ $entry->summary }}</p>
                    </button>
                @empty
                    <p class="reading-body text-(--mut)">Ton journal est vide. Ce soir, raconte ta journée à Hermes — c'est tout.</p>
                @endforelse
            </div>

            <x-btn variant="primary" size="lg" wire:click="openEntryModal">
                {{ $this->todayEntry ? "Compléter l'entrée du jour" : "Écrire l'entrée du jour" }}
            </x-btn>
        </div>
    </div>

    {{-- Un bilan ne se termine pas par un bouton « enregistrer » : il se
         termine par la saison suivante. --}}
    <div class="flex items-center justify-center border-t border-(--bd) px-5 py-4">
        <x-btn variant="primary" size="md" :href="route('goals.index')" wire:navigate>Poser les objectifs de la saison qui vient →</x-btn>
    </div>
</div>

    <flux:modal wire:model.self="showEntryModal" class="md:w-[480px]">
        <div class="space-y-5">
            <flux:heading size="lg">{{ $entryDateHasExistingEntry ? 'Ajouter à cette journée' : 'Nouvelle entrée' }}</flux:heading>

            <div>
                <label class="text-[12px] text-(--mut)">Date</label>
                <input
                    type="date"
                    wire:model="entryDate"
                    max="{{ today()->toDateString() }}"
                    @disabled($entryDateHasExistingEntry)
                    class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none disabled:opacity-50"
                />
                @error('entryDate') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Mood</label>
                <div class="mt-1.5 flex flex-wrap gap-1">
                    @for ($i = 1; $i <= 10; $i++)
                        <button
                            type="button"
                            wire:click="setMood({{ $i }})"
                            class="flex size-7 items-center justify-center rounded-[6px] font-mono text-[11px] {{ $entryMood === $i ? ($i <= 3 ? 'bg-(--dgrbg) text-(--dgr)' : ($i <= 6 ? 'bg-(--surf2) text-(--tx)' : 'bg-(--okbg) text-(--ok)')) : 'bg-(--surf2) text-(--mut) hover:text-(--tx)' }}"
                        >
                            {{ $i }}
                        </button>
                    @endfor
                </div>
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Libellé</label>
                <input type="text" wire:model="entryMoodLabel" placeholder="calme, dense, fatigué…" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none" />
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Résumé</label>
                <textarea wire:model="entrySummary" rows="4" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none"></textarea>
                @error('entrySummary') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Moments</label>
                <div class="mt-1.5 space-y-1">
                    @foreach ($entryHighlights as $index => $highlight)
                        <div class="flex items-center gap-2 text-sm text-(--tx)" wire:key="highlight-{{ $index }}">
                            <span class="flex-1">{{ $highlight }}</span>
                            <button type="button" wire:click="removeHighlight({{ $index }})" class="text-(--mut) hover:text-(--dgr)">✕</button>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1.5 flex gap-2">
                    <input type="text" wire:model="newHighlight" wire:keydown.enter.prevent="addHighlight" placeholder="Un moment marquant…" class="flex-1 rounded-full bg-(--surf2) px-4 py-2 text-[14px] text-(--tx) focus:outline-none" />
                    <x-btn variant="secondary" size="sm" wire:click="addHighlight">Ajouter</x-btn>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showEntryModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="saveEntry">Enregistrer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
