<?php

/**
 * @file ContactFormHandler.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ContactFormHandler
 *
 * @brief Serves the public contact form page and processes submissions.
 */

namespace APP\plugins\generic\contactForm;

use APP\handler\Handler;
use APP\plugins\generic\contactForm\classes\form\ContactForm;
use APP\template\TemplateManager;
use PKP\security\authorization\ContextRequiredPolicy;

class ContactFormHandler extends Handler
{
    private ContactFormPlugin $plugin;

    public function __construct(ContactFormPlugin $plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Show the form.
     *
     * @param array $args
     * @param \PKP\core\PKPRequest $request
     */
    public function index($args, $request)
    {
        $this->prepare($request);

        $form = new ContactForm($this->plugin, $request);
        $form->initData();

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('contactFormSent', (bool) $request->getUserVar('sent'));

        $form->fetch($request, null, true);
    }

    /**
     * Validate and send a submission.
     *
     * On success this redirects, so a refresh cannot resend the message.
     *
     * @param array $args
     * @param \PKP\core\PKPRequest $request
     */
    public function submit($args, $request)
    {
        $this->prepare($request);

        $form = new ContactForm($this->plugin, $request);
        $form->readInputData();

        if (!$form->validate()) {
            $form->fetch($request, null, true);
            return;
        }

        $form->execute();

        if (!$form->wasStored() && !$form->wasDelivered()) {
            // Nothing was kept and nothing was sent: say so rather than
            // thanking the visitor for a message that went nowhere.
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign('contactFormFailed', true);
            $form->fetch($request, null, true);
            return;
        }

        $context = $request->getContext();
        $request->redirect(
            null,
            $this->plugin->getPagePath($context->getId()),
            'index',
            null,
            ['sent' => 1]
        );
    }

    /**
     * Shared setup for both operations.
     *
     * @param \PKP\core\PKPRequest $request
     */
    private function prepare($request): void
    {
        $this->setupTemplate($request);

        // The page carries a CSRF token and a signed render time, so it must
        // never be cached by a proxy or the browser. Form::fetch() also sets
        // this, but index() should not depend on that.
        TemplateManager::getManager($request)
            ->setCacheability(TemplateManager::CACHEABILITY_NO_STORE);
    }
}
