<?php

namespace frontendservices\mailcraft\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use frontendservices\mailcraft\errors\ImportException;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\services\ImportService;
use yii\web\Response;

/**
 * The POST side of Utilities -> MailCraft Import. Every action redirects back to the utility.
 */
class ImportController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requirePostRequest();
        $this->requirePermission('mailcraft:manageEmailTemplates');
        $this->requirePermission('utility:mailcraft-import');

        return parent::beforeAction($action);
    }

    /**
     * Stores the uploaded export file for the preview.
     */
    public function actionUpload(): Response
    {
        $file = UploadedFile::getInstanceByName('file');

        if ($file === null) {
            $this->setFailFlash(Craft::t('mailcraft', 'Choose a file to upload.'));

            return $this->redirectToUtility();
        }

        try {
            MailCraft::getInstance()->templateImport->storeUpload($file);
        } catch (ImportException $e) {
            $this->setFailFlash($e->getMessage());
        }

        return $this->redirectToUtility();
    }

    /**
     * Imports the checked rows of the stored file.
     */
    public function actionRun(): Response
    {
        $service = MailCraft::getInstance()->templateImport;
        $selection = $this->request->getBodyParam('rows');
        $selection = array_filter(is_array($selection) ? $selection : [], static fn($v) => is_string($v) && $v !== '');

        if ($selection === []) {
            $this->setFailFlash(Craft::t('mailcraft', 'No rows selected.'));

            return $this->redirectToUtility();
        }

        $rawRows = $service->getStoredRows();
        if ($rawRows === null) {
            $this->setFailFlash(Craft::t('mailcraft', 'The uploaded file is gone. Please upload it again.'));

            return $this->redirectToUtility();
        }

        if ((string)$this->request->getBodyParam('upload') !== $service->getStoredFingerprint()) {
            $this->setFailFlash(Craft::t('mailcraft', 'The file changed since this preview; please check it again.'));

            return $this->redirectToUtility();
        }

        try {
            $counts = $service->import($rawRows, $selection);
        } catch (ImportException $e) {
            $session = Craft::$app->getSession();
            $session->set(ImportService::SELECTION_KEY, $selection);
            if ($e->rowIndex !== null) {
                $messages = [];
                foreach ($e->errors as $attributeErrors) {
                    foreach ((array)$attributeErrors as $message) {
                        $messages[] = $message;
                    }
                }
                $session->set(ImportService::ERRORS_KEY, [$e->rowIndex => $messages ?: [$e->getMessage()]]);
            }
            $this->setFailFlash($e->getMessage());

            return $this->redirectToUtility();
        }

        $service->discard();
        $this->setSuccessFlash(Craft::t('mailcraft', '{created} created, {updated} updated, {restored} restored.', $counts));

        return $this->redirectToUtility();
    }

    /**
     * Forgets the stored file.
     */
    public function actionDiscard(): Response
    {
        MailCraft::getInstance()->templateImport->discard();

        return $this->redirectToUtility();
    }

    private function redirectToUtility(): Response
    {
        return $this->redirect(UrlHelper::cpUrl('utilities/mailcraft-import'));
    }
}
