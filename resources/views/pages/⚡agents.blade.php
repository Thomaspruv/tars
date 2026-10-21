<?php

use App\Enums\AgentName;
use App\Enums\PlannerProposalStatus;
use App\Jobs\RunAgentCommandJob;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\AiProvider;
use App\Models\PlannerProposal;
use App\Models\PlannerProposalItem;
use App\Support\Agents\KnownModels;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Agents')] class extends Component
{
    private const array CURATOR_MISSIONS = [
        'todo' => 'curator:todo',
        'tidy' => 'curator:tidy',
        'conformance' => 'curator:conformance',
    ];

    /** @var array<string, string> */
    public array $providerId = [];

    /** @var array<string, string> */
    public array $model = [];

    /** @var array<string, bool> */
    public array $enabled = [];

    /** @var array<string, string> */
    public array $runMessage = [];

    /** @var array<string, bool> */
    public array $justSaved = [];

    public string $historyFilterAgent = '';

    public string $historyFilterStatus = '';

    public string $historyFilterPeriod = '';

    public ?int $selectedRunId = null;

    public string $reviewType = 'weekly';

    public string $reviewPeriodStart = '';

    public string $reviewPeriodEnd = '';

    public string $plannerPeriodStart = '';

    public string $plannerPeriodEnd = '';

    public function mount(): void
    {
        foreach ($this->agentNames() as $agentName) {
            if (! $agentName->isAvailable()) {
                continue;
            }

            $this->providerId[$agentName->value] = '';
            $this->model[$agentName->value] = '';
            $this->enabled[$agentName->value] = true;
        }

        foreach (AgentConfig::whereIn('agent_name', array_keys($this->providerId))->get() as $config) {
            $this->providerId[$config->agent_name] = (string) $config->ai_provider_id;
            $this->model[$config->agent_name] = $config->model;
            $this->enabled[$config->agent_name] = $config->enabled;
        }
    }

    /**
     * @return list<AgentName>
     */
    public function agentNames(): array
    {
        return AgentName::cases();
    }

    #[Computed]
    public function activeProviders(): Collection
    {
        return AiProvider::where('is_active', true)->orderBy('name')->get();
    }

    public function agentConfig(string $agentName): ?AgentConfig
    {
        return AgentConfig::where('agent_name', $agentName)->first();
    }

    public function agentLastRun(string $agentName): ?AgentRun
    {
        return AgentRun::where('agent_name', $agentName)->latest('id')->first();
    }

    /**
     * @return list<string>
     */
    public function knownModelsFor(string $agentName): array
    {
        $providerId = $this->providerId[$agentName] ?? '';

        if ($providerId === '') {
            return [];
        }

        return KnownModels::forProvider(AiProvider::find($providerId)?->name);
    }

    public function saveAgentConfig(string $agentName): void
    {
        $this->justSaved[$agentName] = false;

        $validated = $this->validate([
            "providerId.{$agentName}" => ['required', 'exists:ai_providers,id'],
            "model.{$agentName}" => ['required', 'string', 'max:255'],
            "enabled.{$agentName}" => ['boolean'],
        ]);

        AgentConfig::updateOrCreate(
            ['agent_name' => $agentName],
            [
                'ai_provider_id' => $validated['providerId'][$agentName],
                'model' => $validated['model'][$agentName],
                'enabled' => $validated['enabled'][$agentName],
            ]
        );

        $this->justSaved[$agentName] = true;
    }

    public function runReviewerNow(): void
    {
        // Fail fast synchronously on the cheap "is it configured" check — the
        // actual LLM call is queued so a slow provider can't block this request
        // up to AgentRunner's 120s HTTP timeout.
        if (! AgentConfig::where('agent_name', 'reviewer')->where('enabled', true)->exists()) {
            $this->addError('run.reviewer', "Reviewer n'est pas configuré.");

            return;
        }

        if (($this->reviewPeriodStart === '') !== ($this->reviewPeriodEnd === '')) {
            $this->addError('run.reviewer', 'Renseigne une date de début et de fin, ou aucune des deux.');

            return;
        }

        $arguments = ['--type' => $this->reviewType];

        if ($this->reviewPeriodStart !== '' && $this->reviewPeriodEnd !== '') {
            $arguments['--start'] = $this->reviewPeriodStart;
            $arguments['--end'] = $this->reviewPeriodEnd;
        }

        RunAgentCommandJob::dispatch('review:generate', $arguments);

        $this->runMessage['reviewer'] = 'Lancement en cours, mis en file d\'attente…';
        $this->resetErrorBag('run.reviewer');
    }

    public function runCuratorMission(string $mission): void
    {
        $command = self::CURATOR_MISSIONS[$mission] ?? null;

        if (! $command) {
            return;
        }

        if (! AgentConfig::where('agent_name', 'curateur')->where('enabled', true)->exists()) {
            $this->addError('run.curateur', "Curateur n'est pas configuré.");

            return;
        }

        RunAgentCommandJob::dispatch($command);

        $this->runMessage['curateur'] = 'Lancement en cours, mis en file d\'attente…';
        $this->resetErrorBag('run.curateur');
    }

    public function runTriageNow(): void
    {
        if (! AgentConfig::where('agent_name', 'triage')->where('enabled', true)->exists()) {
            $this->addError('run.triage', "Triage n'est pas configuré.");

            return;
        }

        RunAgentCommandJob::dispatch('triage:run');

        $this->runMessage['triage'] = 'Lancement en cours, mis en file d\'attente…';
        $this->resetErrorBag('run.triage');
    }

    public function runPlannerNow(): void
    {
        if (! AgentConfig::where('agent_name', 'planner')->where('enabled', true)->exists()) {
            $this->addError('run.planner', "Planner n'est pas configuré.");

            return;
        }

        if (($this->plannerPeriodStart === '') !== ($this->plannerPeriodEnd === '')) {
            $this->addError('run.planner', 'Renseigne une date de début et de fin, ou aucune des deux.');

            return;
        }

        $arguments = [];

        if ($this->plannerPeriodStart !== '' && $this->plannerPeriodEnd !== '') {
            $arguments['--start'] = $this->plannerPeriodStart;
            $arguments['--end'] = $this->plannerPeriodEnd;
        }

        RunAgentCommandJob::dispatch('planner:generate', $arguments);

        $this->runMessage['planner'] = 'Lancement en cours, mis en file d\'attente…';
        $this->resetErrorBag('run.planner');
    }

    #[Computed]
    public function plannerProposal(): ?PlannerProposal
    {
        if (! $this->selectedRun || $this->selectedRun->agent_name !== 'planner') {
            return null;
        }

        return PlannerProposal::where('agent_run_id', $this->selectedRun->id)->with('items.task')->first();
    }

    public function applyPlannerItem(int $itemId): void
    {
        $item = PlannerProposalItem::findOrFail($itemId);
        $item->task->update(['scheduled_date' => $item->proposed_scheduled_date]);
        $item->update(['status' => PlannerProposalStatus::Applied]);

        unset($this->plannerProposal);
    }

    public function dismissPlannerItem(int $itemId): void
    {
        PlannerProposalItem::where('id', $itemId)->update(['status' => PlannerProposalStatus::Dismissed]);

        unset($this->plannerProposal);
    }

    public function applyAllPlannerItems(int $proposalId): void
    {
        $proposal = PlannerProposal::with('items.task')->findOrFail($proposalId);

        foreach ($proposal->items->where('status', PlannerProposalStatus::Pending) as $item) {
            $item->task->update(['scheduled_date' => $item->proposed_scheduled_date]);
            $item->update(['status' => PlannerProposalStatus::Applied]);
        }

        $proposal->update(['status' => PlannerProposalStatus::Applied]);

        unset($this->plannerProposal);
    }

    public function dismissAllPlannerItems(int $proposalId): void
    {
        $proposal = PlannerProposal::findOrFail($proposalId);
        $proposal->items()->where('status', PlannerProposalStatus::Pending)->update(['status' => PlannerProposalStatus::Dismissed]);
        $proposal->update(['status' => PlannerProposalStatus::Dismissed]);

        unset($this->plannerProposal);
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    #[Computed]
    public function historyAgents(): \Illuminate\Support\Collection
    {
        return AgentRun::query()->distinct()->orderBy('agent_name')->pluck('agent_name');
    }

    #[Computed]
    public function runHistory(): Collection
    {
        return AgentRun::query()
            ->when($this->historyFilterAgent !== '', fn ($query) => $query->where('agent_name', $this->historyFilterAgent))
            ->when($this->historyFilterStatus !== '', fn ($query) => $query->where('status', $this->historyFilterStatus))
            ->when($this->historyFilterPeriod === '7', fn ($query) => $query->where('started_at', '>=', now()->subDays(7)))
            ->when($this->historyFilterPeriod === '30', fn ($query) => $query->where('started_at', '>=', now()->subDays(30)))
            ->latest('started_at')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function selectedRun(): ?AgentRun
    {
        return $this->selectedRunId ? AgentRun::find($this->selectedRunId) : null;
    }

    public function showRunDetail(int $runId): void
    {
        $this->selectedRunId = $runId;
    }

    #[Computed]
    public function tokensThisMonth(): int
    {
        return $this->tokensForMonth(now());
    }

    #[Computed]
    public function tokensLastMonth(): int
    {
        return $this->tokensForMonth(now()->subMonth());
    }

    /**
     * La jauge de consommation suit la grammaire des jauges de saison — même
     * composant, autre donnée. Faute d'un budget configuré, la référence est
     * le mois précédent : c'est la seule comparaison honnête disponible.
     *
     * @return array{percent: int, label: string, tone: string}
     */
    #[Computed]
    public function tokenBudget(): array
    {
        $reference = $this->tokensLastMonth;

        if ($reference === 0) {
            return ['percent' => 0, 'label' => 'premier mois mesuré', 'tone' => 'ai'];
        }

        $percent = (int) round(($this->tokensThisMonth / $reference) * 100);

        return [
            'percent' => min(100, $percent),
            'label' => match (true) {
                $percent <= 80 => 'sous le mois dernier',
                $percent <= 110 => 'au rythme du mois dernier',
                default => 'au-dessus du mois dernier',
            },
            'tone' => $percent <= 110 ? 'ai' : 'warn',
        ];
    }

    private function tokensForMonth(\Carbon\CarbonInterface $month): int
    {
        return (int) AgentRun::whereMonth('started_at', $month->month)
            ->whereYear('started_at', $month->year)
            ->selectRaw('COALESCE(SUM(tokens_in), 0) + COALESCE(SUM(tokens_out), 0) as total')
            ->value('total');
    }
};
?>

<div wire:poll.5s="$refresh">
    @php
        $availableAgents = collect($this->agentNames())->filter(fn ($agent) => $agent->isAvailable());
        $activeAgents = $availableAgents->filter(fn ($agent) => ! empty($enabled[$agent->value]))->count();
    @endphp

    {{-- Les agents système sont l'onglet « réglage » de l'espace Hermès : ils
         tournent tout seuls, on vient ici choisir leur modèle, pas les lancer. --}}
    <x-space-tabs space="hermes" class="mb-3.5 -ml-3.5" />

    <x-screen-header
        :kicker="count($this->agentNames()).' agents · '.$activeAgents.' '.Str::plural('actif', $activeAgents)"
        title="Agents système"
        class="mb-4"
    >
        <x-slot:actions>
            {{-- Même jauge que les jauges de saison : 6px, un aplat, un mot. --}}
            <div class="w-[300px]">
                <div class="mb-2 flex items-baseline gap-2">
                    <span class="font-display text-[20px] font-bold text-(--tx)">{{ number_format($this->tokensThisMonth, 0, ',', ' ') }}</span>
                    <span class="text-[13px] text-(--mut)">tokens ce mois-ci</span>
                    <span class="ml-auto text-[12px] {{ $this->tokenBudget['tone'] === 'warn' ? 'text-(--warn)' : 'text-(--ai)' }}">{{ $this->tokenBudget['label'] }}</span>
                </div>
                <div class="h-[6px] rounded-full bg-(--surf2)">
                    <div
                        class="h-[6px] rounded-full {{ $this->tokenBudget['tone'] === 'warn' ? 'bg-(--warn)' : 'bg-(--ai)' }}"
                        style="width: {{ $this->tokenBudget['percent'] }}%"
                    ></div>
                </div>
            </div>
        </x-slot:actions>
    </x-screen-header>

    <div class="mb-[26px] grid items-start gap-3.5" style="grid-template-columns: repeat(auto-fill, minmax(380px, 1fr))">
        @foreach ($this->agentNames() as $agentName)
            @if ($agentName->isAvailable())
                @php
                    $name = $agentName->value;
                    $lastRun = $this->agentLastRun($name);
                @endphp
                <x-card padding="22">
                    <div class="mb-2 flex items-center gap-3">
                        <span class="font-display text-[19px] font-semibold text-(--tx)">{{ $agentName->label() }}</span>
                        <x-badge type="pill" :tone="! empty($enabled[$name]) ? 'agent' : 'sunk'" class="!text-[12px]">
                            {{ ! empty($enabled[$name]) ? 'actif' : 'inactif' }}
                        </x-badge>
                        <x-toggle
                            class="ml-auto"
                            wire:model="enabled.{{ $name }}"
                            wire:change="saveAgentConfig('{{ $name }}')"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50"
                            wire:target="saveAgentConfig('{{ $name }}')"
                        />
                    </div>

                    <p class="mb-3.5 text-[14px] leading-[1.6] text-(--mut)">{{ $agentName->description() }}</p>

                    @php $knownModels = $this->knownModelsFor($name); @endphp
                    <div class="border-t border-(--bd) pt-3.5">
                        <p class="mb-3 text-[11px] text-(--mut) kicker">Configuration</p>

                        <div
                            class="grid grid-cols-2 gap-2"
                            wire:loading.class="opacity-50"
                            wire:target="saveAgentConfig('{{ $name }}')"
                        >
                            <select wire:model.live="providerId.{{ $name }}" wire:loading.attr="disabled" wire:target="saveAgentConfig('{{ $name }}')" class="rounded-full bg-(--surf2) px-3.5 py-2 text-[13px] text-(--tx) focus:outline-none">
                                <option value="">Fournisseur…</option>
                                @foreach ($this->activeProviders as $provider)
                                    <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                                @endforeach
                            </select>
                            @if (count($knownModels))
                                <select wire:model="model.{{ $name }}" wire:loading.attr="disabled" wire:target="saveAgentConfig('{{ $name }}')" class="rounded-full bg-(--surf2) px-3.5 py-2 text-[13px] text-(--tx) focus:outline-none">
                                    <option value="">modèle…</option>
                                    @foreach ($knownModels as $modelId)
                                        <option value="{{ $modelId }}">{{ $modelId }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input
                                    type="text"
                                    wire:model="model.{{ $name }}"
                                    wire:loading.attr="disabled"
                                    wire:target="saveAgentConfig('{{ $name }}')"
                                    placeholder="modèle"
                                    class="rounded-full bg-(--surf2) px-3.5 py-2 text-[13px] text-(--tx) focus:outline-none"
                                />
                            @endif
                        </div>
                        @error("providerId.{$name}") <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                        @error("model.{$name}") <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror

                        <div class="mt-3 flex items-center justify-end gap-2">
                            @if (! empty($justSaved[$name]) && ! $errors->has("providerId.{$name}") && ! $errors->has("model.{$name}"))
                                <p class="text-xs text-(--ok)" wire:loading.remove wire:target="saveAgentConfig('{{ $name }}')">✓ Enregistré</p>
                            @endif
                            <x-btn
                                variant="secondary"
                                class="!px-3 !py-1.5 text-xs"
                                wire:click="saveAgentConfig('{{ $name }}')"
                                wire:loading.attr="disabled"
                                wire:target="saveAgentConfig('{{ $name }}')"
                            >
                                <span wire:loading wire:target="saveAgentConfig('{{ $name }}')">Enregistrement…</span>
                                <span wire:loading.remove wire:target="saveAgentConfig('{{ $name }}')">Enregistrer</span>
                            </x-btn>
                        </div>
                    </div>

                    {{-- Le pied de carte est la ligne de vérité : quand est-ce
                         passé, combien ça a coûté, et le bouton pour relancer.
                         Les identifiants et les métriques restent en mono —
                         c'est la deuxième et dernière exception du système. --}}
                    <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-(--bd) pt-3.5">
                        @if ($lastRun)
                            <span class="flex items-center gap-2 text-[13px] {{ $lastRun->status->value === 'failed' ? 'text-(--dgr)' : 'text-(--ai)' }}">
                                <span class="size-[7px] rounded-full {{ $lastRun->status->value === 'failed' ? 'bg-(--dgr)' : 'bg-(--ai)' }}" aria-hidden="true"></span>
                                {{ $lastRun->started_at->translatedFormat('D j M · H:i') }}
                            </span>
                            <span class="font-mono text-[12px] text-(--mut)">
                                @if ($lastRun->finished_at){{ (int) $lastRun->started_at->diffInSeconds($lastRun->finished_at) }} s @endif
                                @if ($lastRun->tokens_in || $lastRun->tokens_out)· {{ number_format(($lastRun->tokens_in ?? 0) + ($lastRun->tokens_out ?? 0), 0, ',', ' ') }} tk @endif
                            </span>
                        @else
                            <span class="text-[13px] text-(--mut)">Aucun passage pour l'instant.</span>
                        @endif
                    </div>

                    <div class="mt-3.5">

                        @if ($name === 'reviewer')
                            <div class="mt-3 grid grid-cols-3 gap-2">
                                <div>
                                    <label class="text-[12px] text-(--mut)">Type</label>
                                    <select wire:model="reviewType" class="mt-1.5 w-full rounded-full bg-(--surf2) px-3.5 py-2 text-[13px] text-(--tx) focus:outline-none">
                                        <option value="weekly">Hebdo</option>
                                        <option value="monthly">Mensuelle</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[12px] text-(--mut)">Début</label>
                                    <input type="date" wire:model="reviewPeriodStart" class="mt-1.5 w-full rounded-full bg-(--surf2) px-3.5 py-2 text-[13px] text-(--tx) focus:outline-none" />
                                </div>
                                <div>
                                    <label class="text-[12px] text-(--mut)">Fin</label>
                                    <input type="date" wire:model="reviewPeriodEnd" class="mt-1.5 w-full rounded-full bg-(--surf2) px-3.5 py-2 text-[13px] text-(--tx) focus:outline-none" />
                                </div>
                            </div>
                            <p class="mt-2 text-[12px] text-(--mut)">Début et fin laissés vides : période par défaut (depuis la dernière revue hebdo, ou 7 jours).</p>
                        @elseif ($name === 'planner')
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[12px] text-(--mut)">Début</label>
                                    <input type="date" wire:model="plannerPeriodStart" class="mt-1.5 w-full rounded-full bg-(--surf2) px-3.5 py-2 text-[13px] text-(--tx) focus:outline-none" />
                                </div>
                                <div>
                                    <label class="text-[12px] text-(--mut)">Fin</label>
                                    <input type="date" wire:model="plannerPeriodEnd" class="mt-1.5 w-full rounded-full bg-(--surf2) px-3.5 py-2 text-[13px] text-(--tx) focus:outline-none" />
                                </div>
                            </div>
                            <p class="mt-2 text-[12px] text-(--mut)">Début et fin laissés vides : la semaine à venir par défaut.</p>
                        @endif

                        @error("run.{$name}") <p class="mt-2 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                        @if (! empty($runMessage[$name]) && ! $errors->has("run.{$name}"))
                            <p class="mt-2 text-xs text-(--ok)">{{ $runMessage[$name] }}</p>
                        @endif

                        @if ($name === 'reviewer')
                            <div class="mt-3 flex justify-end">
                                <x-btn variant="agent" wire:click="runReviewerNow" wire:loading.attr="disabled" wire:target="runReviewerNow">
                                    <span wire:loading wire:target="runReviewerNow">En cours…</span>
                                    <span wire:loading.remove wire:target="runReviewerNow">Lancer maintenant</span>
                                </x-btn>
                            </div>
                        @elseif ($name === 'curateur')
                            <div class="mt-3 flex justify-end gap-2">
                                <x-btn variant="secondary" class="!px-3 !py-1.5 text-xs" wire:click="runCuratorMission('todo')" wire:loading.attr="disabled" wire:target="runCuratorMission('todo')">
                                    <span wire:loading wire:target="runCuratorMission('todo')">En cours…</span>
                                    <span wire:loading.remove wire:target="runCuratorMission('todo')">Lancer la todo</span>
                                </x-btn>
                                <x-btn variant="secondary" class="!px-3 !py-1.5 text-xs" wire:click="runCuratorMission('conformance')" wire:loading.attr="disabled" wire:target="runCuratorMission('conformance')">
                                    <span wire:loading wire:target="runCuratorMission('conformance')">En cours…</span>
                                    <span wire:loading.remove wire:target="runCuratorMission('conformance')">Vérifier la conformité</span>
                                </x-btn>
                                <x-btn variant="agent" wire:click="runCuratorMission('tidy')" wire:loading.attr="disabled" wire:target="runCuratorMission('tidy')">
                                    <span wire:loading wire:target="runCuratorMission('tidy')">En cours…</span>
                                    <span wire:loading.remove wire:target="runCuratorMission('tidy')">Lancer le rangement</span>
                                </x-btn>
                            </div>
                        @elseif ($name === 'triage')
                            <div class="mt-3 flex justify-end">
                                <x-btn variant="agent" wire:click="runTriageNow" wire:loading.attr="disabled" wire:target="runTriageNow">
                                    <span wire:loading wire:target="runTriageNow">En cours…</span>
                                    <span wire:loading.remove wire:target="runTriageNow">Lancer maintenant</span>
                                </x-btn>
                            </div>
                        @elseif ($name === 'planner')
                            <div class="mt-3 flex justify-end">
                                <x-btn variant="agent" wire:click="runPlannerNow" wire:loading.attr="disabled" wire:target="runPlannerNow">
                                    <span wire:loading wire:target="runPlannerNow">En cours…</span>
                                    <span wire:loading.remove wire:target="runPlannerNow">Lancer maintenant</span>
                                </x-btn>
                            </div>
                        @endif
                    </div>
                </x-card>
            @else
                <x-card tone="sunk" padding="22">
                    <div class="mb-2 flex items-center gap-3">
                        <span class="font-display text-[19px] font-semibold text-(--mut)">{{ $agentName->label() }}</span>
                        <x-badge type="pill" tone="sunk" class="!text-[12px]">inactif</x-badge>
                        <x-toggle class="ml-auto pointer-events-none opacity-60" disabled />
                    </div>
                    <p class="mb-3.5 text-[14px] leading-[1.6] text-(--mut)">{{ $agentName->description() }}</p>
                    <p class="font-mono text-[12px] text-(--mut)">non configuré</p>
                </x-card>
            @endif
        @endforeach
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-3">
        <h2 class="font-display text-[17px] font-semibold text-(--tx)">Historique des runs</h2>
        <span class="text-[13px] text-(--mut)">
            {{ $this->runHistory->count() }} {{ Str::plural('run', $this->runHistory->count()) }} ·
            {{ $this->runHistory->where('status.value', 'failed')->count() }} {{ Str::plural('échec', $this->runHistory->where('status.value', 'failed')->count()) }}
        </span>

        <div class="ml-auto flex flex-wrap gap-2">
            <select wire:model.live="historyFilterAgent" class="rounded-full bg-(--surf) px-3.5 py-2 text-[13px] text-(--mut) focus:outline-none">
                <option value="">Tous les agents</option>
                @foreach ($this->historyAgents as $agentValue)
                    <option value="{{ $agentValue }}">{{ $agentValue }}</option>
                @endforeach
            </select>
            <select wire:model.live="historyFilterStatus" class="rounded-full bg-(--surf) px-3.5 py-2 text-[13px] text-(--mut) focus:outline-none">
                <option value="">Tous les statuts</option>
                @foreach (\App\Enums\AgentRunStatus::cases() as $status)
                    <option value="{{ $status->value }}">{{ $status->value }}</option>
                @endforeach
            </select>
            <select wire:model.live="historyFilterPeriod" class="rounded-full bg-(--surf) px-3.5 py-2 text-[13px] text-(--mut) focus:outline-none">
                <option value="">Toute période</option>
                <option value="7">7 derniers jours</option>
                <option value="30">30 derniers jours</option>
            </select>
        </div>
    </div>

    {{-- Le tableau d'historique est en mode compact, comme l'écran Tâches. --}}
    <div class="overflow-x-auto pb-6">
        <div class="min-w-[860px]">
            <div class="flex items-center gap-4 border-b border-(--bd2) px-2 py-2 text-[11px] text-(--mut) kicker">
                <span class="w-[20px]"></span>
                <span class="w-[200px]">Agent</span>
                <span class="w-[160px]">Quand</span>
                <span class="w-[90px]">Durée</span>
                <span class="w-[110px]">Tokens</span>
                <span class="flex-1">Résultat</span>
            </div>

            @forelse ($this->runHistory as $run)
                @php
                    $failed = $run->status->value === 'failed';
                    $running = $run->status->value === 'running';
                @endphp
                <button
                    type="button"
                    wire:click="showRunDetail({{ $run->id }})"
                    wire:key="run-{{ $run->id }}"
                    class="press-row flex w-full items-center gap-4 border-b border-(--bd) px-2 py-[9px] text-left text-[14px] hover:bg-(--surf)"
                >
                    <span
                        @class([
                            'w-[20px]',
                            'text-(--dgr)' => $failed,
                            'text-(--ai)' => ! $failed,
                            'animate-pulse-soft' => $running,
                        ])
                        aria-hidden="true"
                    >{{ $failed ? '✕' : ($running ? '●' : '✓') }}</span>
                    <span class="w-[200px] truncate text-(--tx)">{{ $run->agent_name }}</span>
                    <span class="w-[160px] text-[13px] text-(--mut)">{{ $run->started_at->translatedFormat('D j M · H:i') }}</span>
                    <span class="w-[90px] font-mono text-[13px] text-(--mut)">
                        @if ($run->finished_at){{ (int) $run->started_at->diffInSeconds($run->finished_at) }}s @else — @endif
                    </span>
                    <span class="w-[110px] font-mono text-[13px] text-(--mut)">
                        {{ number_format(($run->tokens_in ?? 0) + ($run->tokens_out ?? 0), 0, ',', ' ') }}
                    </span>
                    <span class="min-w-0 flex-1 truncate text-[13px] {{ $failed ? 'text-(--dgr)' : 'text-(--mut)' }}">
                        {{ $failed ? Str::limit((string) $run->error, 80) : ($running ? 'en cours' : $run->trigger->value) }}
                    </span>
                </button>
            @empty
                <p class="py-8 text-[15px] text-(--mut)">Aucun run pour l'instant.</p>
            @endforelse
        </div>
    </div>

    <flux:modal wire:model.self="selectedRunId" class="md:w-[560px]">
        @if ($this->selectedRun)
            <div class="space-y-4">
                <div class="flex items-center gap-2">
                    <flux:heading size="lg">{{ $this->selectedRun->agent_name }}</flux:heading>
                    <x-badge type="agent-run" :value="$this->selectedRun->status->value" />
                </div>

                @if ($this->selectedRun->error)
                    <div>
                        <p class="text-xs font-semibold text-(--mut)">Erreur</p>
                        <pre class="mt-1 max-h-40 overflow-y-auto rounded-[8px] bg-(--surf2) p-3 text-xs text-(--dgr)">{{ $this->selectedRun->error }}</pre>
                    </div>
                @endif

                @if ($this->selectedRun->output && ! $this->plannerProposal)
                    <div>
                        <p class="text-xs font-semibold text-(--mut)">Sortie</p>
                        <pre class="mt-1 max-h-64 overflow-y-auto whitespace-pre-wrap rounded-[8px] bg-(--surf2) p-3 text-xs text-(--tx)">{{ $this->selectedRun->output }}</pre>
                    </div>
                @endif

                @if ($this->plannerProposal)
                    <div>
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold text-(--mut)">Proposition de planification</p>
                            @if ($this->plannerProposal->items->where('status', PlannerProposalStatus::Pending)->isNotEmpty())
                                <div class="flex gap-2">
                                    <x-btn variant="ghost" class="!px-2.5 !py-1 text-xs" wire:click="dismissAllPlannerItems({{ $this->plannerProposal->id }})">Ignorer tout</x-btn>
                                    <x-btn variant="agent" class="!px-2.5 !py-1 text-xs" wire:click="applyAllPlannerItems({{ $this->plannerProposal->id }})">Appliquer tout</x-btn>
                                </div>
                            @endif
                        </div>
                        <div class="mt-2 max-h-64 space-y-2 overflow-y-auto">
                            @forelse ($this->plannerProposal->items as $item)
                                <div class="flex items-center justify-between gap-3 rounded-[8px] bg-(--surf2) px-3 py-2" wire:key="planner-item-{{ $item->id }}">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs text-(--tx)">{{ $item->task->title }}</p>
                                        <p class="font-mono text-[10.5px] text-(--mut)">
                                            {{ $item->task->scheduled_date?->translatedFormat('d M') ?? 'non planifiée' }} → {{ $item->proposed_scheduled_date->translatedFormat('d M') }}
                                            — {{ $item->reason }}
                                        </p>
                                    </div>
                                    @if ($item->status === PlannerProposalStatus::Pending)
                                        <div class="flex shrink-0 gap-2">
                                            <x-btn variant="secondary" class="!px-2.5 !py-1 text-xs" wire:click="dismissPlannerItem({{ $item->id }})">Ignorer</x-btn>
                                            <x-btn variant="agent" class="!px-2.5 !py-1 text-xs" wire:click="applyPlannerItem({{ $item->id }})">Appliquer</x-btn>
                                        </div>
                                    @else
                                        <x-badge type="status" :value="$item->status->value === 'applied' ? 'active' : 'paused'" :label="$item->status->value" />
                                    @endif
                                </div>
                            @empty
                                <p class="p-3 text-center text-xs text-(--mut)">Aucune tâche proposée sur cette période.</p>
                            @endforelse
                        </div>
                    </div>
                @endif

                <div class="flex justify-end">
                    <x-btn variant="ghost" wire:click="$set('selectedRunId', null)">Fermer</x-btn>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
