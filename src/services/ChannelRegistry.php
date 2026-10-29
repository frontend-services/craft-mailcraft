<?php

namespace frontendservices\mailcraft\services;

use craft\base\Component;
use frontendservices\mailcraft\base\AbstractChannel;
use frontendservices\mailcraft\channels\EmailChannel;
use frontendservices\mailcraft\channels\PrivateMessageChannel;
use frontendservices\mailcraft\events\RegisterChannelsEvent;
use frontendservices\mailcraft\MailCraft;

/**
 * Keeps the registered delivery channels.
 */
class ChannelRegistry extends Component
{
    public const EVENT_REGISTER_CHANNELS = 'registerChannels';

    /**
     * @var AbstractChannel[]|null handle => channel, in registration order
     */
    private ?array $channels = null;

    /**
     * Registers a channel. A later registration with the same handle replaces the earlier one.
     */
    public function registerChannel(AbstractChannel $channel): void
    {
        $this->ensureLoaded();
        $this->channels[$channel->getHandle()] = $channel;
    }

    /**
     * All registered channels, keyed by handle, in registration order.
     *
     * @return AbstractChannel[]
     */
    public function getChannels(): array
    {
        $this->ensureLoaded();
        return $this->channels;
    }

    /**
     * The registered channels that are allowed in the plugin settings, in registration order.
     * Handles that are allowed but not registered are ignored.
     *
     * @return AbstractChannel[]
     */
    public function getAllowedChannels(): array
    {
        $allowed = MailCraft::getInstance()->getSettings()->allowedChannels;

        if (in_array('*', $allowed, true)) {
            return $this->getChannels();
        }

        return array_filter(
            $this->getChannels(),
            static fn(string $handle) => in_array($handle, $allowed, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    public function getChannel(string $handle): ?AbstractChannel
    {
        return $this->getChannels()[$handle] ?? null;
    }

    private function ensureLoaded(): void
    {
        if ($this->channels !== null) {
            return;
        }

        $this->channels = [];
        $this->channels['email'] = new EmailChannel();
        $this->channels['privateMessage'] = new PrivateMessageChannel();

        $event = new RegisterChannelsEvent();
        $this->trigger(self::EVENT_REGISTER_CHANNELS, $event);

        foreach ($event->channels as $channel) {
            if ($channel instanceof AbstractChannel) {
                $this->channels[$channel->getHandle()] = $channel;
            }
        }
    }
}
