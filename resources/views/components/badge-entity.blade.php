@props(['entity' => null, 'name' => null])

@php
    $label = $name ?? $entity?->name;
@endphp

@if ($label)
    @if ($entity)
        <a
            href="{{ route('entities.show', $entity) }}"
            wire:navigate
            {{ $attributes->merge(['class' => 'inline-flex items-center rounded-[5px] border border-(--bd2) px-1.5 py-0.5 text-[11px] text-(--mut) hover:text-(--tx)']) }}
        >{{ '@'.$label }}</a>
    @else
        <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-[5px] border border-(--bd2) px-1.5 py-0.5 text-[11px] text-(--mut)']) }}>{{ '@'.$label }}</span>
    @endif
@endif
