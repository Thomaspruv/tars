@props([
    'shape' => 'square', // square (tâche, jalon) | round (article de liste)
    'size' => '20',
    'tone' => 'default', // default | late (bord brique) | agent (bord pousse) | current (bord abricot)
    // Action Livewire déclenchée par la case, au format `methode(params)`. Elle
    // sert de cible au verrou : tant que *cette* requête est en vol, la case ne
    // peut pas être recochée. Sans ça, un deuxième clic pendant l'aller-retour
    // renvoie un toggle qui annule le premier.
    'target' => null,
])

@php
    // Cases *carrées* pour les tâches, *rondes* pour les articles de liste :
    // c'est la distinction que porte le composant lui-même, pas une couleur.
    $box = match ((string) $size) {
        '16' => 'size-[16px] rounded-[5px]',
        '18' => 'size-[18px] rounded-[6px]',
        default => 'size-[20px] rounded-[7px]',
    };

    if ($shape === 'round') {
        $box = preg_replace('/rounded-\[\d+px\]/', 'rounded-full', $box);
    }

    $border = match ($tone) {
        'late' => 'border-(--dgr)',
        'agent' => 'border-(--ai)',
        'current' => 'border-(--ac)',
        default => 'border-(--bd3)',
    };
@endphp

<label class="relative inline-flex shrink-0 cursor-pointer items-center justify-center {{ $box }}">
    <input
        type="checkbox"
        @if ($target)
            wire:target="{{ $target }}"
            wire:loading.attr="disabled"
        @endif
        {{ $attributes->merge(['class' => 'peer sr-only']) }}
    />
    <span class="pointer-events-none absolute inset-0 border-2 bg-transparent transition-[background-color,border-color,scale] duration-(--dur-swap) ease-(--ease) peer-active:scale-[.88] peer-checked:border-(--ai) peer-checked:bg-(--ai) peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-(--ac) {{ $border }} {{ $box }}"></span>
    <span class="pointer-events-none relative scale-50 text-[11px] leading-none text-(--aiT) opacity-0 transition-[opacity,scale] duration-(--dur-swap) ease-(--ease) peer-checked:scale-100 peer-checked:opacity-100">✓</span>
</label>
