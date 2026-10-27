<?php

namespace frontendservices\mailcraft\controllers;

use Craft;
use craft\errors\ElementNotFoundException;
use craft\errors\MissingComponentException;
use craft\web\Controller;
use frontendservices\mailcraft\base\AbstractEventProvider;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\helpers\PreviewHelper;
use frontendservices\mailcraft\MailCraft;
use Throwable;
use yii\base\Exception;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class EmailTemplatesController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if ($action->id === 'preview-iframe') {
            $this->enableCsrfValidation = false;
        }

        // Check if user has permission to manage email templates
        if (!Craft::$app->user->checkPermission('mailcraft:manageEmailTemplates')) {
            throw new ForbiddenHttpException('User is not permitted to manage email templates');
        }

        return parent::beforeAction($action);
    }

    /**
     * Email templates index
     *
     * @throws ForbiddenHttpException
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('mailcraft:manageEmailTemplates');

        // Return template with email templates data
        return $this->renderTemplate('mailcraft/email-templates/index');
    }

    /**
     * Create a new email template
     * 
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionNew(): Response
    {
        $this->requirePermission('mailcraft:manageEmailTemplates');

        return $this->actionEdit();
    }

    /**
     * Edit an email template
     *
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionEdit(?int $templateId = null, ?EmailTemplate $emailTemplate = null): Response
    {
        $this->requirePermission('mailcraft:manageEmailTemplates');

        // Get the email template if one was provided
        if ($emailTemplate === null && $templateId !== null) {
            $emailTemplate = EmailTemplate::find()->id($templateId)->status(null)->one();
            if (!$emailTemplate) {
                throw new NotFoundHttpException('Email template not found');
            }
        }

        // Create a new email template if none was provided
        if ($emailTemplate === null) {
            $emailTemplate = new EmailTemplate();
        }

        return $this->renderTemplate('mailcraft/email-templates/_edit', [
            'emailTemplate' => $emailTemplate,
            'channels' => MailCraft::getInstance()->channelRegistry->getAllowedChannels(),
        ]);
    }

    /**
     * Save an email template
     *
     * @throws MethodNotAllowedHttpException
     * @throws NotFoundHttpException
     * @throws MissingComponentException|BadRequestHttpException
     * @throws ForbiddenHttpException
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('mailcraft:manageEmailTemplates');

        $emailTemplate = $this->templateFromRequest();

        try {
            if (!Craft::$app->elements->saveElement($emailTemplate)) {
                if ($this->request->getAcceptsJson()) {
                    return $this->asJson([
                        'success' => false,
                        'errors' => $emailTemplate->getErrors(),
                    ]);
                }

                Craft::$app->getSession()->setError(Craft::t('mailcraft', 'Couldn\'t save email template.'));

                return $this->renderTemplate('mailcraft/email-templates/_edit', [
                    'emailTemplate' => $emailTemplate,
                    'channels' => MailCraft::getInstance()->channelRegistry->getAllowedChannels(),
                ]);
            }
        } catch (ElementNotFoundException $e) {
            throw new NotFoundHttpException($e->getMessage());
        } catch (Exception $e) {
            throw new MissingComponentException($e->getMessage());
        } catch (Throwable $e) {
            Craft::error('An error occurred when saving an email template: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getSession()->setError(Craft::t('mailcraft', 'An error occurred when saving the email template.'));

            return null;
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'id' => $emailTemplate->id,
                'title' => $emailTemplate->title,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('mailcraft', 'Email template saved.'));

        return $this->redirectToPostedUrl($emailTemplate);
    }

    /**
     * Delete an email template
     *
     * @throws NotFoundHttpException
     * @throws MethodNotAllowedHttpException
     * @throws BadRequestHttpException
     * @throws Throwable
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('mailcraft:manageEmailTemplates');

        $templateId = $this->request->getRequiredBodyParam('templateId');

        $emailTemplate = EmailTemplate::find()->id($templateId)->status(null)->one();
        if (!$emailTemplate) {
            throw new NotFoundHttpException('Email template not found');
        }

        if (!Craft::$app->elements->deleteElement($emailTemplate)) {
            Craft::$app->getSession()->setError(Craft::t('mailcraft', 'Couldn\'t delete email template.'));
            return $this->redirectToPostedUrl($emailTemplate);
        }

        Craft::$app->getSession()->setNotice(Craft::t('mailcraft', 'Email template deleted.'));

        return $this->redirectToPostedUrl($emailTemplate);
    }

    /**
     * Preview an email template (AJAX POST, returns JSON)
     *
     * @throws MethodNotAllowedHttpException
     * @throws BadRequestHttpException|NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('mailcraft:manageEmailTemplates');
        $this->requireAcceptsJson();

        $emailTemplate = $this->templateFromRequest(populate: $this->request->getBodyParam('template') !== null);

        if (!$emailTemplate->event) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('mailcraft', 'Choose an event first so the preview knows which variables to use.'),
            ]);
        }

        $emailService = MailCraft::getInstance()->emailService;
        $sampleData = $emailService->getSampleData($emailTemplate->event);

        try {
            return $this->asJson([
                'success' => true,
                'subject' => $emailService->renderTemplateString($emailTemplate->subject, $sampleData),
                'html' => $this->previewHtml($emailService->renderTemplateString($emailTemplate->template, $sampleData)),
            ]);
        } catch (Throwable $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sends the template currently in the edit form to the logged-in user's email address, rendered with sample data.
     *
     * @throws MethodNotAllowedHttpException
     * @throws BadRequestHttpException|NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionSendTest(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('mailcraft:manageEmailTemplates');
        $this->requireAcceptsJson();

        $emailTemplate = $this->templateFromRequest();
        $user = Craft::$app->getUser()->getIdentity();

        if (!$user || !$user->email) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('mailcraft', 'Your user account has no email address to send the test to.'),
            ]);
        }

        if (!$emailTemplate->event || !$emailTemplate->template) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('mailcraft', 'Choose an event and write the email body before sending a test.'),
            ]);
        }

        $result = MailCraft::getInstance()->emailService->sendTestToUser($emailTemplate, $user);

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
     * Preview an email template in an iframe (GET, returns HTML)
     *
     * @throws BadRequestHttpException|NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionPreviewIframe(): Response
    {
        $this->requirePermission('mailcraft:manageEmailTemplates');

        $templateId = $this->request->getRequiredQueryParam('templateId');

        $emailTemplate = EmailTemplate::find()->id($templateId)->status(null)->one();
        if (!$emailTemplate) {
            throw new NotFoundHttpException('Email template not found');
        }

        $emailService = MailCraft::getInstance()->emailService;
        $sampleData = $emailService->getSampleData($emailTemplate->event);

        try {
            $html = $this->previewHtml($emailService->renderTemplateString($emailTemplate->template, $sampleData));
        } catch (Throwable $e) {
            $html = '<pre style="color:red">' . htmlspecialchars($e->getMessage()) . '</pre>';
        }

        return $this->asRaw($html);
    }

    /**
     * Get examples for all email templates
     *
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionGetExamples(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('mailcraft:manageEmailTemplates');
        $this->requirePostRequest();

        $providers = MailCraft::getInstance()->eventRegistry->getProviders();
        $examples = [];
        foreach ($providers as $provider) {
            /* @var AbstractEventProvider $provider */
            $examples[$provider->getEventId()] = $provider->getTemplateExample();
        }

        return $this->asJson($examples);
    }

    /**
     * Makes rendered email HTML presentable in the preview iframe.
     */
    private function previewHtml(?string $html): string
    {
        return PreviewHelper::wrap($html);
    }

    /**
     * Loads the template named by the `templateId` body param (or a new one) and,
     * unless told otherwise, fills it with the posted form values.
     *
     * @throws NotFoundHttpException
     */
    private function templateFromRequest(bool $populate = true): EmailTemplate
    {
        $templateId = $this->request->getBodyParam('templateId') ?: $this->request->getBodyParam('id');

        if ($templateId) {
            $emailTemplate = EmailTemplate::find()->id($templateId)->status(null)->one();
            if (!$emailTemplate) {
                throw new NotFoundHttpException('Email template not found');
            }
        } else {
            $emailTemplate = new EmailTemplate();
        }

        if (!$populate) {
            return $emailTemplate;
        }

        $emailTemplate->title = $this->request->getBodyParam('title');
        $emailTemplate->subject = $this->request->getBodyParam('subject');
        $emailTemplate->event = $this->request->getBodyParam('event');
        $emailTemplate->template = $this->request->getBodyParam('template');
        $emailTemplate->enabled = (bool)$this->request->getBodyParam('enabled');
        $emailTemplate->delay = (int)$this->request->getBodyParam('delay');

        foreach (['to', 'toName', 'from', 'fromName', 'cc', 'bcc', 'replyTo'] as $field) {
            $emailTemplate->$field = $this->request->getBodyParam($field, $emailTemplate->$field);
        }
        $emailTemplate->conditions = $this->request->getBodyParam('conditions');
        $emailTemplate->condition1 = $this->request->getBodyParam('condition1');
        $emailTemplate->condition2 = $this->request->getBodyParam('condition2');

        $posted = $this->request->getBodyParam('channels');
        if (is_array($posted)) {
            $registry = MailCraft::getInstance()->channelRegistry;
            $channels = $emailTemplate->channels;
            foreach ($posted as $handle => $settings) {
                if (!is_string($handle) || $registry->getChannel($handle) === null || !is_array($settings)) {
                    continue;
                }
                $settings['enabled'] = filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $channels[$handle] = $settings;
            }
            $emailTemplate->channels = $channels;
        }

        return $emailTemplate;
    }
}
