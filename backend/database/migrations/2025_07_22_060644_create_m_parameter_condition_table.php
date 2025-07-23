<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('m_parameter_condition', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger("parameter")->nullable();
            $table->foreign("parameter")->references("id")->on("m_parameter")->nullOnDelete();

            $table->unsignedInteger("if_parameter")->nullable();
            $table->foreign("if_parameter")->references("id")->on("m_parameter")->nullOnDelete();

            $table->enum("is", ["=", "<", ">"])->nullable();
            $table->string("value")->nullable();
            $table->enum("action", ["hide", "show"])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_parameter_condition');
    }
};
