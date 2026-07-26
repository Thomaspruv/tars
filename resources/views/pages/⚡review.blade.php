<?php

use App\Enums\DecisionSource;
use App\Models\Decision;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\Review;
use Illuminate\Database\Eloquent\Collection;
use League\CommonMark\CommonMarkConverter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Revue')] class extends Component
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

        Decision::create([
            'content' => $item['question'],
            'context' => 'Réponse : '.$labels[$response],
            'source' => DecisionSource::Review,
            'review_id' => $review->id,
            'goal_id' => $item['goal'] ? Goal::where('title', $item['goal'])->value('id') : null,
            'entity_id' => $item['entity'] ? Entity::where('name', $item['entity'])->value('id') : null,
            'decided_at' => now(),
        ]);

        $decisions[$index]['response'] = $response;
        $decisions[$index]['answered_at'] = now()->toIso8601String();
        $review->update(['proposed_decisions' => $decisions]);

        unset($this->currentReview);
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

<div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_260px]">
    <div>
        <div class="flex items-center justify-between">
            <h1 class="text-[28px] font-bold tracking-[-0.02em] text-(--tx)">Revue</h1>
            @if ($this->currentReview)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-(--aibg) px-2.5 py-1 font-mono text-[11px] text-(--ai)">
                    ◈ générée {{ $this->currentReview->created_at->translatedFormat('D. H:i') }}
                </span>
            @endif
        </div>

        @if (! $this->currentReview)
            <p class="mt-6 rounded-[14px] border border-(--bd) bg-(--surf) p-8 text-center text-sm text-(--mut)">
                Aucune revue pour l'instant. Configure l'agent <span class="font-mono text-(--ai)">reviewer</span> dans l'écran Agents puis lance-la manuellement.
            </p>
        @else
            <div class="brain-content mt-6 rounded-[14px] border border-(--bd) bg-(--surf) p-6">
                {!! $this->renderedMarkdown !!}
            </div>

            @if (! empty($this->currentReview->proposed_decisions))
                <div class="mt-6 space-y-3">
                    @foreach ($this->currentReview->proposed_decisions as $index => $decision)
                        <div
                            class="rounded-[10px] border-l-2 bg-(--surf) p-4 {{ $decision['response'] ? 'border-(--ok) opacity-60' : 'border-(--ac)' }}"
                            wire:key="decision-{{ $index }}"
                        >
                            <p class="font-mono text-[10.5px] font-semibold tracking-[.08em] text-(--ac) uppercase">Décision {{ $index + 1 }}/{{ count($this->currentReview->proposed_decisions) }}</p>
                            <p class="mt-1 text-[14.5px] text-(--tx)">{{ $decision['question'] }}</p>

                            @if ($decision['response'])
                                <p class="mt-2 text-xs text-(--ok)">✓ {{ ['oui' => 'Oui', 'non' => 'Non', 'plus_tard' => 'Reporté'][$decision['response']] ?? $decision['response'] }} — enregistré au journal de décisions</p>
                            @else
                                <div class="mt-3 flex gap-2">
                                    <x-btn variant="primary" class="!px-3 !py-1.5 text-xs" wire:click="answerDecision({{ $index }}, 'oui')">Oui</x-btn>
                                    <x-btn variant="secondary" class="!px-3 !py-1.5 text-xs" wire:click="answerDecision({{ $index }}, 'non')">Non</x-btn>
                                    <x-btn variant="ghost" class="!px-3 !py-1.5 text-xs" wire:click="answerDecision({{ $index }}, 'plus_tard')">Plus tard</x-btn>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="mt-6">
                <label class="text-xs font-semibold text-(--mut)">Notes</label>
                <textarea
                    wire:model.blur="userNotes"
                    rows="3"
                    placeholder="Tes propres notes sur cette revue…"
                    class="mt-1.5 w-full rounded-[10px] border border-(--bd2) bg-(--in) p-3 text-sm text-(--tx)"
                ></textarea>
            </div>

            <div class="mt-4 flex justify-end">
                @if ($this->currentReview->completed_at)
                    <span class="text-xs text-(--mut)">✓ Marquée comme faite le {{ $this->currentReview->completed_at->translatedFormat('d M à H:i') }}</span>
                @else
                    <x-btn variant="primary" wire:click="markDone">Marquer comme faite</x-btn>
                @endif
            </div>
        @endif
    </div>

    <div class="sticky top-6 self-start">
        <h2 class="text-sm font-semibold text-(--tx)">Historique</h2>
        <div class="mt-3 space-y-1">
            @forelse ($this->reviewHistory as $review)
                <button
                    type="button"
                    wire:click="selectReview({{ $review->id }})"
                    wire:key="history-{{ $review->id }}"
                    class="block w-full rounded-[8px] px-2.5 py-2 text-left text-xs {{ $this->currentReview?->id === $review->id ? 'bg-(--acbg) text-(--ac)' : 'text-(--mut) hover:bg-(--surf2)' }}"
                >
                    <span class="font-mono">{{ $review->period_start->translatedFormat('d M') }} → {{ $review->period_end->translatedFormat('d M') }}</span>
                    <span class="ml-1">{{ $review->type->value === 'monthly' ? '· mensuelle' : '' }}</span>
                    @if (! $review->completed_at)
                        <span class="ml-1 text-(--warn)">●</span>
                    @endif
                </button>
            @empty
                <p class="px-2.5 py-2 text-xs text-(--mut)">Aucune revue.</p>
            @endforelse
        </div>
    </div>
</div>
