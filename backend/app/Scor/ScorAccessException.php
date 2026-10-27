<?php

namespace App\Scor;

final class ScorAccessException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 403)
    {
        parent::__construct($message);
    }
}
