<?php

/**
 * @file classes/form/ContactForm.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ContactForm
 *
 * @brief The public contact form: validation, spam checks, storage and sending.
 */

namespace APP\plugins\generic\contactForm\classes\form;

use APP\plugins\generic\contactForm\classes\ContactFormMailer;
use APP\plugins\generic\contactForm\classes\ContactSubmission;
use APP\plugins\generic\contactForm\classes\spam\ContentHeuristics;
use APP\plugins\generic\contactForm\classes\spam\HoneypotGuard;
use APP\plugins\generic\contactForm\classes\spam\Throttle;
use APP\plugins\generic\contactForm\ContactFormPlugin;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\core\Core;
use PKP\facades\Locale;
use PKP\form\Form;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorEmail;
use PKP\form\validation\FormValidatorLength;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorReCaptcha;
use Throwable;

class ContactForm extends Form
{
    /** Pseudo-field the single generic spam error is attached to. */
    public const SPAM_ERROR_FIELD = 'spamCheck';

    private ContactFormPlugin $plugin;

    /** @var \APP\journal\Journal */
    private $context;

    /** @var \PKP\core\PKPRequest */
    private $request;

    private bool $captchaEnabled;
    private bool $stored = false;
    private bool $delivered = false;

    /**
     * @param \PKP\core\PKPRequest $request
     */
    public function __construct(ContactFormPlugin $plugin, $request)
    {
        parent::__construct($plugin->getTemplateResource('contactForm.tpl'));

        $this->plugin = $plugin;
        $this->request = $request;
        $this->context = $request->getContext();
        $this->captchaEnabled = self::isCaptchaConfigured();

        $this->addChecks();
    }

    /**
     * Is reCAPTCHA both switched on and actually configured?
     *
     * A half-configured captcha must not take the contact page down, so an
     * enabled captcha with no keys simply means no challenge.
     */
    public static function isCaptchaConfigured(): bool
    {
        return (bool) Config::getVar('captcha', 'recaptcha')
            && (string) Config::getVar('captcha', 'recaptcha_public_key') !== ''
            && (string) Config::getVar('captcha', 'recaptcha_private_key') !== '';
    }

