<?php

namespace App\Mail\Catalog;

use Closure;
use Illuminate\Mail\Mailable;

final class MailNotificationDefinition
{
    public const STATUS_LIVE = 'live';

    public const STATUS_DRAFT = 'draft';

    /**
     * @param  Closure(): Mailable  $sample
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $trigger,
        public readonly string $audience,
        public readonly string $status,
        public readonly Closure $sample,
    ) {}

    public function mailable(): Mailable
    {
        return ($this->sample)();
    }

    /**
     * @return array{key: string, name: string, trigger: string, audience: string, status: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'trigger' => $this->trigger,
            'audience' => $this->audience,
            'status' => $this->status,
        ];
    }
}
