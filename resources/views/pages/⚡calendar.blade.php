<?php

use App\Models\Entity;
use App\Models\Event;
use App\Models\GoogleCalendarConnection;
use App\Models\Goal;
use App\Support\Palette;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Calendrier')] class extends Component
{
    private const int MAX_LANES = 3;

    #[Url]
    public ?string $month = null;

    public bool $showEditEventModal = false;

    public ?int $editingEventId = null;

    public string $eventTitle = '';

    public string $eventNotes = '';

    public string $eventStartsAt = '';

    public string $eventEndsAt = '';

    public ?int $eventGoalId = null;

    public ?int $eventEntityId = null;

    public function mount(): void
    {
        $this->month ??= today()->format('Y-m');
    }

    private function monthStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();
    }

    private function gridStart(): Carbon
    {
        return $this->monthStart()->startOfWeek(Carbon::MONDAY);
    }

    private function gridEnd(): Carbon
    {
        return $this->monthStart()->copy()->endOfMonth()->endOfWeek(Carbon::MONDAY);
    }

    #[Computed]
    public function monthLabel(): string
    {
        return ucfirst($this->monthStart()->translatedFormat('F Y'));
    }

    /**
     * La teinte de saison d'un événement. Un événement rattaché à un objectif
     * porte *la* teinte de cet objectif — la même qu'aux Tâches, aux Objectifs
     * et dans la bande d'état. Les événements sans objectif sont semés par
     * leur propre id, pour rester distincts les uns des autres au lieu de
     * s'agglomérer dans une teinte « par défaut ».
     */
    private function eventColorSlot(Event $event): string
    {
        return $event->goal?->color_slot ?? Palette::slot($event->id);
    }

    /**
     * La légende du mois : une entrée par objectif réellement présent à
     * l'écran, pour qu'une couleur ne soit jamais lue seule.
     *
     * @return \Illuminate\Support\Collection<int, Goal>
     */
    #[Computed]
    public function legendGoals(): \Illuminate\Support\Collection
    {
        return $this->visibleEvents()
            ->pluck('goal')
            ->filter()
            ->unique('id')
            ->sortBy('title')
            ->values();
    }

    #[Computed]
    public function syncLabel(): string
    {
        $connection = GoogleCalendarConnection::first();

        if ($connection === null) {
            return 'Google Calendar non connecté';
        }

        if ($connection->needs_reconnect) {
            return 'Google Calendar à reconnecter';
        }

        return $connection->last_synced_at === null
            ? 'Google Calendar connecté, pas encore synchronisé'
            : 'Google Calendar synchronisé '.$connection->last_synced_at->diffForHumans();
    }

    private function eventDateRange(Event $event): string
    {
        if ($event->ends_at !== null && $event->ends_at->toDateString() !== $event->starts_at->toDateString()) {
            return $event->starts_at->translatedFormat('d M').' → '.$event->ends_at->translatedFormat('d M');
        }

        $range = $event->starts_at->translatedFormat('d M à H:i');

        return $event->ends_at !== null
            ? $range.'–'.$event->ends_at->translatedFormat('H:i')
            : $range;
    }

    #[Computed]
    public function editGoalOptions(): Collection
    {
        return Goal::where('status', 'active')->orderBy('title')->get();
    }

    #[Computed]
    public function editEntityOptions(): Collection
    {
        return Entity::orderBy('name')->get();
    }

    public function openEditEventModal(int $eventId): void
    {
        $event = Event::findOrFail($eventId);

        $this->editingEventId = $event->id;
        $this->eventTitle = $event->title;
        $this->eventNotes = (string) $event->notes;
        $this->eventStartsAt = $event->starts_at->format('Y-m-d\TH:i');
        $this->eventEndsAt = $event->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->eventGoalId = $event->goal_id;
        $this->eventEntityId = $event->entity_id;
        $this->showEditEventModal = true;
    }

    public function saveEvent(): void
    {
        $validated = $this->validate([
            'eventTitle' => ['required', 'string', 'max:255'],
            'eventNotes' => ['nullable', 'string'],
            'eventStartsAt' => ['required', 'date'],
            'eventEndsAt' => ['nullable', 'date', 'after_or_equal:eventStartsAt'],
            'eventGoalId' => ['nullable', 'exists:goals,id'],
            'eventEntityId' => ['nullable', 'exists:entities,id'],
        ]);

        Event::findOrFail($this->editingEventId)->update([
            'title' => $validated['eventTitle'],
            'notes' => $validated['eventNotes'] ?: null,
            'starts_at' => $validated['eventStartsAt'],
            'ends_at' => $validated['eventEndsAt'] ?: null,
            'goal_id' => $validated['eventGoalId'],
            'entity_id' => $validated['eventEntityId'],
        ]);

        $this->showEditEventModal = false;

        unset($this->weeks);
    }

    /**
     * Each week is rendered as a small CSS grid of its own: a row of day
     * numbers, then up to MAX_LANES rows of event "bars" that can span
     * multiple day-columns (for multi-day events), then an overflow row for
     * whatever didn't fit. Everything beyond a bar's own start column is
     * marked 'covered' so the template skips it — the bar's own colspan
     * consumes those grid columns instead.
     *
     * @return list<array{days: list<Carbon>, laneRows: list<list<mixed>>, overflow: array<string, int>}>
     */
    #[Computed]
    public function weeks(): array
    {
        $events = $this->visibleEvents();

        $weeks = [];
        $cursor = $this->gridStart();

        while ($cursor->lte($this->gridEnd())) {
            $weekStart = $cursor->copy();
            $weekEnd = $cursor->copy()->addDays(6);

            $weeks[] = $this->buildWeek($weekStart, $weekEnd, $events);

            $cursor = $cursor->addWeek();
        }

        return $weeks;
    }

    /**
     * @return Collection<int, Event>
     */
    private function visibleEvents(): Collection
    {
        return Event::whereDate('starts_at', '<=', $this->gridEnd())
            ->with(['goal', 'entity'])
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Event $event): bool => ($event->ends_at ?? $event->starts_at)->toDateString() >= $this->gridStart()->toDateString());
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return array{days: list<Carbon>, laneRows: list<list<mixed>>, overflow: array<string, int>}
     */
    private function buildWeek(Carbon $weekStart, Carbon $weekEnd, Collection $events): array
    {
        $weekEvents = $events->filter(function (Event $event) use ($weekStart, $weekEnd): bool {
            $start = $event->starts_at->toDateString();
            $end = ($event->ends_at ?? $event->starts_at)->toDateString();

            return $start <= $weekEnd->toDateString() && $end >= $weekStart->toDateString();
        });

        [$laneRows, $overflow] = $this->assignLanes($weekEvents, $weekStart, $weekEnd);

        return [
            'days' => CarbonPeriod::create($weekStart, $weekEnd)->toArray(),
            'laneRows' => $laneRows,
            'overflow' => $overflow,
        ];
    }

    /**
     * Greedy first-fit lane assignment (the same interval-scheduling
     * approach Google Calendar's month view uses): events are placed in the
     * first lane whose last-placed event ends before this one starts, so
     * multi-day events reserve their whole span and never collide.
     *
     * @param  Collection<int, Event>  $events
     * @return array{0: list<list<mixed>>, 1: array<string, int>}
     */
    private function assignLanes(Collection $events, Carbon $weekStart, Carbon $weekEnd): array
    {
        $lanes = [];
        $laneLastCol = [];
        $overflow = [];

        foreach ($events as $event) {
            $startDate = max($event->starts_at->toDateString(), $weekStart->toDateString());
            $endDate = min(($event->ends_at ?? $event->starts_at)->toDateString(), $weekEnd->toDateString());

            $startCol = (int) $weekStart->diffInDays(Carbon::parse($startDate));
            $endCol = (int) $weekStart->diffInDays(Carbon::parse($endDate));

            $placedLane = null;

            foreach ($laneLastCol as $lane => $lastCol) {
                if ($startCol > $lastCol) {
                    $placedLane = $lane;
                    break;
                }
            }

            if ($placedLane === null && count($lanes) < self::MAX_LANES) {
                $placedLane = count($lanes);
                $lanes[$placedLane] = [];
            }

            if ($placedLane === null) {
                for ($col = $startCol; $col <= $endCol; $col++) {
                    $date = $weekStart->copy()->addDays($col)->toDateString();
                    $overflow[$date] = ($overflow[$date] ?? 0) + 1;
                }

                continue;
            }

            $lanes[$placedLane][] = [
                'event' => $event,
                'startCol' => $startCol,
                'colSpan' => $endCol - $startCol + 1,
                // Whether this week's slice is where the event actually
                // starts/ends (vs. merely clipped by the week boundary) —
                // only true edges get rounded corners, so a multi-week event
                // visually "continues" across the square-cut edge.
                'isStart' => $startDate === $event->starts_at->toDateString(),
                'isEnd' => $endDate === ($event->ends_at ?? $event->starts_at)->toDateString(),
            ];
            $laneLastCol[$placedLane] = $endCol;
        }

        // Always exactly MAX_LANES rows (padded with empty ones) so every
        // week renders at the same height regardless of how many events it
        // holds — an uneven accordion of row heights is the single biggest
        // thing that made the grid read as unpolished.
        for ($lane = count($lanes); $lane < self::MAX_LANES; $lane++) {
            $lanes[$lane] = [];
        }

        $laneRows = array_map(fn (array $placements): array => $this->laneRow($placements), $lanes);

        return [$laneRows, $overflow];
    }

    /**
     * @param  list<array{event: Event, startCol: int, colSpan: int, isStart: bool, isEnd: bool}>  $placements
     * @return list<mixed>
     */
    private function laneRow(array $placements): array
    {
        $row = array_fill(0, 7, null);

        foreach ($placements as $placement) {
            $row[$placement['startCol']] = $placement;

            for ($col = $placement['startCol'] + 1; $col < $placement['startCol'] + $placement['colSpan']; $col++) {
                $row[$col] = 'covered';
            }
        }

        return $row;
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonth()->format('Y-m');

        unset($this->monthLabel, $this->weeks);
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonth()->format('Y-m');

        unset($this->monthLabel, $this->weeks);
    }

    public function goToToday(): void
    {
        $this->month = today()->format('Y-m');

        unset($this->monthLabel, $this->weeks);
    }
};
?>

