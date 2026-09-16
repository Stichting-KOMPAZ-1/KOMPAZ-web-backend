<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // The rotation chain this token belongs to, shared by every successor of the sign-in
            // that started it. It is what lets a replayed token revoke the whole session rather
            // than just itself.
            $table->uuid('session_id');
            $table->string('token_hash', 128)->unique();

            // The sliding deadline, restarted on every rotation and capped by absolute_expires_at.
            $table->timestamp('expires_at', 6);
            $table->timestamp('absolute_expires_at', 6);
            $table->timestamp('consumed_at', 6)->nullable();
            $table->timestamp('revoked_at', 6)->nullable();
            $table->timestamp('created_at', 6);

            // Revoking a session walks every token in the chain, so the chain is the index.
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
