<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const array NAMES = ['Bilan mensuel', 'Bilan annuel'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        DB::table('questionnaires')->insert([
            [
                'name' => 'Bilan mensuel',
                'frequency' => 'monthly',
                'anchor' => '1',
                'questions' => json_encode([
                    ['text' => 'Satisfaction du mois, de 1 à 10', 'type' => 'scale', 'scale_max' => 10],
                    ['text' => 'Top 3 du mois', 'type' => 'text'],
                    ['text' => 'Ce qui a pesé', 'type' => 'text'],
                    ['text' => 'Une chose à changer', 'type' => 'text'],
                ]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Bilan annuel',
                'frequency' => 'yearly',
                'anchor' => '12-31',
                'questions' => json_encode([
                    ['text' => 'Satisfaction — Santé, de 1 à 10', 'type' => 'scale', 'scale_max' => 10],
                    ['text' => 'Satisfaction — Travail, de 1 à 10', 'type' => 'scale', 'scale_max' => 10],
                    ['text' => 'Satisfaction — Relations, de 1 à 10', 'type' => 'scale', 'scale_max' => 10],
                    ['text' => 'Satisfaction — Finances, de 1 à 10', 'type' => 'scale', 'scale_max' => 10],
                    ['text' => 'Satisfaction — Projets perso, de 1 à 10', 'type' => 'scale', 'scale_max' => 10],
                    ['text' => 'Ta plus grande fierté de l\'année', 'type' => 'text'],
                    ['text' => 'Ton plus grand regret de l\'année', 'type' => 'text'],
                    ['text' => "Le cap pour l'année prochaine", 'type' => 'text'],
                ]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('questionnaires')->whereIn('name', self::NAMES)->delete();
    }
};
