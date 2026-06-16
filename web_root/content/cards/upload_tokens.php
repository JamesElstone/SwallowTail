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
            . $this->tokensTable($context, $csrfToken);
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

    private function tokensTable(array $context, string $csrfToken): string
    {
        $tokens = (array)(($context['services'] ?? [])['upload_tokens'] ?? []);
        if ($tokens === []) {
            return '<p class="helper">No upload tokens have been created.</p>';
        }

        $rows = '';
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            $rows .= $this->tokenRow($context, $token, $csrfToken);
        }

        if ($rows === '') {
            return '<p class="helper">No upload tokens have been created.</p>';
        }

        return '<div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Token</th>
                        <th>Access</th>
                        <th>Dates</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>' . $rows . '</tbody>
            </table>
        </div>';
    }

    private function tokenRow(array $context, array $token, string $csrfToken): string
    {
        $tokenId = (int)($token['id'] ?? 0);
        $escapedTokenId = HelperFramework::escape((string)$tokenId);
        $updateFormId = 'upload-token-update-' . $escapedTokenId;
        $isActive = (int)($token['is_active'] ?? 0) === 1;
        $canUploadRaw = (int)($token['can_upload_raw'] ?? 0) === 1;
        $cidrs = implode("\n", (array)($token['cidrs'] ?? []));

        return '<tr>
            <td>
                <form id="' . $updateFormId . '" method="post" action="?page=settings" data-ajax="true"></form>
                ' . $this->hiddenFields($context, $updateFormId) . '
                <input form="' . $updateFormId . '" type="hidden" name="card_action" value="UploadTokens">
                <input form="' . $updateFormId . '" type="hidden" name="upload_token_action" value="update">
                <input form="' . $updateFormId . '" type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input form="' . $updateFormId . '" type="hidden" name="token_id" value="' . $escapedTokenId . '">
                    <div class="form-row full">
                        <label for="upload-token-label-' . $escapedTokenId . '">Label</label>
                        <input form="' . $updateFormId . '" class="input" id="upload-token-label-' . $escapedTokenId . '" name="token_label" type="text" value="' . HelperFramework::escape((string)($token['token_label'] ?? '')) . '" required>
                    </div>
            </td>
            <td>
                    <label class="checkbox-item" for="upload-token-active-' . $escapedTokenId . '">
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
                    </div>
            </td>
            <td>
                    <div class="form-row full">
                        <label for="upload-token-expires-' . $escapedTokenId . '">Expires At</label>
                        <input form="' . $updateFormId . '" class="input" id="upload-token-expires-' . $escapedTokenId . '" name="expires_at" type="datetime-local" value="' . HelperFramework::escape($this->datetimeLocalValue((string)($token['expires_at'] ?? ''))) . '">
                    </div>
                    <p class="helper">Created: ' . HelperFramework::escape((string)($token['created_at'] ?? '')) . '</p>
                    <p class="helper">Last used: ' . HelperFramework::escape((string)($token['last_used_at'] ?? 'Never')) . '</p>
            </td>
            <td>
                    <div class="actions-row">
                        <button form="' . $updateFormId . '" class="button primary" type="submit">Save</button>
                <form method="post" action="?page=settings" data-ajax="true">
                    ' . $this->hiddenFields($context) . '
                    <input type="hidden" name="card_action" value="UploadTokens">
                    <input type="hidden" name="upload_token_action" value="delete">
                    <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                    <input type="hidden" name="token_id" value="' . $escapedTokenId . '">
                    <button class="button danger" type="submit">Delete</button>
                </form>
                    </div>
            </td>
        </tr>';
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

        return $html;
    }
}
