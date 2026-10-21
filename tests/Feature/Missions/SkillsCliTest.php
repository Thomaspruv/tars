<?php

use App\Support\Missions\SkillsCli;
use Illuminate\Support\Facades\Process;

/**
 * Le CLI de skills.sh écrit pour un terminal. Recopié tel quel dans une page
 * web, ça donne une bouillie de `[?25l` et de `[1G` — vu en vrai sur l'écran
 * Skills après une installation.
 */
test('terminal control codes never reach the page', function () {
    Process::fake(['*' => Process::result(
        output: "\e[32m✓\e[0m Installé\e[?25l\e[1G\e[J\nSource: github.com/kepano/obsidian-skills",
    )]);

    $result = (new SkillsCli)->run(['add', 'kepano/obsidian-skills']);

    expect($result['output'])->toBe("✓ Installé\nSource: github.com/kepano/obsidian-skills")
        ->and($result['output'])->not->toContain("\e");
});

test('a line rewritten by a spinner is kept only in its final state', function () {
    Process::fake(['*' => Process::result(output: "Cloning repository…\rCloning repository……\rRepository cloned\nFini")]);

    expect((new SkillsCli)->run(['add', 'a/b'])['output'])->toBe("Repository cloned\nFini");
});

test('the command runs from the project, never from the web root', function () {
    Process::fake(['*' => Process::result(output: 'ok')]);

    (new SkillsCli)->run(['list']);

    // Sous PHP-FPM le répertoire courant est `public/` : on n'y lance pas un
    // outil qui sait installer des fichiers.
    Process::assertRan(fn ($process) => $process->path === base_path());
});

test('a failing command is reported, not swallowed', function () {
    Process::fake(['*' => Process::result(output: 'npm error enoent spawn sh', exitCode: 254)]);

    $result = (new SkillsCli)->run(['use', 'a/b@c']);

    expect($result['ok'])->toBeFalse()
        ->and($result['output'])->toContain('spawn sh');
});
