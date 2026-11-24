<?php

namespace App\Support;

/**
 * The SCORE rights on an event grant.
 * can_scor stays the app gate and is true when any of them is set.
 * Older rows that only have can_scor still open every view.
 *
 * Head Ref = Schiri plus reopening referee sheets. Head Judge = Jury plus reopening jury sheets and the stage pick.
 * Admin is the RP: everything, including close, release, awards and coach mail.
 * Only the heads and the admin see the results.
 */
final class ScorGrant
{
    public function __construct(
        public readonly bool $referee,
        public readonly bool $jury,
        public readonly bool $admin,
        public readonly bool $headReferee = false,
        public readonly bool $headJudge = false,
    ) {}

    public static function none(): self
    {
        return new self(false, false, false);
    }

    public static function all(): self
    {
        return new self(true, true, true, true, true);
    }

    public static function fromFlags(bool $referee, bool $jury, bool $admin, bool $headReferee = false, bool $headJudge = false): self
    {
        return new self($referee, $jury, $admin, $headReferee, $headJudge);
    }

    /** A legacy SCORE checkbox: on means all rights, off means none. */
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
            (bool) data_get($row, 'can_scor_head_referee'),
            (bool) data_get($row, 'can_scor_head_judge'),
        );

        if (!$grant->any() && (bool) data_get($row, 'can_scor')) {
            return self::all();
        }

        return $grant;
    }

    public function any(): bool
    {
        return $this->referee || $this->jury || $this->admin || $this->headReferee || $this->headJudge;
    }

    public function or(self $other): self
    {
        return new self(
            $this->referee || $other->referee,
            $this->jury || $other->jury,
            $this->admin || $other->admin,
            $this->headReferee || $other->headReferee,
            $this->headJudge || $other->headJudge,
        );
    }

    public function allowsReferee(): bool
    {
        return $this->referee || $this->headReferee || $this->admin;
    }

    public function allowsJury(): bool
    {
        return $this->jury || $this->headJudge || $this->admin;
    }

    public function reopensReferee(): bool
    {
        return $this->headReferee || $this->admin;
    }

    public function reopensJury(): bool
    {
        return $this->headJudge || $this->admin;
    }

    public function picksStage(): bool
    {
        return $this->headJudge || $this->admin;
    }

    public function seesResults(): bool
    {
        return $this->headReferee || $this->headJudge || $this->admin;
    }

    /**
     * @return array{referee: bool, jury: bool, head_referee: bool, head_judge: bool, admin: bool, results: bool, stage: bool}
     */
    public function view(): array
    {
        return [
            'referee' => $this->allowsReferee(),
            'jury' => $this->allowsJury(),
            'head_referee' => $this->reopensReferee(),
            'head_judge' => $this->reopensJury(),
            'admin' => $this->admin,
            'results' => $this->seesResults(),
            'stage' => $this->picksStage(),
        ];
    }

    /**
     * @return array{can_scor: bool, can_scor_referee: bool, can_scor_jury: bool, can_scor_admin: bool, can_scor_head_referee: bool, can_scor_head_judge: bool}
     */
    public function columns(): array
    {
        return [
            'can_scor' => $this->any(),
            'can_scor_referee' => $this->referee,
            'can_scor_jury' => $this->jury,
            'can_scor_admin' => $this->admin,
            'can_scor_head_referee' => $this->headReferee,
            'can_scor_head_judge' => $this->headJudge,
        ];
    }
}
