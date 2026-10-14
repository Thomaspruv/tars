<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>{{ config('app.name', 'Laravel') }}</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

{{-- L'app s'installe : le manifeste porte l'icône, les raccourcis et la cible
     de partage (« Partager vers Tars » depuis n'importe quelle app Android,
     et depuis un raccourci iOS qui ouvre /partager). --}}
<link rel="manifest" href="/manifest.webmanifest">
{{-- La barre du navigateur suit le thème de l'app, pas celui du système :
     c'est le cookie `la-theme` qui fait foi partout ailleurs. --}}
<meta name="theme-color" content="{{ request()->cookie('la-theme') === 'light' ? '#F3F5EE' : '#0F1310' }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
{{-- `black` plutôt que `black-translucent` : le translucide fait passer le
     contenu *sous* l'horloge, ce qui masquerait l'en-tête mobile tant qu'on
     n'a pas repris toutes les marges en safe-area. --}}
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="apple-mobile-web-app-title" content="Tars">

{{-- Les polices sont auto-hébergées par le plugin `fonts` de laravel-vite
     (voir vite.config.js) : aucun appel à un CDN de police au runtime. --}}
@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])

<script>
    (function () {
        // The theme now lives in a cookie so the server can render the right
        // data-theme/class on <html> directly — this is what stops wire:navigate's
        // View Transition snapshot from ever showing a dark flash before a client
        // script gets a chance to fix it. This block only migrates leftover
        // localStorage state from before that change; it has nothing to do once
        // the cookie exists.
        if (!/(?:^|; )la-theme=/.test(document.cookie)) {
            var legacy = window.localStorage.getItem('la-theme');

            if (legacy === 'light' || legacy === 'dark') {
                document.cookie = 'la-theme=' + legacy + '; path=/; max-age=31536000; SameSite=Lax';
                document.documentElement.dataset.theme = legacy;
                document.documentElement.classList.toggle('dark', legacy === 'dark');
            }
        }
    })();
</script>

