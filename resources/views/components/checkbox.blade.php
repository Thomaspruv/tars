@props(['shape' => 'square']) {{-- square (tâche) | round (liste) --}}

<label class="relative inline-flex size-[16px] shrink-0 cursor-pointer items-center justify-center">
    <input type="checkbox" {{ $attributes->merge(['class' => 'peer sr-only']) }} />
    <span class="pointer-events-none absolute inset-0 border border-(--bd2) bg-transparent transition-colors duration-150 ease-out peer-checked:border-(--ac) peer-checked:bg-(--ac) peer-focus-visible:outline peer-focus-visible:outline-1 peer-focus-visible:outline-(--ac) peer-focus-visible:outline-offset-2 {{ $shape === 'round' ? 'rounded-full' : 'rounded-[5px]' }}"></span>
    <span class="pointer-events-none relative text-[10px] leading-none text-(--acT) opacity-0 peer-checked:opacity-100">✓</span>
</label>
