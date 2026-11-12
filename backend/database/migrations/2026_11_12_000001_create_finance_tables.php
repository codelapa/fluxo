<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_demo')->default(false);
            $t->unsignedBigInteger('dashboard_version')->default(0);
        });
        Schema::create('imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('filename');
            $t->string('path');
            $t->char('checksum', 64);
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('processed_rows')->default(0);
            $t->json('errors')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'checksum']);
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('import_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('row_number');
            $t->date('date');
            $t->string('description', 500);
            $t->unsignedBigInteger('amount');
            $t->string('type', 10);
            $t->timestamps();
            $t->unique(['import_id', 'row_number']);
            $t->index(['user_id', 'date', 'id']);
            $t->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('imports');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_demo', 'dashboard_version']));
    }
};
