<?php

namespace App\Scor;

use App\Models\User;
use App\Support\ScorGrant;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Event accounts such as "Schiri 1". They stay in SCOR and are not HERO users.
 */
final class ScorLocalAccounts
{
    public const ISSUER = 'scor-local';

    public function create(User $actor, int $eventId, string $username, ScorGrant $roles): array
    {
        $this->assertManager($actor, $eventId);
        if (!$roles->any()) {
            throw new ScorAccessException('Mindestens eine Berechtigung wählen.', 422);
        }
        $username = $this->normalize($username);
        if ($username === '') {
            throw new ScorAccessException('Bitte einen Namen eintragen.', 422);
        }
        if (DB::table('scor_local_account')->whereRaw('lower(username) = ?', [mb_strtolower($username)])->exists()) {
            throw new ScorAccessException('Dieser Name ist schon vergeben.', 422);
        }

        $password = $this->password();
        $userId = DB::table('user')->insertGetId([
            'subject' => 'scor-local-tmp-'.bin2hex(random_bytes(8)),
            'name' => $username,
            'email' => null,
        ]);
        $accountId = DB::table('scor_local_account')->insertGetId([
            'event' => $eventId,
            'user' => $userId,
            'username' => $username,
            'password' => Hash::make($password),
        ]);
        DB::table('user')->where('id', $userId)->update([
            'subject' => 'scor-local-'.$accountId,
        ]);
        DB::table('event_user_access')->insert(array_merge([
            'user' => $userId,
            'event' => $eventId,
            'can_flow' => false,
            'granted_at' => now(),
            'granted_by' => $actor->id,
        ], $roles->columns()));

        return [
            'id' => (int) $accountId,
            'username' => $username,
            'password' => $password,
            'roles' => $roles->columns(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $eventId): array
    {
        if (!Schema::hasTable('scor_local_account')) {
            return [];
        }

        return DB::table('scor_local_account as a')
            ->join('event_user_access as g', function ($join) {
                $join->on('g.user', '=', 'a.user')->on('g.event', '=', 'a.event');
            })
            ->where('a.event', $eventId)
            ->orderBy('a.username')
            ->get([
                'a.id',
                'a.username',
                'g.can_scor_referee',
                'g.can_scor_jury',
                'g.can_scor_admin',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'username' => $row->username,
                'can_scor_referee' => (bool) $row->can_scor_referee,
                'can_scor_jury' => (bool) $row->can_scor_jury,
                'can_scor_admin' => (bool) $row->can_scor_admin,
            ])
            ->all();
    }

    public function resetPassword(User $actor, int $eventId, int $accountId): array
    {
        $this->assertManager($actor, $eventId);
        $account = DB::table('scor_local_account')->where('id', $accountId)->where('event', $eventId)->first();
        if (!$account) {
            throw new ScorAccessException('Konto nicht gefunden.', 404);
        }
        $password = $this->password();
        DB::table('scor_local_account')->where('id', $account->id)->update([
            'password' => Hash::make($password),
        ]);

        return ['username' => $account->username, 'password' => $password];
    }

    /**
     * @return array{token: string, username: string, event_id: int}
     */
    public function login(string $username, string $password): array
    {
        $account = DB::table('scor_local_account')
            ->whereRaw('lower(username) = ?', [mb_strtolower(trim($username))])
            ->first();
        if (!$account || !Hash::check($password, (string) $account->password)) {
            throw new ScorAccessException('Name oder Passwort stimmt nicht.', 401);
        }

        $token = JWT::encode([
            'iss' => self::ISSUER,
            'sub' => (string) $account->user,
            'account' => (int) $account->id,
            'event' => (int) $account->event,
            'exp' => time() + 60 * 60 * 14,
        ], $this->key(), 'HS256');

        return [
            'token' => $token,
            'username' => $account->username,
            'event_id' => (int) $account->event,
        ];
    }

    /**
     * @return array{user: int, account: int, event: int}|null
     */
    public function claims(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->key(), 'HS256'));
        } catch (\Throwable) {
            return null;
        }
        $claims = (array) $decoded;
        if (($claims['iss'] ?? '') !== self::ISSUER) {
            return null;
        }

        return [
            'user' => (int) ($claims['sub'] ?? 0),
            'account' => (int) ($claims['account'] ?? 0),
            'event' => (int) ($claims['event'] ?? 0),
        ];
    }

    public function assertManager(User $actor, int $eventId): void
    {
        if ($actor->isFlowAdmin()) {
            return;
        }
        $partner = (int) DB::table('event')->where('id', $eventId)->value('regional_partner');
        if ($partner > 0 && $actor->canManageRegionalPartner($partner)) {
            return;
        }
        $admin = DB::table('event_user_access')
            ->where('user', $actor->id)
            ->where('event', $eventId)
            ->where('can_scor_admin', true)
            ->exists();
        if (!$admin) {
            throw new ScorAccessException('Konten anlegen geht nur mit dem Recht Veranstaltung-Admin.');
        }
    }

    private function normalize(string $username): string
    {
        $username = trim(preg_replace('/\s+/', ' ', $username) ?? '');
        if (mb_strlen($username) > 40) {
            throw new ScorAccessException('Der Name darf höchstens 40 Zeichen haben.', 422);
        }

        return $username;
    }

    private function password(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
        $length = strlen($alphabet);
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= $alphabet[random_int(0, $length - 1)];
        }

        return $out;
    }

    private function key(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded !== false ? $decoded : $key;
        }

        return $key;
    }
}
