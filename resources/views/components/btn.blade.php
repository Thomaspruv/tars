@props([
    'variant' => 'secondary',
    'href' => null,
    'pulse' => false,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-[9px] px-[18px] py-[9px] text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-55';

    $variants = [
        'primary' => 'bg-(--ac) text-(--acT) hover:bg-(--achov) hover:shadow-(--glow)',
        'secondary' => 'border border-(--bd2) bg-transparent text-(--tx) hover:border-(--ac) hover:text-(--ac)',
        'ghost' => 'bg-transparent text-(--mut) hover:bg-(--surf2) hover:text-(--tx)',
        'danger' => 'border border-(--dgr)/40 bg-transparent text-(--dgr) hover:bg-(--dgrbg)',
        'ai' => 'border border-(--ai)/45 bg-(--aibg) text-(--ai) hover:shadow-(--aiglow)',
    ];

    $classes = $base.' '.($variants[$variant] ?? $variants['secondary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($pulse)
            <span class="size-1.5 rounded-full bg-(--ai) animate-pulse-soft"></span>
        @endif
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $classes]) }}>
        @if ($pulse)
            <span class="size-1.5 rounded-full bg-(--ai) animate-pulse-soft"></span>
        @endif
        {{ $slot }}
    </button>
@endif
