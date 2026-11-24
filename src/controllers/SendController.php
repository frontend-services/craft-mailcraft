<?php

namespace frontendservices\mailcraft\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\elements\User;
use craft\web\Controller;
use frontendservices\mailcraft\channels\PrivateMessageChannel;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\helpers\PreviewHelper;
use frontendservices\mailcraft\MailCraft;
use DateTime;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The Send page controller.
 */
class SendController extends Controller
{
    private const FORM_FLASH = 'mailcraft.send.form';
    private const SAMPLE_SIZE = 10;

    /**
     * @inheritdoc
     * @throws ForbiddenHttpException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('mailcraft:sendToUsers');

        return true;
    }

    /**
     * The Send page. `templateId` in the query string preselects a template with its audience and channels.
     */
    public function actionIndex(): Response
    {
        $plugin = MailCraft::getInstance();
        $sends = $plugin->sends;
        $audiences = $plugin->audiences;
        $user = Craft::$app->getUser();

        $templates = [];
        foreach (EmailTemplate::find()->event('manual')->orderBy(['title' => SORT_ASC])->all() as $t) {
            /** @var EmailTemplate $t */
            $available = $sends->availableChannels($t);
            if ($available === []) {
                continue;
            }
            $templates[] = [
                'id' => (int)$t->id,
                'title' => (string)$t->title,
                'channels' => $available,
            ];
        }

        $channels = [];
        foreach ($plugin->channelRegistry->getAllowedChannels() as $handle => $channel) {
            $channels[$handle] = $channel->getName();
        }

        $form = Craft::$app->getSession()->getFlash(self::FORM_FLASH, null, true);
        $form = is_array($form) ? $form : null;

        $templateId = (int)($form['templateId'] ?? $this->request->getQueryParam('templateId'));
        $selected = null;
        foreach ($templates as $row) {
            if ($row['id'] === $templateId) {
                $selected = $row;
            }
        }

        if ($form !== null) {
            $mode = ($form['mode'] ?? '') === 'template' ? 'template' : 'custom';
            $audience = $audiences->normalize($form['audience'] ?? []);
            $checkedChannels = (array)($form['channels'] ?? []);
        } else {
            $mode = $selected !== null ? 'template' : 'custom';
            $audience = [];
            $checkedChannels = $selected['channels'] ?? [];
        }

        return $this->renderTemplate('mailcraft/send/index', [
            'templates' => $templates,
            'selectedTemplate' => $selected,
            'channels' => $channels,
            'messageTypes' => $plugin->getSettings()->getPrivateMessageTypes(),
            'recent' => $sends->getRecent(20),
            'canEditTemplates' => $user->checkPermission('mailcraft:manageEmailTemplates'),
            'canViewStatus' => $user->checkPermission('mailcraft:viewStatus'),
            'audience' => $audience,
            'errors' => (array)($form['errors'] ?? []),
            'mode' => $mode,
            'templateId' => $selected['id'] ?? null,
            'subject' => (string)($form['subject'] ?? ''),
            'template' => (string)($form['template'] ?? ''),
            'pmType' => $form !== null
                ? (string)($form['pmType'] ?? '')
                : (string)(array_key_first($plugin->getSettings()->getPrivateMessageTypes()) ?? ''),
            'pmPinned' => !empty($form['pmPinned']),
            'pmMeta' => (array)($form['pmMeta'] ?? []),
            'pmData' => (array)($form['pmData'] ?? []),
            'pmExpiresDays' => (string)($form['pmExpiresDays'] ?? ''),
            'pmExpiresMode' => (string)($form['pmExpiresMode'] ?? ''),
            'pmDateExpires' => !empty($form['pmDateExpires'])
                ? (DateTime::createFromFormat('Y-m-d H:i:s', (string)$form['pmDateExpires']) ?: null)
                : null,
            'metaFields' => $plugin->getSettings()->getPrivateMessageMeta(),
            'dataFields' => $plugin->getSettings()->getAdditionalDataFields(),
            'checkedChannels' => $checkedChannels,
            'adhocTemplate' => new EmailTemplate(),
        ]);
    }

