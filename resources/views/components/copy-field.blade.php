@props([
    'label' => null,
    'value' => '',
    'display' => null,
    'copyable' => true,
])

{{-- Une valeur technique ne se sélectionne pas à la souris : elle se copie en
     un clic. Le mono est gardé ici — c'est exactement le cas où il porte du
     sens (URL, jeton), pas de la décoration. --}}
<div x-data="{ copied: false }" {{ $attributes->only('class') }}>
    @if ($label)
        <p class="mb-1.5 text-[12px] font-medium text-(--mut)">{{ $label }}</p>
    @endif

    <div class="flex items-center gap-2 rounded-full bg-(--surf2) py-[5px] pr-[5px] pl-4">
        <code class="min-w-0 flex-1 truncate font-mono text-[12.5px] {{ $copyable ? 'text-(--tx)' : 'text-(--mut)' }}">{{ $display ?? $value }}</code>

        @if ($copyable)
            <button
                type="button"
                x-on:click="navigator.clipboard.writeText(@js($value)).then(() => { copied = true; setTimeout(() => copied = false, 1600) }).catch(() => {})"
                class="press shrink-0 rounded-full px-3 py-[6px] text-[12px] font-semibold text-(--mut) hover:bg-(--bd2) hover:text-(--tx)"
            >
                <span x-show="! copied">Copier</span>
                <span x-show="copied" x-cloak class="text-(--ai)">Copié ✓</span>
            </button>
        @endif
    </div>
</div>
