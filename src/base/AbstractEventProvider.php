<?php
namespace frontendservices\mailcraft\base;

use Craft;

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
     */
    abstract public function testConditions($template, $variables): bool;

    /**
     * Evaluates the template's free-form Twig "Extra Conditions" expression against the
     * event variables.
     *
     * Returns true when the field is empty. Returns false, and logs a warning, when the
     * expression fails to compile or evaluate, so a broken condition only silences its own
     * template.
     *
     * @param $template The EmailTemplate element
     * @param array $variables Event variables passed to the Twig expression
     * @return bool
     */
    protected function matchesExtraConditions($template, array $variables): bool
    {
        $expression = trim((string)($template->conditions ?? ''));

        if ($expression === '') {
            return true;
        }

        // Strip the outer {{ }} if the user included them
        if (str_starts_with($expression, '{{') && str_ends_with($expression, '}}')) {
            $expression = trim(substr($expression, 2, -2));
        }

        try {
            $result = Craft::$app->getView()->renderString(
                '{{ (' . $expression . ') ? 1 : 0 }}',
                $variables
            );

            return trim($result) === '1';
        } catch (\Throwable $e) {
            Craft::warning(sprintf(
                'MailCraft: extra conditions of email template "%s" (#%s, event %s) could not be evaluated and the email was skipped: %s',
                $template->title ?? '',
                $template->id ?? '?',
                $this->getEventId(),
                $e->getMessage()
            ), __METHOD__);

            return false;
        }
    }
}