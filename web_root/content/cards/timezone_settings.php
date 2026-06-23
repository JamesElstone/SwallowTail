<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _timezone_settingsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'timezone_settings';
    }

    public function title(): string
    {
        return 'TimeZone';
    }

    public function render(array $context): string
    {
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $current = trim((string)AppConfigurationStore::get('swallowtail.timezone.server', 'Europe/London'));
        if ($current === '') {
            $current = 'Europe/London';
        }

        return '<form method="post" action="?page=settings" data-ajax="true" class="form-grid">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="card_action" value="TimezoneSettings">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <fieldset class="form-row full settings-fieldset">
                <legend>Server timezone</legend>
                <div class="form-row full">
                    <label for="timezone-server">Server timezone</label>
                    <select class="selector-input" id="timezone-server" name="server_timezone" data-submit-on-change="true">
                        ' . $this->timezoneOptions($current) . '
                    </select>
                </div>
            </fieldset>
        </form>';
    }

    private function timezoneOptions(string $current): string
    {
        $html = '';
        foreach (DateTimeZone::listIdentifiers() as $timezone) {
            $html .= '<option value="' . HelperFramework::escape($timezone) . '"'
                . ($timezone === $current ? ' selected' : '')
                . '>' . HelperFramework::escape($timezone) . '</option>';
        }

        return $html;
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
