@props(['variant' => 'default']) {{-- default | technical --}}

@php
    $classes = match ($variant) {
        'technical' => 'rounded-full bg-(--surf2) px-4 py-2.5 font-mono text-[13px] text-(--tx) focus:outline-none',
        default => 'rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none',
    };
@endphp

<select {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</select>
