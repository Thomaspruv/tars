<?php

use App\Enums\EntityType;
use App\Models\Checklist;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\Task;
use Database\Seeders\ReferenceScenarioSeeder;

test('it seeds every element of the reference scenario from SPECS.md §6bis', function () {
    (new ReferenceScenarioSeeder)->run();

    $companies = Entity::where('type', EntityType::Company)->pluck('name');
    expect($companies)->toContain('SARL Alpha')->toContain('SAS Beta');

    $properties = Entity::where('type', EntityType::Property)->pluck('name');
    expect($properties)->toContain('Appart Lilas')->toContain('Appart Lyon');

    foreach ($companies as $company) {
        $entity = Entity::where('name', $company)->first();
        expect(Task::where('entity_id', $entity->id)->where('title', 'TVA mensuelle')->whereNotNull('recurrence')->exists())
            ->toBeTrue("Missing monthly TVA task for [{$company}]");
    }

    foreach ($properties as $property) {
        $entity = Entity::where('name', $property)->first();
        expect(Task::where('entity_id', $entity->id)->where('title', 'Quittance de loyer')->exists())->toBeTrue();
        expect(Task::where('entity_id', $entity->id)->where('title', 'Taxe foncière')->where('recurrence', 'like', 'yearly%')->exists())->toBeTrue();
    }

    $goal500k = Goal::where('title', '500k CA SARL Alpha')->first();
    expect($goal500k)->not->toBeNull();
    expect($goal500k->entity->name)->toBe('SARL Alpha');

    $goalRenovation = Goal::where('title', 'Rénover SDB Lilas')->first();
    expect($goalRenovation)->not->toBeNull();
    expect($goalRenovation->entity->name)->toBe('Appart Lilas');

    $goalSemiMarathon = Goal::where('title', 'Semi-marathon')->first();
    expect($goalSemiMarathon)->not->toBeNull();
    expect($goalSemiMarathon->entity_id)->toBeNull();

    $courses = Checklist::where('name', 'Courses')->first();
    expect($courses)->not->toBeNull();
    expect($courses->is_pinned)->toBeTrue();

    $travaux = Checklist::where('name', 'Travaux SDB')->first();
    expect($travaux)->not->toBeNull();
    expect($travaux->entity->name)->toBe('Appart Lilas');
});
