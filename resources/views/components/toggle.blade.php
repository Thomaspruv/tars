@props(['agent' => false])

<label class="inline-flex cursor-pointer items-center">
    <input type="checkbox" {{ $attributes->merge(['class' => 'peer sr-only']) }} />
    <span class="relative h-[19px] w-[34px] shrink-0 rounded-full bg-(--bd2) transition-colors duration-150 ease-out peer-focus-visible:outline peer-focus-visible:outline-1 peer-focus-visible:outline-(--ac) peer-focus-visible:outline-offset-2 {{ $agent ? 'peer-checked:bg-(--ai)' : 'peer-checked:bg-(--ac)' }}">
        <span class="absolute top-[2px] left-[2px] size-[15px] rounded-full bg-(--bg) transition-transform duration-150 ease-out peer-checked:translate-x-[15px]"></span>
    </span>
</label>
