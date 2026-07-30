<?php

use App\Models\QuestionnaireRun;
use App\Support\Questionnaire\QuestionAnswerNormalizer;
use App\Support\Questionnaire\QuestionnaireCompleter;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Remplir le bilan')] class extends Component
{
    public QuestionnaireRun $run;

    /** @var array<int, string> */
    public array $answers = [];

    /** @var list<string> */
    public array $answeredViaHermes = [];

    public function mount(): void
    {
        $this->run->load(['questionnaire', 'answers']);

        $existing = $this->run->answers->keyBy('question_text');
        $this->answeredViaHermes = $existing->keys()->all();

        foreach ($this->run->questionnaire->questions as $index => $question) {
            $answer = $existing->get($question['text']);
            $this->answers[$index] = $answer === null
                ? ''
                : (string) ($answer->answer_numeric ?? $answer->answer_text);
        }
    }

    public function allAnswered(): bool
    {
        if ($this->run->questionnaire->questions === []) {
            return false;
        }

        $normalizer = app(QuestionAnswerNormalizer::class);

        foreach ($this->run->questionnaire->questions as $index => $question) {
            $raw = trim($this->answers[$index] ?? '');

            if ($raw === '' || $normalizer->normalize($question, $raw) === null) {
                return false;
            }
        }

        return true;
    }

    public function saveProgress(QuestionAnswerNormalizer $normalizer): void
    {
        $this->persistAnswers($normalizer);
    }

    public function complete(QuestionnaireCompleter $completer, QuestionAnswerNormalizer $normalizer): void
    {
        $this->persistAnswers($normalizer);

        if (! $this->allAnswered()) {
            return;
        }

        $completer->complete($this->run);

        $this->redirect(route('bilan.index', ['activeTab' => 'bilans']), navigate: true);
    }

    private function persistAnswers(QuestionAnswerNormalizer $normalizer): void
    {
        foreach ($this->run->questionnaire->questions as $index => $question) {
            $raw = trim($this->answers[$index] ?? '');

            if ($raw === '') {
                continue;
            }

            $normalized = $normalizer->normalize($question, $raw);

            if ($normalized === null) {
                continue;
            }

            $this->run->answers()->updateOrCreate(
                ['question_text' => $question['text']],
                ['type' => $question['type'], 'answer_text' => $normalized['answerText'], 'answer_numeric' => $normalized['answerNumeric']],
            );
        }
    }
};
?>

<div class="mx-auto max-w-xl">
    <x-screen-header
        treatment="record"
        :title="$run->questionnaire->name"
        :back="['label' => 'Bilan de vie', 'href' => route('bilan.index', ['activeTab' => 'bilans'])]"
    />

    <div class="mt-6 space-y-4">
        @foreach ($run->questionnaire->questions as $index => $question)
            @php
                $type = $question['type'];
                $scaleMax = $question['scale_max'] ?? 10;
            @endphp

            <x-card wire:key="question-{{ $index }}">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm text-(--tx)">{{ $question['text'] }}</p>
                    @if (in_array($question['text'], $answeredViaHermes, true))
                        <span class="shrink-0 rounded-[5px] bg-(--surf2) px-1.5 py-0.5 font-mono text-[10px] text-(--mut)">répondu via Hermes</span>
                    @endif
                </div>

                <div class="mt-3">
                    @if ($type === 'scale')
                        <div class="flex flex-wrap gap-1">
                            @for ($i = 1; $i <= $scaleMax; $i++)
                                <button
                                    type="button"
                                    wire:click="$set('answers.{{ $index }}', '{{ $i }}')"
                                    class="flex size-7 items-center justify-center rounded-[6px] font-mono text-[11px] {{ ($answers[$index] ?? '') == $i ? 'bg-(--acbg) text-(--ac)' : 'bg-(--surf2) text-(--mut) hover:text-(--tx)' }}"
                                >
                                    {{ $i }}
                                </button>
                            @endfor
                        </div>
                    @elseif ($type === 'boolean')
                        <div class="flex gap-2">
                            <x-btn variant="{{ ($answers[$index] ?? '') === 'oui' ? 'primary' : 'secondary' }}" size="sm" wire:click="$set('answers.{{ $index }}', 'oui')">Oui</x-btn>
                            <x-btn variant="{{ ($answers[$index] ?? '') === 'non' ? 'primary' : 'secondary' }}" size="sm" wire:click="$set('answers.{{ $index }}', 'non')">Non</x-btn>
                        </div>
                    @elseif ($type === 'number')
                        <input type="number" wire:model.live.debounce.500ms="answers.{{ $index }}" class="w-32 rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-1.5 font-mono text-sm text-(--tx)" />
                    @else
                        <textarea wire:model.live.debounce.500ms="answers.{{ $index }}" rows="3" class="w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)"></textarea>
                    @endif
                </div>
            </x-card>
        @endforeach
    </div>

    <div class="mt-6 flex justify-end gap-2">
        <x-btn variant="secondary" wire:click="saveProgress">Enregistrer et continuer plus tard</x-btn>
        <x-btn variant="primary" wire:click="complete" :disabled="! $this->allAnswered()">Terminer</x-btn>
    </div>
</div>
