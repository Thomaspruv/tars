<?php

namespace App\Support\Entity;

use App\Enums\EntityType;
use Illuminate\Support\Carbon;

class EntityDetailFields
{
    /**
     * The extra, type-specific fields offered on the entity form — all optional,
     * stored in Entity::$details (json). Adding a field here makes it available
     * to the create form, the edit form, and the fiche display in one place.
     *
     * @return list<array{key: string, label: string, type: string, options?: array<string, string>}>
     */
    public static function forType(EntityType|string $type): array
    {
        $type = $type instanceof EntityType ? $type->value : $type;

        return match ($type) {
            'property' => [
                ['key' => 'address', 'label' => 'Adresse', 'type' => 'text'],
                ['key' => 'surface_m2', 'label' => 'Surface (m²)', 'type' => 'number'],
                ['key' => 'housing_type', 'label' => 'Type de logement', 'type' => 'select', 'options' => [
                    'apartment' => 'Appartement',
                    'house' => 'Maison',
                    'garage' => 'Garage',
                    'parking' => 'Parking',
                    'commercial' => 'Local',
                ]],
                ['key' => 'occupation', 'label' => 'Occupation', 'type' => 'select', 'options' => [
                    'owner' => 'Le propriétaire y habite',
                    'rented' => 'Loué',
                    'vacant' => 'Vacant',
                ]],
                ['key' => 'tenant_name', 'label' => 'Locataire', 'type' => 'text'],
                ['key' => 'monthly_rent', 'label' => 'Loyer mensuel (€)', 'type' => 'number'],
                ['key' => 'purchase_date', 'label' => "Date d'achat", 'type' => 'date'],
                ['key' => 'purchase_price', 'label' => "Prix d'achat (€)", 'type' => 'number'],
                ['key' => 'energy_class', 'label' => 'Classe énergie (DPE)', 'type' => 'select', 'options' => [
                    'A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D', 'E' => 'E', 'F' => 'F', 'G' => 'G',
                ]],
                ['key' => 'insurer', 'label' => 'Assureur', 'type' => 'text'],
                ['key' => 'insurance_due_date', 'label' => 'Échéance assurance', 'type' => 'date'],
                ['key' => 'lot_number', 'label' => 'Numéro de lot (copropriété)', 'type' => 'text'],
            ],
            'company' => [
                ['key' => 'legal_form', 'label' => 'Forme juridique', 'type' => 'select', 'options' => [
                    'sarl' => 'SARL',
                    'sas' => 'SAS',
                    'ei' => 'Entreprise individuelle',
                    'micro' => 'Micro-entreprise',
                    'association' => 'Association',
                    'other' => 'Autre',
                ]],
                ['key' => 'siret', 'label' => 'SIRET', 'type' => 'text'],
                ['key' => 'creation_date', 'label' => 'Date de création', 'type' => 'date'],
                ['key' => 'address', 'label' => 'Adresse du siège', 'type' => 'text'],
                ['key' => 'manager_name', 'label' => 'Gérant / dirigeant', 'type' => 'text'],
                ['key' => 'accountant_contact', 'label' => 'Expert-comptable', 'type' => 'text'],
                ['key' => 'share_capital', 'label' => 'Capital social (€)', 'type' => 'number'],
            ],
            'vehicle' => [
                ['key' => 'plate_number', 'label' => 'Immatriculation', 'type' => 'text'],
                ['key' => 'make_model', 'label' => 'Marque / modèle', 'type' => 'text'],
                ['key' => 'registration_date', 'label' => 'Date de mise en circulation', 'type' => 'date'],
                ['key' => 'next_inspection_date', 'label' => 'Prochain contrôle technique', 'type' => 'date'],
                ['key' => 'insurer', 'label' => 'Assureur', 'type' => 'text'],
                ['key' => 'insurance_due_date', 'label' => 'Échéance assurance', 'type' => 'date'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, list<string>>
     */
    public static function validationRules(EntityType|string $type, string $prefix): array
    {
        $rules = [];

        foreach (self::forType($type) as $field) {
            $rules["{$prefix}.{$field['key']}"] = match ($field['type']) {
                'number' => ['nullable', 'numeric'],
                'date' => ['nullable', 'date'],
                default => ['nullable', 'string', 'max:255'],
            };
        }

        return $rules;
    }

    /**
     * The subset of a type's fields that actually have a value, formatted for display.
     *
     * @param  array<string, mixed>  $details
     * @return list<array{label: string, value: string}>
     */
    public static function filledPairs(EntityType|string $type, array $details): array
    {
        $pairs = [];

        foreach (self::forType($type) as $field) {
            $value = $details[$field['key']] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $pairs[] = [
                'label' => $field['label'],
                'value' => match ($field['type']) {
                    'select' => $field['options'][$value] ?? (string) $value,
                    'date' => Carbon::parse($value)->translatedFormat('d M Y'),
                    default => (string) $value,
                },
            ];
        }

        return $pairs;
    }
}
