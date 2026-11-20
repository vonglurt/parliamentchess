<?php
declare(strict_types=1);

namespace Parliament\Server;

/** An API failure with its HTTP status. */
final class ApiError extends \RuntimeException
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}
