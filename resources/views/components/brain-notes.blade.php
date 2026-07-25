@props(['documents'])

<div>
    <h2 class="text-base font-semibold text-(--tx)">Notes du vault</h2>
    <div class="mt-3 space-y-2">
        @forelse ($documents as $document)
            <div class="rounded-[10px] border border-(--bd) bg-(--surf) p-3" wire:key="brain-doc-{{ $document->id }}">
                <div class="flex items-center gap-2">
                    @if ($type = $document->frontmatter['type'] ?? null)
                        <span class="font-mono text-[10px] font-semibold uppercase text-(--mut)">{{ $type }}</span>
                    @endif
                    <span class="text-sm font-medium text-(--tx)">{{ $document->title }}</span>
                </div>
                <p class="mt-1 text-sm text-(--mut)">{{ Str::limit($document->content, 140) }}</p>
                @if ($document->mtime)
                    <p class="mt-1 font-mono text-[10.5px] text-(--mut)">{{ $document->mtime->translatedFormat('d M · H:i') }}</p>
                @endif
            </div>
        @empty
            <p class="rounded-[14px] border border-(--bd) bg-(--surf) p-5 text-center text-sm text-(--mut)">Aucune note du vault.</p>
        @endforelse
    </div>
</div>
