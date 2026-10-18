<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->nullable();
            $table->string('number');
            $table->string('customer');
            $table->string('email')->nullable();
            $table->decimal('total', 10, 2)->default(0);
            $table->string('status')->default('new');
            $table->boolean('paid')->default(false);
            $table->boolean('is_private')->default(false);
            $table->text('notes')->nullable();
            $table->text('description')->nullable();
            $table->string('secret_code')->nullable();
            $table->date('ordered_at')->nullable();
            $table->timestamps();
        });
    }
};
