<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ config('app.name', 'Laravel') }}</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">

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

<script data-navigate-once>
    document.addEventListener('keydown', function (event) {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            var input = document.getElementById('quick-add-input');

            if (input) {
                event.preventDefault();
                input.focus();
            }
        }
    });
</script>
