<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _upload_tokensCard extends CardBaseFramework
{
    private const PAGE_SIZE = 10;

    public function key(): string
    {
        return 'upload_tokens';
    }

    public function title(): string
    {
        return 'Upload Tokens';
    }

    public function helper(array $context): string
    {
        return 'Manage bearer tokens used by camera bridge upload clients.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'upload_tokens',
                'service' => SwallowtailPhotoLibraryService::class,
                'method' => 'listUploadTokens',
            ],
        ];
    }

    public function render(array $context): string
    {
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $createdToken = trim((string)(($context['upload_tokens'] ?? [])['created_token'] ?? ''));

        return $this->createdTokenPanel($createdToken)
            . $this->createForm($context, $csrfToken)
            . $this->configuredTable($context, $csrfToken)->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
                'upload_tokens_filter' => $this->selectedOwnershipFilter($context),
            ]);
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $pageContext = $this->applyTableSortContext($request, $pageContext, $this->key());
        $pageContext[$this->key()]['ownership_filter'] = $this->normaliseOwnershipFilter((string)$request->input(
            'upload_tokens_filter',
            (string)($pageContext[$this->key()]['ownership_filter'] ?? 'owned')
        ));
        $pageContext[$this->key()]['current_user_id'] = $this->currentUserId();

        return $pageContext;
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context, (string)($context['page']['csrf_token'] ?? ''))];
    }

    private function createdTokenPanel(string $createdToken): string
    {
        if ($createdToken === '') {
            return '';
        }

        return '<div class="panel-soft success">
            <p class="helper">New upload token. Copy it now; it will not be shown again.</p>
            <code>' . HelperFramework::escape($createdToken) . '</code>
        </div>';
    }

    private function createForm(array $context, string $csrfToken): string
    {
        return '<form method="post" action="?page=settings" data-ajax="true" class="form-grid">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="card_action" value="UploadTokens">
            <input type="hidden" name="upload_token_action" value="create">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <fieldset class="form-row full settings-fieldset">
                <legend>Create Upload Token</legend>
                <div class="form-grid">
                    <div class="form-row half">
                        <label for="upload-token-label-new">Label</label>
                        <input class="input" id="upload-token-label-new" name="token_label" type="text" required>
                    </div>
                    <div class="form-row half">
                        <label for="upload-token-expires-new">Expires At</label>
                        <input class="input" id="upload-token-expires-new" name="expires_at" type="datetime-local">
                    </div>
                    <div class="form-row full">
                        <label for="upload-token-cidrs-new">Allowed CIDRs</label>
                        <textarea class="input" id="upload-token-cidrs-new" name="cidrs" rows="3" required></textarea>
                    </div>
                    <div class="form-row full">
                        <button class="button primary" type="submit">Create Token</button>
                    </div>
                </div>
            </fieldset>
        </form>';
    }

    private function configuredTable(array $context, string $csrfToken): TableFramework
    {
        $ownershipFilter = $this->selectedOwnershipFilter($context);
        $hiddenFields = $this->tableHiddenFields($context, [
            '_pagination' => '1',
            'upload_tokens_filter' => $ownershipFilter,
        ]);
        $table = $this->configureTableSorting($this->table($context, $csrfToken), $context, $hiddenFields);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Upload tokens',
                $this->paginationPageField(),
                $hiddenFields
            )
            ->filterSelect(
                'upload_tokens_filter',
                'Filter',
                $this->ownershipFilterOptions(),
                $ownershipFilter,
                $this->tableHiddenFields($context, [
                    '_pagination' => '1',
                ])
            );
    }

    private function table(array $context, string $csrfToken): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('upload-tokens')
            ->exportLimit(500)
            ->empty('No upload tokens have been created.')
            ->classes(wrapperClass: 'table-scroll upload-tokens-table')
            ->column(
                'token_label',
                'Token',
                html: fn(array $row): string => $this->labelEditorHtml($context, $row, $csrfToken),
                export: static fn(array $row): string => (string)($row['token_label'] ?? '')
            )
            ->column(
                'created_by_user_label',
                'User',
                html: fn(array $row): string => $this->userHtml($row),
                export: fn(array $row): string => $this->userExportValue($row)
            )
            ->column(
                'cidr_summary',
                'Access',
                html: fn(array $row): string => $this->accessEditorHtml($row),
                export: fn(array $row): string => $this->accessExportValue($row)
            )
            ->column(
                'expires_at',
                'Dates',
                html: fn(array $row): string => $this->datesEditorHtml($row),
                export: fn(array $row): string => $this->datesExportValue($row)
            )
            ->column(
                'actions',
                'Actions',
                html: fn(array $row): string => $this->actionsHtml($context, $row, $csrfToken),
                exportable: false
            );
    }

    private function rows(array $context): array
    {
        $rows = array_values(array_filter(
            (array)(($context['services'] ?? [])['upload_tokens'] ?? []),
            static fn(mixed $row): bool => is_array($row) && (int)($row['hidden'] ?? 0) === 0
        ));

        if ($this->selectedOwnershipFilter($context) !== 'owned') {
            return $rows;
        }

        $currentUserId = $this->currentUserIdFromContext($context);
        if ($currentUserId <= 0) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => (int)($row['created_by_user_id'] ?? 0) === $currentUserId
        ));
    }

    private function labelEditorHtml(array $context, array $token, string $csrfToken): string
    {
        $tokenId = (int)($token['id'] ?? 0);
        $escapedTokenId = HelperFramework::escape((string)$tokenId);
        $updateFormId = 'upload-token-update-' . $escapedTokenId;

        return '<form id="' . $updateFormId . '" method="post" action="?page=settings" data-ajax="true"></form>
            ' . $this->hiddenFields($context, $updateFormId) . '
            <input form="' . $updateFormId . '" type="hidden" name="card_action" value="UploadTokens">
            <input form="' . $updateFormId . '" type="hidden" name="upload_token_action" value="update">
            <input form="' . $updateFormId . '" type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <input form="' . $updateFormId . '" type="hidden" name="token_id" value="' . $escapedTokenId . '">
            <div class="form-row full">
                <label for="upload-token-label-' . $escapedTokenId . '">Label</label>
                <input form="' . $updateFormId . '" class="input" id="upload-token-label-' . $escapedTokenId . '" name="token_label" type="text" value="' . HelperFramework::escape((string)($token['token_label'] ?? '')) . '" required>
            </div>';
    }

    private function userHtml(array $token): string
    {
        $userLabel = trim((string)($token['created_by_user_label'] ?? ''));
        if ($userLabel === '') {
            $userLabel = 'Unassigned';
        }

        $details = [];
        $userId = (int)($token['created_by_user_id'] ?? 0);
        if ($userId > 0) {
            $details[] = 'ID ' . $userId;
        }

        $email = trim((string)($token['created_by_user_email_address'] ?? ''));
        if ($email !== '' && strcasecmp($email, $userLabel) !== 0) {
            $details[] = $email;
        }

        return HelperFramework::escape($userLabel)
            . ($details !== [] ? '<div class="helper">' . HelperFramework::escape(implode(' | ', $details)) . '</div>' : '');
    }

    private function userExportValue(array $token): string
    {
        $userLabel = trim((string)($token['created_by_user_label'] ?? ''));
        $userId = (int)($token['created_by_user_id'] ?? 0);

        if ($userLabel === '') {
            $userLabel = $userId > 0 ? 'User #' . $userId : 'Unassigned';
        }

        return $userId > 0 ? $userLabel . ' (ID ' . $userId . ')' : $userLabel;
    }

    private function accessEditorHtml(array $token): string
    {
        $tokenId = (int)($token['id'] ?? 0);
        $escapedTokenId = HelperFramework::escape((string)$tokenId);
        $updateFormId = 'upload-token-update-' . $escapedTokenId;
        $isActive = (int)($token['is_active'] ?? 0) === 1;
        $canUploadRaw = (int)($token['can_upload_raw'] ?? 0) === 1;
        $cidrs = implode("\n", (array)($token['cidrs'] ?? []));

        return '<label class="checkbox-item" for="upload-token-active-' . $escapedTokenId . '">
            <input form="' . $updateFormId . '" type="hidden" name="is_active" value="0">
            <input form="' . $updateFormId . '" id="upload-token-active-' . $escapedTokenId . '" name="is_active" type="checkbox" value="1"' . ($isActive ? ' checked' : '') . '>
            <span class="checkbox-copy"><span>Active</span></span>
        </label>
        <label class="checkbox-item" for="upload-token-raw-' . $escapedTokenId . '">
            <input form="' . $updateFormId . '" type="hidden" name="can_upload_raw" value="0">
            <input form="' . $updateFormId . '" id="upload-token-raw-' . $escapedTokenId . '" name="can_upload_raw" type="checkbox" value="1"' . ($canUploadRaw ? ' checked' : '') . '>
            <span class="checkbox-copy"><span>Can upload CR2</span></span>
        </label>
        <div class="form-row full">
            <label for="upload-token-cidrs-' . $escapedTokenId . '">Allowed CIDRs</label>
            <textarea form="' . $updateFormId . '" class="input" id="upload-token-cidrs-' . $escapedTokenId . '" name="cidrs" rows="3" required>' . HelperFramework::escape($cidrs) . '</textarea>
        </div>';
    }

    private function accessExportValue(array $token): string
    {
        $parts = [
            (int)($token['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive',
            (int)($token['can_upload_raw'] ?? 0) === 1 ? 'Can upload CR2' : 'Cannot upload CR2',
        ];
        $cidrs = trim((string)($token['cidr_summary'] ?? ''));
        if ($cidrs !== '') {
            $parts[] = $cidrs;
        }

        return implode(' | ', $parts);
    }

    private function datesEditorHtml(array $token): string
    {
        $tokenId = (int)($token['id'] ?? 0);
        $escapedTokenId = HelperFramework::escape((string)$tokenId);
        $updateFormId = 'upload-token-update-' . $escapedTokenId;

        return '<div class="form-row full">
            <label for="upload-token-expires-' . $escapedTokenId . '">Expires At</label>
            <input form="' . $updateFormId . '" class="input" id="upload-token-expires-' . $escapedTokenId . '" name="expires_at" type="datetime-local" value="' . HelperFramework::escape($this->datetimeLocalValue((string)($token['expires_at'] ?? ''))) . '">
        </div>
        <p class="helper">Created: ' . HelperFramework::escape((string)($token['created_at'] ?? '')) . '</p>
        <p class="helper">Last used: ' . HelperFramework::escape((string)($token['last_used_at'] ?? 'Never')) . '</p>';
    }

    private function datesExportValue(array $token): string
    {
        return implode(' | ', [
            'Created: ' . (string)($token['created_at'] ?? ''),
            'Last used: ' . ((string)($token['last_used_at'] ?? '') !== '' ? (string)$token['last_used_at'] : 'Never'),
            'Expires: ' . ((string)($token['expires_at'] ?? '') !== '' ? (string)$token['expires_at'] : 'Never'),
        ]);
    }

    private function actionsHtml(array $context, array $token, string $csrfToken): string
    {
        $tokenId = (int)($token['id'] ?? 0);
        $escapedTokenId = HelperFramework::escape((string)$tokenId);
        $updateFormId = 'upload-token-update-' . $escapedTokenId;

        return '<div class="actions-row">
            <button form="' . $updateFormId . '" class="button primary" type="submit">Save</button>
            <form method="post" action="?page=settings" data-ajax="true">
                ' . $this->hiddenFields($context) . '
                <input type="hidden" name="card_action" value="UploadTokens">
                <input type="hidden" name="upload_token_action" value="delete">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input type="hidden" name="token_id" value="' . $escapedTokenId . '">
                <button class="button danger" type="submit">Delete</button>
            </form>
        </div>';
    }

    private function tableHiddenFields(array $context, array $extraFields = []): array
    {
        return array_merge([
            'page' => (string)($context['page']['page_id'] ?? 'settings'),
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
        ], $extraFields);
    }

    private function selectedOwnershipFilter(array $context): string
    {
        return $this->normaliseOwnershipFilter((string)(($context[$this->key()] ?? [])['ownership_filter'] ?? 'owned'));
    }

    private function normaliseOwnershipFilter(string $filter): string
    {
        $filter = strtolower(trim($filter));

        return array_key_exists($filter, $this->ownershipFilterOptions()) ? $filter : 'owned';
    }

    private function ownershipFilterOptions(): array
    {
        return [
            'owned' => 'Owned Tokens',
            'all' => 'All Tokens',
        ];
    }

    private function currentUserIdFromContext(array $context): int
    {
        return max(0, (int)(($context[$this->key()] ?? [])['current_user_id'] ?? 0));
    }

    private function currentUserId(): int
    {
        try {
            $sessionAuthenticationService = new SessionAuthenticationService();
            $sessionAuthenticationService->startSession();
            $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

            return max(0, (int)$sessionAuthenticationService->authenticatedUserId($currentDeviceId));
        } catch (Throwable) {
            return 0;
        }
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'upload.tokens');
    }

    private function datetimeLocalValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d\TH:i');
        } catch (Throwable) {
            return '';
        }
    }

    private function hiddenFields(array $context, string $formId = ''): string
    {
        $html = '';
        $formAttribute = $formId !== '' ? ' form="' . HelperFramework::escape($formId) . '"' : '';
        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input' . $formAttribute . ' type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }
        $html .= '<input' . $formAttribute . ' type="hidden" name="upload_tokens_filter" value="' . HelperFramework::escape($this->selectedOwnershipFilter($context)) . '">';

        return $html;
    }
}
