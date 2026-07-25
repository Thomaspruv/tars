<?php

use App\Support\Entity\EntityDetailFields;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setLocale('fr'));

test('returns the property fields', function () {
    $keys = array_column(EntityDetailFields::forType('property'), 'key');

    expect($keys)->toContain('address', 'surface_m2', 'occupation', 'tenant_name', 'monthly_rent');
});

test('returns the company fields', function () {
    $keys = array_column(EntityDetailFields::forType('company'), 'key');

    expect($keys)->toContain('legal_form', 'siret', 'manager_name');
});

test('returns the vehicle fields', function () {
    $keys = array_column(EntityDetailFields::forType('vehicle'), 'key');

    expect($keys)->toContain('plate_number', 'next_inspection_date');
});

test('returns no fields for other', function () {
    expect(EntityDetailFields::forType('other'))->toBe([]);
});

test('builds validation rules per field type', function () {
    $rules = EntityDetailFields::validationRules('property', 'details');

    expect($rules['details.surface_m2'])->toBe(['nullable', 'numeric'])
        ->and($rules['details.purchase_date'])->toBe(['nullable', 'date'])
        ->and($rules['details.address'])->toBe(['nullable', 'string', 'max:255']);
});

test('filled pairs skip empty values and format select/date fields', function () {
    $pairs = EntityDetailFields::filledPairs('property', [
        'address' => '12 rue des Lilas',
        'surface_m2' => '',
        'occupation' => 'rented',
        'purchase_date' => '2024-03-15',
    ]);

    expect($pairs)->toBe([
        ['label' => 'Adresse', 'value' => '12 rue des Lilas'],
        ['label' => 'Occupation', 'value' => 'Loué'],
        ['label' => "Date d'achat", 'value' => '15 mars 2024'],
    ]);
});
