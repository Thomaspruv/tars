{{-- Le thème est écrit dans un cookie (pas localStorage) pour que le serveur
     puisse rendre le bon `data-theme` sur <html> dès la réponse, et donc que
     les transitions wire:navigate n'affichent jamais un flash de thème. --}}
<button
    type="button"
    x-data
    x-on:click="
        let next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        document.documentElement.classList.toggle('dark', next === 'dark');
        document.cookie = 'la-theme=' + next + '; path=/; max-age=31536000; SameSite=Lax';
    "
    {{ $attributes->merge(['class' => 'transition-colors']) }}
>
    Thème
    <span class="sr-only" data-theme-icon="sun">— actuellement clair</span>
    <span class="sr-only" data-theme-icon="moon">— actuellement sombre</span>
</button>
