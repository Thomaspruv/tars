@props([
    'padding' => '20',
    'tone' => 'default', // default | agent | sunk | dashed
])

@php
    $paddingClasses = match ((string) $padding) {
        '0' => '',
        '14' => 'px-[16px] py-[14px]',
        '16' => 'px-[18px] py-[16px]',
        '18' => 'px-[20px] py-[18px]',
        '22' => 'px-[24px] py-[22px]',
        default => 'px-[22px] py-[20px]',
    };

    $tones = [
        'default' => 'bg-(--surf)',
        // Bloc d'agent : fond vert sombre. Le filet vert de 4px se pose avec
        // <x-agent-block>, qui compose au-dessus de cette carte.
        'agent' => 'bg-(--aibg)',
        // Creux : ce qui est déjà traité, archivé, ou hors du mois courant.
        'sunk' => 'bg-(--sunk)',
        'dashed' => 'border-2 border-dashed border-(--bd2)',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'rounded-[18px] '.($tones[$tone] ?? $tones['default']).' '.$paddingClasses]) }}>
    {{ $slot }}
</div>
