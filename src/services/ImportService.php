<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\web\UploadedFile;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\errors\ImportException;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\models\ImportRow;
use Throwable;

/**
 * Imports email templates from a file made by Craft's element export on the Email Templates index ("Raw data" or "Expanded", JSON).
 */
class ImportService extends Component
{
    public const SESSION_KEY = 'mailcraft.import.key';
    public const SELECTION_KEY = 'mailcraft.import.selection';
    public const ERRORS_KEY = 'mailcraft.import.errors';
    public const CACHE_PREFIX = 'mailcraft.import.rows.';
    public const STALE_SECONDS = 86400;

    /**
     * Template table columns carried by the file, besides uid, handle, title, enabled and channels.
     */
    public const FIELDS = ['subject', 'event', 'delay', 'template', 'to', 'toName', 'cc', 'bcc', 'from', 'fromName', 'replyTo', 'conditions', 'condition1', 'condition2'];

    // Upload storage
    // -------------------------------------------------------------------------

    /**
     * Validates the upload, keeps its rows in the cache and remembers the key in the session.
     *
     * @throws ImportException when the file is not a template export
     */
    public function storeUpload(UploadedFile $file): void
    {
        $rows = $this->readFile($file->tempName);

        $this->discard();
        Craft::$app->getSession()->set(self::SESSION_KEY, $this->putRows($rows));
    }

    /**
     * The rows of the stored upload, or null when there is none or it expired (a stale session entry is removed).
     *
     * @return array[]|null
     */
    public function getStoredRows(): ?array
    {
        $session = Craft::$app->getSession();
        $rows = $this->fetchRows($session->get(self::SESSION_KEY));

        if ($rows === null) {
            $session->remove(self::SESSION_KEY);
        }

        return $rows;
    }

    /**
     * Identifies the stored upload without revealing its key, for the preview form. Empty when nothing is stored.
     */
    public function getStoredFingerprint(): string
    {
        $key = Craft::$app->getSession()->get(self::SESSION_KEY);

        return is_string($key) && $key !== '' ? sha1($key) : '';
    }

    /**
     * Forgets the stored upload and everything about the current import.
     */
    public function discard(): void
    {
        $session = Craft::$app->getSession();
        $key = $session->get(self::SESSION_KEY);
        $this->forgetRows(is_string($key) ? $key : null);

        $session->remove(self::SESSION_KEY);
        $session->remove(self::SELECTION_KEY);
        $session->remove(self::ERRORS_KEY);
    }

    /**
     * Puts rows in the cache for `STALE_SECONDS` and returns their random key.
     */
    protected function putRows(array $rows): string
    {
        $key = StringHelper::randomString(32);
        Craft::$app->getCache()->set(self::CACHE_PREFIX . $key, $rows, self::STALE_SECONDS);

        return $key;
    }

    /**
     * @return array[]|null
     */
    protected function fetchRows(?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }

        $rows = Craft::$app->getCache()->get(self::CACHE_PREFIX . $key);

