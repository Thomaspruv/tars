@props([
    'treatment' => 'work', // work (T1) | record (T2) | ai (T3)
    'kicker' => null,
    'title',
    'subtitle' => null,
    'back' => null, // T2 only: ['label' => ..., 'href' => ...]
])

@php
    $titleClasses = match ($treatment) {
        'record' => 'text-[27px] font-semibold tracking-[-.03em] text-(--tx)',
        default => 'text-[29px] font-semibold tracking-[-.03em] text-(--tx)',
    };
@endphp

<div>
    @if ($treatment === 'record' && $back)
        <a href="{{ $back['href'] }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs text-(--mut) hover:text-(--tx)">
            <span aria-hidden="true">←</span> {{ $back['label'] }}
        </a>
    @endif

    @if ($treatment === 'work' && $kicker)
        <p class="flex items-center gap-1.5 font-mono text-[10.5px] font-medium tracking-[.1em] text-(--ac) uppercase">
            <span class="size-[5px] rounded-full bg-(--ac)"></span>{{ $kicker }}
        </p>
    @elseif ($treatment === 'ai' && $kicker)
        <p class="flex items-center gap-1.5 font-mono text-[10.5px] font-medium tracking-[.1em] text-(--ai) uppercase">
            <span class="size-[5px] rounded-full bg-(--ai) animate-pulse-soft"></span>◈ {{ $kicker }}
        </p>
    @endif

    <div class="{{ $treatment === 'record' ? 'mt-2 flex items-start justify-between gap-4' : 'mt-1 flex items-start justify-between gap-4' }}">
        <div class="flex items-center gap-3">
            <h1 {{ $attributes->merge(['class' => $titleClasses]) }} style="text-wrap: balance">{{ $title }}</h1>
            {{ $slot ?? '' }}
        </div>

        @isset($actions)
            <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>

    @if ($subtitle)
        <p class="mt-1 text-[13.5px] text-(--mut)">{{ $subtitle }}</p>
    @endif

    <div class="{{ $treatment === 'ai' ? 'mt-4 h-0.5' : 'mt-4 h-px' }} w-full" style="background: {{ $treatment === 'ai' ? 'linear-gradient(90deg, var(--gradient-signature), transparent)' : 'var(--bd)' }}"></div>
</div>
