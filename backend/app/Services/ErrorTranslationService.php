<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class ErrorTranslationService
{
    /**
     * Translate SQL errors to user-friendly messages
     * 
     * @param \Throwable $exception
     * @return array{message: string, details: string|null}
     */
    public static function translateException(\Throwable $exception): array
    {
        // Log the full exception for debugging
        Log::error('Exception occurred', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'class' => get_class($exception),
        ]);

        // Handle QueryException (SQL errors)
        if ($exception instanceof QueryException) {
            return self::translateQueryException($exception);
        }

        // Handle other exceptions
        $exceptionClass = get_class($exception);
        $message = $exception->getMessage();

        // Check for common error patterns
        if (str_contains($message, 'SQLSTATE')) {
            return self::translateSqlError($message);
        }

        // Generic fallback
        return [
            'message' => 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es erneut oder kontaktieren Sie den Support.',
            'details' => null,
        ];
    }

    /**
     * Translate QueryException to user-friendly messages
     */
    private static function translateQueryException(QueryException $exception): array
    {
        $errorCode = $exception->getCode();
        $errorMessage = $exception->getMessage();
        $sqlState = $exception->errorInfo[0] ?? null;

        // SQLSTATE 23000: Integrity constraint violation
        if ($sqlState === '23000' || $sqlState === '23000') {
            // Foreign key constraint violation
            if (str_contains($errorMessage, '1451') || str_contains($errorMessage, 'Cannot delete or update a parent row')) {
                return [
                    'message' => 'Dieses Element kann nicht gelöscht werden, da es von anderen Elementen verwendet wird.',
                    'details' => 'Bitte entfernen Sie zuerst alle Abhängigkeiten, bevor Sie dieses Element löschen.',
                ];
            }
            
            // Foreign key constraint violation (child row)
            if (str_contains($errorMessage, '1452') || str_contains($errorMessage, 'Cannot add or update a child row')) {
                return [
                    'message' => 'Die Aktion kann nicht ausgeführt werden, da die erforderlichen Daten fehlen.',
                    'details' => 'Bitte überprüfen Sie, ob alle erforderlichen Informationen vorhanden sind.',
                ];
            }

            // Duplicate entry
            if (str_contains($errorMessage, '1062') || str_contains($errorMessage, 'Duplicate entry')) {
                // Try to extract the field name from the error
                if (preg_match("/Duplicate entry '.*?' for key '.*?\.(\w+)'/", $errorMessage, $matches)) {
                    $field = $matches[1] ?? 'Feld';
                    return [
                        'message' => "Ein Eintrag mit diesem Wert existiert bereits.",
                        'details' => "Bitte verwenden Sie einen anderen Wert für {$field}.",
                    ];
                }
                return [
                    'message' => 'Ein Eintrag mit diesem Wert existiert bereits.',
                    'details' => 'Bitte verwenden Sie einen anderen Wert.',
                ];
            }
        }

        // SQLSTATE 42S22: Column not found
        if ($sqlState === '42S22' || str_contains($errorMessage, 'Column not found')) {
            return [
                'message' => 'Die angeforderte Spalte wurde nicht gefunden.',
                'details' => 'Dieser Fehler tritt normalerweise bei Datenbank-Updates auf. Bitte kontaktieren Sie den Support.',
            ];
        }

        // SQLSTATE 42S02: Table not found
        if ($sqlState === '42S02' || str_contains($errorMessage, 'Table') && str_contains($errorMessage, "doesn't exist")) {
            return [
                'message' => 'Die angeforderte Tabelle wurde nicht gefunden.',
                'details' => 'Dieser Fehler tritt normalerweise bei Datenbank-Updates auf. Bitte kontaktieren Sie den Support.',
            ];
        }

        // Generic SQL error
        return [
            'message' => 'Ein Datenbankfehler ist aufgetreten.',
            'details' => 'Bitte versuchen Sie es erneut oder kontaktieren Sie den Support, wenn das Problem weiterhin besteht.',
        ];
    }

    /**
     * Translate SQL error message string
     */
    private static function translateSqlError(string $errorMessage): array
    {
        // Foreign key constraint violations
        if (str_contains($errorMessage, 'foreign key constraint fails')) {
            if (str_contains($errorMessage, 'Cannot delete')) {
                return [
                    'message' => 'Dieses Element kann nicht gelöscht werden, da es von anderen Elementen verwendet wird.',
                    'details' => 'Bitte entfernen Sie zuerst alle Abhängigkeiten.',
                ];
            }
            if (str_contains($errorMessage, 'Cannot add or update')) {
                return [
                    'message' => 'Die Aktion kann nicht ausgeführt werden, da die erforderlichen Daten fehlen.',
                    'details' => 'Bitte überprüfen Sie alle erforderlichen Informationen.',
                ];
            }
        }

        // Duplicate entry
        if (str_contains($errorMessage, 'Duplicate entry')) {
            return [
                'message' => 'Ein Eintrag mit diesem Wert existiert bereits.',
                'details' => 'Bitte verwenden Sie einen anderen Wert.',
            ];
        }

        // Column not found
        if (str_contains($errorMessage, 'Column') && (str_contains($errorMessage, 'not found') || str_contains($errorMessage, "doesn't exist"))) {
            return [
                'message' => 'Die angeforderte Spalte wurde nicht gefunden.',
                'details' => 'Dieser Fehler tritt normalerweise bei Datenbank-Updates auf. Bitte kontaktieren Sie den Support.',
            ];
        }

        // Table not found
        if (str_contains($errorMessage, 'Table') && (str_contains($errorMessage, "doesn't exist") || str_contains($errorMessage, 'not found'))) {
            return [
                'message' => 'Die angeforderte Tabelle wurde nicht gefunden.',
                'details' => 'Dieser Fehler tritt normalerweise bei Datenbank-Updates auf. Bitte kontaktieren Sie den Support.',
            ];
        }

        // Generic fallback
        return [
            'message' => 'Ein Datenbankfehler ist aufgetreten.',
            'details' => 'Bitte versuchen Sie es erneut oder kontaktieren Sie den Support.',
        ];
    }
}

