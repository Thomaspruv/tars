<x-layouts::app.chrome :title="$title ?? null">
    {{-- La coque prend toute la largeur de l'écran : sur un 2560, on veut la
         place, pas une colonne centrée avec deux marges vides. La barre
         latérale est `sticky` plutôt que `fixed` — elle reste dans le flux,
         donc le contenu occupe exactement ce qui reste, sans marge calculée à
         la main. --}}
    <div class="flex w-full">
        <x-layouts::app.sidebar />

        <main class="min-h-screen min-w-0 flex-1 pb-32 lg:pb-10">
            <x-mobile-header />

            <div class="px-5 pt-4 lg:px-[34px] lg:pt-[26px] lg:pl-[14px]">
                {{ $slot }}
            </div>
        </main>
    </div>
</x-layouts::app.chrome>
