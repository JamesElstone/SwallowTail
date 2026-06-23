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
        $daylightSaving = (array)AppConfigurationStore::get('swallowtail.timezone.daylight_saving', []);
        $dstEnabled = (bool)($daylightSaving['enabled'] ?? false);
        $dstStart = $this->monthDayForDateInput((string)($daylightSaving['start'] ?? '03-31'));
        $dstEnd = $this->monthDayForDateInput((string)($daylightSaving['end'] ?? '10-31'));
        $dstOffset = (int)($daylightSaving['offset_minutes'] ?? 60);

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
            <fieldset class="form-row full settings-fieldset">
                <legend>Daylight saving</legend>
                <div class="form-row full">
                    <label class="checkbox-label" for="timezone-dst-enabled">
                        <input type="checkbox" id="timezone-dst-enabled" name="daylight_saving_enabled" value="1" data-submit-on-change="true"' . ($dstEnabled ? ' checked' : '') . '>
                        Apply Daylight Saving Time
                    </label>
                </div>
                <div class="form-row">
                    <label for="timezone-dst-start">Daylight Saving Start</label>
                    <input class="text-input" id="timezone-dst-start" type="date" name="daylight_saving_start" value="' . HelperFramework::escape($dstStart) . '" data-submit-on-change="true">
                </div>
                <div class="form-row">
                    <label for="timezone-dst-end">Daylight Saving End</label>
                    <input class="text-input" id="timezone-dst-end" type="date" name="daylight_saving_end" value="' . HelperFramework::escape($dstEnd) . '" data-submit-on-change="true">
                </div>
                <div class="form-row">
                    <label for="timezone-dst-offset">Daylight Saving Offset</label>
                    <select class="selector-input" id="timezone-dst-offset" name="daylight_saving_offset_minutes" data-submit-on-change="true">
                        ' . $this->offsetOptions($dstOffset) . '
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

    private function offsetOptions(int $current): string
    {
        $options = [
            60 => '+1',
            30 => '+0.5',
            0 => '0',
            -30 => '-0.5',
            -60 => '-1',
        ];
        $html = '';
        foreach ($options as $minutes => $label) {
            $html .= '<option value="' . $minutes . '"'
                . ($minutes === $current ? ' selected' : '')
                . '>' . HelperFramework::escape($label) . '</option>';
        }

        return $html;
    }

    private function monthDayForDateInput(string $monthDay): string
    {
        return date('Y') . '-' . preg_replace('/[^0-9-]/', '', $monthDay);
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
