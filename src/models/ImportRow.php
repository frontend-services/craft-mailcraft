<?php

namespace frontendservices\mailcraft\models;

use Craft;
use craft\base\Model;

/**
 * One row of an import preview: a template from the file and what importing it would do.
 */
class ImportRow extends Model
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const RESTORE = 'restore';
    public const UNCHANGED = 'unchanged';
    public const INVALID = 'invalid';

    /**
     * @var int 1-based position in the file
     */
    public int $index = 0;

    /**
     * @var array Normalised row, see `ImportService::normalizeRow()`
     */
    public array $data = [];

    /**
     * @var string One of the outcome constants
     */
    public string $outcome = self::INVALID;

    /**
     * @var int|null Id of the matched local template
     */
    public ?int $localId = null;

    /**
     * @var string|null Title of the matched local template
     */
    public ?string $localTitle = null;

    /**
     * @var string[] Translated notes shown next to the row
     */
    public array $notes = [];

    /**
     * @var bool Whether the row is selected for import by default
     */
    public bool $checked = false;

    public function getTitle(): string
    {
        $title = trim((string)($this->data['title'] ?? ''));

        return $title !== '' ? $title : Craft::t('mailcraft', '(untitled)');
    }

    public function getHandle(): ?string
    {
        return $this->data['handle'] ?? null;
    }

    public function getUid(): ?string
    {
        return $this->data['uid'] ?? null;
    }

    public function getCanCheck(): bool
    {
        return $this->outcome !== self::INVALID;
    }
}
