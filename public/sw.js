/**
 * Service worker de Tars — délibérément minimal.
 *
 * Il ne met rien en cache de l'application elle-même : les pages sont rendues
 * par le serveur et dépendent de la session, et Livewire fait ses échanges en
 * POST. Tout ce qu'il fait, c'est rattraper une navigation qui échoue faute de
 * réseau et servir à la place une page de capture hors-ligne, pour que la
 * saisie ne soit jamais bloquée — c'est la seule promesse du système.
 */

const CACHE = 'tars-shell-v1';
const OFFLINE_URL = '/hors-ligne.html';
const PRECACHE = [OFFLINE_URL, '/icons/icon-192.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // On ne touche qu'aux navigations : les requêtes Livewire, les assets Vite
    // et tout le reste passent au réseau sans interception.
    if (request.method !== 'GET' || request.mode !== 'navigate') {
        return;
    }

    event.respondWith(
        fetch(request).catch(async () => {
            const cached = await caches.match(OFFLINE_URL);

            return (
                cached ??
                new Response('Hors ligne.', {
                    status: 503,
                    headers: { 'Content-Type': 'text/plain; charset=utf-8' },
                })
            );
        }),
    );
});
