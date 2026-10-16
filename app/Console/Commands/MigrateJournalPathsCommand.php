<?php

namespace App\Console\Commands;

use App\Models\JournalEntry;
use App\Models\QuestionnaireRun;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\VaultOperation;
use App\Support\Brain\VaultParser;
use App\Support\Brain\VaultWriter;
use App\Support\Journal\JournalWriter;
use App\Support\Questionnaire\QuestionnaireCompleter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * One-shot migration of the journal and the bilans from the sas
 * (`TARS/Journal/…`, where they landed before the ontology existed) to the
 * target tree (`Journal/AAAA/`, `Journal/Bilans/`).
 *
 * It is a command rather than a database migration on purpose: it moves files
 * and commits to git, which has no business running during a deploy on a
 * machine that may not even have the vault cloned.
 */
#[Signature('brain:migrate-journal {--dry-run : Afficher les déplacements sans rien écrire}')]
#[Description("Déplace le journal et les bilans de TARS/Journal/ vers l'arborescence cible, et réécrit les note_path en base")]
class MigrateJournalPathsCommand extends Command
{
    public function handle(BrainSettings $settings, VaultWriter $writer, VaultParser $parser): int
    {
        if (! $settings->isConfigured()) {
            $this->warn("Le vault n'est pas configuré — rien à migrer.");

            return self::SUCCESS;
        }

        $vaultPath = rtrim($settings->localPath(), '/');
        $dryRun = (bool) $this->option('dry-run');

        /** @var list<VaultOperation> $operations */
        $operations = [];
        /** @var list<array{model: JournalEntry|QuestionnaireRun, to: string}> $pointers */
        $pointers = [];

        foreach ($this->entriesToMove() as [$model, $from, $to]) {
            $pointers[] = ['model' => $model, 'to' => $to];
            $this->line("  {$from} → {$to}");

            $absoluteFrom = "{$vaultPath}/{$from}";

            // A pointer with no file left to move still gets rewritten: the DB
            // has to describe where the note lives from now on.
            if (! File::exists($absoluteFrom)) {
                $this->comment('    (fichier absent du vault — seul le pointeur est réécrit)');

                continue;
            }

            $parsed = $parser->parse(File::get($absoluteFrom), basename($from, '.md'));
            $operations[] = VaultOperation::move($from, $to, $parsed->frontmatter, $parsed->content);
        }

        if ($pointers === []) {
            $this->info('Rien à migrer — le journal est déjà dans l\'arborescence cible.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info(count($pointers).' pointeur(s) et '.count($operations).' fichier(s) seraient déplacés.');

            return self::SUCCESS;
        }

        if ($operations !== []) {
            $result = $writer->apply($operations, 'journal migration vers Journal/');

            if (! $result->isWritten()) {
                $this->error($result->message());

                return self::FAILURE;
            }
        }

        foreach ($pointers as ['model' => $model, 'to' => $to]) {
            $model->forceFill(['note_path' => $to])->save();
        }

        $this->info(count($pointers).' pointeur(s) réécrit(s), '.count($operations).' fichier(s) déplacé(s) en un commit.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{0: JournalEntry|QuestionnaireRun, 1: string, 2: string}>
     */
    private function entriesToMove(): array
    {
        $moves = [];

        foreach (JournalEntry::where('note_path', 'like', 'TARS/Journal/%')->orderBy('date')->get() as $entry) {
            $target = JournalWriter::pathFor($entry->date);

            if ($entry->note_path !== $target) {
                $moves[] = [$entry, (string) $entry->note_path, $target];
            }
        }

        foreach (QuestionnaireRun::where('note_path', 'like', 'TARS/Journal/%')->with('questionnaire')->orderBy('due_date')->get() as $run) {
            $target = QuestionnaireCompleter::pathFor($run);

            if ($run->note_path !== $target) {
                $moves[] = [$run, (string) $run->note_path, $target];
            }
        }

        return $moves;
    }
}