<div class="flex min-h-[calc(100vh-120px)] flex-col">
    <x-screen-header :kicker="$this->syncLabel" :title="$this->monthLabel" class="mb-3.5">
        <x-slot:actions>
            <x-btn variant="secondary" class="!px-3.5" wire:click="previousMonth" aria-label="Mois précédent">←</x-btn>
            <button
                type="button"
                wire:click="goToToday"
                class="press rounded-full bg-(--acbg) px-4 py-2 text-[13px] font-semibold text-(--ac)"
            >Aujourd'hui</button>
            <x-btn variant="secondary" class="!px-3.5" wire:click="nextMonth" aria-label="Mois suivant">→</x-btn>
        </x-slot:actions>
    </x-screen-header>

    {{-- La légende d'abord : une teinte ne se lit jamais seule, elle se lit
         avec le nom de l'objectif qui la porte. --}}
    @if ($this->legendGoals->isNotEmpty())
        <div class="mb-3.5 flex flex-wrap gap-x-4 gap-y-2 border-b border-(--bd) pb-3.5 text-[12px] text-(--mut)">
            @foreach ($this->legendGoals as $goal)
                <span class="flex items-center gap-[7px]" wire:key="legend-{{ $goal->id }}">
                    <span class="size-[10px] rounded-[3px]" style="background: var(--goal-{{ $goal->color_slot }})"></span>{{ $goal->title }}
                </span>
            @endforeach
        </div>
    @endif

    <div class="mb-1.5 grid grid-cols-7 gap-1.5 text-[11px] tracking-[.1em] text-(--mut) uppercase">
        @foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $label)
            <div class="pl-1">{{ $label }}</div>
        @endforeach
    </div>

    <div class="flex min-h-[560px] flex-1 flex-col gap-1.5 pb-6">
        @foreach ($this->weeks as $week)
            @php $weekKey = $week['days'][0]->toDateString(); @endphp

            {{-- Chaque semaine est une couche de fond (sept cartes de jour) et
                 une couche de contenu par-dessus, dans la même grille. C'est ce
                 qui permet à une barre multi-jours de traverser les gouttières
                 sans casser les cartes : la continuité reste visible. --}}
            <div class="relative min-h-[92px] flex-1" wire:key="week-{{ $weekKey }}">
                <div class="absolute inset-0 grid grid-cols-7 gap-1.5" aria-hidden="true">
                    @foreach ($week['days'] as $day)
                        @php $isCurrentMonth = $day->month === $this->monthStart()->month; @endphp
                        <div
                            @class([
                                'rounded-[10px]',
                                'bg-(--surf)' => $isCurrentMonth && ! $day->isWeekend(),
                                'bg-(--sunk)' => ! $isCurrentMonth || $day->isWeekend(),
                                'opacity-40' => ! $isCurrentMonth,
                                'outline-2 -outline-offset-2 outline-(--ac)' => $day->isToday(),
                            ])
                        ></div>
                    @endforeach
                </div>

                <div class="relative grid h-full grid-cols-7 content-start gap-x-1.5 gap-y-1 py-2">
                    @foreach ($week['days'] as $day)
                        @php $isCurrentMonth = $day->month === $this->monthStart()->month; @endphp
                        <div class="px-[9px]" wire:key="daynum-{{ $day->toDateString() }}">
                            <span @class([
                                'font-display text-[15px]',
                                'font-bold text-(--tx)' => $day->isToday(),
                                'font-medium text-(--mut)' => ! $day->isToday(),
                                'opacity-40' => ! $isCurrentMonth,
                            ])>{{ $day->day }}</span>
                        </div>
                    @endforeach

                    @foreach ($week['laneRows'] as $laneIndex => $row)
                        @foreach ($row as $col => $slot)
                            @continue($slot === 'covered')

                            @if ($slot === null)
                                <div wire:key="filler-{{ $weekKey }}-{{ $laneIndex }}-{{ $col }}" class="h-[21px]"></div>
                            @else
                                @php $hue = 'var(--goal-'.$this->eventColorSlot($slot['event']).')'; @endphp
                                <div
                                    wire:key="bar-{{ $slot['event']->id }}-{{ $weekKey }}-{{ $laneIndex }}"
                                    class="group/bar relative px-[9px]"
                                    style="grid-column: span {{ $slot['colSpan'] }};"
                                >
                                    {{-- Aplat plein + texte foncé : la couleur
                                         porte l'information, jamais un simple
                                         liseré. --}}
                                    <button
                                        type="button"
                                        wire:click="openEditEventModal({{ $slot['event']->id }})"
                                        @class([
                                            'press block h-[21px] w-full cursor-pointer truncate px-1.5 py-[3px] text-left text-[11px] leading-[15px] hover:brightness-110',
                                            'rounded-l-[4px]' => $slot['isStart'],
                                            'rounded-r-[4px]' => $slot['isEnd'],
                                        ])
                                        style="background-color: {{ $hue }}; color: var(--onhue);"
                                    >
                                        @if ($slot['colSpan'] === 1 && $slot['isStart'])
                                            {{ $slot['event']->starts_at->format('H:i') }}
                                        @endif
                                        {{ $slot['event']->title }}
                                    </button>

                                    <div class="pointer-events-none invisible absolute bottom-full left-[9px] z-50 mb-1.5 w-max max-w-64 rounded-[12px] bg-(--surf2) px-3 py-2.5 opacity-0 transition-opacity duration-100 group-hover/bar:visible group-hover/bar:opacity-100">
                                        <p class="text-[13px] font-medium text-(--tx)">{{ $slot['event']->title }}</p>
                                        <p class="mt-0.5 text-[12px] text-(--mut)">{{ $this->eventDateRange($slot['event']) }}</p>
                                        @if ($slot['event']->goal || $slot['event']->entity)
                                            <div class="mt-1.5 flex flex-wrap gap-2">
                                                <x-badge type="goal" :goal="$slot['event']->goal" />
                                                <x-badge type="entity" :entity="$slot['event']->entity" />
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    @endforeach

                    @foreach ($week['days'] as $day)
                        <div class="h-[16px] px-[9px]" wire:key="overflow-{{ $day->toDateString() }}">
                            @if ($count = $week['overflow'][$day->toDateString()] ?? 0)
                                <span class="text-[11px] text-(--mut)">+{{ $count }} de plus</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <flux:modal wire:model.self="showEditEventModal" class="md:w-[420px]">
        <div class="space-y-5" wire:loading.class="opacity-50" wire:target="saveEvent">
            <flux:heading size="lg">Modifier l'événement</flux:heading>

            <div>
                <label class="text-xs text-(--mut)">Titre</label>
                <input
                    type="text"
                    wire:model="eventTitle"
                    wire:loading.attr="disabled"
                    wire:target="saveEvent"
                    class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)"
                />
                @error('eventTitle') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs text-(--mut)">Début</label>
                    <input
                        type="datetime-local"
                        wire:model="eventStartsAt"
                        wire:loading.attr="disabled"
                        wire:target="saveEvent"
                        class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-2 py-2 font-mono text-xs text-(--tx)"
                    />
                    @error('eventStartsAt') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-(--mut)">Fin (optionnel)</label>
                    <input
                        type="datetime-local"
                        wire:model="eventEndsAt"
                        wire:loading.attr="disabled"
                        wire:target="saveEvent"
                        class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-2 py-2 font-mono text-xs text-(--tx)"
                    />
                    @error('eventEndsAt') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="text-xs text-(--mut)">Objectif lié (optionnel)</label>
                <select wire:model="eventGoalId" wire:loading.attr="disabled" wire:target="saveEvent" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)">
                    <option value="">—</option>
                    @foreach ($this->editGoalOptions as $goal)
                        <option value="{{ $goal->id }}">{{ $goal->title }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-xs text-(--mut)">Entité liée (optionnel)</label>
                <select wire:model="eventEntityId" wire:loading.attr="disabled" wire:target="saveEvent" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)">
                    <option value="">—</option>
                    @foreach ($this->editEntityOptions as $entity)
                        <option value="{{ $entity->id }}">{{ $entity->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-xs text-(--mut)">Notes (optionnel)</label>
                <textarea
                    wire:model="eventNotes"
                    wire:loading.attr="disabled"
                    wire:target="saveEvent"
                    rows="3"
                    class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)"
                ></textarea>
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showEditEventModal', false)" wire:loading.attr="disabled" wire:target="saveEvent">Annuler</x-btn>
                <x-btn variant="primary" wire:click="saveEvent" wire:loading.attr="disabled" wire:target="saveEvent">
                    <span wire:loading wire:target="saveEvent">Enregistrement…</span>
                    <span wire:loading.remove wire:target="saveEvent">Enregistrer</span>
                </x-btn>
            </div>
        </div>
    </flux:modal>
</div>
