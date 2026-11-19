<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Controlled error raised by RealtimeKitService. The message is always safe
 * to show to end users; it never contains API tokens or participant JWTs.
 */
class RealtimeKitException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const AUTH_FAILED = 'auth_failed';

    public const NETWORK = 'network';

    public const PRESET = 'preset';

    public const LIMIT = 'limit';

    public const MEETING_INACTIVE = 'meeting_inactive';

    public const API = 'api';

    public function __construct(
        string $message,
        public readonly string $reason = self::API,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }
}
