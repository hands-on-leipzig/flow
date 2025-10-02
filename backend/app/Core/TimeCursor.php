<?php

namespace App\Core;

use DateTime;
use DateInterval;

class TimeCursor
{
    private DateTime $time;

    public function __construct(?DateTime $start = null)
    {
        $this->time = $start ? clone $start : new DateTime();
    }

    /**
     * Gibt die aktuelle Zeit zurück.
     */
    public function current(): DateTime
    {
        return clone $this->time;
    }

    /**
     * Setzt den Cursor auf eine neue Zeit.
     */
    public function set(DateTime $time): void
    {
        $this->time = clone $time;
    }

    /**
     * Verschiebt den Cursor um X Minuten nach vorne.
     */
    public function addMinutes(int $minutes): void
    {
        $this->time->add(new DateInterval("PT{$minutes}M"));
    }

    /**
     * Verschiebt den Cursor um X Minuten nach hinten.
     */
    public function subMinutes(int $minutes): void
    {
        $this->time->sub(new DateInterval("PT{$minutes}M"));
    }

    /**
     * Erstellt eine Kopie des aktuellen Cursors.
     */
    public function copy(): TimeCursor
    {
        return new TimeCursor($this->time);
    }

    /**
     * Gibt die Zeit als formatierten String zurück (z. B. für DB-Insert).
     */
    public function format(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->time->format($format);
    }
}