(function () {
  'use strict';

  const panels = new WeakSet();
  const pollTimers = new WeakMap();
  const pictureEditorBaselinePolls = new WeakMap();

  function statusLabel(status) {
    const value = String(status || '').toLowerCase();
    if (value === 'processing') {
      return 'Rendering';
    }
    if (value === 'succeeded' || value === 'ready' || value === 'loaded') {
      return 'Ready';
    }
    return 'Queued';
  }

  function setText(node, text) {
    if (node instanceof HTMLElement) {
      node.textContent = text;
    }
  }

  function isBusyStatus(text) {
    const value = String(text || '').toLowerCase();
    return value === 'queued' || value === 'queuing' || value === 'rendering' || value === 'loading';
  }

  function setStatusImage(panel, text) {
    const image = panel.querySelector('[data-rawtherapee-profile-state-image="true"]');
    if (!(image instanceof HTMLImageElement)) {
      return;
    }

    const nextSrc = isBusyStatus(text)
      ? String(image.dataset.busySrc || '/swallowtail_256.gif')
      : String(image.dataset.readySrc || '/swallowtail_butterfly_42x42.png');
    if ((image.getAttribute('src') || '') !== nextSrc) {
      image.src = nextSrc;
    }
  }

  function setStatus(panel, text) {
    setText(panel.querySelector('[data-rawtherapee-profile-status="true"]'), text);
    setStatusImage(panel, text);
  }

  function setImageShown(panel, text) {
    setText(panel.querySelector('[data-rawtherapee-profile-image-shown="true"]'), text);
  }

  function setAppliedProfile(panel, text) {
    setText(panel.querySelector('[data-rawtherapee-profile-applied="true"]'), text);
  }

  function setDisplayFields(panel, url, type) {
    panel.querySelectorAll('[data-rawtherapee-display-url-field="true"]').forEach((urlField) => {
      if (urlField instanceof HTMLInputElement) {
        urlField.value = String(url || '');
      }
    });

    panel.querySelectorAll('[data-rawtherapee-display-type-field="true"]').forEach((typeField) => {
      if (typeField instanceof HTMLInputElement) {
        typeField.value = String(type || 'none');
      }
    });
  }

  function syncDisplayFields(panel, image) {
    if (!(image instanceof HTMLImageElement)) {
      return;
    }

    setDisplayFields(panel, image.getAttribute('src') || '', String(image.dataset.rawtherapeeProfileImageType || 'none'));
  }

  function imageLoaded(image) {
    return image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0;
  }

  function ensureRawTherapeeProfileImage(panel) {
    const existing = panel.querySelector('[data-rawtherapee-profile-image="true"]');
    if (existing instanceof HTMLImageElement) {
      return existing;
    }

    const preview = panel.querySelector('.rawtherapee-profile-preview');
    if (!(preview instanceof HTMLElement)) {
      return null;
    }

    const figure = document.createElement('figure');
    figure.className = 'rawtherapee-profile-preview-frame';

    const shell = document.createElement('span');
    shell.className = 'rawtherapee-profile-image-shell';

    const image = document.createElement('img');
    image.className = 'rawtherapee-profile-image';
    image.dataset.rawtherapeeProfileImage = 'true';
    image.alt = 'Photo';

    shell.appendChild(image);
    figure.appendChild(shell);
    preview.replaceChildren(figure);

    return image;
  }

  function bindImageLoad(panel, image) {
    if (!(image instanceof HTMLImageElement)) {
      return;
    }

    if (imageLoaded(image)) {
      setStatus(panel, 'Ready');
      syncDisplayFields(panel, image);
      return;
    }

    setStatus(panel, 'Loading');
    image.addEventListener('load', () => {
      syncDisplayFields(panel, image);
      setStatus(panel, 'Ready');
    }, { once: true });
  }

  function resolvedImagePayload(payload) {
    const rawTherapeeUrl = String(payload.rawtherapee_sample_url || '').trim();
    if (rawTherapeeUrl !== '') {
      return {
        type: 'rawtherapee',
        url: rawTherapeeUrl,
        profileLabel: String(payload.rawtherapee_sample_profile_label || '').trim(),
      };
    }

    const previewUrl = String(payload.preview_url || '').trim();
    if (previewUrl !== '') {
      return { type: 'preview', url: previewUrl, profileLabel: 'Current Profile' };
    }

    return { type: 'none', url: '', profileLabel: '' };
  }

  async function pollStatus(panel, url, attempt) {
    if (!panel.isConnected) {
      return;
    }

    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) {
        throw new Error(`Status request failed with ${response.status}`);
      }

      const payload = await response.json();
      const status = String(payload.status || '').toLowerCase();
      setStatus(panel, statusLabel(status));

      const resolved = resolvedImagePayload(payload);
      const image = resolved.url !== ''
        ? ensureRawTherapeeProfileImage(panel)
        : panel.querySelector('[data-rawtherapee-profile-image="true"]');
      if ((status === 'succeeded' || payload.ready === true) && resolved.url !== '' && image instanceof HTMLImageElement) {
        if ((image.getAttribute('src') || '') === resolved.url && imageLoaded(image)) {
          image.dataset.rawtherapeeProfileImageType = resolved.type;
          setImageShown(panel, resolved.type);
          setDisplayFields(panel, resolved.url, resolved.type);
          setAppliedProfile(panel, resolved.profileLabel || 'none');
          setStatus(panel, 'Ready');
          return;
        }

        setStatus(panel, 'Loading');
        setImageShown(panel, resolved.type);
        image.dataset.rawtherapeeProfileImageType = resolved.type;
        image.addEventListener('load', () => {
          setDisplayFields(panel, resolved.url, resolved.type);
          setAppliedProfile(panel, resolved.profileLabel || 'none');
          setStatus(panel, 'Ready');
        }, { once: true });
        image.src = resolved.url;
        return;
      }

      if (status === 'failed') {
        return;
      }
    } catch (error) {
      console.error(error);
    }

    if (attempt < 90 && panel.isConnected) {
      pollTimers.set(panel, window.setTimeout(() => pollStatus(panel, url, attempt + 1), 2000));
    }
  }

  function initialiseRawTherapeeProfilePanel(panel) {
    if (!(panel instanceof HTMLElement) || panels.has(panel)) {
      return;
    }

    panels.add(panel);
    const image = panel.querySelector('[data-rawtherapee-profile-image="true"]');
    const statusUrl = String(panel.dataset.rawtherapeeProfileStatusUrl || '').trim();

    if (statusUrl !== '') {
      pollStatus(panel, statusUrl, 0);
      return;
    }

    if (image instanceof HTMLImageElement) {
      syncDisplayFields(panel, image);
      bindImageLoad(panel, image);
    }
  }

  function initialise(root) {
    if (!root || typeof root.querySelectorAll !== 'function') {
      return;
    }

    root.querySelectorAll('[data-rawtherapee-profile-panel="true"]').forEach(initialiseRawTherapeeProfilePanel);
  }

  function prepareInternalProfileMove(button) {
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }

    const direction = String(button.dataset.internalProfileMoveDirection || '').trim();
    if (direction !== 'up' && direction !== 'down') {
      return;
    }

    const form = button.form;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    const actionField = form.elements.namedItem('internal_profiles_action');
    const directionField = form.elements.namedItem('internal_profiles_move_direction');

    if (actionField instanceof HTMLInputElement) {
      actionField.value = 'move_profile';
    }

    if (directionField instanceof HTMLInputElement) {
      directionField.value = direction;
    }
  }

  function syncRawTherapeeDefaultButton(select) {
    if (!(select instanceof HTMLSelectElement)) {
      return;
    }

    const form = select.closest('form');
    const button = form instanceof HTMLFormElement ? form.querySelector('[data-rawtherapee-set-default-button="true"]') : null;
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }

    const selected = String(select.value || '0');
    const defaultProfile = String(select.dataset.rawtherapeeDefaultProfileId || '0');
    const disabled = selected === '0' || selected === defaultProfile;
    button.disabled = disabled;
    button.setAttribute('aria-disabled', disabled ? 'true' : 'false');
  }

  function nextPictureEditorBaselineSequence(editor) {
    const next = (pictureEditorBaselinePolls.get(editor)?.sequence || 0) + 1;
    pictureEditorBaselinePolls.set(editor, { sequence: next, timer: null });
    return next;
  }

  function currentPictureEditorBaselineSequence(editor) {
    return pictureEditorBaselinePolls.get(editor)?.sequence || 0;
  }

  function clearPictureEditorBaselinePoll(editor) {
    const state = pictureEditorBaselinePolls.get(editor);
    if (typeof state?.timer === 'number') {
      window.clearTimeout(state.timer);
    }
  }

  function schedulePictureEditorBaselinePoll(editor, sequence, callback, delay) {
    clearPictureEditorBaselinePoll(editor);
    const timer = window.setTimeout(callback, delay);
    pictureEditorBaselinePolls.set(editor, { sequence, timer });
  }

  function setPictureEditorControlsEnabled(editor, enabled) {
    editor.querySelectorAll('[data-picture-editor-field], [data-picture-editor-number], [data-picture-editor-check], [data-picture-editor-revert], [data-picture-editor-save]').forEach((field) => {
      if (field instanceof HTMLInputElement || field instanceof HTMLButtonElement) {
        field.disabled = !enabled;
      }
    });
  }

  function setPictureEditorPhotoStatus(editor, text) {
    setText(editor.querySelector('[data-picture-editor-status]'), `Photo: ${text}`);
  }

  function setPictureEditorProfileStatus(editor, text) {
    setText(editor.querySelector('[data-picture-editor-profile-state]'), `Profile: ${text}`);
  }

  function setPictureEditorDisplayStatus(editor, text, type) {
    const node = editor.querySelector('[data-picture-editor-display-state]');
    if (node instanceof HTMLElement) {
      node.textContent = `Displaying: ${text}`;
      node.dataset.pictureEditorDisplayType = type;
    }
  }

  function pictureEditorSettingValue(settings, path) {
    return String(path || '').split('.').reduce((current, part) => current?.[part], settings);
  }

  function pictureEditorCssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }

    return String(value).replace(/[^A-Za-z0-9_-]/g, '\\$&');
  }

  function syncPictureEditorSettings(editor, settings) {
    if (!settings || typeof settings !== 'object') {
      return;
    }

    editor.querySelectorAll('[data-picture-editor-field]').forEach((field) => {
      if (!(field instanceof HTMLInputElement)) {
        return;
      }
      const key = String(field.dataset.pictureEditorField || '');
      const value = pictureEditorSettingValue(settings, key);
      if (value === undefined || value === null) {
        return;
      }
      field.value = String(value);
      const number = editor.querySelector(`[data-picture-editor-number="${pictureEditorCssEscape(key)}"]`);
      if (number instanceof HTMLInputElement) {
        number.value = String(value);
      }
      field.dispatchEvent(new Event('input'));
    });

    editor.querySelectorAll('[data-picture-editor-check]').forEach((field) => {
      if (!(field instanceof HTMLInputElement)) {
        return;
      }
      const key = String(field.dataset.pictureEditorCheck || '');
      const value = pictureEditorSettingValue(settings, key);
      if (value === undefined || value === null) {
        return;
      }
      field.checked = value === true || value === 1 || value === '1';
      field.dispatchEvent(new Event('change'));
    });
  }

  function ensurePictureEditorImage(editor) {
    const existing = editor.querySelector('[data-picture-editor-image]');
    if (existing instanceof HTMLImageElement) {
      return existing;
    }

    const stage = editor.querySelector('[data-picture-editor-stage]');
    const crop = editor.querySelector('[data-picture-editor-crop]');
    if (!(stage instanceof HTMLElement)) {
      return null;
    }

    const image = document.createElement('img');
    image.alt = 'Photo preview';
    image.dataset.pictureEditorImage = 'true';
    const empty = editor.querySelector('[data-picture-editor-empty]');
    if (empty instanceof HTMLElement) {
      empty.remove();
    }
    if (crop instanceof HTMLElement) {
      stage.insertBefore(image, crop);
    } else {
      stage.appendChild(image);
    }

    return image;
  }

  function showPictureEditorPreview(editor, url, type) {
    const image = ensurePictureEditorImage(editor);
    if (!(image instanceof HTMLImageElement)) {
      return;
    }

    const displayType = type === 'thumbnail' ? 'thumbnail' : 'preview';
    editor.dataset.previewType = displayType;
    editor.dataset.previewReady = displayType === 'preview' ? '1' : '0';
    setPictureEditorDisplayStatus(editor, displayType, displayType);
    setPictureEditorPhotoStatus(editor, 'Loading');
    image.addEventListener('load', () => {
      setPictureEditorPhotoStatus(editor, displayType === 'preview' ? 'Ready' : 'Queued');
      setPictureEditorControlsEnabled(editor, displayType === 'preview');
    }, { once: true });
    image.src = url;
    if (image.complete && image.naturalWidth > 0) {
      setPictureEditorPhotoStatus(editor, displayType === 'preview' ? 'Ready' : 'Queued');
      setPictureEditorControlsEnabled(editor, displayType === 'preview');
    }
  }

  function pictureEditorPreviewPayload(payload) {
    const preview = payload?.preview || payload || {};
    const previewUrl = String(preview.preview_url || payload?.preview_url || '').trim();
    if (previewUrl !== '') {
      return { ready: preview.ready === true || payload?.preview_ready === true, url: previewUrl, type: 'preview' };
    }

    const displayUrl = String(preview.display_url || payload?.display_url || '').trim();
    const displayType = String(preview.display_type || payload?.display_type || '').trim();
    if (displayUrl !== '' && (displayType === 'preview' || displayType === 'thumbnail')) {
      return { ready: displayType === 'preview' && (preview.ready === true || payload?.preview_ready === true), url: displayUrl, type: displayType };
    }

    return { ready: false, url: '', type: '' };
  }

  async function fetchPictureEditorJson(url, options = {}) {
    const response = await fetch(url, {
      ...options,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(options.headers || {}),
      },
    });
    const payload = await response.json();
    if (!response.ok || payload.success === false) {
      throw new Error((payload.errors || [payload.message || 'Picture editor request failed.']).join(' '));
    }
    return payload;
  }

  async function pollPictureEditorPreview(editor, statusUrl, sequence, attempt, startedAt) {
    if (!editor.isConnected || currentPictureEditorBaselineSequence(editor) !== sequence) {
      return;
    }

    if ((Date.now() - startedAt) > 120000) {
      setPictureEditorPhotoStatus(editor, 'Timed out');
      return;
    }

    try {
      const payload = await fetchPictureEditorJson(statusUrl);
      if (currentPictureEditorBaselineSequence(editor) !== sequence) {
        return;
      }

      const status = String(payload.status || 'queued').toLowerCase();
      const preview = pictureEditorPreviewPayload(payload);
      if ((status === 'succeeded' || preview.ready) && preview.url !== '') {
        showPictureEditorPreview(editor, preview.url, preview.type || 'preview');
        return;
      }

      if (status === 'failed' || status === 'cancelled') {
        setPictureEditorPhotoStatus(editor, status === 'cancelled' ? 'Cancelled' : 'Failed');
        return;
      }

      setPictureEditorPhotoStatus(editor, status === 'processing' ? 'Rendering' : 'Queued');
      schedulePictureEditorBaselinePoll(editor, sequence, () => {
        pollPictureEditorPreview(editor, statusUrl, sequence, attempt + 1, startedAt);
      }, attempt < 5 ? 750 : 1500);
    } catch (error) {
      console.error(error);
      schedulePictureEditorBaselinePoll(editor, sequence, () => {
        pollPictureEditorPreview(editor, statusUrl, sequence, attempt + 1, startedAt);
      }, attempt < 5 ? 1500 : 3000);
    }
  }

  async function pollPictureEditorBaseline(editor, select, statusUrl, sequence, attempt, startedAt) {
    if (!editor.isConnected || currentPictureEditorBaselineSequence(editor) !== sequence) {
      return;
    }

    if ((Date.now() - startedAt) > 120000) {
      setPictureEditorProfileStatus(editor, 'Timed out');
      select.disabled = false;
      return;
    }

    try {
      const payload = await fetchPictureEditorJson(statusUrl);
      if (currentPictureEditorBaselineSequence(editor) !== sequence) {
        return;
      }

      const baseline = payload?.baseline || {};
      const status = String(baseline.status || '').toLowerCase();
      if (baseline.ready === true) {
        editor.dataset.baselineReady = '1';
        setPictureEditorProfileStatus(editor, 'Ready');
        syncPictureEditorSettings(editor, payload?.settings);
        const preview = pictureEditorPreviewPayload(payload);
        const previewStatusUrl = String(payload?.preview?.status_url || payload?.preview_status_url || '').trim();
        if (preview.ready && preview.url !== '') {
          showPictureEditorPreview(editor, preview.url, preview.type || 'preview');
          select.disabled = false;
          return;
        }
        if (previewStatusUrl !== '') {
          editor.dataset.previewStatusUrl = previewStatusUrl;
          pollPictureEditorPreview(editor, previewStatusUrl, sequence, 0, Date.now());
          select.disabled = false;
          return;
        }

        setPictureEditorPhotoStatus(editor, 'Queued');
        select.disabled = false;
        return;
      }

      if (status === 'failed') {
        setPictureEditorProfileStatus(editor, 'Failed');
        setPictureEditorPhotoStatus(editor, 'Failed');
        select.disabled = false;
        return;
      }

      setPictureEditorProfileStatus(editor, 'Preparing');
      setPictureEditorPhotoStatus(editor, 'Waiting for profile');
      schedulePictureEditorBaselinePoll(editor, sequence, () => {
        pollPictureEditorBaseline(editor, select, statusUrl, sequence, attempt + 1, startedAt);
      }, attempt < 5 ? 1000 : 2500);
    } catch (error) {
      console.error(error);
      schedulePictureEditorBaselinePoll(editor, sequence, () => {
        pollPictureEditorBaseline(editor, select, statusUrl, sequence, attempt + 1, startedAt);
      }, attempt < 5 ? 1500 : 3000);
    }
  }

  async function changePictureEditorBaseline(select) {
    if (!(select instanceof HTMLSelectElement)) {
      return;
    }

    const editor = select.closest('[data-picture-editor="true"]');
    if (!(editor instanceof HTMLElement)) {
      return;
    }

    const url = String(editor.dataset.profileUrl || '/api/photo-update.php').trim() || '/api/photo-update.php';
    const statusUrl = String(editor.dataset.profileStatusUrl || '').trim();
    const photoId = String(editor.dataset.photoId || '').trim();
    const csrfToken = String(editor.dataset.csrfToken || '').trim();
    const profileState = editor.querySelector('[data-picture-editor-profile-state]');
    const previousValue = String(select.dataset.previousValue || select.defaultValue || '0');
    const sequence = nextPictureEditorBaselineSequence(editor);
    const form = new FormData();
    form.set('action', 'baseline_profile');
    form.set('photo_id', photoId);
    form.set('rawtherapee_profile_id', select.value);
    if (csrfToken !== '') {
      form.set('csrf_token', csrfToken);
    }

    select.disabled = true;
    setPictureEditorControlsEnabled(editor, false);
    editor.dataset.baselineReady = '0';
    editor.dataset.previewReady = '0';
    setPictureEditorDisplayStatus(editor, 'previous preview', 'preview');
    setText(profileState, 'Profile: Queued');
    try {
      const payload = await fetchPictureEditorJson(url, {
        method: 'POST',
        body: form,
      });
      select.dataset.previousValue = select.value;
      setText(profileState, 'Profile: Preparing');
      const statusNode = editor.querySelector('[data-picture-editor-status]');
      setText(statusNode, 'Photo: Waiting for profile');
      const baseline = payload?.baseline || {};
      if (baseline.ready === true) {
        editor.dataset.baselineReady = '1';
        setPictureEditorProfileStatus(editor, 'Ready');
      }
      if (statusUrl !== '' && currentPictureEditorBaselineSequence(editor) === sequence) {
        pollPictureEditorBaseline(editor, select, statusUrl, sequence, 0, Date.now());
        return;
      }
      const previewStatusUrl = String(payload?.preview?.status_url || payload?.preview_status_url || '').trim();
      if (previewStatusUrl !== '' && currentPictureEditorBaselineSequence(editor) === sequence) {
        pollPictureEditorPreview(editor, previewStatusUrl, sequence, 0, Date.now());
        return;
      }
      const preview = pictureEditorPreviewPayload(payload);
      if (preview.url !== '') {
        showPictureEditorPreview(editor, preview.url, preview.type || 'preview');
      }
    } catch (error) {
      console.error(error);
      select.value = previousValue;
      setText(profileState, 'Profile: Change failed');
      setPictureEditorPhotoStatus(editor, 'Failed');
      setPictureEditorControlsEnabled(editor, true);
    } finally {
      if (currentPictureEditorBaselineSequence(editor) !== sequence || statusUrl === '') {
        select.disabled = false;
      }
    }
  }

  document.addEventListener('DOMContentLoaded', () => initialise(document));

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    prepareInternalProfileMove(target.closest('[data-internal-profile-move-direction]'));
  });

  document.addEventListener('change', (event) => {
    const target = event.target;
    if (target instanceof HTMLSelectElement && target.matches('[data-picture-editor-baseline-profile]')) {
      changePictureEditorBaseline(target);
    } else if (target instanceof HTMLSelectElement && target.matches('[data-rawtherapee-default-profile-id]')) {
      syncRawTherapeeDefaultButton(target);
    }
  });

  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (node instanceof HTMLElement) {
          if (node.matches('[data-rawtherapee-profile-panel="true"]')) {
            initialiseRawTherapeeProfilePanel(node);
          }
          initialise(node);
        }
      });
    });
  });

  observer.observe(document.documentElement, {
    childList: true,
    subtree: true,
  });

  window.addEventListener('pagehide', () => {
    document.querySelectorAll('[data-rawtherapee-profile-panel="true"]').forEach((panel) => {
      const timer = pollTimers.get(panel);
      if (typeof timer === 'number') {
        window.clearTimeout(timer);
      }
    });
  });
}());
