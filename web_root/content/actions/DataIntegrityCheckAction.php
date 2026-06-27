<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailDataIntegrityCheckService;

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

            $checks = $service->integrityChecks();
            $issues = 0;
            foreach ($checks as $check) {
                $issues += max(0, (int)($check['count'] ?? 0));
            }

            return ActionResultFramework::success(['data.integrity'], [[
                'type' => $issues === 0 ? 'success' : 'warning',
                'message' => $issues === 0
                    ? 'Data integrity checks completed without issues.'
                    : 'Data integrity checks completed with ' . number_format($issues) . ' items to review.',
            ]], [], [
                'data_integrity_check' => [
                    'checks_loaded' => true,
                    'checks' => $checks,
                ],
            ]);
        }

        if ($action === 'details') {
            $result = $service->detailSummary((string)$request->input('data_integrity_check_key', ''));

            return ActionResultFramework::success(['data.integrity'], [[
                'type' => 'info',
                'message' => (string)($result['message'] ?? 'No detail is available for this check.'),
            ]]);
        }

        if ($action === 'repair_safe_issues') {
            return $this->repairResult($service->repairSafeIssues(), ['data.integrity', 'conversion.jobs']);
        }

        if ($action === 'repair_missing_base_conversions') {
            return $this->repairResult($service->repairMissingBaseConversions(), ['data.integrity', 'conversion.jobs']);
        }

        if ($action === 'repair_profile_signatures') {
            return $this->repairResult($service->repairProfileSignatures(), ['data.integrity', 'conversion.jobs']);
        }

        if ($action === 'repair_conversion_states') {
            return $this->repairResult($service->repairPhotoConversionStates(), ['data.integrity']);
        }

        return new ActionResultFramework(false, ['data.integrity'], [[
            'type' => 'error',
            'message' => 'Unknown data integrity action.',
        ]]);
    }

    private function repairResult(array $result, array $facts): ActionResultFramework
    {
        if (empty($result['success'])) {
            return new ActionResultFramework(false, $facts, [[
                'type' => 'error',
                'message' => (string)($result['message'] ?? 'Data integrity repair could not run.'),
            ]]);
        }

        return ActionResultFramework::success($facts, [[
            'type' => 'success',
            'message' => (string)($result['message'] ?? 'Data integrity repair completed.'),
        ]]);
    }

    private function canAccess(SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);

        return $userId > 0 && in_array('data_integrity_check', (new CardAccessFramework())->allowedCardsForUser($userId, ['data_integrity_check']), true);
    }
}
