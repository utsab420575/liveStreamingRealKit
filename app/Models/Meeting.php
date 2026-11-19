<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class Meeting extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    /** Allowed characters for public meeting codes (no 0/O/1/I to avoid confusion). */
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 8;

    /** Regex used to validate meeting codes supplied by users. */
    public const CODE_PATTERN = '/^[A-Z0-9]{6,10}$/';

    /**
     * host_secret_hash is intentionally NOT fillable; it is set explicitly
     * via setHostSecret() and never mass-assigned from user input.
     */
    protected $fillable = [
        'title',
        'meeting_code',
        'realtimekit_meeting_id',
        'host_name',
        'status',
    ];

    protected $hidden = [
        'host_secret_hash',
        'realtimekit_meeting_id',
    ];

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function setHostSecret(string $secret): void
    {
        $this->host_secret_hash = Hash::make($secret);
    }

    public function verifyHostSecret(?string $secret): bool
    {
        return is_string($secret) && $secret !== '' && Hash::check($secret, $this->host_secret_hash);
    }

    /** Session key under which the raw host secret for this meeting is kept. */
    public function hostSessionKey(): string
    {
        return 'host_secrets.'.$this->meeting_code;
    }

    /** Whether the current browser session is the creator/host of this meeting. */
    public function isHostSession(): bool
    {
        return $this->verifyHostSecret(session($this->hostSessionKey()));
    }

    /** Generate a random, unique, human-shareable meeting code. */
    public static function generateUniqueCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;

        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, $max)];
            }
        } while (static::where('meeting_code', $code)->exists());

        return $code;
    }
}
