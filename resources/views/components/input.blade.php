@props(['variant' => 'default']) {{-- default | technical (chemins, identifiants : le mono survit ici) --}}

@php
    $classes = match ($variant) {
        'technical' => 'rounded-full bg-(--surf2) px-4 py-2.5 font-mono text-[13px] text-(--tx) placeholder:text-(--mut) focus:outline-none',
        default => 'rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) placeholder:text-(--mut) focus:outline-none',
    };
@endphp

<input {{ $attributes->merge(['class' => $classes]) }} />
