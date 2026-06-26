<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _internal_profilesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'internal_profiles';
    }

    public function title(): string
    {
        return 'Internal Profiles';
    }

    public function helper(array $context): string
    {
        return 'Reusable PP3 overlays stored in internal_profile_data.';
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $service = new SwallowtailInternalProfilesService();
        $current = (array)($pageContext[$this->key()] ?? []);
        $actionContext = (array)($actionResult->context()[$this->key()] ?? []);
        $imageType = $service->normaliseImageType((string)$request->input('internal_profiles_image_type', (string)($actionContext['image_type'] ?? $current['image_type'] ?? 'preview')));
        $names = $service->profileNames($imageType);
        $profileName = $service->normaliseProfileName((string)$request->input('internal_profiles_profile_name', (string)($actionContext['profile_name'] ?? $current['profile_name'] ?? ($names[0] ?? 'default'))));

        $pageContext[$this->key()] = array_replace($current, $actionContext, [
            'image_type' => $imageType,
            'profile_name' => $profileName,
        ]);

        return $pageContext;
    }

    public function render(array $context): string
    {
        $service = new SwallowtailInternalProfilesService();
        if (!$service->tableAvailable()) {
            return '<div class="panel-soft warn">internal_profile_data is not available. Run database migrations.</div>';
        }

        $state = (array)($context[$this->key()] ?? []);
        $imageType = $service->normaliseImageType((string)($state['image_type'] ?? 'preview'));
        $profileNames = $service->profileNames($imageType);
        $profileName = $service->normaliseProfileName((string)($state['profile_name'] ?? ($profileNames[0] ?? 'default')));
        $rows = $service->rows($imageType, $profileName);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $draft = !empty($state['draft']);

        return '<div class="settings-fieldset">
            ' . $this->filterForms($service, $imageType, $profileName, $profileNames, $csrfToken) . '
            <div class="profile-editor-grid">
                ' . $this->headerRow() . '
                ' . implode('', array_map(fn(array $row): string => $this->rowForm($row, $csrfToken), $rows)) . '
                ' . ($draft || $rows === [] ? $this->draftRow($imageType, $profileName, $csrfToken) : '') . '
            </div>
        </div>';
    }

    private function filterForms(SwallowtailInternalProfilesService $service, string $imageType, string $profileName, array $profileNames, string $csrfToken): string
    {
        $imageOptions = '';
        foreach ($service->imageTypes() as $type) {
            $imageOptions .= '<option value="' . HelperFramework::escape($type) . '"' . ($type === $imageType ? ' selected' : '') . '>' . HelperFramework::escape($type) . '</option>';
        }

        $nameOptions = '';
        foreach (array_values(array_unique(array_merge($profileNames, [$profileName]))) as $name) {
            $nameOptions .= '<option value="' . HelperFramework::escape($name) . '"' . ($name === $profileName ? ' selected' : '') . '>' . HelperFramework::escape($name) . '</option>';
        }

        return '<div class="form-grid">
            <form method="post" action="?page=profiles" data-ajax="true" class="form-row half">
                <input type="hidden" name="cards[]" value="internal_profiles">
                <input type="hidden" name="_card_refresh" value="1">
                <input type="hidden" name="_invalidate_fact" value="internal.profiles">
                <label for="internal-profiles-image-type">Image type</label>
                <select id="internal-profiles-image-type" name="internal_profiles_image_type">' . $imageOptions . '</select>
            </form>
            <form method="post" action="?page=profiles" data-ajax="true" class="form-row half">
                <input type="hidden" name="cards[]" value="internal_profiles">
                <input type="hidden" name="_card_refresh" value="1">
                <input type="hidden" name="_invalidate_fact" value="internal.profiles">
                <input type="hidden" name="internal_profiles_image_type" value="' . HelperFramework::escape($imageType) . '">
                <label for="internal-profiles-profile-name">Profile name</label>
                <select id="internal-profiles-profile-name" name="internal_profiles_profile_name">' . $nameOptions . '</select>
            </form>
            <form method="post" action="?page=profiles" data-ajax="true" class="form-row full">
                <input type="hidden" name="cards[]" value="internal_profiles">
                <input type="hidden" name="card_action" value="InternalProfiles">
                <input type="hidden" name="internal_profiles_action" value="add_profile">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input type="hidden" name="internal_profiles_image_type" value="' . HelperFramework::escape($imageType) . '">
                <label for="internal-profiles-new-profile-name">New profile name</label>
                <div class="input-action-row">
                    <input class="input" id="internal-profiles-new-profile-name" name="internal_profiles_new_profile_name" type="text" value="' . HelperFramework::escape($profileName) . '">
                    <button class="button button-inline" type="submit">Add</button>
                </div>
            </form>
            <form method="post" action="?page=profiles" data-ajax="true" class="form-row full">
                <input type="hidden" name="cards[]" value="internal_profiles">
                <input type="hidden" name="card_action" value="InternalProfiles">
                <input type="hidden" name="internal_profiles_action" value="add_profile">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input type="hidden" name="internal_profiles_image_type" value="' . HelperFramework::escape($imageType) . '">
                <input type="hidden" name="internal_profiles_new_profile_name" value="' . HelperFramework::escape($profileName) . '">
                <button class="button button-inline" type="submit">Add Row For Image Type</button>
            </form>
        </div>';
    }

    private function headerRow(): string
    {
        return '<div class="settings-order-row profile-editor-row profile-editor-header">
            <strong>Type</strong><strong>Key</strong><strong>Value</strong><strong>Value Type</strong><strong>Actions</strong>
        </div>';
    }

    private function draftRow(string $imageType, string $profileName, string $csrfToken): string
    {
        return $this->rowForm([
            'id' => 0,
            'image_type' => $imageType,
            'profile_name' => $profileName,
            'type' => '',
            'key' => '',
            'value' => '',
            'value_type' => 'string',
        ], $csrfToken, true);
    }

    private function rowForm(array $row, string $csrfToken, bool $draft = false): string
    {
        $id = max(0, (int)($row['id'] ?? 0));
        $prefix = 'internal-profile-row-' . (string)$id . '-' . substr(hash('sha1', (string)json_encode($row)), 0, 8);
        $typeId = $prefix . '-type';
        $keyId = $prefix . '-key';
        $valueId = $prefix . '-value';
        $saveId = $prefix . '-save';
        $imageType = (string)($row['image_type'] ?? 'preview');
        $profileName = (string)($row['profile_name'] ?? 'default');
        $type = (string)($row['type'] ?? '');
        $key = (string)($row['key'] ?? '');
        $value = (string)($row['value'] ?? '');
        $valueType = (string)($row['value_type'] ?? 'string');

        return '<form method="post" action="?page=profiles" data-ajax="true" class="settings-order-row profile-editor-row"
            data-state-fields="' . HelperFramework::escape($typeId . ',' . $keyId . ',' . $valueId) . '"
            data-state-target="' . HelperFramework::escape($saveId) . '">
            <input type="hidden" name="cards[]" value="internal_profiles">
            <input type="hidden" name="card_action" value="InternalProfiles">
            <input type="hidden" name="internal_profiles_action" value="save_row">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <input type="hidden" name="internal_profile_id" value="' . HelperFramework::escape((string)$id) . '">
            <input type="hidden" name="internal_profiles_move_id" value="' . HelperFramework::escape((string)$id) . '">
            <input type="hidden" name="internal_profiles_move_direction" value="">
            <input type="hidden" name="internal_profiles_image_type" value="' . HelperFramework::escape($imageType) . '">
            <input type="hidden" name="internal_profiles_profile_name" value="' . HelperFramework::escape($profileName) . '">
            <input class="input" id="' . HelperFramework::escape($typeId) . '" name="internal_profile_type" type="text" value="' . HelperFramework::escape($type) . '" data-state-default="' . HelperFramework::escape($type) . '" placeholder="Section">
            <input class="input" id="' . HelperFramework::escape($keyId) . '" name="internal_profile_key" type="text" value="' . HelperFramework::escape($key) . '" data-state-default="' . HelperFramework::escape($key) . '" placeholder="Key">
            <input class="input" id="' . HelperFramework::escape($valueId) . '" name="internal_profile_value" type="text" value="' . HelperFramework::escape($value) . '" data-state-default="' . HelperFramework::escape($value) . '" placeholder="Value">
            ' . $this->valueTypeSelect($valueType, !$draft) . '
            <span class="actions-row">
                ' . ($draft ? '' : $this->moveButton($id, $imageType, $profileName, 'up', '+', $csrfToken) . $this->moveButton($id, $imageType, $profileName, 'down', '-', $csrfToken)) . '
                <button id="' . HelperFramework::escape($saveId) . '" class="button button-inline primary" type="submit" disabled>Save</button>
            </span>
        </form>';
    }

    private function valueTypeSelect(string $current, bool $submitOnChange): string
    {
        $options = '';
        foreach (SwallowtailInternalProfilesService::VALUE_TYPES as $type) {
            $options .= '<option value="' . HelperFramework::escape($type) . '"' . ($type === $current ? ' selected' : '') . '>' . HelperFramework::escape($type) . '</option>';
        }

        return '<select name="internal_profile_value_type"' . ($submitOnChange ? ' data-submit-on-change="true"' : '') . '>' . $options . '</select>';
    }

    private function moveButton(int $id, string $imageType, string $profileName, string $direction, string $label, string $csrfToken): string
    {
        return '<button class="button button-inline" type="submit" formaction="?page=profiles" name="internal_profiles_move" value="' . HelperFramework::escape($direction) . '"
            onclick="this.form.internal_profiles_action.value=\'move_profile\'; this.form.internal_profiles_move_direction.value=\'' . HelperFramework::escape($direction) . '\';"
            title="Move ' . HelperFramework::escape($direction) . '">' . HelperFramework::escape($label) . '</button>';
    }
}
