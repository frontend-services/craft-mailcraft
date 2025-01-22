<?php

namespace frontendservices\mailcraft\controllers;

use Craft;
use craft\web\Controller;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\events\TriggerEvents;
use frontendservices\mailcraft\MailCraft;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class EmailTemplatesController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // Check if user has permission to manage email templates
        if (!Craft::$app->user->checkPermission('mailcraft:manageEmailTemplates')) {
            throw new ForbiddenHttpException('User is not permitted to manage email templates');
        }

        return parent::beforeAction($action);
    }

    /**
     * Email templates index
     * @throws ForbiddenHttpException
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('mailcraft:manageEmailTemplates');

        // Return template with email templates data
        return $this->renderTemplate('mailcraft/email-templates/index');
    }

    /**
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionNew(): Response
    {
        $this->requirePermission('mailcraft:manageEmailTemplates');

        return $this->actionEdit(null, null);
    }

    /**
     * Edit an email template
     * @throws NotFoundHttpException
     */
    public function actionEdit(?int $templateId = null, ?EmailTemplate $emailTemplate = null): Response
    {
        // Get the email template if one was provided
        if ($emailTemplate === null && $templateId !== null) {
            $emailTemplate = EmailTemplate::findOne($templateId);
            if (!$emailTemplate) {
                throw new NotFoundHttpException('Email template not found');
            }
        }

        // Create a new email template if none was provided
        if ($emailTemplate === null) {
            $emailTemplate = new EmailTemplate();
        }

        // Check if we're limited by the standard edition
        if (!MailCraft::getInstance()->is(MailCraft::EDITION_PRO)) {
            $existingCount = EmailTemplate::find()->count();
            if ($existingCount >= 3 && !$templateId) {
                return $this->renderTemplate('mailcraft/email-templates/_upgrade', [
                    'message' => Craft::t('mailcraft', 'Standard edition is limited to 3 email templates. Please upgrade to Pro.'),
                ]);
            }
        }

        return $this->renderTemplate('mailcraft/email-templates/_edit', [
            'emailTemplate' => $emailTemplate,
        ]);
    }

    /**
     * Save an email template
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $templateId = $this->request->getBodyParam('templateId');

        if ($templateId) {
            $emailTemplate = EmailTemplate::findOne($templateId);
            if (!$emailTemplate) {
                throw new NotFoundHttpException('Email template not found');
            }
        } else {
            $emailTemplate = new EmailTemplate();
        }

        // Set the email template attributes
        $emailTemplate->title = $this->request->getBodyParam('title');
        $emailTemplate->subject = $this->request->getBodyParam('subject');
        $emailTemplate->event = $this->request->getBodyParam('event');
        $emailTemplate->template = $this->request->getBodyParam('template');
        $emailTemplate->to = $this->request->getBodyParam('to');
        $emailTemplate->toName = $this->request->getBodyParam('toName');
        $emailTemplate->from = $this->request->getBodyParam('from');
        $emailTemplate->fromName = $this->request->getBodyParam('fromName');
        $emailTemplate->enabled = (bool)$this->request->getBodyParam('enabled');

        // Pro edition fields
        if (MailCraft::getInstance()->is(MailCraft::EDITION_PRO)) {
            $emailTemplate->delay = $this->request->getBodyParam('delay');
            $emailTemplate->cc = $this->request->getBodyParam('cc');
            $emailTemplate->bcc = $this->request->getBodyParam('bcc');
            $emailTemplate->replyTo = $this->request->getBodyParam('replyTo');
            $emailTemplate->conditions = $this->request->getBodyParam('conditions');
        }

        // Save the email template
        if (!Craft::$app->elements->saveElement($emailTemplate)) {
            if ($this->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'errors' => $emailTemplate->getErrors(),
                ]);
            }

            Craft::$app->getSession()->setError(Craft::t('mailcraft', 'Couldn\'t save email template.'));

            // Send the email template back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'emailTemplate' => $emailTemplate,
            ]);

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
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $templateId = $this->request->getRequiredBodyParam('id');

        $emailTemplate = EmailTemplate::findOne($templateId);
        if (!$emailTemplate) {
            throw new NotFoundHttpException('Email template not found');
        }

        if (!Craft::$app->elements->deleteElement($emailTemplate)) {
            return $this->asJson(['success' => false]);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Preview an email template
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $templateId = $this->request->getRequiredBodyParam('id');

        $emailTemplate = EmailTemplate::findOne($templateId);
        if (!$emailTemplate) {
            throw new NotFoundHttpException('Email template not found');
        }

        // Get sample data based on event type
        $sampleData = MailCraft::getInstance()->emailService->getSampleData($emailTemplate->event);

        try {
            $html = Craft::$app->view->renderString($emailTemplate->template, $sampleData);

            return $this->asJson([
                'success' => true,
                'html' => $html,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function actionGetExamples(): Response
    {
        $this->requireAcceptsJson();

        $examples = [
            'user_welcome' => [
                'title' => 'Welcome Email',
                'subject' => 'Welcome to {siteName}!',
                'event' => TriggerEvents::EVENT_USER_CREATE,
                'template' => '<p>Welcome to {siteName}!</p>'
            ],
            'verify_email' => [
                'title' => 'Email Verification',
                'subject' => 'Please Verify Your Email',
                'event' => TriggerEvents::EVENT_USER_VERIFY,
                'template' => '<p>Please verify your email by clicking the link below.</p>'
            ],
            'new_entry' => [
                'title' => 'New Entry Notification',
                'subject' => 'New Content: {entry.title}',
                'event' => TriggerEvents::EVENT_ENTRY_CREATE,
                'template' => '<p>New content has been published: {entry.title}</p>'
            ],
            'order_complete' => [
                'title' => 'Order Confirmation',
                'subject' => 'Order Confirmation #{order.number}',
                'event' => TriggerEvents::EVENT_COMMERCE_ORDER_COMPLETE,
                'template' => '<p>Your order #{order.number} has been confirmed.</p>'
            ],
            'order_status' => [
                'title' => 'Order Status Update',
                'subject' => 'Order Status Update #{order.number}',
                'event' => TriggerEvents::EVENT_COMMERCE_ORDER_STATUS,
                'template' => '<p>The status of your order #{order.number} has been updated.</p>'
            ],
            'password_reset' => [
                'title' => 'Password Reset',
                'subject' => 'Password Reset Request',
                'event' => TriggerEvents::EVENT_USER_VERIFY,
                'template' => '<p>Click the link below to reset your password.</p>'
            ]
        ];

        return $this->asJson($examples);
    }
}