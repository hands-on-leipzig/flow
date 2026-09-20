<?php

namespace App\Support;

class MailFrom
{
    /**
     * Split `Name <addr@domain>` into address + name.
     *
     * @return array{address: string, name: string}
     */
    public static function parse(?string $address, ?string $name = null): array
    {
        $address = trim((string) $address);
        $name = trim((string) $name);

        if (preg_match('/^(?:"([^"]+)"|([^<]*?))\s*<([^>]+)>\s*$/', $address, $matches) === 1) {
            $extractedName = trim($matches[1] !== '' ? $matches[1] : $matches[2]);
            $address = trim($matches[3]);
            if ($name === '') {
                $name = $extractedName;
            }
        }

        return [
            'address' => $address,
            'name' => $name,
        ];
    }
}
