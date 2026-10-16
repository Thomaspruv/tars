@props([
    'title',
    'subtitle' => null,
    'status' => null,
    'tone' => 'neutral', // neutral | agent | warn | danger | sunk
])

{{-- Toutes les connexions de Tars (Google, MCP) se lisent sur la même carte :
     une marque, un nom, un état, puis les actions en pied. --}}
<x-card padding="22" {{ $attributes->merge(['class' => 'flex flex-col gap-4']) }}>
    <div class="flex items-start gap-3.5">
        @isset($mark)
            <span class="flex size-[40px] shrink-0 items-center justify-center rounded-[13px] bg-(--surf2)">{{ $mark }}</span>
        @endisset

        <div class="min-w-0 flex-1">
            <p class="text-[16px] font-semibold text-(--tx)">{{ $title }}</p>
            @if ($subtitle)
                <p class="mt-0.5 truncate text-[13px] text-(--mut)">{{ $subtitle }}</p>
            @endif
        </div>

        <div class="flex shrink-0 items-center gap-2.5">
            @if ($status)
                <x-badge type="pill" :tone="$tone">{{ $status }}</x-badge>
            @endif
            {{ $aside ?? '' }}
        </div>
    </div>

    {{ $slot }}

    @isset($actions)
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-(--bd) pt-4">{{ $actions }}</div>
    @endisset
</x-card>
