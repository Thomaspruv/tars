<?php

use App\Models\Event;
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

    /**
     * Categorical hues events are colored by when they belong to a goal —
     * blue is skipped (already the default/goal-less accent) and violet is
     * skipped (reads as the --ai agent color elsewhere in the app). Order
     * matters: it's the CVD-safe adjacent ordering from the dataviz skill's
     * reference palette, validated against this app's actual surfaces.
     *
     * @var list<string>
     */
    private const array GOAL_COLOR_SLOTS = ['green', 'magenta', 'yellow', 'aqua', 'orange', 'violet', 'red'];

    #[Url]
    public ?string $month = null;

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
     * Deterministic so the same goal always gets the same color across the
     * whole calendar (and across renders) — not a color per event, a color
     * per goal. Goal-less events return null and keep the default accent.
     */
    private function eventColorSlot(Event $event): ?string
    {
        if ($event->goal_id === null) {
            return null;
        }

        return self::GOAL_COLOR_SLOTS[$event->goal_id % count(self::GOAL_COLOR_SLOTS)];
    }

    private function eventTooltip(Event $event): string
    {
        $range = $event->ends_at !== null && $event->ends_at->toDateString() !== $event->starts_at->toDateString()
            ? $event->starts_at->translatedFormat('d M').' → '.$event->ends_at->translatedFormat('d M')
            : $event->starts_at->translatedFormat('d M à H:i');

        $goal = $event->goal ? ' · #'.$event->goal->tag : '';

        return "{$event->title} · {$range}{$goal}";
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

            $lanes[$placedLane][] = ['event' => $event, 'startCol' => $startCol, 'colSpan' => $endCol - $startCol + 1];
            $laneLastCol[$placedLane] = $endCol;
        }

        $laneRows = array_map(fn (array $placements): array => $this->laneRow($placements), $lanes);

        return [$laneRows, $overflow];
    }

    /**
     * @param  list<array{event: Event, startCol: int, colSpan: int}>  $placements
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

<div>
    <x-screen-header treatment="work" title="Calendrier">
        <x-slot:actions>
            <x-btn variant="secondary" class="!px-3 !py-1.5" wire:click="previousMonth">←</x-btn>
            <x-btn variant="secondary" wire:click="goToToday">Aujourd'hui</x-btn>
            <x-btn variant="secondary" class="!px-3 !py-1.5" wire:click="nextMonth">→</x-btn>
        </x-slot:actions>
    </x-screen-header>

    <h2 class="mt-5 text-lg font-semibold text-(--tx)">{{ $this->monthLabel }}</h2>

    <div class="mt-3 grid grid-cols-7 overflow-hidden rounded-[12px] border border-(--bd)">
        @foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $i => $label)
            <div class="border-b border-(--bd) bg-(--surf2) px-2 py-1.5 text-center font-mono text-[10.5px] font-semibold text-(--mut) uppercase {{ $i < 6 ? 'border-r' : '' }}">
                {{ $label }}
            </div>
        @endforeach

        @foreach ($this->weeks as $week)
            @php $isFirstDayOfWeekCurrentMonth = $week['days'][0]->toDateString(); @endphp

            {{-- day-number row --}}
            @foreach ($week['days'] as $i => $day)
                @php $isCurrentMonth = $day->month === $this->monthStart()->month; @endphp
                <div
                    wire:key="daynum-{{ $day->toDateString() }}"
                    class="border-t border-(--bd) bg-(--surf) px-1.5 pt-1.5 pb-0.5 {{ $i < 6 ? 'border-r' : '' }} {{ $isCurrentMonth ? '' : 'opacity-40' }}"
                >
                    <span class="inline-flex size-5 items-center justify-center rounded-full font-mono text-[11px] {{ $day->isToday() ? 'bg-(--ac) text-white' : 'text-(--mut)' }}">
                        {{ $day->day }}
                    </span>
                </div>
            @endforeach

            {{-- event bars, one row per lane --}}
            @foreach ($week['laneRows'] as $laneIndex => $row)
                @foreach ($row as $col => $slot)
                    @continue($slot === 'covered')

                    @if ($slot === null)
                        <div
                            wire:key="filler-{{ $isFirstDayOfWeekCurrentMonth }}-{{ $laneIndex }}-{{ $col }}"
                            class="bg-(--surf) {{ $col < 6 ? 'border-r border-(--bd)' : '' }}"
                        ></div>
                    @else
                        @php
                            $colorSlot = $this->eventColorSlot($slot['event']);
                            $bgVar = $colorSlot ? "--goal-{$colorSlot}-bg" : '--acbg';
                            $textVar = $colorSlot ? "--goal-{$colorSlot}" : '--ac';
                        @endphp
                        <div
                            wire:key="bar-{{ $slot['event']->id }}-{{ $isFirstDayOfWeekCurrentMonth }}-{{ $laneIndex }}"
                            class="bg-(--surf) px-0.5 pb-0.5 {{ $col + $slot['colSpan'] > 6 ? '' : 'border-r border-(--bd)' }}"
                            style="grid-column: span {{ $slot['colSpan'] }};"
                        >
                            <div
                                title="{{ $this->eventTooltip($slot['event']) }}"
                                class="truncate rounded-[4px] px-1.5 py-[3px] text-[11px] leading-[13px] transition-[filter,box-shadow] duration-100 hover:shadow-[inset_0_0_0_1px_var(--bar-color)] hover:brightness-110"
                                style="background-color: var({{ $bgVar }}); color: var({{ $textVar }}); --bar-color: var({{ $textVar }});"
                            >
                                @if ($slot['colSpan'] === 1)
                                    <span class="font-mono text-[10px] opacity-70">{{ $slot['event']->starts_at->format('H:i') }}</span>
                                @endif
                                {{ $slot['event']->title }}
                            </div>
                        </div>
                    @endif
                @endforeach
            @endforeach

            {{-- overflow indicators --}}
            @if (array_sum($week['overflow']) > 0)
                @foreach ($week['days'] as $i => $day)
                    <div
                        wire:key="overflow-{{ $day->toDateString() }}"
                        class="bg-(--surf) px-1.5 pb-1 {{ $i < 6 ? 'border-r border-(--bd)' : '' }}"
                    >
                        @if ($count = $week['overflow'][$day->toDateString()] ?? 0)
                            <span class="text-[10px] text-(--mut)">+{{ $count }} de plus</span>
                        @endif
                    </div>
                @endforeach
            @endif
        @endforeach
    </div>
</div>
