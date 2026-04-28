<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create a small table used by SQLite migration lifecycle tests.
     */
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Drop the fixture table so refresh-style migrations remain reversible.
     */
    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};
