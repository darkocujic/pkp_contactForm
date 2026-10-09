<?php

/**
 * @file classes/form/ContactFormSettingsForm.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ContactFormSettingsForm
 *
 * @brief Journal-level settings for the contact form.
 */

namespace APP\plugins\generic\contactForm\classes\form;

use APP\core\Application;
use APP\plugins\generic\contactForm\ContactFormPlugin;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorEmail;
use PKP\form\validation\FormValidatorPost;

class ContactFormSettingsForm extends Form
{
    /** Settings stored as a locale => value array. */
    public const LOCALIZED_FIELDS = ['pageTitle', 'introText'];

    /** Settings stored as a plain string. */
    public const TEXT_FIELDS = ['pagePath', 'recipientEmail', 'recipientName', 'ccEmails', 'blocklist'];

    /** Settings stored as a boolean. */
    public const BOOLEAN_FIELDS = ['requireSubject', 'enableAcknowledgement'];

    /** Settings stored as a non-negative integer. */
    public const INTEGER_FIELDS = [
        'honeypotMinSeconds',
        'honeypotMaxSeconds',
        'maxUrls',
        'maxPerHourPerIp',
        'maxPerHourPerContext',
        'retentionDays',
    ];

    private ContactFormPlugin $plugin;
    private int $contextId;

    public function __construct(ContactFormPlugin $plugin, int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->plugin = $plugin;
        $this->contextId = $contextId;

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));

        $this->addCheck(new FormValidator(
            $this,
            'pagePath',
            FormValidator::FORM_VALIDATOR_REQUIRED_VALUE,
            'plugins.generic.contactForm.settings.error.pagePath'
        ));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'pagePath',
            FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
            'plugins.generic.contactForm.settings.error.pagePath',
            fn ($value) => (bool) preg_match('/^[A-Za-z0-9]+$/', (string) $value)
        ));

        $this->addCheck(new FormValidatorEmail(
            $this,
            'recipientEmail',
            FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
            'plugins.generic.contactForm.settings.error.recipientEmail'
        ));

        $this->addCheck(new FormValidatorCustom(
            $this,
            'ccEmails',
            FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
            'plugins.generic.contactForm.settings.error.ccEmails',
            [self::class, 'areAllEmailsValid']
        ));

        foreach (self::INTEGER_FIELDS as $field) {
            $this->addCheck(new FormValidatorCustom(
                $this,
                $field,
                FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
                'plugins.generic.contactForm.settings.error.number',
                [self::class, 'isNonNegativeInteger']
            ));
        }

        // A minimum above the maximum would reject every submission, so catch
        // it here rather than in production.
        $this->addCheck(new FormValidatorCustom(
            $this,
            'honeypotMinSeconds',
            FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
            'plugins.generic.contactForm.settings.error.timing',
            function ($value) {
                $min = (int) $value;
                $max = (int) $this->getData('honeypotMaxSeconds');
                return $min === 0 || $max === 0 || $min < $max;
            }
        ));
    }

    /**
     * Every entry in a separated list of addresses must be a valid address.
     *
     */
    public static function areAllEmailsValid($value): bool
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return true;
        }

        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            if (!filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
        }

        return true;
    }

    /**
     */
    public static function isNonNegativeInteger($value): bool
    {
        $raw = trim((string) $value);
        return $raw === '' || ctype_digit($raw);
    }

    /**
     * @copydoc Form::getLocaleFieldNames()
     */
    public function getLocaleFieldNames()
    {
        return self::LOCALIZED_FIELDS;
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData()
    {
        foreach (self::TEXT_FIELDS as $field) {
            $this->setData($field, $this->plugin->getStringSetting($this->contextId, $field));
        }
        foreach (self::BOOLEAN_FIELDS as $field) {
            $this->setData($field, $this->plugin->getBoolSetting($this->contextId, $field));
        }
        foreach (self::INTEGER_FIELDS as $field) {
            $this->setData($field, $this->plugin->getIntSetting($this->contextId, $field));
        }
        foreach (self::LOCALIZED_FIELDS as $field) {
            $value = $this->plugin->getSetting($this->contextId, $field);
            $this->setData($field, is_array($value) ? $value : []);
        }

        $this->setData('regenerateHoneypotField', false);

        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars(array_merge(
            self::TEXT_FIELDS,
            self::BOOLEAN_FIELDS,
            self::INTEGER_FIELDS,
            self::LOCALIZED_FIELDS,
            ['regenerateHoneypotField']
        ));
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'honeypotFieldName' => $this->plugin->getHoneypotFieldName($this->contextId),
            'captchaConfigured' => ContactForm::isCaptchaConfigured(),
            'defaultPagePath' => ContactFormPlugin::DEFAULT_PAGE_PATH,
            'settingDefaults' => ContactFormPlugin::SETTING_DEFAULTS,
            'contactFormUrl' => $request->getDispatcher()->url(
                $request,
                Application::ROUTE_PAGE,
                $request->getContext()->getPath(),
                $this->plugin->getPagePath($this->contextId),
                'index'
            ),
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        foreach (self::TEXT_FIELDS as $field) {
            $this->plugin->updateSetting($this->contextId, $field, trim((string) $this->getData($field)), 'string');
        }

        foreach (self::BOOLEAN_FIELDS as $field) {
            $this->plugin->updateSetting($this->contextId, $field, (bool) $this->getData($field), 'bool');
        }

        foreach (self::INTEGER_FIELDS as $field) {
            $value = trim((string) $this->getData($field));
            // An empty box means "use the shipped default" rather than zero,
            // which for a rate limit would mean "unlimited".
            $value = $value === ''
                ? (int) (ContactFormPlugin::SETTING_DEFAULTS[$field] ?? 0)
                : (int) $value;
            $this->plugin->updateSetting($this->contextId, $field, $value, 'int');
        }

        foreach (self::LOCALIZED_FIELDS as $field) {
            $value = $this->getData($field);
            $this->plugin->updateSetting($this->contextId, $field, is_array($value) ? $value : [], 'object');
        }

        if ($this->getData('regenerateHoneypotField')) {
            $this->plugin->regenerateHoneypotFieldName($this->contextId);
        }

        parent::execute(...$functionArgs);
    }
}
