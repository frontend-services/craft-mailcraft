<?php

namespace frontendservices\mailcraft\utilities;

use Craft;
use craft\base\Utility;
use craft\helpers\UrlHelper;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\models\ImportRow;
use frontendservices\mailcraft\services\ImportService;

/**
 * Utilities -> MailCraft Import: upload a Craft export of email templates, preview, import.
 */
class ImportTemplatesUtility extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('mailcraft', 'MailCraft Import');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'mailcraft-import';
    }

    /**
     * Craft 5.
     */
    public static function icon(): ?string
    {
        return dirname(__DIR__) . '/icon-mask.svg';
    }

    /**
     * Craft 4.
     */
    public static function iconPath(): ?string
    {
        return dirname(__DIR__) . '/icon-mask.svg';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $service = MailCraft::getInstance()->templateImport;
        $session = Craft::$app->getSession();
        $rows = null;
        $counts = [ImportRow::CREATE => 0, ImportRow::UPDATE => 0, ImportRow::RESTORE => 0, ImportRow::UNCHANGED => 0, ImportRow::INVALID => 0];

        $rawRows = $service->getStoredRows();
        if ($rawRows !== null) {
            $rows = $service->preview($rawRows);
        }

        if ($rows !== null) {
            $selection = $session->get(ImportService::SELECTION_KEY);
            $errors = $session->get(ImportService::ERRORS_KEY);
            $session->remove(ImportService::SELECTION_KEY);
            $session->remove(ImportService::ERRORS_KEY);

            foreach ($rows as $row) {
                if (is_array($selection)) {
                    $row->checked = $row->getCanCheck() && array_key_exists($row->index, $selection);
                }
                if (is_array($errors) && !empty($errors[$row->index])) {
                    foreach ((array)$errors[$row->index] as $message) {
                        $row->notes[] = (string)$message;
                    }
                }
                $counts[$row->outcome]++;
            }
        }

        return Craft::$app->getView()->renderTemplate('mailcraft/utilities/import', [
            'rows' => $rows,
            'counts' => $counts,
            'uploadFingerprint' => $service->getStoredFingerprint(),
            'templatesUrl' => UrlHelper::cpUrl('mailcraft'),
        ]);
    }
}
