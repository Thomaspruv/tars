@props(['goal' => null, 'tag' => null])

@php
    $label = $tag ?? $goal?->tag;
@endphp

@if ($label)
    @if ($goal)
        <a
            href="{{ route('goals.show', $goal) }}"
            wire:navigate
            {{ $attributes->merge(['class' => 'inline-flex items-center rounded-[5px] border border-(--ac)/35 px-1.5 py-0.5 text-[11px] text-(--ac) hover:border-(--ac)']) }}
        >{{ '#'.$label }}</a>
    @else
        <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-[5px] border border-(--ac)/35 px-1.5 py-0.5 text-[11px] text-(--ac)']) }}>{{ '#'.$label }}</span>
    @endif
@endif
