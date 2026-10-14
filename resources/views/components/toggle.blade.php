@props(['size' => 'md']) {{-- md (44×24) | sm (40×22) --}}

@php
    $track = $size === 'sm' ? 'h-[22px] w-[40px]' : 'h-[24px] w-[44px]';
    $knob = $size === 'sm' ? 'size-[16px]' : 'size-[18px]';
    $travel = $size === 'sm' ? 'peer-checked:translate-x-[18px]' : 'peer-checked:translate-x-[20px]';
@endphp

{{-- Un interrupteur allume toujours du vert : ce qu'il active, c'est un agent
     ou un automatisme, jamais mon action. --}}
<label class="inline-flex cursor-pointer items-center">
    <input type="checkbox" {{ $attributes->merge(['class' => 'peer sr-only']) }} />
    <span class="relative shrink-0 rounded-full bg-(--bd2) transition-colors duration-(--dur-swap) ease-(--ease) peer-active:scale-[.96] peer-checked:bg-(--ai) peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-(--ac) {{ $track }}">
        <span class="absolute top-[3px] left-[3px] rounded-full bg-(--mut) transition-[translate,background-color] duration-(--dur-swap) ease-(--ease) peer-checked:bg-(--bg) {{ $knob }} {{ $travel }}"></span>
    </span>
</label>
