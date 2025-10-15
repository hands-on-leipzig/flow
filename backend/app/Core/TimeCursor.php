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
     * Setzt die Zeit des Tages ohne das Datum zu ändern.
     */
    public function setTime(string $timeString): void
    {
        [$hours, $minutes] = explode(':', $timeString);
        $this->time->setTime((int)$hours, (int)$minutes);
    }

    /**
     * Verschiebt den Cursor um X Minuten nach vorne.
     */
    public function addMinutes(int $minutes): void
    {
        if ($minutes < 0) {
            $this->subMinutes(abs($minutes));
        } else {
            $this->time->add(new DateInterval("PT{$minutes}M"));
        }
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

    /**
     * Berechnet die Differenz in Minuten zu einem anderen TimeCursor.
     */
    public function diffInMinutes(TimeCursor $other): int
    {
        $diff = $this->time->diff($other->current());
        return ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
    }

    /**
     * Deep clone implementation to ensure DateTime is properly cloned.
     */
    public function __clone()
    {
        $this->time = clone $this->time;
    }
}