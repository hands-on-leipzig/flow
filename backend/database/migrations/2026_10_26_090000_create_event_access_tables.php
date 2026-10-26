<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('event_user_access')) {
            Schema::create('event_user_access', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('user');
                $table->unsignedInteger('event');
                $table->boolean('can_flow')->default(false);
                $table->boolean('can_scor')->default(false);
                $table->timestamp('granted_at')->nullable();
                $table->unsignedInteger('granted_by')->nullable();

                $table->unique(['user', 'event'], 'eua_user_event_unique');
                $table->foreign('user')->references('id')->on('user')->onDelete('cascade');
                $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('event_invitation')) {
            Schema::create('event_invitation', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->string('email');
                $table->boolean('can_flow')->default(false);
                $table->boolean('can_scor')->default(false);
                $table->unsignedInteger('invited_by')->nullable();
                $table->timestamp('invited_at')->nullable();
                $table->timestamp('expires_at')->nullable();

                $table->unique(['event', 'email'], 'einv_event_email_unique');
                $table->index('email', 'einv_email_index');
                $table->index('expires_at', 'einv_expires_index');
                $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
                $table->foreign('invited_by')->references('id')->on('user')->onDelete('set null');
            });
        }

        if (!Schema::hasTable('access_log')) {
            Schema::create('access_log', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->timestamp('created_at')->nullable();
                $table->unsignedInteger('event')->nullable();
                $table->unsignedInteger('regional_partner')->nullable();
                $table->unsignedInteger('actor_user')->nullable();
                $table->unsignedInteger('target_user')->nullable();
                $table->string('target_email')->nullable();
                $table->string('action', 32);
                $table->boolean('can_flow')->nullable();
                $table->boolean('can_scor')->nullable();

                $table->index('event', 'alog_event_index');
                $table->index('created_at', 'alog_created_index');
            });
        }

        $this->moveManualGrantsOntoEvents();
        $this->moveRegionalPartnerInvitationsOntoEvents();
    }

    public function down(): void
    {
        Schema::dropIfExists('access_log');
        Schema::dropIfExists('event_invitation');
        Schema::dropIfExists('event_user_access');
    }

    /**
     * Older manual grants covered every event of the regional partner.
     * Keep that reach by copying one row per current event, then drop the partner-wide grant.
     * Draht rows stay: they still cover every event, including ones created later.
     */
    private function moveManualGrantsOntoEvents(): void
    {
        if (!Schema::hasColumn('user_regional_partner', 'source')) {
            return;
        }

        $manual = DB::table('user_regional_partner')->where('source', 'manual')->get();
        foreach ($manual as $row) {
            $eventIds = DB::table('event')
                ->where('regional_partner', $row->regional_partner)
                ->pluck('id');

            foreach ($eventIds as $eventId) {
                $exists = DB::table('event_user_access')
                    ->where('user', $row->user)
                    ->where('event', $eventId)
                    ->exists();
                if ($exists) {
                    continue;
                }

                DB::table('event_user_access')->insert([
                    'user' => $row->user,
                    'event' => $eventId,
                    'can_flow' => (bool) ($row->can_flow ?? true),
                    'can_scor' => (bool) ($row->can_scor ?? false),
                    'granted_at' => $row->granted_at ?? now(),
                    'granted_by' => $row->granted_by ?? null,
                ]);
            }
        }

        DB::table('user_regional_partner')->where('source', 'manual')->delete();
    }

    private function moveRegionalPartnerInvitationsOntoEvents(): void
    {
        if (!Schema::hasTable('regional_partner_invitation')) {
            return;
        }

        $invitations = DB::table('regional_partner_invitation')->get();
        foreach ($invitations as $invitation) {
            $eventIds = DB::table('event')
                ->where('regional_partner', $invitation->regional_partner)
                ->pluck('id');
            $invitedAt = $invitation->invited_at ?? now();

            foreach ($eventIds as $eventId) {
                $exists = DB::table('event_invitation')
                    ->where('event', $eventId)
                    ->where('email', $invitation->email)
                    ->exists();
                if ($exists) {
                    continue;
                }

                DB::table('event_invitation')->insert([
                    'event' => $eventId,
                    'email' => $invitation->email,
                    'can_flow' => (bool) $invitation->can_flow,
                    'can_scor' => (bool) $invitation->can_scor,
                    'invited_by' => $invitation->invited_by,
                    'invited_at' => $invitedAt,
                    'expires_at' => \Illuminate\Support\Carbon::parse($invitedAt)->addDays(30),
                ]);
            }
        }

        Schema::drop('regional_partner_invitation');
    }
};
