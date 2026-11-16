<?php

namespace App\Support;

use App\Mail\TeamPlayersChangedMail;
use App\Models\Event;
use App\Models\Team;
use App\Services\EventSlugService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the coach and co-coaches that a regional partner changed the roster.
 * The message carries the team name and a FLOW link, never member details.
 */
class TeamPlayersChangeMailer
{
    public function __construct(
        private readonly EventSlugService $slugs,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $coaches  DRAHT coach rows (email optional)
     */
    public function send(Event $event, Team $team, array $coaches): int
    {
        $flowUrl = $this->flowUrl($event);
        $sent = 0;
        foreach ($this->recipients($coaches) as $email) {
            try {
                Mail::to($email)->send(new TeamPlayersChangedMail(
                    eventName: (string) $event->name,
                    teamName: (string) $team->name,
                    flowUrl: $flowUrl,
                ));
                $sent++;
            } catch (Throwable $e) {
                Log::error('Team roster change mail failed', [
                    'event_id' => $event->id,
                    'team_id' => $team->id,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * @param  list<array<string, mixed>>  $coaches
     * @return list<string>
     */
    private function recipients(array $coaches): array
    {
        $emails = [];
        foreach ($coaches as $coach) {
            if (! is_array($coach)) {
                continue;
            }
            $email = strtolower(trim((string) ($coach['email'] ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $emails[$email] = $email;
        }

        return array_values($emails);
    }

    private function flowUrl(Event $event): string
    {
        try {
            $url = $this->slugs->url($event);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        } catch (Throwable) {
            // The season catalog is optional. The public host and slug still open FLOW.
        }

        $base = rtrim((string) config('app.public_url', 'https://handson.tools'), '/');
        $slug = trim((string) ($event->slug ?? ''));

        return $slug !== '' ? $base.'/'.$slug : $base;
    }
}
