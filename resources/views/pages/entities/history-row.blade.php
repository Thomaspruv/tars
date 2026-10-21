{{-- Une ligne d'historique : l'heure à gauche, la case si c'est une tâche (elle
     se coche d'ici), le texte, et le type à droite. Trois sortes d'objets, une
     seule colonne — c'est ce qui rend la relation lisible. --}}
<div class="flex items-start gap-3.5 border-b border-(--bd) px-[18px] py-3 text-[14px] last:border-b-0" wire:key="histo-{{ $key }}">
    <span class="w-[64px] shrink-0 pt-0.5 text-[12px] font-semibold text-(--mut)">{{ $this->stamp($line['at']) }}</span>

    @if ($line['task'])
        <button
            type="button"
            wire:click="toggleTask({{ $line['task']->id }})"
            wire:target="toggleTask({{ $line['task']->id }})"
            wire:loading.attr="disabled"
            wire:loading.class="scale-90 opacity-60"
            aria-label="{{ $line['done'] ? 'Marquer non terminée' : 'Marquer terminée' }}"
            class="mt-0.5 flex size-[18px] shrink-0 items-center justify-center rounded-[5px] border-2 transition-[background-color,border-color,scale] duration-(--dur-swap) ease-(--ease) active:scale-[.88] {{ $line['done'] ? 'border-(--ai) bg-(--ai)' : 'border-(--bd3)' }}"
        >
            @if ($line['done'])<span aria-hidden="true" class="animate-tick-in text-[10px] leading-none text-(--aiT)">✓</span>@endif
        </button>
    @else
        <span class="mt-[7px] size-[6px] shrink-0 rounded-full bg-(--bd3)" aria-hidden="true"></span>
    @endif

    <div class="min-w-0 flex-1">
        <p class="{{ $line['done'] ? 'text-(--mut) line-through opacity-60' : 'text-(--tx)' }}">{{ $line['text'] }}</p>
        @if ($line['meta'])
            <p class="mt-0.5 text-[12px] {{ $line['meta'] === 'écrite par Hermès' ? 'text-(--ai)' : 'text-(--mut)' }}">{{ $line['meta'] }}</p>
        @endif
    </div>

    <span class="shrink-0 text-[12px] text-(--mut)">{{ $line['kind'] }}</span>
</div>
