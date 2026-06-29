(function () {
  'use strict';

  const panels = new WeakSet();
  const pollTimers = new WeakMap();

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
    initialisePictureEditors(root);
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

  function pictureEditorCssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(String(value));
    }

    return String(value).replace(/["\\]/g, '\\$&');
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

  function initialisePictureEditors(root = document) {
      const editors = [];
      if (root instanceof HTMLElement && root.matches('[data-picture-editor="true"]')) {
          editors.push(root);
      }
      if (root.querySelectorAll) {
          root.querySelectorAll('[data-picture-editor="true"]').forEach((node) => {
              editors.push(node);
          });
      }

      editors.forEach((editor) => {
          if (!(editor instanceof HTMLElement) || editor.dataset.pictureEditorBound === '1') {
              return;
          }

          editor.dataset.pictureEditorBound = '1';
          const stage = editor.querySelector('[data-picture-editor-stage]');
          const cropNode = editor.querySelector('[data-picture-editor-crop]');
          const statusNode = editor.querySelector('[data-picture-editor-status]');
          const displayState = editor.querySelector('[data-picture-editor-display-state]');
          const cropReadout = editor.querySelector('[data-picture-editor-crop-readout]');
          const cropState = editor.querySelector('[data-picture-editor-crop-state]');
          const revertButton = editor.querySelector('[data-picture-editor-revert]');
          const saveButton = editor.querySelector('[data-picture-editor-save]');
          const profileState = editor.querySelector('[data-picture-editor-profile-state]');
          const baselineSelect = editor.querySelector('[data-picture-editor-baseline-profile]');
          const profileUrl = String(editor.dataset.profileUrl || '').trim();
          const finalUrl = String(editor.dataset.finalUrl || '').trim();
          const profileStatusUrl = String(editor.dataset.profileStatusUrl || '').trim();
          const initialPreviewStatusUrl = String(editor.dataset.previewStatusUrl || '').trim();
          const sourceWidth = Math.max(1, Number.parseInt(String(editor.dataset.sourceWidth || '1'), 10));
          const sourceHeight = Math.max(1, Number.parseInt(String(editor.dataset.sourceHeight || '1'), 10));
          let imageNode = editor.querySelector('[data-picture-editor-image]');
          let requestSequence = 0;
          let submitTimer = null;
          let pollTimer = null;
          let finalPollTimer = null;
          let finalSaveSequence = 0;
          let baselinePollTimer = null;
          let dragState = null;
          let displayedPreviewStage = String(editor.dataset.previewType || '').trim();
          let displayedImageType = displayedPreviewStage;
          let baselineReady = editor.dataset.baselineReady === '1';
          let previewDisplayReady = false;

          if (!(stage instanceof HTMLElement) || !(cropNode instanceof HTMLElement) || profileUrl === '') {
              return;
          }

          const defaultSettings = () => ({
              crop: { enabled: true, x: 0, y: 0, width: sourceWidth, height: sourceHeight },
              exposure: { enabled: true, black: 63, lightness: 0, contrast: 26, saturation: 0 },
              white_balance: { enabled: true, setting: 'Custom', temperature: 5324, green: 0.846 },
              shadows_highlights: {
                  enabled: true,
                  highlights: 30,
                  highlight_tonal_width: 80,
                  shadows: 30,
                  shadow_tonal_width: 80,
                  radius: 40,
                  lab: true,
                  local_contrast: 0,
              },
              rotation: { enabled: false, degree: 0 },
              perspective: { enabled: false, method: 'simple', horizontal: 0, vertical: 0 },
          });
          let settings = defaultSettings();
          let baselineSettings = typeof structuredClone === 'function' ? structuredClone(settings) : JSON.parse(JSON.stringify(settings));

          try {
              const parsed = JSON.parse(String(editor.dataset.settings || '{}'));
              settings = normaliseSettings(parsed);
              baselineSettings = cloneSettings(settings);
          } catch (error) {
              // Use neutral defaults if the embedded state is malformed.
          }

          editor.querySelectorAll('[data-picture-editor-panel]').forEach((panel) => {
              if (!(panel instanceof HTMLDetailsElement)) {
                  return;
              }
              panel.addEventListener('toggle', () => {
                  if (!panel.open) {
                      return;
                  }
                  editor.querySelectorAll('[data-picture-editor-panel]').forEach((other) => {
                      if (other instanceof HTMLDetailsElement && other !== panel) {
                          other.open = false;
                      }
                  });
              });
          });

          function clampNumber(value, min, max) {
              const number = Number(value);

              if (!Number.isFinite(number)) {
                  return min;
              }

              return Math.max(min, Math.min(max, number));
          }

          function normaliseCrop(crop) {
              const x = Math.round(clampNumber(crop.x, 0, sourceWidth - 1));
              const y = Math.round(clampNumber(crop.y, 0, sourceHeight - 1));
              const width = Math.round(clampNumber(crop.width, 1, sourceWidth - x));
              const height = Math.round(clampNumber(crop.height, 1, sourceHeight - y));

              return { enabled: crop.enabled !== false, x, y, width, height };
          }

          function normaliseSettings(value) {
              const next = defaultSettings();
              next.crop = normaliseCrop({ ...next.crop, ...(value?.crop || {}) });
              next.exposure = {
                  enabled: value?.exposure?.enabled !== false,
                  black: clampNumber(value?.exposure?.black ?? next.exposure.black, -100, 100),
                  lightness: clampNumber(value?.exposure?.lightness ?? next.exposure.lightness, -100, 100),
                  contrast: clampNumber(value?.exposure?.contrast ?? next.exposure.contrast, -100, 100),
                  saturation: clampNumber(value?.exposure?.saturation ?? next.exposure.saturation, -100, 100),
              };
              next.white_balance = {
                  enabled: value?.white_balance?.enabled !== false,
                  setting: String(value?.white_balance?.setting || 'Custom'),
                  temperature: clampNumber(value?.white_balance?.temperature ?? next.white_balance.temperature, 1500, 60000),
                  green: clampNumber(value?.white_balance?.green ?? next.white_balance.green, 0.02, 5),
              };
              next.shadows_highlights = {
                  enabled: value?.shadows_highlights?.enabled !== false,
                  highlights: clampNumber(value?.shadows_highlights?.highlights ?? next.shadows_highlights.highlights, 0, 100),
                  highlight_tonal_width: clampNumber(value?.shadows_highlights?.highlight_tonal_width ?? next.shadows_highlights.highlight_tonal_width, 0, 100),
                  shadows: clampNumber(value?.shadows_highlights?.shadows ?? next.shadows_highlights.shadows, 0, 100),
                  shadow_tonal_width: clampNumber(value?.shadows_highlights?.shadow_tonal_width ?? next.shadows_highlights.shadow_tonal_width, 0, 100),
                  radius: clampNumber(value?.shadows_highlights?.radius ?? next.shadows_highlights.radius, 1, 100),
                  lab: value?.shadows_highlights?.lab !== false,
                  local_contrast: clampNumber(value?.shadows_highlights?.local_contrast ?? next.shadows_highlights.local_contrast, 0, 100),
              };
              next.rotation = {
                  enabled: value?.rotation?.enabled === true,
                  degree: clampNumber(value?.rotation?.degree ?? next.rotation.degree, -45, 45),
              };
              next.perspective = {
                  enabled: value?.perspective?.enabled === true,
                  method: 'simple',
                  horizontal: clampNumber(value?.perspective?.horizontal ?? next.perspective.horizontal, -100, 100),
                  vertical: clampNumber(value?.perspective?.vertical ?? next.perspective.vertical, -100, 100),
              };
              return next;
          }

          function cloneSettings(value) {
              return JSON.parse(JSON.stringify(value));
          }

          function getSetting(path) {
              return path.split('.').reduce((current, part) => current?.[part], settings);
          }

          function setSetting(path, value) {
              const parts = path.split('.');
              let current = settings;
              while (parts.length > 1) {
                  current = current[parts.shift()];
              }
              current[parts[0]] = value;
          }

          function cropIsInteractive() {
              return baselineReady && previewDisplayReady && settings.crop.enabled;
          }

          function editorCanEdit() {
              return baselineReady && previewDisplayReady;
          }

          function normaliseStatusState(state) {
              const value = String(state || '').trim().toLowerCase();
              if (['ready', 'succeeded', 'loaded'].includes(value)) {
                  return 'ready';
              }
              if (['failed', 'cancelled', 'timeout', 'timed_out'].includes(value)) {
                  return 'failed';
              }
              if (['processing', 'rendering', 'saving', 'loading'].includes(value)) {
                  return 'processing';
              }
              if ([
                  'queued',
                  'pending',
                  'profile_pending',
                  'preview_pending',
                  'obsolete',
                  'superseded',
                  'waiting',
              ].includes(value)) {
                  return 'queued';
              }

              return value;
          }

          function setStatus(message, state = '') {
              if (!(statusNode instanceof HTMLElement)) {
                  return;
              }

              statusNode.textContent = `Photo: ${message}`;
              statusNode.dataset.pictureEditorState = normaliseStatusState(state);
          }

          function normaliseDisplayType(type) {
              const value = String(type || '').trim().toLowerCase();
              return ['preview', 'thumbnail'].includes(value) ? value : '';
          }

          function setDisplayType(type) {
              displayedImageType = normaliseDisplayType(type);
              if (!(displayState instanceof HTMLElement)) {
                  return;
              }

              displayState.textContent = `Displaying: ${displayedImageType !== '' ? displayedImageType : 'none'}`;
              displayState.dataset.pictureEditorDisplayType = displayedImageType;
          }

          function displayBox() {
              const stageRect = stage.getBoundingClientRect();

              if (imageNode instanceof HTMLImageElement && imageNode.isConnected && imageNode.naturalWidth > 0) {
                  const imageRect = imageNode.getBoundingClientRect();
                  if (imageRect.width > 0 && imageRect.height > 0) {
                      return {
                          left: imageRect.left,
                          top: imageRect.top,
                          width: imageRect.width,
                          height: imageRect.height,
                          stageLeft: stageRect.left,
                          stageTop: stageRect.top,
                      };
                  }
              }

              return {
                  left: stageRect.left,
                  top: stageRect.top,
                  width: Math.max(1, stageRect.width),
                  height: Math.max(1, stageRect.height),
                  stageLeft: stageRect.left,
                  stageTop: stageRect.top,
              };
          }

          function renderCrop() {
              settings.crop = normaliseCrop(settings.crop);
              const interactive = cropIsInteractive();
              cropNode.hidden = !interactive;
              cropNode.dataset.pictureEditorDisabled = interactive ? '0' : '1';
              if (cropState instanceof HTMLElement) {
                  cropState.textContent = baselineReady
                      ? (previewDisplayReady ? 'Crop follows preview images.' : 'Crop waiting for preview image.')
                      : 'Crop waiting for baseline profile.';
              }
              const box = displayBox();
              const left = (settings.crop.x / sourceWidth) * box.width;
              const top = (settings.crop.y / sourceHeight) * box.height;
              const width = (settings.crop.width / sourceWidth) * box.width;
              const height = (settings.crop.height / sourceHeight) * box.height;

              cropNode.style.left = `${String(Math.round((box.left - box.stageLeft) + left))}px`;
              cropNode.style.top = `${String(Math.round((box.top - box.stageTop) + top))}px`;
              cropNode.style.width = `${String(Math.max(12, Math.round(width)))}px`;
              cropNode.style.height = `${String(Math.max(12, Math.round(height)))}px`;

              if (cropReadout instanceof HTMLElement) {
                  cropReadout.textContent = `${String(settings.crop.x)}, ${String(settings.crop.y)} ${String(settings.crop.width)} x ${String(settings.crop.height)}`;
              }
          }

          function pointInSource(event) {
              const box = displayBox();
              const x = ((event.clientX - box.left) / box.width) * sourceWidth;
              const y = ((event.clientY - box.top) / box.height) * sourceHeight;

              return {
                  x: clampNumber(x, 0, sourceWidth),
                  y: clampNumber(y, 0, sourceHeight),
              };
          }

          function payload() {
              return {
                  photo_id: Number.parseInt(String(editor.dataset.photoId || '0'), 10),
                  csrf_token: String(editor.dataset.csrfToken || ''),
                  crop: { ...settings.crop },
                  exposure: { ...settings.exposure },
                  white_balance: { ...settings.white_balance },
                  shadows_highlights: { ...settings.shadows_highlights },
                  rotation: { ...settings.rotation },
                  perspective: { ...settings.perspective },
              };
          }

          function syncControls() {
              editor.querySelectorAll('[data-picture-editor-field]').forEach((field) => {
                  if (!(field instanceof HTMLInputElement)) {
                      return;
                  }
                  const key = String(field.dataset.pictureEditorField || '');
                  const value = String(getSetting(key) ?? 0);
                  const number = editor.querySelector(`[data-picture-editor-number="${pictureEditorCssEscape(key)}"]`);

                  field.value = value;
                  if (number instanceof HTMLInputElement) {
                      number.value = value;
                  }
              });
              editor.querySelectorAll('[data-picture-editor-check]').forEach((field) => {
                  if (field instanceof HTMLInputElement) {
                      field.checked = Boolean(getSetting(String(field.dataset.pictureEditorCheck || '')));
                  }
              });
          }

          function setEditorEnabled(enabled) {
              editor.querySelectorAll('[data-picture-editor-field], [data-picture-editor-number], [data-picture-editor-check], [data-picture-editor-revert], [data-picture-editor-save]').forEach((field) => {
                  if (field instanceof HTMLInputElement || field instanceof HTMLButtonElement) {
                      field.disabled = !enabled;
                  }
              });
              renderCrop();
          }

          function setProfileReady(ready, failed = false) {
              if (!(profileState instanceof HTMLElement)) {
                  return;
              }
              profileState.textContent = `Profile: ${failed ? 'Failed' : (ready ? 'Ready' : 'Preparing')}`;
              profileState.dataset.pictureEditorProfileReady = ready ? '1' : '0';
          }

          function clearPoll() {
              if (pollTimer !== null) {
                  window.clearTimeout(pollTimer);
                  pollTimer = null;
              }
          }

          function clearFinalPoll() {
              if (finalPollTimer !== null) {
                  window.clearTimeout(finalPollTimer);
                  finalPollTimer = null;
              }
          }

          function clearBaselinePoll() {
              if (baselinePollTimer !== null) {
                  window.clearTimeout(baselinePollTimer);
                  baselinePollTimer = null;
              }
          }

          function clearSubmitTimer() {
              if (submitTimer !== null) {
                  window.clearTimeout(submitTimer);
                  submitTimer = null;
              }
          }

          function clearEditorTimers() {
              clearSubmitTimer();
              clearPoll();
              clearFinalPoll();
              clearBaselinePoll();
          }

          function scheduleSubmit() {
              if (!editorCanEdit()) {
                  return;
              }
              clearSubmitTimer();

              setStatus('Queued', 'queued');
              submitTimer = window.setTimeout(() => {
                  submitTimer = null;
                  submitPreview();
              }, 500);
          }

          async function submitPreview() {
              const sequence = ++requestSequence;
              displayedPreviewStage = '';
              previewDisplayReady = false;
              clearPoll();
              setEditorEnabled(false);
              setStatus('Rendering', 'processing');

              try {
                  const response = await fetchPictureEditorJson(profileUrl, {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify(payload()),
                  });

                  if (sequence !== requestSequence) {
                      return;
                  }

                  if (!response || response.success === false || !response.status_url) {
                      setStatus('Failed', 'failed');
                      return;
                  }

                  pollPreviewStatus(String(response.status_url), sequence, 0, Date.now());
              } catch (error) {
                  if (sequence === requestSequence) {
                      setStatus('Failed', 'failed');
                  }
                  console.error(error);
              }
          }

          function previewUrlFromResponse(response) {
              const preview = response?.preview || {};
              return String(response?.preview_url || preview?.preview_url || preview?.display_url || '').trim();
          }

          function previewStatusUrlFromResponse(response) {
              const preview = response?.preview || {};
              return String(response?.status_url || response?.preview_status_url || preview?.status_url || '').trim();
          }

          async function pollPreviewStatus(statusUrl, sequence, attempt, startedAt) {
              if (sequence !== requestSequence || !editor.isConnected) {
                  return;
              }

              if ((Date.now() - startedAt) > 120000) {
                  setStatus('Timed out', 'timeout');
                  return;
              }

              try {
                  const response = await fetchPictureEditorJson(statusUrl);

                  if (sequence !== requestSequence) {
                      return;
                  }

                  const state = String(response?.status || 'queued').toLowerCase();
                  const previewUrl = previewUrlFromResponse(response);
                  if ((state === 'succeeded' || response?.preview?.ready === true) && previewUrl !== '') {
                      swapPreviewImage(previewUrl, 'preview');
                      setStatus('Loading', 'processing');
                      return;
                  }

                  if (state === 'obsolete' || response?.superseded === true) {
                      const replacementStatusUrl = previewStatusUrlFromResponse(response);
                      setStatus('Queued', 'queued');
                      if (previewUrl !== '') {
                          swapPreviewImage(previewUrl, 'preview');
                          setStatus('Loading', 'processing');
                          return;
                      }
                      if (replacementStatusUrl !== '' && replacementStatusUrl !== statusUrl) {
                          pollPreviewStatus(replacementStatusUrl, sequence, 0, Date.now());
                          return;
                      }
                      if (profileStatusUrl !== '') {
                          pollProfileStatus(0, true, sequence);
                          return;
                      }
                  }

                  if (state === 'failed' || state === 'cancelled') {
                      setStatus(state === 'cancelled' ? 'Cancelled' : 'Failed', 'failed');
                      return;
                  }

                  setStatus(state === 'processing' ? 'Rendering' : 'Queued', state);
                  const delay = attempt < 5 ? 750 : 1500;
                  pollTimer = window.setTimeout(() => {
                      pollPreviewStatus(statusUrl, sequence, attempt + 1, startedAt);
                  }, delay);
              } catch (error) {
                  if (sequence === requestSequence) {
                      setStatus('Failed', 'failed');
                  }
                  console.error(error);
              }
          }

          function swapPreviewImage(url, stageType = '') {
              const emptyNode = editor.querySelector('[data-picture-editor-empty]');
              if (!(imageNode instanceof HTMLImageElement)) {
                  imageNode = document.createElement('img');
                  imageNode.setAttribute('alt', 'Photo preview');
                  imageNode.dataset.pictureEditorImage = 'true';
                  imageNode.addEventListener('load', handleImageLoad);
                  stage.insertBefore(imageNode, cropNode);
              }

              if (emptyNode instanceof HTMLElement) {
                  emptyNode.remove();
              }

              imageNode.src = url;
              if (stageType !== '') {
                  displayedPreviewStage = stageType;
                  previewDisplayReady = false;
                  setDisplayType(stageType);
                  editor.dataset.previewType = stageType;
                  editor.dataset.previewReady = stageType === 'preview' ? '1' : '0';
                  renderCrop();
              }
              if (imageNode.complete && imageNode.naturalWidth > 0) {
                  handleImageLoad();
              }
          }

          function handleImageLoad() {
              if (displayedPreviewStage === 'preview') {
                  previewDisplayReady = true;
                  setStatus('Ready', 'ready');
                  setEditorEnabled(editorCanEdit());
                  return;
              }

              previewDisplayReady = false;
              setEditorEnabled(false);
              renderCrop();
          }

          async function submitFinal() {
              if (!baselineReady || finalUrl === '') {
                  return;
              }

              const sequence = ++finalSaveSequence;
              clearFinalPoll();
              setStatus('Saving', 'processing');

              try {
                  const response = await fetchPictureEditorJson(finalUrl, {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify(payload()),
                  });

                  if (sequence !== finalSaveSequence) {
                      return;
                  }

                  if (!response || response.success === false || !response.status_url) {
                      setStatus('Final failed', 'failed');
                      return;
                  }

                  pollFinalStatus(String(response.status_url), sequence, 0, Date.now());
              } catch (error) {
                  if (sequence === finalSaveSequence) {
                      setStatus('Final failed', 'failed');
                  }
                  console.error(error);
              }
          }

          async function pollFinalStatus(statusUrl, sequence, attempt, startedAt) {
              if (sequence !== finalSaveSequence || !editor.isConnected) {
                  return;
              }

              if ((Date.now() - startedAt) > 120000) {
                  setStatus('Final timed out', 'failed');
                  return;
              }

              try {
                  const response = await fetchPictureEditorJson(statusUrl);

                  if (sequence !== finalSaveSequence) {
                      return;
                  }

                  const state = String(response?.status || 'queued').toLowerCase();
                  if (state === 'succeeded') {
                      setStatus('Final ready', 'ready');
                      return;
                  }

                  if (state === 'obsolete' || response?.superseded === true) {
                      const replacementStatusUrl = String(response?.status_url || '').trim();
                      if (response?.ready === true) {
                          setStatus('Final ready', 'ready');
                          return;
                      }
                      setStatus('Final queued', 'queued');
                      if (replacementStatusUrl !== '' && replacementStatusUrl !== statusUrl) {
                          pollFinalStatus(replacementStatusUrl, sequence, 0, Date.now());
                          return;
                      }
                  }

                  if (state === 'failed' || state === 'cancelled') {
                      setStatus(state === 'cancelled' ? 'Final cancelled' : 'Final failed', 'failed');
                      return;
                  }

                  setStatus(state === 'processing' ? 'Saving' : 'Final queued', state);
                  const delay = attempt < 5 ? 1000 : 2500;
                  finalPollTimer = window.setTimeout(() => {
                      pollFinalStatus(statusUrl, sequence, attempt + 1, startedAt);
                  }, delay);
              } catch (error) {
                  if (sequence === finalSaveSequence) {
                      setStatus('Final failed', 'failed');
                  }
                  console.error(error);
              }
          }

          function revertToBaseline() {
              settings = cloneSettings(baselineSettings);
              settings.crop = normaliseCrop(settings.crop);
              syncControls();
              renderCrop();
              scheduleSubmit();
          }

          async function changeBaselineProfile() {
              if (!(baselineSelect instanceof HTMLSelectElement)) {
                  return;
              }

              const previousValue = String(baselineSelect.dataset.previousValue || baselineSelect.defaultValue || '0');
              const form = new FormData();
              form.set('action', 'baseline_profile');
              form.set('photo_id', String(editor.dataset.photoId || '0'));
              form.set('rawtherapee_profile_id', baselineSelect.value);
              const csrfToken = String(editor.dataset.csrfToken || '').trim();
              if (csrfToken !== '') {
                  form.set('csrf_token', csrfToken);
              }

              const sequence = ++requestSequence;
              ++finalSaveSequence;
              clearEditorTimers();
              baselineReady = false;
              previewDisplayReady = false;
              editor.dataset.baselineReady = '0';
              editor.dataset.previewReady = '0';
              baselineSelect.disabled = true;
              setEditorEnabled(false);
              setProfileReady(false);
              setStatus('Queued', 'queued');
              renderCrop();

              try {
                  const response = await fetchPictureEditorJson(profileUrl, {
                      method: 'POST',
                      body: form,
                  });

                  if (sequence !== requestSequence) {
                      return;
                  }

                  baselineSelect.dataset.previousValue = baselineSelect.value;
                  setProfileReady(false);
                  setStatus('Queued', 'queued');

                  if (profileStatusUrl !== '') {
                      pollProfileStatus(0, true, sequence);
                      return;
                  }

                  if (response?.baseline?.ready && response?.settings) {
                      baselineReady = true;
                      editor.dataset.baselineReady = '1';
                      settings = normaliseSettings(response.settings);
                      baselineSettings = cloneSettings(settings);
                      syncControls();
                      setProfileReady(true);
                      const previewUrl = previewUrlFromResponse(response);
                      if (previewUrl !== '') {
                          swapPreviewImage(previewUrl, 'preview');
                      }
                  }
              } catch (error) {
                  if (sequence === requestSequence) {
                      baselineSelect.value = previousValue;
                      setProfileReady(false, true);
                      setStatus('Failed', 'failed');
                      setEditorEnabled(editorCanEdit());
                  }
                  console.error(error);
              } finally {
                  if (sequence === requestSequence) {
                      baselineSelect.disabled = false;
                  }
              }
          }

          editor.querySelectorAll('[data-picture-editor-field]').forEach((field) => {
              if (!(field instanceof HTMLInputElement)) {
                  return;
              }

              const key = String(field.dataset.pictureEditorField || '');
              const number = editor.querySelector(`[data-picture-editor-number="${pictureEditorCssEscape(key)}"]`);
              const sync = (value) => {
                  const next = clampNumber(value, Number(field.min), Number(field.max));
                  setSetting(key, next);
                  field.value = String(next);
                  if (number instanceof HTMLInputElement) {
                      number.value = String(next);
                  }
                  scheduleSubmit();
              };

              field.addEventListener('input', () => sync(field.value));
              if (number instanceof HTMLInputElement) {
                  number.addEventListener('input', () => sync(number.value));
              }
          });

          editor.querySelectorAll('[data-picture-editor-check]').forEach((field) => {
              if (!(field instanceof HTMLInputElement)) {
                  return;
              }
              field.addEventListener('change', () => {
                  setSetting(String(field.dataset.pictureEditorCheck || ''), field.checked);
                  renderCrop();
                  scheduleSubmit();
              });
          });

          cropNode.addEventListener('pointerdown', (event) => {
              if (!cropIsInteractive()) {
                  return;
              }
              if (!(event.target instanceof HTMLElement)) {
                  return;
              }

              event.preventDefault();
              const point = pointInSource(event);
              const handle = String(event.target.dataset.pictureEditorHandle || '');
              dragState = {
                  mode: handle !== '' ? handle : 'move',
                  pointerId: event.pointerId,
                  startPoint: point,
                  startCrop: { ...settings.crop },
              };
              cropNode.setPointerCapture(event.pointerId);
          });

          stage.addEventListener('pointerdown', (event) => {
              if (!cropIsInteractive()) {
                  return;
              }
              if (event.target === cropNode || (event.target instanceof HTMLElement && event.target.closest('[data-picture-editor-crop]'))) {
                  return;
              }

              event.preventDefault();
              const point = pointInSource(event);
              settings.crop = normaliseCrop({
                  x: point.x,
                  y: point.y,
                  width: Math.max(1, sourceWidth * 0.05),
                  height: Math.max(1, sourceHeight * 0.05),
              });
              dragState = {
                  mode: 'draw',
                  pointerId: event.pointerId,
                  startPoint: point,
                  startCrop: { ...settings.crop },
              };
              stage.setPointerCapture(event.pointerId);
              renderCrop();
          });

          const updateDrag = (event) => {
              if (!cropIsInteractive() || !dragState || dragState.pointerId !== event.pointerId) {
                  return;
              }

              const point = pointInSource(event);
              const start = dragState.startCrop;
              const minSize = Math.max(16, Math.round(Math.min(sourceWidth, sourceHeight) * 0.01));

              if (dragState.mode === 'move') {
                  const deltaX = Math.round(point.x - dragState.startPoint.x);
                  const deltaY = Math.round(point.y - dragState.startPoint.y);
                  settings.crop = normaliseCrop({
                      ...start,
                      x: clampNumber(start.x + deltaX, 0, sourceWidth - start.width),
                      y: clampNumber(start.y + deltaY, 0, sourceHeight - start.height),
                  });
              } else if (dragState.mode === 'draw') {
                  const left = Math.min(dragState.startPoint.x, point.x);
                  const top = Math.min(dragState.startPoint.y, point.y);
                  settings.crop = normaliseCrop({
                      x: left,
                      y: top,
                      width: Math.max(minSize, Math.abs(point.x - dragState.startPoint.x)),
                      height: Math.max(minSize, Math.abs(point.y - dragState.startPoint.y)),
                  });
              } else {
                  let left = start.x;
                  let top = start.y;
                  let right = start.x + start.width;
                  let bottom = start.y + start.height;

                  if (dragState.mode.includes('n')) {
                      top = clampNumber(point.y, 0, bottom - minSize);
                  }
                  if (dragState.mode.includes('s')) {
                      bottom = clampNumber(point.y, top + minSize, sourceHeight);
                  }
                  if (dragState.mode.includes('w')) {
                      left = clampNumber(point.x, 0, right - minSize);
                  }
                  if (dragState.mode.includes('e')) {
                      right = clampNumber(point.x, left + minSize, sourceWidth);
                  }

                  settings.crop = normaliseCrop({
                      x: left,
                      y: top,
                      width: right - left,
                      height: bottom - top,
                  });
              }

              renderCrop();
          };

          const finishDrag = (event) => {
              if (!dragState || dragState.pointerId !== event.pointerId) {
                  return;
              }

              dragState = null;
              scheduleSubmit();
          };

          stage.addEventListener('pointermove', updateDrag);
          cropNode.addEventListener('pointermove', updateDrag);
          stage.addEventListener('pointerup', finishDrag);
          cropNode.addEventListener('pointerup', finishDrag);
          stage.addEventListener('pointercancel', finishDrag);
          cropNode.addEventListener('pointercancel', finishDrag);

          if (imageNode instanceof HTMLImageElement) {
              imageNode.addEventListener('load', handleImageLoad);
          }
          if (revertButton instanceof HTMLButtonElement) {
              revertButton.addEventListener('click', revertToBaseline);
          }
          if (saveButton instanceof HTMLButtonElement) {
              saveButton.addEventListener('click', submitFinal);
          }
          if (baselineSelect instanceof HTMLSelectElement) {
              baselineSelect.addEventListener('change', changeBaselineProfile);
          }
          window.addEventListener('resize', renderCrop);
          window.addEventListener('pagehide', clearEditorTimers, { once: true });
          syncControls();
          setProfileReady(baselineReady);
          setEditorEnabled(false);
          if (imageNode instanceof HTMLImageElement && imageNode.complete && imageNode.naturalWidth > 0) {
              handleImageLoad();
          }
          if (!baselineReady && profileStatusUrl !== '') {
              pollProfileStatus(0);
          } else if (!previewDisplayReady && initialPreviewStatusUrl !== '') {
              const sequence = ++requestSequence;
              pollPreviewStatus(initialPreviewStatusUrl, sequence, 0, Date.now());
          }
          renderCrop();

          async function pollProfileStatus(attempt, force = false, sequence = requestSequence) {
              if (sequence !== requestSequence || (baselineReady && !force) || !editor.isConnected) {
                  return;
              }
              try {
                  const response = await fetchPictureEditorJson(profileStatusUrl);
                  if (sequence !== requestSequence) {
                      return;
                  }
                  const status = String(response?.baseline?.status || '').toLowerCase();
                  if (response?.baseline?.ready && response?.settings) {
                      baselineReady = true;
                      editor.dataset.baselineReady = '1';
                      settings = normaliseSettings(response.settings);
                      baselineSettings = cloneSettings(settings);
                      syncControls();
                      setProfileReady(true);
                      setEditorEnabled(editorCanEdit());
                      const preview = response?.preview || {};
                      const statusUrl = String(preview?.status_url || response?.preview_status_url || '');
                      const previewUrl = previewUrlFromResponse(response);
                      if ((preview?.ready || response?.preview_ready) && previewUrl !== '') {
                          swapPreviewImage(previewUrl, 'preview');
                          return;
                      }
                      if (statusUrl !== '') {
                          const sequence = ++requestSequence;
                          setStatus(String(preview?.status || '').toLowerCase() === 'processing' ? 'Rendering' : 'Queued', String(preview?.status || 'queued'));
                          pollPreviewStatus(statusUrl, sequence, 0, Date.now());
                          return;
                      }
                      setStatus('Queued', 'queued');
                      return;
                  }
                  if (profileState instanceof HTMLElement) {
                      setProfileReady(false, status === 'failed');
                  }
                  setStatus(status === 'failed' ? 'Failed' : 'Queued', status === 'failed' ? 'failed' : 'queued');
                  if (status === 'failed') {
                      return;
                  }
                  const delay = attempt < 5 ? 1000 : 2500;
                  baselinePollTimer = window.setTimeout(() => pollProfileStatus(attempt + 1, force, sequence), delay);
              } catch (error) {
                  const delay = attempt < 5 ? 1500 : 3000;
                  baselinePollTimer = window.setTimeout(() => pollProfileStatus(attempt + 1, force, sequence), delay);
                  console.error(error);
              }
          }
      });
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
    if (target instanceof HTMLSelectElement && target.matches('[data-rawtherapee-default-profile-id]')) {
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
