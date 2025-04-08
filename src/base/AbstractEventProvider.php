<?php
namespace frontendservices\mailcraft\base;

abstract class AbstractEventProvider
{
    abstract public function getEventId(): string;
    abstract public function getEventDetails(): array;
    abstract public function getSampleData(): array;
    abstract public function getTemplateVariables(): array;
    abstract public function getTemplateExample(): array;
    abstract public function registerEventListener(callable $handler): void;
    abstract public function testConditions($template, $variables): bool;
}