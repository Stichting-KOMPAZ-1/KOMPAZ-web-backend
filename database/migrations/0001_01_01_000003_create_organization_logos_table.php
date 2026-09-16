<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_logos', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // One logo per organization, enforced rather than assumed: an upload replaces the row
            // it finds, and two rows would make "the logo" a question about ordering. The logo goes
            // when the organization does, which is why the image is a row here rather than a file
            // a deployment has to remember to clean up.
            $table->foreignUuid('organization_id')->unique()->constrained()->cascadeOnDelete();

            // Where the bytes are. Minted from the organization's id and a fresh identifier, never
            // accepted from a caller.
            $table->string('storage_key', 512);

            // The media type the bytes actually are, decided by reading them rather than by
            // believing the upload. Served back verbatim, so an unverified value would be a media
            // type an attacker chose.
            $table->string('content_type', 100);
            $table->unsignedInteger('byte_count');

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_logos');
    }
};
