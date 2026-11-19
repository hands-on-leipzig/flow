<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sharepoint_config', function (Blueprint $table) {
            if (! Schema::hasColumn('sharepoint_config', 'folder_url_de')) {
                $table->text('folder_url_de')->nullable()->after('folder_url');
            }
            if (! Schema::hasColumn('sharepoint_config', 'folder_url_en')) {
                $table->text('folder_url_en')->nullable()->after('folder_url_de');
            }
            if (! Schema::hasColumn('sharepoint_config', 'cached_roots')) {
                $table->json('cached_roots')->nullable()->after('cached_root_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sharepoint_config', function (Blueprint $table) {
            if (Schema::hasColumn('sharepoint_config', 'cached_roots')) {
                $table->dropColumn('cached_roots');
            }
            if (Schema::hasColumn('sharepoint_config', 'folder_url_en')) {
                $table->dropColumn('folder_url_en');
            }
            if (Schema::hasColumn('sharepoint_config', 'folder_url_de')) {
                $table->dropColumn('folder_url_de');
            }
        });
    }
};