    private function addChecks(): void
    {
        $contextId = $this->context->getId();

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));

        $this->addCheck(new FormValidator(
            $this,
            'senderName',
            FormValidator::FORM_VALIDATOR_REQUIRED_VALUE,
            'plugins.generic.contactForm.error.nameRequired'
        ));
        $this->addCheck(new FormValidatorLength(
            $this,
            'senderName',
            FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
            'plugins.generic.contactForm.error.nameTooLong',
            '<=',
            ContentHeuristics::MAX_LENGTHS['name']
        ));

        $this->addCheck(new FormValidatorEmail(
            $this,
            'senderEmail',
            FormValidator::FORM_VALIDATOR_REQUIRED_VALUE,
            'plugins.generic.contactForm.error.emailInvalid'
        ));

        if ($this->plugin->getBoolSetting($contextId, 'requireSubject')) {
            $this->addCheck(new FormValidator(
                $this,
                'subject',
                FormValidator::FORM_VALIDATOR_REQUIRED_VALUE,
                'plugins.generic.contactForm.error.subjectRequired'
            ));
        }
        $this->addCheck(new FormValidatorLength(
            $this,
            'subject',
            FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
            'plugins.generic.contactForm.error.subjectTooLong',
            '<=',
            ContentHeuristics::MAX_LENGTHS['subject']
        ));

        $this->addCheck(new FormValidator(
            $this,
            'body',
            FormValidator::FORM_VALIDATOR_REQUIRED_VALUE,
            'plugins.generic.contactForm.error.bodyRequired'
        ));
        $this->addCheck(new FormValidatorLength(
            $this,
            'body',
            FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
            'plugins.generic.contactForm.error.bodyTooLong',
            '<=',
            ContentHeuristics::MAX_LENGTHS['body']
        ));

        if ($this->captchaEnabled) {
            $this->addCheck(new FormValidatorReCaptcha(
                $this,
                $this->request->getRemoteAddr(),
                'plugins.generic.contactForm.error.captcha',
                $this->request->getServerHost()
            ));
        }
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData()
    {
        $this->setData('senderName', '');
        $this->setData('senderEmail', '');
        $this->setData('subject', '');
        $this->setData('body', '');

        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars(['senderName', 'senderEmail', 'subject', 'body']);

        if ($this->captchaEnabled) {
            $this->readUserVars(['g-recaptcha-response']);
        }
    }

    /**
     * @copydoc Form::fetch()
     */
    public function fetch($request, $template = null, $display = false)
    {
        $contextId = $this->context->getId();
        $guard = $this->honeypotGuard();

        $templateMgr = TemplateManager::getManager($request);

        if ($this->captchaEnabled) {
            // Core only loads the reCAPTCHA script on the register and login
            // pages, so this page has to ask for it itself.
            $templateMgr->addJavaScript(
                'recaptcha',
                'https://www.recaptcha.net/recaptcha/api.js?hl=' . substr(Locale::getLocale(), 0, 2),
                ['contexts' => 'frontend']
            );
        }

        $this->setData('pluginPagePath', $this->plugin->getPagePath($contextId));
        $this->setData('contactFormTitle', $this->plugin->getLocalizedSetting($contextId, 'pageTitle'));
        $this->setData('contactFormIntro', $this->plugin->getLocalizedSetting($contextId, 'introText'));
        $this->setData('requireSubject', $this->plugin->getBoolSetting($contextId, 'requireSubject'));
        $this->setData('honeypotFieldName', $guard->getFieldName());
        $this->setData('honeypotTimestampField', HoneypotGuard::TIMESTAMP_FIELD);
        $this->setData('honeypotTimestampToken', $guard->createTimestampToken());
        $this->setData('maxLengths', ContentHeuristics::MAX_LENGTHS);
        $this->setData('recaptchaPublicKey', $this->captchaEnabled
            ? Config::getVar('captcha', 'recaptcha_public_key')
            : null);

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::validate()
     *
     * @param mixed $callHooks
     */
    public function validate($callHooks = true)
    {
        if (!parent::validate($callHooks)) {
            return false;
        }

        // Only once the ordinary validation has passed, so that a visitor who
        // simply forgot their name still gets a useful message.
        $reason = $this->findSpamReason();
        if ($reason !== null) {
            error_log(
                'contactForm: rejected a submission for journal '
                . $this->context->getId() . ' (' . $reason . ')'
            );
            // One generic message for every spam check: telling a bot which
            // one it tripped is free tuning advice.
            $this->addError(self::SPAM_ERROR_FIELD, __('plugins.generic.contactForm.error.rejected'));
            return false;
        }

        return true;
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        parent::execute(...$functionArgs);

        $contextId = $this->context->getId();
        $submission = new ContactSubmission(
            null,
            $contextId,
            trim((string) $this->getData('senderName')),
            trim((string) $this->getData('senderEmail')),
            $this->nullIfEmpty(trim((string) $this->getData('subject'))),
            trim((string) $this->getData('body')),
            Locale::getLocale(),
            $this->plugin->hashIp($contextId, $this->request->getRemoteAddr()),
            $this->nullIfEmpty(mb_substr((string) $this->request->getUserAgent(), 0, 255)),
            false,
            Core::getCurrentDate()
        );

        $repository = $this->plugin->getSubmissionRepository();

        // Store first: a mail failure must not lose the message.
        try {
            $repository->add($submission);
            $this->stored = true;
        } catch (Throwable $exception) {
            error_log('contactForm: could not store a message: ' . $exception->getMessage());
        }

        $mailer = new ContactFormMailer($this->plugin);
        $this->delivered = $mailer->sendNotification($this->context, $submission);

        if ($this->delivered) {
            if ($this->stored && $submission->id !== null) {
                try {
                    $repository->markEmailed($submission->id);
                } catch (Throwable $exception) {
                    error_log('contactForm: could not flag a message as emailed: ' . $exception->getMessage());
                }
            }
            $mailer->sendAcknowledgement($this->context, $submission);
        }

        return $submission;
    }

    /**
     * Was the message archived?
     */
    public function wasStored(): bool
    {
        return $this->stored;
    }

    /**
     * Was the notification handed to the mailer?
     */
    public function wasDelivered(): bool
    {
        return $this->delivered;
    }

    /**
     * Run the spam checks in increasing cost order.
     *
     * @return string|null The reason, for the log only, or null when the
     *  submission looks genuine.
     */
    private function findSpamReason(): ?string
    {
        $contextId = $this->context->getId();

        $guard = $this->honeypotGuard();
        if (!$guard->check($this->request->getUserVars())) {
            return $guard->getLastReason();
        }

        $heuristics = new ContentHeuristics(
            $this->plugin->getIntSetting($contextId, 'maxUrls'),
            ContentHeuristics::parseBlocklist($this->plugin->getStringSetting($contextId, 'blocklist'))
        );
        $fields = [
            'name' => (string) $this->getData('senderName'),
            'email' => (string) $this->getData('senderEmail'),
            'subject' => (string) $this->getData('subject'),
            'body' => (string) $this->getData('body'),
        ];
        if (!$heuristics->check($fields)) {
            return $heuristics->getLastReason();
        }

        return $this->findThrottleReason($contextId);
    }

    /**
     * The only check that touches the database, so it runs last.
     */
    private function findThrottleReason(int $contextId): ?string
    {
        $throttle = new Throttle(
            $this->plugin->getIntSetting($contextId, 'maxPerHourPerIp'),
            $this->plugin->getIntSetting($contextId, 'maxPerHourPerContext')
        );

        try {
            $repository = $this->plugin->getSubmissionRepository();
            $since = Core::getCurrentDate(Throttle::windowStart());
            $ipHash = $this->plugin->hashIp($contextId, $this->request->getRemoteAddr());

            $allowed = $throttle->isAllowed(
                $ipHash === null ? 0 : $repository->countSince($contextId, $ipHash, $since),
                $repository->countSince($contextId, null, $since)
            );
        } catch (Throwable $exception) {
            // The rate limit is a safeguard, not a gate: if the count cannot
            // be read, let a genuine visitor through rather than breaking the
            // contact page.
            error_log('contactForm: could not evaluate the rate limit: ' . $exception->getMessage());
            return null;
        }

        return $allowed ? null : $throttle->getLastReason();
    }

    private function honeypotGuard(): HoneypotGuard
    {
        $contextId = $this->context->getId();

        return new HoneypotGuard(
            $this->plugin->getHoneypotFieldName($contextId),
            $this->plugin->getSecret($contextId),
            $this->plugin->getIntSetting($contextId, 'honeypotMinSeconds'),
            $this->plugin->getIntSetting($contextId, 'honeypotMaxSeconds')
        );
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
