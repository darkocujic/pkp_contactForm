<?php

/**
 * @file classes/tasks/PurgeSubmissions.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PurgeSubmissions
 *
 * @brief Deletes archived contact form messages past their retention window.
 */

namespace APP\plugins\generic\contactForm\classes\tasks;

use APP\core\Application;
use APP\plugins\generic\contactForm\ContactFormPlugin;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;
use Throwable;

class PurgeSubmissions extends ScheduledTask
{
    /**
     * @copydoc ScheduledTask::getName()
     */
    public function getName()
    {
        return __('plugins.generic.contactForm.task.purge');
    }

    /**
     * @copydoc ScheduledTask::executeActions()
     */
    protected function executeActions()
    {
        $plugin = $this->getPlugin();
        if (!$plugin) {
            $this->addExecutionLogEntry(
                __('plugins.generic.contactForm.task.purge.notInstalled'),
                ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING
            );
            return false;
        }

        $removed = 0;
        $contexts = Application::getContextDAO()->getAll();

        while ($context = $contexts->next()) {
            $contextId = (int) $context->getId();

            if (!$plugin->getEnabled($contextId)) {
                continue;
            }

            $retentionDays = $plugin->getIntSetting($contextId, 'retentionDays');
            if ($retentionDays < 1) {
                // 0 means "keep indefinitely".
                continue;
            }

            try {
                $removed += $plugin->getSubmissionRepository()->purgeOlderThan($contextId, $retentionDays);
                // Keep the opportunistic purge from repeating this work today.
                $plugin->updateSetting($contextId, 'lastPurgeAt', time(), 'int');
            } catch (Throwable $exception) {
                $this->addExecutionLogEntry(
                    'contactForm: ' . $exception->getMessage(),
                    ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_ERROR
                );
                return false;
            }
        }

        $this->addExecutionLogEntry(
            __('plugins.generic.contactForm.task.purge.removed', ['count' => $removed]),
            ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_COMPLETED
        );

        return true;
    }

    /**
     * The task runs from the CLI, where the plugin may not be registered yet.
     */
    private function getPlugin(): ?ContactFormPlugin
    {
        $plugin = PluginRegistry::getPlugin('generic', ContactFormPlugin::REGISTRY_NAME);

        if (!$plugin instanceof ContactFormPlugin) {
            PluginRegistry::loadCategory('generic', true);
            $plugin = PluginRegistry::getPlugin('generic', ContactFormPlugin::REGISTRY_NAME);
        }

        return $plugin instanceof ContactFormPlugin ? $plugin : null;
    }
}
