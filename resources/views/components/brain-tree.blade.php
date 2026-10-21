@props(['tree', 'selectedPath' => null, 'depth' => 0, 'nonConformantPaths' => []])

{{-- L'arborescence est la navigation de l'écran, pas un chemin : elle parle
     donc la même langue que la barre latérale et que le sommaire des Réglages
     — 13px, sans empattement. Le mono reste pour ce qui est vraiment un chemin
     (le fil d'Ariane de la note ouverte, la destination calculée d'une
     nouvelle note, la branche git). --}}
<div class="flex flex-col gap-px text-[13px]">
    @foreach ($tree as $name => $value)
        @if (is_array($value))
            <div class="rounded-[8px] px-3 py-[7px] font-semibold text-(--mut)" style="padding-left: {{ 12 + $depth * 12 }}px">{{ $name }}</div>
            <x-brain-tree :tree="$value" :selected-path="$selectedPath" :depth="$depth + 1" :non-conformant-paths="$nonConformantPaths" />
        @else
            <button
                type="button"
                wire:click="selectDocument('{{ $value }}')"
                class="press flex w-full items-center gap-1.5 rounded-[8px] py-[7px] pr-3 text-left {{ $selectedPath === $value ? 'bg-(--surf2) font-semibold text-(--tx)' : 'text-(--tx) hover:bg-(--surf)' }}"
                style="padding-left: {{ 12 + $depth * 12 }}px"
            >
                <span class="truncate">{{ $name }}</span>
                @if (in_array($value, $nonConformantPaths, true))
                    <span
                        class="ml-auto size-[7px] shrink-0 rounded-full bg-(--warn)"
                        title="Non conforme au contrat v3"
                        aria-label="Non conforme au contrat v3"
                    ></span>
                @endif
            </button>
        @endif
    @endforeach
</div>
