<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE m_parameter MODIFY COLUMN context ENUM('input', 'expert', 'protected', 'finale', 'afternoon', 'integration') NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE m_parameter MODIFY COLUMN context ENUM('input', 'expert', 'protected', 'finale', 'afternoon') NULL");
    }
};
