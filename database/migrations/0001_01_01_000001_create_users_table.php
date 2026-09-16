<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('email', 320);

            // Carries the unique index, so two accounts cannot differ by case alone. Every lookup
            // compares against this column rather than against `email`.
            $table->string('normalized_email', 320);
            $table->string('name', 200);
            $table->string('role', 50);
            $table->string('status', 50);

            $table->timestamp('invited_at', 6)->nullable();
            $table->timestamp('activated_at', 6)->nullable();
            $table->timestamp('last_login_at', 6)->nullable();

            // Deletion is soft and deliberately separate from `status`: the status records where
            // somebody reached in the invitation lifecycle, and overwriting it would lose what a
            // restore has to put back. The row also survives because the audit columns on every
            // other table refer to people by identifier.
            $table->softDeletes('deleted_at', 6);

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();

            $table->unique('normalized_email');

            // The roster pages by organization and status and leaves deleted users out, so the
            // filter it always applies belongs in the index rather than on the rows it hands back.
            $table->index(['organization_id', 'deleted_at', 'status']);
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
