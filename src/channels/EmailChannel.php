<?php

namespace frontendservices\mailcraft\channels;

use Craft;
use craft\elements\User;
use craft\mail\Mailer;
use craft\mail\Message;
use frontendservices\mailcraft\base\AbstractChannel;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\variables\MailCraftVariable;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * Sends a template by email through Craft's mailer.
 *
 * Its per-template settings (to, toName, cc, bcc, from, fromName, replyTo) live in their own
 * columns on the template, not in the `channels` JSON, so sites that predate channels keep
 * their data untouched.
 */
class EmailChannel extends AbstractChannel
{
    public function getHandle(): string
    {
        return 'email';
    }

    public function getName(): string
    {
        return Craft::t('mailcraft', 'Email');
    }

    public function getTemplateSettingsHtml(EmailTemplate $template): string
    {
        return Craft::$app->getView()->renderTemplate('mailcraft/channels/email/_template', [
            'emailTemplate' => $template,
            'mailDefaults' => (new MailCraftVariable())->getSystemMailDefaults(),
            'pluginSettings' => MailCraft::getInstance()->getSettings(),
        ]);
    }

    /**
     * One payload per recipient address. Names are matched to addresses by position when
     * there are several recipients; a single recipient gets the whole name field.
     */
    public function buildDeliveries(EmailTemplate $template, array $content, array $variables): array
    {
        $base = [
            'subject' => (string)($content['subject'] ?? ''),
            'cc' => $this->render($template->cc, $variables),
            'bcc' => $this->render($template->bcc, $variables),
            'from' => $this->render($template->from, $variables),
            'fromName' => $this->render($template->fromName, $variables),
            'replyTo' => $this->render($template->replyTo, $variables),
            'message' => (string)($content['body'] ?? ''),
        ];

        $renderedTo = $this->render($template->to, $variables);
        $renderedToName = $this->render($template->toName, $variables);

        $emails = array_map('trim', explode(',', (string)$renderedTo));

        $names = [];
        if (count($emails) > 1) {
            $names = explode(',', (string)$renderedToName);
        } else {
            $names[] = $renderedToName;
        }

        $payloads = [];
        foreach ($emails as $index => $email) {
            $payload = $base;
            $payload['to'] = $email;
            $payload['toName'] = $names[$index] ?? null;
            $payloads[] = $payload;
        }

        return $payloads;
    }

    public function deliver(array $payload): bool
    {
        $this->lastError = null;

        try {
            $message = new Message();

            $message->setCharset('utf-8');
            $message->setSubject($payload['subject'] ?? '');
            if (!empty($payload['toName'])) {
                $message->setTo([$payload['to'] => $payload['toName']]);
            } else {
                $message->setTo($payload['to'] ?? null);
            }
            $message->setCc($payload['cc'] ?? null);
            $message->setBcc($payload['bcc'] ?? null);
            if (!empty($payload['from'])) {
                if (!empty($payload['fromName'])) {
                    $message->setFrom([$payload['from'] => $payload['fromName']]);
                } else {
                    $message->setFrom($payload['from']);
                }
            }
            $message->setReplyTo($payload['replyTo'] ?? null);
            $message->setHtmlBody($payload['message'] ?? '');

            $logger = Craft::getLogger();
            $logIndex = count($logger->messages);

            if (Craft::$app->mailer->send($message)) {
                return true;
            }

            $this->lastError = $this->findMailerError($logger->messages, $logIndex)
                ?? 'The mailer returned false without an error message.';
            Craft::error('Error sending email: ' . $this->lastError, __METHOD__);

            return false;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Craft::error('Error sending email: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    public function supportsTestSend(): bool
    {
        return true;
    }

    public function sendTest(EmailTemplate $template, User $user): bool
    {
        $emailService = MailCraft::getInstance()->emailService;

        $payload = $emailService->buildEmailVariables($template, $emailService->getSampleData($template->event));
        $payload['to'] = $user->email;
        $payload['toName'] = $user->fullName ?: null;
        $payload['subject'] = '[' . Craft::t('mailcraft', 'Test') . '] ' . ($payload['subject'] ?? '');

        return $this->deliver($payload);
    }

    public function getTemplateSummary(EmailTemplate $template): array
    {
        return [
            Craft::t('mailcraft', 'To') => (string)$template->to,
            Craft::t('mailcraft', 'From') => $template->from ?: Craft::t('mailcraft', 'System default'),
        ];
    }

    /**
     * Renders a template string, returning '' when it fails to compile so one bad field
     * does not stop the delivery.
     */
    private function render(?string $string, array $variables): ?string
    {
        try {
            return MailCraft::getInstance()->emailService->renderTemplateString($string, $variables);
        } catch (LoaderError|SyntaxError $e) {
            Craft::error('Error rendering email template: ' . $e->getMessage(), __METHOD__);
        }

        return '';
    }

    /**
     * Finds the most recent message logged by Craft's mailer.
     *
     * @param array $messages Yii logger message buffer (`[text, level, category, time, traces, memory]`)
     * @param int $fromIndex Only look at messages logged from this index onwards
     */
    private function findMailerError(array $messages, int $fromIndex): ?string
    {
        $keys = array_keys($messages);
        $start = count($keys) >= $fromIndex ? $fromIndex : 0;

        for ($i = count($keys) - 1; $i >= $start; $i--) {
            $entry = $messages[$keys[$i]];
            $text = $entry[0] ?? null;
            $category = $entry[2] ?? null;

            if ($category === Mailer::class . '::send' && is_string($text)) {
                return preg_replace('/^Error sending email: /', '', $text);
            }
        }

        return null;
    }
}
