<?php

use App\Enums\MilestoneStatus;
use App\Models\Entity;
use App\Models\Event;
use App\Models\Goal;
use App\Models\GoogleCalendarConnection;
use App\Models\Milestone;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Planning · Mois')] class extends Component
{
    /**
     * Au-delà, la journée est « chargée » et prend un fond blé. Six choses dans
     * une journée, ce n'est pas une alerte — c'est une information qu'on veut
     * voir avant de poser la septième.
     */
    private const int HEAVY_DAY = 6;

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
        // La semaine commence le lundi, elle finit donc le dimanche : sans
        // ça la dernière ligne de la grille s'arrêtait au lundi et six cases
        // n'existaient pas dans `days()`.
        return $this->monthStart()->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
    }

    #[Computed]
    public function monthLabel(): string
    {
        return ucfirst($this->monthStart()->translatedFormat('F Y'));
    }

    /**
     * La charge du mois, jour par jour.
     *
     * Le mois ne sert pas à lire le détail d'une journée — il sert à voir où
     * elle est lourde et où tombent les échéances. Chaque case porte donc deux
     * lignes au plus : ce qu'il y a, et la chose la plus notable.
     *
     * @return array<string, array{tasks: int, events: int, deadlines: int, notable: array{kind: string, label: string, event: Event|null}|null}>
     */
    #[Computed]
    public function days(): array
    {
        $from = $this->gridStart();
        $to = $this->gridEnd();

        $days = [];

        // Deux pièges de date dans la même boucle, et ils se contredisent :
        // `now()`/`today()` rendent des dates *immuables*
        // (`Date::use(CarbonImmutable)` dans AppServiceProvider), alors que
        // `Carbon::createFromFormat()` rend une date *mutable*. D'où la forme
        // qui marche dans les deux cas : on part d'une copie, et on réassigne.
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor = $cursor->addDay()) {
            $days[$cursor->toDateString()] = ['tasks' => 0, 'events' => 0, 'deadlines' => 0, 'notable' => null];
        }

        // Les événements comptent sur chaque jour qu'ils traversent : une
        // semaine de vacances pèse sur sept cases, pas sur la première.
        foreach ($this->visibleEvents() as $event) {
            $start = $event->starts_at->startOfDay()->max($from);
            $end = ($event->ends_at ?? $event->starts_at)->startOfDay()->min($to);

            for ($cursor = $start->copy(); $cursor->lte($end); $cursor = $cursor->addDay()) {
                $key = $cursor->toDateString();

                if (! isset($days[$key])) {
                    continue;
                }

                $days[$key]['events']++;

                if ($days[$key]['notable'] === null || $days[$key]['notable']['kind'] === 'task') {
                    $days[$key]['notable'] = [
                        'kind' => 'event',
                        'label' => ($cursor->isSameDay($event->starts_at) ? $event->starts_at->format('H:i').' ' : '').$event->title,
                        'event' => $event,
                    ];
                }
            }
        }

        foreach ($this->visibleTasks() as $task) {
            $day = ($task->scheduled_date ?? $task->due_date)->toDateString();

            if (! isset($days[$day])) {
                continue;
            }

            $days[$day]['tasks']++;

            if ($days[$day]['notable'] === null) {
                $days[$day]['notable'] = ['kind' => 'task', 'label' => $task->title, 'event' => null];
            }

            // Une échéance n'est pas une tâche de plus : c'est une date qui ne
            // se déplace pas, et elle passe donc devant tout le reste.
            if ($task->due_date !== null && $task->due_date->toDateString() === $day) {
                $days[$day]['deadlines']++;
                $days[$day]['notable'] = ['kind' => 'deadline', 'label' => $task->title, 'event' => null];
            }
        }

        foreach ($this->visibleMilestones() as $milestone) {
            $day = $milestone->target_date->toDateString();

            if (! isset($days[$day])) {
                continue;
            }

            $days[$day]['deadlines']++;
            $days[$day]['notable'] = ['kind' => 'deadline', 'label' => 'Jalon : '.$milestone->title, 'event' => null];
        }

        return $days;
    }

    #[Computed]
    public function monthSummary(): string
    {
        $tasks = 0;
        $events = 0;
        $deadlines = 0;
        $month = $this->monthStart()->month;

        foreach ($this->days as $date => $day) {
            if (Carbon::parse($date)->month !== $month) {
                continue;
            }

            $tasks += $day['tasks'];
            $events += $day['events'];
            $deadlines += $day['deadlines'];
        }

        $parts = [];

        if ($tasks > 0) {
            $parts[] = $tasks.' '.Str::plural('tâche', $tasks);
        }

        if ($events > 0) {
            $parts[] = $events.' '.Str::plural('événement', $events);
        }

        if ($deadlines > 0) {
            $parts[] = $deadlines.' '.Str::plural('échéance', $deadlines);
        }

        return $parts === [] ? 'rien de posé ce mois-ci' : implode(' · ', $parts);
    }

    /**
     * Les semaines de la grille — chacune ses sept jours.
     *
     * @return list<list<Carbon>>
     */
    #[Computed]
    public function weeks(): array
    {
        $weeks = [];
        $cursor = $this->gridStart()->copy();
        $end = $this->gridEnd();

        while ($cursor->lte($end)) {
            $week = [];

            for ($index = 0; $index < 7; $index++) {
                $week[] = $cursor->copy();
                $cursor = $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return $weeks;
    }

    public function isHeavy(string $date): bool
    {
        $day = $this->days[$date] ?? null;

        return $day !== null && ($day['tasks'] + $day['events']) >= self::HEAVY_DAY;
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

    /**
     * @return Collection<int, Event>
     */
    private function visibleEvents(): Collection
    {
        return Event::whereDate('starts_at', '<=', $this->gridEnd())
            ->with(['goal', 'entity'])
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Event $event): bool => ($event->ends_at ?? $event->starts_at)->toDateString() >= $this->gridStart()->toDateString())
            ->values();
    }

    /**
     * @return Collection<int, Task>
     */
    private function visibleTasks(): Collection
    {
        return Task::topLevel()
            ->where(fn ($query) => $query
                ->whereBetween('scheduled_date', [$this->gridStart(), $this->gridEnd()])
                ->orWhereBetween('due_date', [$this->gridStart(), $this->gridEnd()]))
            ->orderBy('due_date')
            ->get();
    }

    /**
     * @return Collection<int, Milestone>
     */
    private function visibleMilestones(): Collection
    {
        return Milestone::whereNotNull('target_date')
            ->where('status', MilestoneStatus::Pending->value)
            ->whereBetween('target_date', [$this->gridStart(), $this->gridEnd()])
            ->get();
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

    /**
     * @return Collection<int, Goal>
     */
    #[Computed]
    public function editGoalOptions(): Collection
    {
        return Goal::where('status', 'active')->orderBy('title')->get();
    }

    /**
     * @return Collection<int, Entity>
     */
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

        $this->forgetMonth();
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonth()->format('Y-m');

        $this->forgetMonth();
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonth()->format('Y-m');

        $this->forgetMonth();
    }

    public function goToToday(): void
    {
        $this->month = today()->format('Y-m');

        $this->forgetMonth();
    }

    private function forgetMonth(): void
    {
        unset($this->monthLabel, $this->weeks, $this->days, $this->monthSummary);
    }
};
?>

