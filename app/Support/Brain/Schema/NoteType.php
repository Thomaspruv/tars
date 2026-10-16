<?php

namespace App\Support\Brain\Schema;

/**
 * The ontology of the vault — what a note *is*, which decides where it lives
 * and how it is named. See `resources/vault/README.md` (the copy TARS writes
 * into the vault) and SPEC-TARS-OS-v1 §A.3/§A.4.
 */
enum NoteType: string
{
    case Project = 'project';
    case Person = 'person';
    case Org = 'org';
    case Meeting = 'meeting';
    case Note = 'note';
    case Decision = 'decision';
    case Journal = 'journal';
    case Veille = 'veille';
    case Skill = 'skill';
    case Profil = 'profil';
    case ATraiter = 'a-traiter';
    case Archive = 'archive';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Projet',
            self::Person => 'Personne',
            self::Org => 'Organisation',
            self::Meeting => 'Réunion',
            self::Note => 'Note vivante',
            self::Decision => 'Décision',
            self::Journal => 'Journal',
            self::Veille => 'Veille',
            self::Skill => 'Skill',
            self::Profil => 'Profil',
            self::ATraiter => 'À traiter',
            self::Archive => 'Archive',
        };
    }

    /**
     * The folder a note of this type belongs in — the first segment of its
     * path. Sub-folders (a veille subject, a project slug) are the
     * NotePathResolver's business, not the type's.
     */
    public function folder(): string
    {
        return match ($this) {
            self::Project => 'Projets',
            self::Person => 'Personnes',
            self::Org => 'Organisations',
            self::Meeting => 'Réunions',
            self::Note => 'Notes',
            self::Decision => 'Décisions',
            self::Journal => 'Journal',
            self::Veille => 'Veille',
            self::Skill => 'Skills',
            self::Profil => 'Profil',
            self::ATraiter => 'TARS',
            self::Archive => 'Archives',
        };
    }

    /**
     * Dated notes are immutable and stacked (`AAAA-MM-JJ-slug.md`); the rest
     * are living notes, rewritten in place at a stable path.
     */
    public function isDated(): bool
    {
        return in_array($this, [self::Meeting, self::Decision, self::Journal, self::Veille], true);
    }

    public function requiresStatus(): bool
    {
        return in_array($this, [self::Project, self::Veille, self::ATraiter], true);
    }
}
