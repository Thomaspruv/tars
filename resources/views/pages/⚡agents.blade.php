<?php

use App\Enums\AgentName;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Agents')] class extends Component
{
    public string $reviewerProviderId = '';

    public string $reviewerModel = '';

    public bool $reviewerEnabled = true;

    public ?string $reviewerRunMessage = null;

    public string $historyFilterAgent = '';

    public string $historyFilterStatus = '';

    public string $historyFilterPeriod = '';

    public ?int $selectedRunId = null;

    public function mount(): void
    {
        $config = $this->reviewerConfig;

        if ($config) {
            $this->reviewerProviderId = (string) $config->ai_provider_id;
            $this->reviewerModel = $config->model;
            $this->reviewerEnabled = $config->enabled;
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

    #[Computed]
    public function reviewerConfig(): ?AgentConfig
    {
        return AgentConfig::where('agent_name', AgentName::Reviewer->value)->first();
    }

    #[Computed]
    public function reviewerLastRun(): ?AgentRun
    {
        return AgentRun::where('agent_name', AgentName::Reviewer->value)->latest('id')->first();
    }

    public function saveReviewerConfig(): void
    {
        $validated = $this->validate([
            'reviewerProviderId' => ['required', 'exists:ai_providers,id'],
            'reviewerModel' => ['required', 'string', 'max:255'],
            'reviewerEnabled' => ['boolean'],
        ]);

        AgentConfig::updateOrCreate(
            ['agent_name' => AgentName::Reviewer->value],
            [
                'ai_provider_id' => $validated['reviewerProviderId'],
                'model' => $validated['reviewerModel'],
                'enabled' => $validated['reviewerEnabled'],
            ]
        );

        unset($this->reviewerConfig);
    }

    public function runReviewerNow(): void
    {
        $exitCode = Artisan::call('review:generate');

        $this->reviewerRunMessage = trim(Artisan::output()) ?: null;

        unset($this->reviewerLastRun, $this->runHistory, $this->historyAgents, $this->tokensThisMonth);

        if ($exitCode !== 0) {
            $this->addError('reviewerRun', $this->reviewerRunMessage ?? 'Échec du lancement.');
        }
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

<div>
    <div class="flex items-center justify-between">
        <h1 class="text-[28px] font-bold tracking-[-0.02em] text-(--tx)">Agents</h1>
        <p class="font-mono text-xs text-(--mut)">{{ number_format($this->tokensThisMonth) }} tokens ce mois-ci</p>
    </div>

    <div class="mt-6 grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr))">
        @foreach ($this->agentNames() as $agentName)
            @if ($agentName->isAvailable())
                <div class="rounded-[14px] border border-(--ai)/25 bg-(--surf) p-5">
                    <div class="flex items-center gap-2.5">
                        <span class="flex size-9 items-center justify-center rounded-[10px] bg-(--aibg) text-(--ai)">◈</span>
                        <div>
                            <p class="font-mono text-sm font-semibold text-(--tx)">{{ $agentName->value }}</p>
                            <p class="text-xs text-(--mut)">{{ $agentName->description() }}</p>
                        </div>
                        <label class="ml-auto flex items-center gap-2 text-xs text-(--mut)">
                            <input type="checkbox" wire:model="reviewerEnabled" wire:change="saveReviewerConfig" class="rounded-[4px] border-(--bd2) text-(--ai) focus:ring-0" />
                            Activé
                        </label>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <select wire:model="reviewerProviderId" class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)">
                            <option value="">Fournisseur…</option>
                            @foreach ($this->activeProviders as $provider)
                                <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                            @endforeach
                        </select>
                        <input
                            type="text"
                            wire:model="reviewerModel"
                            placeholder="modèle"
                            class="rounded-[8px] border border-(--bd2) bg-(--in) px-2.5 py-1.5 font-mono text-xs text-(--tx)"
                        />
                    </div>
                    @error('reviewerProviderId') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                    @error('reviewerModel') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror

                    <div class="mt-3 flex justify-end">
                        <x-btn variant="secondary" class="!px-3 !py-1.5 text-xs" wire:click="saveReviewerConfig">Enregistrer</x-btn>
                    </div>

                    <div class="mt-4 rounded-[10px] bg-(--surf2) px-3 py-2 font-mono text-[11px] text-(--mut)">
                        @if ($this->reviewerLastRun)
                            <x-badge-status :status="$this->reviewerLastRun->status->value" />
                            {{ $this->reviewerLastRun->started_at->translatedFormat('d M · H:i') }}
                            @if ($this->reviewerLastRun->finished_at)
                                · {{ $this->reviewerLastRun->started_at->diffInSeconds($this->reviewerLastRun->finished_at) }}s
                            @endif
                            @if ($this->reviewerLastRun->tokens_in || $this->reviewerLastRun->tokens_out)
                                · {{ number_format(($this->reviewerLastRun->tokens_in ?? 0) + ($this->reviewerLastRun->tokens_out ?? 0)) }} tk
                            @endif
                        @else
                            Aucun run pour l'instant.
                        @endif
                    </div>

                    @error('reviewerRun') <p class="mt-2 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                    @if ($reviewerRunMessage && ! $errors->has('reviewerRun'))
                        <p class="mt-2 text-xs text-(--ok)">{{ $reviewerRunMessage }}</p>
                    @endif

                    <div class="mt-3 flex justify-end">
                        <x-btn variant="ai" wire:click="runReviewerNow" wire:loading.attr="disabled" wire:target="runReviewerNow">
                            <span wire:loading wire:target="runReviewerNow">Génération…</span>
                            <span wire:loading.remove wire:target="runReviewerNow">Lancer maintenant</span>
                        </x-btn>
                    </div>
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
