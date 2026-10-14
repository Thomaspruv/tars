<?php

namespace App\Support\Momentum;

use App\Models\Setting;

/**
 * Les interrupteurs de la mécanique de jeu.
 *
 * Tout est activé par défaut, et tout se coupe indépendamment : les jauges,
 * la série et la célébration ne partagent aucun état, uniquement des règles
 * d'affichage. Couper les trois ramène l'app à ses listes, sans rien perdre —
 * c'est ce qui rend la gamification acceptable.
 */
class MomentumSettings
{
    public function gaugesEnabled(): bool
    {
        return $this->flag('gauges');
    }

    public function streakEnabled(): bool
    {
        return $this->flag('streak');
    }

    public function celebrationEnabled(): bool
    {
        return $this->flag('celebration');
    }

    public function update(bool $gauges, bool $streak, bool $celebration): void
    {
        $this->set('gauges', $gauges);
        $this->set('streak', $streak);
        $this->set('celebration', $celebration);
    }

    private function flag(string $key): bool
    {
        $value = Setting::where('key', "momentum.{$key}")->value('value');

        return $value === null || filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function set(string $key, bool $value): void
    {
        Setting::updateOrCreate(['key' => "momentum.{$key}"], ['value' => $value ? '1' : '0']);
    }
}
