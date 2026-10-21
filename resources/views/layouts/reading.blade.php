{{-- Registre « lecture » — Revue, Bilan de vie, Mémoire.
     Ces trois écrans remplacent la sidebar de l'app par leur propre colonne de
     contexte (historique des revues, liste des bilans, arborescence du vault)
     et posent le contenu dans une colonne de lecture. Ils sont donc rendus
     sans le chrome de navigation habituel : c'est délibéré, et c'est ce qui
     fait qu'on les lit au lieu de les parcourir. --}}
<x-layouts::app.chrome :title="$title ?? null">
    <main class="min-h-screen pb-32 lg:pb-0">
        <x-mobile-header />

        {{ $slot }}
    </main>
</x-layouts::app.chrome>
