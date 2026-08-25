<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FirstProgram;
use InvalidArgumentException;

/**
 * Catalog param names for one Challenge-shaped program (Challenge or Future 8+).
 *
 * Aligns with ProgramPresence::CHALLENGE_SHAPED and MatchPlanSpec bindings.
 * Used by quality mass-test create/evaluate so C vs F8 is not hard-coded as c_*.
 *
 * Note: q_plan still stores grid dimensions in columns named c_teams / j_lanes /
 * r_tables — those are program-agnostic storage; resolve live plan params via this map.
 */
final class ChallengeShapedParamMap
{
    /**
     * @var array<int, array{
     *     mode: string,
     *     teams: string,
     *     lanes: string,
     *     tables: string,
     *     transfer: string,
     *     robotCheck: ?string
     * }>
     */
    private const MAP = [
        FirstProgram::CHALLENGE->value => [
            'mode' => 'c_mode',
            'teams' => 'c_teams',
            'lanes' => 'j_lanes',
            'tables' => 'r_tables',
            'transfer' => 'c_duration_transfer',
            'robotCheck' => 'r_robot_check',
        ],
        FirstProgram::FUTURE_8->value => [
            'mode' => 'f8_mode',
            'teams' => 'f8_teams',
            'lanes' => 'f8_lanes',
            'tables' => 'f8_fields',
            'transfer' => 'f8_duration_transfer',
            'robotCheck' => null,
        ],
    ];

    private function __construct(
        public readonly FirstProgram $program,
        private readonly array $keys,
    ) {
    }

    public static function isSupported(int $firstProgramId): bool
    {
        return isset(self::MAP[$firstProgramId]);
    }

    /** @return list<int> */
    public static function supportedIds(): array
    {
        return array_map('intval', array_keys(self::MAP));
    }

    public static function from(FirstProgram|int $program): self
    {
        $id = $program instanceof FirstProgram ? $program->value : $program;
        $keys = self::MAP[$id] ?? null;
        if ($keys === null) {
            throw new InvalidArgumentException(
                "ChallengeShapedParamMap: program {$id} is not Challenge-shaped (C or F8)."
            );
        }

        $enum = $program instanceof FirstProgram
            ? $program
            : FirstProgram::from($id);

        return new self($enum, $keys);
    }

    public function mode(): string
    {
        return $this->keys['mode'];
    }

    public function teams(): string
    {
        return $this->keys['teams'];
    }

    public function lanes(): string
    {
        return $this->keys['lanes'];
    }

    public function tables(): string
    {
        return $this->keys['tables'];
    }

    public function transfer(): string
    {
        return $this->keys['transfer'];
    }

    /** Param name for robot check, or null if this program has none. */
    public function robotCheck(): ?string
    {
        return $this->keys['robotCheck'];
    }

    public function supportsRobotCheck(): bool
    {
        return $this->keys['robotCheck'] !== null;
    }
}
