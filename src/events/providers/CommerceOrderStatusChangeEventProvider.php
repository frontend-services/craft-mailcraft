<?php
namespace frontendservices\mailcraft\events\providers;

use Craft;
use frontendservices\mailcraft\base\AbstractEventProvider;
use frontendservices\mailcraft\helpers\TemplateHelper;
use frontendservices\mailcraft\MailCraft;
use yii\base\Event;

class CommerceOrderStatusChangeEventProvider extends AbstractEventProvider
{
    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'commerce.order.statusChange';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => 'Order Status Changes',
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
            \craft\commerce\services\OrderHistories::class,
            \craft\commerce\services\OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            static function(\craft\commerce\events\OrderStatusEvent $event) use ($handler) {
                $order = $event->order;
                $orderHistory = $event->orderHistory;
                $newStatus = $orderHistory->newStatus->handle;
                $oldStatus = $orderHistory->prevStatus?->handle;

                $handler([
                    'order' => $order,
                    'newStatus' => $newStatus,
                    'oldStatus' => $oldStatus,
                    'orderHistory' => $orderHistory
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
        $variables['newStatus'] = [
            'type' => 'String',
            'description' => 'New order status handle'
        ];
        $variables['oldStatus'] = [
            'type' => 'String',
            'description' => 'Previous order status handle (may be null)'
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
            'title' => 'Commerce Order Status Change Notification',
            'to' => '{{order.email}}',
            'subject' => 'Your Order #{{order.number}} Status: {{newStatus}}',
            'template' => '<h1>Order Status Update</h1>
<p>Dear Customer,</p>

<p>Your order #{{order.number}} status has been changed to <strong>{{newStatus}}</strong>.</p>

<p><strong>Order Date:</strong> {{order.dateOrdered|date("F j, Y")}}<br>
<strong>Order Status:</strong> {{newStatus}}</p>

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
        $orderStatuses = MailCraft::getInstance()->conditionService->getOrderStatuses();

        return [
            'condition1' => [
                'operand' => 'newStatus == condition',
                'name' => Craft::t('mailcraft', 'New Status'),
                'options' => $orderStatuses,
            ],
            'condition2' => [
                'operand' => 'oldStatus == condition',
                'name' => Craft::t('mailcraft', 'Previous Status'),
                'options' => $orderStatuses,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function testConditions($template, $variables): bool
    {
        $newStatus = $variables['newStatus'] ?? null;
        $oldStatus = $variables['oldStatus'] ?? null;

        if (!$newStatus) {
            return false;
        }

        if (isset($template->condition1) && $template->condition1 && $newStatus !== $template->condition1) {
            return false;
        }

        if (isset($template->condition2) && $template->condition2 && $oldStatus !== $template->condition2) {
            return false;
        }

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