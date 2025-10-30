<?php

namespace App\Enums;

enum GeneratorStatus: string
{
    case RUNNING = 'running';
    case DONE = 'done';
    case FAILED = 'failed';
    case UNKNOWN = 'unknown';

    /**
     * Check if generation is in progress
     */
    public function isRunning(): bool
    {
        return $this === self::RUNNING;
    }

    /**
     * Check if generation completed successfully
     */
    public function isDone(): bool
    {
        return $this === self::DONE;
    }

    /**
     * Check if generation failed
     */
    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }

    /**
     * Check if generation is complete (done or failed)
     */
    public function isComplete(): bool
    {
        return $this === self::DONE || $this === self::FAILED;
    }
}

