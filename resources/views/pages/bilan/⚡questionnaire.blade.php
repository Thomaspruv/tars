<?php

use App\Enums\QuestionnaireFrequency;
use App\Enums\QuestionType;
use App\Models\Questionnaire;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Questionnaire')] class extends Component
{
    public Questionnaire $questionnaire;

    public string $name = '';

    public string $frequency = 'monthly';

    public string $anchor = '';

    /** @var list<array{text: string, type: string, scale_max?: int}> */
    public array $questions = [];

    public function mount(): void
    {
        $this->name = $this->questionnaire->name;
        $this->frequency = $this->questionnaire->frequency->value;
        $this->anchor = $this->questionnaire->anchor;
        $this->questions = $this->questionnaire->questions;
    }

    public function addQuestion(): void
    {
        $this->questions[] = ['text' => '', 'type' => 'text'];
    }

    public function removeQuestion(int $index): void
    {
        unset($this->questions[$index]);
        $this->questions = array_values($this->questions);
    }

    public function moveUp(int $index): void
    {
        if ($index === 0) {
            return;
        }

        $this->swap($index, $index - 1);
    }

    public function moveDown(int $index): void
    {
        if ($index >= count($this->questions) - 1) {
            return;
        }

        $this->swap($index, $index + 1);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'frequency' => ['required', 'in:weekly,monthly,quarterly,yearly'],
            'anchor' => ['required', 'string'],
            'questions' => ['array'],
            'questions.*.text' => ['required', 'string'],
            'questions.*.type' => ['required', 'in:scale,text,boolean,number'],
        ]);

        $this->questionnaire->update([
            'name' => $validated['name'],
            'frequency' => $validated['frequency'],
            'anchor' => $validated['anchor'],
            'questions' => $validated['questions'],
        ]);

        $this->redirect(route('bilan.index', ['activeTab' => 'bilans']), navigate: true);
    }

    public function delete(): void
    {
        if ($this->questionnaire->runs()->exists()) {
            return;
        }

        $this->questionnaire->delete();

        $this->redirect(route('bilan.index', ['activeTab' => 'bilans']), navigate: true);
    }

    private function swap(int $a, int $b): void
    {
        [$this->questions[$a], $this->questions[$b]] = [$this->questions[$b], $this->questions[$a]];
    }
};
?>

<div>
    <x-screen-header
        treatment="record"
        title="Modifier le questionnaire"
        :back="['label' => 'Bilan de vie', 'href' => route('bilan.index', ['activeTab' => 'bilans'])]"
    />

    <div class="mt-6 max-w-xl space-y-5">
        <div>
            <label class="text-xs text-(--mut)">Nom</label>
            <input type="text" wire:model="name" class="mt-1 w-full rounded-[9px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)" />
            @error('name') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="text-xs text-(--mut)">Fréquence</label>
                <select wire:model="frequency" class="mt-1 w-full rounded-[9px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)">
                    @foreach (QuestionnaireFrequency::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
                @error('frequency') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-xs text-(--mut)">Ancre</label>
                <input
                    type="text"
                    wire:model="anchor"
                    placeholder="ex. 1 (jour du mois), mon, 12-31"
                    class="mt-1 w-full rounded-[9px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)"
                />
                @error('anchor') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <div class="flex items-center justify-between">
                <label class="text-xs text-(--mut)">Questions</label>
                <x-btn variant="secondary" size="sm" wire:click="addQuestion">+ Question</x-btn>
            </div>

            <div class="mt-2 space-y-2">
                @foreach ($questions as $index => $question)
                    <div class="flex items-center gap-2" wire:key="question-{{ $index }}">
                        <div class="flex shrink-0 flex-col items-center">
                            <button type="button" wire:click="moveUp({{ $index }})" aria-label="Monter" class="text-(--mut) hover:text-(--tx)">
                                <span aria-hidden="true" class="inline-block rotate-180 text-[10px]">▾</span>
                            </button>
                            <button type="button" wire:click="moveDown({{ $index }})" aria-label="Descendre" class="text-(--mut) hover:text-(--tx)">
                                <span aria-hidden="true" class="text-[10px]">▾</span>
                            </button>
                        </div>
                        <input
                            type="text"
                            wire:model="questions.{{ $index }}.text"
                            placeholder="Texte de la question"
                            class="flex-1 rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-1.5 text-sm text-(--tx)"
                        />
                        <select wire:model="questions.{{ $index }}.type" class="rounded-[8px] border border-(--bd2) bg-(--in) px-2 py-1.5 font-mono text-xs text-(--tx)">
                            @foreach (QuestionType::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="removeQuestion({{ $index }})" aria-label="Supprimer la question" class="shrink-0 text-(--mut) hover:text-(--dgr)">✕</button>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex items-center justify-between">
            @if (! $questionnaire->runs()->exists())
                <x-btn variant="danger" wire:click="delete" wire:confirm="Supprimer ce questionnaire ?">Supprimer</x-btn>
            @else
                <span></span>
            @endif

            <x-btn variant="primary" wire:click="save">Enregistrer</x-btn>
        </div>
    </div>
</div>
