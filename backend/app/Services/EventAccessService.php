<?php

namespace App\Services;

use App\Mail\EventAccessInvitationMail;
use App\Models\User;
use App\Support\FlowAccess;
use App\Support\ScorGrant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Per-event invitations. The invited person needs an existing SSO account.
 * The invitation becomes a grant on the next request whose email Keycloak has verified.
 * Open invitations expire after 30 days. Draht membership is separate and is not touched here,
 * so removing a Draht contact leaves grants they created in place.
 */
class EventAccessService
{
    public const INVITATION_DAYS = 30;

    public const ACTION_INVITED = 'invited';

    public const ACTION_INVITATION_UPDATED = 'invitation_updated';

    public const ACTION_INVITATION_WITHDRAWN = 'invitation_withdrawn';

    public const ACTION_CLAIMED = 'claimed';

    public const ACTION_EXPIRED = 'expired';

    public const ACTION_PERMISSIONS_UPDATED = 'permissions_updated';

    public const ACTION_REVOKED = 'revoked';

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return array{id: int, mail_sent: bool}
     */
    public function invite(User $actor, int $eventId, int $regionalPartnerId, string $email, bool $canFlow, bool $canScor, ?ScorGrant $roles = null): array
    {
        $this->purgeExpired();

        $roles = $roles ?? ScorGrant::fromLegacy($canScor);
        $expiresAt = now()->addDays(self::INVITATION_DAYS);

        $id = DB::table('event_invitation')->insertGetId(array_merge([
            'event' => $eventId,
            'email' => $email,
            'can_flow' => $canFlow,
            'invited_by' => $actor->id,
            'invited_at' => now(),
            'expires_at' => $expiresAt,
        ], $roles->columns()));

        $this->writeLog($eventId, $regionalPartnerId, self::ACTION_INVITED, $actor->id, null, $email, $canFlow, $roles->any(), $roles);

        return [
            'id' => (int) $id,
            'mail_sent' => $this->sendInvitationMail($eventId, $email, $canFlow, $roles, $expiresAt),
        ];
    }

    public function updateInvitation(int $invitationId, int $eventId, int $regionalPartnerId, User $actor, bool $canFlow, bool $canScor, ?ScorGrant $roles = null): void
    {
        $invitation = DB::table('event_invitation')->where('id', $invitationId)->first();
        $roles = $roles ?? ScorGrant::fromLegacy($canScor);

        DB::table('event_invitation')->where('id', $invitationId)->update(array_merge([
            'can_flow' => $canFlow,
        ], $roles->columns()));

        $this->writeLog(
            $eventId,
            $regionalPartnerId,
            self::ACTION_INVITATION_UPDATED,
            $actor->id,
            null,
            $invitation->email ?? null,
            $canFlow,
            $roles->any(),
            $roles,
        );
    }

    public function withdrawInvitation(int $invitationId, int $eventId, int $regionalPartnerId, User $actor): void
    {
        $invitation = DB::table('event_invitation')->where('id', $invitationId)->first();
        DB::table('event_invitation')->where('id', $invitationId)->delete();

        $this->writeLog(
            $eventId,
            $regionalPartnerId,
            self::ACTION_INVITATION_WITHDRAWN,
            $actor->id,
            null,
            $invitation->email ?? null,
            (bool) ($invitation->can_flow ?? false),
            (bool) ($invitation->can_scor ?? false),
        );
    }

    public function updateGrant(int $grantId, int $eventId, int $regionalPartnerId, User $actor, int $targetUserId, bool $canFlow, bool $canScor, ?ScorGrant $roles = null): void
    {
        $roles = $roles ?? ScorGrant::fromLegacy($canScor);

        DB::table('event_user_access')->where('id', $grantId)->update(array_merge([
            'can_flow' => $canFlow,
        ], $roles->columns()));

        $this->writeLog(
            $eventId,
            $regionalPartnerId,
            self::ACTION_PERMISSIONS_UPDATED,
            $actor->id,
            $targetUserId,
            $this->emailOf($targetUserId),
            $canFlow,
            $roles->any(),
            $roles,
        );
    }

    public function revokeGrant(int $grantId, int $eventId, int $regionalPartnerId, User $actor, int $targetUserId, bool $canFlow, bool $canScor): void
    {
        DB::table('event_user_access')->where('id', $grantId)->delete();

        $this->writeLog(
            $eventId,
            $regionalPartnerId,
            self::ACTION_REVOKED,
            $actor->id,
            $targetUserId,
            $this->emailOf($targetUserId),
            $canFlow,
            $canScor,
        );
    }

