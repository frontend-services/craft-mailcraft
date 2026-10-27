<?php

namespace frontendservices\mailcraft\controllers;

use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use frontendservices\mailcraft\helpers\PreviewHelper;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\services\StatusService;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Status page: the delivery log of the channels chosen in the plugin settings.
 */
class StatusController extends Controller
{
    /**
     * @inheritdoc
     * @throws ForbiddenHttpException
     */
    public function beforeAction($action): bool
    {
        $this->requirePermission('mailcraft:viewStatus');

        return parent::beforeAction($action);
    }

    /**
     * Status index with filters and pagination.
     */
    public function actionIndex(): Response
    {
        $statusService = MailCraft::getInstance()->statusService;
        $registry = MailCraft::getInstance()->channelRegistry;

        $channel = trim((string)$this->request->getQueryParam('channel', ''));
        $status = trim((string)$this->request->getQueryParam('status', ''));
        $search = trim((string)$this->request->getQueryParam('search', ''));
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $limit = 50;

        $criteria = [];
        if ($channel !== '') {
            $criteria['channel'] = $channel;
        }
        if ($status !== '') {
            $criteria['status'] = $status;
        }
        if ($search !== '') {
            $criteria['search'] = $search;
        }

        $total = $statusService->count($criteria);
        $lastPage = max(1, (int)ceil($total / $limit));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $limit;

        $statuses = $statusService->find($criteria, $limit, $offset);
        $ids = array_map(static fn($s) => (int)$s->id, $statuses);
        $resentIds = $ids !== [] ? $statusService->getResentIds($ids) : [];

        // Allowed channels by name, plus any handle that has rows (labelled by handle when not registered)
        $channelOptions = [];
        foreach ($registry->getAllowedChannels() as $handle => $registered) {
            $channelOptions[$handle] = $registered->getName();
        }
        foreach ($statusService->getChannelHandlesInUse() as $handle) {
            if (!isset($channelOptions[$handle])) {
                $registered = $registry->getChannel($handle);
                $channelOptions[$handle] = $registered !== null ? $registered->getName() : $handle;
            }
        }

        $params = array_filter([
            'channel' => $channel,
            'status' => $status,
            'search' => $search,
        ], static fn($value) => $value !== '');

        $pageInfo = [
            'first' => $total > 0 ? $offset + 1 : 0,
            'last' => $offset + count($statuses),
            'total' => $total,
            'prevUrl' => $page > 1 ? UrlHelper::cpUrl('mailcraft/status', $params + ['page' => $page - 1]) : null,
            'nextUrl' => $page < $lastPage ? UrlHelper::cpUrl('mailcraft/status', $params + ['page' => $page + 1]) : null,
        ];

        // A GET form drops the action URL's query string, so carry it (e.g. `p=admin/...` on sites without URL rewriting) as hidden inputs
        $filterAction = UrlHelper::cpUrl('mailcraft/status');
        $filterHidden = [];
        $query = parse_url($filterAction, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $filterHidden);
            $filterAction = strtok($filterAction, '?');
        }

        return $this->renderTemplate('mailcraft/status/index', [
            'statuses' => $statuses,
            'resentIds' => $resentIds,
            'channelOptions' => $channelOptions,
            'statusOptions' => [
                StatusService::STATUS_QUEUED => Craft::t('mailcraft', 'Queued'),
                StatusService::STATUS_SENT => Craft::t('mailcraft', 'Sent'),
                StatusService::STATUS_FAILED => Craft::t('mailcraft', 'Failed'),
            ],
            'filters' => ['channel' => $channel, 'status' => $status, 'search' => $search],
            'filterAction' => $filterAction,
            'filterHidden' => $filterHidden,
            'pageInfo' => $pageInfo,
            'canManage' => Craft::$app->getUser()->checkPermission('mailcraft:manageStatus'),
        ]);
    }

    /**
     * A single status with its payload and body.
     *
     * @throws NotFoundHttpException
     */
    public function actionView(int $id): Response
    {
        $status = MailCraft::getInstance()->statusService->getById($id);
        if ($status === null) {
            throw new NotFoundHttpException('Status not found');
        }

        $payload = $status->getPayload();
        $channel = $status->getChannel();
        $body = $channel !== null ? $channel->describeBody($payload) : null;

        $payloadFields = [];
        $bodyKeyDropped = false;
        foreach ($payload as $key => $value) {
            if (!$bodyKeyDropped && $body !== null && $value === $body) {
                $bodyKeyDropped = true;
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value !== null && !is_scalar($value)) {
                $value = Json::encode($value);
            }

            $payloadFields[$key] = $value === null ? '' : (string)$value;
        }

        $bodyHtml = null;
        if ($body !== null && preg_match('/<[a-z][\s\S]*>/i', $body)) {
            $bodyHtml = PreviewHelper::wrap($body);
        }

        return $this->renderTemplate('mailcraft/status/_view', [
            'status' => $status,
            'payloadFields' => $payloadFields,
            'body' => $body,
            'bodyHtml' => $bodyHtml,
            'canManage' => Craft::$app->getUser()->checkPermission('mailcraft:manageStatus'),
        ]);
    }

    /**
     * Sends the posted statuses again.
     *
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionResend(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('mailcraft:manageStatus');

        $ids = $this->idsFromRequest();
        if ($ids === []) {
            $this->setFailFlash(Craft::t('mailcraft', 'No statuses selected.'));
            return $this->redirectToPostedUrl(null, 'mailcraft/status');
        }

        $result = MailCraft::getInstance()->statusService->resend($ids);

        if ($result['resent'] > 0) {
            $this->setSuccessFlash(Craft::t('mailcraft', '{n} resent.', ['n' => $result['resent']]));
        }

        if (!empty($result['skipped'])) {
            $reasons = [];
            foreach ($result['skipped'] as $id => $reason) {
                $reasons[] = '#' . $id . ': ' . $reason;
            }
            $this->setFailFlash(Craft::t('mailcraft', 'Skipped {reasons}', ['reasons' => implode('; ', $reasons)]));
        }

        return $this->redirectToPostedUrl(null, 'mailcraft/status');
    }

    /**
     * Deletes the posted statuses.
     *
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('mailcraft:manageStatus');

        $ids = $this->idsFromRequest();
        if ($ids === []) {
            $this->setFailFlash(Craft::t('mailcraft', 'No statuses selected.'));
            return $this->redirectToPostedUrl(null, 'mailcraft/status');
        }

        $deleted = MailCraft::getInstance()->statusService->delete($ids);
        $this->setSuccessFlash(Craft::t('mailcraft', '{n} deleted.', ['n' => $deleted]));

        return $this->redirectToPostedUrl(null, 'mailcraft/status');
    }

    /**
     * The posted `ids[]` (or a single `id`) as positive integers.
     *
     * @return int[]
     */
    private function idsFromRequest(): array
    {
        $ids = $this->request->getBodyParam('ids');
        if ($ids === null || $ids === '' || $ids === []) {
            $ids = $this->request->getBodyParam('id');
        }

        $ids = array_map('intval', array_filter((array)$ids, 'is_scalar'));

        return array_values(array_unique(array_filter($ids, static fn(int $id) => $id > 0)));
    }
}