    /**
     * Live audience count (POST, JSON).
     */
    public function actionCount(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $audiences = MailCraft::getInstance()->audiences;
        $audience = $audiences->normalize($this->request->getBodyParam('audience'));
        $problems = $audiences->problems($audience);

        if ($problems !== []) {
            return $this->asJson(['success' => false, 'problems' => $problems]);
        }

        $count = $audiences->count($audience);

        // the first users in send order, so the admin can see audience preview
        $sample = [];
        foreach ($audiences->query($audience)->limit(self::SAMPLE_SIZE)->all() as $user) {
            /** @var User $user */
            $sample[] = ['name' => $user->getName(), 'email' => (string)$user->email];
        }

        return $this->asJson([
            'success' => true,
            'count' => $count,
            'label' => Craft::t('mailcraft', '{n} active users', ['n' => $count]),
            'sample' => $sample,
        ]);
    }

    /**
     * Sends the page's message to the current user through the ticked channels.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $form = $this->fromRequest();
        if ($form['errors'] !== []) {
            return $this->asJson(['success' => false, 'error' => implode("\n", $form['errors'])]);
        }
        $template = $form['template'];
        $user = Craft::$app->getUser()->getIdentity();

        $errors = $template === null
            ? [Craft::t('mailcraft', 'Choose a template.')]
            : MailCraft::getInstance()->sends->validateSend($template, $form['channels'], [], false);
        if ($errors !== []) {
            return $this->asJson(['success' => false, 'error' => implode("\n", $errors)]);
        }

        if (!$user || !$user->email) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('mailcraft', 'Your user account has no email address to send the test to.'),
            ]);
        }

        // only the ticked channels
        $test = clone $template;
        $channels = $test->channels;
        foreach (MailCraft::getInstance()->sends->availableChannels($template) as $handle) {
            if (!in_array($handle, $form['channels'], true)) {
                $channels[$handle]['enabled'] = false;
            }
        }
        $test->channels = $channels;

        $result = MailCraft::getInstance()->emailService->sendTestToUser($test, $user);

        if ($result['sent'] === [] && $result['errors'] === []) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('mailcraft', 'No enabled channel supports test sending.'),
            ]);
        }

        return $this->asJson([
            'success' => $result['sent'] !== [],
            'sent' => $result['sent'],
            'errors' => $result['errors'],
            'error' => $result['sent'] === [] ? implode("\n", $result['errors']) : null,
            'to' => $user->email,
        ]);
    }

    /**
     * Renders the page's message with sample data.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $form = $this->fromRequest();
        if ($form['errors'] !== []) {
            return $this->asJson(['success' => false, 'error' => implode("\n", $form['errors'])]);
        }
        $template = $form['template'];

        $errors = $template === null
            ? [Craft::t('mailcraft', 'Choose a template.')]
            : MailCraft::getInstance()->sends->validateSend($template, $form['channels'], [], false);
        if ($errors !== []) {
            return $this->asJson(['success' => false, 'error' => implode("\n", $errors)]);
        }

        $emailService = MailCraft::getInstance()->emailService;
        $sampleData = array_merge($emailService->getSampleData('manual'), ['subject' => '', 'content' => '', 'type' => '']);
        $channel = $this->request->getBodyParam('channel');
        $channel = is_string($channel) && $channel !== '' ? $channel : null;

        try {
            $content = $emailService->renderContentFor($template, $channel, $sampleData);

            $dataTable = '';
            $pm = MailCraft::getInstance()->channelRegistry->getChannels()[PrivateMessageChannel::HANDLE] ?? null;
            if ($pm instanceof PrivateMessageChannel && in_array(PrivateMessageChannel::HANDLE, $form['channels'], true)) {
                $dataTable = PreviewHelper::dataTable(
                    $pm->renderData($template, $sampleData) ?? [],
                    MailCraft::getInstance()->getSettings()->getAdditionalDataFields()
                );
            }

            return $this->asJson([
                'success' => true,
                'subject' => $content['subject'],
                'html' => PreviewHelper::wrap($content['body'], $dataTable),
            ]);
        } catch (Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Queues the send.
     */
    public function actionSend(): ?Response
    {
        $this->requirePostRequest();

        $form = $this->fromRequest();
        $notice = Craft::t('mailcraft', "Couldn't queue the send.");
        if ($form['errors'] !== []) {
            return $this->failWith($form, $form['errors'], $notice);
        }
        if ($form['template'] === null) {
            return $this->failWith($form, [Craft::t('mailcraft', 'Choose a template.')], $notice);
        }

        $sends = MailCraft::getInstance()->sends;
        $send = $sends->queue(
            $form['audience'],
            $form['template'],
            $form['channels'],
            (int)Craft::$app->getUser()->getId() ?: null,
        );

        if ($send === null) {
            return $this->failWith($form, [(string)$sends->getLastError()], $notice);
        }

        Craft::$app->getSession()->setNotice(Craft::t('mailcraft', 'Send queued: about {n} users.', [
            'n' => MailCraft::getInstance()->audiences->count($form['audience']),
        ]));

        return $this->redirect(UrlHelper::cpUrl('mailcraft/send'));
    }