{{-- Le mois sert à voir la charge et les échéances, pas le détail : deux lignes
     par jour au plus. Les journées chargées prennent un fond blé, les échéances
     et les jalons une pastille blé, le week-end est en creux. --}}
<div class="flex min-h-[calc(100vh-120px)] flex-col">
    <x-space-tabs space="planning" class="mb-3.5 -ml-3.5" />

    <x-screen-header :kicker="$this->monthSummary" :title="$this->monthLabel" class="mb-3.5">
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

    <p class="mb-3.5 border-b border-(--bd) pb-3.5 text-[12px] text-(--mut)">{{ $this->syncLabel }}</p>

    <div class="mb-1.5 grid grid-cols-7 gap-1.5 text-[11px] text-(--mut) kicker">
        @foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $label)
            <div class="pl-1">{{ $label }}</div>
        @endforeach
    </div>

    <div class="flex min-h-[520px] flex-1 flex-col gap-1.5">
        @foreach ($this->weeks as $week)
            <div class="grid flex-1 grid-cols-7 gap-1.5" wire:key="week-{{ $week[0]->toDateString() }}">
                @foreach ($week as $day)
                    @php
                        $date = $day->toDateString();
                        $cell = $this->days[$date] ?? ['tasks' => 0, 'events' => 0, 'deadlines' => 0, 'notable' => null];
                        $isCurrentMonth = $day->month === \Illuminate\Support\Carbon::parse($this->month.'-01')->month;
                        $heavy = $this->isHeavy($date);
                        $counts = collect([
                            $cell['tasks'] > 0 ? $cell['tasks'].' '.Str::plural('tâche', $cell['tasks']) : null,
                            $cell['events'] > 0 ? $cell['events'].' '.Str::plural('événement', $cell['events']) : null,
                        ])->filter()->implode(' · ');
                    @endphp

                    <div
                        @class([
                            'min-h-[86px] overflow-hidden rounded-[10px] px-2.5 py-2',
                            'bg-(--warnbg)' => $heavy && $isCurrentMonth,
                            'bg-(--surf)' => ! $heavy && $isCurrentMonth && ! $day->isWeekend(),
                            'bg-(--sunk)' => ! $heavy && (! $isCurrentMonth || $day->isWeekend()),
                            'opacity-40' => ! $isCurrentMonth,
                            'outline-2 -outline-offset-2 outline-(--ac)' => $day->isToday(),
                        ])
                        wire:key="day-{{ $date }}"
                    >
                        <div @class([
                            'font-display mb-1 text-[15px] font-extrabold',
                            'text-(--ac)' => $day->isToday(),
                            'text-(--tx)' => ! $day->isToday() && $isCurrentMonth,
                            'text-(--mut)' => ! $isCurrentMonth,
                        ])>{{ $day->day }}</div>

                        @if ($counts !== '')
                            <p class="truncate text-[12px] text-(--mut)">{{ $counts }}</p>
                        @endif

                        @if ($cell['notable'])
                            @if ($cell['notable']['kind'] === 'deadline')
                                <p class="flex items-center gap-1.5 truncate text-[12px] font-semibold text-(--warn)">
                                    <span class="size-[7px] shrink-0 rounded-full bg-(--warn)" aria-hidden="true"></span>
                                    <span class="truncate">{{ $cell['notable']['label'] }}</span>
                                </p>
                            @elseif ($cell['notable']['event'])
                                <button
                                    type="button"
                                    wire:click="openEditEventModal({{ $cell['notable']['event']->id }})"
                                    class="press block w-full truncate text-left text-[12px] text-(--tx) hover:text-(--ac)"
                                >{{ $cell['notable']['label'] }}</button>
                            @else
                                <p class="truncate text-[12px] text-(--tx)">{{ $cell['notable']['label'] }}</p>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="flex flex-wrap gap-x-5 gap-y-2 pt-3 pb-6 text-[12px] text-(--mut)">
        <span class="flex items-center gap-[7px]">
            <span class="size-[12px] rounded-[3px] bg-(--warnbg)"></span>Journée chargée
        </span>
        <span class="flex items-center gap-[7px]">
            <span class="size-[8px] rounded-full bg-(--warn)"></span>Échéance ou jalon
        </span>
        <span class="flex items-center gap-[7px]">
            <span class="size-[12px] rounded-[3px] bg-(--sunk)"></span>Week-end
        </span>
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
