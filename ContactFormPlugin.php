<?php

/**
 * @file ContactFormPlugin.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ContactFormPlugin
 *
 * @brief Adds a public, spam-resistant "Contact us" page to each journal.
 */

namespace APP\plugins\generic\contactForm;

use APP\core\Application;
use APP\plugins\generic\contactForm\classes\form\ContactFormSettingsForm;
use APP\plugins\generic\contactForm\classes\mailables\ContactFormAcknowledgement;
use APP\plugins\generic\contactForm\classes\mailables\ContactFormNotification;
use APP\plugins\generic\contactForm\classes\spam\HoneypotGuard;
use APP\plugins\generic\contactForm\classes\SubmissionRepository;
use APP\template\TemplateManager;
use Exception;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class ContactFormPlugin extends GenericPlugin
{
    /** Fallback URL segment for the public page, e.g. /index.php/journal/contactForm */
    public const DEFAULT_PAGE_PATH = 'contactForm';

    /** Navigation menu item type managers can add under Settings > Website > Navigation */
    public const NMI_TYPE_CONTACT_FORM = 'NMI_TYPE_CONTACT_FORM';

    /** How many messages the "Messages" modal lists per page */
    public const MESSAGES_PER_PAGE = 25;

    /** Don't attempt an opportunistic retention purge more often than this */
    public const PURGE_INTERVAL_SECONDS = 86400;

    /** Hard ceiling on CC recipients, however many are configured */
    public const MAX_CC_RECIPIENTS = 10;

    /**
     * Default value for every setting this plugin reads.
     *
     * Keeping them in one place means a missing or never-saved setting behaves
     * identically everywhere instead of each call site inventing a fallback.
     */
    public const SETTING_DEFAULTS = [
        'pagePath' => self::DEFAULT_PAGE_PATH,
        'recipientEmail' => '',
        'recipientName' => '',
        'ccEmails' => '',
        'requireSubject' => true,
        'enableAcknowledgement' => false,
        'honeypotMinSeconds' => 3,
        'honeypotMaxSeconds' => 7200,
        'maxUrls' => 2,
        'blocklist' => '',
        'maxPerHourPerIp' => 5,
        'maxPerHourPerContext' => 50,
        'retentionDays' => 365,
    ];

    /** @var SubmissionRepository|null Lazily constructed */
    private $submissionRepository = null;

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.contactForm.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.contactForm.description');
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        // Nothing below is safe (or useful) before the database exists.
        if (!Application::isInstalled() || Application::isUpgrading()) {
            return true;
        }

        if ($this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', [$this, 'setPageHandler']);
            Hook::add('NavigationMenus::itemTypes', [$this, 'addNavigationMenuItemType']);
            Hook::add('NavigationMenus::displaySettings', [$this, 'setNavigationMenuItemDisplayDetails']);
            Hook::add('Mailer::Mailables', [$this, 'addMailables']);
            Hook::add('AcronPlugin::parseCronTab', [$this, 'addScheduledTask']);
        }

        return true;
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     */
    public function getInstallMigration()
    {
        return new ContactFormSchemaMigration();
    }

    /**
     * @copydoc Plugin::getInstallEmailTemplatesFile()
     */
    public function getInstallEmailTemplatesFile()
    {
        return $this->getPluginPath() . '/emailTemplates.xml';
    }

    //
    // Hook callbacks
    //

    /**
     * Serve the public contact form from this plugin's own handler.
     *
     * LoadHandler is called with [&$page, &$op, &$sourceFile, &$handler]; the
     * array elements are references, so assigning $args[3] hands the router our
     * handler instance.
     *
     * @param string $hookName
     * @param array $args
     *
     * @return bool True iff this request was claimed.
     */
    public function setPageHandler($hookName, $args)
    {
        $page = $args[0];
        $op = $args[1];
        $handler = &$args[3];

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return false;
        }

        if ($page !== $this->getPagePath($context->getId())) {
            return false;
        }

        // Only the operations the handler actually implements; anything else on
        // this path should 404 rather than surprise us.
        if (!in_array($op, ['index', 'submit'], true)) {
            return false;
        }

        $handler = new ContactFormHandler($this);
        return true;
    }

    /**
     * Offer a "Contact form" navigation menu item type so managers can place
     * the link wherever they want it.
     *
     * @param string $hookName
     * @param array $args [&$types]
     */
    public function addNavigationMenuItemType($hookName, $args)
    {
        $types = &$args[0];
        $types[self::NMI_TYPE_CONTACT_FORM] = [
            'title' => __('plugins.generic.contactForm.navigationMenuItem'),
            'description' => __('plugins.generic.contactForm.navigationMenuItem.description'),
        ];
        return false;
    }

    /**
     * Point the navigation menu item at this journal's contact form page.
     *
     * @param string $hookName
     * @param array $args [$navigationMenuItem, $navigationMenu]
     */
    public function setNavigationMenuItemDisplayDetails($hookName, $args)
    {
        $navigationMenuItem = $args[0];
        if ($navigationMenuItem->getType() !== self::NMI_TYPE_CONTACT_FORM) {
            return false;
        }

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            $navigationMenuItem->setIsDisplayed(false);
            return false;
        }

        $navigationMenuItem->setUrl($request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            null,
            $this->getPagePath($context->getId()),
            'index'
        ));
        $navigationMenuItem->setIsDisplayed(true);
        return false;
    }

    /**
     * Expose this plugin's mailables so managers can edit their text under
     * Settings > Workflow > Emails.
     *
     * @param string $hookName
     * @param array $args [$mailables (Illuminate\Support\Collection), $context]
     */
    public function addMailables($hookName, $args)
    {
        $mailables = $args[0];
        $mailables->push(ContactFormNotification::class);
        $mailables->push(ContactFormAcknowledgement::class);
        return false;
    }

    /**
     * Register the retention purge task with the Acron plugin.
     *
     * @param string $hookName
     * @param array $args [&$taskFilesPath]
     */
    public function addScheduledTask($hookName, $args)
    {
        $taskFilesPath = &$args[0];
        $taskFilesPath[] = $this->getPluginPath() . '/scheduledTasks.xml';
        return false;
    }

    //
    // Management interface
    //

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $router = $request->getRouter();
        $url = function (string $verb) use ($request, $router): string {
            return $router->url($request, null, null, 'manage', null, [
                'verb' => $verb,
                'plugin' => $this->getName(),
                'category' => 'generic',
            ]);
        };

        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal($url('settings'), $this->getDisplayName()),
                    __('manager.plugins.settings'),
                    null
                ),
                new LinkAction(
                    'messages',
                    new AjaxModal($url('messages'), __('plugins.generic.contactForm.messages')),
                    __('plugins.generic.contactForm.messages'),
                    null
                ),
            ] : [],
            parent::getActions($request, $actionArgs)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        $context = $request->getContext();
        if (!$context) {
            return new JSONMessage(false);
        }

        switch ($request->getUserVar('verb')) {
            case 'settings':
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->registerPlugin('function', 'plugin_url', [$this, 'smartyPluginUrl']);

                $form = new ContactFormSettingsForm($this, $context->getId());
                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }
                return new JSONMessage(true, $form->fetch($request));

            case 'deleteMessage':
                // State-changing: require a real POST carrying this session's token.
                if (!$request->isPost() || !$request->checkCSRF()) {
                    return new JSONMessage(false);
                }
                $this->getSubmissionRepository()
                    ->delete((int) $request->getUserVar('submissionId'), $context->getId());
                // Then re-render the list.
                // no break
            case 'messages':
                return new JSONMessage(true, $this->renderMessages($request));
        }

        return parent::manage($args, $request);
    }

    /**
     * Render the paginated list of stored messages for the management modal.
     *
     * @param \PKP\core\PKPRequest $request
     */
    private function renderMessages($request): string
    {
        $contextId = $request->getContext()->getId();

        $this->maybePurge($contextId);

        $repository = $this->getSubmissionRepository();
        $total = $repository->getCount($contextId);
        $lastPage = max(1, (int) ceil($total / self::MESSAGES_PER_PAGE));
        $page = min(max(1, (int) $request->getUserVar('messagePage')), $lastPage);

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->getName(),
            'submissions' => $repository->getMany(
                $contextId,
                ($page - 1) * self::MESSAGES_PER_PAGE,
                self::MESSAGES_PER_PAGE
            ),
            'messageTotal' => $total,
            'messagePage' => $page,
            'messageLastPage' => $lastPage,
            'retentionDays' => $this->getIntSetting($contextId, 'retentionDays'),
        ]);

        return $templateMgr->fetch($this->getTemplateResource('messages.tpl'));
    }

    //
    // Settings access
    //

    public function getSubmissionRepository(): SubmissionRepository
    {
        if ($this->submissionRepository === null) {
            $this->submissionRepository = new SubmissionRepository();
        }
        return $this->submissionRepository;
    }

    /**
     * Read a setting, falling back to its documented default when it has never
     * been saved. Only null triggers the fallback, so a deliberately saved
     * false or 0 is respected.
     *
     * @return mixed
     */
    public function getSettingOrDefault(int $contextId, string $name)
    {
        $value = $this->getSetting($contextId, $name);
        return $value === null ? (self::SETTING_DEFAULTS[$name] ?? null) : $value;
    }

    public function getStringSetting(int $contextId, string $name): string
    {
        $value = $this->getSettingOrDefault($contextId, $name);
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function getIntSetting(int $contextId, string $name): int
    {
        return (int) $this->getSettingOrDefault($contextId, $name);
    }

    public function getBoolSetting(int $contextId, string $name): bool
    {
        return (bool) $this->getSettingOrDefault($contextId, $name);
    }

    /**
     * The URL segment the public page is served from.
     *
     * Restricted to letters and digits: the page component of an OJS URL is
     * sanitised by the router, so anything else would never match.
     */
    public function getPagePath(int $contextId): string
    {
        $path = $this->getStringSetting($contextId, 'pagePath');
        return preg_match('/^[A-Za-z0-9]+$/', $path) ? $path : self::DEFAULT_PAGE_PATH;
    }

    /**
     * Where notifications go. Falls back to the journal's principal contact so
     * the form is never silently sending mail nowhere.
     *
     * @param \APP\journal\Journal $context
     */
    public function getRecipientEmail($context): string
    {
        $configured = $this->getStringSetting($context->getId(), 'recipientEmail');
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) {
            return $configured;
        }
        return (string) $context->getData('contactEmail');
    }

    /**
     * @param \APP\journal\Journal $context
     */
    public function getRecipientName($context): string
    {
        $configured = $this->getStringSetting($context->getId(), 'recipientName');
        return $configured !== '' ? $configured : (string) $context->getData('contactName');
    }

    /**
     * Parse the configured CC list into validated addresses.
     *
     * @return string[]
     */
    public function getCcEmails(int $contextId): array
    {
        $raw = $this->getStringSetting($contextId, 'ccEmails');
        if ($raw === '') {
            return [];
        }

        $addresses = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $addresses[strtolower($candidate)] = $candidate;
            }
        }

        return array_slice(array_values($addresses), 0, self::MAX_CC_RECIPIENTS);
    }

    /**
     * The decoy field's name, generated once per journal and then reused so
     * that caches and in-flight forms stay valid.
     */
    public function getHoneypotFieldName(int $contextId): string
    {
        $name = $this->getSetting($contextId, 'honeypotFieldName');
        if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{2,63}$/', $name)) {
            $name = HoneypotGuard::generateFieldName();
            $this->updateSetting($contextId, 'honeypotFieldName', $name, 'string');
        }
        return $name;
    }

    public function regenerateHoneypotFieldName(int $contextId): string
    {
        $name = HoneypotGuard::generateFieldName();
        $this->updateSetting($contextId, 'honeypotFieldName', $name, 'string');
        return $name;
    }

    /**
     * Per-journal secret used to sign form timestamps and hash client IPs.
     * Generated on first use; never leaves the server.
     */
    public function getSecret(int $contextId): string
    {
        $secret = $this->getSetting($contextId, 'formSecret');
        if (!is_string($secret) || strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            $this->updateSetting($contextId, 'formSecret', $secret, 'string');
        }
        return $secret;
    }

    /**
     * Hash a client address for throttling. The raw address is never stored,
     * and the per-journal secret makes the hashes useless elsewhere.
     */
    public function hashIp(int $contextId, ?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        return hash_hmac('sha256', $ip, $this->getSecret($contextId));
    }

    /**
     * Delete messages past their retention window, at most once a day.
     *
     * The scheduled task does this properly; this is the safety net for
     * installations that never run acron or cron.
     */
    public function maybePurge(int $contextId): void
    {
        $retentionDays = $this->getIntSetting($contextId, 'retentionDays');
        if ($retentionDays < 1) {
            return;
        }

        $lastPurgeAt = (int) $this->getSetting($contextId, 'lastPurgeAt');
        if ($lastPurgeAt > time() - self::PURGE_INTERVAL_SECONDS) {
            return;
        }

        // Record the attempt first: a failing purge must not be retried on
        // every page load.
        $this->updateSetting($contextId, 'lastPurgeAt', time(), 'int');

        try {
            $this->getSubmissionRepository()->purgeOlderThan($contextId, $retentionDays);
        } catch (Exception $exception) {
            error_log('contactForm: retention purge failed: ' . $exception->getMessage());
        }
    }
}
