@props(['goal', 'weeklyActivity' => null, 'stagnant' => false])

@php
    $milestones = $goal->milestones;
    $milestonesTotal = $milestones->count();
    $milestonesDone = $milestones->where('status', \App\Enums\MilestoneStatus::Done)->count();
    $nextMilestone = $milestones->firstWhere('status', \App\Enums\MilestoneStatus::Pending);

    $isDone = $goal->status === \App\Enums\GoalStatus::Done;
    $isPaused = $goal->status === \App\Enums\GoalStatus::Paused;

    $maxActivity = $weeklyActivity ? max([1, ...$weeklyActivity]) : 1;
@endphp

<a
    href="{{ route('goals.show', $goal) }}"
    wire:navigate
    {{ $attributes->merge(['class' => 'block rounded-[18px] bg-(--surf) px-[22px] py-5 transition-[background-color,scale] duration-(--dur-swap) ease-(--ease) hover:bg-(--surf2) active:scale-[.99]']) }}
>
    <div class="mb-2.5 flex items-center gap-2.5">
        <span class="text-[11px] tracking-[.1em] uppercase {{ $isPaused ? 'text-(--mut)' : 'text-(--ai)' }}">
            {{ $isDone ? 'Atteint' : ($isPaused ? 'En pause' : 'En cours') }}
        </span>

        {{-- La stagnation est dite en français, en blé, jamais en rouge et
             jamais en gros. --}}
        @if ($stagnant)
            <span class="ml-auto text-[12px] text-(--warn)">rien depuis trois semaines</span>
        @elseif ($goal->target_date)
            <time class="ml-auto text-[12px] text-(--mut)" datetime="{{ $goal->target_date->toDateString() }}">
                cible : {{ $goal->target_date->translatedFormat('j F') }}
            </time>
        @else
            <time class="ml-auto text-[12px] text-(--mut)">ouvert le {{ $goal->created_at->translatedFormat('j F') }}</time>
        @endif
    </div>

    <h3 class="font-display mb-3.5 text-[21px] leading-[1.25] font-semibold {{ $isPaused ? 'text-(--mut)' : 'text-(--tx)' }}">
        {{ $goal->title }}
    </h3>

    {{-- Les jalons sont une rangée de segments — la même grammaire que la
         journée sur Aujourd'hui, à une autre échelle. --}}
    @if ($milestonesTotal > 0)
        <div class="mb-2.5 flex gap-[5px]">
            @foreach ($milestones as $milestone)
                <div
                    class="h-[10px] flex-1 rounded-full transition-colors duration-(--dur-swap) ease-(--ease) {{ $milestone->status === \App\Enums\MilestoneStatus::Done ? 'bg-(--ai)' : 'bg-(--bd2)' }}"
                    aria-hidden="true"
                ></div>
            @endforeach
        </div>

        <div class="flex items-baseline gap-2 {{ $weeklyActivity ? 'mb-4' : '' }}">
            <span class="font-display text-[18px] font-bold {{ $isDone ? 'text-(--ai)' : 'text-(--tx)' }}">{{ $milestonesDone }}</span>
            <span class="text-[13px] text-(--mut)">
                {{ Str::plural('jalon', $milestonesDone) }} sur {{ $milestonesTotal }}@if ($nextMilestone) · prochain : {{ $nextMilestone->title }}@endif
            </span>
        </div>
    @else
        <p class="mb-4 text-[13px] text-(--mut)">aucun jalon posé</p>
    @endif

    {{-- Le rythme : tâches terminées par semaine. Gris tant que ça tient,
         blé sur la dernière barre quand ça ralentit. --}}
    @if ($weeklyActivity && array_sum($weeklyActivity) > 0)
        <div class="mb-2 flex h-[34px] items-end gap-1">
            @foreach ($weeklyActivity as $count)
                <div
                    class="flex-1 rounded-t-[3px] {{ $loop->last && $stagnant ? 'bg-(--warn)' : 'bg-(--bd2)' }}"
                    style="height: {{ max(12, (int) round(($count / $maxActivity) * 100)) }}%"
                    aria-hidden="true"
                ></div>
            @endforeach
        </div>

        <div class="flex items-center gap-2.5">
            <span class="text-[12px] text-(--mut)">{{ count($weeklyActivity) }} dernières semaines</span>
            @if ($stagnant)
                <span class="ml-auto text-[12px] text-(--warn)">le rythme ralentit</span>
            @endif
        </div>
    @endif
</a>
