<?php

namespace App\Support\QuickAdd;

use App\Enums\TaskPriority;
use App\Models\Entity;
use App\Models\Goal;
use Carbon\CarbonInterface;

final readonly class QuickAddResult
{
    /**
     * Les verbes qui ouvrent une tâche, pas un rendez-vous. « Appeler le
     * plombier demain 14h » est une tâche avec une heure ; « Réunion compta
     * vendredi 10h » est un événement.
     */
    private const array ACTION_VERBS = [
        'appeler', 'payer', 'relancer', 'envoyer', 'rédiger', 'rediger',
        'ranger', 'préparer', 'preparer',
    ];

    /**
     * @param  list<QuickAddToken>  $tokens
     * @param  string|null  $time  Heure au format H:i, séparée de la date pour
     *                             que `date` reste une date nue — c'est ce que
     *                             stocke `scheduled_date`.
     */
    public function __construct(
        public string $title,
        public ?CarbonInterface $date,
        public ?Entity $entity,
        public ?Goal $goal,
        public ?TaskPriority $priority,
        public array $tokens,
        public ?string $time = null,
        public ?string $recurrence = null,
    ) {}

    public function hasStructuredToken(): bool
    {
        return $this->date !== null
            || $this->entity !== null
            || $this->goal !== null
            || $this->priority !== null
            || $this->time !== null
            || $this->recurrence !== null;
    }

    /**
     * Ce que la ligne va créer : `note` si elle s'annonce comme telle, `event`
     * si elle porte une heure sans verbe d'action en tête, `task` sinon.
     *
     * @return 'task'|'event'|'note'
     */
    public function kind(): string
    {
        if (preg_match('/^\s*(?:id[ée]e|note)\s*:/iu', $this->title) === 1) {
            return 'note';
        }

        if ($this->time !== null && ! $this->startsWithActionVerb()) {
            return 'event';
        }

        return 'task';
    }

    /**
     * Date et heure réunies — ce que demande `starts_at` d'un événement. Sans
     * date, l'heure se pose sur aujourd'hui ; sans heure, il est minuit.
     */
    public function startsAt(): ?CarbonInterface
    {
        if ($this->date === null && $this->time === null) {
            return null;
        }

        $base = $this->date?->copy() ?? today();

        if ($this->time === null) {
            return $base->startOfDay();
        }

        [$hour, $minute] = explode(':', $this->time);

        return $base->setTime((int) $hour, (int) $minute);
    }

    private function startsWithActionVerb(): bool
    {
        return preg_match('/^\s*(?:'.implode('|', self::ACTION_VERBS).')\b/iu', $this->title) === 1;
    }
}
