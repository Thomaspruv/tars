<?php

namespace App\Support\Navigation;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Quelle page on regarde — y compris au milieu d'un rendu Livewire.
 *
 * `request()->routeIs()` répond juste au chargement d'une page et faux partout
 * ailleurs : un rendu Livewire (un clic, une validation, un `wire:poll`) arrive
 * sur `livewire.update`, donc aucune route de page ne « matche » plus. Un
 * composant qui s'en servait pour s'allumer s'éteignait au premier rafraîchis-
 * sement — et une barre d'onglets conditionnée là-dessus disparaissait purement
 * et simplement de la page.
 *
 * Livewire garde le chemin d'origine dans l'instantané du composant : on le
 * repasse au routeur pour retrouver le nom de la page.
 */
final class CurrentRoute
{
    private const MEMO = 'nav.current-route';

    public static function name(): ?string
    {
        $request = request();

        if (! $request->attributes->has(self::MEMO)) {
            $request->attributes->set(self::MEMO, self::resolve());
        }

        /** @var string|null */
        return $request->attributes->get(self::MEMO);
    }

    /**
     * Mêmes motifs que `request()->routeIs()`, étoile comprise.
     */
    public static function is(string ...$names): bool
    {
        $current = self::name();

        if ($current === null) {
            return false;
        }

        foreach ($names as $name) {
            if (Str::is($name, $current)) {
                return true;
            }
        }

        return false;
    }

    private static function resolve(): ?string
    {
        $name = request()->route()?->getName();

        if ($name !== null && ! str_starts_with($name, 'livewire.')) {
            return $name;
        }

        return self::fromLivewireOrigin();
    }

    private static function fromLivewireOrigin(): ?string
    {
        try {
            return Route::getRoutes()
                ->match(Request::create(Livewire::originalUrl()))
                ->getName();
        } catch (\Throwable) {
            // Hors d'une vraie requête Livewire (le banc de test, une commande
            // console), il n'y a pas de page d'origine à retrouver.
            return null;
        }
    }
}
