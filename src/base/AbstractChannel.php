<?php

namespace frontendservices\mailcraft\base;

use craft\elements\User;
use frontendservices\mailcraft\elements\EmailTemplate;

/**
 * A delivery channel: one way of sending a rendered template (email, Slack, ...).
 */
abstract class AbstractChannel
{
    /**
     * The reason the most recent deliver() call failed, if known.
     */
    protected ?string $lastError = null;

    /**
     * Stable key used in the database, project config and form field names. E.g. `email`.
     */
    abstract public function getHandle(): string;

    /**
     * Human-readable name used for the tab label and settings checkbox. E.g. "Email".
     */
    abstract public function getName(): string;

    /**
     * Renders the fields shown on this channel's tab of the template editor, below the
     * enable switch. Field names must be namespaced `channels[<handle>][...]`, except for
     * the email channel whose fields are stored in their own columns.
     */
    abstract public function getTemplateSettingsHtml(EmailTemplate $template): string;

    /**
     * Validates this channel's per-template settings and adds errors to the element.
     * Only called when the channel is enabled on the template. Errors for settings stored
     * in the `channels` JSON are added under `channels.<handle>.<field>`.
     */
    public function validateTemplateSettings(EmailTemplate $template): void
    {
    }

    /**
     * Fields for the plugin settings page (e.g. a webhook URL), or null when the channel
     * has no global settings. Field names are namespaced by the caller.
     *
     * @param array $settings The channel's current global settings
     * @param array<string, string[]> $errors Validation errors from validateGlobalSettings(), `field => [messages]`
     */
    public function getGlobalSettingsHtml(array $settings, array $errors = []): ?string
    {
        return null;
    }

    /**
     * Validates the channel's global settings.
     *
     * @param array $settings
     * @return array<string, string[]> `field => [messages]`
     */
    public function validateGlobalSettings(array $settings): array
    {
        return [];
    }

    /**
     * Turns one rendered message into zero or more delivery payloads.
     *
     * Each payload is a plain, serialisable array that is handed to deliver(), possibly
     * from a queue job much later. The email channel returns one payload per recipient.
     *
     * @param EmailTemplate $template
     * @param array $content `['subject' => string, 'body' => string]`, already rendered
     * @param array $variables The raw event variables, in case channel settings contain Twig
     * @return array<int, array>
     */
    abstract public function buildDeliveries(EmailTemplate $template, array $content, array $variables): array;

    /**
     * Performs one delivery. Returns false on failure and records the reason for getLastError().
     */
    abstract public function deliver(array $payload): bool;

    /**
     * The reason the most recent deliver() call failed, if known.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Whether "Send a test to me" can use this channel.
     */
    public function supportsTestSend(): bool
    {
        return false;
    }

    /**
     * Sends the template, rendered with sample data, to the given control panel user.
     * Only called when supportsTestSend() is true.
     */
    public function sendTest(EmailTemplate $template, User $user): bool
    {
        return false;
    }

    /**
     * Rows for the sidebar summary on the template editor.
     *
     * @return array<string, string> `label => value`
     */
    public function getTemplateSummary(EmailTemplate $template): array
    {
        return [];
    }
}
