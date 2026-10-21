<?php

use App\Enums\DecisionSource;
use App\Enums\MilestoneStatus;
use App\Enums\ReviewType;
use App\Enums\TaskStatus;
use App\Models\Decision;
use App\Models\Entity;
use App\Models\Event;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Review;
use App\Models\Task;
use App\Support\FuzzyMatcher;
use Illuminate\Database\Eloquent\Collection;
use League\CommonMark\CommonMarkConverter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::reading')] #[Title('Revue')] class extends Component
{
    /**
     * Trois étapes, et dans cet ordre : les chiffres d'abord (ils ne se
     * discutent pas), ce qu'Hermès en dit ensuite, ce que j'en retiens à la
     * fin. Le doc de refonte nommait la troisième « choisir la semaine qui
     * vient » : rien en base ne porte un choix de semaine, et on ne fabrique
     * pas un écran pour une donnée qui n'existe pas — la troisième étape est
     * donc ma parole et la clôture de la revue.
     */
    public const array STEPS = [
        1 => 'La semaine en chiffres',
        2 => 'Ce qu\'Hermès en dit',
        3 => 'Ce que j\'en retiens',
    ];

    #[Url(as: 'review')]
    public ?int $reviewId = null;

    #[Url]
    public int $step = 1;

    public string $userNotes = '';

    public function mount(): void
    {
        $this->userNotes = $this->currentReview?->user_notes ?? '';
        $this->step = max(1, min(3, $this->step));
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function steps(): array
    {
        return self::STEPS;
    }

    #[Computed]
    public function currentReview(): ?Review
    {
        return $this->reviewId
            ? Review::find($this->reviewId)
            : Review::orderByDesc('period_end')->orderByDesc('id')->first();
    }

    /**
     * @return Collection<int, Review>
     */
    #[Computed]
    public function reviewHistory(): Collection
    {
        return Review::orderByDesc('period_end')->orderByDesc('id')->limit(20)->get();
    }

    /**
     * Le titre d'une revue est sa période dite en français : « Semaine 35 »
     * ou « Août 2026 ». Personne ne cherche une revue par sa date de début.
     */
    public function reviewLabel(Review $review): string
    {
        return $review->type === ReviewType::Monthly
            ? ucfirst($review->period_end->translatedFormat('F Y'))
            : 'Semaine '.$review->period_end->isoWeek();
    }

    /**
     * La semaine en chiffres — tout se compte sur la période de la revue, et
     * rien ici ne vient de l'agent : ce sont mes données, lues telles quelles.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function figures(): array
    {
        $review = $this->currentReview;

        if (! $review) {
            return [];
        }

        $from = $review->period_start->copy()->startOfDay();
        $to = $review->period_end->copy()->endOfDay();

        $done = Task::withArchived()
            ->where('status', TaskStatus::Done)
            ->whereBetween('completed_at', [$from, $to])
            ->count();

        $events = Event::whereBetween('starts_at', [$from, $to])->count();

        $milestones = Milestone::where('status', MilestoneStatus::Done->value)
            ->whereBetween('updated_at', [$from, $to])
            ->count();

        return [
            ['value' => (string) $done, 'label' => Str::plural('tâche', $done).' '.Str::plural('cochée', $done)],
            ['value' => (string) $this->clearedDays, 'label' => 'journée'.($this->clearedDays === 1 ? '' : 's').' vidée'.($this->clearedDays === 1 ? '' : 's')],
            ['value' => (string) $events, 'label' => Str::plural('événement', $events)],
            ['value' => (string) $milestones, 'label' => Str::plural('jalon', $milestones).' '.Str::plural('atteint', $milestones)],
        ];
    }

    /**
     * Le nombre de jours de la période où tout ce qui était posé a été fait.
     * Une journée sans rien de posé ne compte pas : on ne vide pas une journée
     * vide.
     */
    #[Computed]
    public function clearedDays(): int
    {
        $review = $this->currentReview;

        if (! $review) {
            return 0;
        }

        $tasks = Task::withArchived()
            ->whereBetween('scheduled_date', [$review->period_start, $review->period_end])
            ->get(['scheduled_date', 'status']);

        return $tasks
            ->groupBy(fn (Task $task): string => $task->scheduled_date->toDateString())
            ->filter(fn (Collection|\Illuminate\Support\Collection $day): bool => $day->every(fn (Task $task): bool => $task->status === TaskStatus::Done))
            ->count();
    }

    /**
     * Les objectifs qui ont bougé pendant la période, avec leur teinte — c'est
     * la seule image de l'étape 1 : où est passée la semaine.
     *
     * @return \Illuminate\Support\Collection<int, array{goal: Goal, done: int}>
     */
    #[Computed]
    public function goalsTouched(): \Illuminate\Support\Collection
    {
        $review = $this->currentReview;

        if (! $review) {
            return collect();
        }

        $counts = Task::withArchived()
            ->where('status', TaskStatus::Done)
            ->whereNotNull('goal_id')
            ->whereBetween('completed_at', [$review->period_start->copy()->startOfDay(), $review->period_end->copy()->endOfDay()])
            ->selectRaw('goal_id, count(*) as total')
            ->groupBy('goal_id')
            ->pluck('total', 'goal_id');

        return Goal::whereIn('id', $counts->keys())
            ->get()
            ->map(fn (Goal $goal): array => ['goal' => $goal, 'done' => (int) $counts[$goal->id]])
            ->sortByDesc('done')
            ->values();
    }

    /**
     * @return array{total: int, since: mixed}
     */
    #[Computed]
    public function decisionJournal(): array
    {
        $total = Decision::count();

        return [
            'total' => $total,
            'since' => $total > 0 ? Decision::min('decided_at') : null,
        ];
    }

    #[Computed]
    public function answeredCount(): int
    {
        return collect($this->currentReview?->proposed_decisions ?? [])
            ->filter(fn (array $decision): bool => ($decision['response'] ?? null) !== null)
            ->count();
    }

    #[Computed]
    public function renderedMarkdown(): string
    {
        if (! $this->currentReview) {
            return '';
        }

        return (new CommonMarkConverter)->convertToHtml((string) $this->currentReview->generated_content)->getContent();
    }

    public function goToStep(int $step): void
    {
        $this->step = max(1, min(3, $step));
    }

    public function selectReview(int $reviewId): void
    {
        $this->reviewId = $reviewId;
        $this->userNotes = Review::find($reviewId)?->user_notes ?? '';
        $this->step = 1;

        unset($this->currentReview, $this->renderedMarkdown, $this->figures, $this->clearedDays, $this->goalsTouched, $this->answeredCount);
    }

    public function answerDecision(int $index, string $response): void
    {
        $review = $this->currentReview;

        if (! $review || ! in_array($response, ['oui', 'non', 'plus_tard'], true)) {
            return;
        }

        $decisions = $review->proposed_decisions ?? [];

        if (! isset($decisions[$index]) || $decisions[$index]['response'] !== null) {
            return;
        }

        $item = $decisions[$index];
        $labels = ['oui' => 'Oui', 'non' => 'Non', 'plus_tard' => 'Reporté'];

        $matcher = app(FuzzyMatcher::class);

        Decision::create([
            'content' => $item['question'],
            'context' => 'Réponse : '.$labels[$response],
            'source' => DecisionSource::Review,
            'review_id' => $review->id,
            'goal_id' => $item['goal'] ? $matcher->bestMatch(Goal::all(), $item['goal'], fn (Goal $g): string => $g->title)?->id : null,
            'entity_id' => $item['entity'] ? $matcher->bestMatch(Entity::all(), $item['entity'], fn (Entity $e): string => $e->name)?->id : null,
            'decided_at' => now(),
        ]);

        $decisions[$index]['response'] = $response;
        $decisions[$index]['answered_at'] = now()->toIso8601String();
        $review->update(['proposed_decisions' => $decisions]);

        unset($this->currentReview, $this->answeredCount, $this->decisionJournal);
    }

    public function updatedUserNotes(string $value): void
    {
        $this->currentReview?->update(['user_notes' => $value]);
    }

    public function markDone(): void
    {
        $this->currentReview?->update(['completed_at' => now()]);

        unset($this->currentReview, $this->reviewHistory);
    }
};
?>

{{-- Le seul écran, avec le Bilan, qui n'a pas la sidebar de l'app : elle est
     remplacée par une barre d'étape, et le contenu passe en colonne de lecture
     de 680. Tout l'écran est une parole d'agent — il n'a donc pas besoin de
     filet vert, c'est le document entier qui l'est. --}}
<div class="flex min-h-screen flex-col">
    @if (! $this->currentReview)
        <div class="flex flex-1 justify-center px-5 pt-10 pb-16 lg:px-10">
            <div class="w-full max-w-[680px]">
                <h1 class="font-display mb-3 text-[42px] leading-[1.1] font-bold tracking-[-.03em] text-(--tx)">Aucune revue</h1>
                <p class="reading-body text-(--mut)">
                    L'agent qui les écrit s'active sur l'écran Agents. Une fois configuré, il passe le dimanche
                    et la revue apparaît ici.
                </p>
                <x-btn variant="secondary" size="md" class="mt-6" :href="route('agents.index')" wire:navigate>Aller aux Agents</x-btn>
            </div>
        </div>
    @else
        {{-- La barre du haut remplace la sidebar : la sortie à gauche, ce qu'on
             lit au milieu, où l'on en est à droite. --}}
        <div class="sticky top-0 z-10 flex flex-wrap items-center gap-4 border-b border-(--bd) bg-(--bg) px-5 py-3 lg:px-8">
            <a href="{{ route('today') }}" wire:navigate class="press shrink-0 rounded-full px-3 py-1.5 text-[14px] text-(--mut) hover:text-(--tx)">← Quitter la revue</a>

            <p class="order-last w-full text-[11px] tracking-[.1em] text-(--mut) uppercase lg:order-none lg:w-auto lg:flex-1 lg:text-center">
                Revue · {{ mb_strtolower($this->reviewLabel($this->currentReview)) }} ·
                {{ $this->currentReview->period_end->translatedFormat('l j F') }}
            </p>

            <div class="ml-auto flex shrink-0 items-center gap-3">
                <span class="text-[13px] font-semibold text-(--mut)">Étape {{ $this->step }} / 3</span>
                <div class="flex gap-1" aria-hidden="true">
                    @foreach ($this->steps as $number => $label)
                        <span class="h-[4px] w-[24px] rounded-full {{ $number <= $this->step ? 'bg-(--tx)' : 'bg-(--bd2)' }}"></span>
                    @endforeach
                </div>

                @if ($this->reviewHistory->count() > 1)
                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="press rounded-full bg-(--surf2) px-3 py-1.5 text-[13px] text-(--tx) hover:bg-(--bd2)">
                            Autre revue
                        </button>

                        <flux:menu>
                            @foreach ($this->reviewHistory as $review)
                                <flux:menu.item as="button" wire:click="selectReview({{ $review->id }})">
                                    {{ $this->reviewLabel($review) }}
                                    @if (! $review->completed_at) · non lue @endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        </div>

        <div class="flex flex-1 justify-center px-5 pt-8 pb-10 lg:px-10">
            <div class="w-full max-w-[680px]">
                @if ($this->step === 1)
                    <h1 class="font-display mb-2 text-[42px] leading-[1.1] font-bold tracking-[-.03em] text-(--tx)">
                        {{ $this->reviewLabel($this->currentReview) }}
                    </h1>
                    <p class="reading-body mb-8 text-(--mut)">
                        Du {{ $this->currentReview->period_start->translatedFormat('j') }}
                        au {{ $this->currentReview->period_end->translatedFormat('j F') }} · les chiffres d'abord,
                        ils ne se discutent pas.
                    </p>

                    <div class="mb-8 grid grid-cols-2 gap-3.5 sm:grid-cols-4">
                        @foreach ($this->figures as $figure)
                            <x-card padding="16" wire:key="figure-{{ $loop->index }}">
                                <p class="font-display text-[32px] leading-none font-bold tabular-nums text-(--tx)">{{ $figure['value'] }}</p>
                                <p class="mt-1.5 text-[13px] leading-[1.35] text-(--mut)">{{ $figure['label'] }}</p>
                            </x-card>
                        @endforeach
                    </div>

                    @if ($this->goalsTouched->isNotEmpty())
                        <h2 class="font-display mb-3.5 text-[22px] font-semibold text-(--tx)">Où la semaine est passée</h2>

                        @php $max = max(1, $this->goalsTouched->max('done')); @endphp
                        <div class="mb-6 flex flex-col gap-2.5">
                            @foreach ($this->goalsTouched as $row)
                                <div class="flex items-center gap-3" wire:key="touched-{{ $row['goal']->id }}">
                                    <span class="w-[160px] shrink-0 truncate text-[14px] text-(--tx)">{{ $row['goal']->title }}</span>
                                    <span class="h-[14px] min-w-[6px] rounded-[3px]" style="width: {{ round($row['done'] / $max * 100) }}%; background: var(--goal-{{ $row['goal']->color_slot }})"></span>
                                    <span class="shrink-0 text-[13px] tabular-nums text-(--mut)">{{ $row['done'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="reading-body mb-6 text-(--mut)">Aucune tâche rattachée à un objectif n'a été cochée sur la période.</p>
                    @endif
                @elseif ($this->step === 2)
                    <div class="mb-4 flex items-center gap-3">
                        <span class="size-[9px] rounded-full bg-(--ai)" aria-hidden="true"></span>
                        <span class="text-[13px] text-(--ai)">
                            Générée par Hermès le {{ $this->currentReview->created_at->translatedFormat('l j F à H:i') }}
                        </span>
                    </div>

                    <div class="brain-content reading-body mb-8">
                        {!! $this->renderedMarkdown !!}
                    </div>

                    @if (! empty($this->currentReview->proposed_decisions))
                        @php $decisions = $this->currentReview->proposed_decisions; @endphp

                        <div class="mb-[30px] h-px bg-(--bd)"></div>

                        <h2 class="font-display mb-1.5 text-[22px] font-semibold text-(--tx)">
                            {{ count($decisions) }} {{ Str::plural('décision', count($decisions)) }}
                        </h2>
                        <p class="mb-[18px] text-[15px] text-(--mut)">
                            Réponse tracée dans le journal des décisions. « Plus tard » les reporte à la revue suivante.
                        </p>

                        <div class="flex flex-col gap-3">
                            @foreach ($decisions as $index => $decision)
                                <x-card-decision
                                    :decision="$decision"
                                    :index="$index"
                                    :total="count($decisions)"
                                    wire:key="decision-{{ $index }}"
                                />
                            @endforeach
                        </div>
                    @endif
                @else
                    {{-- Mes notes ne portent aucun marqueur vert : c'est mon texte,
                         pas celui de l'agent. --}}
                    <h2 class="font-display mb-3 text-[22px] font-semibold text-(--tx)">Ce que j'en retiens</h2>
                    <p class="mb-4 text-[15px] leading-[1.6] text-(--mut)">
                        Une phrase suffit. Elle est relue au bilan, avec celles des autres semaines.
                    </p>

                    <textarea
                        wire:model.blur="userNotes"
                        rows="5"
                        placeholder="Écrire ici… ce que la semaine a vraiment donné, ce qui n'apparaît pas dans les tâches."
                        class="reading-body mb-[26px] w-full rounded-[18px] bg-(--surf) px-[22px] py-5 text-(--tx) placeholder:text-(--mut) focus:outline-none"
                    ></textarea>

                    @if ($this->currentReview->completed_at)
                        <p class="text-[15px] text-(--ai)">
                            ✓ Revue faite le {{ $this->currentReview->completed_at->translatedFormat('j F à H:i') }}
                        </p>
                    @else
                        <x-btn variant="primary" size="lg" wire:click="markDone">Marquer la revue comme faite</x-btn>
                    @endif

                    <p class="mt-8 text-[13px] leading-[1.6] text-(--mut)">
                        @if ($this->decisionJournal['total'] > 0)
                            {{ $this->decisionJournal['total'] }} {{ Str::plural('décision', $this->decisionJournal['total']) }}
                            {{ Str::plural('tracée', $this->decisionJournal['total']) }}
                            @if ($this->decisionJournal['since'])
                                depuis {{ \Illuminate\Support\Carbon::parse($this->decisionJournal['since'])->translatedFormat('F Y') }}
                            @endif
                        @else
                            Aucune décision tracée pour l'instant.
                        @endif
                    </p>
                @endif
            </div>
        </div>

        {{-- La barre du bas nomme l'étape d'avant et celle d'après : une revue
             se lit dans l'ordre, et on doit pouvoir revenir sans se demander
             où l'on va. --}}
        <div class="flex items-center justify-center gap-3 border-t border-(--bd) px-5 py-4">
            @if ($this->step > 1)
                <x-btn variant="ghost" size="md" wire:click="goToStep({{ $this->step - 1 }})">← {{ $this->steps[$this->step - 1] }}</x-btn>
            @endif

            @if ($this->step < 3)
                <x-btn variant="primary" size="md" wire:click="goToStep({{ $this->step + 1 }})">{{ $this->steps[$this->step + 1] }} →</x-btn>
            @endif
        </div>
    @endif
</div>
