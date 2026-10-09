<?php

/**
 * @file classes/ContactSubmission.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ContactSubmission
 *
 * @brief One message received through the contact form.
 */

declare(strict_types=1);

namespace APP\plugins\generic\contactForm\classes;

class ContactSubmission
{
    /**
     * Properties are public so templates can read them directly; nothing
     * mutates an instance after construction.
     */
    public function __construct(
        public ?int $id,
        public int $contextId,
        public string $senderName,
        public string $senderEmail,
        public ?string $subject,
        public string $body,
        public string $locale = '',
        public ?string $ipHash = null,
        public ?string $userAgent = null,
        public bool $emailed = false,
        public ?string $dateSubmitted = null
    ) {
    }

    /**
     * Build an instance from a database row.
     *
     * @param object|array $row
     */
    public static function fromRow($row): self
    {
        $row = (array) $row;

        return new self(
            isset($row['contact_form_submission_id']) ? (int) $row['contact_form_submission_id'] : null,
            (int) ($row['context_id'] ?? 0),
            (string) ($row['sender_name'] ?? ''),
            (string) ($row['sender_email'] ?? ''),
            isset($row['subject']) ? (string) $row['subject'] : null,
            (string) ($row['body'] ?? ''),
            (string) ($row['locale'] ?? ''),
            isset($row['ip_hash']) ? (string) $row['ip_hash'] : null,
            isset($row['user_agent']) ? (string) $row['user_agent'] : null,
            (bool) ($row['emailed'] ?? false),
            isset($row['date_submitted']) ? (string) $row['date_submitted'] : null
        );
    }

    /**
     * The column values for an INSERT. The id is excluded; the database
     * assigns it.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'context_id' => $this->contextId,
            'sender_name' => $this->senderName,
            'sender_email' => $this->senderEmail,
            'subject' => $this->subject,
            'body' => $this->body,
            'locale' => $this->locale,
            'ip_hash' => $this->ipHash,
            'user_agent' => $this->userAgent,
            'emailed' => $this->emailed ? 1 : 0,
            'date_submitted' => $this->dateSubmitted,
        ];
    }
}
