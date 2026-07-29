<?php

use App\Enums\GitSyncStatus;
use App\Models\AiProvider;
use App\Models\BrainDocument;
use App\Models\McpCall;
use App\Support\Agents\AgentRunner;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use App\Support\Mcp\McpSettings;
use App\Support\Review\ReviewSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Réglages')] class extends Component
{
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

    public bool $showProviderModal = false;

    public ?int $editingProviderId = null;

    public string $providerName = '';

    public string $providerApiKey = '';

    public string $providerBaseUrl = '';

    public bool $providerIsActive = true;

    /** @var array<int, array{success: bool, message: string}> */
    public array $providerTestResults = [];

    public string $reviewWeeklyTime = '17:00';

    public string $reviewNotificationEmail = '';

    public bool $reviewNotificationsEnabled = false;

    public bool $brainSettingsSaved = false;

    public bool $reviewSettingsSaved = false;

    public function mount(): void
    {
        $settings = app(BrainSettings::class);

        $this->brainRemoteUrl = (string) $settings->remoteUrl();
        $this->brainBranch = $settings->branch();
        $this->brainSyncFrequency = $settings->syncFrequencyMinutes();
        $this->brainAutoIndex = $settings->autoIndexEnabled();

        $this->mcpEnabled = app(McpSettings::class)->isEnabled();

        $reviewSettings = app(ReviewSettings::class);
        $this->reviewWeeklyTime = $reviewSettings->weeklyTime();
        $this->reviewNotificationEmail = (string) $reviewSettings->notificationEmail();
        $this->reviewNotificationsEnabled = $reviewSettings->notificationsEnabled();
    }

    public function saveReviewSettings(): void
    {
        $this->reviewSettingsSaved = false;

        $validated = $this->validate([
            'reviewWeeklyTime' => ['required', 'date_format:H:i'],
            'reviewNotificationEmail' => ['nullable', 'email'],
            'reviewNotificationsEnabled' => ['boolean'],
        ]);

        $settings = app(ReviewSettings::class);
        $settings->updateWeeklyTime($validated['reviewWeeklyTime']);
        $settings->updateNotifications($validated['reviewNotificationEmail'] ?: null, $validated['reviewNotificationsEnabled']);

        $this->reviewSettingsSaved = true;
    }

    #[Computed]
    public function aiProviders(): Collection
    {
        return AiProvider::orderBy('name')->get();
    }

    public function openCreateProviderModal(): void
    {
        $this->reset(['providerName', 'providerApiKey', 'providerBaseUrl', 'editingProviderId']);
        $this->providerIsActive = true;
        $this->showProviderModal = true;
    }

    public function openEditProviderModal(int $providerId): void
    {
        $provider = AiProvider::findOrFail($providerId);

        $this->editingProviderId = $provider->id;
        $this->providerName = $provider->name;
        $this->providerApiKey = '';
        $this->providerBaseUrl = (string) $provider->base_url;
        $this->providerIsActive = $provider->is_active;
        $this->showProviderModal = true;
    }

    public function saveProvider(): void
    {
        $validated = $this->validate([
            'providerName' => ['required', 'string', 'max:255'],
            'providerApiKey' => [$this->editingProviderId ? 'nullable' : 'required', 'string'],
            'providerBaseUrl' => ['nullable', 'string', 'max:255'],
            'providerIsActive' => ['boolean'],
        ]);

        $attributes = [
            'name' => $validated['providerName'],
            'base_url' => $validated['providerBaseUrl'] ?: null,
            'is_active' => $validated['providerIsActive'],
        ];

        if (filled($validated['providerApiKey'])) {
            $attributes['api_key'] = $validated['providerApiKey'];
        }

        if ($this->editingProviderId) {
            AiProvider::findOrFail($this->editingProviderId)->update($attributes);
        } else {
            AiProvider::create([...$attributes, 'api_key' => $validated['providerApiKey']]);
        }

        $this->showProviderModal = false;
        unset($this->aiProviders);
    }

    public function deleteProvider(int $providerId): void
    {
        $provider = AiProvider::findOrFail($providerId);

        if ($provider->agentConfigs()->exists()) {
            $this->addError('deleteProvider', "Impossible de supprimer « {$provider->name} » : au moins un agent l'utilise encore. Change son fournisseur dans l'écran Agents d'abord.");

            return;
        }

        $provider->delete();

        unset($this->aiProviders);
    }

    public function testProviderConnection(int $providerId): void
    {
        $provider = AiProvider::findOrFail($providerId);
        $success = app(AgentRunner::class)->testConnection($provider);

        $this->providerTestResults[$providerId] = [
            'success' => $success,
            'message' => $success ? 'Connexion réussie.' : 'Échec de la connexion.',
        ];
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
        $this->brainSettingsSaved = false;

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

        $this->brainSettingsSaved = true;
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
     * @return Illuminate\Support\Collection<int, string>
     */
    #[Computed]
    public function mcpTools(): Illuminate\Support\Collection
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
    <x-screen-header treatment="work" title="Réglages" />

    <div class="mt-8">
        <h2 class="text-base font-semibold text-(--tx)">Cerveau</h2>

        <div class="mt-4 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <form wire:submit="saveBrainSettings" class="space-y-4 rounded-[12px] border border-(--bd) bg-(--surf) p-5" wire:loading.class="opacity-50" wire:target="saveBrainSettings">
                <div>
                    <label class="text-xs text-(--mut)">URL du remote git</label>
                    <input
                        type="text"
                        wire:model="brainRemoteUrl"
                        wire:loading.attr="disabled"
                        wire:target="saveBrainSettings"
                        placeholder="git@example.com:vault.git"
                        class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)"
                    />
                    @error('brainRemoteUrl') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="text-xs text-(--mut)">Branche</label>
                    <input type="text" wire:model="brainBranch" wire:loading.attr="disabled" wire:target="saveBrainSettings" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)" />
                    @error('brainBranch') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="text-xs text-(--mut)">Fréquence de sync (minutes)</label>
                    <input type="number" min="1" wire:model="brainSyncFrequency" wire:loading.attr="disabled" wire:target="saveBrainSettings" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)" />
                    @error('brainSyncFrequency') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-(--tx)">
                    <input type="checkbox" wire:model="brainAutoIndex" wire:loading.attr="disabled" wire:target="saveBrainSettings" class="rounded-[4px] border-(--bd2) text-(--ac) focus:ring-0" />
                    Indexation automatique
                </label>

                <div class="flex flex-wrap items-center justify-end gap-2 pt-1">
                    @if ($brainTestMessage)
                        <p class="mr-auto text-xs {{ $brainTestSuccessful ? 'text-(--ok)' : 'text-(--dgr)' }}">{{ $brainTestMessage }}</p>
                    @endif
                    @if ($brainSettingsSaved)
                        <p class="text-xs text-(--ok)" wire:loading.remove wire:target="saveBrainSettings">✓ Enregistré</p>
                    @endif
                    <x-btn type="button" variant="secondary" wire:click="testBrainConnection" wire:loading.attr="disabled" wire:target="saveBrainSettings">Tester la connexion</x-btn>
                    <x-btn type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveBrainSettings">
                        <span wire:loading wire:target="saveBrainSettings">Enregistrement…</span>
                        <span wire:loading.remove wire:target="saveBrainSettings">Enregistrer</span>
                    </x-btn>
                </div>
            </form>

            <div class="space-y-4 rounded-[12px] border border-(--bd) bg-(--surf) p-5">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-(--mut)">Statut</span>
                    <x-badge type="status" :value="$this->brainStatus['gitStatus']->value" />
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
            <div class="space-y-4 rounded-[12px] border border-(--bd) bg-(--surf) p-5">
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

            <div class="space-y-4 rounded-[12px] border border-(--bd) bg-(--surf) p-5">
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
                            <x-badge type="status" :value="$call->status->value" />
                            <span class="font-mono text-[10.5px] text-(--mut)">{{ $call->duration_ms }}ms</span>
                            <span class="ml-auto truncate text-xs text-(--mut)">{{ Str::limit($call->result_summary ?? $call->error_message, 60) }}</span>
                        </div>
                    @empty
                        <p class="p-4 text-center text-sm text-(--mut)">Aucun appel pour l'instant.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="mt-4 rounded-[12px] border border-(--bd) bg-(--surf) p-5">
            <h3 class="text-xs font-semibold text-(--mut)">Configuration côté Hermes</h3>
            <dl class="mt-3 space-y-1.5 font-mono text-xs text-(--tx)">
                <div>URL : {{ $this->mcpEndpointUrl }}</div>
                <div>Header : Authorization: Bearer &lt;token&gt;</div>
                <div class="text-(--mut)">
                    Depuis un conteneur Docker sur ce même VPS, utilise <span class="text-(--tx)">172.17.0.1</span> à la place du domaine ;
                    le domaine public reste nécessaire si Hermes tourne ailleurs.
                </div>
            </dl>
        </div>
    </div>

    <div class="mt-8">
        <div class="flex items-center justify-between">
            <h2 class="text-base font-semibold text-(--tx)">Fournisseurs IA</h2>
            <x-btn variant="primary" wire:click="openCreateProviderModal">+ Nouveau fournisseur</x-btn>
        </div>

        @error('deleteProvider') <p class="mt-2 text-xs text-(--dgr)">{{ $message }}</p> @enderror

        <div class="mt-4 divide-y divide-(--bd) rounded-[12px] border border-(--bd) bg-(--surf)">
            @forelse ($this->aiProviders as $provider)
                <div class="flex items-center gap-3 px-4 py-3" wire:key="provider-{{ $provider->id }}">
                    <div class="flex-1">
                        <p class="text-sm font-medium text-(--tx)">{{ $provider->name }}</p>
                        @if ($provider->base_url)
                            <p class="font-mono text-[10.5px] text-(--mut)">{{ $provider->base_url }}</p>
                        @endif
                    </div>
                    <x-badge type="status" :value="$provider->is_active ? 'active' : 'paused'" />
                    @if (isset($this->providerTestResults[$provider->id]))
                        <span class="text-xs {{ $this->providerTestResults[$provider->id]['success'] ? 'text-(--ok)' : 'text-(--dgr)' }}">
                            {{ $this->providerTestResults[$provider->id]['message'] }}
                        </span>
                    @endif
                    <x-btn variant="secondary" class="!px-3 !py-1.5 text-xs" wire:click="testProviderConnection({{ $provider->id }})">
                        Tester la connexion
                    </x-btn>
                    <button type="button" wire:click="openEditProviderModal({{ $provider->id }})" class="text-(--mut) hover:text-(--tx)">
                        <flux:icon name="pencil" class="size-3.5" />
                    </button>
                    <button
                        type="button"
                        wire:click="deleteProvider({{ $provider->id }})"
                        wire:confirm="Supprimer ce fournisseur ?"
                        class="text-(--mut) hover:text-(--dgr)"
                    >
                        <flux:icon name="x-mark" class="size-3.5" />
                    </button>
                </div>
            @empty
                <p class="p-5 text-center text-sm text-(--mut)">Aucun fournisseur pour l'instant.</p>
            @endforelse
        </div>
    </div>

    <div class="mt-8">
        <h2 class="text-base font-semibold text-(--tx)">Revue</h2>

        <div class="mt-4 space-y-3 rounded-[12px] border border-(--bd) bg-(--surf) p-4" wire:loading.class="opacity-50" wire:target="saveReviewSettings">
            <div class="flex items-center gap-3">
                <label class="text-sm text-(--tx)">Heure de la revue hebdomadaire (dimanche)</label>
                <input
                    type="time"
                    wire:model="reviewWeeklyTime"
                    wire:loading.attr="disabled"
                    wire:target="saveReviewSettings"
                    class="rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-1.5 font-mono text-sm text-(--tx)"
                />
            </div>
            @error('reviewWeeklyTime') <p class="text-xs text-(--dgr)">{{ $message }}</p> @enderror

            <div class="flex items-center gap-3">
                <label class="w-64 text-sm text-(--tx)">Email de notification</label>
                <input
                    type="email"
                    wire:model="reviewNotificationEmail"
                    wire:loading.attr="disabled"
                    wire:target="saveReviewSettings"
                    placeholder="toi@exemple.com"
                    class="flex-1 rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-1.5 text-sm text-(--tx)"
                />
            </div>
            @error('reviewNotificationEmail') <p class="text-xs text-(--dgr)">{{ $message }}</p> @enderror

            <label class="flex items-center gap-2 text-sm text-(--tx)">
                <input type="checkbox" wire:model="reviewNotificationsEnabled" wire:loading.attr="disabled" wire:target="saveReviewSettings" class="rounded-[4px] border-(--bd2) text-(--ac) focus:ring-0" />
                Recevoir un email quand une revue est générée
            </label>

            <div class="flex items-center justify-end gap-2">
                @if ($reviewSettingsSaved)
                    <p class="text-xs text-(--ok)" wire:loading.remove wire:target="saveReviewSettings">✓ Enregistré</p>
                @endif
                <x-btn variant="secondary" class="!px-3 !py-1.5 text-xs" wire:click="saveReviewSettings" wire:loading.attr="disabled" wire:target="saveReviewSettings">
                    <span wire:loading wire:target="saveReviewSettings">Enregistrement…</span>
                    <span wire:loading.remove wire:target="saveReviewSettings">Enregistrer</span>
                </x-btn>
            </div>
        </div>
    </div>

    <flux:modal wire:model.self="showProviderModal" class="md:w-[420px]">
        <div class="space-y-5" wire:loading.class="opacity-50" wire:target="saveProvider">
            <flux:heading size="lg">{{ $editingProviderId ? 'Modifier le fournisseur' : 'Nouveau fournisseur' }}</flux:heading>

            <div>
                <label class="text-xs text-(--mut)">Nom</label>
                <input
                    type="text"
                    wire:model="providerName"
                    wire:loading.attr="disabled"
                    wire:target="saveProvider"
                    placeholder="anthropic, openai, deepseek…"
                    class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)"
                />
                @error('providerName') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-xs text-(--mut)">Clé API</label>
                <input
                    type="password"
                    wire:model="providerApiKey"
                    wire:loading.attr="disabled"
                    wire:target="saveProvider"
                    placeholder="{{ $editingProviderId ? 'Laisser vide pour conserver la clé actuelle' : '' }}"
                    class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)"
                />
                @error('providerApiKey') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-xs text-(--mut)">Base URL — requis pour tout fournisseur autre qu'OpenAI ou Anthropic (ex. DeepSeek : https://api.deepseek.com)</label>
                <input
                    type="text"
                    wire:model="providerBaseUrl"
                    wire:loading.attr="disabled"
                    wire:target="saveProvider"
                    placeholder="https://api.deepseek.com"
                    class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)"
                />
                <p class="mt-1 text-[10.5px] text-(--mut)">Laissé vide, l'appel part vers l'API OpenAI officielle — la clé d'un autre fournisseur y sera toujours rejetée.</p>
            </div>

            <label class="flex items-center gap-2 text-sm text-(--tx)">
                <input type="checkbox" wire:model="providerIsActive" wire:loading.attr="disabled" wire:target="saveProvider" class="rounded-[4px] border-(--bd2) text-(--ac) focus:ring-0" />
                Actif
            </label>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showProviderModal', false)" wire:loading.attr="disabled" wire:target="saveProvider">Annuler</x-btn>
                <x-btn variant="primary" wire:click="saveProvider" wire:loading.attr="disabled" wire:target="saveProvider">
                    <span wire:loading wire:target="saveProvider">Enregistrement…</span>
                    <span wire:loading.remove wire:target="saveProvider">Enregistrer</span>
                </x-btn>
            </div>
        </div>
    </flux:modal>
</div>
