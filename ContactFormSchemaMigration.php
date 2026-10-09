<?php

/**
 * @file ContactFormSchemaMigration.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ContactFormSchemaMigration
 *
 * @brief Creates the table that archives received contact form messages.
 */

namespace APP\plugins\generic\contactForm;

use APP\core\Application;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ContactFormSchemaMigration extends Migration
{
    public const TABLE = 'contact_form_submissions';

    /**
     * Run the migration.
     *
     * Idempotent on purpose: the same release tarball may be uploaded over an
     * existing installation, and OJS runs plugin migrations on upgrade too.
     */
    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        $contextDao = Application::getContextDAO();

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

            // An HMAC of the client address, never the address itself. Used
            // only to rate limit; nullable because the address can be absent
            // (CLI, odd proxies).
            $table->string('ip_hash', 64)->nullable()
                ->comment('Keyed hash of the submitter IP, for rate limiting only.');
            $table->string('user_agent', 255)->nullable();

            $table->smallInteger('emailed')->default(0)
                ->comment('1 once the notification has been handed to the mailer.');
            $table->datetime('date_submitted');

            $table->index(['context_id', 'date_submitted'], 'contact_form_submissions_context_date');
            $table->index(['context_id', 'ip_hash', 'date_submitted'], 'contact_form_submissions_throttle');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
}
