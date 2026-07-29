@props(['padding' => '18'])

@php
    $paddingClasses = match ((string) $padding) {
        '20' => 'p-[20px]',
        '22' => 'p-[22px]',
        default => 'p-[18px]',
    };
@endphp

<div {{ $attributes->merge(['class' => 'rounded-[12px] border border-(--bd) bg-(--surf) shadow-(--card-shadow) '.$paddingClasses]) }}>
    {{ $slot }}
</div>
