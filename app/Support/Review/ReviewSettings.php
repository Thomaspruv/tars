<?php

namespace App\Support\Review;

use App\Models\Setting;

class ReviewSettings
{
    public function weeklyTime(): string
    {
        return $this->get('weekly_time') ?? '17:00';
    }

    public function updateWeeklyTime(string $time): void
    {
        $this->set('weekly_time', $time);
    }

    private function get(string $key): ?string
    {
        return Setting::where('key', "review.{$key}")->value('value');
    }

    private function set(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => "review.{$key}"], ['value' => $value]);
    }
}
