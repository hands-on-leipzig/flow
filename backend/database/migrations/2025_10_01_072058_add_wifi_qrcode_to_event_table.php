<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up()
{
    Schema::table('event', function (Blueprint $table) {
        $table->longText('wifi_qrcode')->nullable()->after('wifi_instruction');
    });
}

public function down()
{
    Schema::table('event', function (Blueprint $table) {
        $table->dropColumn('wifi_qrcode');
    });
}
};
