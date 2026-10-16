<?php

namespace App\Models;

use App\Support\GoogleCalendar\GoogleCalendarClient;
use Database\Factories\GoogleCalendarConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $access_token
 * @property string $refresh_token
 * @property Carbon|null $expires_at
 * @property string|null $google_account_email
 * @property list<string>|null $granted_scopes
 * @property string $calendar_id
 * @property string|null $sync_token
 * @property Carbon|null $last_synced_at
 * @property bool $needs_reconnect
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['access_token', 'refresh_token', 'expires_at', 'google_account_email', 'granted_scopes', 'calendar_id', 'sync_token', 'last_synced_at', 'needs_reconnect'])]
class GoogleCalendarConnection extends Model
{
    /** @use HasFactory<GoogleCalendarConnectionFactory> */
    use HasFactory;

    protected $attributes = [
        'calendar_id' => 'primary',
        'needs_reconnect' => false,
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'granted_scopes' => 'array',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'needs_reconnect' => 'boolean',
        ];
    }

    public function isAccessTokenExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->subMinute()->isPast();
    }

    /**
     * Whether Google actually granted a scope. Connections created before
     * scopes were recorded have no list at all: assume the calendar access
     * they were built for, and nothing more.
     */
    public function hasScope(string $scope): bool
    {
        if ($this->granted_scopes === null) {
            return $scope === GoogleCalendarClient::CALENDAR_SCOPE;
        }

        return in_array($scope, $this->granted_scopes, true);
    }

    public function canReadAndWriteCalendar(): bool
    {
        return $this->hasScope(GoogleCalendarClient::CALENDAR_SCOPE);
    }

    public function canReadGmail(): bool
    {
        return $this->hasScope(GoogleCalendarClient::GMAIL_READONLY_SCOPE);
    }
}
