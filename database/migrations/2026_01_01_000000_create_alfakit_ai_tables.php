<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per AI call: what the usage resource lists and totals.
        Schema::create('alfakit_ai_usage', function (Blueprint $table): void {
            $table->id();
            $table->string('panel', 64)->index();
            $table->string('feature', 32);
            $table->string('context')->nullable();
            $table->nullableMorphs('user');
            $table->nullableMorphs('tenant');
            $table->string('provider', 64)->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 14, 6)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['panel', 'created_at']);
            $table->index(['panel', 'feature']);
        });

        Schema::create('alfakit_ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('panel', 64);
            $table->morphs('user');
            $table->nullableMorphs('tenant');
            $table->string('title')->nullable();
            $table->timestamps();

            $table->index(['panel', 'user_type', 'user_id', 'updated_at']);
        });

        Schema::create('alfakit_ai_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('alfakit_ai_conversations')->cascadeOnDelete();
            // user, assistant, action (a proposed panel action), navigation, error.
            $table->string('role', 16);
            $table->text('content')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamps();
        });

        // The audit log of every tool the copilot or an MCP client called.
        Schema::create('alfakit_ai_tool_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('panel', 64)->index();
            $table->string('channel', 16);
            $table->foreignId('conversation_id')->nullable()->constrained('alfakit_ai_conversations')->nullOnDelete();
            $table->nullableMorphs('user');
            $table->nullableMorphs('tenant');
            $table->string('tool', 64);
            $table->json('arguments')->nullable();
            // ok, denied, failed, proposed, confirmed, rejected.
            $table->string('status', 16);
            $table->text('result')->nullable();
            $table->timestamps();
        });

        // Only used when neither alfasix/alfakit-api nor laravel/sanctum issues the MCP tokens.
        Schema::create('alfakit_ai_mcp_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('panel', 64);
            $table->morphs('tokenable');
            $table->nullableMorphs('tenant');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alfakit_ai_mcp_tokens');
        Schema::dropIfExists('alfakit_ai_tool_calls');
        Schema::dropIfExists('alfakit_ai_messages');
        Schema::dropIfExists('alfakit_ai_conversations');
        Schema::dropIfExists('alfakit_ai_usage');
    }
};