    /**
     * @return int number of invitations turned into grants
     */
    public function claim(User $user, array $claims): int
    {
        $this->purgeExpired();

        $email = $claims['email'] ?? null;
        if (!is_string($email) || trim($email) === '' || ($claims['email_verified'] ?? false) !== true) {
            return 0;
        }

        $invitations = DB::table('event_invitation')
            ->where('email', self::normalizeEmail($email))
            ->get();

        if ($invitations->isEmpty()) {
            return 0;
        }

        foreach ($invitations as $invitation) {
            DB::transaction(function () use ($user, $invitation) {
                $current = DB::table('event_invitation')->where('id', $invitation->id)->first();
                if (!$current) {
                    return;
                }

                $existing = DB::table('event_user_access')
                    ->where('user', $user->id)
                    ->where('event', $current->event)
                    ->first();

                $canFlow = (bool) $current->can_flow || (bool) ($existing->can_flow ?? false);
                $roles = ScorGrant::fromRow($current)->or(ScorGrant::fromRow($existing));

                if (!$existing) {
                    DB::table('event_user_access')->insert(array_merge([
                        'user' => $user->id,
                        'event' => $current->event,
                        'can_flow' => $canFlow,
                        'granted_at' => $current->invited_at ?? now(),
                        'granted_by' => $current->invited_by,
                    ], $roles->columns()));
                } else {
                    DB::table('event_user_access')->where('id', $existing->id)->update(array_merge([
                        'can_flow' => $canFlow,
                    ], $roles->columns()));
                }

                DB::table('event_invitation')->where('id', $current->id)->delete();

                $this->writeLog(
                    (int) $current->event,
                    $this->regionalPartnerOfEvent((int) $current->event),
                    self::ACTION_CLAIMED,
                    $current->invited_by ? (int) $current->invited_by : null,
                    (int) $user->id,
                    self::normalizeEmail((string) $current->email),
                    $canFlow,
                    $roles->any(),
                    $roles,
                );
            });
        }

        Log::info('Event invitations claimed', [
            'user_id' => $user->id,
            'events' => $invitations->pluck('event')->all(),
        ]);

        return $invitations->count();
    }

    public function purgeExpired(): int
    {
        $expired = DB::table('event_invitation')->where('expires_at', '<', now())->get();
        foreach ($expired as $invitation) {
            DB::table('event_invitation')->where('id', $invitation->id)->delete();
            $this->writeLog(
                (int) $invitation->event,
                $this->regionalPartnerOfEvent((int) $invitation->event),
                self::ACTION_EXPIRED,
                $invitation->invited_by ? (int) $invitation->invited_by : null,
                null,
                (string) $invitation->email,
                (bool) $invitation->can_flow,
                (bool) $invitation->can_scor,
            );
        }

        return $expired->count();
    }

