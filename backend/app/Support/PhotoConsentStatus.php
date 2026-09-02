<?php

namespace App\Support;

final class PhotoConsentStatus
{
    public const PENDING = 'pending';

    public const GRANTED = 'granted';

    public const DENIED = 'denied';

    /**
     * @return array{status: string, check_in_label: string, self_service_message: string}
     */
    public static function forVolunteer(?bool $consent): array
    {
        if ($consent === true) {
            return self::payload(
                self::GRANTED,
                'Fotoerlaubnis liegt vor',
                'Danke für die Fotoerlaubnis.',
            );
        }

        if ($consent === false) {
            return self::payload(
                self::DENIED,
                'Fotoerlaubnis verweigert',
                'Schade, dass du die Fotoerlaubnis nicht erteilt hast. Wenn du deine Meinung noch ändern möchtest, melde dich einfach.',
            );
        }

        return self::payload(
            self::PENDING,
            'Fotoerlaubnis fehlt',
            'Bitte nicht vergessen, die Fotoerlaubnis zu schicken.',
        );
    }

    /**
     * @param  array<string, int>  $counts  keys unknown/yes/no
     * @return array{status: string, check_in_label: string, self_service_message: string, answered: int, people_count: int|null}
     */
    public static function forTeam(array $counts, ?int $peopleCount): array
    {
        $yes = (int) ($counts['yes'] ?? 0);
        $no = (int) ($counts['no'] ?? 0);
        $answered = $yes + $no;

        if ($no >= 1) {
            return array_merge(self::payload(
                self::DENIED,
                'Mindestens eine Fotoerlaubnis verweigert',
                'Schade, dass nicht alle die Fotoerlaubnis gegeben haben. Wenn sich das noch ändert, melde dich einfach.',
            ), [
                'answered' => $answered,
                'people_count' => $peopleCount,
            ]);
        }

        if ($peopleCount !== null && $peopleCount > 0 && $yes === $peopleCount) {
            return array_merge(self::payload(
                self::GRANTED,
                'Alle Fotoerlaubnisse liegen vor',
                'Alle Fotoerlaubnisse liegen vor. Danke!',
            ), [
                'answered' => $answered,
                'people_count' => $peopleCount,
            ]);
        }

        $y = $peopleCount ?? 0;
        $message = sprintf(
            'Bisher liegen %d von %d Fotoerlaubnissen vor. Bitte nicht vergessen, die restlichen zu schicken.',
            $answered,
            $y,
        );

        return array_merge(self::payload(
            self::PENDING,
            'Es fehlen Fotoerlaubnisse',
            $message,
        ), [
            'answered' => $answered,
            'people_count' => $peopleCount,
        ]);
    }

    /**
     * @return array{status: string, check_in_label: string, self_service_message: string}
     */
    private static function payload(string $status, string $checkInLabel, string $selfServiceMessage): array
    {
        return [
            'status' => $status,
            'check_in_label' => $checkInLabel,
            'self_service_message' => $selfServiceMessage,
        ];
    }
}
