<div class="flex items-center gap-4 border-b border-(--bd) bg-(--surf) px-6 py-3 md:px-10">
    <livewire:quick-add />

    <flux:spacer />

    <time class="font-mono text-xs text-(--mut)" datetime="{{ now()->toDateString() }}">
        {{ ucfirst(now()->translatedFormat('l j F')) }}
    </time>
</div>
