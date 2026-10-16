<?php

use App\Enums\DecisionSource;
use App\Models\Decision;
use App\Models\Entity;
use App\Models\Goal;
use App\Enums\ReviewType;
use App\Models\Review;
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
    #[Url(as: 'review')]
    public ?int $reviewId = null;

    public string $userNotes = '';

    public function mount(): void
    {
        $this->userNotes = $this->currentReview?->user_notes ?? '';
    }

    #[Computed]
    public function currentReview(): ?Review
    {
        return $this->reviewId
            ? Review::find($this->reviewId)
            : Review::orderByDesc('period_end')->orderByDesc('id')->first();
    }

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

    public function selectReview(int $reviewId): void
    {
        $this->reviewId = $reviewId;
        $this->userNotes = Review::find($reviewId)?->user_notes ?? '';

        unset($this->currentReview, $this->renderedMarkdown);
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

{{-- Le seul écran sans la sidebar de l'app : elle est remplacée par
     l'historique des revues, et le contenu passe en colonne de lecture. Tout
     l'écran est une parole d'agent — il n'a donc pas besoin de filet vert,
     c'est le document entier qui l'est. --}}
<div class="flex min-h-screen">
    <div class="hidden w-[236px] shrink-0 flex-col border-r border-(--bd) px-5 py-[26px] lg:flex">
        <a href="{{ route('today') }}" wire:navigate class="mb-3.5 text-[13px] text-(--mut) hover:text-(--tx)">← Aujourd'hui</a>

        {{-- Ces écrans n'ont pas la barre latérale : les onglets de l'espace
             passent donc dans leur colonne. --}}
        <x-space-tabs variant="column" class="mb-[22px] -mx-3" />

        <p class="mb-3.5 text-[11px] tracking-[.12em] text-(--mut) uppercase">Revues</p>

        <div class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto">
            @forelse ($this->reviewHistory as $review)
                @php $isCurrent = $this->currentReview?->id === $review->id; @endphp
                <button
                    type="button"
                    wire:click="selectReview({{ $review->id }})"
                    wire:key="history-{{ $review->id }}"
                    class="press rounded-[12px] px-3.5 py-2.5 text-left {{ $isCurrent ? 'bg-(--aibg)' : 'hover:bg-(--surf)' }}"
                >
                    <span class="block text-[14px] {{ $isCurrent ? 'font-semibold text-(--ai)' : 'text-(--tx)' }}">{{ $this->reviewLabel($review) }}</span>
                    <span class="block text-[12px] text-(--mut)">
                        {{ $review->period_end->translatedFormat('j M') }} ·
                        @if (! $review->completed_at)
                            non lue
                        @else
                            {{ count($review->proposed_decisions ?? []) }} {{ Str::plural('décision', count($review->proposed_decisions ?? [])) }}
                        @endif
                    </span>
                </button>
            @empty
                <p class="text-[13px] text-(--mut)">Aucune revue.</p>
            @endforelse
        </div>

        <div class="mt-auto border-t border-(--bd) pt-4">
            <p class="mb-2.5 text-[11px] tracking-[.12em] text-(--mut) uppercase">Journal des décisions</p>
            <p class="text-[13px] leading-[1.6] text-(--mut)">
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
        </div>
    </div>

    <div class="flex min-w-0 flex-1 justify-center px-5 pt-6 pb-16 lg:px-10 lg:pt-10">
        <div class="w-full max-w-[680px]">
            @if (! $this->currentReview)
                <h1 class="font-display mb-3 text-[42px] leading-[1.1] font-bold tracking-[-.03em] text-(--tx)">Aucune revue</h1>
                <p class="reading-body text-(--mut)">
                    L'agent qui les écrit s'active sur l'écran Agents. Une fois configuré, il passe le dimanche
                    et la revue apparaît ici.
                </p>
                <x-btn variant="secondary" size="md" class="mt-6" :href="route('agents.index')" wire:navigate>Aller aux Agents</x-btn>
            @else
                <div class="mb-4 flex items-center gap-3">
                    <span class="size-[9px] rounded-full bg-(--ai)" aria-hidden="true"></span>
                    <span class="text-[13px] text-(--ai)">
                        Générée par Hermès le {{ $this->currentReview->created_at->translatedFormat('l j F à H:i') }}
                    </span>
                </div>

                <h1 class="font-display mb-3 text-[42px] leading-[1.1] font-bold tracking-[-.03em] text-(--tx)">
                    {{ $this->reviewLabel($this->currentReview) }}
                </h1>

                <p class="reading-body mb-[34px] text-(--mut)">
                    Du {{ $this->currentReview->period_start->translatedFormat('j') }}
                    au {{ $this->currentReview->period_end->translatedFormat('j F') }}
                </p>

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

                    <div class="mb-[30px] flex flex-col gap-3">
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

                {{-- Mes notes ne portent aucun marqueur vert : c'est mon texte,
                     pas celui de l'agent. --}}
                <h2 class="font-display mb-3 text-[22px] font-semibold text-(--tx)">Mes notes sur la semaine</h2>
                <textarea
                    wire:model.blur="userNotes"
                    rows="4"
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
            @endif
        </div>
    </div>
</div>
