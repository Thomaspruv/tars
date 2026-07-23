@props(['goal', 'weeklyActivity' => null, 'stagnant' => false])

@php
    $milestonesTotal = $goal->milestones->count();
    $milestonesDone = $goal->milestones->where('status', \App\Enums\MilestoneStatus::Done)->count();
    $percent = $milestonesTotal > 0 ? (int) round(($milestonesDone / $milestonesTotal) * 100) : 0;
    $maxActivity = $weeklyActivity ? max([1, ...$weeklyActivity]) : 1;
@endphp

<a
    href="{{ route('goals.show', $goal) }}"
    wire:navigate
    {{ $attributes->merge(['class' => 'block rounded-[14px] border border-(--bd) bg-(--surf) p-5 transition-colors hover:border-(--ac)/40']) }}
>
    <div class="flex items-center justify-between gap-3">
        <x-badge-status :status="$goal->status" />
        @if ($goal->target_date)
            <time class="font-mono text-xs text-(--mut)" datetime="{{ $goal->target_date->toDateString() }}">
                {{ $goal->target_date->translatedFormat('M Y') }}
            </time>
        @endif
    </div>

    <h3 class="mt-3 text-[15.5px] font-semibold text-(--tx)">{{ $goal->title }}</h3>

    @if ($goal->lifeArea)
        <p class="mt-1 text-xs text-(--mut)">{{ $goal->lifeArea->name }}</p>
    @endif

    @if ($milestonesTotal > 0)
        <div class="mt-4">
            <div class="flex items-center justify-between text-xs">
                <span class="text-(--mut)">{{ $milestonesDone }}/{{ $milestonesTotal }} jalons</span>
                <span class="font-mono font-semibold text-(--ac)">{{ $percent }}%</span>
            </div>
            <div class="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-(--surf2)">
                <div class="h-full rounded-full" style="width: {{ $percent }}%; background: var(--gradient-signature)"></div>
            </div>
        </div>
    @endif

    @if ($weeklyActivity)
        <div class="mt-4 flex h-6 items-end gap-1">
            @foreach ($weeklyActivity as $index => $count)
                <div
                    class="flex-1 rounded-t-[2px] bg-(--ac)"
                    style="height: {{ max(15, (int) round(($count / $maxActivity) * 100)) }}%; opacity: {{ $loop->last ? 1 : 0.35 }}"
                ></div>
            @endforeach
        </div>
    @endif

    @if ($stagnant)
        <p class="mt-3 flex items-center gap-1.5 text-xs text-(--warn)">
            <span aria-hidden="true">⚠</span> Aucune activité récente
        </p>
    @endif
</a>
