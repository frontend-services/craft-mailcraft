<?php

namespace frontendservices\mailcraft\errors;

use yii\base\Exception;

/**
 * Thrown by the template import when a file cannot be read or a row cannot be written.
 */
class ImportException extends Exception
{
    /**
     * @var int|null 1-based position of the row in the file, when the error concerns one row
     */
    public ?int $rowIndex = null;

    /**
     * @var array Validation errors of the row, `attribute => string[]`, as `Model::getErrors()` returns
     */
    public array $errors = [];

    public function __construct(string $message = '', ?int $rowIndex = null, array $errors = [])
    {
        parent::__construct($message);
        $this->rowIndex = $rowIndex;
        $this->errors = $errors;
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Template import error';
    }
}
