@props(['documents'])

@if ($documents->isNotEmpty())
    <p class="mb-3.5 text-[11px] tracking-[.12em] text-(--mut) uppercase">Dans la Mémoire</p>
    <div class="mb-[30px] flex flex-col gap-2.5">
        @foreach ($documents as $document)
            <a
                href="{{ route('brain.index', ['path' => $document->path]) }}"
                wire:navigate
                class="press block rounded-[12px] bg-(--surf) px-3.5 py-2.5 font-mono text-[13px] text-(--tx) hover:bg-(--surf2)"
                wire:key="brain-doc-{{ $document->id }}"
            >{{ $document->path }}</a>
        @endforeach
    </div>
@endif
