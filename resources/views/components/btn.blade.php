@props([
    'variant' => 'secondary', // primary | secondary | ghost | danger | agent
    'size' => 'md', // md (30px) | sm (28px)
    'href' => null,
    'loading' => false, // agent variant only: shows a pulsing dot
])

@php
    $base = 'inline-flex items-center justify-center gap-2 font-(family-name:--font-sans) transition-[background,border-color,opacity] duration-150 ease-out disabled:cursor-not-allowed disabled:pointer-events-none disabled:opacity-40 focus-visible:outline focus-visible:outline-1 focus-visible:outline-(--ac) focus-visible:outline-offset-2';

    $sizes = [
        'md' => 'rounded-[7px] px-[15px] py-[8px] text-[13px] leading-none',
        'sm' => 'rounded-[6px] px-[12px] py-[6px] text-[12.5px] leading-none',
    ];

    $variants = [
        'primary' => 'bg-(--ac) text-(--acT) font-semibold shadow-[inset_0_1px_0_rgba(255,255,255,.18),0_1px_2px_rgba(0,0,0,.4)] hover:bg-(--achov)',
        'secondary' => 'bg-(--surf2) border border-(--bd2) text-(--tx) font-medium hover:border-(--bd3)',
        'ghost' => 'bg-transparent text-(--mut) font-medium hover:bg-(--surf2) hover:text-(--tx)',
        'danger' => 'bg-transparent border border-(--dgr)/35 text-(--dgr) font-medium hover:bg-(--dgrbg)',
        'agent' => 'bg-(--aibg) border border-(--aibd) text-(--ai) font-medium hover:shadow-(--aiglow)',
    ];

    $classes = $base.' '.($sizes[$size] ?? $sizes['md']).' '.($variants[$variant] ?? $variants['secondary']);
    $showPulse = $loading && $variant === 'agent';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($showPulse)
            <span class="size-[5px] shrink-0 rounded-full bg-(--ai) animate-pulse-soft"></span>
        @endif
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $classes]) }}>
        @if ($showPulse)
            <span class="size-[5px] shrink-0 rounded-full bg-(--ai) animate-pulse-soft"></span>
        @endif
        {{ $slot }}
    </button>
@endif
