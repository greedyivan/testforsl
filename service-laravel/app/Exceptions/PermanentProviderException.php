<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a notification provider permanently rejects a message.
 * The worker will mark the notification as "dropped" without retrying.
 */
final class PermanentProviderException extends RuntimeException {}
