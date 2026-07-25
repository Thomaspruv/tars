<?php

use App\Enums\GitSyncStatus;
use App\Models\BrainDocument;
use App\Support\Brain\AnchorResolver;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use App\Support\Brain\VaultIndexer;
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
        @if ($this->configured)
            <x-btn variant="primary" wire:click="openCreateModal">+ Nouvelle note</x-btn>
        @endif
    </div>

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
                        <div class="prose prose-sm mt-4 max-w-none text-(--tx)">
                            {!! $this->renderedContent !!}
                        </div>
                    @endif
                @endif
            </div>
        </div>
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
