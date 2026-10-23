<?php

use App\Enums\GitSyncStatus;
use App\Models\AiProvider;
use App\Models\BrainDocument;
use App\Models\Goal;
use App\Models\GoogleCalendarConnection;
use App\Models\McpCall;
use App\Support\Agents\AgentRunner;
use App\Support\Archiving\ArchiveSettings;
use App\Jobs\RefreshVaultStatusJob;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use App\Support\GoogleCalendar\GoogleCalendarClient;
use App\Jobs\SendAlertJob;
use App\Support\Alerts\AlertSettings;
use App\Support\Mcp\McpSettings;
use App\Support\Momentum\MomentumSettings;
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

    public bool $momentumGauges = true;

    public bool $momentumStreak = true;

    public bool $momentumCelebration = true;

    public bool $mcpEnabled = false;

    public ?string $mcpNewToken = null;

    public string $alertWebhookUrl = '';

    public bool $alertWebhookSaved = false;

    public bool $alertWebhookTested = false;

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

    public ?int $archiveAfterDays = null;

    public bool $archiveSettingsSaved = false;

    public ?string $googleCalendarSyncMessage = null;

    public function mount(): void
    {
        $settings = app(BrainSettings::class);

        // Même raison que sur /cerveau : la vérification git part sur la file,
        // une fois par chargement, et cet écran se contente de relire.
        RefreshVaultStatusJob::dispatchIfStale();

        $this->brainRemoteUrl = (string) $settings->remoteUrl();
        $this->brainBranch = $settings->branch();
        $this->brainSyncFrequency = $settings->syncFrequencyMinutes();
        $this->brainAutoIndex = $settings->autoIndexEnabled();

        $this->mcpEnabled = app(McpSettings::class)->isEnabled();
        $this->alertWebhookUrl = (string) app(AlertSettings::class)->webhookUrl();

        $reviewSettings = app(ReviewSettings::class);
        $this->reviewWeeklyTime = $reviewSettings->weeklyTime();
        $this->reviewNotificationEmail = (string) $reviewSettings->notificationEmail();
        $this->reviewNotificationsEnabled = $reviewSettings->notificationsEnabled();

        $this->archiveAfterDays = app(ArchiveSettings::class)->afterDays();

        $momentum = app(MomentumSettings::class);
        $this->momentumGauges = $momentum->gaugesEnabled();
        $this->momentumStreak = $momentum->streakEnabled();
        $this->momentumCelebration = $momentum->celebrationEnabled();
    }

    /**
     * Les jauges, la série et la célébration se coupent chacune séparément —
     * et se coupent *en un clic*, sans bouton Enregistrer : c'est le seul
     * réglage qu'on peut vouloir désactiver en pleine journée.
     */
    public function updatedMomentumGauges(): void
    {
        $this->saveMomentumSettings();
    }

    public function updatedMomentumStreak(): void
    {
        $this->saveMomentumSettings();
    }

    public function updatedMomentumCelebration(): void
    {
        $this->saveMomentumSettings();
    }

    private function saveMomentumSettings(): void
    {
        app(MomentumSettings::class)->update(
            gauges: $this->momentumGauges,
            streak: $this->momentumStreak,
            celebration: $this->momentumCelebration,
        );
    }

    /**
     * La palette catégorielle en clair : un objectif, une teinte, partout.
     * C'est ici qu'on vient vérifier quelle couleur veut dire quoi.
     *
     * @return Collection<int, Goal>
     */
    #[Computed]
    public function paletteGoals(): Collection
    {
        return Goal::where('status', 'active')->orderBy('position')->orderBy('title')->get();
    }

    public function saveArchiveSettings(): void
    {
        $this->archiveSettingsSaved = false;

        $validated = $this->validate([
            'archiveAfterDays' => ['nullable', 'integer', 'min:1'],
        ]);

        app(ArchiveSettings::class)->updateAfterDays($validated['archiveAfterDays']);

        $this->archiveSettingsSaved = true;
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
     * L'état du vault, relu dans les réglages — jamais recalculé.
     *
     * `git status` fait un `git fetch` (~1,1 s de réseau) : le laisser ici le
     * faisait payer à chaque rendu de cet écran, c'est-à-dire à chaque champ
     * touché dans une page qui en compte des dizaines. C'est
     * RefreshVaultStatusJob qui va voir, hors requête.
     *
     * @return array{lastSynced: ?Carbon, lastIndexed: ?Carbon, documentsCount: int, gitStatus: GitSyncStatus, gitCheckedAt: ?Carbon, lastPushed: ?Carbon, lastPushError: ?string, lastSyncWarning: ?string, syncFailure: ?array{since: Carbon, message: string, attempts: int}, lastConflictAt: ?Carbon, lastConflictMessage: ?string}
     */
    #[Computed]
    public function brainStatus(): array
    {
        $settings = app(BrainSettings::class);

        return [
            'lastSynced' => $settings->lastSyncedAt(),
            'lastIndexed' => $settings->lastIndexedAt(),
            'documentsCount' => BrainDocument::count(),
            'gitStatus' => $settings->syncStatus(),
            'gitCheckedAt' => $settings->syncStatusAt(),
            'lastPushed' => $settings->lastPushAt(),
            'lastPushError' => $settings->lastPushError(),
            'lastSyncWarning' => $settings->lastSyncWarning(),
            'syncFailure' => $settings->syncFailure(),
            'lastConflictAt' => $settings->lastConflictAt(),
            'lastConflictMessage' => $settings->lastConflictMessage(),
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

    /**
     * Pull-rebase then push, in one click — what Thomas reaches for when a
     * conflict alert is showing and he has sorted it out in Obsidian.
     */
    public function forceBrainSync(): void
    {
        $settings = app(BrainSettings::class);
        $git = app(GitRepository::class);
        $path = $settings->localPath();

        $pull = $git->pullRebase($path);

        if (! $pull->successful) {
            $this->brainSyncMessage = "Le pull-rebase a échoué : {$pull->message}";
            unset($this->brainStatus);

            return;
        }

        try {
            $git->push($path);
            $settings->recordPushError(null);
            $this->brainSyncMessage = 'Vault synchronisé — pull-rebase et push effectués.';
        } catch (\Throwable $e) {
            $settings->recordPushError($e->getMessage());
            $this->brainSyncMessage = "Le push a échoué : {$e->getMessage()}";
        }

        // Thomas vient de cliquer et attend le résultat : ici, et seulement
        // ici, la vérification se fait dans la requête.
        dispatch_sync(new RefreshVaultStatusJob);

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

    /**
     * Où Tars pousse ce qui ne peut pas attendre que Thomas ouvre l'écran.
     */
    public function saveAlertWebhook(): void
    {
        $validated = $this->validate([
            'alertWebhookUrl' => ['nullable', 'string', 'max:2000', 'url'],
        ]);

        app(AlertSettings::class)->setWebhookUrl($validated['alertWebhookUrl'] ?: null);

        $this->alertWebhookSaved = true;
    }

    public function testAlertWebhook(): void
    {
        if (! app(AlertSettings::class)->isConfigured()) {
            $this->addError('alertWebhookUrl', "Renseigne d'abord une URL, et enregistre-la.");

            return;
        }

        SendAlertJob::dispatch(message: 'Test depuis Tars : le webhook d\'alerte fonctionne.');

        $this->alertWebhookTested = true;
    }

    public function generateMcpToken(): void
    {
        $this->mcpNewToken = app(McpSettings::class)->generateToken();

        unset($this->mcpHasToken);
    }

    #[Computed]
    public function googleCalendarConnection(): ?GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->first();
    }

    #[Computed]
    public function googleCalendarConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    #[Computed]
    public function googleCalendarRedirectUri(): string
    {
        return route('google-calendar.callback');
    }

    /**
     * Les accès demandés à Google et leur état réel : l'écran de consentement
     * laisse Thomas décocher un scope, auquel cas la connexion existe mais
     * l'accès manque.
     *
     * @return list<array{label: string, detail: string, granted: bool}>
     */
    #[Computed]
    public function googleAccesses(): array
    {
        $connection = $this->googleCalendarConnection;

        if ($connection === null) {
            return [];
        }

        return [
            ['label' => 'Google Calendar', 'detail' => 'lecture et écriture des événements', 'granted' => $connection->canReadAndWriteCalendar()],
            ['label' => 'Gmail', 'detail' => 'lecture seule', 'granted' => $connection->canReadGmail()],
        ];
    }

    #[Computed]
    public function googleAccessesMissing(): bool
    {
        return collect($this->googleAccesses)->contains(fn (array $access): bool => ! $access['granted']);
    }

    public function disconnectGoogleCalendar(): void
    {
        $connection = GoogleCalendarConnection::query()->first();

        if ($connection === null) {
            return;
        }

        app(GoogleCalendarClient::class)->revoke($connection->refresh_token);
        $connection->delete();

        unset($this->googleCalendarConnection);
    }

    public function syncGoogleCalendarNow(): void
    {
        Artisan::call('google-calendar:sync');

        $this->googleCalendarSyncMessage = trim(Artisan::output());

        unset($this->googleCalendarConnection);
    }
};
?>

@php
    // Sommaire à gauche, panneau à droite : les Réglages sont le seul écran
    // assez long pour justifier une table des matières. Les entrées sont
    // groupées comme sur l'écran 13 du doc — ce qui est à moi, ce qui est
    // branché, ce qui tourne tout seul.
    //
    // Deux entrées du doc n'existent pas et ne sont donc pas écrites ici :
    // « Domaines de vie » (le modèle `LifeArea` a été retiré en juillet 2026 ;
    // ce que l'app a, ce sont des objectifs et leurs teintes) et « Ce qu'Hermès
    // peut faire » (la matrice fait seul / propose / jamais n'a aucune
    // permission en base — l'écran ne peut pas promettre un réglage qui ne
    // retiendrait rien).
    $groups = [
        'Moi' => [
            'palette' => 'Objectifs & teintes',
            'rituels' => 'Rituels & jauges',
            'revue' => 'Revue & bilan',
        ],
        'Branché' => [
            'google' => 'Google',
            'mcp' => 'Serveur MCP',
            'notifications' => 'Notifications',
            'ia' => 'Fournisseurs IA',
            'agents' => 'Agents système',
        ],
        'Mémoire' => [
            'cerveau' => 'Vault',
            'archivage' => 'Archivage',
        ],
    ];
@endphp

<div>
    <x-screen-header kicker="objectifs, connexions, notifications, revue, mémoire" title="Réglages" class="mb-6" />

    @if (session('status') === 'google-calendar-connected')
        <div class="mb-6 flex items-center gap-2.5 rounded-[14px] bg-(--aibg) px-4 py-3 text-[14px] font-semibold text-(--ai)">
            <span aria-hidden="true">✓</span> Compte Google connecté.
        </div>
    @elseif (session('status') === 'google-calendar-failed')
        <div class="mb-6 flex items-center gap-2.5 rounded-[14px] bg-(--dgrbg) px-4 py-3 text-[14px] font-semibold text-(--dgr)">
            <span aria-hidden="true">✕</span> Échec de la connexion à Google. Réessaie.
        </div>
    @endif

    <div class="grid grid-cols-1 items-start gap-8 xl:grid-cols-[200px_minmax(0,1fr)] xl:gap-12">
        <nav class="sticky top-6 hidden flex-col gap-4 xl:flex">
            <div class="flex flex-col gap-0.5">
                <p class="px-3.5 pb-1 text-[11px] text-(--mut) kicker">Compte</p>
                <a href="{{ route('profile.edit') }}" wire:navigate class="press rounded-full px-3.5 py-2 text-[13px] font-semibold text-(--mut) hover:bg-(--surf) hover:text-(--tx)">Profil & sécurité</a>
                <a href="{{ route('missions.index') }}" wire:navigate class="press rounded-full px-3.5 py-2 text-[13px] font-semibold text-(--mut) hover:bg-(--surf) hover:text-(--tx)">Hermès</a>
            </div>

            @foreach ($groups as $heading => $sections)
                <div class="flex flex-col gap-0.5">
                    <p class="px-3.5 pb-1 text-[11px] text-(--mut) kicker">{{ $heading }}</p>
                    @foreach ($sections as $anchor => $label)
                        <a href="#{{ $anchor }}" class="press rounded-full px-3.5 py-2 text-[13px] font-semibold text-(--mut) hover:bg-(--surf) hover:text-(--tx)">{{ $label }}</a>
                    @endforeach
                </div>
            @endforeach
        </nav>

        {{-- Aucun plafond de largeur sur le panneau : il prend tout l'écran.
             Seuls les paragraphes gardent une largeur de lecture — une ligne de
             texte de 1600px ne se lit pas. Les cartes, elles, remplissent. --}}
        <div class="min-w-0">

    <section id="palette" class="mb-12">
        <h2 class="font-display mb-2 text-[20px] font-bold tracking-[-.01em] text-(--tx)">Objectifs &amp; teintes</h2>
        <p class="mb-6 max-w-[620px] text-[15px] leading-[1.65] text-(--mut)">
            Chaque objectif porte une des sept teintes de saison, la même partout : calendrier, tâches, bande
            d'état, fiches. Au-delà de sept objectifs, la série recommence.
        </p>

        <div class="mb-4 flex flex-col">
            @forelse ($this->paletteGoals as $goal)
                <a
                    href="{{ route('goals.show', $goal) }}"
                    wire:navigate
                    wire:key="palette-{{ $goal->id }}"
                    class="press-row flex items-center gap-4 border-b border-(--bd) px-1 py-[13px] hover:bg-(--surf)"
                >
                    <span class="size-[22px] shrink-0 rounded-[7px]" style="background: var(--goal-{{ $goal->color_slot }})" aria-hidden="true"></span>
                    <span class="min-w-0 flex-1 truncate text-[15px] text-(--tx)">{{ $goal->title }}</span>
                    <span class="text-[13px] text-(--mut)">
                        {{ $goal->milestones->count() }} {{ Str::plural('jalon', $goal->milestones->count()) }}
                    </span>
                </a>
            @empty
                <p class="py-3 text-[14px] text-(--mut)">Aucun objectif ouvert : la palette est vide.</p>
            @endforelse
        </div>

        <x-btn variant="secondary" :href="route('goals.index')" wire:navigate>Gérer les objectifs</x-btn>
    </section>

    <section id="rituels" class="mb-12">
        <h2 class="font-display mb-5 text-[20px] font-bold tracking-[-.01em] text-(--tx)">Rituels &amp; jauges</h2>

        <x-card padding="22" class="flex flex-col divide-y divide-(--bd)">
            <div class="flex items-start gap-5 py-4 first:pt-0 last:pb-0">
                <div>
                    <p class="mb-1 text-[15px] font-semibold text-(--tx)">Jauges de saison</p>
                    <p class="text-[13px] leading-[1.6] text-(--mut)">
                        Affiche la bande d'état sur les écrans de travail. Décroissance de 8 % par semaine sans
                        activité, plancher à 10 %.
                    </p>
                </div>
                <x-toggle class="ml-auto shrink-0" wire:model.live="momentumGauges" />
            </div>

            <div class="flex items-start gap-5 py-4 first:pt-0 last:pb-0">
                <div>
                    <p class="mb-1 text-[15px] font-semibold text-(--tx)">Série et paliers</p>
                    <p class="text-[13px] leading-[1.6] text-(--mut)">
                        Compte les jours consécutifs avec au moins une tâche cochée. Paliers à 7, 30 et 100 jours.
                    </p>
                </div>
                <x-toggle class="ml-auto shrink-0" wire:model.live="momentumStreak" />
            </div>

            <div class="flex items-start gap-5 py-4 first:pt-0 last:pb-0">
                <div>
                    <p class="mb-1 text-[15px] font-semibold text-(--tx)">Célébration à la fermeture d'un objectif</p>
                    <p class="text-[13px] leading-[1.6] text-(--mut)">
                        Affiche une carte en tête d'Aujourd'hui les deux jours qui suivent un objectif atteint.
                    </p>
                </div>
                <x-toggle class="ml-auto shrink-0" wire:model.live="momentumCelebration" />
            </div>
        </x-card>
    </section>

    <section id="cerveau" class="mb-12">
        <h2 class="font-display mb-5 text-[20px] font-bold tracking-[-.01em] text-(--tx)">Mémoire — le vault</h2>

        <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-2">
            <form wire:submit="saveBrainSettings" class="space-y-4 rounded-[18px] bg-(--surf) p-5" wire:loading.class="opacity-50" wire:target="saveBrainSettings">
                <div>
                    <label class="text-[12px] text-(--mut)">URL du remote git</label>
                    <input
                        type="text"
                        wire:model="brainRemoteUrl"
                        wire:loading.attr="disabled"
                        wire:target="saveBrainSettings"
                        placeholder="git@example.com:vault.git"
                        class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none"
                    />
                    @error('brainRemoteUrl') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="text-[12px] text-(--mut)">Branche</label>
                    <input type="text" wire:model="brainBranch" wire:loading.attr="disabled" wire:target="saveBrainSettings" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none" />
                    @error('brainBranch') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="text-[12px] text-(--mut)">Fréquence de sync (minutes)</label>
                    <input type="number" min="1" wire:model="brainSyncFrequency" wire:loading.attr="disabled" wire:target="saveBrainSettings" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none" />
                    @error('brainSyncFrequency') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
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

            <div class="space-y-4 rounded-[18px] bg-(--surf) p-5">
                <div class="flex items-center justify-between">
                    <span class="text-[12px] text-(--mut)">Statut</span>
                    <x-badge type="status" :value="$this->brainStatus['gitStatus']->value" />
                </div>

                <dl class="space-y-2 font-mono text-xs text-(--mut)">
                    {{-- Le statut ci-dessus est un constat, pas une mesure en
                         direct : dire de quand il date évite de lire une
                         pastille verte comme une vérification à l'instant. --}}
                    <div class="flex justify-between">
                        <dt>Statut vérifié</dt>
                        <dd class="text-(--tx)">{{ $this->brainStatus['gitCheckedAt']?->translatedFormat('d M · H:i') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Dernier pull</dt>
                        <dd class="text-(--tx)">{{ $this->brainStatus['lastSynced']?->translatedFormat('d M · H:i') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Dernière indexation</dt>
                        <dd class="text-(--tx)">{{ $this->brainStatus['lastIndexed']?->translatedFormat('d M · H:i') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Dernier push</dt>
                        <dd class="text-(--tx)">{{ $this->brainStatus['lastPushed']?->translatedFormat('d M · H:i') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Documents indexés</dt>
                        <dd class="text-(--tx)">{{ $this->brainStatus['documentsCount'] }}</dd>
                    </div>
                </dl>

                @if ($this->brainStatus['lastConflictAt'])
                    <div class="rounded-[14px] border border-(--dgr) bg-(--surf2) p-3.5">
                        <p class="mb-1 text-[13px] font-semibold text-(--dgr)">
                            Conflit git — {{ $this->brainStatus['lastConflictAt']->translatedFormat('d M · H:i') }}
                        </p>
                        <p class="text-[12px] leading-[1.6] text-(--mut)">{{ $this->brainStatus['lastConflictMessage'] }}</p>
                    </div>
                @endif

                {{-- Une divergence ne se répare jamais d'elle-même : la
                     synchro réessaiera à l'identique toutes les quinze minutes
                     jusqu'à ce que Thomas tranche. Donc on le dit ici, et pas
                     seulement par un « dernier pull » qui vieillit. --}}
                @if ($this->brainStatus['syncFailure'])
                    <div class="rounded-[14px] border border-(--dgr) bg-(--surf2) p-3.5">
                        <p class="mb-1 text-[13px] font-semibold text-(--dgr)">
                            Synchro en panne depuis {{ $this->brainStatus['syncFailure']['since']->diffForHumans(null, true) }}
                            · {{ $this->brainStatus['syncFailure']['attempts'] }} tentative{{ $this->brainStatus['syncFailure']['attempts'] > 1 ? 's' : '' }}
                        </p>
                        <p class="text-[12px] leading-[1.6] text-(--mut)">Le vault est figé : rien n'est tiré de GitHub ni poussé vers lui.</p>
                        <p class="mt-1.5 font-mono text-[11px] leading-[1.6] text-(--mut)">{{ Str::limit($this->brainStatus['syncFailure']['message'], 300) }}</p>
                    </div>
                @endif

                @if ($this->brainStatus['lastSyncWarning'])
                    <p class="text-[12px] leading-[1.6] text-(--warn)">{{ $this->brainStatus['lastSyncWarning'] }}</p>
                @endif

                @if ($this->brainStatus['lastPushError'])
                    <p class="text-[12px] leading-[1.6] text-(--dgr)">Dernière erreur de push : {{ $this->brainStatus['lastPushError'] }}</p>
                @endif

                <div class="flex flex-wrap gap-2 pt-1">
                    <x-btn variant="secondary" wire:click="syncBrainNow">Synchroniser maintenant</x-btn>
                    <x-btn variant="secondary" wire:click="forceBrainSync">Forcer la synchro</x-btn>
                    <x-btn variant="secondary" wire:click="reindexBrainFull" wire:confirm="Reconstruire tout l'index depuis zéro ?">Réindexer tout</x-btn>
                </div>

                @if ($brainSyncMessage)
                    <p class="text-[12px] text-(--mut)">{{ $brainSyncMessage }}</p>
                @endif
                @if ($brainReindexMessage)
                    <p class="text-[12px] text-(--mut)">{{ $brainReindexMessage }}</p>
                @endif
            </div>
        </div>
    </section>

    {{-- Par où un agent me joint quand ça ne peut pas attendre. C'est la
         « Notifications » du doc de refonte : il n'y en a qu'une, parce qu'il
         n'y a qu'un chemin sortant (`alert_thomas`). --}}
    <section id="notifications" class="mb-12">
        <h2 class="font-display mb-2 text-[20px] font-bold tracking-[-.01em] text-(--tx)">Notifications</h2>
        <p class="mb-6 max-w-[620px] text-[15px] leading-[1.65] text-(--mut)">
            Un webhook plutôt qu'un canal en dur : branche-le sur ce que tu lis vraiment.
        </p>

        <div class="max-w-[620px]">
            <x-connection-card
                title="Alertes"
                subtitle="Par où un agent me prévient quand ça ne peut pas attendre"
                :status="$alertWebhookUrl !== '' ? 'Configuré' : 'Aucun'"
                :tone="$alertWebhookUrl !== '' ? 'agent' : 'sunk'"
            >
                <x-slot:mark>
                    <flux:icon name="bell-alert" class="size-[19px] text-(--ai)" />
                </x-slot:mark>

                <p class="mb-3 text-[13px] leading-[1.6] text-(--mut)">
                    Tars poste un JSON sur cette URL quand un agent appelle <span class="font-mono">alert_thomas</span>.
                    Branche-la sur ce que tu veux — Hermes, n8n, ntfy — pour que l'alerte arrive là où tu es.
                </p>

                <label class="block">
                    <span class="mb-1.5 block text-[13px] font-semibold text-(--mut)">URL du webhook</span>
                    <x-input variant="technical" wire:model="alertWebhookUrl" placeholder="https://…" class="w-full" />
                </label>
                @error('alertWebhookUrl')<p class="mt-1.5 text-[12px] text-(--warn)">{{ $message }}</p>@enderror

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-btn wire:click="saveAlertWebhook" variant="secondary" size="sm">Enregistrer</x-btn>
                    <x-btn wire:click="testAlertWebhook" variant="ghost" size="sm">Envoyer un test</x-btn>

                    @if ($alertWebhookSaved)<span class="text-[12px] text-(--ai)">enregistré</span>@endif
                    @if ($alertWebhookTested)<span class="text-[12px] text-(--ai)">test envoyé</span>@endif
                </div>
            </x-connection-card>
        </div>
    </section>

    <section id="mcp" class="mb-12">
        <h2 class="font-display mb-2 text-[20px] font-bold tracking-[-.01em] text-(--tx)">Serveur MCP — la porte d'Hermès</h2>
        <p class="mb-6 max-w-[620px] text-[15px] leading-[1.65] text-(--mut)">
            Le serveur MCP est la porte par laquelle Hermes lit et écrit dans Tars. Un seul jeton à la fois :
            en régénérer un révoque l'ancien sur-le-champ.
        </p>

        <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-2">
            <div class="flex flex-col gap-5">
                <x-connection-card
                    title="Serveur MCP"
                    subtitle="La porte d'Hermes sur Tars"
                    :status="$mcpEnabled ? 'Actif' : 'Inactif'"
                    :tone="$mcpEnabled ? 'agent' : 'sunk'"
                >
                    <x-slot:mark>
                        <flux:icon name="command-line" class="size-[19px] text-(--ai)" />
                    </x-slot:mark>

                    <x-slot:aside>
                        <x-toggle wire:model.live="mcpEnabled" />
                    </x-slot:aside>

                    <x-copy-field label="URL de l'endpoint" :value="$this->mcpEndpointUrl" />

                    <div>
                        @if ($mcpNewToken)
                            <x-copy-field label="Token" :value="$mcpNewToken" />
                            <p class="mt-2 flex items-start gap-1.5 text-[12.5px] font-medium text-(--warn)">
                                <span aria-hidden="true">!</span>
                                Ce token ne sera plus jamais affiché en clair — copie-le maintenant.
                            </p>
                        @else
                            <x-copy-field
                                label="Token"
                                :copyable="false"
                                :display="$this->mcpHasToken ? '••••••••••••••••' : 'Aucun token généré'"
                            />
                        @endif
                    </div>

                    <x-slot:actions>
                        @php
                            $mcpConfirmMessage = $this->mcpHasToken
                                ? "Régénérer le token révoque immédiatement l'ancien. Continuer ?"
                                : 'Générer un nouveau token ?';
                        @endphp
                        <x-btn variant="secondary" wire:click="generateMcpToken" wire:confirm="{{ $mcpConfirmMessage }}">
                            {{ $this->mcpHasToken ? 'Régénérer le token' : 'Générer un token' }}
                        </x-btn>
                    </x-slot:actions>
                </x-connection-card>

                <x-card padding="18" tone="sunk">
                    <div x-data="{ open: false }">
                        <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between text-left text-[13px] font-semibold text-(--tx)">
                            Configuration côté Hermes
                            <span aria-hidden="true" class="text-(--mut)" x-text="open ? '−' : '+'"></span>
                        </button>

                        <div x-show="open" x-cloak class="mt-3.5 space-y-2.5">
                            <x-copy-field label="URL" :value="$this->mcpEndpointUrl" />
                            <x-copy-field label="Header" value="Authorization: Bearer <token>" />
                            <p class="text-[12.5px] leading-[1.6] text-(--mut)">
                                Depuis un conteneur Docker sur ce même VPS, utilise <span class="font-semibold text-(--tx)">172.17.0.1</span>
                                à la place du domaine ; le domaine public reste nécessaire si Hermes tourne ailleurs.
                            </p>
                        </div>
                    </div>
                </x-card>
            </div>

            <x-card padding="22" class="flex flex-col gap-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-[14px] font-semibold text-(--tx)">Journal des appels</p>
                    <span class="inline-flex items-center gap-2 text-[12.5px] text-(--mut)">
                        <span class="size-[8px] rounded-full {{ $this->mcpStatus['lastCallAt'] ? 'bg-(--ai)' : 'bg-(--bd2)' }}" aria-hidden="true"></span>
                        {{ $this->mcpStatus['lastCallAt'] ? 'Dernier appel '.$this->mcpStatus['lastCallAt']->diffForHumans() : 'Aucun appel' }}
                    </span>
                </div>

                <div class="flex items-baseline gap-2 border-y border-(--bd) py-3">
                    <span class="font-display text-[26px] font-bold text-(--tx)">{{ $this->mcpStatus['callsLast7Days'] }}</span>
                    <span class="text-[13px] text-(--mut)">{{ Str::plural('appel', $this->mcpStatus['callsLast7Days']) }} sur 7 jours</span>
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-select variant="technical" wire:model.live="mcpFilterTool">
                        <option value="">Tous les outils</option>
                        @foreach ($this->mcpTools as $tool)
                            <option value="{{ $tool }}">{{ $tool }}</option>
                        @endforeach
                    </x-select>
                    <x-select variant="technical" wire:model.live="mcpFilterStatus">
                        <option value="">Tous les statuts</option>
                        @foreach (\App\Enums\McpCallStatus::cases() as $status)
                            <option value="{{ $status->value }}">{{ $status->value }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="max-h-[340px] divide-y divide-(--bd) overflow-y-auto">
                    @forelse ($this->mcpCalls as $call)
                        <div class="flex items-center gap-2.5 py-2" wire:key="mcp-call-{{ $call->id }}">
                            <span class="font-mono text-[12px] font-semibold text-(--tx)">{{ $call->tool }}</span>
                            <x-badge type="status" :value="$call->status->value" />
                            <span class="font-mono text-[11.5px] text-(--mut)">{{ $call->duration_ms }}ms</span>
                            <span class="ml-auto min-w-0 truncate text-[12.5px] text-(--mut)">{{ Str::limit($call->result_summary ?? $call->error_message, 60) }}</span>
                        </div>
                    @empty
                        <p class="py-5 text-center text-[13.5px] text-(--mut)">Aucun appel pour l'instant.</p>
                    @endforelse
                </div>
            </x-card>
        </div>
    </section>

    <section id="google" class="mb-12">
        <h2 class="font-display mb-2 text-[20px] font-bold tracking-[-.01em] text-(--tx)">Connexions — Google</h2>
        <p class="mb-6 max-w-[620px] text-[15px] leading-[1.65] text-(--mut)">
            Un seul compte Google, deux accès : le calendrier dans les deux sens, la boîte Gmail en lecture seule.
        </p>

        <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-2">
            <x-connection-card
                title="Google"
                :subtitle="$this->googleCalendarConnection
                    ? ($this->googleCalendarConnection->google_account_email ?? 'Compte Google connecté')
                    : 'Aucun compte connecté'"
                :status="match (true) {
                    $this->googleCalendarConnection === null => 'Non connecté',
                    $this->googleCalendarConnection->needs_reconnect => 'Reconnexion requise',
                    $this->googleAccessesMissing => 'Accès partiel',
                    default => 'Connecté',
                }"
                :tone="match (true) {
                    $this->googleCalendarConnection === null => 'sunk',
                    $this->googleCalendarConnection->needs_reconnect => 'danger',
                    $this->googleAccessesMissing => 'warn',
                    default => 'agent',
                }"
            >
                <x-slot:mark>
                    <x-brand-google class="size-[21px]" />
                </x-slot:mark>

                @if ($this->googleCalendarConnection)
                    <div class="grid grid-cols-1 gap-2">
                        @foreach ($this->googleAccesses as $access)
                            <div class="flex items-center gap-3 rounded-[13px] bg-(--surf2) px-3.5 py-3">
                                <span
                                    class="flex size-[20px] shrink-0 items-center justify-center rounded-full text-[12px] font-bold {{ $access['granted'] ? 'bg-(--aibg) text-(--ai)' : 'bg-(--dgrbg) text-(--dgr)' }}"
                                    aria-hidden="true"
                                >{{ $access['granted'] ? '✓' : '✕' }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[13.5px] font-semibold text-(--tx)">{{ $access['label'] }}</p>
                                    <p class="text-[12.5px] text-(--mut)">{{ $access['detail'] }}</p>
                                </div>
                                @unless ($access['granted'])
                                    <x-badge type="pill" tone="danger" class="shrink-0">non accordé</x-badge>
                                @endunless
                            </div>
                        @endforeach
                    </div>

                    <dl class="flex items-baseline gap-2 text-[13px]">
                        <dt class="text-(--mut)">Dernière synchro</dt>
                        <dd class="font-semibold text-(--tx)">{{ $this->googleCalendarConnection->last_synced_at?->diffForHumans() ?? 'jamais' }}</dd>
                    </dl>

                    @if ($this->googleCalendarConnection->needs_reconnect)
                        <p class="rounded-[13px] bg-(--dgrbg) px-3.5 py-3 text-[12.5px] leading-[1.6] text-(--dgr)">
                            Google a révoqué l'accès — souvent parce que l'écran de consentement OAuth est resté en mode « Test »,
                            où les jetons expirent après 7 jours. Reconnecte le compte ci-dessous.
                        </p>
                    @elseif ($this->googleAccessesMissing)
                        <p class="rounded-[13px] bg-(--warnbg) px-3.5 py-3 text-[12.5px] leading-[1.6] text-(--warn)">
                            Un accès demandé n'a pas été accordé : case décochée sur l'écran de consentement, ou scope absent
                            de la configuration OAuth. Réautorise le compte pour le réclamer.
                        </p>
                    @endif

                    @if ($googleCalendarSyncMessage)
                        <p class="text-[12.5px] leading-[1.6] text-(--mut)">{{ $googleCalendarSyncMessage }}</p>
                    @endif

                    <x-slot:actions>
                        <x-btn variant="danger" size="xs" wire:click="disconnectGoogleCalendar" wire:confirm="Déconnecter le compte Google ?">Déconnecter</x-btn>

                        @if ($this->googleCalendarConnection->needs_reconnect || $this->googleAccessesMissing)
                            <x-btn variant="secondary" wire:click="syncGoogleCalendarNow">
                                <span wire:loading.remove wire:target="syncGoogleCalendarNow">Synchroniser</span>
                                <span wire:loading wire:target="syncGoogleCalendarNow">Synchronisation…</span>
                            </x-btn>
                            <x-btn-google :href="route('google-calendar.connect')" label="Réautoriser" />
                        @else
                            <x-btn variant="ghost" :href="route('google-calendar.connect')">Réautoriser</x-btn>
                            <x-btn variant="primary" wire:click="syncGoogleCalendarNow">
                                <span wire:loading.remove wire:target="syncGoogleCalendarNow">Synchroniser</span>
                                <span wire:loading wire:target="syncGoogleCalendarNow">Synchronisation…</span>
                            </x-btn>
                        @endif
                    </x-slot:actions>
                @elseif ($this->googleCalendarConfigured)
                    <p class="text-[13.5px] leading-[1.65] text-(--mut)">
                        Tars demandera deux accès à ton compte : <span class="font-semibold text-(--tx)">lecture et écriture
                        des événements</span> du calendrier, et <span class="font-semibold text-(--tx)">lecture seule</span> de Gmail.
                    </p>

                    <x-slot:actions>
                        <x-btn-google :href="route('google-calendar.connect')" />
                    </x-slot:actions>
                @else
                    <p class="rounded-[13px] bg-(--warnbg) px-3.5 py-3 text-[12.5px] leading-[1.6] text-(--warn)">
                        Aucun identifiant OAuth Google n'est configuré côté serveur. Suis le guide à droite pour en créer un —
                        ça prend 5 minutes et ne se fait qu'une fois.
                    </p>
                @endif
            </x-connection-card>

            <x-card padding="22" tone="sunk">
                <div x-data="{ open: {{ $this->googleCalendarConfigured ? 'false' : 'true' }} }">
                    <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between text-left text-[13px] font-semibold text-(--tx)">
                        Comment connecter un compte Google ?
                        <span aria-hidden="true" class="text-(--mut)" x-text="open ? '−' : '+'"></span>
                    </button>

                    <div x-show="open" x-cloak class="mt-4 space-y-4 text-[12.5px] leading-[1.65] text-(--mut)">
                        <ol class="space-y-3">
                            <li class="flex gap-2.5">
                                <span class="font-display shrink-0 font-bold text-(--tx)">1.</span>
                                <span>Ouvre la <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" class="font-semibold text-(--ac) underline">Google Cloud Console</a> et crée un projet (ou réutilise un projet existant).</span>
                            </li>
                            <li class="flex gap-2.5">
                                <span class="font-display shrink-0 font-bold text-(--tx)">2.</span>
                                <span>Active les API <span class="font-semibold text-(--tx)">Google Calendar API</span> et <span class="font-semibold text-(--tx)">Gmail API</span> (menu « API et services » → « Bibliothèque »).</span>
                            </li>
                            <li class="flex gap-2.5">
                                <span class="font-display shrink-0 font-bold text-(--tx)">3.</span>
                                <span>
                                    Configure l'écran de consentement OAuth en type « Externe », puis ajoute les deux scopes :
                                    <code class="rounded-[5px] bg-(--surf2) px-1.5 py-0.5 font-mono text-[11.5px] text-(--tx)">.../auth/calendar.events</code>
                                    et
                                    <code class="rounded-[5px] bg-(--surf2) px-1.5 py-0.5 font-mono text-[11.5px] text-(--tx)">.../auth/gmail.readonly</code>.
                                    Passe la publication en <span class="font-semibold text-(--tx)">Production</span> dès que possible : laissée en « Test »,
                                    Google expire le jeton tous les 7 jours.
                                </span>
                            </li>
                            <li class="flex gap-2.5">
                                <span class="font-display shrink-0 font-bold text-(--tx)">4.</span>
                                <span>Crée des identifiants → « ID client OAuth » → type « Application Web ».</span>
                            </li>
                            <li class="flex flex-col gap-2">
                                <span class="flex gap-2.5">
                                    <span class="font-display shrink-0 font-bold text-(--tx)">5.</span>
                                    <span>Ajoute cette URI de redirection autorisée, à l'identique :</span>
                                </span>
                                <x-copy-field :value="$this->googleCalendarRedirectUri" class="pl-6" />
                            </li>
                            <li class="flex gap-2.5">
                                <span class="font-display shrink-0 font-bold text-(--tx)">6.</span>
                                <span>
                                    Copie l'« ID client » et le « Code secret » dans le
                                    <code class="rounded-[5px] bg-(--surf2) px-1.5 py-0.5 font-mono text-[11.5px] text-(--tx)">.env</code> du serveur
                                    (<code class="rounded-[5px] bg-(--surf2) px-1.5 py-0.5 font-mono text-[11.5px] text-(--tx)">GOOGLE_CLIENT_ID</code>,
                                    <code class="rounded-[5px] bg-(--surf2) px-1.5 py-0.5 font-mono text-[11.5px] text-(--tx)">GOOGLE_CLIENT_SECRET</code>), puis redémarre l'application.
                                </span>
                            </li>
                            <li class="flex gap-2.5">
                                <span class="font-display shrink-0 font-bold text-(--tx)">7.</span>
                                <span>Reviens ici et clique sur « Continuer avec Google ».</span>
                            </li>
                        </ol>

                        <p class="border-t border-(--bd) pt-3.5">
                            <span class="font-semibold text-(--tx)">À savoir sur Gmail :</span>
                            <code class="rounded-[5px] bg-(--surf2) px-1.5 py-0.5 font-mono text-[11.5px] text-(--tx)">gmail.readonly</code>
                            est un scope « restreint » chez Google. Sur un projet personnel non vérifié, l'écran de consentement
                            affichera un avertissement « application non validée » à accepter à la main, et Google peut exiger une
                            validation pour rester en Production. Si la connexion redemande une autorisation tous les 7 jours,
                            c'est ce point-là qu'il faut regarder.
                        </p>
                    </div>
                </div>
            </x-card>
        </div>
    </section>

    {{-- Les agents ne sont pas une destination quotidienne : c'est de la
         configuration et un historique de runs. Ils ont donc quitté la barre
         latérale pour vivre ici, à côté des fournisseurs qui les font tourner. --}}
    <section id="agents" class="mb-12">
        <h2 class="font-display mb-2 text-[20px] font-bold tracking-[-.01em] text-(--tx)">Agents</h2>
        <p class="mb-6 max-w-[620px] text-[15px] leading-[1.65] text-(--mut)">
            Le reviewer, le curateur, le triage et le planner : leur fournisseur, leur modèle, leur
            déclenchement et l'historique de leurs passages.
        </p>
        <x-btn variant="secondary" size="md" :href="route('agents.index')" wire:navigate>Ouvrir les agents</x-btn>
    </section>

    <section id="ia" class="mb-12">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-display mb-5 text-[20px] font-bold tracking-[-.01em] text-(--tx)">Fournisseurs IA</h2>
            <x-btn variant="primary" wire:click="openCreateProviderModal">+ Nouveau fournisseur</x-btn>
        </div>

        @error('deleteProvider') <p class="mb-2 text-[12.5px] text-(--dgr)">{{ $message }}</p> @enderror

        <div class="divide-y divide-(--bd) overflow-hidden rounded-[18px] bg-(--surf)">
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
                <p class="p-5 text-center text-[13.5px] text-(--mut)">Aucun fournisseur pour l'instant.</p>
            @endforelse
        </div>
    </section>

    <section id="revue" class="mb-12">
        <h2 class="font-display mb-5 text-[20px] font-bold tracking-[-.01em] text-(--tx)">Revue</h2>

        <x-card padding="22" class="space-y-5" wire:loading.class="opacity-50" wire:target="saveReviewSettings">
            <div class="grid items-start gap-5 sm:grid-cols-2 xl:grid-cols-3">
                <div>
                    <label class="text-[12px] font-medium text-(--mut)">Heure de la revue hebdomadaire (dimanche)</label>
                    <x-input
                        variant="technical"
                        type="time"
                        wire:model="reviewWeeklyTime"
                        wire:loading.attr="disabled"
                        wire:target="saveReviewSettings"
                        class="mt-1.5 w-full"
                    />
                    @error('reviewWeeklyTime') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="text-[12px] font-medium text-(--mut)">Email de notification</label>
                    <x-input
                        type="email"
                        wire:model="reviewNotificationEmail"
                        wire:loading.attr="disabled"
                        wire:target="saveReviewSettings"
                        placeholder="toi@exemple.com"
                        class="mt-1.5 w-full"
                    />
                    @error('reviewNotificationEmail') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-5 sm:col-span-2 xl:col-span-1">
                    <p class="text-[14px] text-(--tx)">Recevoir un email quand une revue est générée</p>
                    <x-toggle class="ml-auto shrink-0" wire:model="reviewNotificationsEnabled" wire:loading.attr="disabled" wire:target="saveReviewSettings" />
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-(--bd) pt-4">
                @if ($reviewSettingsSaved)
                    <p class="text-[12.5px] font-semibold text-(--ok)" wire:loading.remove wire:target="saveReviewSettings">✓ Enregistré</p>
                @endif
                <x-btn variant="primary" wire:click="saveReviewSettings" wire:loading.attr="disabled" wire:target="saveReviewSettings">
                    <span wire:loading wire:target="saveReviewSettings">Enregistrement…</span>
                    <span wire:loading.remove wire:target="saveReviewSettings">Enregistrer</span>
                </x-btn>
            </div>
        </x-card>
    </section>

    <section id="archivage" class="mb-12">
        <h2 class="font-display mb-5 text-[20px] font-bold tracking-[-.01em] text-(--tx)">Archivage</h2>

        <x-card padding="22" class="space-y-5" wire:loading.class="opacity-50" wire:target="saveArchiveSettings">
            <div class="grid items-start gap-5 sm:grid-cols-[200px_minmax(0,1fr)]">
                <div>
                    <label class="text-[12px] font-medium text-(--mut)">Archiver après (jours)</label>
                    <x-input
                        variant="technical"
                        type="number"
                        min="1"
                        wire:model="archiveAfterDays"
                        wire:loading.attr="disabled"
                        wire:target="saveArchiveSettings"
                        placeholder="désactivé"
                        class="mt-1.5 w-full"
                    />
                    @error('archiveAfterDays') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
                </div>

                <p class="max-w-[620px] text-[13px] leading-[1.65] text-(--mut) sm:mt-6">
                    Laisser vide pour désactiver. Les tâches terminées et éléments de liste cochés depuis plus de N jours
                    seront masqués — mais conservés — automatiquement.
                </p>
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-(--bd) pt-4">
                @if ($archiveSettingsSaved)
                    <p class="text-[12.5px] font-semibold text-(--ok)" wire:loading.remove wire:target="saveArchiveSettings">✓ Enregistré</p>
                @endif
                <x-btn variant="primary" wire:click="saveArchiveSettings" wire:loading.attr="disabled" wire:target="saveArchiveSettings">
                    <span wire:loading wire:target="saveArchiveSettings">Enregistrement…</span>
                    <span wire:loading.remove wire:target="saveArchiveSettings">Enregistrer</span>
                </x-btn>
            </div>
        </x-card>
    </section>

        </div>
    </div>

    <flux:modal wire:model.self="showProviderModal" class="md:w-[420px]">
        <div class="space-y-5" wire:loading.class="opacity-50" wire:target="saveProvider">
            <flux:heading size="lg">{{ $editingProviderId ? 'Modifier le fournisseur' : 'Nouveau fournisseur' }}</flux:heading>

            <div>
                <label class="text-[12px] text-(--mut)">Nom</label>
                <input
                    type="text"
                    wire:model="providerName"
                    wire:loading.attr="disabled"
                    wire:target="saveProvider"
                    placeholder="anthropic, openai, deepseek…"
                    class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none"
                />
                @error('providerName') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Clé API</label>
                <input
                    type="password"
                    wire:model="providerApiKey"
                    wire:loading.attr="disabled"
                    wire:target="saveProvider"
                    placeholder="{{ $editingProviderId ? 'Laisser vide pour conserver la clé actuelle' : '' }}"
                    class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none"
                />
                @error('providerApiKey') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Base URL — requis pour tout fournisseur autre qu'OpenAI ou Anthropic (ex. DeepSeek : https://api.deepseek.com)</label>
                <input
                    type="text"
                    wire:model="providerBaseUrl"
                    wire:loading.attr="disabled"
                    wire:target="saveProvider"
                    placeholder="https://api.deepseek.com"
                    class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none"
                />
                <p class="mt-1.5 text-[12px] text-(--mut)">Laissé vide, l'appel part vers l'API OpenAI officielle — la clé d'un autre fournisseur y sera toujours rejetée.</p>
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
