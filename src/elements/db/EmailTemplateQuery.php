<?php

namespace frontendservices\mailcraft\elements\db;

use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use frontendservices\mailcraft\elements\EmailTemplate;

class EmailTemplateQuery extends ElementQuery
{
    public ?string $event = null;
    public ?string $subject = null;
    public ?string $to = null;

    public function event($value): self
    {
        $this->event = $value;
        return $this;
    }

    public function subject($value): self
    {
        $this->subject = $value;
        return $this;
    }

    public function to($value): self
    {
        $this->to = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('mailcraft_emailtemplates');

        $this->query->select([
            'mailcraft_emailtemplates.subject',
            'mailcraft_emailtemplates.event',
            'mailcraft_emailtemplates.delay',
            'mailcraft_emailtemplates.template',
            'mailcraft_emailtemplates.to',
            'mailcraft_emailtemplates.toName',
            'mailcraft_emailtemplates.cc',
            'mailcraft_emailtemplates.bcc',
            'mailcraft_emailtemplates.from',
            'mailcraft_emailtemplates.fromName',
            'mailcraft_emailtemplates.replyTo',
            'mailcraft_emailtemplates.conditions',
            'mailcraft_emailtemplates.condition1',
            'mailcraft_emailtemplates.condition2',
            'mailcraft_emailtemplates.channels',
        ]);

        if ($this->event) {
            $this->subQuery->andWhere(['mailcraft_emailtemplates.event' => $this->event]);
        }

        if ($this->subject) {
            $this->subQuery->andWhere(['like', 'mailcraft_emailtemplates.subject', $this->subject]);
        }

        if ($this->to) {
            $this->subQuery->andWhere(['like', 'mailcraft_emailtemplates.to', $this->to]);
        }

        return parent::beforePrepare();
    }

    /**
     * @inheritdoc
     */
    public function createElement(array $row): ElementInterface
    {
        $row['channels'] = EmailTemplate::normalizeChannels($row['channels'] ?? null);

        return parent::createElement($row);
    }
}
