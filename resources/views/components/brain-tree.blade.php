@props(['tree', 'selectedPath' => null, 'depth' => 0])

{{-- Le Cerveau est l'une des deux seules zones de l'app à garder du mono : un
     chemin de fichier n'est pas de la prose, et l'alignement des extensions
     aide vraiment à s'y retrouver. --}}
<div class="flex flex-col gap-px font-mono text-[12px]">
    @foreach ($tree as $name => $value)
        @if (is_array($value))
            <div class="rounded-[8px] px-3 py-[7px] text-(--mut)" style="padding-left: {{ 12 + $depth * 12 }}px">{{ $name }}/</div>
            <x-brain-tree :tree="$value" :selected-path="$selectedPath" :depth="$depth + 1" />
        @else
            <button
                type="button"
                wire:click="selectDocument('{{ $value }}')"
                class="press block w-full truncate rounded-[8px] py-[7px] pr-3 text-left {{ $selectedPath === $value ? 'bg-(--surf2) text-(--tx)' : 'text-(--mut) hover:bg-(--surf) hover:text-(--tx)' }}"
                style="padding-left: {{ 12 + $depth * 12 }}px"
            >{{ $name }}</button>
        @endif
    @endforeach
</div>
