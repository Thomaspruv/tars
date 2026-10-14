<x-layouts::app.chrome :title="$title ?? null">
    <x-layouts::app.sidebar />

    <main class="min-h-screen pb-32 lg:ml-[232px] lg:pb-10">
        <x-mobile-header />

        <div class="px-5 pt-4 lg:px-[34px] lg:pt-[26px] lg:pl-[14px]">
            {{ $slot }}
        </div>
    </main>
</x-layouts::app.chrome>
