<?php

use App\Enums\EntityRelationType;
use App\Models\Entity;
use App\Models\EntityRelation;
use Illuminate\Database\QueryException;

test('an entity exposes relations where it is the subject and the object', function () {
    $company = Entity::factory()->create(['name' => 'SARL Dupont']);
    $person = Entity::factory()->create(['name' => 'Jean Dupont']);

    EntityRelation::create([
        'entity_id' => $person->id,
        'related_entity_id' => $company->id,
        'relation_type' => EntityRelationType::EmployedBy,
    ]);

    expect($person->relations)->toHaveCount(1)
        ->and($person->relations->first()->relatedEntity->id)->toBe($company->id)
        ->and($company->relatedFrom)->toHaveCount(1)
        ->and($company->relatedFrom->first()->entity->id)->toBe($person->id);
});

test('the same entity pair cannot have the same relation type twice', function () {
    $a = Entity::factory()->create();
    $b = Entity::factory()->create();

    EntityRelation::create(['entity_id' => $a->id, 'related_entity_id' => $b->id, 'relation_type' => EntityRelationType::Other]);

    expect(fn () => EntityRelation::create(['entity_id' => $a->id, 'related_entity_id' => $b->id, 'relation_type' => EntityRelationType::Other]))
        ->toThrow(QueryException::class);
});

test('deleting an entity cascades its relations', function () {
    $a = Entity::factory()->create();
    $b = Entity::factory()->create();

    EntityRelation::create(['entity_id' => $a->id, 'related_entity_id' => $b->id, 'relation_type' => EntityRelationType::Other]);

    $a->delete();

    expect(EntityRelation::count())->toBe(0);
});
