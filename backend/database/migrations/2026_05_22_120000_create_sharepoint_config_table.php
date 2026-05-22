<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('sharepoint_config')) {
            Schema::create('sharepoint_config', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary()->default(1);
                $table->string('tenant_id', 64)->nullable();
                $table->string('client_id', 64)->nullable();
                $table->text('client_secret')->nullable();
                $table->text('folder_url')->nullable();
                $table->boolean('is_enabled')->default(false);
                $table->string('cached_drive_id', 128)->nullable();
                $table->string('cached_root_item_id', 256)->nullable();
                $table->string('cached_root_name', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sharepoint_config');
    }
};
