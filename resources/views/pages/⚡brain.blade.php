<?php

use App\Enums\BrainSuggestionStatus;
use App\Enums\GitSyncStatus;
use App\Models\BrainDocument;
use App\Models\BrainSuggestion;
use App\Support\Brain\AnchorResolver;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use App\Support\Brain\VaultIndexer;
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

new #[Layout('layouts::reading')] #[Title('Cerveau')] class extends Component
{
    #[Url]
    public ?string $path = null;

    #[Url]
    public string $search = '';

    public bool $isEditing = false;

    public string $editContent = '';

    public bool $showCreateModal = false;

    public string $newFolder = '';

    public string $newTitle = '';

    #[Computed]
    public function configured(): bool
    {
        return app(BrainSettings::class)->isConfigured();
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

        unset($this->pendingSuggestions, $this->tree, $this->folders);
    }

    public function rejectSuggestion(int $suggestionId): void
    {
        BrainSuggestion::where('id', $suggestionId)->update(['status' => BrainSuggestionStatus::Rejected->value]);

        unset($this->pendingSuggestions);
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
        unset($this->selectedDocument, $this->renderedContent, $this->documentLinks);
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

        $settings = app(BrainSettings::class);
        $git = app(GitRepository::class);

        if ($git->status($settings->localPath()) === GitSyncStatus::Conflict) {
            $this->addError('save', "Le dépôt du vault a un conflit git — résous-le avant de sauvegarder.");

            return;
        }

        File::put($this->absolutePath($document->path), $this->editContent);
        $git->commit($settings->localPath(), $document->path, "tars: edit {$document->path}");
        app(VaultIndexer::class)->indexFile($this->absolutePath($document->path));

        $this->isEditing = false;
        unset($this->selectedDocument, $this->renderedContent, $this->tree, $this->folders);
    }

    public function openCreateModal(): void
    {
        $this->reset(['newFolder', 'newTitle']);
        $this->showCreateModal = true;
    }

    public function createNote(): void
    {
        $validated = $this->validate(['newTitle' => ['required', 'string', 'max:255']]);

        $slug = Str::slug($validated['newTitle']);
        $relativePath = ($this->newFolder !== '' ? "{$this->newFolder}/" : '')."{$slug}.md";

        if (File::exists($this->absolutePath($relativePath))) {
            $this->addError('newTitle', 'Une note avec ce nom existe déjà dans ce dossier.');

            return;
        }

        $content = "---\ntype: note\ndate: \"{$this->today()}\"\n---\n\n# {$validated['newTitle']}\n\n";

        $settings = app(BrainSettings::class);
        File::ensureDirectoryExists(dirname($this->absolutePath($relativePath)));
        File::put($this->absolutePath($relativePath), $content);
        app(GitRepository::class)->commit($settings->localPath(), $relativePath, "tars: create {$relativePath}");
        app(VaultIndexer::class)->indexFile($this->absolutePath($relativePath));

        $this->showCreateModal = false;
        $this->path = $relativePath;
        unset($this->selectedDocument, $this->renderedContent, $this->tree, $this->folders);
    }

    private function absolutePath(string $relativePath): string
    {
        return rtrim(app(BrainSettings::class)->localPath(), '/').'/'.$relativePath;
    }

    private function today(): string
    {
        return now()->toDateString();
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

        <div class="mb-4 flex items-center gap-2.5 rounded-full bg-(--surf2) px-3 py-2 text-[14px] font-semibold text-(--tx)">
            Cerveau
            <a href="{{ route('today') }}" wire:navigate class="ml-auto text-[12px] font-normal text-(--mut) hover:text-(--tx)">← retour</a>
        </div>

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
                <x-brain-tree :tree="$this->tree" :selected-path="$path" />
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
        @if (! $this->configured)
            <x-card padding="22" class="max-w-[620px]">
                <p class="mb-2 text-[15px] text-(--mut)">Le vault n'est pas configuré.</p>
                <a href="{{ route('settings.index') }}" wire:navigate class="text-[15px] text-(--ac) hover:text-(--achov)">Le configurer dans les Réglages →</a>
            </x-card>
        @elseif (! $this->selectedDocument)
            <div class="lg:hidden">
                <p class="mb-3.5 font-mono text-[11px] tracking-[.1em] text-(--mut) uppercase">Arborescence</p>
                <x-brain-tree :tree="$this->tree" :selected-path="$path" />
            </div>
            <p class="mt-6 text-[15px] text-(--mut)">Choisis une note dans l'arborescence.</p>
        @else
            <div class="mb-[22px] flex flex-wrap items-center gap-3.5">
                <span class="font-mono text-[12px] text-(--mut)">{{ str_replace('/', ' / ', $this->selectedDocument->path) }}</span>
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
                <label class="text-[12px] text-(--mut)">Dossier</label>
                <select wire:model="newFolder" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 font-mono text-[13px] text-(--tx) focus:outline-none">
                    <option value="">Racine</option>
                    @foreach ($this->folders as $folder)
                        <option value="{{ $folder }}">{{ $folder }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Titre</label>
                <input type="text" wire:model="newTitle" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none" />
                @error('newTitle') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showCreateModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="createNote">Créer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
