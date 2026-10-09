<?php

/**
 * @file classes/mailables/ContactFormNotification.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ContactFormNotification
 *
 * @brief Sent to the journal when a visitor submits the contact form.
 */

namespace APP\plugins\generic\contactForm\classes\mailables;

use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\security\Role;

class ContactFormNotification extends Mailable
{
    use Configurable;

    public const VARIABLE_SENDER_NAME = 'senderName';
    public const VARIABLE_SENDER_EMAIL = 'senderEmail';
    public const VARIABLE_MESSAGE_SUBJECT = 'messageSubject';
    public const VARIABLE_MESSAGE_BODY = 'messageBody';
    public const VARIABLE_SUBMITTED_AT = 'submittedAt';

    protected static ?string $name = 'plugins.generic.contactForm.emails.notification.name';
    protected static ?string $description = 'plugins.generic.contactForm.emails.notification.description';
    protected static ?string $emailTemplateKey = 'CONTACT_FORM_NOTIFICATION';
    protected static bool $supportsTemplates = true;
    protected static array $groupIds = [self::GROUP_OTHER];
    protected static array $toRoleIds = [Role::ROLE_ID_MANAGER];

    public function __construct(Context $context)
    {
        parent::__construct([$context]);
    }

    /**
     * Describe this plugin's own variables so they are listed in the email
     * template editor alongside the context ones.
     */
    public static function getDataDescriptions(): array
    {
        return array_merge(parent::getDataDescriptions(), [
            self::VARIABLE_SENDER_NAME => __('plugins.generic.contactForm.emails.variable.senderName'),
            self::VARIABLE_SENDER_EMAIL => __('plugins.generic.contactForm.emails.variable.senderEmail'),
            self::VARIABLE_MESSAGE_SUBJECT => __('plugins.generic.contactForm.emails.variable.messageSubject'),
            self::VARIABLE_MESSAGE_BODY => __('plugins.generic.contactForm.emails.variable.messageBody'),
            self::VARIABLE_SUBMITTED_AT => __('plugins.generic.contactForm.emails.variable.submittedAt'),
        ]);
    }
}
