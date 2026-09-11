<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * At most one ICS rebuild in flight. MySQL GET_LOCK lives on a dedicated
 * connection so the default connection can disconnect during DRAHT HTTP.
 * SQLite uses a process-local flag so unit tests can stay on sqlite.
 */
class CalendarRebuildLock
{
    public const NAME = 'flow_ics_rebuild';

    private const CONNECTION = 'flow_ics_lock';

    private ?Connection $lockConnection = null;

    private static bool $memoryHeld = false;

    public function tryAcquire(): bool
    {
        if (! $this->supportsMysqlLock()) {
            if (self::$memoryHeld) {
                return false;
            }
            self::$memoryHeld = true;

            return true;
        }

        $this->ensureLockConnection();
        $row = $this->lockConnection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [self::NAME]);
        $acquired = $row !== null && (int) $row->acquired === 1;
        if (! $acquired) {
            $this->disconnectLockConnection();
        }

        return $acquired;
    }

    public function release(): void
    {
        if (! $this->supportsMysqlLock()) {
            self::$memoryHeld = false;

            return;
        }

        if ($this->lockConnection === null) {
            return;
        }

        try {
            $this->lockConnection->selectOne('SELECT RELEASE_LOCK(?) AS released', [self::NAME]);
        } finally {
            $this->disconnectLockConnection();
        }
    }

    public static function resetMemoryLock(): void
    {
        self::$memoryHeld = false;
    }

    private function supportsMysqlLock(): bool
    {
        $driver = DB::getDriverName();

        return $driver === 'mysql' || $driver === 'mariadb';
    }

    private function ensureLockConnection(): void
    {
        if ($this->lockConnection !== null) {
            return;
        }

        $default = (string) config('database.default');
        $config = config("database.connections.{$default}");
        config(['database.connections.'.self::CONNECTION => $config]);
        DB::purge(self::CONNECTION);
        $this->lockConnection = DB::connection(self::CONNECTION);
    }

    private function disconnectLockConnection(): void
    {
        if ($this->lockConnection === null) {
            return;
        }

        $this->lockConnection->disconnect();
        $this->lockConnection = null;
        DB::purge(self::CONNECTION);
    }
}
