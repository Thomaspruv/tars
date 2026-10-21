<?php

use App\Support\Missions\ShellPath;

/**
 * Régression, trouvée en lançant pour de bon : depuis PHP-FPM, `npx` échouait
 * sur `spawn sh ENOENT`. Il trouvait node — on le lui donnait — mais pas le
 * shell dont il a besoin, parce que le PATH d'un serveur web ne contient pas
 * forcément `/bin`. Depuis un terminal, tout marchait.
 */
test('the system directories are always there, shell included', function () {
    $path = explode(':', ShellPath::env()['PATH']);

    expect($path)->toContain('/bin')
        ->and($path)->toContain('/usr/bin')
        ->and($path)->toContain('/usr/local/bin');
});

test('what the caller needs comes first', function () {
    $path = explode(':', ShellPath::env('/opt/node22/bin')['PATH']);

    expect($path[0])->toBe('/opt/node22/bin');
});

test('nothing is listed twice, and nothing empty is listed at all', function () {
    $path = explode(':', ShellPath::env('/bin', '', '  ')['PATH']);

    expect($path)->toBe(array_unique($path))
        ->and($path)->not->toContain('')
        ->and(array_filter($path, fn (string $directory): bool => trim($directory) === ''))->toBe([]);
});
