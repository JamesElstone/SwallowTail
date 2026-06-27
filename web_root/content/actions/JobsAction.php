<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailJobStatisticsService;

final class JobsAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        if (!$this->canUpdate($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['jobs'], [[
                'type' => 'error',
                'message' => 'You do not have permission to update jobs, or your security token expired.',
            ]]);
        }

        if ((string)$request->input('jobs_action', '') !== 'reprocess_exceptions') {
            return new ActionResultFramework(false, ['jobs'], [[
                'type' => 'error',
                'message' => 'Unknown jobs action.',
            ]]);
        }

        $result = (new SwallowtailJobStatisticsService())->reprocessExceptions((string)$request->input('job_type', ''));
        $label = (string)($result['label'] ?? '');
        if ($label === '') {
            return new ActionResultFramework(false, ['jobs'], [[
                'type' => 'error',
                'message' => 'Unknown job type.',
            ]]);
        }

        $count = max(0, (int)($result['count'] ?? 0));

        return ActionResultFramework::success(['jobs'], [[
            'type' => 'success',
            'message' => $count === 1
                ? $label . ' exception queued for reprocessing.'
                : $label . ' exceptions queued for reprocessing: ' . number_format($count) . '.',
        ]]);
    }

    private function canUpdate(SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);

        return $userId > 0 && in_array('jobs', (new CardAccessFramework())->allowedCardsForUser($userId, ['jobs']), true);
    }
}
