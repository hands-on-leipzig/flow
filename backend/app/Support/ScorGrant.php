<?php

namespace App\Support;

/**
 * The three SCOR rights on an event grant.
 * can_scor stays the app gate and is true when any of them is set.
 * Older rows that only have can_scor still open all three views.
 */
final class ScorGrant
{
    public function __construct(
        public readonly bool $referee,
        public readonly bool $jury,
        public readonly bool $admin,
    ) {}

    public static function none(): self
    {
        return new self(false, false, false);
    }

    public static function all(): self
    {
        return new self(true, true, true);
    }

    public static function fromFlags(bool $referee, bool $jury, bool $admin): self
    {
        return new self($referee, $jury, $admin);
    }

    /** A legacy SCOR checkbox: on means all three rights, off means none. */
    public static function fromLegacy(bool $canScor): self
    {
        return $canScor ? self::all() : self::none();
    }

    public static function fromRow(object|array|null $row): self
    {
        if ($row === null) {
            return self::none();
        }

        $grant = new self(
            (bool) data_get($row, 'can_scor_referee'),
            (bool) data_get($row, 'can_scor_jury'),
            (bool) data_get($row, 'can_scor_admin'),
        );

        if (!$grant->any() && (bool) data_get($row, 'can_scor')) {
            return self::all();
        }

        return $grant;
    }

    public function any(): bool
    {
        return $this->referee || $this->jury || $this->admin;
    }

    public function or(self $other): self
    {
        return new self(
            $this->referee || $other->referee,
            $this->jury || $other->jury,
            $this->admin || $other->admin,
        );
    }

    public function allowsReferee(): bool
    {
        return $this->referee || $this->admin;
    }

    public function allowsJury(): bool
    {
        return $this->jury || $this->admin;
    }

    /**
     * @return array{can_scor: bool, can_scor_referee: bool, can_scor_jury: bool, can_scor_admin: bool}
     */
    public function columns(): array
    {
        return [
            'can_scor' => $this->any(),
            'can_scor_referee' => $this->referee,
            'can_scor_jury' => $this->jury,
            'can_scor_admin' => $this->admin,
        ];
    }
}
