<?php

namespace App\Support;

use App\Enums\FirstProgram;

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
            'Robot-Game' => 5,
            'Live Challenge' => 6,
            'Allgemein-4' => 7,
            'Future 8+' => 8,
            'Game' => 9,
        ];
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
    ): array {
        $exploreTint = ProgramCatalog::mixHexWithWhite($exploreBorder, self::PROGRAM_TINT);
        $challengeTint = ProgramCatalog::mixHexWithWhite($challengeBorder, self::PROGRAM_TINT);
        $future8Tint = ProgramCatalog::mixHexWithWhite($future8Border, self::PROGRAM_TINT);
        $robotTint = ProgramCatalog::mixHexWithWhite($challengeBorder, self::FIELD_TINT);
        $gameTint = ProgramCatalog::mixHexWithWhite($future8Border, self::FIELD_TINT);

        return match ($assignedColumn) {
            'Explore' => ['bg' => $exploreTint, 'border' => $exploreBorder],
            'Challenge' => ['bg' => $challengeTint, 'border' => $challengeBorder],
            'Robot-Game' => ['bg' => $robotTint, 'border' => $challengeBorder],
            'Future 8+' => ['bg' => $future8Tint, 'border' => $future8Border],
            'Game' => ['bg' => $gameTint, 'border' => $future8Border],
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
