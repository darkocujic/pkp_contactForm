<?php

/**
 * @file classes/SubmissionRepository.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class SubmissionRepository
 *
 * @brief Reads and writes archived contact form messages.
 */

declare(strict_types=1);

namespace APP\plugins\generic\contactForm\classes;

use APP\plugins\generic\contactForm\ContactFormSchemaMigration;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;

class SubmissionRepository
{
    /** Never return an unbounded result set to a template. */
    public const MAX_PAGE_SIZE = 100;

    private function table(): string
    {
        return ContactFormSchemaMigration::TABLE;
    }

    /**
     * Store a message and return its new id.
     *
     * Called before the mail is sent, so a delivery failure never loses the
     * message.
     */
    public function add(ContactSubmission $submission): int
    {
        $row = $submission->toRow();
        $row['date_submitted'] = $row['date_submitted'] ?? Core::getCurrentDate();

        $id = DB::table($this->table())
            ->insertGetId($row, 'contact_form_submission_id');

        $submission->id = (int) $id;
        $submission->dateSubmitted = $row['date_submitted'];

        return $submission->id;
    }

    /**
     * Flag a message as handed to the mailer.
     */
    public function markEmailed(int $id): void
    {
        DB::table($this->table())
            ->where('contact_form_submission_id', '=', $id)
            ->update(['emailed' => 1]);
    }

    /**
     * One page of a journal's messages, newest first.
     *
     * @return ContactSubmission[]
     */
    public function getMany(int $contextId, int $offset, int $limit): array
    {
        $limit = max(1, min($limit, self::MAX_PAGE_SIZE));

        $rows = DB::table($this->table())
            ->where('context_id', '=', $contextId)
            ->orderBy('date_submitted', 'desc')
            ->orderBy('contact_form_submission_id', 'desc')
            ->offset(max(0, $offset))
            ->limit($limit)
            ->get();

        $submissions = [];
        foreach ($rows as $row) {
            $submissions[] = ContactSubmission::fromRow($row);
        }

        return $submissions;
    }

    public function getCount(int $contextId): int
    {
        return (int) DB::table($this->table())
            ->where('context_id', '=', $contextId)
            ->count();
    }

    /**
     * Delete one message. The context id is part of the condition so a forged
     * id cannot reach another journal's archive.
     *
     * @return bool True iff a row was deleted.
     */
    public function delete(int $id, int $contextId): bool
    {
        if ($id < 1) {
            return false;
        }

        return DB::table($this->table())
            ->where('contact_form_submission_id', '=', $id)
            ->where('context_id', '=', $contextId)
            ->delete() > 0;
    }

    /**
     * How many messages arrived since a cut-off, optionally from one client.
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
     * Delete messages older than the retention window.
     *
     * @return int Rows removed.
     */
    public function purgeOlderThan(int $contextId, int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        return DB::table($this->table())
            ->where('context_id', '=', $contextId)
            ->where('date_submitted', '<', Core::getCurrentDate(time() - ($days * 86400)))
            ->delete();
    }

    /**
     * Delete expired messages across every journal. Used by the scheduled
     * task, which has no single context.
     *
     * @param array<int, int> $retentionDaysByContextId
     *
     * @return int Rows removed.
     */
    public function purgeAll(array $retentionDaysByContextId): int
    {
        $removed = 0;
        foreach ($retentionDaysByContextId as $contextId => $days) {
            $removed += $this->purgeOlderThan((int) $contextId, (int) $days);
        }
        return $removed;
    }
}
