import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            // Une seule famille de texte, Manrope, exactement comme la
            // maquette : elle y sert aux titres comme au corps, en 400 à 800.
            // Deux familles (Outfit pour les titres, Karla pour le reste) ne
            // rendaient pas le même écran. IBM Plex Mono reste pour les deux
            // seules zones qui gardent du mono : les chemins de la Mémoire et
            // les identifiants de run de l'écran Système.
            fonts: [
                bunny('Manrope', {
                    weights: [400, 500, 600, 700, 800],
                }),
                bunny('IBM Plex Mono', {
                    weights: [400, 500],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
