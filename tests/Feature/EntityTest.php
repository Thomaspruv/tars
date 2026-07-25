<?php

use App\Enums\EntityContext;
use App\Models\Entity;
use App\Models\LifeArea;

test('deduces a pro context for a company', function () {
    $entity = Entity::create([
        'life_area_id' => LifeArea::factory()->create()->id,
        'name' => 'SARL Alpha',
        'type' => 'company',
    ]);

    expect($entity->context)->toBe(EntityContext::Pro);
});

test('deduces a perso context for property, vehicle and other types', function (string $type) {
    $entity = Entity::create([
        'life_area_id' => LifeArea::factory()->create()->id,
        'name' => 'Appart Lyon',
        'type' => $type,
    ]);

    expect($entity->context)->toBe(EntityContext::Perso);
})->with(['property', 'vehicle', 'other']);

test('re-deduces context when the type changes', function () {
    $entity = Entity::factory()->create(['type' => 'company']);

    expect($entity->context)->toBe(EntityContext::Pro);

    $entity->update(['type' => 'property']);

    expect($entity->fresh()->context)->toBe(EntityContext::Perso);
});
