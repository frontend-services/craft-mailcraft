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
            'description' => 'The order whose status changed',
            'fields' => TemplateHelper::orderFields(),
        ];
        $variables['newStatusName'] = [
            'type' => 'string',
            'description' => 'New status, display name',
            'example' => '{{ newStatusName }}',
        ];
        $variables['oldStatusName'] = [
            'type' => 'string',
            'description' => 'Previous status, display name (may be empty)',
            'example' => '{{ oldStatusName }}',
        ];
        $variables['newStatus'] = [
            'type' => 'string',
            'description' => 'New status handle',
            'example' => '{{ newStatus }}',
        ];
        $variables['oldStatus'] = [
            'type' => 'string',
            'description' => 'Previous status handle (may be empty)',
            'example' => '{{ oldStatus }}',
        ];
        $variables['orderHistory'] = [
            'type' => 'OrderHistory',
            'description' => 'The status change record',
            'fields' => [
                'message' => 'Status change message',
                'dateCreated' => [
                    'label' => 'Changed on',
                    'example' => TemplateHelper::dateExample('orderHistory.dateCreated'),
                ],
                'user.fullName' => 'Changed by',
            ],
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
            'subject' => 'Your Order #{{order.number}} Status: {{newStatusName}}',
            'template' => '<h1>Order Status Update</h1>
<p>Dear Customer,</p>

<p>Your order #{{order.number}} status has been changed to <strong>{{newStatusName}}</strong>.</p>

<p><strong>Order Date:</strong> {{order.dateOrdered|date("F j, Y")}}<br>
<strong>Order Status:</strong> {{newStatusName}}</p>

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

        return $this->matchesExtraConditions($template, $variables);
    }
}