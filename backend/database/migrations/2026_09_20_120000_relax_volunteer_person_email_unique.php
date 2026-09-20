<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE = 'volunteer_person_rp_email_unique';

    private const LOOKUP = 'volunteer_person_rp_email_index';

    public function up(): void
    {
        if (! Schema::hasTable('volunteer_person') || ! Schema::hasColumn('volunteer_person', 'email')) {
            return;
        }

        if (Schema::hasIndex('volunteer_person', self::UNIQUE)) {
            Schema::table('volunteer_person', function (Blueprint $table) {
                $table->dropUnique(self::UNIQUE);
            });
        }

        Schema::table('volunteer_person', function (Blueprint $table) {
            $table->string('email', 255)->nullable()->change();
        });

        if (! Schema::hasIndex('volunteer_person', self::LOOKUP)) {
            Schema::table('volunteer_person', function (Blueprint $table) {
                $table->index(['regional_partner', 'email'], self::LOOKUP);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('volunteer_person') || ! Schema::hasColumn('volunteer_person', 'email')) {
            return;
        }

        if (Schema::hasIndex('volunteer_person', self::LOOKUP)) {
            Schema::table('volunteer_person', function (Blueprint $table) {
                $table->dropIndex(self::LOOKUP);
            });
        }

        Schema::table('volunteer_person', function (Blueprint $table) {
            $table->string('email', 255)->nullable(false)->change();
        });

        if (! Schema::hasIndex('volunteer_person', self::UNIQUE)) {
            Schema::table('volunteer_person', function (Blueprint $table) {
                $table->unique(['regional_partner', 'email'], self::UNIQUE);
            });
        }
    }
};
