<?php

use App\Models\JournalEntry;
use App\Models\Questionnaire;
use App\Models\QuestionnaireRun;
use App\Support\Journal\JournalWriter;
use App\Support\Recurrence\RecurrenceCalculator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Bilan de vie')] class extends Component
{
    #[Url]
    public string $activeTab = 'journal';

    #[Url]
    public int $curveDays = 30;

    public bool $showEntryModal = false;

    public bool $entryDateHasExistingEntry = false;

    public string $entryDate = '';

    public ?int $entryMood = null;

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
     * @return list<array{date: Carbon, mood: int|null}>
     */
    #[Computed]
    public function moodCurve(): array
    {
        $days = $this->normalizedCurveDays();
        $start = today()->subDays($days - 1);

        $entries = JournalEntry::whereBetween('date', [$start->toDateString(), today()->toDateString()])
            ->get()
            ->keyBy(fn (JournalEntry $entry): string => $entry->date->toDateString());

        $points = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $entry = $entries->get($date->toDateString());
            $points[] = ['date' => $date, 'mood' => $entry?->mood];
        }

        return $points;
    }

    /**
     * @return list<array{date: Carbon, average: float|null}>
     */
    #[Computed]
    public function moodMovingAverage(): array
    {
        $points = $this->moodCurve;
        $result = [];

        foreach ($points as $index => $point) {
            $windowStart = max(0, $index - 6);
            $window = array_slice($points, $windowStart, $index - $windowStart + 1);
            $moods = array_values(array_filter(array_column($window, 'mood'), fn (?int $mood): bool => $mood !== null));

            $result[] = ['date' => $point['date'], 'average' => $moods !== [] ? array_sum($moods) / count($moods) : null];
        }

        return $result;
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

    #[Computed]
    public function completedRuns(): Collection
    {
        return QuestionnaireRun::where('status', 'completed')
            ->with(['questionnaire', 'answers'])
            ->orderByDesc('completed_at')
            ->limit(20)
            ->get();
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['journal', 'bilans'], true) ? $tab : 'journal';
    }

    public function setCurveDays(int $days): void
    {
        $this->curveDays = $days;
        unset($this->moodCurve, $this->moodMovingAverage);
    }

    public function openEntryModal(?string $date = null): void
    {
        $entry = $date ? JournalEntry::whereDate('date', $date)->first() : null;

        $this->entryDate = $date ?? today()->toDateString();
        $this->entryDateHasExistingEntry = $entry !== null;
        $this->entryMood = $entry?->mood;
        $this->entryMoodLabel = (string) $entry?->mood_label;
        $this->entrySummary = '';
        $this->entryHighlights = [];
        $this->newHighlight = '';
        $this->showEntryModal = true;
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
        );

        $this->showEntryModal = false;

        unset(
            $this->moodCurve, $this->moodMovingAverage, $this->timeline, $this->todayEntry,
            $this->regularity30d, $this->moodAverage7d, $this->moodAveragePrior7d, $this->moodDelta,
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

    private function normalizedCurveDays(): int
    {
        return in_array($this->curveDays, [30, 90, 365], true) ? $this->curveDays : 30;
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

<div>
    <x-screen-header treatment="work" title="Bilan de vie" />

    <div class="mt-5 flex flex-wrap items-center gap-2">
        @foreach (['journal' => 'Journal', 'bilans' => 'Bilans'] as $value => $label)
            <button
                type="button"
                wire:click="setTab('{{ $value }}')"
                class="rounded-full px-3 py-1.5 text-xs font-medium {{ $activeTab === $value ? 'bg-(--acbg) text-(--ac)' : 'bg-(--surf2) text-(--mut) hover:text-(--tx)' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="mt-5 grid grid-cols-1 gap-6 lg:grid-cols-[1fr_280px]">
        <div>
            @if ($activeTab === 'journal')
                <div>
                    <x-card>
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-(--tx)">Courbe de mood</h2>
                            <div class="flex gap-1">
                                @foreach ([30 => '30j', 90 => '90j', 365 => '365j'] as $days => $label)
                                    <button
                                        type="button"
                                        wire:click="setCurveDays({{ $days }})"
                                        class="rounded-full px-2.5 py-1 font-mono text-[11px] {{ $this->normalizedCurveDays() === $days ? 'bg-(--acbg) text-(--ac)' : 'bg-(--surf2) text-(--mut) hover:text-(--tx)' }}"
                                    >
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="mt-4 overflow-x-auto">
                            @php
                                $points = $this->moodCurve;
                                $averages = $this->moodMovingAverage;
                                $count = count($points);
                                $width = max(300, $count * 8);
                                $height = 110;

                                $x = fn (int $index): float => $count > 1 ? ($index / ($count - 1)) * ($width - 8) + 4 : $width / 2;
                                $y = fn (int $mood): float => 10 + (10 - $mood) * 9;

                                $palier = fn (int $mood): string => match (true) {
                                    $mood <= 3 => 'var(--dgr)',
                                    $mood <= 6 => 'var(--mut)',
                                    default => 'var(--ok)',
                                };

                                $averagePoints = collect($averages)
                                    ->values()
                                    ->map(fn (array $point, int $index): ?string => $point['average'] !== null ? $x($index).','.$y((int) round($point['average'])) : null)
                                    ->filter()
                                    ->implode(' ');
                            @endphp

                            <svg viewBox="0 0 {{ $width }} {{ $height }}" width="{{ $width }}" height="{{ $height }}" class="min-w-full">
                                @if ($averagePoints !== '')
                                    <polyline points="{{ $averagePoints }}" fill="none" stroke="var(--cy, #5EC8D8)" stroke-width="1.5" />
                                @endif

                                @foreach ($points as $index => $point)
                                    @if ($point['mood'] !== null)
                                        <circle
                                            cx="{{ $x($index) }}"
                                            cy="{{ $y($point['mood']) }}"
                                            r="3"
                                            fill="{{ $palier($point['mood']) }}"
                                        >
                                            <title>{{ $point['date']->translatedFormat('d M') }} — mood {{ $point['mood'] }}/10</title>
                                        </circle>
                                    @endif
                                @endforeach
                            </svg>
                        </div>
                    </x-card>

                    <div class="mt-6 divide-y divide-(--bd) rounded-[12px] border border-(--bd) bg-(--surf)">
                        @if (! $this->todayEntry)
                            <div class="flex items-center justify-between gap-3 border-l-2 border-dashed border-(--bd) px-4 py-3">
                                <p class="text-sm text-(--mut)">
                                    Aujourd'hui — rien encore. Raconte ta journée à Hermes ce soir, ou
                                    <button type="button" wire:click="openEntryModal" class="text-(--ac) underline underline-offset-2">écris-la ici</button>.
                                </p>
                            </div>
                        @endif

                        @forelse ($this->timeline as $entry)
                            <button
                                type="button"
                                wire:click="openEntryModal('{{ $entry->date->toDateString() }}')"
                                wire:key="entry-{{ $entry->id }}"
                                class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-(--surf2)"
                            >
                                <time class="w-14 shrink-0 font-mono text-xs text-(--mut)" datetime="{{ $entry->date->toDateString() }}">
                                    {{ $entry->date->translatedFormat('d M') }}
                                </time>

                                @if ($entry->mood !== null)
                                    <span
                                        class="shrink-0 rounded-full px-2 py-0.5 font-mono text-[11px]"
                                        style="background: {{ $entry->mood <= 3 ? 'var(--dgrbg)' : ($entry->mood <= 6 ? 'var(--surf2)' : 'var(--okbg)') }}; color: {{ $entry->mood <= 3 ? 'var(--dgr)' : ($entry->mood <= 6 ? 'var(--mut)' : 'var(--ok)') }}"
                                    >
                                        {{ $entry->mood }}
                                    </span>
                                @endif

                                @if ($entry->mood_label)
                                    <span class="shrink-0 font-mono text-[10.5px] text-(--mut)">{{ $entry->mood_label }}</span>
                                @endif

                                <span class="min-w-0 flex-1 truncate text-sm text-(--tx)">{{ $entry->summary }}</span>

                                @if ($entry->source === 'hermes')
                                    <span class="shrink-0 rounded-[5px] bg-(--surf2) px-1.5 py-0.5 font-mono text-[10px] text-(--mut)">via Hermes</span>
                                @endif
                            </button>
                        @empty
                            <div class="p-8 text-center">
                                <p class="text-sm text-(--mut)">Ton journal est vide. Ce soir, raconte ta journée à Hermes — c'est tout.</p>
                                <x-btn variant="secondary" wire:click="openEntryModal" class="mt-3">Écrire ma première entrée</x-btn>
                            </div>
                        @endforelse
                    </div>
                </div>
            @else
                <div>
                    @if ($this->pendingRun)
                        <div class="flex items-center justify-between rounded-[12px] border border-(--ac)/35 bg-(--acbg) px-[18px] py-3">
                            <p class="text-sm text-(--tx)">
                                <span class="font-semibold text-(--ac)">{{ $this->pendingRun->questionnaire->name }}</span>
                                — en attente depuis le {{ $this->pendingRun->due_date->translatedFormat('d M') }}
                            </p>
                            <a href="{{ route('bilan.run.fill', $this->pendingRun) }}" wire:navigate class="text-sm text-(--ac) underline underline-offset-2">
                                Le remplir →
                            </a>
                        </div>
                    @endif

                    <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($this->questionnaires as $questionnaire)
                            <x-card wire:key="questionnaire-{{ $questionnaire->id }}">
                                <div class="flex items-start justify-between gap-2">
                                    <h3 class="text-[15.5px] font-medium text-(--tx)">{{ $questionnaire->name }}</h3>
                                    <x-toggle wire:click="toggleQuestionnaire({{ $questionnaire->id }})" :checked="$questionnaire->is_active" />
                                </div>
                                <div class="mt-2 flex items-center gap-2">
                                    <span class="inline-flex items-center gap-1 rounded-[5px] bg-(--surf2) px-1.5 py-0.5 font-mono text-[10px] text-(--mut)">
                                        <span aria-hidden="true">↻</span>{{ $questionnaire->frequency->label() }}
                                    </span>
                                    <span class="text-xs text-(--mut)">{{ count($questionnaire->questions) }} question(s)</span>
                                </div>
                                <a href="{{ route('bilan.questionnaire.edit', $questionnaire) }}" wire:navigate class="mt-3 inline-block text-xs text-(--mut) hover:text-(--tx)">
                                    Modifier
                                </a>
                            </x-card>
                        @endforeach

                        <button
                            type="button"
                            wire:click="createQuestionnaire"
                            class="flex min-h-[120px] items-center justify-center rounded-[12px] border border-dashed border-(--bd2) text-sm text-(--mut) hover:border-(--ac) hover:text-(--ac)"
                        >
                            + Nouveau questionnaire
                        </button>
                    </div>

                    <div class="mt-8">
                        <h2 class="text-sm font-semibold text-(--tx)">Historique</h2>
                        <div class="mt-3 space-y-2">
                            @forelse ($this->completedRuns as $run)
                                <x-card wire:key="run-{{ $run->id }}" padding="14">
                                    <div class="flex items-center justify-between">
                                        <h3 class="text-sm font-medium text-(--tx)">{{ $run->questionnaire->name }}</h3>
                                        <time class="font-mono text-xs text-(--mut)" datetime="{{ $run->completed_at?->toDateString() }}">
                                            {{ $run->completed_at?->translatedFormat('d M Y') }}
                                        </time>
                                    </div>
                                    <div class="mt-2 space-y-1.5">
                                        @foreach ($run->answers as $answer)
                                            <div class="flex items-center justify-between gap-3 text-sm">
                                                <span class="text-xs text-(--mut)">{{ $answer->question_text }}</span>
                                                <span class="shrink-0 font-mono text-(--tx)">
                                                    {{ $answer->answer_numeric !== null ? $answer->answer_numeric.'/10' : $answer->answer_text }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </x-card>
                            @empty
                                <p class="rounded-[12px] border border-(--bd) bg-(--surf) p-5 text-center text-sm text-(--mut)">Aucun bilan complété pour l'instant.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="sticky top-6 self-start space-y-4">
            <x-card>
                <p class="font-mono text-[10.5px] font-semibold tracking-[.08em] text-(--mut) uppercase">Mood — 7 jours</p>
                @if ($this->moodAverage7d !== null)
                    <p class="mt-1 font-mono text-[26px] font-semibold text-(--tx)">
                        {{ $this->moodAverage7d }}
                        @if ($this->moodDelta !== null)
                            <span class="text-xs font-normal {{ $this->moodDelta >= 0 ? 'text-(--ok)' : 'text-(--dgr)' }}">
                                {{ $this->moodDelta >= 0 ? '+' : '' }}{{ $this->moodDelta }}
                            </span>
                        @endif
                    </p>
                @else
                    <p class="mt-1 text-sm text-(--mut)">Pas encore de données.</p>
                @endif
            </x-card>

            <x-card>
                <p class="font-mono text-[10.5px] font-semibold tracking-[.08em] text-(--mut) uppercase">Régularité</p>
                <p class="mt-1 font-mono text-sm text-(--tx)">{{ $this->regularity30d['filled'] }}/{{ $this->regularity30d['total'] }} jours</p>
            </x-card>

            <x-card>
                <p class="font-mono text-[10.5px] font-semibold tracking-[.08em] text-(--mut) uppercase">Prochain bilan</p>
                @if ($this->pendingRun)
                    <p class="mt-1 text-sm text-(--warn)">{{ $this->pendingRun->questionnaire->name }} — en attente</p>
                    <x-btn variant="secondary" size="sm" href="{{ route('bilan.run.fill', $this->pendingRun) }}" wire:navigate class="mt-2">Le remplir</x-btn>
                @elseif ($this->nextBilanPreview)
                    <p class="mt-1 text-sm text-(--tx)">{{ $this->nextBilanPreview['name'] }}</p>
                    <p class="font-mono text-xs text-(--mut)">{{ $this->nextBilanPreview['date']->translatedFormat('d M Y') }}</p>
                @else
                    <p class="mt-1 text-sm text-(--mut)">Aucun questionnaire actif.</p>
                @endif
            </x-card>

            @if (! $this->todayEntry)
                <x-btn variant="secondary" wire:click="openEntryModal" class="w-full">Écrire l'entrée du jour</x-btn>
            @endif
        </div>
    </div>

    <flux:modal wire:model.self="showEntryModal" class="md:w-[480px]">
        <div class="space-y-5">
            <flux:heading size="lg">{{ $entryDateHasExistingEntry ? 'Ajouter à cette journée' : 'Nouvelle entrée' }}</flux:heading>

            <div>
                <label class="text-xs text-(--mut)">Date</label>
                <input
                    type="date"
                    wire:model="entryDate"
                    max="{{ today()->toDateString() }}"
                    @disabled($entryDateHasExistingEntry)
                    class="mt-1 w-full rounded-[9px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx) disabled:opacity-50"
                />
                @error('entryDate') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-xs text-(--mut)">Mood</label>
                <div class="mt-1.5 flex flex-wrap gap-1">
                    @for ($i = 1; $i <= 10; $i++)
                        <button
                            type="button"
                            wire:click="$set('entryMood', {{ $entryMood === $i ? 'null' : $i }})"
                            class="flex size-7 items-center justify-center rounded-[6px] font-mono text-[11px] {{ $entryMood === $i ? ($i <= 3 ? 'bg-(--dgrbg) text-(--dgr)' : ($i <= 6 ? 'bg-(--surf2) text-(--tx)' : 'bg-(--okbg) text-(--ok)')) : 'bg-(--surf2) text-(--mut) hover:text-(--tx)' }}"
                        >
                            {{ $i }}
                        </button>
                    @endfor
                </div>
            </div>

            <div>
                <label class="text-xs text-(--mut)">Libellé</label>
                <input type="text" wire:model="entryMoodLabel" placeholder="calme, dense, fatigué…" class="mt-1 w-full rounded-[9px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)" />
            </div>

            <div>
                <label class="text-xs text-(--mut)">Résumé</label>
                <textarea wire:model="entrySummary" rows="4" class="mt-1 w-full rounded-[9px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)"></textarea>
                @error('entrySummary') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-xs text-(--mut)">Moments</label>
                <div class="mt-1.5 space-y-1">
                    @foreach ($entryHighlights as $index => $highlight)
                        <div class="flex items-center gap-2 text-sm text-(--tx)" wire:key="highlight-{{ $index }}">
                            <span class="flex-1">{{ $highlight }}</span>
                            <button type="button" wire:click="removeHighlight({{ $index }})" class="text-(--mut) hover:text-(--dgr)">✕</button>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1.5 flex gap-2">
                    <input type="text" wire:model="newHighlight" wire:keydown.enter.prevent="addHighlight" placeholder="Un moment marquant…" class="flex-1 rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-1.5 text-sm text-(--tx)" />
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
