<?php

use App\Enums\GitSyncStatus;
use App\Models\BrainDocument;
use App\Models\LifeArea;
use App\Models\McpCall;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use App\Support\Mcp\McpSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Réglages')] class extends Component
{
    private const array PRESET_COLORS = [
        '#53D6E8', '#9D8CF5', '#4ADE80', '#FBBF24', '#F87171', '#60A5FA', '#F472B6', '#8A93A8',
    ];

    public bool $showFormModal = false;

    public ?int $editingLifeAreaId = null;

    public string $name = '';

    public string $color = '#53D6E8';

    public string $brainRemoteUrl = '';

    public string $brainBranch = 'main';

    public int $brainSyncFrequency = 15;

    public bool $brainAutoIndex = true;

    public ?string $brainTestMessage = null;

    public ?bool $brainTestSuccessful = null;

    public ?string $brainSyncMessage = null;

    public ?string $brainReindexMessage = null;

    public bool $mcpEnabled = false;

    public ?string $mcpNewToken = null;

    public string $mcpFilterTool = '';

    public string $mcpFilterStatus = '';

    public function mount(): void
    {
        $settings = app(BrainSettings::class);

        $this->brainRemoteUrl = (string) $settings->remoteUrl();
        $this->brainBranch = $settings->branch();
        $this->brainSyncFrequency = $settings->syncFrequencyMinutes();
        $this->brainAutoIndex = $settings->autoIndexEnabled();

        $this->mcpEnabled = app(McpSettings::class)->isEnabled();
    }

    #[Computed]
    public function lifeAreas(): Collection
    {
        return LifeArea::orderBy('position')->get();
    }

    public function presetColors(): array
    {
        return self::PRESET_COLORS;
    }

    public function openCreateModal(): void
    {
        $this->reset(['name', 'editingLifeAreaId']);
        $this->color = self::PRESET_COLORS[0];
        $this->showFormModal = true;
    }

    public function openEditModal(int $lifeAreaId): void
    {
        $lifeArea = LifeArea::findOrFail($lifeAreaId);

        $this->editingLifeAreaId = $lifeArea->id;
        $this->name = $lifeArea->name;
        $this->color = $lifeArea->color;
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string'],
        ]);

        if ($this->editingLifeAreaId) {
            LifeArea::findOrFail($this->editingLifeAreaId)->update($validated);
        } else {
            LifeArea::create([...$validated, 'position' => $this->lifeAreas->count()]);
        }

        $this->showFormModal = false;
        unset($this->lifeAreas);
    }

    public function delete(int $lifeAreaId): void
    {
        LifeArea::findOrFail($lifeAreaId)->delete();

        unset($this->lifeAreas);
    }

    #[Computed]
    public function brainConfigured(): bool
    {
        return app(BrainSettings::class)->isConfigured();
    }

    /**
     * @return array{lastSynced: ?Carbon, lastIndexed: ?Carbon, documentsCount: int, gitStatus: GitSyncStatus}
     */
    #[Computed]
    public function brainStatus(): array
    {
        $settings = app(BrainSettings::class);

        return [
            'lastSynced' => $settings->lastSyncedAt(),
            'lastIndexed' => $settings->lastIndexedAt(),
            'documentsCount' => BrainDocument::count(),
            'gitStatus' => app(GitRepository::class)->status($settings->localPath()),
        ];
    }

    public function saveBrainSettings(): void
    {
        $validated = $this->validate([
            'brainRemoteUrl' => ['nullable', 'string', 'max:255'],
            'brainBranch' => ['required', 'string', 'max:255'],
            'brainSyncFrequency' => ['required', 'integer', 'min:1'],
            'brainAutoIndex' => ['boolean'],
        ]);

        app(BrainSettings::class)->updateConfiguration([
            'remote_url' => $validated['brainRemoteUrl'] ?: null,
            'branch' => $validated['brainBranch'],
            'sync_frequency_minutes' => $validated['brainSyncFrequency'],
            'auto_index' => $validated['brainAutoIndex'],
        ]);

        unset($this->brainConfigured, $this->brainStatus);
    }

    public function testBrainConnection(): void
    {
        $this->brainTestSuccessful = app(GitRepository::class)->lsRemote($this->brainRemoteUrl);
        $this->brainTestMessage = $this->brainTestSuccessful
            ? 'Connexion au dépôt réussie.'
            : 'Impossible de joindre le dépôt distant.';
    }

    public function syncBrainNow(): void
    {
        $exitCode = Artisan::call('brain:sync');

        $this->brainSyncMessage = $exitCode === 0
            ? trim(Artisan::output())
            : 'Échec de la synchronisation — voir les logs.';

        unset($this->brainStatus);
    }

    public function reindexBrainFull(): void
    {
        Artisan::call('brain:index', ['--fresh' => true]);

        $this->brainReindexMessage = trim(Artisan::output());

        unset($this->brainStatus);
    }

    #[Computed]
    public function mcpEndpointUrl(): string
    {
        return app(McpSettings::class)->endpointUrl();
    }

    #[Computed]
    public function mcpHasToken(): bool
    {
        return app(McpSettings::class)->hasToken();
    }

    /**
     * @return array{lastCallAt: ?Carbon, callsLast7Days: int}
     */
    #[Computed]
    public function mcpStatus(): array
    {
        return [
            'lastCallAt' => McpCall::latest('id')->value('created_at'),
            'callsLast7Days' => McpCall::where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    #[Computed]
    public function mcpTools(): \Illuminate\Support\Collection
    {
        return McpCall::query()->distinct()->orderBy('tool')->pluck('tool');
    }

    /**
     * @return Collection<int, McpCall>
     */
    #[Computed]
    public function mcpCalls(): Collection
    {
        return McpCall::query()
            ->when($this->mcpFilterTool !== '', fn ($query) => $query->where('tool', $this->mcpFilterTool))
            ->when($this->mcpFilterStatus !== '', fn ($query) => $query->where('status', $this->mcpFilterStatus))
            ->latest('id')
            ->limit(50)
            ->get();
    }

    public function updatedMcpEnabled(bool $value): void
    {
        app(McpSettings::class)->setEnabled($value);
    }

    public function generateMcpToken(): void
    {
        $this->mcpNewToken = app(McpSettings::class)->generateToken();

        unset($this->mcpHasToken);
    }
};
?>

<div>
    <h1 class="text-[28px] font-bold tracking-[-0.02em] text-(--tx)">Réglages</h1>

    <div class="mt-8">
        <div class="flex items-center justify-between">
            <h2 class="text-base font-semibold text-(--tx)">Domaines de vie</h2>
            <x-btn variant="primary" wire:click="openCreateModal">+ Nouveau domaine</x-btn>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            @forelse ($this->lifeAreas as $lifeArea)
                <div class="flex items-center gap-2 rounded-full border border-(--bd) bg-(--surf) px-3 py-1.5" wire:key="area-{{ $lifeArea->id }}">
                    <span class="size-2.5 rounded-[3px]" style="background: {{ $lifeArea->color }}"></span>
                    <span class="text-sm text-(--tx)">{{ $lifeArea->name }}</span>
                    <button type="button" wire:click="openEditModal({{ $lifeArea->id }})" class="text-(--mut) hover:text-(--tx)">
                        <flux:icon name="pencil" class="size-3.5" />
                    </button>
                    <button
                        type="button"
                        wire:click="delete({{ $lifeArea->id }})"
                        wire:confirm="Supprimer ce domaine de vie ?"
                        class="text-(--mut) hover:text-(--dgr)"
                    >
                        <flux:icon name="x-mark" class="size-3.5" />
                    </button>
                </div>
            @empty
                <p class="text-sm text-(--mut)">Aucun domaine de vie pour l'instant.</p>
            @endforelse
        </div>
    </div>

    <div class="mt-8">
        <h2 class="text-base font-semibold text-(--tx)">Cerveau</h2>

        <div class="mt-4 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <form wire:submit="saveBrainSettings" class="space-y-4 rounded-[14px] border border-(--bd) bg-(--surf) p-5">
                <div>
                    <label class="text-xs text-(--mut)">URL du remote git</label>
                    <input
                        type="text"
                        wire:model="brainRemoteUrl"
                        placeholder="git@example.com:vault.git"
                        class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)"
                    />
                    @error('brainRemoteUrl') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="text-xs text-(--mut)">Branche</label>
                    <input type="text" wire:model="brainBranch" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)" />
                    @error('brainBranch') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="text-xs text-(--mut)">Fréquence de sync (minutes)</label>
                    <input type="number" min="1" wire:model="brainSyncFrequency" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)" />
                    @error('brainSyncFrequency') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-(--tx)">
                    <input type="checkbox" wire:model="brainAutoIndex" class="rounded-[4px] border-(--bd2) text-(--ac) focus:ring-0" />
                    Indexation automatique
                </label>

                <div class="flex flex-wrap items-center justify-end gap-2 pt-1">
                    @if ($brainTestMessage)
                        <p class="mr-auto text-xs {{ $brainTestSuccessful ? 'text-(--ok)' : 'text-(--dgr)' }}">{{ $brainTestMessage }}</p>
                    @endif
                    <x-btn type="button" variant="secondary" wire:click="testBrainConnection">Tester la connexion</x-btn>
                    <x-btn type="submit" variant="primary">Enregistrer</x-btn>
                </div>
            </form>

            <div class="space-y-4 rounded-[14px] border border-(--bd) bg-(--surf) p-5">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-(--mut)">Statut</span>
                    <x-badge-status :status="$this->brainStatus['gitStatus']->value" />
                </div>

                <dl class="space-y-2 font-mono text-xs text-(--mut)">
                    <div class="flex justify-between">
                        <dt>Dernier pull</dt>
                        <dd class="text-(--tx)">{{ $this->brainStatus['lastSynced']?->translatedFormat('d M · H:i') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Dernière indexation</dt>
                        <dd class="text-(--tx)">{{ $this->brainStatus['lastIndexed']?->translatedFormat('d M · H:i') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Documents indexés</dt>
                        <dd class="text-(--tx)">{{ $this->brainStatus['documentsCount'] }}</dd>
                    </div>
                </dl>

                <div class="flex flex-wrap gap-2 pt-1">
                    <x-btn variant="secondary" wire:click="syncBrainNow">Synchroniser maintenant</x-btn>
                    <x-btn variant="secondary" wire:click="reindexBrainFull" wire:confirm="Reconstruire tout l'index depuis zéro ?">Réindexer tout</x-btn>
                </div>

                @if ($brainSyncMessage)
                    <p class="text-xs text-(--mut)">{{ $brainSyncMessage }}</p>
                @endif
                @if ($brainReindexMessage)
                    <p class="text-xs text-(--mut)">{{ $brainReindexMessage }}</p>
                @endif
            </div>
        </div>
    </div>

    <div class="mt-8">
        <h2 class="text-base font-semibold text-(--tx)">Connexions — MCP / Hermes</h2>

        <div class="mt-4 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="space-y-4 rounded-[14px] border border-(--bd) bg-(--surf) p-5">
                <label class="flex items-center gap-2 text-sm text-(--tx)">
                    <input type="checkbox" wire:model.live="mcpEnabled" class="rounded-[4px] border-(--bd2) text-(--ac) focus:ring-0" />
                    Activer le serveur MCP
                </label>

                <div>
                    <label class="text-xs text-(--mut)">URL de l'endpoint</label>
                    <input
                        type="text"
                        readonly
                        onclick="this.select()"
                        value="{{ $this->mcpEndpointUrl }}"
                        class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)"
                    />
                </div>

                <div>
                    <label class="text-xs text-(--mut)">Token</label>
                    @if ($mcpNewToken)
                        <input
                            type="text"
                            readonly
                            onclick="this.select()"
                            value="{{ $mcpNewToken }}"
                            class="mt-1 w-full rounded-[8px] border border-(--ac) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)"
                        />
                        <p class="mt-1 text-xs text-(--warn)">Ce token ne sera plus jamais affiché en clair — copie-le maintenant.</p>
                    @else
                        <input
                            type="text"
                            readonly
                            value="{{ $this->mcpHasToken ? '••••••••••••••••' : 'Aucun token généré' }}"
                            class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--mut)"
                        />
                    @endif
                </div>

                <div class="flex justify-end pt-1">
                    @php
                        $mcpConfirmMessage = $this->mcpHasToken
                            ? "Régénérer le token révoque immédiatement l'ancien. Continuer ?"
                            : 'Générer un nouveau token ?';
                    @endphp
                    <x-btn variant="secondary" wire:click="generateMcpToken" wire:confirm="{{ $mcpConfirmMessage }}">
                        {{ $this->mcpHasToken ? 'Régénérer le token' : 'Générer un token' }}
                    </x-btn>
                </div>
            </div>

            <div class="space-y-4 rounded-[14px] border border-(--bd) bg-(--surf) p-5">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-(--mut)">Statut</span>
                    <span class="inline-flex items-center gap-1.5 font-mono text-[10.5px] text-(--mut)">
                        <span class="size-1.5 rounded-full {{ $this->mcpStatus['lastCallAt'] ? 'bg-(--ok)' : 'bg-(--bd2)' }}"></span>
                        {{ $this->mcpStatus['lastCallAt'] ? 'Dernier appel '.$this->mcpStatus['lastCallAt']->diffForHumans() : 'Aucun appel' }}
                    </span>
                </div>

                <dl class="space-y-2 font-mono text-xs text-(--mut)">
                    <div class="flex justify-between">
                        <dt>Appels (7 jours)</dt>
                        <dd class="text-(--tx)">{{ $this->mcpStatus['callsLast7Days'] }}</dd>
                    </div>
                </dl>

                <div class="flex flex-wrap gap-2 pt-1">
                    <select wire:model.live="mcpFilterTool" class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)">
                        <option value="">Tous les outils</option>
                        @foreach ($this->mcpTools as $tool)
                            <option value="{{ $tool }}">{{ $tool }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="mcpFilterStatus" class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)">
                        <option value="">Tous les statuts</option>
                        @foreach (\App\Enums\McpCallStatus::cases() as $status)
                            <option value="{{ $status->value }}">{{ $status->value }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="max-h-72 space-y-1 overflow-y-auto">
                    @forelse ($this->mcpCalls as $call)
                        <div class="flex items-center gap-2 rounded-[8px] px-2 py-1.5 hover:bg-(--surf2)" wire:key="mcp-call-{{ $call->id }}">
                            <span class="font-mono text-[10.5px] text-(--tx)">{{ $call->tool }}</span>
                            <x-badge-status :status="$call->status->value" />
                            <span class="font-mono text-[10.5px] text-(--mut)">{{ $call->duration_ms }}ms</span>
                            <span class="ml-auto truncate text-xs text-(--mut)">{{ Str::limit($call->result_summary ?? $call->error_message, 60) }}</span>
                        </div>
                    @empty
                        <p class="p-4 text-center text-sm text-(--mut)">Aucun appel pour l'instant.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <flux:modal wire:model.self="showFormModal" class="md:w-[380px]">
        <div class="space-y-5">
            <flux:heading size="lg">{{ $editingLifeAreaId ? 'Modifier le domaine' : 'Nouveau domaine' }}</flux:heading>

            <div>
                <label class="text-xs text-(--mut)">Nom</label>
                <input type="text" wire:model="name" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)" />
                @error('name') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-xs text-(--mut)">Couleur</label>
                <div class="mt-2 flex gap-2">
                    @foreach ($this->presetColors() as $preset)
                        <button
                            type="button"
                            wire:click="$set('color', '{{ $preset }}')"
                            class="size-7 rounded-full {{ $color === $preset ? 'ring-2 ring-(--tx) ring-offset-2 ring-offset-(--surf)' : '' }}"
                            style="background: {{ $preset }}"
                            aria-label="{{ $preset }}"
                        ></button>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showFormModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="save">Enregistrer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
