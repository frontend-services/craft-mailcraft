<?php
namespace frontendservices\mailcraft\events\providers;

use Craft;
use craft\commerce\elements\Order;
use frontendservices\mailcraft\base\AbstractEventProvider;
use frontendservices\mailcraft\helpers\TemplateHelper;
use yii\base\Event;

class CommerceOrderCreatedEventProvider extends AbstractEventProvider
{
    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'commerce.order.created';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => 'Order Created',
            'group' => 'Commerce',
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerEventListener(callable $handler): void
    {
        if (!class_exists('craft\commerce\Plugin')) {
            return;
        }

        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            static function(Event $event) use ($handler) {
                $order = $event->sender;

                $handler([
                    'order' => $order
                ]);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function getTemplateVariables(): array
    {
        $variables = TemplateHelper::getGeneralVariables();
        $variables['order'] = [
            'type' => 'Order',
            'description' => 'Order model',
            'fields' => [
                'number' => 'Order number',
                'reference' => 'Order reference',
                'email' => 'Customer email',
                'totalPrice' => 'Order total price',
                'itemTotal' => 'Order item total',
                'paymentCurrency' => 'Payment currency',
                'dateOrdered' => 'Date ordered',
                'dateCreated' => 'Date created',
                'dateUpdated' => 'Date updated',
                'customer' => 'Customer information',
                'shippingAddress' => 'Shipping address',
                'billingAddress' => 'Billing address',
                'lineItems' => 'Order line items',
            ]
        ];

        return $variables;
    }

    /**
     * @inheritDoc
     */
    public function getTemplateExample(): array
    {
        return [
            'id' => $this->getEventId(),
            'title' => 'Commerce Order Created Notification',
            'to' => '{{order.email}}',
            'subject' => 'Your Order #{{order.number}} Has Been Created',
            'template' => '<h1>Order Confirmation</h1>
<p>Dear Customer,</p>

<p>Thank you for your order #{{order.number}}.</p>

<p><strong>Order Date:</strong> {{order.dateCreated|date("F j, Y")}}</p>

<h2>Order Items</h2>
{{ craft.mailCraft.orderDetails(order) }}

<h2>Shipping Address</h2>
<p>{{ craft.app.addresses.formatAddress(order.shippingAddress)|raw }}</p>

<h2>Billing Address</h2>
<p>{{ craft.app.addresses.formatAddress(order.billingAddress)|raw }}</p>

<p>Thank you for shopping with us!</p>',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getConditions(): array
    {
        // No specific conditions for order creation
        return [];
    }

    /**
     * @inheritDoc
     */
    public function testConditions($template, $variables): bool
    {
        // Check for custom conditions if any
        if (isset($template->conditions) && $template->conditions) {
            try {
                $twig = Craft::$app->getView()->renderString("{{".$template->conditions."}}", $variables);
                return Craft::$app->getView()->renderString($twig) === "1";
            } catch (\Throwable $e) {
                return false;
            }
        }

        return true;
    }
}