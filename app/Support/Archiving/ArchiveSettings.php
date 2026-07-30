<?php

namespace App\Support\Archiving;

use App\Models\Setting;

class ArchiveSettings
{
    /**
     * Number of days a done task / checked list item stays visible before
     * being archived. Null means archiving is disabled.
     */
    public function afterDays(): ?int
    {
        $value = $this->get('after_days');

        return $value !== null ? (int) $value : null;
    }

    public function updateAfterDays(?int $days): void
    {
        $this->set('after_days', $days !== null ? (string) $days : null);
    }

    private function get(string $key): ?string
    {
        return Setting::where('key', "archive.{$key}")->value('value');
    }

    private function set(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => "archive.{$key}"], ['value' => $value]);
    }
}