    public function emailHasAccess(int $eventId, string $email): bool
    {
        $email = self::normalizeEmail($email);
        $regionalPartnerId = $this->regionalPartnerOfEvent($eventId);

        $knownUser = DB::table('user')->whereRaw('LOWER(email) = ?', [$email])->first();
        if (!$knownUser) {
            return false;
        }

        $onEvent = DB::table('event_user_access')
            ->where('user', $knownUser->id)
            ->where('event', $eventId)
            ->exists();
        if ($onEvent) {
            return true;
        }

        if ($regionalPartnerId <= 0) {
            return false;
        }

        return DB::table('user_regional_partner')
            ->where('user', $knownUser->id)
            ->where('regional_partner', $regionalPartnerId)
            ->where('source', FlowAccess::SOURCE_DRAHT)
            ->exists();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingForEvent(int $eventId): array
    {
        $this->purgeExpired();

        return DB::table('event_invitation as i')
            ->leftJoin('user as inviter', 'i.invited_by', '=', 'inviter.id')
            ->where('i.event', $eventId)
            ->orderBy('i.email')
            ->get([
                'i.id',
                'i.email',
                'i.can_flow',
                'i.can_scor',
                'i.can_scor_referee',
                'i.can_scor_jury',
                'i.can_scor_admin',
                'i.invited_at',
                'i.expires_at',
                'inviter.name as invited_by_name',
            ])
            ->map(function ($row) {
                $roles = ScorGrant::fromRow($row);

                return [
                    'id' => (int) $row->id,
                    'email' => $row->email,
                    'can_flow' => (bool) $row->can_flow,
                    ...$roles->columns(),
                    'invited_at' => $row->invited_at,
                    'expires_at' => $row->expires_at,
                    'invited_by_name' => $row->invited_by_name,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function logForEvent(int $eventId, int $limit = 50): array
    {
        return $this->logQuery()
            ->where('access_log.event', $eventId)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->logRow($row))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function logRecent(int $limit = 200): array
    {
        return $this->logQuery()
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->logRow($row))
            ->all();
    }

    private function logQuery()
    {
        return DB::table('access_log')
            ->leftJoin('user as actor', 'access_log.actor_user', '=', 'actor.id')
            ->leftJoin('user as target', 'access_log.target_user', '=', 'target.id')
            ->leftJoin('event', 'access_log.event', '=', 'event.id')
            ->orderByDesc('access_log.id')
            ->select([
                'access_log.id',
                'access_log.created_at',
                'access_log.event',
                'access_log.action',
                'access_log.target_email',
                'access_log.can_flow',
                'access_log.can_scor',
                'actor.name as actor_name',
                'target.name as target_name',
                'event.name as event_name',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function logRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'created_at' => $row->created_at,
            'event_id' => $row->event ? (int) $row->event : null,
            'event_name' => $row->event_name,
            'action' => $row->action,
            'actor_name' => $row->actor_name,
            'target_name' => $row->target_name,
            'target_email' => $row->target_email,
            'can_flow' => $row->can_flow === null ? null : (bool) $row->can_flow,
            'can_scor' => $row->can_scor === null ? null : (bool) $row->can_scor,
        ];
    }

    private function sendInvitationMail(int $eventId, string $email, bool $canFlow, ScorGrant $roles, Carbon $expiresAt): bool
    {
        $permissions = array_values(array_filter([
            $canFlow ? 'FLOW' : null,
            $roles->referee ? 'Schiri' : null,
            $roles->jury ? 'Jury' : null,
            $roles->admin ? 'Veranstaltung-Admin' : null,
        ]));

        $loginUrl = rtrim((string) config('app.url'), '/');
        if (!$canFlow && $roles->any()) {
            $loginUrl .= '/scor';
        }

        try {
            Mail::to($email)->send(new EventAccessInvitationMail(
                eventName: $this->eventName($eventId),
                permissions: $permissions,
                loginUrl: $loginUrl,
                expiresOn: $expiresAt->timezone(config('app.timezone'))->format('d.m.Y'),
            ));

            return true;
        } catch (\Throwable $e) {
            Log::error('Event invitation mail failed', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function grantUser(User $actor, int $eventId, int $userId, ScorGrant $roles, bool $canFlow = false): void
    {
        $existing = DB::table('event_user_access')
            ->where('user', $userId)
            ->where('event', $eventId)
            ->first();
        $merged = $roles->or(ScorGrant::fromRow($existing));
        $flow = $canFlow || (bool) ($existing->can_flow ?? false);

        if (!$existing) {
            DB::table('event_user_access')->insert(array_merge([
                'user' => $userId,
                'event' => $eventId,
                'can_flow' => $flow,
                'granted_at' => now(),
                'granted_by' => $actor->id,
            ], $merged->columns()));
        } else {
            DB::table('event_user_access')->where('id', $existing->id)->update(array_merge([
                'can_flow' => $flow,
            ], $merged->columns()));
        }

        $this->writeLog(
            $eventId,
            $this->regionalPartnerOfEvent($eventId),
            self::ACTION_PERMISSIONS_UPDATED,
            $actor->id,
            $userId,
            $this->emailOf($userId),
            $flow,
            $merged->any(),
            $merged,
        );
    }

    private function eventName(int $eventId): string
    {
        $name = trim((string) DB::table('event')->where('id', $eventId)->value('name'));

        return $name !== '' ? $name : 'Veranstaltung';
    }

    private function regionalPartnerOfEvent(int $eventId): int
    {
        return (int) DB::table('event')->where('id', $eventId)->value('regional_partner');
    }

    private function emailOf(int $userId): ?string
    {
        $email = DB::table('user')->where('id', $userId)->value('email');

        return is_string($email) && $email !== '' ? self::normalizeEmail($email) : null;
    }

    private function writeLog(
        ?int $eventId,
        ?int $regionalPartnerId,
        string $action,
        ?int $actorUserId,
        ?int $targetUserId,
        ?string $targetEmail,
        ?bool $canFlow,
        ?bool $canScor,
        ?ScorGrant $roles = null,
    ): void {
        $roles = $roles ?? ($canScor === null ? null : ScorGrant::fromLegacy((bool) $canScor));
        $row = [
            'created_at' => now(),
            'event' => $eventId,
            'regional_partner' => $regionalPartnerId,
            'actor_user' => $actorUserId,
            'target_user' => $targetUserId,
            'target_email' => $targetEmail,
            'action' => $action,
            'can_flow' => $canFlow,
            'can_scor' => $roles?->any() ?? $canScor,
        ];
        if ($roles) {
            $row['can_scor_referee'] = $roles->referee;
            $row['can_scor_jury'] = $roles->jury;
            $row['can_scor_admin'] = $roles->admin;
        }

        DB::table('access_log')->insert($row);
    }
}
