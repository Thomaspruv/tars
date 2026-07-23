<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="!p-0">
        <x-topbar />

        <div class="mx-auto w-full max-w-[1080px] px-6 py-8 md:px-10">
            {{ $slot }}
        </div>
    </flux:main>
</x-layouts::app.sidebar>
