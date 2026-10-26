<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_regional_partner', function (Blueprint $table) {
            if (!Schema::hasColumn('user_regional_partner', 'can_flow')) {
                $table->boolean('can_flow')->default(true)->after('source');
            }
            if (!Schema::hasColumn('user_regional_partner', 'can_scor')) {
                $table->boolean('can_scor')->default(false)->after('can_flow');
            }
        });

        // Draht contacts are the regional partner itself: they get every app.
        DB::table('user_regional_partner')
            ->where('source', 'draht')
            ->update(['can_flow' => true, 'can_scor' => true]);

        if (!Schema::hasTable('regional_partner_invitation')) {
            Schema::create('regional_partner_invitation', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('regional_partner');
                $table->string('email');
                $table->boolean('can_flow')->default(true);
                $table->boolean('can_scor')->default(false);
                $table->unsignedInteger('invited_by')->nullable();
                $table->timestamp('invited_at')->nullable();

                $table->unique(['regional_partner', 'email'], 'rpi_rp_email_unique');
                $table->index('email', 'rpi_email_index');
                $table->foreign('regional_partner')->references('id')->on('regional_partner')->onDelete('cascade');
                $table->foreign('invited_by')->references('id')->on('user')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('regional_partner_invitation');

        Schema::table('user_regional_partner', function (Blueprint $table) {
            $cols = array_filter(['can_scor', 'can_flow'], fn ($c) => Schema::hasColumn('user_regional_partner', $c));
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
    }
};
