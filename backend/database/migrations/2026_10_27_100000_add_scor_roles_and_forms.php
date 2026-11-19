<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addRoleColumns('user_regional_partner');
        $this->addRoleColumns('event_user_access');
        $this->addRoleColumns('event_invitation');
        $this->addRoleColumns('access_log', nullable: true);

        foreach (['user_regional_partner', 'event_user_access', 'event_invitation'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'can_scor_referee')) {
                continue;
            }
            DB::table($table)->where('can_scor', true)->update([
                'can_scor_referee' => true,
                'can_scor_jury' => true,
                'can_scor_admin' => true,
            ]);
        }

        if (!Schema::hasTable('scor_form')) {
            Schema::create('scor_form', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('season');
                $table->unsignedInteger('first_program');
                $table->string('kind', 16);
                $table->unique(['season', 'first_program', 'kind'], 'scor_form_season_program_kind');
            });
        }

        if (!Schema::hasTable('scor_section')) {
            Schema::create('scor_section', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('form');
                $table->unsignedInteger('sequence')->default(0);
                $table->string('title');
                $table->string('kind', 16);
                $table->text('note')->nullable();
                $table->foreign('form')->references('id')->on('scor_form')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('scor_item')) {
            Schema::create('scor_item', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('section');
                $table->unsignedInteger('sequence')->default(0);
                $table->string('item_type', 16);
                $table->string('label')->nullable();
                $table->text('hint')->nullable();
                $table->boolean('core_value')->default(false);
                $table->json('config')->nullable();
                $table->foreign('section')->references('id')->on('scor_section')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('scor_sheet')) {
            Schema::create('scor_sheet', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement();
                $table->unsignedInteger('event');
                $table->string('kind', 16);
                $table->unsignedInteger('team');
                $table->unsignedInteger('match')->default(0);
                $table->json('answers')->nullable();
                $table->integer('points')->default(0);
                $table->integer('core_points')->default(0);
                $table->boolean('confirmed_referee')->default(false);
                $table->boolean('confirmed_team')->default(false);
                $table->boolean('completed')->default(false);
                $table->timestamp('updated_at')->nullable();
                $table->unique(['event', 'kind', 'team', 'match'], 'scor_sheet_event_kind_team_match');
                $table->foreign('event')->references('id')->on('event')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scor_sheet');
        Schema::dropIfExists('scor_item');
        Schema::dropIfExists('scor_section');
        Schema::dropIfExists('scor_form');

        foreach (['access_log', 'event_invitation', 'event_user_access', 'user_regional_partner'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach (['can_scor_admin', 'can_scor_jury', 'can_scor_referee'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }

    private function addRoleColumns(string $table, bool $nullable = false): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $nullable) {
            if (!Schema::hasColumn($table, 'can_scor_referee')) {
                $column = $blueprint->boolean('can_scor_referee')->default(false);
                if ($nullable) {
                    $column->nullable();
                }
            }
            if (!Schema::hasColumn($table, 'can_scor_jury')) {
                $column = $blueprint->boolean('can_scor_jury')->default(false);
                if ($nullable) {
                    $column->nullable();
                }
            }
            if (!Schema::hasColumn($table, 'can_scor_admin')) {
                $column = $blueprint->boolean('can_scor_admin')->default(false);
                if ($nullable) {
                    $column->nullable();
                }
            }
        });
    }
};
