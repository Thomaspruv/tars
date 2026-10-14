<?php

use App\Enums\GoalStatus;
use App\Enums\InboxSuggestionStatus;
use App\Enums\OpenDecisionStatus;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\InboxSuggestion;
use App\Models\Note;
use App\Models\OpenDecision;
use App\Models\ProposalDismissal;
use App\Models\Review;
use App\Models\Task;
use App\Support\Proposals\Proposal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** Le rail ne dit jamais plus de quatre choses : au-delà, il ne propose plus, il liste. */
    private const int MAX_PROPOSALS = 4;

    /** Un objectif sans jalon ni activité depuis trois semaines a décroché. */
    private const int IDLE_WEEKS = 3;

    /** Une décision qui arrive à échéance dans la quinzaine. */
    private const int DECISION_HORIZON_DAYS = 15;

    /**
     * Ce que TARS a vu, dans l'ordre où ça compte : la revue qui attend, puis
     * l'inbox pré-qualifiée, puis la décision qui arrive, puis l'objectif qui
     * a décroché.
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function proposals(): Collection
    {
        $dismissed = ProposalDismissal::active()->pluck('key')->all();

        return collect([
            $this->reviewProposal(),
            $this->inboxProposal(),
            $this->decisionProposal(),
            $this->idleGoalProposal(),
        ])
            ->filter(fn (?Proposal $proposal): bool => $proposal !== null && ! in_array($proposal->source, $dismissed, true))
            ->take(self::MAX_PROPOSALS)
            ->values();
    }

    /**
     * « Plus tard » n'est pas « non » : la proposition revient dans une
     * semaine si rien n'a bougé.
     */
    public function dismiss(string $key): void
    {
        ProposalDismissal::updateOrCreate(['key' => $key], ['until' => now()->addWeek()]);

        unset($this->proposals);
    }

    private function reviewProposal(): ?Proposal
    {
        $review = Review::whereNull('completed_at')->orderByDesc('period_end')->first();

        if (! $review) {
            return null;
        }

        $summary = $this->firstSentence($review->generated_content);

        return new Proposal(
            source: 'review:'.$review->id,
            meta: 'Revue · '.$review->period_end->translatedFormat('j F'),
            text: trim('Semaine '.$review->period_end->weekOfYear.' : '.$summary).' Je l\'ouvre ?',
            primaryLabel: 'Lire',
            primaryAction: route('review.index'),
            secondaryLabel: 'Plus tard',
        );
    }

    private function inboxProposal(): ?Proposal
    {
        $pendingCount = InboxItem::whereNull('processed_at')->count();

        if ($pendingCount === 0) {
            return null;
        }

        $suggestions = InboxSuggestion::where('status', InboxSuggestionStatus::Pending->value)
            ->whereHas('inboxItem', fn ($query) => $query->whereNull('processed_at'))
            ->get();

        if ($suggestions->isEmpty()) {
            return new Proposal(
                source: 'inbox:untriaged',
                meta: 'Inbox',
                text: $pendingCount.' '.Str::plural('item', $pendingCount).' en attente, pas encore '.Str::plural('trié', $pendingCount).'.',
                primaryLabel: 'Voir l\'Inbox',
                primaryAction: route('inbox.index'),
            );
        }

        $breakdown = $suggestions
            ->groupBy(fn (InboxSuggestion $suggestion): string => $suggestion->suggested_type->value)
            ->map(fn (Collection $group): string => $group->count().' '.Str::lower($group->first()->suggested_type->label()).'s')
            ->implode(', ');

        return new Proposal(
            source: 'inbox:prequalified',
            meta: 'Inbox',
            text: 'J\'ai pré-qualifié les '.$suggestions->count().' : '.$breakdown.'.',
            primaryLabel: 'Voir l\'Inbox',
            primaryAction: route('inbox.index'),
        );
    }

    private function decisionProposal(): ?Proposal
    {
        $decision = OpenDecision::where('status', OpenDecisionStatus::Open->value)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', today()->addDays(self::DECISION_HORIZON_DAYS))
            ->with(['options.scores', 'criteria'])
            ->orderBy('due_date')
            ->first();

        if (! $decision) {
            return null;
        }

        $leading = $decision->leadingOption();
        $score = $leading ? ($decision->scores()[$leading->id] ?? null) : null;

        $text = $leading !== null
            ? $decision->question.' : '.$leading->label.' ('.$score.' pts).'
            : $decision->question.' : rien ne se détache encore.';

        return new Proposal(
            source: 'decision:'.$decision->id,
            meta: 'Décision · à trancher avant le '.$decision->due_date->translatedFormat('j F'),
            text: $text,
            primaryLabel: 'Peser',
            primaryAction: route('decisions.show', $decision),
            secondaryLabel: 'Ignorer',
        );
    }

    private function idleGoalProposal(): ?Proposal
    {
        $goal = Goal::where('status', GoalStatus::Active->value)
            ->whereDoesntHave('milestones')
            ->get()
            ->first(fn (Goal $candidate): bool => $this->lastActivityAt($candidate)->lessThan(now()->subWeeks(self::IDLE_WEEKS)));

        if (! $goal) {
            return null;
        }

        return new Proposal(
            source: 'goal:'.$goal->id,
            meta: $goal->title,
            text: 'Rien depuis '.self::IDLE_WEEKS.' semaines et aucun jalon. Je te propose 3 jalons à partir du Cerveau.',
            primaryLabel: 'Voir les jalons',
            primaryAction: route('goals.show', $goal),
            secondaryLabel: 'Mettre en pause',
        );
    }

    /**
     * La dernière fois que quelque chose s'est passé sur l'objectif. Sa propre
     * création compte : un objectif posé hier n'a pas « décroché ».
     */
    private function lastActivityAt(Goal $goal): CarbonInterface
    {
        $dates = collect([
            $goal->created_at,
            Task::where('goal_id', $goal->id)->max('completed_at'),
            Task::where('goal_id', $goal->id)->max('created_at'),
            Note::where('goal_id', $goal->id)->max('created_at'),
        ])
            ->filter()
            ->map(fn ($date): CarbonInterface => $date instanceof CarbonInterface ? $date : \Illuminate\Support\Carbon::parse($date));

        return $dates->sortDesc()->first() ?? now();
    }

    private function firstSentence(string $content): string
    {
        // Les titres de la revue ne sont pas sa première phrase : « # Revue de
        // la semaine » collé au texte donnait « Revue Elle restait debout ».
        $body = collect(preg_split('/\R/', strip_tags($content)) ?: [])
            ->reject(fn (string $line): bool => str_starts_with(trim($line), '#'))
            ->implode(' ');

        $plain = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[*_>`]+/', '', $body)));

        if ($plain === '') {
            return '';
        }

        $sentence = Str::before($plain, '. ');

        return Str::limit(trim($sentence), 120).'.';
    }
};
?>

{{-- Le rail : tout ce que l'agent a vu, au même endroit, chacun avec sa
     sortie « non ». Rien ici ne s'applique tout seul. --}}
<div>
    @if ($this->proposals->isNotEmpty())
        <div class="mb-3 flex items-center gap-2.5 px-1">
            <span class="size-[7px] shrink-0 rounded-full bg-(--ai)" aria-hidden="true"></span>
            <h2 class="font-display text-[17px] font-semibold text-(--tx)">TARS propose</h2>
            <span class="text-[13px] text-(--mut)">{{ $this->proposals->count() }}</span>
        </div>

        <div class="flex flex-col gap-2.5">
            @foreach ($this->proposals as $proposal)
                <x-agent-block padding="16" wire:key="proposal-{{ md5($proposal->source) }}">
                    <p class="mb-1 text-[12px] leading-[1.35] text-(--mut)">{{ $proposal->meta }}</p>
                    <p class="mb-3 text-[14px] leading-[1.45] text-(--tx)">{{ $proposal->text }}</p>

                    <div class="flex flex-wrap items-center gap-2">
                        <x-btn variant="agent" size="xs" :href="$proposal->primaryAction" wire:navigate>{{ $proposal->primaryLabel }}</x-btn>

                        @if ($proposal->secondaryLabel)
                            <x-btn variant="ghost" size="xs" wire:click="dismiss('{{ $proposal->source }}')">{{ $proposal->secondaryLabel }}</x-btn>
                        @endif
                    </div>
                </x-agent-block>
            @endforeach
        </div>
    @endif
</div>
