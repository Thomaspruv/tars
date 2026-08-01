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
     * @return list<list<Carbon>>
     */
    #[Computed]
    public function weeks(): array
    {
        return collect(CarbonPeriod::create($this->gridStart(), $this->gridEnd()))
            ->chunk(7)
            ->map(fn ($chunk) => $chunk->values())
            ->values()
            ->all();
    }

    /**
     * @return Collection<string, Collection<int, Event>>
     */
    #[Computed]
    public function eventsByDay(): Collection
    {
        return Event::whereBetween('starts_at', [$this->gridStart(), $this->gridEnd()->endOfDay()])
            ->with(['goal', 'entity'])
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (Event $event) => $event->starts_at->toDateString());
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonth()->format('Y-m');

        unset($this->monthLabel, $this->weeks, $this->eventsByDay);
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonth()->format('Y-m');

        unset($this->monthLabel, $this->weeks, $this->eventsByDay);
    }

    public function goToToday(): void
    {
        $this->month = today()->format('Y-m');

        unset($this->monthLabel, $this->weeks, $this->eventsByDay);
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

    <div class="mt-3 grid grid-cols-7 gap-px overflow-hidden rounded-[12px] border border-(--bd) bg-(--bd)">
        @foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $label)
            <div class="bg-(--surf2) px-2 py-1.5 text-center font-mono text-[10.5px] font-semibold text-(--mut) uppercase">{{ $label }}</div>
        @endforeach

        @foreach ($this->weeks as $week)
            @foreach ($week as $day)
                @php
                    $dayEvents = $this->eventsByDay->get($day->toDateString(), collect());
                    $isCurrentMonth = $day->month === $this->monthStart()->month;
                    $visibleEvents = $dayEvents->take(3);
                    $overflow = $dayEvents->count() - $visibleEvents->count();
                @endphp
                <div
                    wire:key="day-{{ $day->toDateString() }}"
                    class="min-h-24 bg-(--surf) p-1.5 {{ $isCurrentMonth ? '' : 'opacity-40' }}"
                >
                    <span class="inline-flex size-5 items-center justify-center rounded-full font-mono text-[11px] {{ $day->isToday() ? 'bg-(--ac) text-white' : 'text-(--mut)' }}">
                        {{ $day->day }}
                    </span>

                    <div class="mt-1 space-y-0.5">
                        @foreach ($visibleEvents as $event)
                            <div wire:key="event-{{ $event->id }}" class="truncate rounded-[4px] bg-(--acbg) px-1.5 py-0.5 text-[10.5px] text-(--ac)">
                                <span class="font-mono">{{ $event->starts_at->format('H:i') }}</span> {{ $event->title }}
                            </div>
                        @endforeach

                        @if ($overflow > 0)
                            <div class="px-1.5 text-[10.5px] text-(--mut)">+{{ $overflow }} de plus</div>
                        @endif
                    </div>
                </div>
            @endforeach
        @endforeach
    </div>
</div>
