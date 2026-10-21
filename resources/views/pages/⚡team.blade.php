<?php

use App\Enums\MissionStatus;
use App\Models\Mission;
use App\Models\MissionEvent;
use App\Models\TeamAgent;
use App\Enums\AgentWorkspaceKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Équipe')] class extends Component
{
    /** L'agent en cours d'édition, ou `0` pour celui qu'on crée. */
    public ?int $editing = null;

    public string $name = '';

    public string $whenToUse = '';

    public string $role = '';

    public string $model = 'sonnet';

    public string $workspaceKind = 'vault';

    public bool $enabled = true;

    /**
     * @return Collection<int, TeamAgent>
     */
    #[Computed]
    public function agents(): Collection
    {
        return TeamAgent::orderByDesc('can_delegate')->orderBy('position')->orderBy('name')->get();
    }

    public function edit(int $id): void
    {
        $agent = TeamAgent::findOrFail($id);

        $this->editing = $agent->id;
        $this->name = $agent->name;
        $this->whenToUse = (string) $agent->when_to_use;
        $this->role = $agent->role;
        $this->model = $agent->model;
        $this->workspaceKind = $agent->workspace_kind->value;
        $this->enabled = $agent->enabled;
    }

    public function recruit(): void
    {
        $this->editing = 0;
        $this->name = '';
        $this->whenToUse = '';
        $this->role = '';
        $this->model = 'sonnet';
        $this->workspaceKind = 'vault';
        $this->enabled = true;
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            // L'aiguillage du chef ne tient qu'à ce texte : un agent sans
            // consigne d'appel ne sera jamais appelé à bon escient.
            'whenToUse' => ['required', 'string', 'min:10', 'max:1000'],
            'role' => ['required', 'string', 'min:10', 'max:10000'],
            'model' => ['required', 'string', 'max:80'],
            'workspaceKind' => ['required', Rule::enum(AgentWorkspaceKind::class)],
            'enabled' => ['boolean'],
        ]);

        $agent = $this->editing > 0 ? TeamAgent::findOrFail($this->editing) : new TeamAgent;

        $agent->fill([
            'name' => $validated['name'],
            'when_to_use' => $validated['whenToUse'],
            'role' => $validated['role'],
            'model' => $validated['model'],
            'workspace_kind' => $validated['workspaceKind'],
            'enabled' => $validated['enabled'],
        ]);

        if (! $agent->exists) {
            $agent->slug = $this->uniqueSlug($validated['name']);
            $agent->position = (int) TeamAgent::max('position') + 1;
        }

        $agent->save();

        $this->editing = null;

        unset($this->agents);
    }

    /**
     * Le chef ne se met pas au repos : sans lui, Thomas n'a plus
     * d'interlocuteur.
     */
    public function toggle(int $id): void
    {
        $agent = TeamAgent::findOrFail($id);

        if ($agent->can_delegate && $agent->enabled) {
            $this->addError('team', "Le chef est ton interlocuteur : il ne se met pas au repos.");

            return;
        }

        $agent->forceFill(['enabled' => ! $agent->enabled])->save();

        unset($this->agents);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'agent';
        $slug = $base;
        $suffix = 2;

        while (TeamAgent::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * Ce que chaque agent fait en ce moment, indexé par agent.
     *
     * @return Collection<int, Mission>
     */
    #[Computed]
    public function working(): Collection
    {
        return Mission::whereIn('status', [MissionStatus::Queued, MissionStatus::Running])
            ->latest('id')
            ->get()
            ->keyBy('team_agent_id');
    }

    /**
     * @return Collection<int, Mission>
     */
    #[Computed]
    public function latest(): Collection
    {
        return Mission::whereNotIn('status', [MissionStatus::Queued, MissionStatus::Running])
            ->latest('id')
            ->get()
            ->unique('team_agent_id')
            ->keyBy('team_agent_id');
    }

    /**
     * Les veilles armées, par agent.
     *
     * Une conversation qui dort entre deux passages n'est pas « au repos » :
     * elle va se réveiller et dépenser. Le plateau doit donc dire laquelle
     * guette et à quelle heure elle repasse — sinon une veille oubliée tourne
     * sans que rien à l'écran ne la nomme.
     *
     * @return Collection<int, Mission>
     */
    #[Computed]
    public function watching(): Collection
    {
        return Mission::whereNotNull('watch_every_minutes')
            ->latest('id')
            ->get()
            ->unique('team_agent_id')
            ->keyBy('team_agent_id');
    }

    /**
     * Les coéquipiers au travail sous un agent, par mission ouverte.
     *
     * Un sous-agent n'a pas de ligne dans `missions` : il vit à l'intérieur du
     * process de celui qui l'a appelé. Sans cette lecture du flux, le plateau
     * montre un chef « au travail » et tait les trois agents qui travaillent
     * réellement sous lui. Une délégation est en cours tant qu'aucun
     * `tool_result` ne porte son `tool_use_id`.
     *
     * @return array<int, list<array{name: string, step: string|null, seconds: int}>>
     */
    #[Computed]
    public function delegations(): array
    {
        $open = [];

        foreach ($this->working as $mission) {
            // Le tour courant seulement : une délégation restée sans retour sur
            // un tour précédent — mission arrêtée en plein vol — n'est pas en
            // train de travailler.
            $events = $mission->events()->where('turn', $mission->turn)->orderBy('seq')->get();
            $closed = $events->where('subtype', 'tool_result')->pluck('tool_use_id')->all();

            $crew = [];

            foreach ($events as $event) {
                if (! $event->isDelegation() || $event->parent_tool_use_id !== null || $event->tool_use_id === null) {
                    continue;
                }

                if (in_array($event->tool_use_id, $closed, true)) {
                    continue;
                }

                $last = $events
                    ->where('parent_tool_use_id', $event->tool_use_id)
                    ->whereIn('subtype', ['text', 'tool_use'])
                    ->last();

                $crew[] = [
                    'name' => $this->nameOf($event->delegateSlug()),
                    'step' => $last === null ? null : $this->describe($last),
                    'seconds' => (int) $event->occurred_at->diffInSeconds(now()),
                ];
            }

            $open[$mission->id] = $crew;
        }

        return $open;
    }

    /**
     * L'étape en cours d'une mission : la dernière ligne de son flux qui dise
     * quelque chose. C'est ce qu'on regarde quand on veut savoir où il en est
     * sans ouvrir la conversation.
     *
     * Les lignes d'un sous-agent sont écartées (`parent_tool_use_id`) : elles
     * sont rendues sous leur propre nom, et faire passer le travail d'un
     * coéquipier pour celui du chef trompe sur qui fait quoi.
     */
    public function currentStep(Mission $mission): ?string
    {
        $event = MissionEvent::where('mission_id', $mission->id)
            ->whereNull('parent_tool_use_id')
            ->whereIn('subtype', ['text', 'tool_use'])
            ->orderByDesc('seq')
            ->first();

        return $event === null ? null : $this->describe($event);
    }

    /**
     * Une ligne du flux, dite en une phrase courte.
     */
    private function describe(MissionEvent $event): string
    {
        if ($event->isDelegation()) {
            return 'missionne un coéquipier : '.$event->text;
        }

        if ($event->subtype === 'tool_use') {
            return $event->text === null
                ? (string) $event->tool_name
                : $event->tool_name.' · '.$event->text;
        }

        return (string) $event->text;
    }

    /**
     * Le nom d'un coéquipier, tel que l'équipe le nomme. Un slug inconnu — un
     * agent renvoyé depuis — reste affiché tel quel : on ne perd pas la trace
     * de qui a travaillé.
     */
    private function nameOf(?string $slug): string
    {
        if ($slug === null) {
            return 'Un coéquipier';
        }

        return $this->agents->firstWhere('slug', $slug)?->name ?? $slug;
    }

    /**
     * Une durée dite comme on la lit.
     */
    public function clock(?int $seconds): string
    {
        if ($seconds === null) {
            return '';
        }

        if ($seconds < 60) {
            return $seconds.' s';
        }

        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $rest === 0 ? $minutes.' min' : $minutes.' min '.$rest.' s';
    }

    /**
     * Ce que l'équipe a dépensé aujourd'hui.
     *
     * Compté sur les événements `result`, qui portent le prix d'un passage à
     * l'heure où il a eu lieu : sommer `missions.cost_usd` mettrait au compte
     * d'aujourd'hui une veille ouverte la semaine dernière, et tairait ce
     * qu'elle vient de dépenser cette nuit. Un passage encore en cours n'a pas
     * encore de prix — il n'est donc pas compté, mais sa ligne est à l'écran,
     * en train de tourner.
     */
    #[Computed]
    public function spendToday(): float
    {
        return (float) MissionEvent::where('type', 'result')
            ->where('occurred_at', '>=', today())
            ->get()
            ->sum(fn (MissionEvent $event): float => (float) ($event->payload['total_cost_usd'] ?? 0));
    }

    /**
     * La dépense des sept derniers jours, par agent — la fenêtre où l'on peut
     * encore changer quelque chose.
     *
     * @return array<int, float>
     */
    #[Computed]
    public function spendThisWeek(): array
    {
        /** @var array<int, float> */
        return Mission::where('created_at', '>=', now()->subWeek())
            ->selectRaw('team_agent_id, COALESCE(SUM(cost_usd), 0) as total')
            ->groupBy('team_agent_id')
            ->pluck('total', 'team_agent_id')
            ->map(fn ($total): float => (float) $total)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    #[Computed]
    public function missionCount(): array
    {
        /** @var array<int, int> */
        return Mission::selectRaw('team_agent_id, COUNT(*) as total')
            ->groupBy('team_agent_id')
            ->pluck('total', 'team_agent_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }
};
?>

<div wire:poll.3s>
    @php
        // Le sur-titre dit l'état de l'équipe, pas le nom de l'écran : combien
        // travaillent, combien de coéquipiers tournent sous eux, et ce que la
        // journée a coûté jusqu'ici.
        $busy = $this->working->count();
        $crewAtWork = array_sum(array_map('count', $this->delegations));

        $kicker = $this->agents->count().' agents · '.($busy > 0 ? $busy.' au travail' : 'aucun au travail');

        if ($crewAtWork > 0) {
            $kicker .= ' + '.$crewAtWork.' '.Str::plural('coéquipier', $crewAtWork);
        }

        $kicker .= ' · '.number_format($this->spendToday, 2).' $ aujourd\'hui';
    @endphp

    <x-screen-header :kicker="$kicker" title="Équipe" class="mb-4">
        <x-slot:actions>
            <x-btn wire:click="recruit" variant="secondary" size="sm">Recruter</x-btn>
        </x-slot:actions>
    </x-screen-header>

    <x-space-tabs space="hermes" class="mb-5" />

    @error('team')<p class="mb-3 rounded-[14px] bg-(--surf) px-4 py-3 text-[13px] text-(--warn)">{{ $message }}</p>@enderror

    @if ($editing !== null)
        {{-- Le banc est éditable parce qu'un banc figé n'est pas le sien. Les
             deux textes ne s'adressent pas au même lecteur : l'un dit au chef
             quand appeler, l'autre dit à l'agent comment travailler. --}}
        <x-card padding="22" class="mb-5">
            <h2 class="mb-4 font-display text-[19px] font-semibold text-(--tx)">
                {{ $editing > 0 ? 'Modifier' : 'Recruter' }}
            </h2>

            <form wire:submit="save" class="flex flex-col gap-3.5">
                <div class="flex flex-wrap gap-3.5">
                    <label class="min-w-[200px] flex-1">
                        <span class="mb-1.5 block text-[13px] font-semibold text-(--mut)">Son nom</span>
                        <x-input wire:model="name" class="w-full" placeholder="Le chercheur" />
                        @error('name')<span class="mt-1 block text-[12px] text-(--warn)">{{ $message }}</span>@enderror
                    </label>

                    <label class="w-[150px]">
                        <span class="mb-1.5 block text-[13px] font-semibold text-(--mut)">Modèle</span>
                        <x-select wire:model="model" class="w-full">
                            <option value="sonnet">sonnet</option>
                            <option value="opus">opus</option>
                            <option value="haiku">haiku</option>
                        </x-select>
                    </label>

                    <label class="w-[180px]">
                        <span class="mb-1.5 block text-[13px] font-semibold text-(--mut)">Son terrain</span>
                        <x-select wire:model="workspaceKind" class="w-full">
                            <option value="vault">le cerveau</option>
                            <option value="repo">un dépôt de code</option>
                        </x-select>
                    </label>
                </div>

                <label>
                    <span class="mb-1.5 block text-[13px] font-semibold text-(--mut)">Quand le chef doit l'appeler</span>
                    <textarea
                        wire:model="whenToUse"
                        rows="2"
                        placeholder="Quand la réponse est dehors : chercher sur le web, vérifier un fait, comparer des offres…"
                        class="w-full rounded-[18px] bg-(--surf2) px-4 py-3 text-[14px] leading-[1.6] text-(--tx) placeholder:text-(--mut) focus:outline-none"
                    ></textarea>
                    <span class="mt-1 block text-[12px] text-(--mut)">C'est le seul texte qui décide d'un aiguillage.</span>
                    @error('whenToUse')<span class="mt-1 block text-[12px] text-(--warn)">{{ $message }}</span>@enderror
                </label>

                <label>
                    <span class="mb-1.5 block text-[13px] font-semibold text-(--mut)">Comment il travaille</span>
                    <textarea
                        wire:model="role"
                        rows="6"
                        placeholder="Son prompt : ce qu'il est, ses outils, ce qu'il rend."
                        class="w-full rounded-[18px] bg-(--surf2) px-4 py-3 text-[14px] leading-[1.6] text-(--tx) placeholder:text-(--mut) focus:outline-none"
                    ></textarea>
                    @error('role')<span class="mt-1 block text-[12px] text-(--warn)">{{ $message }}</span>@enderror
                </label>

                <div class="flex items-center gap-3">
                    <x-btn type="submit" variant="agent" size="md">Enregistrer</x-btn>
                    <x-btn wire:click="cancel" type="button" variant="ghost" size="md">Annuler</x-btn>
                </div>
            </form>
        </x-card>
    @endif

    {{-- Le plateau : une ligne par agent, dans l'ordre du banc.

         L'ordre ne bouge jamais, même quand quelqu'un se met au travail :
         l'écran se rafraîchit toutes les trois secondes, et des lignes qui
         changent de place pendant qu'on les lit rendent le plateau illisible.
         C'est la pastille qui dit qui travaille, pas la position.

         Un coéquipier missionné apparaît indenté sous celui qui l'a appelé,
         avec sa propre étape — c'est la seule façon de voir les trois agents
         qui tournent derrière un chef « au travail ». --}}
    <div class="flex flex-col gap-1.5">
        @foreach ($this->agents as $agent)
            @php
                $mission = $this->working[$agent->id] ?? null;
                $watch = $this->watching[$agent->id] ?? null;
                $last = $this->latest[$agent->id] ?? null;
                $step = $mission ? $this->currentStep($mission) : null;
                $crew = $mission ? ($this->delegations[$mission->id] ?? []) : [];
                $reread = $mission ?? $watch ?? $last;
            @endphp

            <div
                wire:key="agent-{{ $agent->id }}"
                @class([
                    'rounded-[16px] px-[18px] py-[13px]',
                    // Le vert ne se pose que sur un agent qui travaille vraiment :
                    // c'est ce qui fait lire le plateau d'un coup d'œil.
                    'bg-(--aibg)' => $mission !== null,
                    'bg-(--surf)' => $mission === null,
                ])
            >
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    {{-- Pleine et battante au travail, creuse en veille (armée,
                         mais pas en train de tourner), éteinte sinon. --}}
                    @if ($mission)
                        <span class="inline-block size-[8px] shrink-0 animate-pulse rounded-full bg-(--ai)" aria-hidden="true"></span>
                    @elseif ($watch)
                        <span class="inline-block size-[8px] shrink-0 rounded-full border-2 border-(--ai)" aria-hidden="true"></span>
                    @else
                        <span class="inline-block size-[8px] shrink-0 rounded-full bg-(--bd2)" aria-hidden="true"></span>
                    @endif

                    <span class="shrink-0 font-display text-[16px] font-bold text-(--tx)">{{ $agent->name }}</span>

                    @if ($agent->can_delegate)
                        <x-badge type="pill" tone="agent" class="shrink-0 !text-[11px]">délègue</x-badge>
                    @endif

                    @unless ($agent->enabled)
                        <x-badge type="pill" tone="sunk" class="shrink-0 !text-[11px]">au repos</x-badge>
                    @endunless

                    @if ($mission?->isFromHermes())
                        <x-badge type="pill" tone="agent" class="shrink-0 !text-[11px]">Hermès</x-badge>
                    @endif

                    @if ($mission?->isWatching())
                        {{-- Il tourne parce qu'une veille l'a réveillé, pas
                             parce que Thomas vient de lui écrire. --}}
                        <x-badge type="pill" tone="agent" class="shrink-0 !text-[11px]">veille</x-badge>
                    @endif

                    {{-- Ce qu'il fait, maintenant : la seule chose pour laquelle
                         on vient sur cet écran. --}}
                    @if ($mission)
                        <a
                            href="{{ route('missions.show', $mission) }}"
                            wire:navigate
                            class="press min-w-0 flex-1 basis-[200px] truncate text-[13px] text-(--tx)"
                        >
                            <span class="font-semibold text-(--ai)">{{ $mission->status === MissionStatus::Queued ? 'en file' : '▸' }}</span>
                            {{ $step ?? $mission->currentPrompt() }}
                        </a>
                        <span class="shrink-0 text-[12px] text-(--mut)">{{ $this->clock($mission->durationInSeconds()) }}</span>
                    @elseif ($watch)
                        {{-- Elle guette, elle n'est pas finie. Dire quand elle
                             repasse, et ce qu'elle a déjà coûté. --}}
                        <span class="min-w-0 flex-1 basis-[200px] truncate text-[13px] text-(--mut)">
                            <span class="font-semibold text-(--ai)">en veille</span>
                            toutes les {{ $watch->watch_every_minutes }} min · {{ $watch->watch_runs }} {{ Str::plural('passage', $watch->watch_runs) }}
                            @if ($watch->watch_next_at)
                                · prochain à {{ $watch->watch_next_at->translatedFormat('H\hi') }}
                            @endif
                        </span>
                    @elseif ($last)
                        <a
                            href="{{ route('missions.show', $last) }}"
                            wire:navigate
                            class="press min-w-0 flex-1 basis-[200px] truncate text-[13px] text-(--mut)"
                        >
                            {{ $last->status->label() }} {{ $last->finished_at?->diffForHumans() ?? $last->created_at?->diffForHumans() }} · {{ $last->result ?: $last->prompt }}
                        </a>
                    @else
                        <span class="min-w-0 flex-1 basis-[200px] truncate text-[13px] text-(--mut)">Il n'a encore rien fait.</span>
                    @endif

                    <span class="ml-auto flex shrink-0 items-center gap-1">
                        @if ($mission)
                            <x-btn :href="route('missions.show', $mission)" wire:navigate variant="ghost" size="xs">Voir</x-btn>
                        @elseif ($reread)
                            <x-btn :href="route('missions.show', $reread)" wire:navigate variant="ghost" size="xs">Relire</x-btn>
                        @endif

                        <x-btn wire:click="edit({{ $agent->id }})" variant="ghost" size="xs">Modifier</x-btn>

                        @unless ($agent->can_delegate)
                            <x-btn wire:click="toggle({{ $agent->id }})" variant="ghost" size="xs">
                                {{ $agent->enabled ? 'Mettre au repos' : 'Remettre' }}
                            </x-btn>
                        @endunless
                    </span>
                </div>

                @foreach ($crew as $index => $mate)
                    {{-- Un coéquipier au travail sous lui. Le filet vert dit
                         l'appartenance sans qu'on ait à dessiner un arbre. --}}
                    <div wire:key="crew-{{ $mission->id }}-{{ $index }}" class="mt-2.5 flex items-center gap-3 pl-[11px]">
                        <span class="h-[16px] w-[2px] shrink-0 rounded-full bg-(--ai)/50"></span>
                        <span class="shrink-0 text-[13px] font-bold text-(--ai)">{{ $mate['name'] }}</span>
                        <span class="min-w-0 flex-1 truncate text-[13px] text-(--mut)">{{ $mate['step'] ?? 'il démarre…' }}</span>
                        <span class="shrink-0 text-[12px] text-(--mut)">{{ $this->clock($mate['seconds']) }}</span>
                    </div>
                @endforeach

                {{-- Ce qui ne change pas d'une seconde à l'autre : à qui le chef
                     le confie, avec quoi il travaille, ce qu'il a coûté. --}}
                <div class="mt-2.5 flex flex-wrap items-baseline gap-x-4 gap-y-1 text-[12px] text-(--mut)">
                    @if ($agent->when_to_use)
                        <span class="min-w-0 flex-1 basis-[200px] truncate italic">{{ $agent->when_to_use }}</span>
                    @endif

                    <span class="ml-auto shrink-0">{{ $agent->model }} · {{ $agent->workspace_kind->label() }}</span>
                    <span class="shrink-0">{{ $this->missionCount[$agent->id] ?? 0 }} {{ Str::plural('conversation', $this->missionCount[$agent->id] ?? 0) }}</span>
                    <span class="shrink-0">{{ number_format($this->spendThisWeek[$agent->id] ?? 0, 2) }} $ sur 7 jours</span>
                </div>
            </div>
        @endforeach
    </div>
</div>

