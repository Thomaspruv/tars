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

    public function notificationEmail(): ?string
    {
        return $this->get('notification_email');
    }

    public function notificationsEnabled(): bool
    {
        return filter_var($this->get('notifications_enabled') ?? '0', FILTER_VALIDATE_BOOLEAN);
    }

    public function updateNotifications(?string $email, bool $enabled): void
    {
        $this->set('notification_email', $email);
        $this->set('notifications_enabled', $enabled ? '1' : '0');
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
