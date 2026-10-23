<?php

namespace App\Support\Brain;

use App\Support\Brain\Schema\NoteType;

/**
 * Où vit chaque type de note dans *ce* vault.
 *
 * Le cerveau est un dépôt unique partagé par plusieurs applications
 * (décision du 23/10/2026), et sa disposition lui appartient : elle est
 * antérieure à Tars et d'autres applications s'y appuient. La correspondance
 * type → dossier est donc une **donnée**, pas un `match` en dur — voir
 * `resources/vault/AGENTS.md`, le règlement du cerveau.
 *
 * Ce que ça rend possible : Tars écrit dans `09 - GENS/` plutôt que de créer
 * un `Personnes/` concurrent à côté. Deux taxonomies dans un dépôt, c'est
 * exactement le bordel que la décision voulait éviter.
 *
 * `NoteType::folder()` reste le défaut — celui d'un vault neuf, que Tars
 * dispose alors à sa façon.
 */
class VaultLayout
{
    public function __construct(private readonly BrainSettings $settings) {}

    /**
     * Le dossier racine où ranger une note de ce type.
     */
    public function folderFor(NoteType $type): string
    {
        return $this->overrides()[$type->value] ?? $type->folder();
    }

    /**
     * Le type qu'on présume pour une note trouvée dans ce dossier — utilisé
     * pour compléter le frontmatter d'une note d'avant le contrat d'après
     * l'endroit où elle vit déjà.
     */
    public function typeForFolder(string $folder): ?NoteType
    {
        foreach (NoteType::cases() as $type) {
            if ($this->folderFor($type) === $folder) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Tous les dossiers que l'ontologie revendique, sans doublon. Ce qui est
     * en dehors n'est pas du ressort du curateur.
     *
     * @return list<string>
     */
    public function managedFolders(): array
    {
        return array_values(array_unique(array_map($this->folderFor(...), NoteType::cases())));
    }

    /**
     * Les dossiers qui reçoivent un `_index.md`, dans l'ordre de lecture.
     *
     * @return list<string>
     */
    public function indexedFolders(): array
    {
        return array_values(array_unique(array_map(
            $this->folderFor(...),
            [NoteType::Project, NoteType::Person, NoteType::Org, NoteType::Veille, NoteType::Skill],
        )));
    }

    /**
     * Les dossiers dont chaque sous-dossier mérite son propre index : un sujet
     * de veille, une catégorie de skill.
     *
     * @return list<string>
     */
    public function nestedFolders(): array
    {
        return array_values(array_unique(array_map(
            $this->folderFor(...),
            [NoteType::Veille, NoteType::Skill],
        )));
    }

    /**
     * Les dossiers où l'emplacement ne dit rien du type : le sas est une salle
     * d'attente, les archives gardent le type qu'elles avaient au classement.
     *
     * @return list<string>
     */
    public function unplacedFolders(): array
    {
        return array_values(array_unique(array_map(
            $this->folderFor(...),
            [NoteType::ATraiter, NoteType::Archive],
        )));
    }

    /**
     * @return array<string, string>
     */
    private function overrides(): array
    {
        return $this->settings->folders();
    }
}
