<x-layouts::app.chrome :title="$title ?? null">
    {{-- La coque entière se centre : barre latérale *et* contenu, pas l'un sans
         l'autre. 1432px, c'est l'artboard des maquettes (240 de barre latérale
         + 1200 de contenu). Sur un écran de 2560, centrer le seul contenu
         laissait la barre latérale orpheline à l'extrême gauche et l'app
         paraissait dézoomée ; sur un portable de 1512 le plafond ne mord
         presque pas. --}}
    <div class="mx-auto flex w-full max-w-[1432px]">
        <x-layouts::app.sidebar />

        <main class="min-h-screen min-w-0 flex-1 pb-32 lg:pb-10">
            <x-mobile-header />

            <div class="px-5 pt-4 lg:px-[34px] lg:pt-[26px] lg:pl-[14px]">
                {{ $slot }}
            </div>
        </main>
    </div>
</x-layouts::app.chrome>
