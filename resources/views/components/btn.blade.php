@props([
    'variant' => 'secondary', // primary | agent | secondary | ghost | danger
    'size' => 'sm', // xs | sm | md | lg
    'href' => null,
    'loading' => false, // agent variant only: shows a pulsing dot
])

@php
    // Tout est une pilule (999px). Les deux variantes pleines portent une ombre
    // *dure* de 2px et s'enfoncent au clic — c'est la seule profondeur du
    // système, il n'y a aucune ombre floue nulle part.
    $base = 'inline-flex items-center justify-center gap-2 rounded-full font-(family-name:--font-sans) transition-[background-color,box-shadow,scale,translate,color] duration-(--dur-tap) ease-(--ease) disabled:cursor-not-allowed disabled:pointer-events-none disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ac)';

    $sizes = [
        'xs' => 'px-[14px] py-[7px] text-[12px]',
        'sm' => 'px-[18px] py-[9px] text-[13px]',
        'md' => 'px-[22px] py-[11px] text-[14px]',
        'lg' => 'px-[28px] py-[13px] text-[15px]',
    ];

    // Les deux variantes pleines s'enfoncent vraiment : l'ombre dure de 2px
    // disparaît sous le doigt et le bouton descend d'exactement sa hauteur.
    $pressable = 'shadow-[0_2px_0_var(--sh)] active:translate-y-[2px] active:shadow-none';

    // Les variantes plates n'ont pas d'ombre à écraser : elles se tassent.
    $flatPress = 'active:scale-[.97]';

    $variants = [
        // L'abricot est *mon* action. L'agent n'y touche jamais.
        'primary' => 'bg-(--ac) text-(--acT) font-bold hover:bg-(--achov) [--sh:var(--acsh)] '.$pressable,
        // Le vert est celui de l'agent — et aussi celui du « fait ».
        'agent' => 'bg-(--ai) text-(--aiT) font-bold hover:bg-(--aihov) [--sh:var(--aish)] '.$pressable,
        'secondary' => 'bg-(--surf2) text-(--tx) font-bold hover:bg-(--bd2) '.$flatPress,
        'ghost' => 'bg-transparent text-(--mut) font-semibold hover:text-(--tx) '.$flatPress,
        'danger' => 'bg-transparent text-(--dgr) font-semibold hover:bg-(--dgrbg) '.$flatPress,
    ];

    $classes = $base.' '.($sizes[$size] ?? $sizes['sm']).' '.($variants[$variant] ?? $variants['secondary']);
    $showPulse = $loading && $variant === 'agent';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($showPulse)
            <span class="size-[6px] shrink-0 animate-pulse-soft rounded-full bg-(--aiT)"></span>
        @endif
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $classes]) }}>
        @if ($showPulse)
            <span class="size-[6px] shrink-0 animate-pulse-soft rounded-full bg-(--aiT)"></span>
        @endif
        {{ $slot }}
    </button>
@endif
