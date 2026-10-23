<?php

namespace App\Support\Brain;

use App\Models\BrainDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * La recherche dans le cerveau — et le seul endroit où elle est définie.
 *
 * Ce qu'elle remplace : un `LIKE %{requête entière}%` sur le titre et le
 * corps, 5 résultats, triés par date de modification. Une question posée en
 * langage naturel — la seule façon dont un agent cherche — ne trouvait donc
 * jamais rien : `search_brain('marché concurrentiel de Spreak')` exigeait cette
 * chaîne exacte, mot pour mot, dans le texte.
 *
 * Trois corrections, dans cet ordre d'importance :
 *
 * 1. **La requête est découpée en mots**, et tous doivent être présents — c'est
 *    ce qui fait qu'une phrase trouve enfin la note.
 * 2. **Les accents et la casse sont repliés** à l'indexation (`search_text`),
 *    pas à la lecture : sans ça « réunion » et « reunion » sont deux mots en
 *    SQLite et un seul en MySQL, et la recherche ne rend pas la même chose en
 *    local et en production.
 * 3. **Les résultats sont classés**, un mot dans le titre pesant dix fois un
 *    mot dans le corps. Trier par date revenait à répondre « la note la plus
 *    récente qui contient vaguement ça ».
 */
class NoteSearch
{
    /**
     * Les mots trop courants pour discriminer : les garder ferait de
     * « la fiche de Marc » une recherche sur « la » et « de », que presque
     * toute note contient — et la conjonction ET ne filtrerait plus rien.
     */
    private const array STOP_WORDS = [
        'de', 'du', 'des', 'la', 'le', 'les', 'un', 'une', 'et', 'ou', 'au', 'aux',
        'en', 'dans', 'sur', 'pour', 'par', 'avec', 'que', 'qui', 'quoi', 'est',
        'sont', 'ce', 'cet', 'cette', 'ces', 'son', 'sa', 'ses', 'mon', 'ma', 'mes',
        'the', 'of', 'and', 'to', 'in', 'for', 'on', 'is', 'are', 'it',
    ];

    /**
     * Un mot plus long que ça est comparé sur ses premiers caractères
     * seulement. Sans ce raccourci, exiger tous les mots de la requête casse
     * sur la première variante : « concurrentiel » ne trouve pas
     * « concurrence », « fiscalité » ne trouve pas « fiscal ». Sept
     * caractères gardent `concurr`, `fiscali`, `ecosyst` — assez pour
     * discriminer, assez court pour attraper la famille du mot.
     */
    private const int STEM_LENGTH = 7;

    /**
     * Au-delà, on ne charge pas tout le vault pour en classer le contenu. Les
     * candidats sont pris par date décroissante : sur un cerveau personnel
     * (quelques milliers de notes au plus) la conjonction ET en ramène bien
     * moins, et ce plafond ne se voit jamais.
     */
    private const int MAX_CANDIDATES = 400;

    /**
     * La forme sous laquelle on compare : minuscules, sans accents.
     */
    public static function fold(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }

    /**
     * Le texte indexé d'une note — titre d'abord, pour qu'un mot du titre
     * compte aussi comme présent dans le document.
     */
    public static function haystack(?string $title, ?string $content): string
    {
        return self::fold(trim((string) $title."\n".(string) $content));
    }

    /**
     * Les radicaux à chercher — un par mot utile de la requête.
     *
     * @return list<string>
     */
    public static function tokens(string $term): array
    {
        $words = preg_split('/[^a-z0-9]+/', self::fold($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn (string $word): bool => mb_strlen($word) > 1));

        $discriminating = array_values(array_filter($words, fn (string $word): bool => ! in_array($word, self::STOP_WORDS, true)));

        // Une requête qui n'est *faite* que de mots courants (« ce qui est »)
        // vaut mieux qu'une recherche vide : on les garde alors.
        $kept = $discriminating !== [] ? $discriminating : $words;

        return array_values(array_unique(array_map(self::stem(...), $kept)));
    }

    /**
     * Le radical d'un mot : ses premiers caractères. Voir STEM_LENGTH — c'est
     * ce qui fait qu'une requête trouve la famille du mot et pas seulement sa
     * forme exacte.
     */
    private static function stem(string $word): string
    {
        return mb_strlen($word) > self::STEM_LENGTH ? mb_substr($word, 0, self::STEM_LENGTH) : $word;
    }

    /**
     * Les notes qui contiennent tous les mots de la requête, les plus
     * pertinentes d'abord.
     *
     * @return Collection<int, BrainDocument>
     */
    public function find(string $term, int $limit = 12): Collection
    {
        $tokens = self::tokens($term);

        if ($tokens === []) {
            return new Collection;
        }

        $candidates = BrainDocument::query()
            ->search($term)
            ->orderByDesc('mtime')
            ->limit(self::MAX_CANDIDATES)
            ->get();

        return $candidates
            ->sortByDesc(fn (BrainDocument $document): int => self::score($document, $tokens, $term))
            ->take($limit)
            ->values();
    }

    /**
     * Le classement. Un mot dans le titre vaut dix mots dans le corps, et un
     * titre qui contient la requête entière passe devant tout le reste :
     * quand Thomas ou un agent cherche « Spreak concurrence », la note qui
     * s'appelle « Spreak — Concurrence » est *la* réponse, pas la plus récente
     * des douze qui mentionnent Spreak.
     *
     * @param  list<string>  $tokens
     */
    private static function score(BrainDocument $document, array $tokens, string $term): int
    {
        $title = self::fold((string) $document->title);
        $haystack = (string) $document->search_text;

        $score = 0;
        $inTitle = 0;

        foreach ($tokens as $token) {
            if (str_contains($title, $token)) {
                $score += 10;
                $inTitle++;

                continue;
            }

            if (str_contains($haystack, $token)) {
                $score += 1;
            }
        }

        if ($inTitle === count($tokens)) {
            $score += 50;
        }

        if (str_contains($title, self::fold($term))) {
            $score += 100;
        }

        // Une note conforme au contrat est une note dont on connaît le type et
        // les ancres : à pertinence égale, elle est plus utile à un agent.
        return $document->is_conformant ? $score + 1 : $score;
    }

    /**
     * La contrainte SQL : tous les mots présents dans le texte replié.
     *
     * @param  Builder<BrainDocument>  $query
     * @return Builder<BrainDocument>
     */
    public static function constrain(Builder $query, string $term): Builder
    {
        $tokens = self::tokens($term);

        if ($tokens === []) {
            return $query->whereRaw('1 = 0');
        }

        foreach ($tokens as $token) {
            $query->where('search_text', 'like', '%'.self::escapeLike($token).'%');
        }

        return $query;
    }

    /**
     * `_` et `%` dans une requête sont des jokers pour SQL : sans échappement,
     * chercher « 100 % » ramènerait tout le vault.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
