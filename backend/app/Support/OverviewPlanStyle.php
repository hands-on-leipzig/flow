<?php

namespace App\Support;

use App\Enums\FirstProgram;
use Illuminate\Support\Facades\DB;

/**
 * Überblick / event-overview cell colors.
 *
 * Explore, Challenge, and Future 8+ borders come from m_first_program.color_hex
 * via ProgramCatalog. Live Challenge stays a fixed purple.
 */
final class OverviewPlanStyle
{
    public const GRAY_TINT = '#f5f5f5';

    public const GRAY_BORDER = '#95a5a6';

    public const LIVE_CHALLENGE_TINT = '#f4e6f7';

    public const LIVE_CHALLENGE_BORDER = '#8e44ad';

    /** Fraction of program hex kept when mixing into white (Explore / Challenge / Future 8+). */
    public const PROGRAM_TINT = 0.10;

    /** Slightly stronger light mix for Robot-Game / Game (same border hue). */
    public const FIELD_TINT = 0.18;

    /** Teams-grid slot block cell fill. */
    public const SLOT_TINT = '#fff3e0';

    /** Teams-grid slot block left border (darker orange for contrast on SLOT_TINT). */
    public const SLOT_BORDER = '#ffb74d';

    /**
     * Teams-grid style key for a slot assignment (pale orange fill and border).
     */
    public static function slotStyleColumn(int $programId): string
    {
        return match ($programId) {
            FirstProgram::EXPLORE->value => 'Slot-Explore',
            FirstProgram::CHALLENGE->value => 'Slot-Challenge',
            FirstProgram::FUTURE_8->value => 'Slot-Future 8+',
            default => 'Slot',
        };
    }

    /**
     * @return array{bg: string, border: string}
     */
    public static function slotCellColors(int $programId): array
    {
        return self::cellColors(self::slotStyleColumn($programId));
    }

    /**
     * Allgemein-* column for a first_program id. Future 8+ is Allgemein-4, not Allgemein-8.
     */
    public static function allgemeinColumn(int $programId): ?string
    {
        return match ($programId) {
            FirstProgram::JOINT->value => null,
            FirstProgram::EXPLORE->value => 'Allgemein-2',
            FirstProgram::CHALLENGE->value => 'Allgemein-3',
            FirstProgram::FUTURE_8->value => 'Allgemein-4',
            default => $programId > 0 ? 'Allgemein-'.$programId : null,
        };
    }

    /**
     * Überblick field-column name for a Challenge-shaped program (`r_match` / `f8_r_match`).
     *
     * @var array<int, string>
     */
    private static array $fieldOverviewColumnCache = [];

    public static function fieldOverviewColumn(int $firstProgram): string
    {
        if (array_key_exists($firstProgram, self::$fieldOverviewColumnCache)) {
            return self::$fieldOverviewColumnCache[$firstProgram];
        }

        $challengeId = FirstProgram::CHALLENGE->value;
        $futureId = FirstProgram::FUTURE_8->value;
        if ($firstProgram !== $challengeId && $firstProgram !== $futureId) {
            return self::$fieldOverviewColumnCache[$firstProgram] = 'Robot-Game';
        }

        $code = $firstProgram === $futureId ? 'f8_r_match' : 'r_match';
        $column = (string) (DB::table('m_activity_type_detail as atd')
            ->join('m_activity_type as at', 'at.id', '=', 'atd.activity_type')
            ->where('atd.code', $code)
            ->where('atd.first_program', $firstProgram)
            ->value('at.overview_plan_column') ?? '');

        if ($column === '') {
            $column = $firstProgram === $futureId ? 'Game' : 'Robot-Game';
        }

        return self::$fieldOverviewColumnCache[$firstProgram] = $column;
    }

    /**
     * @return array<string, int>
     */
    public static function columnOrder(): array
    {
        return [
            'Allgemein' => 0,
            'Allgemein-2' => 1,
            'Explore' => 2,
            'Allgemein-3' => 3,
            'Challenge' => 4,
            self::fieldOverviewColumn(FirstProgram::CHALLENGE->value) => 5,
            'Live Challenge' => 6,
            'Allgemein-4' => 7,
            'Future 8+' => 8,
            self::fieldOverviewColumn(FirstProgram::FUTURE_8->value) => 9,
        ];
    }

