<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A contact's job role, which KOM-61 puts on every card next to the name.
 *
 * The job role and the e-mail address are required from now on, but only by the two forms that
 * write a contact (`ContentRules`), not by the columns. Cards written before the rule are already
 * on deployed databases with neither, and a `NOT NULL` would either refuse this migration on those
 * rows or fill them with an empty string that looks like an answer. They are put right the next
 * time somebody saves that module's contacts, which the form will not allow without both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_contacts', function (Blueprint $table): void {
            $table->string('job_role', 200)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('module_contacts', function (Blueprint $table): void {
            $table->dropColumn('job_role');
        });
    }
};
