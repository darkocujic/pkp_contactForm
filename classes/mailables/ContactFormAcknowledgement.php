<?php

/**
 * @file classes/mailables/ContactFormAcknowledgement.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ContactFormAcknowledgement
 *
 * @brief Optionally sent to the visitor to confirm their message arrived.
 *
 * This email is addressed to whatever the visitor typed, so it deliberately
 * carries none of their text: the journal's template is the only content. That
 * keeps the feature from being usable to send attacker-authored mail to a third
 * party. It is also disabled by default.
 */

namespace APP\plugins\generic\contactForm\classes\mailables;

use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;

class ContactFormAcknowledgement extends Mailable
{
    use Configurable;

    public const VARIABLE_SUBMITTED_AT = 'submittedAt';

    protected static ?string $name = 'plugins.generic.contactForm.emails.acknowledgement.name';
    protected static ?string $description = 'plugins.generic.contactForm.emails.acknowledgement.description';
    protected static ?string $emailTemplateKey = 'CONTACT_FORM_ACKNOWLEDGEMENT';
    protected static bool $supportsTemplates = true;
    protected static array $groupIds = [self::GROUP_OTHER];

    public function __construct(Context $context)
    {
        parent::__construct([$context]);
    }

    public static function getDataDescriptions(): array
    {
        return array_merge(parent::getDataDescriptions(), [
            self::VARIABLE_SUBMITTED_AT => __('plugins.generic.contactForm.emails.variable.submittedAt'),
        ]);
    }
}
