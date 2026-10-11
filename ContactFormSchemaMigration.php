<?php

/**
 * @file ContactFormSchemaMigration.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ContactFormSchemaMigration
 *
 * @brief Creates the tables this plugin uses: the message archive, and a
 *  separate, content-free table that backs rate limiting on its own so that
 *  disabling the archive doesn't disable rate limiting too.
 */

namespace APP\plugins\generic\contactForm;

use APP\core\Application;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ContactFormSchemaMigration extends Migration
{
    public const TABLE = 'contact_form_submissions';
    public const THROTTLE_TABLE = 'contact_form_throttle';

    /**
     * Run the migration.
     *
     * Idempotent on purpose: the same release tarball may be uploaded over an
     * existing installation, and OJS runs plugin migrations on upgrade too.
     */
    public function up(): void
    {
        $contextDao = Application::getContextDAO();

        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) use ($contextDao) {
                $table->comment('Messages received through the Contact Form plugin.');

                $table->bigInteger('contact_form_submission_id')->autoIncrement();

                $table->bigInteger('context_id');
                $table->foreign('context_id', 'contact_form_submissions_context_id')
                    ->references($contextDao->primaryKeyColumn)
                    ->on($contextDao->tableName)
                    ->onDelete('cascade');

                $table->string('sender_name', 255);
                $table->string('sender_email', 255);
                $table->string('subject', 255)->nullable();
                $table->text('body');
                $table->string('locale', 14)->default('');

                // An HMAC of the client address, never the address itself.
                // Nullable because the address can be absent (CLI, odd
                // proxies). Rate limiting itself no longer reads this column
                // — see contact_form_throttle below — but it's kept here too
                // for an admin reviewing a stored message's origin.
                $table->string('ip_hash', 64)->nullable()
                    ->comment('Keyed hash of the submitter IP. Informational only; see contact_form_throttle.');
                $table->string('user_agent', 255)->nullable();

                $table->smallInteger('emailed')->default(0)
                    ->comment('1 once the notification has been handed to the mailer.');
                $table->datetime('date_submitted');

                $table->index(['context_id', 'date_submitted'], 'contact_form_submissions_context_date');
            });
        }

        if (!Schema::hasTable(self::THROTTLE_TABLE)) {
            Schema::create(self::THROTTLE_TABLE, function (Blueprint $table) use ($contextDao) {
                $table->comment(
                    'One row per accepted contact form submission, kept only long enough to '
                    . 'enforce the rate limits — no message content, ever, regardless of whether '
                    . 'the full message is also archived in contact_form_submissions.'
                );

                $table->bigInteger('contact_form_throttle_id')->autoIncrement();

                $table->bigInteger('context_id');
                $table->foreign('context_id', 'contact_form_throttle_context_id')
                    ->references($contextDao->primaryKeyColumn)
                    ->on($contextDao->tableName)
                    ->onDelete('cascade');

                $table->string('ip_hash', 64)->nullable();
                $table->datetime('date_submitted');

                $table->index(['context_id', 'ip_hash', 'date_submitted'], 'contact_form_throttle_lookup');
            });
        }
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists(self::THROTTLE_TABLE);
        Schema::dropIfExists(self::TABLE);
    }
}
