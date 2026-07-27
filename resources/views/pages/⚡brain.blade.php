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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Cerveau')] class extends Component
{
    #[Url]
    public ?string $path = null;

    #[Url]
    public string $search = '';

    #[Url]
    public string $activeTab = 'browse';

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

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
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
        unset($this->selectedDocument, $this->renderedContent);
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

<div>
    <div class="flex items-center justify-between">
        <h1 class="text-[28px] font-bold tracking-[-0.02em] text-(--tx)">Cerveau</h1>
        @if ($this->configured && $activeTab === 'browse')
            <x-btn variant="primary" wire:click="openCreateModal">+ Nouvelle note</x-btn>
        @endif
    </div>

    <div class="mt-4 flex gap-1 border-b border-(--bd)">
        <button
            type="button"
            wire:click="switchTab('browse')"
            class="border-b-2 px-3 py-2 text-sm font-medium transition-colors {{ $activeTab === 'browse' ? 'border-(--ac) text-(--ac)' : 'border-transparent text-(--mut) hover:text-(--tx)' }}"
        >
            Parcourir
        </button>
        <button
            type="button"
            wire:click="switchTab('rangement')"
            class="flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition-colors {{ $activeTab === 'rangement' ? 'border-(--ai) text-(--ai)' : 'border-transparent text-(--mut) hover:text-(--tx)' }}"
        >
            Rangement
            @if ($this->pendingSuggestions->isNotEmpty())
                <span class="rounded-full bg-(--aibg) px-1.5 py-0.5 font-mono text-[10.5px] font-semibold text-(--ai)">{{ $this->pendingSuggestions->count() }}</span>
            @endif
        </button>
    </div>

    @if ($activeTab === 'rangement')
        <div class="mt-6 space-y-8">
            @error('acceptSuggestion')
                <p class="rounded-[8px] border border-(--dgrbg) bg-(--dgrbg) px-3 py-2 text-sm text-(--dgr)">{{ $message }}</p>
            @enderror

            <div class="space-y-3">
                @forelse ($this->pendingSuggestions as $suggestion)
                    <div class="rounded-[14px] border border-(--bd) bg-(--surf) p-4 {{ $suggestion->deprioritized_at ? 'opacity-60' : '' }}" wire:key="suggestion-{{ $suggestion->id }}">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm text-(--tx)">{{ $suggestion->reason }}</p>
                                <p class="mt-1 font-mono text-[10.5px] text-(--mut)">{{ $suggestion->brainDocument->path }}</p>
                            </div>
                            <x-badge-status :status="$suggestion->confidence->value === 'high' ? 'active' : 'paused'" :label="$suggestion->confidence->value" />
                        </div>

                        <details class="mt-2">
                            <summary class="cursor-pointer text-xs text-(--mut) hover:text-(--tx)">Détail</summary>
                            <dl class="mt-2 space-y-1 rounded-[8px] bg-(--surf2) p-3 text-xs">
                                <div class="flex gap-2"><dt class="text-(--mut)">Action</dt><dd class="text-(--tx)">{{ $suggestion->action->label() }}</dd></div>
                                @if ($suggestion->target)
                                    <div class="flex gap-2"><dt class="text-(--mut)">Cible</dt><dd class="text-(--tx)">{{ $suggestion->target }}</dd></div>
                                @endif
                                @if ($suggestion->frontmatter_patch)
                                    <div class="flex gap-2"><dt class="text-(--mut)">Frontmatter</dt><dd class="text-(--tx)">{{ json_encode($suggestion->frontmatter_patch) }}</dd></div>
                                @endif
                                @if ($suggestion->merged_content)
                                    <div class="flex gap-2"><dt class="shrink-0 text-(--mut)">Contenu fusionné</dt><dd class="whitespace-pre-wrap text-(--tx)">{{ $suggestion->merged_content }}</dd></div>
                                @endif
                            </dl>
                        </details>

                        <div class="mt-3 flex justify-end gap-2">
                            <x-btn variant="ghost" class="!px-3 !py-1.5 text-xs" wire:click="deprioritizeSuggestion({{ $suggestion->id }})">Plus tard</x-btn>
                            <x-btn variant="secondary" class="!px-3 !py-1.5 text-xs" wire:click="rejectSuggestion({{ $suggestion->id }})">Refuser</x-btn>
                            <x-btn variant="primary" class="!px-3 !py-1.5 text-xs" wire:click="acceptSuggestion({{ $suggestion->id }})">Accepter</x-btn>
                        </div>
                    </div>
                @empty
                    <p class="rounded-[14px] border border-(--bd) bg-(--surf) p-8 text-center text-sm text-(--mut)">Aucune suggestion en attente.</p>
                @endforelse
            </div>

            @if ($this->recentAutoApplied->isNotEmpty())
                <div>
                    <h2 class="text-sm font-semibold text-(--tx)">Auto-appliqué récemment</h2>
                    <div class="mt-3 space-y-2">
                        @foreach ($this->recentAutoApplied as $suggestion)
                            <div class="flex items-center justify-between gap-4 rounded-[10px] border border-(--ai)/25 bg-(--aibg) px-3 py-2" wire:key="auto-{{ $suggestion->id }}">
                                <div>
                                    <p class="text-xs text-(--tx)">◈ {{ $suggestion->reason }}</p>
                                    <p class="font-mono text-[10.5px] text-(--mut)">{{ $suggestion->brainDocument->path }} — {{ $suggestion->created_at->translatedFormat('d M · H:i') }}</p>
                                </div>
                                <x-btn variant="ghost" class="!px-3 !py-1.5 text-xs" wire:click="cancelAutoApplied({{ $suggestion->id }})">Annuler</x-btn>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if ($activeTab === 'browse')
    @if (! $this->configured)
        <div class="mt-8 rounded-[14px] border border-(--bd) bg-(--surf) p-8 text-center">
            <p class="text-sm text-(--mut)">Vault non configuré.</p>
            <a href="{{ route('settings.index') }}" wire:navigate class="mt-2 inline-block text-sm text-(--ac) hover:text-(--achov)">
                Configurer le vault dans les Réglages →
            </a>
        </div>
    @else
        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[260px_1fr]">
            <div class="space-y-4">
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Rechercher…"
                    class="w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-1.5 text-sm text-(--tx)"
                />

                @if (trim($search) !== '')
                    <div class="space-y-0.5">
                        @forelse ($this->searchResults as $result)
                            <button
                                type="button"
                                wire:click="selectDocument('{{ $result->path }}')"
                                class="block w-full truncate rounded-[6px] px-2 py-1 text-left text-sm {{ $path === $result->path ? 'bg-(--acbg) text-(--ac)' : 'text-(--mut) hover:bg-(--surf2) hover:text-(--tx)' }}"
                            >
                                {{ $result->title }}
                            </button>
                        @empty
                            <p class="px-2 py-1 text-xs text-(--mut)">Aucun résultat.</p>
                        @endforelse
                    </div>
                @else
                    <x-brain-tree :tree="$this->tree" :selected-path="$path" />
                @endif
            </div>

            <div>
                @if (! $this->selectedDocument)
                    <div class="rounded-[14px] border border-(--bd) bg-(--surf) p-8 text-center">
                        <p class="text-sm text-(--mut)">Sélectionne une note dans l'arborescence ou la recherche.</p>
                    </div>
                @else
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-xl font-semibold text-(--tx)">{{ $this->selectedDocument->title }}</h2>
                            <p class="mt-1 font-mono text-[10.5px] text-(--mut)">{{ $this->selectedDocument->path }}</p>
                        </div>
                        @unless ($isEditing)
                            <x-btn variant="secondary" wire:click="startEditing">Éditer</x-btn>
                        @endunless
                    </div>

                    @error('save')
                        <p class="mt-3 rounded-[8px] border border-(--dgrbg) bg-(--dgrbg) px-3 py-2 text-sm text-(--dgr)">{{ $message }}</p>
                    @enderror

                    @if ($isEditing)
                        <textarea
                            wire:model="editContent"
                            rows="24"
                            class="mt-4 w-full rounded-[10px] border border-(--bd2) bg-(--in) p-4 font-mono text-sm text-(--tx)"
                        ></textarea>
                        <div class="mt-3 flex justify-end gap-2">
                            <x-btn variant="ghost" wire:click="cancelEditing">Annuler</x-btn>
                            <x-btn variant="primary" wire:click="save">Enregistrer</x-btn>
                        </div>
                    @else
                        <div class="brain-content mt-4">
                            {!! $this->renderedContent !!}
                        </div>
                    @endif
                @endif
            </div>
        </div>
    @endif
    @endif

    <flux:modal wire:model.self="showCreateModal" class="md:w-[420px]">
        <div class="space-y-5">
            <flux:heading size="lg">Nouvelle note</flux:heading>

            <div>
                <label class="text-xs text-(--mut)">Dossier</label>
                <select wire:model="newFolder" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)">
                    <option value="">Racine</option>
                    @foreach ($this->folders as $folder)
                        <option value="{{ $folder }}">{{ $folder }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-xs text-(--mut)">Titre</label>
                <input type="text" wire:model="newTitle" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)" />
                @error('newTitle') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showCreateModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="createNote">Créer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
