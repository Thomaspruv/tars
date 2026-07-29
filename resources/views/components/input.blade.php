@props(['variant' => 'default']) {{-- default | technical --}}

@php
    $classes = match ($variant) {
        'technical' => 'rounded-[8px] border border-(--bd2) bg-(--in) px-[10px] py-[6px] font-mono text-[11px] text-(--tx) placeholder:text-(--mut) focus:border-(--ac) focus:outline-none',
        default => 'rounded-[9px] border border-(--bd2) bg-(--in) px-3 py-2 text-[13px] text-(--tx) placeholder:text-(--mut) focus:border-(--ac) focus:outline-none',
    };
@endphp

<input {{ $attributes->merge(['class' => $classes]) }} />
