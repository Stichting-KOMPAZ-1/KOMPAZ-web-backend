<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();

            // uuidMorphs rather than Sanctum's published morphs(): users are keyed by UUID, and the
            // bigint column the default produces would silently fail to match any of them.
            $table->uuidMorphs('tokenable');

            $table->text('name');

            // Only the hash of the token is stored, never the value handed to the client — the same
            // property the emailed sign-in links have.
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
