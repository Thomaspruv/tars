<?php

use Illuminate\Support\Facades\File;

/**
 * Garde-fous de la couche de mouvement.
 *
 * Tailwind v4 n'accepte la syntaxe « variable CSS » qu'entre parenthèses :
 * `duration-(--dur-swap)`. Écrit entre crochets, il compile sans broncher vers
 * `transition-duration: --dur-swap`, une déclaration invalide que le navigateur
 * jette en silence — l'animation disparaît sans le moindre message. Ces tests
 * existent parce que le piège est passé une fois.
 */
function bladeSources(): array
{
    return collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.blade.php'))
        ->mapWithKeys(fn ($file): array => [
            str_replace(base_path().'/', '', $file->getPathname()) => $file->getContents(),
        ])
        ->all();
}

test('aucune classe utilitaire ne référence une variable CSS entre crochets', function () {
    $offenders = [];

    foreach (bladeSources() as $path => $contents) {
        preg_match_all('/\b(?:duration|ease|delay)-\[--[a-z-]+\]/', $contents, $matches);

        foreach ($matches[0] as $match) {
            $offenders[] = "{$path} : {$match}";
        }
    }

    expect($offenders)->toBe([], implode("\n", $offenders));
});

test('les listes de transition nomment les propriétés que Tailwind anime vraiment', function () {
    // Tailwind v4 écrit dans `scale`, `translate` et `rotate`, jamais dans
    // `transform` : une transition qui nomme `transform` n'anime donc rien.
    $offenders = [];

    foreach (bladeSources() as $path => $contents) {
        preg_match_all('/transition-\[[^\]]*transform[^\]]*\]/', $contents, $matches);

        foreach ($matches[0] as $match) {
            $offenders[] = "{$path} : {$match}";
        }
    }

    expect($offenders)->toBe([], implode("\n", $offenders));
});

test('les trois durées et la courbe du système sont définies', function () {
    $css = File::get(resource_path('css/app.css'));

    expect($css)->toContain('--dur-tap:')
        ->and($css)->toContain('--dur-swap:')
        ->and($css)->toContain('--dur-enter:')
        ->and($css)->toContain('--ease:');
});

test('le mouvement se coupe sous prefers-reduced-motion', function () {
    $css = File::get(resource_path('css/app.css'));

    expect($css)->toContain('@media (prefers-reduced-motion: reduce)')
        ->and($css)->toContain('animation-duration: .01ms !important')
        ->and($css)->toContain('transition-duration: .01ms !important');
});
