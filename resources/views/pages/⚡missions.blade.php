<?php

use App\Enums\MissionStatus;
use App\Enums\WatchEnded;
use App\Models\Mission;
use App\Models\MissionEvent;
use App\Models\TeamAgent;
use App\Support\Mcp\McpSettings;
use App\Support\Missions\MissionLauncher;
use App\Support\Missions\Skill;
use App\Support\Missions\SkillCatalog;
use Carbon\CarbonInterface;
use League\CommonMark\CommonMarkConverter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * La messagerie de l'équipe : les conversations à gauche, celle qu'on lit à
 * droite. Les deux URL (`/missions` et `/missions/{mission}`) rendent ce même
 * composant — écrire, lire et retrouver sont un seul geste, et il n'y a qu'un
 * fil à maintenir.
 */
new #[Title('Missions')] class extends Component
{
    public ?Mission $mission = null;

    /** Le message en cours d'écriture, nouveau fil ou réponse. */
    public string $message = '';

    public string $skillSlug = '';

    public string $budget = '';

    /** Une veille : revérifier toutes les N minutes. Vide = une seule fois. */
    public string $watchEvery = '';

    public string $watchHours = '';

    public function mount(?Mission $mission = null): void
    {
        $this->mission = $mission;

        // L'écran Skills envoie ici avec un skill en poche (`?skill=…`). On le
        // lit une fois, à l'ouverture, plutôt que de l'accrocher à l'URL avec
        // `#[Url]` : il n'a plus aucun sens une fois un fil ouvert, et suivrait
        // la navigation en la salissant.
        $this->skillSlug = (string) request()->query('skill');

        $this->budget = number_format((float) config('missions.default_budget_usd'), 2, '.', '');
    }

    /**
     * Thomas ne choisit pas son interlocuteur : il écrit au chef, qui trouve la
     * bonne personne dans son équipe.
     */
    #[Computed]
    public function chief(): ?TeamAgent
    {
        return TeamAgent::chief();
    }

    /**
     * @return list<Skill>
     */
    #[Computed]
    public function skills(): array
    {
        return app(SkillCatalog::class)->all();
    }

    /**
     * Toutes les conversations, celles qui tournent en tête.
     *
     * @return Collection<int, Mission>
     */
    #[Computed]
    public function conversations(): Collection
    {
        return Mission::with('teamAgent')
            ->latest('id')
            ->limit(60)
            ->get()
            ->sortByDesc(fn (Mission $mission): array => [$mission->status->isOpen() ? 1 : 0, $mission->id])
            ->values();
    }

    #[Computed]
    public function runningCount(): int
    {
        return $this->conversations->filter(fn (Mission $mission): bool => $mission->status->isOpen())->count();
    }

    /**
     * Ce qui manquerait à un agent pour être utile. On le dit avant d'écrire,
     * pas après : un agent sans outils répond quand même, poliment, sans rien
     * toucher.
     *
     * @return list<string>
     */
    #[Computed]
    public function warnings(): array
    {
        $warnings = [];
        $mcp = app(McpSettings::class);

        if (! $mcp->isEnabled()) {
            $warnings[] = "Le serveur MCP est éteint : les agents n'auront pas les outils de Tars. Allume-le dans Réglages.";
        } elseif ($mcp->agentToken() === null) {
            $warnings[] = 'Aucun jeton MCP exploitable : régénère-le dans Réglages pour que les agents puissent appeler Tars.';
        }

        if (! File::exists((string) config('missions.claude_binary'))) {
            $warnings[] = 'Binaire Claude Code introuvable ('.config('missions.claude_binary').'). Renseigne CLAUDE_BINARY dans .env.';
        }

        return $warnings;
    }

    /**
     * Le fil, tour par tour.
     *
     * Un tour, c'est : ce que j'ai demandé, ce qu'il a fait pour y répondre, et
     * ce qu'il répond. Le « ce qu'il a fait » — appels d'outils, délégations,
     * réflexion — est vrai mais secondaire : il se replie dès que la réponse
     * est là, et reste ouvert tant qu'elle ne l'est pas. On lit une
     * conversation, pas un journal de bord ; le journal est à un clic.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function turns(): array
    {
        if ($this->mission === null) {
            return [];
        }

        $events = $this->mission->events()->orderBy('seq')->get();

        // Chaque tour a son horloge. Les mesurer depuis le début de la mission
        // donnerait, au deuxième message, un premier tour entièrement à zéro :
        // `started_at` est remis à chaque relance.
        $this->turnStartedAt = $events
            ->groupBy('turn')
            ->map(fn (Collection $ofTurn): CarbonInterface => $ofTurn->first()->occurred_at)
            ->all();

        $rows = $this->branch(
            events: $events,
            results: $events->where('subtype', 'tool_result')->keyBy('tool_use_id'),
            parent: null,
        );

        $turns = [];

        foreach ($rows as $row) {
            $number = $row['turn'];

            $turns[$number] ??= [
                'turn' => $number,
                'prompt' => null,
                'work' => [],
                'alerts' => [],
                'answer' => null,
                'seconds' => 0,
                'running' => false,
            ];

            if ($row['event']->isMine()) {
                $turns[$number]['prompt'] = $row['event'];

                continue;
            }

            // Une alerte ne se replie pas avec le reste : c'est précisément ce
            // pour quoi on a lancé la veille.
            if ($row['event']->type === 'alert') {
                $turns[$number]['alerts'][] = $row['event'];

                continue;
            }

            $turns[$number]['work'][] = $row;
            $turns[$number]['seconds'] = max($turns[$number]['seconds'], $row['elapsed'] ?? 0);
        }

        foreach ($turns as $number => $turn) {
            $running = $number >= $this->mission->turn && $this->mission->status->isOpen();

            // Tant qu'il travaille, son dernier mot n'est pas encore une
            // réponse : il reste une étape parmi les autres.
            if (! $running) {
                for ($index = count($turn['work']) - 1; $index >= 0; $index--) {
                    if ($turn['work'][$index]['event']->subtype === 'text') {
                        $turn['answer'] = $turn['work'][$index];
                        array_splice($turn['work'], $index, 1);

                        break;
                    }
                }
            }

            $turn['running'] = $running;
            $turns[$number] = $turn;
        }

        return array_values($turns);
    }

    /** @var array<int, CarbonInterface> */
    private array $turnStartedAt = [];

    /**
     * @param  Collection<int, MissionEvent>  $events
     * @param  Collection<string, MissionEvent>  $results
     * @return list<array<string, mixed>>
     */
    private function branch(Collection $events, Collection $results, ?string $parent): array
    {
        $rows = [];

        foreach ($events as $event) {
            if ($event->parent_tool_use_id !== $parent || ! $this->isWorthShowing($event)) {
                continue;
            }

            $children = $event->subtype === 'tool_use' && $event->tool_use_id !== null
                ? $this->branch($events, $results, $event->tool_use_id)
                : [];

            $rows[] = [
                'event' => $event,
                'result' => $event->tool_use_id !== null ? $results->get($event->tool_use_id) : null,
                'children' => $children,
                'speaker' => $event->isDelegation() ? $this->nameOf($event->delegateSlug()) : null,
                'steps' => count($children),
                'elapsed' => $this->elapsed($event),
                'turn' => $event->turn,
                'conclusion' => false,
            ];
        }

        return $rows;
    }

    /**
     * Le bruit du transport : les bornes de débit, les étapes internes d'une
     * tâche, la comptabilité des tokens. Rien de tout ça ne dit à Thomas ce que
     * son agent est en train de faire.
     */
    private function isWorthShowing(MissionEvent $event): bool
    {
        if ($event->subtype === 'tool_result' || in_array($event->type, ['result', 'rate_limit_event'], true)) {
            return false;
        }

        return ! ($event->type === 'system' && $event->subtype !== 'init');
    }

    /**
     * Le dernier mot de chaque tour achevé est une réponse, pas une étape de
     * plus : c'est ce que Thomas est venu lire. L'agent le dit au même endroit
     * et de la même façon que le reste — c'est à l'écran de faire la
     * différence.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function markConclusions(array $rows): array
    {
        $lastTextOfTurn = [];

        foreach ($rows as $index => $row) {
            if ($row['event']->subtype === 'text') {
                $lastTextOfTurn[$row['turn']] = $index;
            }
        }

        foreach ($lastTextOfTurn as $turn => $index) {
            if ($turn < $this->mission?->turn || ! $this->mission?->status->isOpen()) {
                $rows[$index]['conclusion'] = true;
            }
        }

        return $rows;
    }

    private function elapsed(MissionEvent $event): ?int
    {
        $start = $this->turnStartedAt[$event->turn] ?? null;

        return $start === null ? null : max(0, (int) $start->diffInSeconds($event->occurred_at));
    }

    /**
     * Le nom d'un coéquipier, tel que l'équipe le nomme. Un agent supprimé
     * depuis garde au moins son slug : on ne perd pas la trace de qui a
     * travaillé.
     */
    private function nameOf(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }

        return $this->teamNames()[$slug] ?? $slug;
    }

    /**
     * @return array<string, string>
     */
    #[Computed(persist: true)]
    public function teamNames(): array
    {
        /** @var array<string, string> */
        return TeamAgent::pluck('name', 'slug')->all();
    }

    /**
     * Un agent répond en markdown — titres, listes, gras. Affiché brut, on lit
     * des `**` au milieu des phrases.
     *
     * Surtout pas `render()` : c'est le nom que Livewire se réserve pour
     * produire la vue du composant, et le redéfinir casse tout l'écran.
     */
    public function asHtml(?string $markdown): string
    {
        return $markdown === null || trim($markdown) === ''
            ? ''
            : (new CommonMarkConverter)->convertToHtml($markdown)->getContent();
    }

    #[Computed]
    public function subagentCount(): int
    {
        if ($this->mission === null) {
            return 0;
        }

        return MissionEvent::where('mission_id', $this->mission->id)
            ->whereIn('tool_name', MissionEvent::DELEGATION_TOOLS)
            ->count();
    }

    /**
     * Envoyer : le message suivant d'un fil ouvert, ou le premier d'un nouveau.
     */
    public function send(): void
    {
        $this->mission === null ? $this->open() : $this->reply();
    }

    private function open(): void
    {
        $validated = $this->validate([
            'skillSlug' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:3', 'max:10000'],
            'budget' => ['required', 'numeric', 'min:0.1', 'max:'.config('missions.max_budget_usd')],
            'watchEvery' => ['nullable', 'numeric', 'min:1', 'max:10080'],
            'watchHours' => ['nullable', 'numeric', 'min:1', 'max:720'],
        ]);

        $chief = $this->chief;

        if ($chief === null) {
            $this->addError('message', "Aucun agent disponible : l'équipe est vide ou tout le monde est au repos.");

            return;
        }

        $mission = app(MissionLauncher::class)->open(
            agent: $chief,
            message: $validated['message'],
            skillSlug: $validated['skillSlug'],
            budgetUsd: (float) $validated['budget'],
        );

        if ($validated['watchEvery'] !== '' && $validated['watchEvery'] !== null) {
            app(MissionLauncher::class)->watch(
                mission: $mission,
                everyMinutes: (int) $validated['watchEvery'],
                forHours: $validated['watchHours'] !== '' ? (int) $validated['watchHours'] : null,
            );
        }

        $this->redirectRoute('missions.show', $mission, navigate: true);
    }

    private function reply(): void
    {
        $validated = $this->validate(['message' => ['required', 'string', 'min:1', 'max:10000']]);

        if ($this->mission !== null && app(MissionLauncher::class)->reply($this->mission, $validated['message'])) {
            $this->message = '';
            $this->mission->refresh();

            unset($this->turns, $this->conversations);
        }
    }

    /**
     * Arrêter la veille sans arrêter la conversation : le fil reste, on peut
     * encore lui parler, il ne se réveillera simplement plus tout seul.
     */
    public function stopWatch(): void
    {
        if ($this->mission?->isWatching()) {
            $this->mission->endWatch(WatchEnded::Stopped);
            $this->mission->refresh();

            unset($this->conversations);
        }
    }

    /**
     * Arrêter ne tue pas le process depuis la requête web : on lève le drapeau,
     * et le worker — qui seul tient le process — envoie le signal.
     */
    public function stop(): void
    {
        if ($this->mission === null || ! $this->mission->status->isOpen()) {
            return;
        }

        $this->mission->forceFill(['cancel_requested' => true])->save();

        // Une conversation encore en file n'a pas de process à tuer : elle est
        // arrêtée séance tenante, et le job la laissera passer.
        if ($this->mission->status === MissionStatus::Queued) {
            $this->mission->forceFill([
                'status' => MissionStatus::Cancelled,
                'finished_at' => now(),
            ])->save();
        }

        unset($this->turns, $this->conversations);
    }
};
?>

