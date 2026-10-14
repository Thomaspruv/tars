/**
 * Deux choses seulement, toutes deux au service de la capture :
 * l'enregistrement du service worker, et l'envoi des notes prises hors ligne.
 */

const QUEUE_KEY = 'tars.pending-captures';

function readQueue() {
    try {
        const parsed = JSON.parse(window.localStorage.getItem(QUEUE_KEY) ?? '[]');

        return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
        return [];
    }
}

/**
 * On retire par identifiant, jamais en vidant la file : une note peut avoir été
 * ajoutée pendant que l'envoi était en vol.
 */
function forgetQueued(sentIds) {
    const remaining = readQueue().filter((item) => !sentIds.includes(item.id));

    try {
        window.localStorage.setItem(QUEUE_KEY, JSON.stringify(remaining));
    } catch (error) {
        // Rien à faire : au pire les notes repartiront au prochain envoi.
    }
}

async function flushQueue() {
    const items = readQueue().filter((item) => typeof item?.content === 'string' && item.content.trim() !== '');

    if (items.length === 0 || !navigator.onLine) {
        return;
    }

    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    if (!token) {
        return;
    }

    try {
        const response = await fetch('/inbox/file-attente', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ items: items.map((item) => ({ content: item.content })) }),
        });

        if (response.ok) {
            forgetQueued(items.map((item) => item.id));
        }
    } catch (error) {
        // Hors ligne ou session expirée : la file reste intacte pour plus tard.
    }
}

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // Échoue silencieusement en contexte non sécurisé (http:// local) :
        // le service worker n'existe qu'en HTTPS, l'app fonctionne sans lui.
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

window.addEventListener('load', flushQueue);
window.addEventListener('online', flushQueue);
