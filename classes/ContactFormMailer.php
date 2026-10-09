<?php

/**
 * @file classes/ContactFormMailer.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ContactFormMailer
 *
 * @brief Composes and sends the contact form emails.
 */

namespace APP\plugins\generic\contactForm\classes;

use APP\facades\Repo;
use APP\plugins\generic\contactForm\classes\mailables\ContactFormAcknowledgement;
use APP\plugins\generic\contactForm\classes\mailables\ContactFormNotification;
use APP\plugins\generic\contactForm\ContactFormPlugin;
use Illuminate\Support\Facades\Mail;
use PKP\mail\Mailable;
use Throwable;

class ContactFormMailer
{
    /** Display names in headers are capped; nothing legitimate needs more. */
    public const MAX_HEADER_NAME_LENGTH = 120;

    private ContactFormPlugin $plugin;

    public function __construct(ContactFormPlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Notify the journal about a message.
     *
     * The submission is already stored by the time this runs, so a false
     * return means "not delivered", never "lost".
     *
     * @param \APP\journal\Journal $context
     */
    public function sendNotification($context, ContactSubmission $submission): bool
    {
        $recipient = $this->plugin->getRecipientEmail($context);
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            error_log(
                'contactForm: no valid recipient address is configured for journal '
                . $context->getId() . ', message ' . $submission->id . ' was stored but not emailed'
            );
            return false;
        }

        try {
            $mailable = new ContactFormNotification($context);

            $this->setFrom($mailable, $context, $recipient);
            $mailable->to($recipient, $this->headerSafe($this->plugin->getRecipientName($context)));

            foreach ($this->plugin->getCcEmails($context->getId()) as $cc) {
                $mailable->cc($cc);
            }

            // The visitor is the Reply-To, never the From: forging the From
            // would fail SPF/DKIM and get the whole journal's mail filtered.
            $mailable->replyTo($submission->senderEmail, $this->headerSafe($submission->senderName));

            $mailable->addData([
                ContactFormNotification::VARIABLE_SENDER_NAME => $this->forTemplate($submission->senderName),
                ContactFormNotification::VARIABLE_SENDER_EMAIL => $this->forTemplate($submission->senderEmail),
                ContactFormNotification::VARIABLE_MESSAGE_SUBJECT => $this->forTemplate((string) $submission->subject),
                ContactFormNotification::VARIABLE_MESSAGE_BODY => $this->forTemplate($submission->body),
                ContactFormNotification::VARIABLE_SUBMITTED_AT => $this->forTemplate((string) $submission->dateSubmitted),
            ]);

            $this->setSubjectAndBody(
                $mailable,
                $context,
                'emails.contactFormNotification.subject',
                'emails.contactFormNotification.body'
            );

            Mail::send($mailable);
            return true;
        } catch (Throwable $exception) {
            error_log(
                'contactForm: failed to email message ' . $submission->id
                . ' for journal ' . $context->getId() . ': ' . $exception->getMessage()
            );
            return false;
        }
    }

    /**
     * Confirm receipt to the visitor, if the journal has enabled it.
     *
     * Carries none of the visitor's own text — see
     * ContactFormAcknowledgement for why.
     *
     * @param \APP\journal\Journal $context
     */
    public function sendAcknowledgement($context, ContactSubmission $submission): bool
    {
        if (!$this->plugin->getBoolSetting($context->getId(), 'enableAcknowledgement')) {
            return false;
        }

        if (!filter_var($submission->senderEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $mailable = new ContactFormAcknowledgement($context);

            $this->setFrom($mailable, $context, $this->plugin->getRecipientEmail($context));
            $mailable->to($submission->senderEmail, $this->headerSafe($submission->senderName));

            $mailable->addData([
                ContactFormAcknowledgement::VARIABLE_SUBMITTED_AT => $this->forTemplate((string) $submission->dateSubmitted),
            ]);

            $this->setSubjectAndBody(
                $mailable,
                $context,
                'emails.contactFormAcknowledgement.subject',
                'emails.contactFormAcknowledgement.body'
            );

            Mail::send($mailable);
            return true;
        } catch (Throwable $exception) {
            error_log(
                'contactForm: failed to send the acknowledgement for message '
                . $submission->id . ': ' . $exception->getMessage()
            );
            return false;
        }
    }

    /**
     * Use the journal's contact address as the sender, falling back to the
     * recipient so a half-configured journal still gets its mail.
     *
     * @param \APP\journal\Journal $context
     */
    private function setFrom(Mailable $mailable, $context, string $fallbackEmail): void
    {
        $fromEmail = (string) $context->getData('contactEmail');
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = $fallbackEmail;
        }

        $mailable->from($fromEmail, $this->headerSafe((string) $context->getData('contactName')));
    }

    /**
     * Take the subject and body from the journal's editable email template,
     * falling back to the shipped text if the template is missing or has no
     * content in the current locale.
     *
     * @param \APP\journal\Journal $context
     */
    private function setSubjectAndBody(Mailable $mailable, $context, string $subjectKey, string $bodyKey): void
    {
        $subject = '';
        $body = '';

        $template = Repo::emailTemplate()->getByKey($context->getId(), $mailable::getEmailTemplateKey());
        if ($template) {
            $subject = (string) $template->getLocalizedData('subject');
            $body = (string) $template->getLocalizedData('body');
        }

        if (trim($subject) === '') {
            $subject = __($subjectKey);
        }
        if (trim($body) === '') {
            $body = __($bodyKey);
        }

        $mailable->subject($subject)->body($body);
    }

    /**
     * Make a value safe to place in an email header: no line breaks (which
     * would let a submitter append headers of their own) and bounded length.
     */
    private function headerSafe(string $value): ?string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, self::MAX_HEADER_NAME_LENGTH);
    }

    /**
     * Make a submitted value safe to substitute into an email template.
     *
     * Two separate hazards: the templates are HTML, and core substitutes
     * variables with preg_replace(), where "$1" and "\1" in a *replacement*
     * are backreferences. Both are neutralised here.
     */
    private function forTemplate(string $value): string
    {
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $value = nl2br($value, false);

        return str_replace(['\\', '$'], ['\\\\', '\\$'], $value);
    }
}