    /**
     * Cancels a queued send.
     */
    public function actionCancel(): Response
    {
        $this->requirePostRequest();

        $sends = MailCraft::getInstance()->sends;
        $id = (int)$this->request->getRequiredBodyParam('id');

        if ($sends->cancel($id)) {
            Craft::$app->getSession()->setNotice(Craft::t('mailcraft', 'Send cancelled.'));
        } else {
            Craft::$app->getSession()->setError((string)$sends->getLastError());
        }

        return $this->redirect(UrlHelper::cpUrl('mailcraft/send'));
    }

    /**
     * The template, channels, audience and mode posted by the page.
     *
     * @return array{template: ?EmailTemplate, channels: string[], audience: array, mode: string, errors: string[], pmMeta: array, pmData: array, pmExpiresDays: string, pmDateExpires: string, pmExpiresMode: string}
     */
    private function fromRequest(): array
    {
        $request = $this->request;
        $plugin = MailCraft::getInstance();

        $templateId = (int)$request->getBodyParam('templateId');
        $mode = $request->getBodyParam('mode');
        if ($mode !== 'template' && $mode !== 'custom') {
            $mode = $templateId ? 'template' : 'custom';
        }

        $channels = [];
        $posted = $request->getBodyParam('channels');
        foreach (is_array($posted) ? $posted : [] as $handle) {
            if (is_string($handle) && $handle !== '') {
                $channels[] = $handle;
            }
        }
        $channels = array_values(array_unique($channels));

        $errors = [];
        $pmMeta = [];
        $pmData = [];
        $pmExpiresDays = '';
        $pmDateExpires = '';
        $pmExpiresMode = '';
        if ($mode === 'template') {
            $template = $templateId
                ? EmailTemplate::find()->id($templateId)->event('manual')->status(null)->one()
                : null;
        } else {
            $subject = (string)$request->getBodyParam('subject', '');
            $pmType = $request->getBodyParam('pmType');
            $meta = $plugin->sends->metaFromPost($request->getBodyParam('pmMeta'));
            $pmMeta = $meta['values'];
            $data = $plugin->sends->dataFromPost($request->getBodyParam('pmData'));
            $pmData = $data['values'];
            $errors = array_merge($meta['errors'], $data['errors']);

            $expiresMode = $request->getBodyParam('pmExpiresMode');
            $expiresMode = in_array($expiresMode, ['never', 'days', 'date'], true) ? $expiresMode : null;
            $pmExpiresMode = (string)$expiresMode;
            if (!in_array('privateMessage', $channels, true)) {
                $expiresMode = 'never';
            }
            $postedDays = $expiresMode === null || $expiresMode === 'days' ? $request->getBodyParam('pmExpiresDays') : null;
            $pmExpiresDays = is_scalar($postedDays) ? trim((string)$postedDays) : '';
            if ($expiresMode === 'days' && ($pmExpiresDays === '' || $pmExpiresDays === '0')) {
                $errors[] = Craft::t('mailcraft', 'Expires after: enter a number of days.');
            } elseif ($pmExpiresDays !== '' && !ctype_digit($pmExpiresDays)) {
                $errors[] = Craft::t('mailcraft', 'Expires after: must be a whole number of days.');
                $pmExpiresDays = '';
            }
            $postedDate = $expiresMode === null || $expiresMode === 'date' ? $request->getBodyParam('pmDateExpires') : null;
            $hasDate = is_array($postedDate)
                ? trim((string)($postedDate['date'] ?? '')) !== ''
                : is_string($postedDate) && trim($postedDate) !== '';
            if ($expiresMode === 'date' && !$hasDate) {
                $errors[] = Craft::t('mailcraft', 'Expires at: pick a date.');
            } elseif ($hasDate) {
                $date = DateTimeHelper::toDateTime($postedDate);
                if ($date === false) {
                    $errors[] = Craft::t('mailcraft', 'Expires at: not a valid date.');
                } elseif ($date <= new DateTime()) {
                    $errors[] = Craft::t('mailcraft', 'Expires at: must be in the future.');
                } else {
                    // the channel parses Y-m-d H:i:s in the system time zone
                    $date->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));
                    $pmDateExpires = $date->format('Y-m-d H:i:s');
                }
            }

