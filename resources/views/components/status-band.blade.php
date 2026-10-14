@props(['gauges', 'streak' => 0, 'nextTier' => null])

@php
    // La bande d'état des cinq écrans de travail : trois jauges de saison à
    // gauche, la série à droite. Aucune carte, aucun encadré — juste un filet
    // sous le titre. Elle ne s'affiche que s'il y a quelque chose à dire.
    $tierDots = $nextTier ?? max($streak, 7);
    $dots = min($tierDots, 14);
@endphp

@if ($gauges->isNotEmpty() || $streak > 0)
    <div class="mb-5 flex flex-wrap items-center gap-x-6 gap-y-4 border-b border-(--bd) pt-3 pb-4">
        @foreach ($gauges as $gauge)
            <div class="max-w-[200px] min-w-[140px] flex-1" wire:key="gauge-{{ $gauge->goal->id }}">
                <div class="mb-1.5 flex items-baseline gap-2">
                    <span class="truncate text-[12px] text-(--mut)">{{ $gauge->goal->title }}</span>
                    @if ($gauge->idleLabel())
                        <span class="shrink-0 text-[11px] text-(--warn)">{{ $gauge->idleLabel() }}</span>
                    @endif
                </div>
                <div class="h-[4px] rounded-full bg-(--surf2)">
                    <div
                        class="h-[4px] rounded-full transition-[width,background-color] duration-(--dur-enter) ease-(--ease)"
                        style="width: {{ $gauge->level }}%; background: var(--goal-{{ $gauge->tone() }})"
                    ></div>
                </div>
            </div>
        @endforeach

        @if ($streak > 0)
            <div class="ml-auto flex items-center gap-[10px]">
                <div class="flex gap-1">
                    @for ($day = 1; $day <= $dots; $day++)
                        <span
                            class="size-[8px] rounded-full transition-colors duration-(--dur-swap) ease-(--ease) {{ $day <= $streak ? 'bg-(--ai)' : 'border border-(--ac)' }}"
                            aria-hidden="true"
                        ></span>
                    @endfor
                </div>
                @if ($nextTier)
                    <span class="text-[12px] text-(--mut)">palier {{ $nextTier }} dans {{ $nextTier - $streak }} j</span>
                @endif
            </div>
        @endif
    </div>
@endif
