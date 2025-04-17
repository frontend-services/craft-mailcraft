<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use yii\db\Exception;

class DataService extends Component
{
    /**
     * Get a value by key
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $record = (new Query())
            ->select(['value'])
            ->from('{{%mailcraft_data}}')
            ->where(['key' => $key])
            ->one();

        if (!$record) {
            return $default;
        }

        // Handle JSON data
        $value = $record['value'];
        $decoded = json_decode($value, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
    }

    /**
     * Set a value by key
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function set(string $key, mixed $value): bool
    {
        // If value is array or object, convert to JSON
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        }

        try {
            $existing = (new Query())
                ->select(['id'])
                ->from('{{%mailcraft_data}}')
                ->where(['key' => $key])
                ->one();

            if ($existing) {
                // Update existing record
                Craft::$app->getDb()->createCommand()
                    ->update('{{%mailcraft_data}}', ['value' => $value], ['key' => $key])
                    ->execute();
            } else {
                // Insert new record
                Craft::$app->getDb()->createCommand()
                    ->insert('{{%mailcraft_data}}', [
                        'key' => $key,
                        'value' => $value,
                    ])
                    ->execute();
            }

            return true;
        } catch (Exception $e) {
            Craft::error('Failed to save data: ' . $e->getMessage(), 'mailcraft');
            return false;
        }
    }

    /**
     * Delete a value by key
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        try {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%mailcraft_data}}', ['key' => $key])
                ->execute();

            return true;
        } catch (Exception $e) {
            Craft::error('Failed to delete data: ' . $e->getMessage(), 'mailcraft');
            return false;
        }
    }
}