<?php
namespace frontendservices\mailcraft\base;

use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

abstract class AbstractEventProvider
{
    /**
     * Get the internal event ID. E.q. `entry.update`
     *
     * @return string
     */
    abstract public function getEventId(): string;

    /**
     * Get array with details of the event. E.q.
     * ```
     * return [
     *   'label' => 'Entry Update',
     *   'group' => 'entries',
     * ];
     * ```
     *
     * @return array
     */
    abstract public function getEventDetails(): array;

    /**
     * Register event listener. When the event is triggered, the handler will be called.
     *
     * @param callable $handler
     * @return void
     */
    abstract public function registerEventListener(callable $handler): void;

//    abstract public function getSampleData(): array;

    /**
     * Get template variables for the event. This is used to generate the template.
     *
     * @return array
     */
    abstract public function getTemplateVariables(): array;

    /**
     * Get the example template to start with.
     * Returns an array:
     * ```
     * return [
     *   'title' => 'Entry Update Notification', // admin area title
     *   'subject' => 'Content updated: {{entry.title}}', // email subject
     *   'template' => '<p>Entry {{entry.title}} has been updated</p>', // Twig template
     * ];
     *
     * @return array
     */
    abstract public function getTemplateExample(): array;

    /**
     * Get the main conditions used for email template editor in Craft Admin.
     * E.q.:
     * ```
     * return [
     *    'condition1' => [
     *       'operand' => 'entry.section.handle == condition',
     *       'name' => Craft::t('mailcraft', 'Section'),
     *       'options' => MailCraft::getInstance()->conditionService->getEntrySections(),
     *       'dependant' => true,
     *    ],
     *    'condition2' => [
     *       'operand' => 'entry.type.handle == condition',
     *       'name' => Craft::t('mailcraft', 'Entry Type'),
     *       'options' => MailCraft::getInstance()->conditionService->getEntryTypes(),
     *    ],
     * ];
     * ```
     *
     * @return array
     */
    abstract public function getConditions(): array;

    /**
     * Get the conditions for the event. This is used to determine if email should be sent.
     *
     * @param $template
     * @param $variables
     * @return bool
     * @throws LoaderError
     * @throws SyntaxError
     */
    abstract public function testConditions($template, $variables): bool;
}