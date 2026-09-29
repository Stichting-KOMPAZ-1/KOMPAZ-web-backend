<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A video on its way in: a blob somebody was given leave to write, and whether it has been looked
 * at and used.
 *
 * A row rather than a signed token, because an upload is spent exactly once. A form names the
 * upload by this row's key, and the row is claimed with one conditional UPDATE — so a key cannot be
 * used by somebody it was not issued to, cannot be used twice, and cannot point two videos at one
 * blob, where deleting either would take the other's bytes. The storage key is never accepted
 * from a caller: it is minted here and read back from here.
 *
 * Rows are never updated past being claimed and nothing reads a claimed one, so there is nothing
 * to sweep but the ones nobody finished; see IssueVideoUploadAction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Whose it is. Only they may complete it or put it on a video.
            $table->foreignUuid('issued_to')->constrained('users')->cascadeOnDelete();

            $table->string('storage_key', 512)->unique();

            // What the browser said it would send, which is only what the link was issued against.
            // What arrived is read off the blob when the upload is completed, and is what counts.
            $table->unsignedInteger('declared_byte_count');

            // Set together when the blob has been looked at: what its bytes turned out to be.
            $table->string('content_type', 100)->nullable();
            $table->unsignedInteger('byte_count')->nullable();
            $table->timestamp('verified_at', 6)->nullable();

            $table->timestamp('claimed_at', 6)->nullable();
            $table->timestamp('expires_at', 6);

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();

            // The sweep's question: unclaimed and past its time.
            $table->index(['claimed_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_uploads');
    }
};
