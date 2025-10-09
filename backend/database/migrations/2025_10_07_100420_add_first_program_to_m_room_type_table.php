<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
{
    Schema::table('m_room_type', function (Blueprint $table) {
        $table->unsignedTinyInteger('first_program')
              ->default(0)
              ->after('level'); // oder an andere passende Stelle
    });
}

public function down()
{
    Schema::table('m_room_type', function (Blueprint $table) {
        $table->dropColumn('first_program');
    });
}
};
