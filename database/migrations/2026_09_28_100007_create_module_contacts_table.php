<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who somebody following a module should ring, and when.
 *
 * Owned by an activation and never by a module, which is the difference that made activations a
 * table with a key of its own. A module is written once for everyone; the person to ring about it
 * works at one organization, and the whole point of the organization administrator's form is that
 * their people are not given somebody else's number.
 *
 * Only the name is required. The rest of the card is what that organization happens to publish —
 * a department with a shared inbox and no direct line is a real answer, and refusing it would push
 * an operator into typing a placeholder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('module_activation_id')->constrained()->cascadeOnDelete();

            $table->string('name', 200);
            $table->string('email', 320)->nullable();
            $table->string('phone', 50)->nullable();

            // "Reden voor contact" and "Beschikbaarheid": both prose an organization writes in its
            // own words, shown under the name on the module page.
            $table->text('reason')->nullable();
            $table->text('availability')->nullable();

            // Defaulted, because the panel's repeatable rows arrive without one: the key is a
            // UUIDv7 and therefore already in insertion order, so an unnumbered list keeps the
            // order it was typed in. An explicit position still takes precedence over it.
            $table->unsignedInteger('position')->default(0);

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();

            // Read two ways: as this activation's cards in order, and as the question the
            // organization administrator's table asks of every row — has this one been filled in
            // at all?
            $table->index(['module_activation_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_contacts');
    }
};
