<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailInternalProfilesService;

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

    public function services(): array
    {
        return [
            [
                'key' => 'internal_profiles_dashboard',
                'service' => SwallowtailInternalProfilesService::class,
                'method' => 'dashboard',
                'params' => [
                    'imageType' => ':internal_profiles.image_type',
                    'profileName' => ':internal_profiles.profile_name',
                ],
            ],
        ];
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
        $current = (array)($pageContext[$this->key()] ?? []);
        $actionContext = (array)($actionResult->context()[$this->key()] ?? []);
        $state = array_replace($current, $actionContext);
        $imageType = trim((string)$request->input('internal_profiles_image_type', (string)($state['image_type'] ?? '')));
        $profileName = trim((string)$request->input('internal_profiles_profile_name', (string)($state['profile_name'] ?? '')));

        if ($imageType !== '') {
            $state['image_type'] = $imageType;
        }

        if ($profileName !== '') {
            $state['profile_name'] = $profileName;
        }

        $pageContext[$this->key()] = $state;

        return $pageContext;
    }

    public function render(array $context): string
    {
        $state = (array)($context[$this->key()] ?? []);
        $dashboard = $this->dashboard($context);
        $imageTypes = (array)($dashboard['image_types'] ?? SwallowtailInternalProfilesService::IMAGE_TYPES);
        $imageType = (string)($dashboard['image_type'] ?? 'preview');
        $profileNames = (array)($dashboard['profile_names'] ?? []);
        $profileName = (string)($dashboard['profile_name'] ?? 'default');
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        return '<div class="settings-fieldset">
            ' . $this->filterForms($imageTypes, $imageType, $profileName, $profileNames, $csrfToken) . '
            ' . $this->configuredTable($context)->render($context) . '
            ' . $this->adjustmentEntryForm($imageType, $profileName, $csrfToken) . '
        </div>';
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context)];
    }

    private function dashboard(array $context): array
    {
        return (array)(($context['services'] ?? [])['internal_profiles_dashboard'] ?? []);
    }

    private function configuredTable(array $context): TableFramework
    {
        return $this->table($context);
    }

    private function filterForms(array $imageTypes, string $imageType, string $profileName, array $profileNames, string $csrfToken): string
    {
        $imageOptions = '';
        foreach ($imageTypes as $type) {
            $type = (string)$type;
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
                <select class="select" id="internal-profiles-image-type" name="internal_profiles_image_type">' . $imageOptions . '</select>
            </form>
            <form method="post" action="?page=profiles" data-ajax="true" class="form-row half">
                <input type="hidden" name="cards[]" value="internal_profiles">
                <input type="hidden" name="_card_refresh" value="1">
                <input type="hidden" name="_invalidate_fact" value="internal.profiles">
                <input type="hidden" name="internal_profiles_image_type" value="' . HelperFramework::escape($imageType) . '">
                <label for="internal-profiles-profile-name">Profile name</label>
                <select class="select" id="internal-profiles-profile-name" name="internal_profiles_profile_name">' . $nameOptions . '</select>
            </form>
            <div class="form-row half" aria-hidden="true"></div>
            <form method="post" action="?page=profiles" data-ajax="true" class="form-row half">
                <input type="hidden" name="cards[]" value="internal_profiles">
                <input type="hidden" name="card_action" value="InternalProfiles">
                <input type="hidden" name="internal_profiles_action" value="add_profile">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input type="hidden" name="internal_profiles_image_type" value="' . HelperFramework::escape($imageType) . '">
                <label for="internal-profiles-new-profile-name">Add a new profile</label>
                <div class="input-action-row">
                    <input class="input" id="internal-profiles-new-profile-name" name="internal_profiles_new_profile_name" type="text" value="' . HelperFramework::escape($profileName) . '">
                    <button class="button button-inline" type="submit">Add</button>
                </div>
            </form>
        </div>';
    }

    private function adjustmentEntryForm(string $imageType, string $profileName, string $csrfToken): string
    {
        return '<form method="post" action="?page=profiles" data-ajax="true" class="card-toolbar internal-profile-adjustment-action" data-internal-profile-adjustment-form="true">
            <input type="hidden" name="cards[]" value="internal_profiles">
            <input type="hidden" name="card_action" value="InternalProfiles">
            <input type="hidden" name="internal_profiles_action" value="add_profile">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <input type="hidden" name="internal_profiles_image_type" value="' . HelperFramework::escape($imageType) . '" data-internal-profile-image-field="true">
            <input type="hidden" name="internal_profiles_profile_name" value="' . HelperFramework::escape($profileName) . '" data-internal-profile-name-field="true">
            <input type="hidden" name="internal_profiles_new_profile_name" value="' . HelperFramework::escape($profileName) . '" data-internal-profile-new-name-field="true">
            <div class="actions-row">
                <button class="button button-inline" type="submit" data-internal-profile-adjustment-label="true">' . HelperFramework::escape($this->adjustmentEntryButtonLabel($imageType, $profileName)) . '</button>
            </div>
        </form>';
    }

    private function adjustmentEntryButtonLabel(string $imageType, string $profileName): string
    {
        return 'Add adjustment entry for ' . $imageType . ' : ' . $profileName;
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->exports(false)
            ->empty('No internal profile rows are available.')
            ->classes('profile-editor-table', 'table-scroll profile-editor-grid')
            ->column('type', 'Type', html: fn(array $row): string => $this->typeCell($row), exportable: false)
            ->column('key', 'Key', html: fn(array $row): string => $this->textInputCell($row, 'key'), exportable: false)
            ->column('value', 'Value', html: fn(array $row): string => $this->valueCell($row), exportable: false)
            ->column('value_type', 'Value Type', html: fn(array $row): string => $this->valueTypeSelect($row), exportable: false)
            ->column('actions', 'Actions', html: fn(array $row): string => $this->actionsCell($row), exportable: false);
    }

    private function rows(array $context): array
    {
        $state = (array)($context[$this->key()] ?? []);
        $dashboard = $this->dashboard($context);
        $imageType = (string)($dashboard['image_type'] ?? 'preview');
        $profileName = (string)($dashboard['profile_name'] ?? 'default');
        $rows = (array)($dashboard['rows'] ?? []);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        if (!empty($state['draft']) || $rows === []) {
            $rows[] = $this->draftRow($imageType, $profileName);
        }

        return array_map(
            fn(array $row): array => $this->normaliseTableRow($row, $csrfToken),
            $rows
        );
    }

    private function draftRow(string $imageType, string $profileName): array
    {
        return [
            'id' => 0,
            'image_type' => $imageType,
            'profile_name' => $profileName,
            'type' => '',
            'key' => '',
            'value' => '',
            'value_type' => 'string',
            '_draft' => true,
        ];
    }

    private function normaliseTableRow(array $row, string $csrfToken): array
    {
        $id = max(0, (int)($row['id'] ?? 0));
        $prefix = 'internal-profile-row-' . (string)$id . '-' . substr(hash('sha1', (string)json_encode($row)), 0, 8);
        $row['_id'] = $id;
        $row['_csrf_token'] = $csrfToken;
        $row['_form_id'] = $prefix . '-form';
        $row['_type_id'] = $prefix . '-type';
        $row['_key_id'] = $prefix . '-key';
        $row['_value_id'] = $prefix . '-value';
        $row['_value_type_id'] = $prefix . '-value-type';
        $row['_value_type_token'] = $prefix . '-value-type-token';
        $row['_save_id'] = $prefix . '-save';
        $row['_draft'] = !empty($row['_draft']);

        return $row;
    }

    private function typeCell(array $row): string
    {
        return $this->rowForm($row) . $this->textInputCell($row, 'type');
    }

    private function rowForm(array $row): string
    {
        $formId = (string)($row['_form_id'] ?? '');
        $typeId = (string)($row['_type_id'] ?? '');
        $keyId = (string)($row['_key_id'] ?? '');
        $valueId = (string)($row['_value_id'] ?? '');
        $valueTypeId = (string)($row['_value_type_id'] ?? '');
        $saveId = (string)($row['_save_id'] ?? '');
        $id = max(0, (int)($row['_id'] ?? 0));

        return '<form id="' . HelperFramework::escape($formId) . '" method="post" action="?page=profiles" data-ajax="true"
            data-state-fields="' . HelperFramework::escape($typeId . ',' . $keyId . ',' . $valueId . ',' . $valueTypeId) . '"
            data-state-target="' . HelperFramework::escape($saveId) . '">
            <input type="hidden" name="cards[]" value="internal_profiles">
            <input type="hidden" name="card_action" value="InternalProfiles">
            <input type="hidden" name="internal_profiles_action" value="save_row">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape((string)($row['_csrf_token'] ?? '')) . '">
            <input type="hidden" name="internal_profile_id" value="' . HelperFramework::escape((string)$id) . '">
            <input type="hidden" name="internal_profiles_move_id" value="' . HelperFramework::escape((string)$id) . '">
            <input type="hidden" name="internal_profiles_move_direction" value="">
            <input type="hidden" name="internal_profiles_image_type" value="' . HelperFramework::escape((string)($row['image_type'] ?? 'preview')) . '">
            <input type="hidden" name="internal_profiles_profile_name" value="' . HelperFramework::escape((string)($row['profile_name'] ?? 'default')) . '">
        </form>';
    }

    private function textInputCell(array $row, string $field): string
    {
        $id = (string)($row['_' . $field . '_id'] ?? '');
        $value = (string)($row[$field] ?? '');
        $name = match ($field) {
            'type' => 'internal_profile_type',
            'key' => 'internal_profile_key',
            default => 'internal_profile_value',
        };
        $placeholder = match ($field) {
            'type' => 'Section',
            'key' => 'Key',
            default => 'Value',
        };

        return '<input class="input" id="' . HelperFramework::escape($id) . '" form="' . HelperFramework::escape((string)($row['_form_id'] ?? '')) . '" name="' . HelperFramework::escape($name) . '" type="text" value="' . HelperFramework::escape($value) . '" data-state-default="' . HelperFramework::escape($value) . '" placeholder="' . HelperFramework::escape($placeholder) . '">';
    }

    private function valueCell(array $row): string
    {
        $value = $row['value'] ?? '';
        $valueType = (string)($row['value_type'] ?? 'string');
        $stateDefault = $value === null ? '' : (string)$value;
        $formId = (string)($row['_form_id'] ?? '');
        $id = (string)($row['_value_id'] ?? '');
        $token = (string)($row['_value_type_token'] ?? '');

        $control = FieldValidationFramework::renderTypedValueControl('internal_profile_value', $value, $valueType, [
            'id' => $id,
            'placeholder' => 'Value',
            'attributes' => [
                'form' => $formId,
                'data-state-default' => $stateDefault,
            ],
        ]);

        return '<span data-validate-dynamic-control="true"'
            . ' data-validate-type-target="' . HelperFramework::escape($token) . '"'
            . ' data-validate-dynamic-name="internal_profile_value"'
            . ' data-validate-dynamic-id="' . HelperFramework::escape($id) . '"'
            . ' data-validate-dynamic-form="' . HelperFramework::escape($formId) . '"'
            . ' data-validate-dynamic-placeholder="Value"'
            . ' data-validate-dynamic-state-default="' . HelperFramework::escape($stateDefault) . '">'
            . $control
            . '</span>';
    }

    private function valueTypeSelect(array $row): string
    {
        $current = (string)($row['value_type'] ?? 'string');
        $formId = (string)($row['_form_id'] ?? '');
        $id = (string)($row['_value_type_id'] ?? '');
        $token = (string)($row['_value_type_token'] ?? '');
        $options = '';
        foreach (SwallowtailInternalProfilesService::VALUE_TYPES as $type) {
            $options .= '<option value="' . HelperFramework::escape($type) . '"' . ($type === $current ? ' selected' : '') . '>' . HelperFramework::escape($type) . '</option>';
        }

        return '<select class="select" name="internal_profile_value_type"'
            . ($formId !== '' ? ' form="' . HelperFramework::escape($formId) . '"' : '')
            . ($id !== '' ? ' id="' . HelperFramework::escape($id) . '"' : '')
            . ' data-state-default="' . HelperFramework::escape($current) . '"'
            . ($token !== '' ? ' data-validate-type-control="' . HelperFramework::escape($token) . '"' : '')
            . '>' . $options . '</select>';
    }

    private function actionsCell(array $row): string
    {
        $id = max(0, (int)($row['_id'] ?? 0));
        $formId = (string)($row['_form_id'] ?? '');

        return '<span class="actions-row">'
            . (!empty($row['_draft']) ? '' : $this->moveButton($id, $formId, 'up', '+') . $this->moveButton($id, $formId, 'down', '-'))
            . '<button id="' . HelperFramework::escape((string)($row['_save_id'] ?? '')) . '" form="' . HelperFramework::escape($formId) . '" class="button button-inline primary" type="submit" disabled>Save</button>'
            . '</span>';
    }

    private function moveButton(int $id, string $formId, string $direction, string $label): string
    {
        return '<button class="button button-inline" type="submit" form="' . HelperFramework::escape($formId) . '" formaction="?page=profiles" name="internal_profiles_move" value="' . HelperFramework::escape($direction) . '"
            onclick="this.form.internal_profiles_action.value=\'move_profile\'; this.form.internal_profiles_move_direction.value=\'' . HelperFramework::escape($direction) . '\';"
            title="Move ' . HelperFramework::escape($direction) . '">' . HelperFramework::escape($label) . '</button>';
    }
}