<div @if ($this->runningCount > 0) wire:poll.2s @else wire:poll.15s @endif>
    <x-space-tabs space="hermes" class="mb-3.5" />

    <x-screen-header
        :kicker="$this->runningCount > 0 ? $this->runningCount.' en cours' : 'rien en cours'"
        title="Missions"
        class="mb-4"
    />

    <div class="grid items-start gap-5 lg:grid-cols-[280px_minmax(0,1fr)]">
        {{-- Le volet des conversations. Sur mobile il s'efface quand un fil est
             ouvert : deux colonnes sous le pouce ne tiennent pas. --}}
        <nav aria-label="Conversations" @class(['lg:block', 'hidden' => $mission !== null])>
            <a
                href="{{ route('missions.index') }}"
                wire:navigate
                @class([
                    'press mb-3 flex items-center gap-2 rounded-full px-4 py-2.5 text-[13px] font-semibold',
                    'bg-(--ac) text-(--acT)' => $mission === null,
                    'bg-(--surf2) text-(--tx) hover:bg-(--bd2)' => $mission !== null,
                ])
            >
                <span aria-hidden="true" class="text-[15px] leading-none">+</span>
                Nouvelle conversation
            </a>

            <div class="flex flex-col gap-1">
                @foreach ($this->conversations as $conversation)
                    <a
                        href="{{ route('missions.show', $conversation) }}"
                        wire:navigate
                        wire:key="conversation-{{ $conversation->id }}"
                        @if ($mission?->is($conversation)) aria-current="page" @endif
                        @class([
                            'press block rounded-[14px] px-3.5 py-2.5',
                            'bg-(--surf2)' => $mission?->is($conversation),
                            'hover:bg-(--surf)' => ! $mission?->is($conversation),
                        ])
                    >
                        <div class="mb-0.5 flex items-baseline gap-2">
                            @if ($conversation->status->isOpen())
                                <span class="inline-block size-[7px] shrink-0 animate-pulse rounded-full bg-(--ai)"></span>
                            @endif
                            <span @class([
                                'truncate text-[13px] font-bold',
                                'text-(--ai)' => $conversation->status->isOpen(),
                                'text-(--tx)' => ! $conversation->status->isOpen(),
                            ])>{{ $conversation->teamAgent->name }}</span>
                            @if ($conversation->isWatching())
                                <span class="shrink-0 rounded-full bg-(--aibg) px-2 py-[1px] text-[10px] font-semibold text-(--ai)">veille</span>
                            @endif
                            @if ($conversation->isFromHermes())
                                {{-- Lancée par Hermès, pas par moi : une
                                     conversation que je n'ai pas ouverte ne doit
                                     pas se présenter comme si je l'avais fait. --}}
                                <span class="shrink-0 rounded-full bg-(--aibg) px-2 py-[1px] text-[10px] font-semibold text-(--ai)">Hermès</span>
                            @endif
                            <span class="ml-auto shrink-0 text-[11px] text-(--mut)">{{ $conversation->created_at?->diffForHumans(short: true) }}</span>
                        </div>
                        <p class="line-clamp-2 text-[12px] leading-[1.5] text-(--mut)">{{ $conversation->result ?: $conversation->prompt }}</p>
                    </a>
                @endforeach
            </div>
        </nav>

        <div class="min-w-0">
            @foreach ($this->warnings as $warning)
                <p class="mb-2.5 rounded-[14px] bg-(--surf) px-4 py-3 text-[13px] text-(--warn)">{{ $warning }}</p>
            @endforeach

            @if ($mission === null)
                @include('pages.missions.composer')
            @else
                @include('pages.missions.thread')
            @endif
        </div>
    </div>
</div>
