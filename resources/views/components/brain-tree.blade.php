@props(['tree', 'selectedPath' => null])

<ul class="space-y-0.5 text-sm">
    @foreach ($tree as $name => $value)
        <li>
            @if (is_array($value))
                <p class="mt-3 font-mono text-[10.5px] uppercase tracking-wide text-(--mut)">{{ $name }}</p>
                <x-brain-tree :tree="$value" :selected-path="$selectedPath" />
            @else
                <button
                    type="button"
                    wire:click="selectDocument('{{ $value }}')"
                    class="block w-full truncate rounded-[6px] px-2 py-1 text-left {{ $selectedPath === $value ? 'bg-(--acbg) text-(--ac)' : 'text-(--mut) hover:bg-(--surf2) hover:text-(--tx)' }}"
                >
                    {{ $name }}
                </button>
            @endif
        </li>
    @endforeach
</ul>
