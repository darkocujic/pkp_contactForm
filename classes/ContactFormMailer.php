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
            $mailable->to($recipient, EmailSafeText::forHeader($this->plugin->getRecipientName($context)));

            foreach ($this->plugin->getCcEmails($context->getId()) as $cc) {
                $mailable->cc($cc);
            }

            // The visitor is the Reply-To, never the From: forging the From
            // would fail SPF/DKIM and get the whole journal's mail filtered.
            // Re-validated here rather than trusted from the form, so nothing
            // reaches a header on the strength of an earlier check alone.
            if (filter_var($submission->senderEmail, FILTER_VALIDATE_EMAIL)) {
                $mailable->replyTo($submission->senderEmail, EmailSafeText::forHeader($submission->senderName));
            }

            $mailable->addData([
                ContactFormNotification::VARIABLE_SENDER_NAME => EmailSafeText::forTemplate($submission->senderName),
                ContactFormNotification::VARIABLE_SENDER_EMAIL => EmailSafeText::forTemplate($submission->senderEmail),
                ContactFormNotification::VARIABLE_MESSAGE_SUBJECT => EmailSafeText::forTemplate((string) $submission->subject),
                ContactFormNotification::VARIABLE_MESSAGE_BODY => EmailSafeText::forTemplate($submission->body),
                ContactFormNotification::VARIABLE_SUBMITTED_AT => EmailSafeText::forTemplate((string) $submission->dateSubmitted),
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
            $mailable->to($submission->senderEmail, EmailSafeText::forHeader($submission->senderName));

            $mailable->addData([
                ContactFormAcknowledgement::VARIABLE_SUBMITTED_AT => EmailSafeText::forTemplate((string) $submission->dateSubmitted),
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

        $mailable->from($fromEmail, EmailSafeText::forHeader((string) $context->getData('contactName')));
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

        // Guaranteed ourselves rather than left to Mailable's own variable
        // resolution — see EmailSafeText::substituteToken() for why.
        $contextName = htmlspecialchars((string) $context->getLocalizedData('name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = EmailSafeText::substituteToken($subject, 'contextName', $contextName);
        $body = EmailSafeText::substituteToken($body, 'contextName', $contextName);

        $mailable->subject($subject)->body($body);
    }
}
