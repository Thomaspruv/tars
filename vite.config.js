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
            // Les trois familles du design system : Outfit pour les titres et
            // les chiffres, Karla pour tout le reste, IBM Plex Mono pour les
            // deux seules zones qui gardent du mono (chemins du Cerveau,
            // identifiants de run sur l'écran Agents).
            fonts: [
                bunny('Outfit', {
                    // 800 pour les titres d'écran et les chiffres : la maquette
                    // les pose là, et sans le fichier la classe retombe
                    // silencieusement sur 700.
                    weights: [400, 500, 600, 700, 800],
                }),
                bunny('Karla', {
                    weights: [400, 500, 600, 700],
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
