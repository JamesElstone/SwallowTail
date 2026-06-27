<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class DataIntegrityCheckAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        if (!$this->canAccess($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['data.integrity'], [[
                'type' => 'error',
                'message' => 'You do not have permission to run data integrity checks, or your security token expired.',
            ]]);
        }

        $service = new SwallowtailDataIntegrityCheckService();
        $action = strtolower(trim((string)$request->input('data_integrity_action', '')));

        if ($action === 'prevent_lazy_loading') {
            $result = $service->requestLazyLoadingPrevention();
            if (empty($result['success'])) {
                return new ActionResultFramework(false, ['data.integrity'], [[
                    'type' => 'error',
                    'message' => (string)($result['message'] ?? 'Lazy loading prevention could not run.'),
                ]]);
            }

            return ActionResultFramework::success(['data.integrity', 'conversion.jobs'], [[
                'type' => 'success',
                'message' => (string)($result['message'] ?? 'Lazy loading prevention was requested.'),
            ]]);
        }

        if ($action === 'run_checks') {
            $blockers = $service->queueBlockers();
            if ((int)($blockers['total'] ?? 0) > 0) {
                return new ActionResultFramework(false, ['data.integrity'], [[
                    'type' => 'error',
                    'message' => 'Data integrity checks can only run when conversion and storage migration queues are idle.',
                ]]);
            }

            $issues = 0;
            foreach ($service->integrityChecks() as $check) {
                $issues += max(0, (int)($check['count'] ?? 0));
            }

            return ActionResultFramework::success(['data.integrity'], [[
                'type' => $issues === 0 ? 'success' : 'warning',
                'message' => $issues === 0
                    ? 'Data integrity checks completed without issues.'
                    : 'Data integrity checks completed with ' . number_format($issues) . ' items to review.',
            ]]);
        }

        return new ActionResultFramework(false, ['data.integrity'], [[
            'type' => 'error',
            'message' => 'Unknown data integrity action.',
        ]]);
    }

    private function canAccess(SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);

        return $userId > 0 && in_array('data_integrity_check', (new CardAccessFramework())->allowedCardsForUser($userId, ['data_integrity_check']), true);
    }
}
