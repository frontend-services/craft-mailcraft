<?php

namespace frontendservices\mailcraft\controllers;

use Craft;
use craft\errors\ElementNotFoundException;
use craft\errors\MissingComponentException;
use craft\web\Controller;
use frontendservices\mailcraft\base\AbstractEventProvider;
use frontendservices\mailcraft\elements\EmailTemplate;
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
        $this->requireAdmin(false);

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
            'exampleTemplates' => MailCraft::getInstance()->eventRegistry->getSampleEmails(),
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
        $this->requireAdmin(false);

        $templateId = $this->request->getBodyParam('templateId');

        if ($templateId) {
            $emailTemplate = EmailTemplate::find()->id($templateId)->status(null)->one();
            if (!$emailTemplate) {
                throw new NotFoundHttpException('Email template not found');
            }
        } else {
            $emailTemplate = new EmailTemplate();
        }

        $emailTemplate->title = $this->request->getBodyParam('title');
        $emailTemplate->subject = $this->request->getBodyParam('subject');
        $emailTemplate->event = $this->request->getBodyParam('event');
        $emailTemplate->template = $this->request->getBodyParam('template');
        $emailTemplate->to = $this->request->getBodyParam('to');
        $emailTemplate->toName = $this->request->getBodyParam('toName');
        $emailTemplate->from = $this->request->getBodyParam('from');
        $emailTemplate->fromName = $this->request->getBodyParam('fromName');
        $emailTemplate->enabled = (bool)$this->request->getBodyParam('enabled');
        $emailTemplate->delay = (int)$this->request->getBodyParam('delay');
        $emailTemplate->cc = $this->request->getBodyParam('cc');
        $emailTemplate->bcc = $this->request->getBodyParam('bcc');
        $emailTemplate->replyTo = $this->request->getBodyParam('replyTo');
        $emailTemplate->conditions = $this->request->getBodyParam('conditions');
        $emailTemplate->condition1 = $this->request->getBodyParam('condition1');
        $emailTemplate->condition2 = $this->request->getBodyParam('condition2');

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
                    'exampleTemplates' => MailCraft::getInstance()->eventRegistry->getSampleEmails(),
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
        $this->requireAdmin(false);

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
     * Preview an email template
     *
     * @throws MethodNotAllowedHttpException
     * @throws BadRequestHttpException|NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);
        $this->requireAcceptsJson();

        $templateId = $this->request->getRequiredBodyParam('id');

        $emailTemplate = EmailTemplate::find()->id($templateId)->status(null)->one();
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
        } catch (Throwable $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
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
        $this->requireAdmin(false);
        $this->requirePostRequest();

        $providers = MailCraft::getInstance()->eventRegistry->getProviders();
        $examples = [];
        foreach ($providers as $provider) {
            /* @var AbstractEventProvider $provider */
            $examples[$provider->getEventId()] = $provider->getTemplateExample();
        }

        return $this->asJson($examples);
    }
}