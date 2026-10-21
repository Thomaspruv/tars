@props(['placeholder' => 'Écris…', 'autofocus' => false])

{{-- La zone d'écriture : une pilule, le bouton dedans, et Entrée qui envoie —
     Maj+Entrée va à la ligne. Elle grandit avec le texte jusqu'à une limite,
     puis défile. --}}
<form wire:submit="send">
    <div
        x-data="{
            grow($el) {
                $el.style.height = 'auto';
                $el.style.height = Math.min($el.scrollHeight, 220) + 'px';
            },
        }"
        class="flex items-end gap-2 rounded-[24px] bg-(--surf2) p-2 pl-5 focus-within:ring-1 focus-within:ring-(--bd2)"
    >
        <textarea
            wire:model="message"
            rows="1"
            @if ($autofocus) autofocus @endif
            placeholder="{{ $placeholder }}"
            x-on:input="grow($el)"
            x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send() }"
            x-effect="$wire.message === '' && (($el.value = ''), grow($el))"
            class="max-h-[220px] min-h-[24px] flex-1 resize-none self-center bg-transparent py-2 text-[15px] leading-[1.6] text-(--tx) placeholder:text-(--mut) focus:outline-none"
        ></textarea>

        <button
            type="submit"
            aria-label="Envoyer"
            wire:loading.attr="disabled"
            class="press flex size-[38px] shrink-0 items-center justify-center rounded-full bg-(--ac) text-[18px] font-bold text-(--acT) transition-[background-color,opacity] hover:bg-(--achov) disabled:opacity-40"
        >
            <span aria-hidden="true">↑</span>
        </button>
    </div>

    @error('message')<p class="mt-2 px-2 text-[12px] text-(--warn)">{{ $message }}</p>@enderror
</form>