    /**
     * Preview column order: joint Allgemein first, then program groups by catalog sequence.
     * Columns that belong to one program stay together in {@see columnOrder()} order.
     *
     * @param  list<string>  $columnNames
     * @return list<string>
     */
    public static function sortColumnsByProgramSequence(array $columnNames): array
    {
        if ($columnNames === []) {
            return [];
        }

        $sequence = DB::table('m_first_program')->pluck('sequence', 'id');
        $withinGroup = self::columnOrder();
        $challengeField = self::fieldOverviewColumn(FirstProgram::CHALLENGE->value);
        $futureField = self::fieldOverviewColumn(FirstProgram::FUTURE_8->value);

        usort($columnNames, function (string $a, string $b) use ($sequence, $withinGroup, $challengeField, $futureField): int {
            [$seqA, $idA] = self::columnProgramRank($a, $sequence, $challengeField, $futureField);
            [$seqB, $idB] = self::columnProgramRank($b, $sequence, $challengeField, $futureField);
            if ($seqA !== $seqB) {
                return $seqA <=> $seqB;
            }
            if ($idA !== $idB) {
                return $idA <=> $idB;
            }

            return ($withinGroup[$a] ?? 999) <=> ($withinGroup[$b] ?? 999);
        });

        return array_values($columnNames);
    }

    /**
     * Joint Allgemein sorts before every program. A missing sequence sorts after known ones.
     *
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $sequence
     * @return array{0: int, 1: int}
     */
    private static function columnProgramRank(string $columnName, $sequence, string $challengeField, string $futureField): array
    {
        if ($columnName === 'Allgemein') {
            return [-1, 0];
        }

        $programId = match (true) {
            $columnName === 'Allgemein-2', $columnName === 'Explore' => FirstProgram::EXPLORE->value,
            $columnName === 'Allgemein-3', $columnName === 'Challenge', $columnName === 'Live Challenge', $columnName === $challengeField => FirstProgram::CHALLENGE->value,
            $columnName === 'Allgemein-4', $columnName === 'Future 8+', $columnName === $futureField => FirstProgram::FUTURE_8->value,
            str_starts_with($columnName, 'Allgemein-') => (int) substr($columnName, strlen('Allgemein-')),
            default => null,
        };

        if ($programId === null) {
            return [PHP_INT_MAX, PHP_INT_MAX];
        }

        $seq = $sequence[$programId] ?? null;

        return [$seq === null ? PHP_INT_MAX : (int) $seq, $programId];
    }

    /**
     * @return array{bg: string, border: string}
     */
    public static function cellColors(string $assignedColumn): array
    {
        return self::cellColorsFromCatalog(
            $assignedColumn,
            ProgramCatalog::colorCss('EXPLORE'),
            ProgramCatalog::colorCss('CHALLENGE'),
            ProgramCatalog::colorCss('FUTURE_8'),
            self::fieldOverviewColumn(FirstProgram::CHALLENGE->value),
            self::fieldOverviewColumn(FirstProgram::FUTURE_8->value),
        );
    }

    /**
     * @return array{bg: string, border: string}
     */
    public static function cellColorsFromCatalog(
        string $assignedColumn,
        string $exploreBorder,
        string $challengeBorder,
        string $future8Border,
        string $challengeFieldColumn = 'Robot-Game',
        string $futureFieldColumn = 'Game',
    ): array {
        $exploreTint = ProgramCatalog::mixHexWithWhite($exploreBorder, self::PROGRAM_TINT);
        $challengeTint = ProgramCatalog::mixHexWithWhite($challengeBorder, self::PROGRAM_TINT);
        $future8Tint = ProgramCatalog::mixHexWithWhite($future8Border, self::PROGRAM_TINT);
        $robotTint = ProgramCatalog::mixHexWithWhite($challengeBorder, self::FIELD_TINT);
        $gameTint = ProgramCatalog::mixHexWithWhite($future8Border, self::FIELD_TINT);

        return match ($assignedColumn) {
            'Explore' => ['bg' => $exploreTint, 'border' => $exploreBorder],
            'Challenge' => ['bg' => $challengeTint, 'border' => $challengeBorder],
            $challengeFieldColumn => ['bg' => $robotTint, 'border' => $challengeBorder],
            'Future 8+' => ['bg' => $future8Tint, 'border' => $future8Border],
            $futureFieldColumn => ['bg' => $gameTint, 'border' => $future8Border],
            'Live Challenge' => ['bg' => self::LIVE_CHALLENGE_TINT, 'border' => self::LIVE_CHALLENGE_BORDER],
            'Allgemein-2' => ['bg' => self::GRAY_TINT, 'border' => $exploreBorder],
            'Allgemein-3' => ['bg' => self::GRAY_TINT, 'border' => $challengeBorder],
            'Allgemein-4' => ['bg' => self::GRAY_TINT, 'border' => $future8Border],
            'Slot-Explore' => ['bg' => self::SLOT_TINT, 'border' => self::SLOT_BORDER],
            'Slot-Challenge' => ['bg' => self::SLOT_TINT, 'border' => self::SLOT_BORDER],
            'Slot-Future 8+' => ['bg' => self::SLOT_TINT, 'border' => self::SLOT_BORDER],
            'Slot' => ['bg' => self::SLOT_TINT, 'border' => self::SLOT_BORDER],
            default => ['bg' => self::GRAY_TINT, 'border' => self::GRAY_BORDER],
        };
    }

    /** PDF header text color for a column. */
    public static function headerColor(string $columnName): string
    {
        return self::cellColors($columnName)['border'];
    }
}
