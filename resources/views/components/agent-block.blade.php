@props([
    'title' => null,
    'meta' => null,
    'padding' => '18',
    'rail' => '4', // 4px pour les blocs pleins, 3px pour les citations en colonne étroite
])

{{-- Le bloc de l'agent : fond vert sombre *et* filet vert à gauche — deux
     signaux redondants, aucun marqueur exotique. Tout ce qu'Hermès produit le
     porte ; rien de ce que j'écris ne le porte. --}}
<x-card :padding="$padding" tone="agent" {{ $attributes->merge(['class' => 'flex gap-[14px]']) }}>
    <div class="{{ $rail === '3' ? 'w-[3px]' : 'w-[4px]' }} shrink-0 self-stretch rounded-full bg-(--ai)"></div>

    <div class="min-w-0 flex-1">
        @if ($title)
            <div class="mb-[5px] flex items-center gap-[9px]">
                <span class="text-[13px] font-bold text-(--ai)">{{ $title }}</span>
                @if ($meta)
                    <span class="text-[12px] text-(--mut)">{{ $meta }}</span>
                @endif
            </div>
        @endif

        {{ $slot }}
    </div>
</x-card>
