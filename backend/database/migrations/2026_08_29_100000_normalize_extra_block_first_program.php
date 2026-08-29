<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Treat NULL first_program as übergreifend (0).
     */
    public function up(): void
    {
        DB::table('extra_block')
            ->whereNull('first_program')
            ->update(['first_program' => 0]);
    }

    public function down(): void
    {
        // Irreversible: we cannot distinguish which rows were originally NULL.
    }
};
