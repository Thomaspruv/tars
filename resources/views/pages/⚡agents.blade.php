<?php

use App\Enums\AgentName;
use App\Jobs\RunAgentCommandJob;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\AiProvider;
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
    ];

    /** @var array<string, string> */
    public array $providerId = [];

    /** @var array<string, string> */
    public array $model = [];

    /** @var array<string, bool> */
    public array $enabled = [];

    /** @var array<string, string> */
    public array $runMessage = [];

    public string $historyFilterAgent = '';

    public string $historyFilterStatus = '';

    public string $historyFilterPeriod = '';

    public ?int $selectedRunId = null;

    public string $reviewType = 'weekly';

    public string $reviewPeriodStart = '';

    public string $reviewPeriodEnd = '';

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
        return (int) AgentRun::whereMonth('started_at', now()->month)
            ->whereYear('started_at', now()->year)
            ->selectRaw('COALESCE(SUM(tokens_in), 0) + COALESCE(SUM(tokens_out), 0) as total')
            ->value('total');
    }
};
?>

<div wire:poll.5s="$refresh">
    <div class="flex items-center justify-between">
        <h1 class="text-[28px] font-bold tracking-[-0.02em] text-(--tx)">Agents</h1>
        <p class="font-mono text-xs text-(--mut)">{{ number_format($this->tokensThisMonth) }} tokens ce mois-ci</p>
    </div>

    <div class="mt-6 grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr))">
        @foreach ($this->agentNames() as $agentName)
            @if ($agentName->isAvailable())
                @php
                    $name = $agentName->value;
                    $lastRun = $this->agentLastRun($name);
                @endphp
                <div class="rounded-[14px] border border-(--ai)/25 bg-(--surf) p-5">
                    <div class="flex items-center gap-2.5">
                        <span class="flex size-9 items-center justify-center rounded-[10px] bg-(--aibg) text-(--ai)">◈</span>
                        <div>
                            <p class="font-mono text-sm font-semibold text-(--tx)">{{ $agentName->value }}</p>
                            <p class="text-xs text-(--mut)">{{ $agentName->description() }}</p>
                        </div>
                        <label class="ml-auto flex items-center gap-2 text-xs text-(--mut)">
                            <input type="checkbox" wire:model="enabled.{{ $name }}" wire:change="saveAgentConfig('{{ $name }}')" class="rounded-[4px] border-(--bd2) text-(--ai) focus:ring-0" />
                            Activé
                        </label>
                    </div>

                    @php $knownModels = $this->knownModelsFor($name); @endphp
                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <select wire:model.live="providerId.{{ $name }}" class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)">
                            <option value="">Fournisseur…</option>
                            @foreach ($this->activeProviders as $provider)
                                <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                            @endforeach
                        </select>
                        @if (count($knownModels))
                            <select wire:model="model.{{ $name }}" class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)">
                                <option value="">modèle…</option>
                                @foreach ($knownModels as $modelId)
                                    <option value="{{ $modelId }}">{{ $modelId }}</option>
                                @endforeach
                            </select>
                        @else
                            <input
                                type="text"
                                wire:model="model.{{ $name }}"
                                placeholder="modèle"
                                class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)"
                            />
                        @endif
                    </div>
                    @error("providerId.{$name}") <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                    @error("model.{$name}") <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror

                    <div class="mt-3 flex justify-end">
                        <x-btn variant="secondary" class="!px-3 !py-1.5 text-xs" wire:click="saveAgentConfig('{{ $name }}')">Enregistrer</x-btn>
                    </div>

                    <div class="mt-4 rounded-[10px] bg-(--surf2) px-3 py-2 font-mono text-[11px] text-(--mut)">
                        @if ($lastRun)
                            <x-badge-status :status="$lastRun->status->value" />
                            {{ $lastRun->started_at->translatedFormat('d M · H:i') }}
                            @if ($lastRun->finished_at)
                                · {{ $lastRun->started_at->diffInSeconds($lastRun->finished_at) }}s
                            @endif
                            @if ($lastRun->tokens_in || $lastRun->tokens_out)
                                · {{ number_format(($lastRun->tokens_in ?? 0) + ($lastRun->tokens_out ?? 0)) }} tk
                            @endif
                        @else
                            Aucun run pour l'instant.
                        @endif
                    </div>

                    @if ($name === 'reviewer')
                        <div class="mt-4 grid grid-cols-3 gap-2">
                            <select wire:model="reviewType" class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)">
                                <option value="weekly">Hebdo</option>
                                <option value="monthly">Mensuelle</option>
                            </select>
                            <input type="date" wire:model="reviewPeriodStart" class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)" />
                            <input type="date" wire:model="reviewPeriodEnd" class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)" />
                        </div>
                        <p class="mt-1 text-[10.5px] text-(--mut)">Dates laissées vides : période par défaut (depuis la dernière revue hebdo, ou 7 jours).</p>
                    @endif

                    @error("run.{$name}") <p class="mt-2 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                    @if (! empty($runMessage[$name]) && ! $errors->has("run.{$name}"))
                        <p class="mt-2 text-xs text-(--ok)">{{ $runMessage[$name] }}</p>
                    @endif

                    @if ($name === 'reviewer')
                        <div class="mt-3 flex justify-end">
                            <x-btn variant="ai" wire:click="runReviewerNow" wire:loading.attr="disabled" wire:target="runReviewerNow">
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
                            <x-btn variant="ai" wire:click="runCuratorMission('tidy')" wire:loading.attr="disabled" wire:target="runCuratorMission('tidy')">
                                <span wire:loading wire:target="runCuratorMission('tidy')">En cours…</span>
                                <span wire:loading.remove wire:target="runCuratorMission('tidy')">Lancer le rangement</span>
                            </x-btn>
                        </div>
                    @endif
                </div>
            @else
                <div class="rounded-[14px] border border-(--bd) bg-(--surf) p-5 opacity-55">
                    <div class="flex items-center gap-2.5">
                        <span class="flex size-9 items-center justify-center rounded-[10px] bg-(--surf2) text-(--mut)">◈</span>
                        <div>
                            <p class="font-mono text-sm font-semibold text-(--tx)">{{ $agentName->value }}</p>
                            <p class="text-xs text-(--mut)">{{ $agentName->description() }}</p>
                        </div>
                    </div>
                    <p class="mt-4 font-mono text-[10.5px] uppercase tracking-wide text-(--mut)">À venir</p>
                </div>
            @endif
        @endforeach
    </div>

    <div class="mt-8">
        <h2 class="text-base font-semibold text-(--tx)">Historique des runs</h2>

        <div class="mt-3 flex flex-wrap gap-2">
            <select wire:model.live="historyFilterAgent" class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)">
                <option value="">Tous les agents</option>
                @foreach ($this->historyAgents as $agentValue)
                    <option value="{{ $agentValue }}">{{ $agentValue }}</option>
                @endforeach
            </select>
            <select wire:model.live="historyFilterStatus" class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)">
                <option value="">Tous les statuts</option>
                @foreach (\App\Enums\AgentRunStatus::cases() as $status)
                    <option value="{{ $status->value }}">{{ $status->value }}</option>
                @endforeach
            </select>
            <select wire:model.live="historyFilterPeriod" class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)">
                <option value="">Toute période</option>
                <option value="7">7 derniers jours</option>
                <option value="30">30 derniers jours</option>
            </select>
        </div>

        <div class="mt-3 max-h-96 space-y-1 overflow-y-auto rounded-[14px] border border-(--bd) bg-(--surf) p-2">
            @forelse ($this->runHistory as $run)
                <button
                    type="button"
                    wire:click="showRunDetail({{ $run->id }})"
                    class="flex w-full items-center gap-2 rounded-[8px] px-2 py-1.5 text-left hover:bg-(--surf2)"
                    wire:key="run-{{ $run->id }}"
                >
                    <span class="font-mono text-[10.5px] text-(--tx)">{{ $run->agent_name }}</span>
                    <x-badge-status :status="$run->status->value" />
                    <span class="font-mono text-[10.5px] text-(--mut)">{{ $run->trigger->value }}</span>
                    <span class="ml-auto font-mono text-[10.5px] text-(--mut)">
                        {{ number_format(($run->tokens_in ?? 0) + ($run->tokens_out ?? 0)) }} tk
                    </span>
                    <span class="font-mono text-[10.5px] text-(--mut)">{{ $run->started_at->translatedFormat('d M H:i') }}</span>
                </button>
            @empty
                <p class="p-5 text-center text-sm text-(--mut)">Aucun run pour l'instant.</p>
            @endforelse
        </div>
    </div>

    <flux:modal wire:model.self="selectedRunId" class="md:w-[560px]">
        @if ($this->selectedRun)
            <div class="space-y-4">
                <div class="flex items-center gap-2">
                    <flux:heading size="lg">{{ $this->selectedRun->agent_name }}</flux:heading>
                    <x-badge-status :status="$this->selectedRun->status->value" />
                </div>

                @if ($this->selectedRun->error)
                    <div>
                        <p class="text-xs font-semibold text-(--mut)">Erreur</p>
                        <pre class="mt-1 max-h-40 overflow-y-auto rounded-[8px] bg-(--surf2) p-3 text-xs text-(--dgr)">{{ $this->selectedRun->error }}</pre>
                    </div>
                @endif

                @if ($this->selectedRun->output)
                    <div>
                        <p class="text-xs font-semibold text-(--mut)">Sortie</p>
                        <pre class="mt-1 max-h-64 overflow-y-auto whitespace-pre-wrap rounded-[8px] bg-(--surf2) p-3 text-xs text-(--tx)">{{ $this->selectedRun->output }}</pre>
                    </div>
                @endif

                <div class="flex justify-end">
                    <x-btn variant="ghost" wire:click="$set('selectedRunId', null)">Fermer</x-btn>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
