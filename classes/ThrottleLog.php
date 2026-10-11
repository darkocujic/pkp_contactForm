<?php

/**
 * @file classes/ThrottleLog.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ThrottleLog
 *
 * @brief Backs rate limiting with a record that holds nothing but a hashed
 *  IP and a timestamp — deliberately separate from the message archive, so
 *  rate limiting still works when a journal disables message storage
 *  entirely.
 */

declare(strict_types=1);

namespace APP\plugins\generic\contactForm\classes;

use APP\plugins\generic\contactForm\ContactFormSchemaMigration;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;

class ThrottleLog
{
    private function table(): string
    {
        return ContactFormSchemaMigration::THROTTLE_TABLE;
    }

    /**
     * Record one accepted submission. Called for every submission that
     * passes validation, whether or not the message itself is also
     * archived.
     */
    public function record(int $contextId, ?string $ipHash): void
    {
        DB::table($this->table())->insert([
            'context_id' => $contextId,
            'ip_hash' => $ipHash,
            'date_submitted' => Core::getCurrentDate(),
        ]);
    }

    /**
     * How many submissions arrived since a cut-off, optionally from one
     * client.
     *
     * @param string|null $ipHash Null counts every submitter.
     * @param string $since 'Y-m-d H:i:s'
     */
    public function countSince(int $contextId, ?string $ipHash, string $since): int
    {
        $query = DB::table($this->table())
            ->where('context_id', '=', $contextId)
            ->where('date_submitted', '>=', $since);

        if ($ipHash !== null) {
            $query->where('ip_hash', '=', $ipHash);
        }

        return (int) $query->count();
    }

    /**
     * Delete rows older than a cut-off. These rows exist only to answer
     * "how many submissions in the last hour", so unlike the message
     * archive there's no reason to keep them any longer than that, and no
     * per-journal retention setting controls this — it's always purged
     * aggressively, independent of whether message storage is even
     * enabled.
     *
     * @return int Rows removed.
     */
    public function purgeOlderThan(string $before): int
    {
        return DB::table($this->table())
            ->where('date_submitted', '<', $before)
            ->delete();
    }
}