        return is_array($rows) ? $rows : null;
    }

    protected function forgetRows(?string $key): void
    {
        if ($key !== null && $key !== '') {
            Craft::$app->getCache()->delete(self::CACHE_PREFIX . $key);
        }
    }

    // Reading
    // -------------------------------------------------------------------------

    /**
     * Reads an export file and returns its rows as associative arrays.
     *
     * @return array[]
     * @throws ImportException when the file is not a JSON list of objects
     */
    public function readFile(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        try {
            $rows = $contents === false ? null : Json::decode($contents);
        } catch (Throwable) {
            $rows = null;
        }

        $isList = is_array($rows) && $rows !== [] && array_keys($rows) === range(0, count($rows) - 1);

        if ($isList) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    $isList = false;
                    break;
                }
            }
        }

        if (!$isList) {
            throw new ImportException(Craft::t('mailcraft', 'The file is not a JSON export of email templates.'));
        }

        return $rows;
    }

    /**
     * Reduces an export row to the keys the import knows, with normalised values.
     *
     * Keys: `uid`, `handle`, `title`, `enabled`, every `FIELDS` entry, `channels`.
     */
    public function normalizeRow(array $raw): array
    {
        $text = static function($value): ?string {
            if ($value === null || is_array($value)) {
                return null;
            }
            $value = (string)$value;

            return $value === '' ? null : $value;
        };

        $data = [
            'uid' => $text(isset($raw['uid']) && !is_array($raw['uid']) ? trim((string)$raw['uid']) : null),
            'handle' => $text(isset($raw['handle']) && !is_array($raw['handle']) ? trim((string)$raw['handle']) : null),
            'title' => isset($raw['title']) && !is_array($raw['title']) ? (string)$raw['title'] : '',
            'enabled' => filter_var($raw['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];

        foreach (self::FIELDS as $field) {
            $data[$field] = $text($raw[$field] ?? null);
        }

        $data['delay'] = $data['delay'] !== null && is_numeric($data['delay']) ? (int)$data['delay'] : null;
        $data['channels'] = EmailTemplate::normalizeChannels($raw['channels'] ?? null);

        return $data;
    }

    // Preview
    // -------------------------------------------------------------------------

    /**
     * Matches every row of the file against the local templates and says what importing it
     * would do. Rows are keyed by their 1-based position in the file.
     *
     * @param array[] $rawRows as `readFile()` returns them
     * @return ImportRow[]
     */
    public function preview(array $rawRows): array
    {
        $locals = $this->loadLocals();
        $availableEvents = MailCraft::getInstance()->eventRegistry->getAvailableEventsList();
        $rows = [];
        $claimedIds = [];
        $claimedHandles = [];
        $claimedUids = [];
        $index = 0;

        foreach ($rawRows as $raw) {
            $index++;
            $row = new ImportRow(['index' => $index, 'data' => $this->normalizeRow($raw)]);
            $rows[$index] = $row;
            $data = $row->data;

            foreach (['subject', 'event', 'title'] as $required) {
                if ($data[$required] === null || $data[$required] === '') {
                    $row->outcome = ImportRow::INVALID;
                    $row->notes[] = Craft::t('mailcraft', 'Not a template: missing {attribute}.', ['attribute' => $required]);
                    continue 2;
                }
            }

            if (!in_array($data['event'], $availableEvents, true)) {
                $row->outcome = ImportRow::INVALID;
                $row->notes[] = Craft::t('mailcraft', 'Event {event} is not available on this site.', ['event' => $data['event']]);
                continue;
            }

            $local = $this->findLocal($data, $locals);
            $handleKey = $data['handle'] !== null ? mb_strtolower($data['handle']) : null;

            if ($local !== null) {
                $duplicateOf = $claimedIds[$local->id] ?? null;
            } else {
                $duplicateOf = ($handleKey !== null ? $claimedHandles[$handleKey] ?? null : null)
                    ?? ($data['uid'] !== null ? $claimedUids[$data['uid']] ?? null : null);
            }

            if ($duplicateOf !== null) {
                $row->outcome = ImportRow::INVALID;
                $row->notes[] = Craft::t('mailcraft', 'Duplicate of row {n}.', ['n' => $duplicateOf]);
                continue;
            }

            if ($handleKey !== null) {
                $claimedHandles[$handleKey] = $index;
            }
            if ($data['uid'] !== null) {
                $claimedUids[$data['uid']] = $index;
            }

            if ($local === null) {
                $row->outcome = ImportRow::CREATE;
                $row->checked = true;
                if ($data['handle'] === null) {
                    $row->notes[] = Craft::t('mailcraft', 'No handle; a later import cannot match this template by handle.');
                }
                continue;
            }

            $claimedIds[$local->id] = $index;
            $row->localId = (int)$local->id;
            $row->localTitle = (string)$local->title;

            if ($local->trashed) {
                $row->outcome = ImportRow::RESTORE;
                $row->checked = true;
                $row->notes[] = Craft::t('mailcraft', 'Restores a template deleted on {date}.', [
                    'date' => $local->dateDeleted ? Craft::$app->getFormatter()->asDate($local->dateDeleted, 'short') : '?',
                ]);
            } elseif ($this->isUnchanged($data, $local)) {
                $row->outcome = ImportRow::UNCHANGED;
            } else {
                $row->outcome = ImportRow::UPDATE;
                $row->checked = true;
                $row->notes[] = Craft::t('mailcraft', 'Matches “{title}”.', ['title' => $local->title]);
            }

            if ($data['handle'] === null && $local->handle !== null && $local->handle !== '') {
                $row->notes[] = Craft::t('mailcraft', 'No handle in the file; matched by uid. The local handle is kept.');
            }
        }

        return $rows;
    }

    /**
     * Every local template, trashed ones included, indexed by id.
     *
     * @return EmailTemplate[]
     */
    public function loadLocals(): array
    {
        /** @var EmailTemplate[] $templates */
        $templates = EmailTemplate::find()->status(null)->trashed(null)->indexBy('id')->all();

        return $templates;
    }

    /**
     * The local template a row refers to: same handle (case-insensitive) first, same uid second.
     *
     * @param EmailTemplate[] $locals as `loadLocals()` returns them
     */
    public function findLocal(array $data, array $locals): ?EmailTemplate
    {
        if ($data['handle'] !== null) {
            $wanted = mb_strtolower($data['handle']);
            foreach ($locals as $local) {
                if ($local->handle !== null && $local->handle !== '' && mb_strtolower($local->handle) === $wanted) {
                    return $local;
                }
            }
        }

        if ($data['uid'] !== null) {
            foreach ($locals as $local) {
                if ($local->uid === $data['uid']) {
                    return $local;
                }
            }
        }

        return null;
    }

    /**
     * Whether importing the row would change nothing on the local template.
     */
    public function isUnchanged(array $data, EmailTemplate $local): bool
    {
        if ($local->trashed) {
            return false;
        }

        $same = static fn($a, $b): bool => ($a === null ? '' : (string)$a) === ($b === null ? '' : (string)$b);

        if ($data['title'] !== (string)$local->title || $data['enabled'] !== (bool)$local->enabled) {
            return false;
        }
        if ($data['handle'] !== null && $data['handle'] !== (string)$local->handle) {
            return false;
        }

        foreach (self::FIELDS as $field) {
            if (!$same($data[$field], $local->$field)) {
                return false;
            }
        }

        return $data['channels'] == EmailTemplate::normalizeChannels($local->channels);
    }

    // Import
    // -------------------------------------------------------------------------

    /**
     * Writes the selected rows. The preview is recomputed first; a row whose outcome no longer
     * matches what was posted stops the import before anything is written.
     *
     * @param array[] $rawRows as `readFile()` returns them
     * @param array $selection `index => outcome` as posted from the preview form
     * @return array `['created' => int, 'updated' => int, 'restored' => int]`
     * @throws ImportException on a stale selection or a row that fails validation; nothing is written then
     * @throws Throwable
     */
    public function import(array $rawRows, array $selection): array
    {
        $rows = $this->preview($rawRows);
        $selected = [];

        foreach ($selection as $index => $outcome) {
            $index = (int)$index;
            $row = $rows[$index] ?? null;
            $writable = [ImportRow::CREATE, ImportRow::UPDATE, ImportRow::RESTORE];

            // A selected row that is unchanged is skipped
            if ($row !== null && $row->outcome === ImportRow::UNCHANGED && (string)$outcome === ImportRow::UNCHANGED) {
                continue;
            }

            if ($row === null || $row->outcome !== (string)$outcome || !in_array($row->outcome, $writable, true)) {
                throw new ImportException(Craft::t('mailcraft', 'Row {n} changed since the preview; please check it again.', ['n' => $index]), $index);
            }

            $selected[$index] = $row;
        }

        $counts = ['created' => 0, 'updated' => 0, 'restored' => 0];
        $elements = Craft::$app->getElements();

        Craft::$app->getDb()->transaction(function() use ($selected, $elements, &$counts) {
            foreach ($selected as $row) {
                if ($row->outcome === ImportRow::CREATE) {
                    $element = new EmailTemplate();
                } elseif ($row->outcome === ImportRow::RESTORE) {
                    $trashed = EmailTemplate::find()->id($row->localId)->status(null)->trashed(true)->one();
                    if ($trashed === null) {
                        throw new ImportException(Craft::t('mailcraft', 'Row {n} could not be saved.', ['n' => $row->index]), $row->index);
                    }
                    // Restoring validates the element, so validate the file's values, not the stale stored ones
                    $this->applyRow($trashed, $row->data);
                    if (!$elements->restoreElement($trashed)) {
                        throw new ImportException(Craft::t('mailcraft', 'Row {n} could not be saved.', ['n' => $row->index]), $row->index, $trashed->getErrors());
                    }
                    $element = EmailTemplate::find()->id($row->localId)->status(null)->one();
                } else {
                    $element = EmailTemplate::find()->id($row->localId)->status(null)->one();
                }

                if (!$element instanceof EmailTemplate) {
                    throw new ImportException(Craft::t('mailcraft', 'Row {n} could not be saved.', ['n' => $row->index]), $row->index);
                }

                $this->applyRow($element, $row->data);

                if (!$elements->saveElement($element)) {
                    throw new ImportException(Craft::t('mailcraft', 'Row {n} could not be saved.', ['n' => $row->index]), $row->index, $element->getErrors());
                }

                $counts[$row->outcome === ImportRow::CREATE ? 'created' : ($row->outcome === ImportRow::RESTORE ? 'restored' : 'updated')]++;
            }
        });

        return $counts;
    }

    /**
     * Copies a normalised row onto an element. The handle is only taken over when the file has one, the uid only on a new element.
     */
    public function applyRow(EmailTemplate $element, array $data): void
    {
        $element->title = $data['title'];
        $element->enabled = $data['enabled'];

        foreach (self::FIELDS as $field) {
            $element->$field = $data[$field];
        }

        $element->channels = $data['channels'];

        if ($data['handle'] !== null) {
            $element->handle = $data['handle'];
        }

        if ($element->id === null && $data['uid'] !== null) {
            $element->uid = $data['uid'];
        }
    }
}
