@props([
    'goal',
    'lastActivity' => null,
    'weekTasks' => 0,
])

@php
    use App\Support\Goals\GoalRhythm;

    $milestones = $goal->milestones;
    $milestonesTotal = $milestones->count();
    $milestonesDone = $milestones->where('status', \App\Enums\MilestoneStatus::Done)->count();
    $nextMilestone = $milestones->firstWhere('status', \App\Enums\MilestoneStatus::Pending);

    $isDone = $goal->status === \App\Enums\GoalStatus::Done;
    $isPaused = $goal->status === \App\Enums\GoalStatus::Paused;

    $lastActivity ??= GoalRhythm::lastActivityAt($goal);
    $stagnant = ! $isDone && GoalRhythm::isStagnant($lastActivity);

    // Le sur-titre dit d'où vient l'objectif et jusqu'où il va : l'entité
    // rattachée, puis l'horizon déduit de l'échéance. C'est ce que le doc
    // écrivait « Santé · trimestre » — le domaine de vie n'existe pas en base,
    // on ne l'invente pas, mais l'horizon se déduit.
    $kicker = collect([$goal->entity?->name, $goal->horizon_label])->filter()->implode(' · ');
@endphp

<a
    href="{{ route('goals.show', $goal) }}"
    wire:navigate
    {{ $attributes->merge(['class' => 'block rounded-[18px] bg-(--surf) px-[22px] py-5 transition-[background-color,scale] duration-(--dur-swap) ease-(--ease) hover:bg-(--surf2) active:scale-[.99]']) }}
>
    <div class="mb-2.5 flex items-center gap-2.5">
        <span class="size-[10px] shrink-0 rounded-[3px]" style="background: var(--goal-{{ $goal->color_slot }})" aria-hidden="true"></span>
        <span class="min-w-0 truncate text-[11px] text-(--mut) kicker">{{ $kicker }}</span>

        {{-- La stagnation est dite en français, en blé, jamais en rouge et
             jamais en gros. Le reste du temps, la carte dit simplement quand
             l'objectif a bougé pour la dernière fois. --}}
        @if ($isDone)
            <span class="ml-auto shrink-0 text-[12px] text-(--ai)">atteint</span>
        @elseif ($stagnant)
            <span class="ml-auto shrink-0 rounded-full bg-(--warnbg) px-2.5 py-0.5 text-[12px] font-bold text-(--warn)">
                {{ GoalRhythm::movedLabel($lastActivity) }}
            </span>
        @else
            <span class="ml-auto shrink-0 text-[12px] text-(--mut)">{{ GoalRhythm::movedLabel($lastActivity) }}</span>
        @endif
    </div>

    <h3 class="font-display mb-3.5 text-[20px] leading-[1.25] font-bold tracking-[-.01em] {{ $isPaused ? 'text-(--mut)' : 'text-(--tx)' }}">
        {{ $goal->title }}
    </h3>

    {{-- Les jalons sont une rangée de segments — la même grammaire que la
         journée sur Aujourd'hui, à une autre échelle. --}}
    @if ($milestonesTotal > 0)
        <div class="mb-1.5 flex items-baseline justify-between text-[13px] font-semibold text-(--mut)">
            <span>Jalons</span>
            <span class="font-display font-bold tabular-nums {{ $isDone ? 'text-(--ai)' : 'text-(--tx)' }}">{{ $milestonesDone }} / {{ $milestonesTotal }}</span>
        </div>

        <div class="mb-3.5 flex gap-[5px]">
            @foreach ($milestones as $milestone)
                <div
                    @class([
                        'h-[8px] flex-1 rounded-full transition-colors duration-(--dur-swap) ease-(--ease)',
                        'bg-(--bd2)' => $milestone->status !== \App\Enums\MilestoneStatus::Done,
                    ])
                    @if ($milestone->status === \App\Enums\MilestoneStatus::Done)
                        style="background: var(--goal-{{ $goal->color_slot }})"
                    @endif
                    aria-hidden="true"
                ></div>
            @endforeach
        </div>
    @else
        <p class="mb-3.5 text-[13px] text-(--mut)">Aucun jalon posé.</p>
    @endif

    {{-- Le pied de carte : ce qui vient, et ce qui est déjà posé dans la
         semaine. Deux faits, pas de jauge de plus. --}}
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-[13px] text-(--mut)">
        <span class="min-w-0 truncate">
            @if ($nextMilestone)
                Prochain : {{ $nextMilestone->title }}
            @elseif ($goal->target_date)
                Cible : {{ $goal->target_date->translatedFormat('j F') }}
            @else
                Ouvert le {{ $goal->created_at->translatedFormat('j F') }}
            @endif
        </span>

        <span class="shrink-0">
            @if ($weekTasks > 0)
                {{ $weekTasks }} {{ Str::plural('tâche', $weekTasks) }} cette semaine
            @else
                Rien cette semaine
            @endif
        </span>
    </div>
</a>
