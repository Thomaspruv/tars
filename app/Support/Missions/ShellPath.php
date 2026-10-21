<?php

namespace App\Support\Missions;

/**
 * Le PATH donné aux outils externes qu'on lance.
 *
 * Le PATH d'un processus PHP-FPM n'est pas celui d'un terminal : il peut ne
 * contenir ni `/bin` ni `/usr/bin`. Un `npx` lancé là-dedans échoue sur
 * `spawn sh ENOENT` — il trouve bien node, mais pas le shell dont il a besoin
 * pour travailler. On remet donc les dossiers système en toutes lettres, en
 * plus de ceux que l'appelant exige.
 */
final class ShellPath
{
    private const array STANDARD = ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin', '/usr/sbin'];

    /**
     * @return array<string, string>
     */
    public static function env(string ...$directories): array
    {
        $inherited = explode(':', (string) getenv('PATH'));

        $path = array_values(array_unique(array_filter([
            ...$directories,
            ...self::STANDARD,
            ...$inherited,
        ], fn (string $directory): bool => trim($directory) !== '')));

        return ['PATH' => implode(':', $path)];
    }
}
