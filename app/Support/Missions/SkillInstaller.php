<?php

namespace App\Support\Missions;

/**
 * Installe un skill depuis skills.sh, via son CLI.
 */
class SkillInstaller
{
    public function __construct(
        private readonly SkillCatalog $catalog = new SkillCatalog,
        private readonly SkillsCli $cli = new SkillsCli,
    ) {}

    /**
     * Les skills qu'un dépôt propose, sans rien installer.
     *
     * @return array{ok: bool, output: string}
     */
    public function preview(string $package): array
    {
        return $this->cli->run(['add', trim($package), '--list']);
    }

    /**
     * @return array{ok: bool, output: string}
     */
    public function install(string $package, ?string $skill = null): array
    {
        $arguments = ['add', trim($package), '-g', '-a', 'claude-code', '-y'];

        if ($skill !== null && $skill !== '') {
            $arguments[] = '--skill';
            $arguments[] = $skill;
        }

        $result = $this->cli->run($arguments);

        if ($result['ok']) {
            $this->catalog->forget();
        }

        return $result;
    }
}
