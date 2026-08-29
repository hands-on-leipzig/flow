<?php

namespace App\Support;

use App\Enums\FirstProgram;

/**
 * Maps extra_block.first_program to m_activity_type_detail.code for free vs slot materialization.
 */
final class ExtraBlockActivityTypeCode
{
    /** @return list<string> */
    public static function allFreeCodes(): array
    {
        return [
            'c_free_block',
            'e_free_block',
            'g_free_block',
            'f8_free_block',
        ];
    }

    /** @return list<string> */
    public static function allSlotCodes(): array
    {
        return [
            'c_slot_block',
            'e_slot_block',
            'g_slot_block',
            'f8_slot_block',
        ];
    }

    public static function forFree(int $firstProgram): string
    {
        return match ($firstProgram) {
            FirstProgram::CHALLENGE->value => 'c_free_block',
            FirstProgram::EXPLORE->value => 'e_free_block',
            FirstProgram::FUTURE_8->value => 'f8_free_block',
            FirstProgram::JOINT->value => 'g_free_block',
            default => 'g_free_block',
        };
    }

    public static function forSlot(int $firstProgram): string
    {
        return match ($firstProgram) {
            FirstProgram::CHALLENGE->value => 'c_slot_block',
            FirstProgram::EXPLORE->value => 'e_slot_block',
            FirstProgram::FUTURE_8->value => 'f8_slot_block',
            FirstProgram::JOINT->value => 'g_slot_block',
            default => 'g_slot_block',
        };
    }
}
