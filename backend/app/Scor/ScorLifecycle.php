<?php

namespace App\Scor;

use App\Http\Controllers\Api\DrahtController;
use App\Mail\ScorJuryResultMail;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Close, release, coach mail, and the playground window before the event day.
 */
final class ScorLifecycle
{
    /**
     * @return array{closed: bool, released: bool, playground: bool, mail_body: string}
     */
    public function state(int $eventId): array
    {
        $row = $this->row($eventId);

        return [
            'closed' => $row?->closed_at !== null,
            'released' => $row?->released_at !== null,
            'playground' => $this->playground($eventId),
            'mail_body' => (string) ($row->mail_body ?? $this->defaultMail()),
        ];
    }

    public function close(User $user, int $eventId): array
    {
        $this->assertEventAdmin($user, $eventId);
        $this->touch($eventId, ['closed_at' => now()]);

        return $this->state($eventId);
    }

    public function release(User $user, int $eventId): array
    {
        if (!$user->isFlowAdmin()) {
            throw new ScorAccessException('Veröffentlichen geht nur mit dem FLOW-Admin.');
        }
        if (!$this->state($eventId)['closed']) {
            throw new ScorAccessException('Zuerst schließt die Veranstaltungs-Admin die Auswertung.', 422);
        }
        if ($this->playground($eventId)) {
            throw new ScorAccessException('Während der Spielwiese wird nichts veröffentlicht.', 422);
        }
        $this->touch($eventId, ['released_at' => now()]);

        return $this->state($eventId);
    }

    public function reopen(User $user, int $eventId): array
    {
        if (!$user->isFlowAdmin()) {
            throw new ScorAccessException('Wieder öffnen geht nur mit dem FLOW-Admin.');
        }
        $this->touch($eventId, ['closed_at' => null, 'released_at' => null]);

        return $this->state($eventId);
    }

    public function saveMail(User $user, int $eventId, string $body): array
    {
        $this->assertEventAdmin($user, $eventId);
        $this->touch($eventId, ['mail_body' => trim($body)]);

        return $this->state($eventId);
    }

    public function assertWritable(int $eventId): void
    {
        if ($this->state($eventId)['closed']) {
            throw new ScorAccessException('Die Auswertung ist geschlossen.', 423);
        }
    }

    public function assertPublic(int $eventId): void
    {
        $state = $this->state($eventId);
        if ($state['playground'] || !$state['released']) {
            throw new ScorAccessException('Die Ergebnisse sind nicht veröffentlicht.', 404);
        }
    }

    /**
     * @return array{sent: int, missing: list<string>}
     */
    public function sendCoachMail(User $user, int $eventId): array
    {
        $this->assertEventAdmin($user, $eventId);
        $state = $this->state($eventId);
        if (!$state['released']) {
            throw new ScorAccessException('Die Mail geht erst nach der Veröffentlichung.', 422);
        }

        $sent = 0;
        $missing = [];
        $event = DB::table('event')->where('id', $eventId)->first();
        $eventName = (string) ($event->name ?? 'Veranstaltung');
        foreach (DB::table('team')->where('event', $eventId)->get() as $team) {
            $emails = $this->coachEmails($eventId, (int) $team->id);
            if ($emails === []) {
                $missing[] = trim($team->team_number_hot.' '.$team->name);
                continue;
            }
            $pdf = Pdf::loadHTML($this->pdfHtml($eventName, $team))->output();
            $message = Mail::to($emails);
            if (is_string($user->email) && $user->email !== '') {
                $message->cc($user->email);
            }
            $message->send(new ScorJuryResultMail(
                $eventName,
                (string) $team->name,
                $state['mail_body'],
                $pdf,
            ));
            $sent++;
        }

        return ['sent' => $sent, 'missing' => $missing];
    }

