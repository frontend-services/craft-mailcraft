<?php

namespace frontendservices\mailcraft\events;

use frontendservices\mailcraft\base\AbstractChannel;
use yii\base\Event;

/**
 * Raised when the channel registry is built, so plugins and modules can add delivery channels.
 *
 * ```php
 * Event::on(ChannelRegistry::class, ChannelRegistry::EVENT_REGISTER_CHANNELS, function(RegisterChannelsEvent $e) {
 *     $e->channels[] = new MySlackChannel();
 * });
 * ```
 */
class RegisterChannelsEvent extends Event
{
    /**
     * @var AbstractChannel[] Channel instances to register
     */
    public array $channels = [];
}
