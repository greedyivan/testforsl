<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a notification provider experiences a transient failure.
 * The worker will retry delivery up to MAX_RETRIES times.
 */
final class TemporaryProviderException extends RuntimeException {}
