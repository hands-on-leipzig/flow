<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('event', 'check_in_enabled')) {
            Schema::table('event', function (Blueprint $table) {
                $table->boolean('check_in_enabled')->default(false)->after('public_helper_search');
                $table->text('check_in_pin')->nullable()->after('check_in_enabled');
                $table->text('check_in_text_teams')->nullable()->after('check_in_pin');
                $table->text('check_in_text_helpers')->nullable()->after('check_in_text_teams');
            });
        }

        if (! Schema::hasTable('check_in')) {
            Schema::create('check_in', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('event');
                $table->string('subject_type', 16); // team | volunteer
                $table->unsignedBigInteger('subject_id');
                $table->string('status', 16); // checked_in | no_show
                $table->timestamp('checked_in_at')->nullable();
                $table->text('reception_note')->nullable();
                $table->text('no_show_reason')->nullable();
                $table->text('no_show_source')->nullable();
                $table->timestamps();

                $table->unique(['event', 'subject_type', 'subject_id'], 'check_in_event_subject_unique');
                $table->index(['event', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('check_in');

        if (Schema::hasColumn('event', 'check_in_enabled')) {
            Schema::table('event', function (Blueprint $table) {
                $table->dropColumn([
                    'check_in_enabled',
                    'check_in_pin',
                    'check_in_text_teams',
                    'check_in_text_helpers',
                ]);
            });
        }
    }
};