            $settings = [];
            foreach (array_keys($plugin->channelRegistry->getAllowedChannels()) as $handle) {
                $enabled = in_array($handle, $channels, true);
                if ($handle === 'privateMessage') {
                    $settings[$handle] = [
                        'enabled' => $enabled,
                        'to' => '{{ recipient.id }}',
                        'from' => '{{ sender.id ?? "" }}',
                        'type' => is_string($pmType) ? $pmType : '',
                        'pinned' => (bool)$request->getBodyParam('pmPinned'),
                        'expiresDays' => $pmExpiresDays !== '' && (int)$pmExpiresDays > 0 ? (int)$pmExpiresDays : null,
                        'dateExpires' => $pmDateExpires,
                        'dataFields' => $pmData,
                    ] + $pmMeta + ['metaLiteral' => true];
                } else {
                    $settings[$handle] = ['enabled' => $enabled];
                }
            }
            $template = $plugin->sends->fromSnapshot([
                'title' => 'Send: ' . $subject,
                'subject' => $subject,
                'template' => (string)$request->getBodyParam('template', ''),
                'event' => 'manual',
                'to' => '{{ recipient.email }}',
                'toName' => '{{ recipient.friendlyName }}',
                'channels' => $settings,
            ]);
        }

        return [
            'template' => $template,
            'channels' => $channels,
            'audience' => $plugin->audiences->normalize($request->getBodyParam('audience')),
            'mode' => $mode,
            'errors' => $errors,
            'pmMeta' => $pmMeta,
            'pmData' => $pmData,
            'pmExpiresDays' => $pmExpiresDays,
            'pmDateExpires' => $pmDateExpires,
            'pmExpiresMode' => $pmExpiresMode,
        ];
    }

    /**
     * Flashes the errors and the posted form, and redirects back to the page.
     *
     * @param array $form fromRequest()
     * @param string[] $errors
     */
    private function failWith(array $form, array $errors, string $notice): Response
    {
        $request = $this->request;
        $session = Craft::$app->getSession();
        $templateId = (int)$request->getBodyParam('templateId');

        $session->setError($notice);
        $session->setFlash(self::FORM_FLASH, [
            'errors' => array_values(array_filter($errors, static fn($e) => $e !== '')),
            'mode' => $form['mode'],
            'templateId' => $templateId ?: null,
            'subject' => (string)$request->getBodyParam('subject', ''),
            'template' => (string)$request->getBodyParam('template', ''),
            'pmType' => (string)$request->getBodyParam('pmType', ''),
            'pmPinned' => (bool)$request->getBodyParam('pmPinned'),
            'pmMeta' => $form['pmMeta'],
            'pmData' => $form['pmData'],
            'pmExpiresDays' => $form['pmExpiresDays'],
            'pmDateExpires' => $form['pmDateExpires'],
            'pmExpiresMode' => $form['pmExpiresMode'],
            'channels' => $form['channels'],
            'audience' => $form['audience'],
        ]);

        return $this->redirect(UrlHelper::cpUrl('mailcraft/send', $templateId ? ['templateId' => $templateId] : []));
    }
}
