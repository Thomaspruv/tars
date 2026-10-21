<?php

use App\Enums\BrainSuggestionStatus;
use App\Enums\GitSyncStatus;
use App\Models\BrainDocument;
use App\Models\BrainSuggestion;
use App\Jobs\RunAgentCommandJob;
use App\Models\AgentConfig;
use App\Support\Brain\AnchorResolver;
use App\Support\Brain\BrainHealth;
use App\Support\Brain\BrainHealthReport;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\NotePathResolver;
use App\Support\Brain\Schema\FrontmatterSchema;
use App\Support\Brain\GitRepository;
use App\Support\Brain\Schema\FrontmatterBuilder;
use App\Support\Brain\Schema\NoteType;
use App\Support\Brain\VaultWriter;
use App\Support\Curator\SuggestionApplier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::reading')] #[Title('Mémoire')] class extends Component
{
    #[Url]
    public ?string $path = null;

    #[Url]
    public string $search = '';

    public bool $isEditing = false;

    public string $editContent = '';

    public bool $showCreateModal = false;

    public string $newType = 'note';

    public string $newTitle = '';

    public ?string $saveMessage = null;

    public ?string $conformanceMessage = null;

    #[Computed]
    public function configured(): bool
    {
        return app(BrainSettings::class)->isConfigured();
    }

    #[Computed]
    public function health(): BrainHealthReport
    {
        return app(BrainHealth::class)->report();
    }

    #[Computed]
    public function lastPushedAt(): ?\Carbon\CarbonInterface
    {
        return $this->configured
            ? app(GitRepository::class)->lastPushAt(app(BrainSettings::class)->localPath())
            : null;
    }

    /**
     * @return array{at: ?\Illuminate\Support\Carbon, message: ?string}
     */
    #[Computed]
    public function conflictAlert(): array
    {
        $settings = app(BrainSettings::class);

        return ['at' => $settings->lastConflictAt(), 'message' => $settings->lastConflictMessage()];
    }

    /**
     * Les propositions de conformité, groupées par lot — c'est le lot que
     * Thomas accepte ou refuse d'un clic.
     *
     * @return \Illuminate\Support\Collection<string, Collection>
     */
    #[Computed]
    public function conformanceBatches(): \Illuminate\Support\Collection
    {
        return BrainSuggestion::where('status', BrainSuggestionStatus::Pending->value)
            ->whereNotNull('batch_id')
            ->with('brainDocument')
            ->orderBy('id')
            ->get()
            ->groupBy('batch_id');
    }

    public function runConformanceCheck(): void
    {
        if (! AgentConfig::where('agent_name', 'curateur')->where('enabled', true)->exists()) {
            $this->addError('conformance', "Le curateur n'est pas configuré.");

            return;
        }

        RunAgentCommandJob::dispatch('curator:conformance');

        $this->conformanceMessage = 'Vérification lancée — les propositions arriveront ici.';
        $this->resetErrorBag('conformance');
    }

    public function acceptConformanceBatch(string $batchId): void
    {
        $suggestions = BrainSuggestion::where('batch_id', $batchId)
            ->where('status', BrainSuggestionStatus::Pending->value)
            ->with('brainDocument')
            ->get();

        try {
            app(SuggestionApplier::class)->applyBatch($suggestions, "curator conformance batch {$batchId}");
            $this->resetErrorBag('conformance');
        } catch (\RuntimeException $e) {
            $this->addError('conformance', $e->getMessage());

            return;
        }

        $this->forgetConformance();
    }

    public function rejectConformanceBatch(string $batchId): void
    {
        BrainSuggestion::where('batch_id', $batchId)
            ->where('status', BrainSuggestionStatus::Pending->value)
            ->update(['status' => BrainSuggestionStatus::Rejected->value]);

        $this->forgetConformance();
    }

    private function forgetConformance(): void
    {
        unset($this->conformanceBatches, $this->pendingSuggestions, $this->health, $this->tree, $this->folders, $this->selectedDocument, $this->renderedContent);
    }

    /**
     * L'état git du vault, affiché en pied de l'arborescence.
     *
     * Mis en cache une minute : `git status` est un appel système, et cet
     * écran se re-rend à chaque frappe dans la recherche.
     */
    #[Computed]
    public function gitStatus(): GitSyncStatus
    {
        if (! $this->configured) {
            return GitSyncStatus::Unknown;
        }

        return Cache::remember(
            'brain.git-status',
            now()->addMinute(),
            fn (): GitSyncStatus => app(GitRepository::class)->status(app(BrainSettings::class)->localPath()),
        );
    }

    #[Computed]
    public function gitBranch(): string
    {
        return app(BrainSettings::class)->branch();
    }

    /**
     * Les objets que cette note nomme — entités et objectifs résolus à
     * l'indexation. C'est ce qui fait du vault autre chose qu'un dossier de
     * fichiers.
     *
     * @return \Illuminate\Support\Collection<int, array{label: string, href: string}>
     */
    #[Computed]
    public function documentLinks(): \Illuminate\Support\Collection
    {
        $document = $this->selectedDocument;

        if ($document === null) {
            return collect();
        }

        return collect($document->goals)
            ->map(fn ($goal): array => ['label' => $goal->title, 'href' => route('goals.show', $goal)])
            ->concat(
                collect($document->entities)
                    ->map(fn ($entity): array => ['label' => $entity->name, 'href' => route('entities.show', $entity)])
            )
            ->values();
    }

    #[Computed]
    public function pendingSuggestions(): Collection
    {
        return BrainSuggestion::where('status', BrainSuggestionStatus::Pending->value)
            ->whereNull('batch_id')
            ->with('brainDocument')
            ->get()
            ->sort(function (BrainSuggestion $a, BrainSuggestion $b): int {
                $deprioritizedComparison = ($a->deprioritized_at !== null) <=> ($b->deprioritized_at !== null);

                return $deprioritizedComparison !== 0 ? $deprioritizedComparison : $b->created_at <=> $a->created_at;
            })
            ->values();
    }

    #[Computed]
    public function recentAutoApplied(): Collection
    {
        return BrainSuggestion::where('status', BrainSuggestionStatus::AutoApplied->value)
            ->where('created_at', '>=', now()->subDays(7))
            ->with('brainDocument')
            ->orderByDesc('created_at')
            ->get();
    }

    public function acceptSuggestion(int $suggestionId): void
    {
        $suggestion = BrainSuggestion::findOrFail($suggestionId);

        try {
            app(SuggestionApplier::class)->apply($suggestion);
            $this->resetErrorBag('acceptSuggestion');
        } catch (\RuntimeException $e) {
            $this->addError('acceptSuggestion', $e->getMessage());

            return;
        }

        $this->forgetConformance();
    }

    public function rejectSuggestion(int $suggestionId): void
    {
        BrainSuggestion::where('id', $suggestionId)->update(['status' => BrainSuggestionStatus::Rejected->value]);

        unset($this->pendingSuggestions, $this->conformanceBatches, $this->health);
    }

    public function deprioritizeSuggestion(int $suggestionId): void
    {
        BrainSuggestion::where('id', $suggestionId)->update(['deprioritized_at' => now()]);

        unset($this->pendingSuggestions);
    }

    public function cancelAutoApplied(int $suggestionId): void
    {
        $suggestion = BrainSuggestion::findOrFail($suggestionId);

        $suggestion->createdRecord?->delete();
        $suggestion->update(['status' => BrainSuggestionStatus::Rejected->value]);

        unset($this->recentAutoApplied);
    }

    #[Computed]
    public function tree(): array
    {
        $tree = [];

        foreach (BrainDocument::orderBy('path')->pluck('path') as $documentPath) {
            $parts = explode('/', $documentPath);
            $node = &$tree;

            foreach ($parts as $index => $part) {
                if ($index === count($parts) - 1) {
                    $node[$part] = $documentPath;
                } else {
                    $node[$part] ??= [];
                    $node = &$node[$part];
                }
            }

            unset($node);
        }

        return $tree;
    }

    /**
     * Les notes que le contrat n'accepte pas — portées en badge dans
     * l'arborescence et en lecture. Les zones exemptées (Profil/, territoire
     * d'Hermes) et les fichiers générés n'y figurent jamais.
     *
     * @return list<string>
     */
    #[Computed]
    public function nonConformantPaths(): array
    {
        return BrainDocument::underContract()
            ->where('is_conformant', false)
            ->orderBy('path')
            ->pluck('path')
            ->all();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function schemaErrors(): array
    {
        $document = $this->selectedDocument;

        if ($document === null || $document->is_conformant || $document->isConformanceExempt()) {
            return [];
        }

        return $document->schemaErrors();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function noteTypes(): array
    {
        $types = [];

        foreach (NoteType::cases() as $type) {
            $types[$type->value] = $type->label();
        }

        return $types;
    }

    /**
     * Le chemin que TARS calculera — montré avant d'enregistrer, pour que
     * Thomas voie où sa note va atterrir.
     */
    #[Computed]
    public function newNotePath(): ?string
    {
        if (trim($this->newTitle) === '') {
            return null;
        }

        return app(NotePathResolver::class)->resolve(
            NoteType::tryFrom($this->newType) ?? NoteType::Note,
            $this->newTitle,
            now(),
        );
    }

    #[Computed]
    public function folders(): array
    {
        return BrainDocument::query()
            ->pluck('path')
            ->map(fn (string $documentPath): ?string => str_contains($documentPath, '/') ? dirname($documentPath) : null)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    #[Computed]
    public function selectedDocument(): ?BrainDocument
    {
        return $this->path ? BrainDocument::where('path', $this->path)->first() : null;
    }

    #[Computed]
    public function renderedContent(): ?string
    {
        if (! $this->selectedDocument) {
            return null;
        }

        return $this->renderMarkdown($this->selectedDocument);
    }

    #[Computed]
    public function searchResults(): Collection
    {
        if (trim($this->search) === '') {
            return new Collection;
        }

        return BrainDocument::search($this->search)->orderBy('title')->limit(20)->get();
    }

    public function selectDocument(string $documentPath): void
    {
        $this->path = $documentPath;
        $this->isEditing = false;
        $this->saveMessage = null;
        unset($this->selectedDocument, $this->renderedContent, $this->documentLinks, $this->schemaErrors);
    }

    public function startEditing(): void
    {
        if (! $this->selectedDocument) {
            return;
        }

        $this->editContent = File::get($this->absolutePath($this->selectedDocument->path));
        $this->isEditing = true;
    }

    public function cancelEditing(): void
    {
        $this->isEditing = false;
        $this->resetErrorBag('save');
    }

    public function save(): void
    {
        $document = $this->selectedDocument;

        if (! $document) {
            return;
        }

        $result = app(VaultWriter::class)->writeRaw($document->path, $this->editContent, "edit {$document->path}");

        if (! $result->isWritten()) {
            $this->addError('save', $result->message());

            return;
        }

        $this->isEditing = false;
        unset($this->selectedDocument, $this->renderedContent, $this->tree, $this->folders, $this->nonConformantPaths, $this->schemaErrors, $this->health);

        // La sauvegarde de Thomas passe toujours : le contrat lie les agents,
        // pas lui. Mais il doit savoir ce qu'il vient de perdre.
        $errors = $this->schemaErrors;

        $this->saveMessage = $errors === []
            ? null
            : 'Enregistré, mais non conforme : '.implode(' ', $errors).' Les ancres de cette note ne seront pas résolues.';
    }

    public function openCreateModal(): void
    {
        $this->reset(['newType', 'newTitle']);
        $this->showCreateModal = true;
    }

    public function createNote(): void
    {
        $validated = $this->validate([
            'newTitle' => ['required', 'string', 'max:255'],
            'newType' => ['required', 'string', 'in:'.implode(',', array_keys($this->noteTypes))],
        ]);

        $type = NoteType::from($validated['newType']);
        $relativePath = (string) $this->newNotePath;

        if (! $type->isDated() && File::exists($this->absolutePath($relativePath))) {
            $this->addError('newTitle', "Une note existe déjà à cet emplacement ({$relativePath}).");

            return;
        }

        $frontmatter = app(FrontmatterBuilder::class)->build(
            type: $type,
            title: $validated['newTitle'],
            date: now(),
            source: 'thomas',
        );

        $result = app(VaultWriter::class)->write($relativePath, $frontmatter, "# {$validated['newTitle']}\n", "create {$relativePath}");

        if (! $result->isWritten()) {
            $this->addError('newTitle', $result->message());

            return;
        }

        $this->showCreateModal = false;
        $this->path = $relativePath;
        $this->saveMessage = null;
        unset($this->selectedDocument, $this->renderedContent, $this->tree, $this->folders, $this->nonConformantPaths, $this->schemaErrors, $this->health);
    }

    private function absolutePath(string $relativePath): string
    {
        return rtrim(app(BrainSettings::class)->localPath(), '/').'/'.$relativePath;
    }

    private function renderMarkdown(BrainDocument $document): string
    {
        $html = (new CommonMarkConverter)->convertToHtml((string) $document->content)->getContent();

        return preg_replace_callback('/\[\[([^\]]+)\]\]/u', function (array $matches): string {
            $target = trim(explode('#', explode('|', $matches[1])[0])[0]);
            $label = str_contains($matches[1], '|') ? trim(explode('|', $matches[1])[1]) : $target;

            $resolver = app(AnchorResolver::class);

            if ($entity = $resolver->resolveEntity($target)) {
                return '<a href="'.e(route('entities.show', $entity)).'" wire:navigate>'.e($label).'</a>';
            }

            if ($goal = $resolver->resolveGoal($target)) {
                return '<a href="'.e(route('goals.show', $goal)).'" wire:navigate>'.e($label).'</a>';
            }

            if ($other = BrainDocument::where('title', $target)->first()) {
                return '<a href="'.e(route('brain.index', ['path' => $other->path])).'" wire:navigate>'.e($label).'</a>';
            }

            return e($matches[1]);
        }, $html);
    }
};
?>

{{-- Trois colonnes : arborescence, note rendue, contexte du curateur. Cet
     écran remplace la sidebar de l'app par la sienne — on vient y lire, pas y
     naviguer. --}}
<div class="flex min-h-screen">
    <div class="hidden w-[232px] shrink-0 flex-col border-r border-(--bd) px-4 py-[22px] lg:flex">
        <a href="{{ route('today') }}" wire:navigate class="mb-[18px] flex items-center gap-[11px] px-2.5">
            <x-app-logo-icon class="size-[22px]" />
            <span class="font-display text-[19px] font-bold tracking-[-.01em] text-(--tx)">TARS</span>
        </a>

        <a href="{{ route('today') }}" wire:navigate class="mb-3 px-3 text-[12px] text-(--mut) hover:text-(--tx)">← Aujourd'hui</a>

        {{-- Cet écran n'a pas la barre latérale : les onglets de Mémoire
             passent donc dans sa colonne, au même endroit que sur les deux
             autres écrans de lecture. --}}
        <x-space-tabs space="memoire" variant="column" class="mb-4 -mx-0.5" />

        <input
            type="text"
            wire:model.live.debounce.400ms="search"
            placeholder="Rechercher…"
            class="mb-3 w-full rounded-full bg-(--surf2) px-3.5 py-2 text-[13px] text-(--tx) placeholder:text-(--mut) focus:outline-none"
        />

        <div class="min-h-0 flex-1 overflow-y-auto">
            @if (trim($search) !== '')
                <div class="flex flex-col gap-px">
                    @forelse ($this->searchResults as $result)
                        <button
                            type="button"
                            wire:click="selectDocument('{{ $result->path }}')"
                            class="press block w-full truncate rounded-[8px] px-3 py-[7px] text-left text-[13px] {{ $path === $result->path ? 'bg-(--surf2) text-(--tx)' : 'text-(--mut) hover:bg-(--surf) hover:text-(--tx)' }}"
                        >{{ $result->title }}</button>
                    @empty
                        <p class="px-3 py-2 text-[12px] text-(--mut)">Aucun résultat.</p>
                    @endforelse
                </div>
            @else
                <p class="px-3 pb-2.5 font-mono text-[11px] tracking-[.1em] text-(--mut) uppercase">Arborescence</p>
                <x-brain-tree :tree="$this->tree" :selected-path="$path" :non-conformant-paths="$this->nonConformantPaths" />
            @endif
        </div>

        @if ($this->configured)
            @php
                $gitTone = match ($this->gitStatus) {
                    \App\Enums\GitSyncStatus::Synced => ['text-(--ai)', 'bg-(--ai)', 'Git à jour'],
                    \App\Enums\GitSyncStatus::Behind => ['text-(--warn)', 'bg-(--warn)', 'Git en retard'],
                    \App\Enums\GitSyncStatus::Conflict => ['text-(--dgr)', 'bg-(--dgr)', 'Conflit git'],
                    default => ['text-(--mut)', 'bg-(--mut)', 'État git inconnu'],
                };
            @endphp
            <div class="mt-auto border-t border-(--bd) px-3 pt-3.5">
                <p class="mb-1.5 flex items-center gap-2.5 text-[12px] {{ $gitTone[0] }}">
                    <span class="size-[7px] rounded-full {{ $gitTone[1] }}" aria-hidden="true"></span>{{ $gitTone[2] }}
                </p>
                <p class="font-mono text-[11px] leading-[1.6] text-(--mut)">{{ $this->gitBranch }}</p>
            </div>
        @endif
    </div>

    <div class="min-w-0 flex-1 px-5 pt-4 pb-10 lg:px-9 lg:pt-[26px]">
        @if ($this->configured)
            {{-- La santé de la mémoire, en tête : le contrat tenu, l'état de la
                 synchro, et l'alerte s'il y a eu un conflit. --}}
            <div class="mb-[22px] flex flex-wrap items-center gap-x-6 gap-y-2.5">
                <div class="min-w-[180px]">
                    <p class="mb-1.5 flex items-baseline gap-2 text-[13px] text-(--mut)">
                        <span class="font-semibold text-(--tx)">{{ $this->health->conformanceRate }} % conforme</span>
                        <span>{{ $this->health->conformant }}/{{ $this->health->underContract }} notes</span>
                    </p>
                    <div class="h-[5px] w-full overflow-hidden rounded-full bg-(--surf2)" role="img" aria-label="{{ $this->health->conformanceRate }} % des notes respectent le contrat">
                        <div class="h-full rounded-full bg-(--ai)" style="width: {{ $this->health->conformanceRate }}%"></div>
                    </div>
                </div>

                <p class="text-[13px] text-(--mut)">
                    Dernier push
                    <span class="font-semibold text-(--tx)">{{ $this->lastPushedAt?->diffForHumans() ?? '—' }}</span>
                </p>

                @if ($this->health->misplaced > 0)
                    <p class="text-[13px] text-(--mut)">
                        <span class="font-semibold text-(--warn)">{{ $this->health->misplaced }}</span>
                        hors dossier cible
                    </p>
                @endif

                @if ($this->health->sasNotes > 0)
                    <p class="text-[13px] text-(--mut)">
                        Sas <span class="font-semibold text-(--tx)">{{ $this->health->sasNotes }}</span>
                    </p>
                @endif
            </div>

            @if ($this->conflictAlert['at'])
                <div class="mb-[22px] max-w-[620px] rounded-[16px] border border-(--dgr) bg-(--surf) p-4">
                    <p class="mb-1 text-[13px] font-semibold text-(--dgr)">Conflit git — {{ $this->conflictAlert['at']->diffForHumans() }}</p>
                    <p class="text-[13px] leading-[1.6] text-(--mut)">{{ $this->conflictAlert['message'] }}</p>
                    <a href="{{ route('settings.index') }}#cerveau" wire:navigate class="mt-2 inline-block text-[13px] text-(--ac) hover:text-(--achov)">Forcer la synchro dans les Réglages →</a>
                </div>
            @endif
        @endif

        @if (! $this->configured)
            <x-card padding="22" class="max-w-[620px]">
                <p class="mb-2 text-[15px] text-(--mut)">Le vault n'est pas configuré.</p>
                <a href="{{ route('settings.index') }}" wire:navigate class="text-[15px] text-(--ac) hover:text-(--achov)">Le configurer dans les Réglages →</a>
            </x-card>
        @elseif (! $this->selectedDocument)
            <div class="lg:hidden">
                <p class="mb-3.5 font-mono text-[11px] tracking-[.1em] text-(--mut) uppercase">Arborescence</p>
                <x-brain-tree :tree="$this->tree" :selected-path="$path" :non-conformant-paths="$this->nonConformantPaths" />
            </div>
            <p class="mt-6 text-[15px] text-(--mut)">Choisis une note dans l'arborescence.</p>
        @else
            <div class="mb-[22px] flex flex-wrap items-center gap-3.5">
                <span class="font-mono text-[12px] text-(--mut)">{{ str_replace('/', ' / ', $this->selectedDocument->path) }}</span>

                @if ($this->schemaErrors !== [])
                    <div x-data="{ open: false }" class="relative">
                        <button type="button" x-on:click="open = ! open" class="press">
                            <x-badge type="pill" tone="warn">non conforme</x-badge>
                        </button>
                        <div
                            x-show="open"
                            x-cloak
                            x-on:click.outside="open = false"
                            class="absolute top-full left-0 z-10 mt-1.5 w-[320px] rounded-[14px] bg-(--surf2) p-3.5 text-[13px] leading-[1.6] text-(--tx) shadow-lg"
                        >
                            <p class="mb-1.5 font-semibold">Ce que le contrat v3 attend :</p>
                            <ul class="flex flex-col gap-1 text-(--mut)">
                                @foreach ($this->schemaErrors as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                            <p class="mt-2 text-[12px] text-(--mut)">Tant qu'elle est non conforme, ses ancres ne sont pas résolues.</p>
                        </div>
                    </div>
                @endif

                <div class="ml-auto flex gap-2">
                    @unless ($isEditing)
                        <x-btn variant="secondary" size="xs" wire:click="startEditing">Éditer</x-btn>
                    @endunless
                    <x-btn variant="secondary" size="xs" wire:click="openCreateModal">Nouvelle note</x-btn>
                </div>
            </div>

            @error('save')
                <p class="mb-4 max-w-[620px] text-[13px] text-(--dgr)">{{ $message }}</p>
            @enderror

            @if ($saveMessage)
                <p class="mb-4 max-w-[620px] text-[13px] text-(--warn)">{{ $saveMessage }}</p>
            @endif

            @if ($isEditing)
                <div class="max-w-[620px]">
                    <textarea
                        wire:model="editContent"
                        rows="24"
                        class="w-full rounded-[18px] bg-(--surf) p-5 font-mono text-[13px] leading-[1.7] text-(--tx) focus:outline-none"
                    ></textarea>
                    <div class="mt-3 flex justify-end gap-2">
                        <x-btn variant="ghost" wire:click="cancelEditing">Annuler</x-btn>
                        <x-btn variant="primary" wire:click="save">Enregistrer</x-btn>
                    </div>
                </div>
            @else
                <div class="max-w-[620px]">
                    <h1 class="font-display mb-2.5 text-[32px] leading-[1.15] font-bold tracking-[-.03em] text-(--tx)">{{ $this->selectedDocument->title }}</h1>
                    <p class="mb-[26px] text-[13px] text-(--mut)">
                        @if ($this->selectedDocument->mtime)
                            Modifié {{ $this->selectedDocument->mtime->diffForHumans() }} ·
                        @endif
                        {{ number_format(str_word_count((string) $this->selectedDocument->content), 0, ',', ' ') }} mots
                        @if ($this->documentLinks->isNotEmpty())
                            · lié à {{ $this->documentLinks->count() }} {{ Str::plural('objet', $this->documentLinks->count()) }}
                        @endif
                    </p>

                    <div class="brain-content animate-rise-in" wire:key="note-{{ $this->selectedDocument->id }}">
                        {!! $this->renderedContent !!}
                    </div>
                </div>
            @endif
        @endif
    </div>

    {{-- Colonne du curateur : ce qu'il propose, ce qu'il a fait tout seul, et
         ce que la note touche. Tout y porte le vert. --}}
    <div class="hidden w-[316px] shrink-0 py-[26px] pr-[26px] xl:block">
        @error('acceptSuggestion')
            <p class="mb-3.5 text-[13px] text-(--dgr)">{{ $message }}</p>
        @enderror

        <div class="mb-[26px]">
            <div class="mb-3.5 flex items-center gap-2">
                <p class="text-[11px] tracking-[.12em] text-(--ai) uppercase">Conformité</p>
                <x-btn variant="secondary" size="xs" class="ml-auto" wire:click="runConformanceCheck" wire:loading.attr="disabled" wire:target="runConformanceCheck">
                    <span wire:loading wire:target="runConformanceCheck">En cours…</span>
                    <span wire:loading.remove wire:target="runConformanceCheck">Vérifier la conformité</span>
                </x-btn>
            </div>

            @error('conformance')
                <p class="mb-3 text-[13px] text-(--dgr)">{{ $message }}</p>
            @enderror

            @if ($conformanceMessage)
                <p class="mb-3 text-[13px] text-(--mut)">{{ $conformanceMessage }}</p>
            @endif

            @forelse ($this->conformanceBatches as $batchId => $batch)
                <x-agent-block padding="16" rail="3" wire:key="batch-{{ $batchId }}" class="mb-3 !rounded-[16px]">
                    <p class="mb-3 text-[14px] font-semibold text-(--tx)">
                        {{ $batch->count() }} {{ Str::plural('proposition', $batch->count()) }} de mise en conformité
                    </p>

                    <div class="mb-3.5 flex flex-col gap-2.5">
                        @foreach ($batch as $suggestion)
                            <div wire:key="batch-item-{{ $suggestion->id }}">
                                <p class="text-[13px] leading-[1.5] text-(--tx)">
                                    <span class="font-semibold">{{ $suggestion->action->label() }}</span> — {{ $suggestion->reason }}
                                </p>
                                <p class="font-mono text-[12px] text-(--mut)">
                                    {{ $suggestion->brainDocument?->path }}@if ($suggestion->target) → {{ $suggestion->target }}@endif
                                </p>
                                <div class="mt-1.5 flex gap-2">
                                    <x-btn variant="secondary" size="xs" wire:click="acceptSuggestion({{ $suggestion->id }})">Accepter</x-btn>
                                    <x-btn variant="ghost" size="xs" wire:click="rejectSuggestion({{ $suggestion->id }})">Refuser</x-btn>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-2 border-t border-(--bd) pt-3">
                        <x-btn variant="agent" size="xs" wire:click="acceptConformanceBatch('{{ $batchId }}')">Accepter tout le lot</x-btn>
                        <x-btn variant="ghost" size="xs" wire:click="rejectConformanceBatch('{{ $batchId }}')">Refuser tout le lot</x-btn>
                    </div>
                </x-agent-block>
            @empty
                <p class="text-[13px] text-(--mut)">
                    @if ($this->health->conformanceRate >= 100)
                        Tout le cerveau respecte le contrat.
                    @else
                        Aucune proposition en attente.
                    @endif
                </p>
            @endforelse
        </div>

        @if ($this->pendingSuggestions->isNotEmpty())
            <p class="mb-3.5 text-[11px] tracking-[.12em] text-(--ai) uppercase">Le curateur propose</p>
            <div class="mb-[26px] flex flex-col gap-3">
                @foreach ($this->pendingSuggestions as $suggestion)
                    <x-agent-block
                        padding="16"
                        rail="3"
                        wire:key="suggestion-{{ $suggestion->id }}"
                        class="!rounded-[16px] {{ $suggestion->deprioritized_at ? 'opacity-60' : '' }}"
                    >
                        <p class="mb-3 text-[14px] leading-[1.55] text-(--tx)">{{ $suggestion->reason }}</p>
                        <p class="mb-3 font-mono text-[12px] text-(--mut)">{{ $suggestion->brainDocument?->path }}</p>
                        <div class="flex flex-wrap gap-2">
                            <x-btn variant="agent" size="xs" wire:click="acceptSuggestion({{ $suggestion->id }})">Valider</x-btn>
                            <x-btn variant="secondary" size="xs" wire:click="rejectSuggestion({{ $suggestion->id }})">Corriger</x-btn>
                            <x-btn variant="ghost" size="xs" wire:click="deprioritizeSuggestion({{ $suggestion->id }})">Plus tard</x-btn>
                        </div>
                    </x-agent-block>
                @endforeach
            </div>
        @endif

        @if ($this->recentAutoApplied->isNotEmpty())
            <p class="mb-3.5 text-[11px] tracking-[.12em] text-(--mut) uppercase">Auto-appliqué récemment</p>
            <div class="mb-[26px] flex flex-col gap-3">
                @foreach ($this->recentAutoApplied as $suggestion)
                    <div class="flex items-start gap-2.5 text-[13px] text-(--mut)" wire:key="auto-{{ $suggestion->id }}">
                        <span class="mt-[6px] size-[6px] shrink-0 rounded-full bg-(--ai)" aria-hidden="true"></span>
                        <div>
                            {{ $suggestion->reason }}
                            <button type="button" wire:click="cancelAutoApplied({{ $suggestion->id }})" class="ml-2 text-(--ai) hover:underline">Annuler</button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($this->documentLinks->isNotEmpty())
            <p class="mb-3.5 text-[11px] tracking-[.12em] text-(--mut) uppercase">Liens de cette note</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->documentLinks as $link)
                    <a
                        href="{{ $link['href'] }}"
                        wire:navigate
                        wire:key="link-{{ $loop->index }}"
                        class="press rounded-full bg-(--surf) px-3.5 py-[7px] text-[13px] text-(--tx) hover:bg-(--surf2)"
                    >{{ $link['label'] }}</a>
                @endforeach
            </div>
        @endif
    </div>

    <flux:modal wire:model.self="showCreateModal" class="md:w-[420px]">
        <div class="space-y-5">
            <flux:heading size="lg">Nouvelle note</flux:heading>

            <div>
                <label class="text-[12px] text-(--mut)">Type</label>
                <select wire:model.live="newType" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none">
                    @foreach ($this->noteTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('newType') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Titre</label>
                <input type="text" wire:model.live.debounce.400ms="newTitle" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none" />
                @error('newTitle') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            {{-- C'est TARS qui calcule le chemin : Thomas le voit avant
                 d'enregistrer, il ne le choisit pas. --}}
            <p class="font-mono text-[12px] leading-[1.6] text-(--mut)">
                @if ($this->newNotePath)
                    → {{ $this->newNotePath }}
                @else
                    Donne un titre pour voir où la note sera rangée.
                @endif
            </p>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showCreateModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="createNote">Créer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
