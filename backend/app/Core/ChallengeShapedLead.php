<?php

namespace App\Core;

/**
 * Challenge-shaped program that Core recipes drive (Challenge, or Future 8+ when Challenge is off).
 */
interface ChallengeShapedLead
{
    public function openingsAndBriefings(string $prefix): void;

    public function main(bool $explore = false, ?callable $afterRG1Callback = null): void;

    public function beginAfternoon(): void;

    public function presentations(): void;

    public function endAfternoon(): void;

    public function awards(string $prefix): void;
}
