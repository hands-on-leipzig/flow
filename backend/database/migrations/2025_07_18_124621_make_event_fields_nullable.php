<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('event', function (Blueprint $table) {
            $table->date('date')->nullable()->change();
            $table->unsignedTinyInteger('days')->nullable()->change();
            $table->unsignedInteger('regional_partner')->nullable()->change();
        });

// If you also want to allow deleting partners and setting event.regional_partner to NULL
        DB::statement('ALTER TABLE event DROP FOREIGN KEY event_ibfk_1');
        Schema::table('event', function (Blueprint $table) {
            $table->foreign('regional_partner')
                ->references('id')->on('regional_partner')
                ->nullOnDelete(); // Laravel's fluent way for ON DELETE SET NULL
        });
    }

    public function down(): void
    {
        Schema::table('event', function (Blueprint $table) {
// Rollback changes
            $table->date('date')->nullable(false)->change();
            $table->unsignedTinyInteger('days')->nullable(false)->change();
            $table->unsignedInteger('regional_partner')->nullable(false)->change();

            $table->dropForeign(['regional_partner']);
            $table->foreign('regional_partner')
                ->references('id')->on('regional_partner')
                ->onDelete('no action'); // Restore original constraint
        });
    }
};