    /**
     * Wipe practice sheets the day before the event.
     */
    public function resetPlaygroundDue(): int
    {
        if (!Schema::hasTable('event')) {
            return 0;
        }
        $tomorrow = now()->addDay()->toDateString();
        $events = DB::table('event')->whereDate('date', $tomorrow)->pluck('id');
        $count = 0;
        foreach ($events as $eventId) {
            $row = $this->row((int) $eventId);
            if ($row?->playground_reset_at) {
                continue;
            }
            foreach (['scor_sheet', 'scor_noteworthy', 'scor_stage_pick', 'scor_award'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('event', $eventId)->delete();
                }
            }
            $this->touch((int) $eventId, ['playground_reset_at' => now(), 'closed_at' => null, 'released_at' => null]);
            $count++;
        }

        return $count;
    }

    public function playground(int $eventId): bool
    {
        $date = DB::table('event')->where('id', $eventId)->value('date');
        if (!$date) {
            return false;
        }

        return now()->startOfDay()->lt(\Carbon\Carbon::parse($date)->startOfDay());
    }

    private function assertEventAdmin(User $user, int $eventId): void
    {
        if ($user->isFlowAdmin()) {
            return;
        }
        $admin = DB::table('event_user_access')
            ->where('user', $user->id)
            ->where('event', $eventId)
            ->where('can_scor_admin', true)
            ->exists();
        if (!$admin) {
            throw new ScorAccessException('Das geht nur mit dem Recht Veranstaltung-Admin.');
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function touch(int $eventId, array $values): void
    {
        if (!Schema::hasTable('scor_event')) {
            throw new ScorAccessException('Der Veranstaltungsstatus ist noch nicht eingerichtet.', 503);
        }
        if (DB::table('scor_event')->where('event', $eventId)->exists()) {
            DB::table('scor_event')->where('event', $eventId)->update($values);

            return;
        }
        DB::table('scor_event')->insert(array_merge(['event' => $eventId], $values));
    }

    private function row(int $eventId): ?object
    {
        if (!Schema::hasTable('scor_event')) {
            return null;
        }

        return DB::table('scor_event')->where('event', $eventId)->first();
    }

    /**
     * @return list<string>
     */
    private function coachEmails(int $eventId, int $teamId): array
    {
        $team = DB::table('team')->where('id', $teamId)->first();
        $hot = trim((string) ($team->team_number_hot ?? ''));
        $program = DB::table('event_program')->where('event', $eventId)->whereNotNull('draht_id')->first();
        if (!$team || $hot === '' || !$program) {
            return [];
        }
        try {
            $payload = app(DrahtController::class)->getPeople((int) $program->draht_id)->getData(true);
        } catch (\Throwable) {
            return [];
        }
        $teamData = is_array($payload) ? ($payload[$hot] ?? $payload[(int) $hot] ?? null) : null;
        if (!is_array($teamData)) {
            return [];
        }
        $emails = [];
        foreach ($teamData['coaches'] ?? [] as $coach) {
            $email = trim((string) (is_array($coach) ? ($coach['email'] ?? '') : ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    private function pdfHtml(string $eventName, object $team): string
    {
        $sheet = Schema::hasTable('scor_sheet')
            ? DB::table('scor_sheet')->where('event', $team->event)->where('kind', 'jury')->where('team', $team->id)->where('match', 0)->first()
            : null;
        $answers = [];
        if ($sheet && $sheet->answers) {
            $decoded = json_decode((string) $sheet->answers, true);
            $answers = is_array($decoded) ? ($decoded['texts'] ?? []) : [];
        }
        $lines = '';
        foreach ($answers as $text) {
            $lines .= '<p>'.e((string) $text).'</p>';
        }
        $points = (int) ($sheet->points ?? 0);
        $core = (int) ($sheet->core_points ?? 0);

        return '<h1>'.e($eventName).'</h1><h2>'.e(trim($team->team_number_hot.' '.$team->name)).'</h2>'
            .'<p>Jury: '.$points.' Punkte, davon Grundwerte '.$core.'.</p>'.$lines;
    }

    private function defaultMail(): string
    {
        return 'Anbei die Jurybewertung eures Teams. Die Ergebnisse sind veröffentlicht.';
    }
}
