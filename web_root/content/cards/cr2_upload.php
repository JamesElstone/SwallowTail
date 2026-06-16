<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _cr2_uploadCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'cr2_upload';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['cr2.upload'];
    }

    public function title(): string
    {
        return 'CR2 Upload';
    }

    public function helper(array $context): string
    {
        return 'Drag up to three CR2 RAW image files here to store them privately and queue derivatives.';
    }

    public function render(array $context): string
    {
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        return '
            <form method="post" action="?page=upload" enctype="multipart/form-data" class="form-grid raw-upload-form" data-raw-upload-form="true">
                ' . $this->hiddenFields($context) . '
                <input type="hidden" name="action" value="upload-cr2">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">

                <div class="form-row full">
                    <label class="upload-box upload-dropzone raw-upload-dropzone" data-upload-dropzone="true" data-upload-max-files="3" data-upload-file-label="CR2 file">
                        <span class="raw-upload-icon" aria-hidden="true">CR2</span>
                        <span class="raw-upload-copy">
                            <strong>Drop CR2 files here</strong>
                            <span>or choose up to three files from your computer.</span>
                        </span>
                        <input class="sr-only" type="file" name="cr2_files[]" accept=".cr2,.CR2" multiple data-upload-input="true">
                    </label>
                </div>

                <div class="form-row full raw-upload-selection">
                    <p class="helper" data-upload-selection-summary>No files selected yet.</p>
                    <ul class="file-list" data-upload-file-list hidden></ul>
                </div>

                <div class="form-row full raw-upload-progress" data-raw-upload-status hidden></div>

                <div class="form-row full">
                    <button class="button primary" type="submit" data-upload-submit data-processing-text="Uploading" data-processing-state="disabled">Upload CR2 Files</button>
                </div>
            </form>
            ' . $this->uploadResultMarkup((array)($context['upload_result'] ?? [])) . '
        ';
    }

    private function uploadResultMarkup(array $uploadResult): string
    {
        $files = (array)($uploadResult['files'] ?? []);
        if ($files === []) {
            return '';
        }

        $html = '<div class="raw-upload-results"><h3>Upload results</h3><ul>';
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $success = !empty($file['success']);
            $duplicate = !empty($file['duplicate']);
            $label = (string)($file['filename'] ?? 'CR2 file');
            $status = $success
                ? ($duplicate ? 'Duplicate already in library' : 'Uploaded and queued')
                : implode(' ', array_map('strval', (array)($file['errors'] ?? ['Upload failed.'])));

            $html .= '<li class="' . ($success ? 'success' : 'error') . '"><strong>'
                . HelperFramework::escape($label)
                . '</strong><span>'
                . HelperFramework::escape($status)
                . '</span></li>';
        }

        return $html . '</ul></div>';
    }

    private function hiddenFields(array $context): string
    {
        $html = '';

        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        return $html;
    }
}
