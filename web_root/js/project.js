(function () {
  'use strict';

  const panels = new WeakSet();
  const pollTimers = new WeakMap();
  const projectAjaxNonceBootstrapId = 'ajax-security-bootstrap';
  const projectAjaxNonceState = {
    available: [],
    inFlight: new Set(),
  };
  const galleryAutoRefreshStorageKey = 'gallery:auto-refresh:browse_gallery';
  const galleryAutoScrollStorageKey = 'gallery:auto-scroll:browse_gallery';
  const galleryAutoRefreshIntervalMs = 5000;
  const galleryCardRefreshIntervalMs = 30000;
  const galleryViewerPrefetchDelayMs = 750;
  const galleryViewerPrefetchMinimumBytesPerSecond = 5000000;
  const galleryViewerPrefetchMinimumSampleBytes = 65536;
  const galleryViewerPrefetchMinimumSampleDurationMs = 50;
  const galleryViewerPrefetchCompletedUrls = new Set();
  const galleryViewerPrefetchState = {
    timer: null,
    link: null,
    url: '',
    controller: null,
  };
  let activeGalleryEventCreateButton = null;

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

  function projectStorageAvailable(storageName) {
    try {
      const storage = window[storageName];
      const probe = '__swallowtail_probe__';
      if (!storage) {
        return false;
      }
      storage.setItem(probe, '1');
      storage.removeItem(probe);
      return true;
    } catch (error) {
      return false;
    }
  }

  function projectLoadAjaxNonceBootstrap() {
    const node = document.getElementById(projectAjaxNonceBootstrapId);
    if (!(node instanceof HTMLElement)) {
      return;
    }

    try {
      const payload = JSON.parse(node.dataset.noncePayload || '{}');
      projectAjaxNonceState.available = Array.isArray(payload?.nonce_pool)
        ? payload.nonce_pool
          .map((nonce) => String(nonce || '').trim())
          .filter((nonce) => nonce !== '')
        : [];
      projectAjaxNonceState.inFlight.clear();
    } catch (error) {
      console.error(error);
    }
  }

  function projectReserveAjaxNonce() {
    const nonce = String(projectAjaxNonceState.available.pop() || '').trim();
    if (nonce === '') {
      return null;
    }
    projectAjaxNonceState.inFlight.add(nonce);
    return nonce;
  }

  function projectRestoreAjaxNonce(nonce) {
    const value = String(nonce || '').trim();
    if (value === '') {
      return;
    }
    projectAjaxNonceState.inFlight.delete(value);
    if (!projectAjaxNonceState.available.includes(value)) {
      projectAjaxNonceState.available.push(value);
    }
  }

  function projectCompleteAjaxNonce(usedNonce, replacementNonce) {
    const used = String(usedNonce || '').trim();
    if (used !== '') {
      projectAjaxNonceState.inFlight.delete(used);
    }

    const replacement = String(replacementNonce || '').trim();
    if (replacement !== ''
      && !projectAjaxNonceState.available.includes(replacement)
      && !projectAjaxNonceState.inFlight.has(replacement)
    ) {
      projectAjaxNonceState.available.push(replacement);
    }
  }

  function projectCreateAjaxError(status, payload = null) {
    const message = payload && Array.isArray(payload.errors) && payload.errors.length > 0
      ? payload.errors.join(' ')
      : `Request failed with status ${String(status)}`;
    const error = new Error(message);
    error.status = status;
    error.payload = payload;
    return error;
  }

  function projectFormRequestUrl(form) {
    if (!(form instanceof HTMLFormElement)) {
      return window.location.href;
    }

    const action = String(form.getAttribute('action') || '').trim();
    return action === '' ? window.location.href : action;
  }

  function projectFormDataToJsonPayload(formData) {
    const payload = {};
    if (!(formData instanceof FormData)) {
      return payload;
    }

    formData.forEach((value, key) => {
      const normalisedKey = key.endsWith('[]') ? key.slice(0, -2) : key;
      if (Object.prototype.hasOwnProperty.call(payload, normalisedKey)) {
        if (Array.isArray(payload[normalisedKey])) {
          payload[normalisedKey].push(value);
          return;
        }
        payload[normalisedKey] = [payload[normalisedKey], value];
        return;
      }
      payload[normalisedKey] = key.endsWith('[]') ? [value] : value;
    });

    return payload;
  }

  function projectCollectSiteContextSelections() {
    const selections = [];
    document.querySelectorAll('[data-site-context-key]').forEach((node) => {
      if (!(node instanceof HTMLSelectElement || node instanceof HTMLInputElement)) {
        return;
      }

      const key = String(node.dataset.siteContextKey || '').trim();
      if (key === '') {
        return;
      }

      selections.push({
        key,
        value: String(node.value || ''),
        inputName: String(node.dataset.siteContextInputName || '').trim(),
      });
    });

    return selections;
  }

  function projectFormHasEnabledNamedField(form, name) {
    if (!(form instanceof HTMLFormElement) || name === '') {
      return false;
    }

    return Array.from(form.elements).some((element) => (
      element instanceof HTMLElement
      && element.getAttribute('name') === name
      && !(element instanceof HTMLInputElement && element.disabled)
      && !(element instanceof HTMLSelectElement && element.disabled)
      && !(element instanceof HTMLTextAreaElement && element.disabled)
    ));
  }

  function projectAppendSiteContextToFormData(formData, form = null) {
    if (!(formData instanceof FormData)) {
      return;
    }

    formData.delete('site_context_keys[]');
    formData.delete('site_context_keys');
    formData.delete('site_context_values[]');
    formData.delete('site_context_values');

    projectCollectSiteContextSelections().forEach((selection) => {
      formData.append('site_context_keys[]', selection.key);
      formData.append('site_context_values[]', selection.value);
      if (selection.inputName !== '' && !projectFormHasEnabledNamedField(form, selection.inputName)) {
        formData.set(selection.inputName, selection.value);
      }
    });
  }

  function projectAppendSiteContextToPayload(payload) {
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
      return;
    }

    delete payload.site_context_keys;
    delete payload.site_context_values;
    payload.site_context_keys = [];
    payload.site_context_values = [];

    projectCollectSiteContextSelections().forEach((selection) => {
      payload.site_context_keys.push(selection.key);
      payload.site_context_values.push(selection.value);
      if (selection.inputName !== '' && !Object.prototype.hasOwnProperty.call(payload, selection.inputName)) {
        payload[selection.inputName] = selection.value;
      }
    });
  }

  async function projectFetchJson(url, options = {}) {
    const headers = {
      Accept: 'application/json',
      ...(options.headers || {}),
    };
    const response = await fetch(url, {
      ...options,
      credentials: 'same-origin',
      headers,
    });
    const text = await response.text();
    let payload = null;

    if (text.trim() !== '') {
      try {
        payload = JSON.parse(text);
      } catch (error) {
        throw projectCreateAjaxError(response.status, { errors: ['The server returned an invalid JSON response.'] });
      }
    }

    if (!response.ok) {
      throw projectCreateAjaxError(response.status, payload);
    }

    return payload || {};
  }

  function projectSendXhr(url, options = {}) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open(String(options.method || 'GET').toUpperCase(), url);
      xhr.responseType = 'text';
      xhr.withCredentials = true;
      xhr.setRequestHeader('Accept', 'application/json');

      Object.entries(options.headers || {}).forEach(([name, value]) => {
        xhr.setRequestHeader(name, value);
      });

      if (xhr.upload && typeof options.onUploadProgress === 'function') {
        xhr.upload.addEventListener('progress', options.onUploadProgress);
      }

      xhr.addEventListener('load', () => {
        let payload = null;
        try {
          payload = xhr.responseText ? JSON.parse(xhr.responseText) : {};
        } catch (error) {
          reject(projectCreateAjaxError(xhr.status, { errors: ['The server returned an invalid JSON response.'] }));
          return;
        }

        if (xhr.status < 200 || xhr.status >= 300) {
          reject(projectCreateAjaxError(xhr.status, payload));
          return;
        }

        resolve(payload || {});
      });
      xhr.addEventListener('error', () => reject(projectCreateAjaxError(0, { errors: ['Network request failed.'] })));
      xhr.send(options.body || null);
    });
  }

  function projectEscapedFlashHtml(message) {
    const item = document.createElement('div');
    item.className = 'alert error';
    item.textContent = message;
    return item.outerHTML;
  }

  function projectRenderErrorFlashHtml(payload) {
    if (payload && typeof payload.flash_html === 'string') {
      return payload.flash_html;
    }

    const errors = payload && Array.isArray(payload.errors) ? payload.errors : [];
    if (errors.length > 0) {
      return errors.map((error) => projectEscapedFlashHtml(String(error))).join('');
    }

    return projectEscapedFlashHtml('The request could not be completed.');
  }

  function projectReplaceFlash(html) {
    if (typeof html !== 'string') {
      return;
    }

    const current = document.getElementById('flash-messages');
    if (current instanceof HTMLElement) {
      current.innerHTML = html;
    }
  }

  function projectReplaceSiteContextSlots(slotHtml) {
    if (!slotHtml || typeof slotHtml !== 'object') {
      return;
    }

    Object.entries(slotHtml).forEach(([slot, html]) => {
      const current = document.getElementById(`site-context-${String(slot || '').trim()}-slot`);
      if (current instanceof HTMLElement) {
        current.innerHTML = typeof html === 'string' ? html : '';
      }
    });
  }

  function projectReplaceCards(cards) {
    Object.entries(cards || {}).forEach(([domId, html]) => {
      const current = document.getElementById(domId);
      if (!(current instanceof HTMLElement)) {
        return;
      }

      if (typeof html !== 'string' || html.trim() === '') {
        current.remove();
        return;
      }

      const template = document.createElement('template');
      template.innerHTML = html.trim();
      const replacement = template.content.firstElementChild;
      if (!(replacement instanceof HTMLElement)) {
        return;
      }

      current.replaceWith(replacement);
      initialise(replacement);
    });
  }

  function projectApplyAjaxPagePayload(payload) {
    if (!payload || typeof payload !== 'object') {
      return;
    }

    const redirectUrl = String(payload.redirect_url || payload.location || '').trim();
    if (redirectUrl !== '') {
      window.location.href = redirectUrl;
      return;
    }

    projectReplaceSiteContextSlots(payload.site_context_html);
    projectReplaceCards(payload.cards);
    projectReplaceFlash(payload.flash_html);
  }

  function projectHandleAjaxSecurityFailure(payload) {
    if (payload && payload.reload_required) {
      window.setTimeout(() => window.location.reload(), 150);
    }
  }

  function projectButtonProcessingState(submitter) {
    if (!(submitter instanceof HTMLButtonElement)) {
      return () => {};
    }

    const processingText = String(submitter.dataset.processingText || '').trim();
    if (processingText === '') {
      return () => {};
    }

    const originalHtml = submitter.innerHTML;
    const originalDisabled = submitter.disabled;
    const shouldDisable = String(submitter.dataset.processingState || '').trim().toLowerCase() === 'disabled';
    submitter.textContent = processingText;
    if (shouldDisable) {
      submitter.disabled = true;
      submitter.setAttribute('aria-disabled', 'true');
    }

    return () => {
      if (!submitter.isConnected) {
        return;
      }
      submitter.innerHTML = originalHtml;
      submitter.disabled = originalDisabled;
      if (!originalDisabled) {
        submitter.removeAttribute('aria-disabled');
      }
    };
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
    syncInternalProfileAdjustmentForms(root);
    initialiseRawUploadForms(root);
    initialisePictureViewers(root);
    initialiseGalleryAutoRefresh(root);
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

  function selectedValueById(id) {
    const field = document.getElementById(id);
    return field instanceof HTMLSelectElement ? field.value : '';
  }

  function syncInternalProfileAdjustmentForms(root = document) {
    const forms = [];
    if (root instanceof HTMLElement && root.matches('[data-internal-profile-adjustment-form="true"]')) {
      forms.push(root);
    }
    if (root && typeof root.querySelectorAll === 'function') {
      forms.push(...root.querySelectorAll('[data-internal-profile-adjustment-form="true"]'));
    }

    const selectedImageType = selectedValueById('internal-profiles-image-type');
    const selectedProfileName = selectedValueById('internal-profiles-profile-name');

    forms.forEach((form) => {
      if (!(form instanceof HTMLFormElement)) {
        return;
      }

      const imageField = form.querySelector('[data-internal-profile-image-field="true"]');
      const nameField = form.querySelector('[data-internal-profile-name-field="true"]');
      const newNameField = form.querySelector('[data-internal-profile-new-name-field="true"]');
      const label = form.querySelector('[data-internal-profile-adjustment-label="true"]');
      const imageType = selectedImageType || (imageField instanceof HTMLInputElement ? imageField.value : '');
      const profileName = selectedProfileName || (nameField instanceof HTMLInputElement ? nameField.value : '');

      if (imageField instanceof HTMLInputElement) {
        imageField.value = imageType;
      }
      if (nameField instanceof HTMLInputElement) {
        nameField.value = profileName;
      }
      if (newNameField instanceof HTMLInputElement) {
        newNameField.value = profileName;
      }
      if (label instanceof HTMLElement && imageType !== '' && profileName !== '') {
        label.textContent = `Add adjustment entry for ${imageType} : ${profileName}`;
      }
    });
  }

  function rawUploadInput(form) {
    return form instanceof HTMLFormElement ? form.querySelector('[data-upload-input]') : null;
  }

  function rawUploadDropzone(form) {
    return form instanceof HTMLFormElement ? form.querySelector('[data-upload-dropzone]') : null;
  }

  function rawUploadStatusNode(form) {
    return form instanceof HTMLFormElement ? form.querySelector('[data-raw-upload-status]') : null;
  }

  function setRawUploadStatus(form, message, type = '') {
    const node = rawUploadStatusNode(form);
    if (!(node instanceof HTMLElement)) {
      return;
    }

    node.hidden = String(message || '').trim() === '';
    node.className = `form-row full raw-upload-progress${type ? ` ${type}` : ''}`;
    node.textContent = String(message || '');
  }

  function rawUploadFiles(form) {
    const input = rawUploadInput(form);
    return input instanceof HTMLInputElement && input.files ? Array.from(input.files) : [];
  }

  function updateRawUploadSelection(form) {
    const summary = form.querySelector('[data-upload-selection-summary]');
    const list = form.querySelector('[data-upload-file-list]');
    const files = rawUploadFiles(form);

    if (summary instanceof HTMLElement) {
      summary.textContent = files.length === 0
        ? 'No files selected yet.'
        : `${String(files.length)} file${files.length === 1 ? '' : 's'} selected.`;
    }

    if (list instanceof HTMLElement) {
      list.replaceChildren(...files.map((file) => {
        const item = document.createElement('li');
        item.textContent = file.name;
        return item;
      }));
      list.hidden = files.length === 0;
    }
  }

  function validateRawUploadForm(form) {
    const dropzone = rawUploadDropzone(form);
    const maxFiles = Number(dropzone instanceof HTMLElement ? dropzone.dataset.uploadMaxFiles || '3' : '3');
    const files = rawUploadFiles(form);

    if (files.length === 0) {
      return 'Choose at least one CR2 file to upload.';
    }
    if (files.length > maxFiles) {
      return `Upload no more than ${String(maxFiles)} CR2 files at once.`;
    }

    const invalidFile = files.find((file) => !String(file.name || '').toLowerCase().endsWith('.cr2'));
    return invalidFile ? `${invalidFile.name || 'Selected file'} is not a CR2 file.` : '';
  }

  function resetRawUploadForm(form) {
    const input = rawUploadInput(form);
    if (input instanceof HTMLInputElement) {
      input.value = '';
    }
    updateRawUploadSelection(form);
  }

  function initialiseRawUploadForms(root = document) {
    const forms = root.querySelectorAll ? root.querySelectorAll('[data-raw-upload-form="true"]') : [];

    forms.forEach((form) => {
      if (!(form instanceof HTMLFormElement) || form.dataset.rawUploadBound === '1') {
        return;
      }

      form.dataset.rawUploadBound = '1';
      const input = rawUploadInput(form);
      if (input instanceof HTMLInputElement) {
        input.addEventListener('change', () => updateRawUploadSelection(form));
      }

      form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const validationError = validateRawUploadForm(form);
        if (validationError !== '') {
          setRawUploadStatus(form, validationError, 'error');
          return;
        }

        const formData = new FormData(form);
        formData.set('_ajax', '1');
        projectAppendSiteContextToFormData(formData, form);
        const ajaxNonce = projectReserveAjaxNonce();
        if (ajaxNonce) {
          formData.set('ajax_nonce', ajaxNonce);
        }

        const submitter = event.submitter instanceof HTMLButtonElement
          ? event.submitter
          : form.querySelector('[data-upload-submit]');
        const restoreProcessingState = projectButtonProcessingState(submitter);
        setRawUploadStatus(form, 'Uploading...', '');

        try {
          const payload = await projectSendXhr(projectFormRequestUrl(form), {
            method: 'POST',
            body: formData,
            onUploadProgress: (progressEvent) => {
              if (!progressEvent.lengthComputable || progressEvent.total <= 0) {
                setRawUploadStatus(form, 'Uploading...', '');
                return;
              }
              const percent = Math.max(0, Math.min(100, Math.round((progressEvent.loaded / progressEvent.total) * 100)));
              setRawUploadStatus(form, `Uploading ${String(percent)}%...`, '');
            },
          });

          projectCompleteAjaxNonce(ajaxNonce, payload?.ajax_nonce);
          setRawUploadStatus(form, 'Upload complete.', 'success');
          resetRawUploadForm(form);
          projectApplyAjaxPagePayload(payload);
        } catch (error) {
          projectRestoreAjaxNonce(ajaxNonce);
          projectReplaceFlash(projectRenderErrorFlashHtml(error ? error.payload : null));
          setRawUploadStatus(form, 'Upload failed.', 'error');
          projectHandleAjaxSecurityFailure(error ? error.payload : null);
          console.error(error);
        } finally {
          restoreProcessingState();
        }
      });

      updateRawUploadSelection(form);
    });
  }

  function initialisePictureViewers(root = document) {
    const viewers = root.querySelectorAll ? root.querySelectorAll('[data-picture-viewer="true"]') : [];

    viewers.forEach((viewer) => {
      if (!(viewer instanceof HTMLElement) || viewer.dataset.pictureViewerBound === '1') {
        return;
      }

      viewer.dataset.pictureViewerBound = '1';
      const layout = viewer.closest('[data-picture-viewer-layout]');
      const stateUrl = String(viewer.dataset.pictureViewerStateUrl || '').trim();
      const pill = viewer.querySelector('[data-picture-viewer-status-pill]');
      const openDetailsButton = viewer.querySelector('[data-picture-viewer-details-open]');
      const closeDetailsButton = layout instanceof HTMLElement ? layout.querySelector('[data-picture-viewer-details-close]') : null;
      const imageTypeLabel = viewer.querySelector('[data-picture-viewer-image-type-label]');
      const fullscreenCloseButton = viewer.querySelector('[data-picture-viewer-fullscreen-close]');
      const detailInputs = layout instanceof HTMLElement ? Array.from(layout.querySelectorAll('.picture-details-tab-input')) : [];
      const detailPanels = layout instanceof HTMLElement ? Array.from(layout.querySelectorAll('[data-picture-details-panel]')) : [];
      let imageNode = viewer.querySelector('[data-picture-viewer-image]');
      let pollTimer = null;

      if (stateUrl === '') {
        return;
      }

      function setDetailsCollapsed(collapsed) {
        if (!(layout instanceof HTMLElement)) {
          return;
        }
        layout.classList.toggle('is-details-collapsed', collapsed);
        layout.classList.toggle('is-details-expanded', !collapsed);
        if (openDetailsButton instanceof HTMLButtonElement) {
          openDetailsButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
          openDetailsButton.hidden = !collapsed;
        }
        if (closeDetailsButton instanceof HTMLButtonElement) {
          closeDetailsButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
      }

      function setPill(status) {
        const normalised = ['queued', 'rendering', 'loaded'].includes(status) ? status : 'queued';
        viewer.dataset.pictureViewerStatus = normalised;
        if (pill instanceof HTMLElement) {
          pill.textContent = normalised.charAt(0).toUpperCase() + normalised.slice(1);
          pill.dataset.pictureViewerState = normalised;
        }
      }

      function displayTypeLabel(type) {
        const labels = {
          embedded: 'Embedded',
          thumbnail: 'Thumbnail',
          original: 'Original',
          preview: 'Preview',
          final: 'Final',
        };
        const normalised = String(type || '').trim().toLowerCase();
        if (normalised === '') {
          return 'Queued';
        }
        return labels[normalised] || normalised.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase());
      }

      function setImageTypeLabel(type) {
        if (imageTypeLabel instanceof HTMLElement) {
          imageTypeLabel.textContent = displayTypeLabel(type);
        }
      }

      function setPictureFullscreen(active) {
        viewer.classList.toggle('is-picture-fullscreen', active);
        document.documentElement.classList.toggle('has-picture-viewer-fullscreen', active);
        if (fullscreenCloseButton instanceof HTMLButtonElement) {
          fullscreenCloseButton.hidden = !active;
        }
      }

      async function enterPictureFullscreen() {
        if (!(imageNode instanceof HTMLImageElement) || String(imageNode.getAttribute('src') || '').trim() === '') {
          return;
        }

        setPictureFullscreen(true);
        if (document.fullscreenElement !== viewer && typeof viewer.requestFullscreen === 'function') {
          try {
            await viewer.requestFullscreen({ navigationUI: 'hide' });
          } catch (error) {
            console.warn('Browser fullscreen was not available for the picture viewer.', error);
          }
        }
      }

      async function exitPictureFullscreen() {
        setPictureFullscreen(false);
        if (document.fullscreenElement === viewer && typeof document.exitFullscreen === 'function') {
          try {
            await document.exitFullscreen();
          } catch (error) {
            console.warn('Unable to exit browser fullscreen for the picture viewer.', error);
          }
        }
      }

      function swapImage(url, type) {
        if (url === '' || type === '') {
          return;
        }

        const placeholder = viewer.querySelector('[data-picture-viewer-placeholder]');
        if (!(imageNode instanceof HTMLImageElement)) {
          imageNode = document.createElement('img');
          imageNode.setAttribute('alt', 'Photo');
          imageNode.dataset.pictureViewerImage = 'true';
          viewer.appendChild(imageNode);
        }
        if (placeholder instanceof HTMLElement) {
          placeholder.remove();
        }
        if (imageNode.src !== new URL(url, window.location.href).href) {
          imageNode.src = url;
        }
        imageNode.dataset.pictureViewerImageType = type;
        viewer.dataset.pictureViewerDisplayType = type;
        setImageTypeLabel(type);
      }

      async function poll(attempt = 0) {
        if (!viewer.isConnected) {
          return;
        }

        try {
          const response = await projectFetchJson(stateUrl);
          if (!response || response.success === false) {
            setPill('queued');
          } else {
            const status = String(response.final_status || 'queued');
            setPill(status);
            swapImage(String(response.display_url || ''), String(response.display_type || ''));
            if (status === 'loaded') {
              return;
            }
          }
        } catch (error) {
          setPill('queued');
          console.error(error);
        }

        const delay = attempt < 5 ? 1500 : 4000;
        pollTimer = window.setTimeout(() => poll(attempt + 1), delay);
      }

      async function loadDetailsPanel(panel) {
        if (!(panel instanceof HTMLElement) || panel.dataset.pictureDetailsLoaded === '1') {
          return;
        }

        const url = String(panel.dataset.pictureDetailsLoadUrl || '').trim();
        if (url === '') {
          panel.dataset.pictureDetailsLoaded = '1';
          return;
        }

        panel.dataset.pictureDetailsLoaded = '1';
        try {
          const response = await projectFetchJson(url);
          panel.innerHTML = response && response.success !== false
            ? String(response.html || '<p class="helper">Details are not available.</p>')
            : '<p class="helper">Details are not available.</p>';
        } catch (error) {
          panel.dataset.pictureDetailsLoaded = '';
          panel.innerHTML = '<p class="helper">Details are not available.</p>';
          console.error(error);
        }
      }

      setPill(String(viewer.dataset.pictureViewerStatus || 'queued'));
      setImageTypeLabel(String(viewer.dataset.pictureViewerDisplayType || ''));
      setDetailsCollapsed(true);
      detailInputs.forEach((input, index) => {
        if (!(input instanceof HTMLInputElement)) {
          return;
        }
        input.addEventListener('change', () => {
          if (input.checked) {
            void loadDetailsPanel(detailPanels[index]);
          }
        });
        if (input.checked) {
          void loadDetailsPanel(detailPanels[index]);
        }
      });
      if (openDetailsButton instanceof HTMLButtonElement) {
        openDetailsButton.addEventListener('click', () => setDetailsCollapsed(false));
      }
      if (closeDetailsButton instanceof HTMLButtonElement) {
        closeDetailsButton.addEventListener('click', () => setDetailsCollapsed(true));
      }
      viewer.addEventListener('click', (event) => {
        if (event.target !== imageNode || viewer.classList.contains('is-picture-fullscreen')) {
          return;
        }
        void enterPictureFullscreen();
      });
      if (fullscreenCloseButton instanceof HTMLButtonElement) {
        fullscreenCloseButton.addEventListener('click', (event) => {
          event.stopPropagation();
          void exitPictureFullscreen();
        });
      }
      document.addEventListener('fullscreenchange', () => {
        if (document.fullscreenElement !== viewer && viewer.classList.contains('is-picture-fullscreen')) {
          setPictureFullscreen(false);
        }
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && viewer.classList.contains('is-picture-fullscreen')) {
          void exitPictureFullscreen();
        }
      });
      if (viewer.dataset.pictureViewerStatus !== 'loaded') {
        if (imageNode instanceof HTMLImageElement && String(imageNode.getAttribute('src') || '').trim() !== '') {
          pollTimer = window.setTimeout(() => poll(0), 1000);
        } else {
          void poll(0);
        }
      }
      window.addEventListener('pagehide', () => {
        if (pollTimer !== null) {
          window.clearTimeout(pollTimer);
        }
      }, { once: true });
    });
  }

  function galleryViewerPrefetchLinkFromEvent(event) {
    const link = event.target instanceof Element ? event.target.closest('[data-gallery-viewer-prefetch-url]') : null;
    return link instanceof HTMLAnchorElement ? link : null;
  }

  function galleryViewerPreviewThroughputBytesPerSecond() {
    if (!window.performance || typeof window.performance.getEntriesByType !== 'function') {
      return null;
    }

    const rates = window.performance.getEntriesByType('resource')
      .filter((entry) => {
        if (entry.initiatorType !== 'img'
          || Number(entry.transferSize || 0) <= 0
          || Number(entry.encodedBodySize || 0) < galleryViewerPrefetchMinimumSampleBytes
          || Number(entry.responseEnd || 0) - Number(entry.responseStart || 0) < galleryViewerPrefetchMinimumSampleDurationMs
        ) {
          return false;
        }

        try {
          const resourceUrl = new URL(String(entry.name || ''), window.location.href);
          const imageType = String(resourceUrl.searchParams.get('type') || '').toLowerCase();
          return resourceUrl.origin === window.location.origin
            && resourceUrl.pathname === '/api/photo-imaging.php'
            && ['preview', 'thumbnail'].includes(imageType);
        } catch (error) {
          return false;
        }
      })
      .map((entry) => Number(entry.encodedBodySize) / ((Number(entry.responseEnd) - Number(entry.responseStart)) / 1000))
      .filter((rate) => Number.isFinite(rate) && rate > 0)
      .slice(-8)
      .sort((left, right) => left - right);

    if (rates.length < 2) {
      return null;
    }
    if (rates[rates.length - 1] > rates[0] * 2) {
      return null;
    }

    const middle = Math.floor(rates.length / 2);
    return rates.length % 2 === 0
      ? (rates[middle - 1] + rates[middle]) / 2
      : rates[middle];
  }

  function galleryViewerConnectionThroughputBytesPerSecond() {
    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (!connection || connection.saveData === true) {
      return connection && connection.saveData === true ? 0 : null;
    }

    const downlinkMbps = Number(connection.downlink || 0);
    if (!Number.isFinite(downlinkMbps) || downlinkMbps <= 0) {
      return null;
    }

    return String(connection.effectiveType || '').toLowerCase() === '4g'
      ? downlinkMbps * 125000
      : 0;
  }

  function galleryViewerPrefetchAllowed() {
    if (typeof window.fetch !== 'function' || typeof window.AbortController !== 'function') {
      return false;
    }

    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (connection && connection.saveData === true) {
      return false;
    }

    const estimates = [
      galleryViewerPreviewThroughputBytesPerSecond(),
      galleryViewerConnectionThroughputBytesPerSecond(),
    ].filter((estimate) => Number.isFinite(estimate));

    return estimates.length > 0
      && estimates.every((estimate) => estimate >= galleryViewerPrefetchMinimumBytesPerSecond);
  }

  function cancelGalleryViewerPrefetch(link = null) {
    if (link instanceof HTMLAnchorElement && galleryViewerPrefetchState.link !== link) {
      return;
    }

    const activeLink = galleryViewerPrefetchState.link;
    if (galleryViewerPrefetchState.timer !== null) {
      window.clearTimeout(galleryViewerPrefetchState.timer);
    }
    if (galleryViewerPrefetchState.controller && typeof galleryViewerPrefetchState.controller.abort === 'function') {
      galleryViewerPrefetchState.controller.abort();
    }
    if (activeLink instanceof HTMLAnchorElement && activeLink.dataset.galleryViewerPrefetchStarted !== 'complete') {
      delete activeLink.dataset.galleryViewerPrefetchStarted;
    }

    galleryViewerPrefetchState.timer = null;
    galleryViewerPrefetchState.link = null;
    galleryViewerPrefetchState.url = '';
    galleryViewerPrefetchState.controller = null;
  }

  async function startGalleryViewerPrefetch(link, url) {
    const remainsIntentional = link instanceof HTMLAnchorElement
      && link.isConnected
      && (link.matches(':hover') || document.activeElement === link);
    if (!(link instanceof HTMLAnchorElement)
      || !remainsIntentional
      || galleryViewerPrefetchState.link !== link
      || galleryViewerPrefetchState.url !== url
      || !galleryViewerPrefetchAllowed()
    ) {
      cancelGalleryViewerPrefetch(link);
      return;
    }

    const controller = new window.AbortController();
    galleryViewerPrefetchState.timer = null;
    galleryViewerPrefetchState.controller = controller;
    link.dataset.galleryViewerPrefetchStarted = '1';

    try {
      const response = await window.fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'default',
        priority: 'low',
        signal: controller.signal,
      });
      if (!response.ok) {
        throw new Error(`Gallery viewer prefetch failed with HTTP ${String(response.status)}.`);
      }

      if (!response.body || typeof response.body.getReader !== 'function') {
        throw new Error('Gallery viewer prefetch requires a streaming response body.');
      }

      const reader = response.body.getReader();
      for (;;) {
        const chunk = await reader.read();
        if (chunk.done) {
          break;
        }
      }

      galleryViewerPrefetchCompletedUrls.add(url);
      link.dataset.galleryViewerPrefetchStarted = 'complete';
    } catch (error) {
      if (!controller.signal.aborted) {
        console.warn('Gallery viewer prefetch did not complete.', error);
      }
      if (link.dataset.galleryViewerPrefetchStarted !== 'complete') {
        delete link.dataset.galleryViewerPrefetchStarted;
      }
    } finally {
      if (galleryViewerPrefetchState.controller === controller) {
        galleryViewerPrefetchState.timer = null;
        galleryViewerPrefetchState.link = null;
        galleryViewerPrefetchState.url = '';
        galleryViewerPrefetchState.controller = null;
      }
    }
  }

  function scheduleGalleryViewerPrefetch(link) {
    if (!(link instanceof HTMLAnchorElement)) {
      return;
    }

    const rawUrl = String(link.dataset.galleryViewerPrefetchUrl || '').trim();
    let url = '';
    try {
      const candidate = new URL(rawUrl, window.location.href);
      url = candidate.origin === window.location.origin ? candidate.href : '';
    } catch (error) {
      url = '';
    }
    if (url === '' || galleryViewerPrefetchCompletedUrls.has(url)) {
      return;
    }
    if (galleryViewerPrefetchState.link === link && galleryViewerPrefetchState.url === url) {
      return;
    }

    cancelGalleryViewerPrefetch();
    galleryViewerPrefetchState.link = link;
    galleryViewerPrefetchState.url = url;
    galleryViewerPrefetchState.timer = window.setTimeout(() => {
      void startGalleryViewerPrefetch(link, url);
    }, galleryViewerPrefetchDelayMs);
  }

  function galleryViewerPrefetchEnter(event) {
    const link = galleryViewerPrefetchLinkFromEvent(event);
    if (!(link instanceof HTMLAnchorElement)
      || (typeof window.PointerEvent === 'function' && event instanceof window.PointerEvent && event.pointerType === 'touch')
      || (event.relatedTarget instanceof Node && link.contains(event.relatedTarget))
    ) {
      return;
    }
    scheduleGalleryViewerPrefetch(link);
  }

  function galleryViewerPrefetchLeave(event) {
    const link = galleryViewerPrefetchLinkFromEvent(event);
    if (!(link instanceof HTMLAnchorElement)
      || (event.relatedTarget instanceof Node && link.contains(event.relatedTarget))
    ) {
      return;
    }
    cancelGalleryViewerPrefetch(link);
  }

  function galleryAutoRefreshEnabled() {
    if (!projectStorageAvailable('localStorage')) {
      return true;
    }
    try {
      const stored = window.localStorage.getItem(galleryAutoRefreshStorageKey);
      return stored === null ? true : stored === '1';
    } catch (error) {
      return true;
    }
  }

  function setGalleryAutoRefreshEnabled(enabled) {
    if (!projectStorageAvailable('localStorage')) {
      return;
    }
    try {
      window.localStorage.setItem(galleryAutoRefreshStorageKey, enabled ? '1' : '0');
    } catch (error) {
      // Storage may be disabled; the current checkbox state still applies.
    }
  }

  function galleryAutoScrollEnabled() {
    if (!projectStorageAvailable('localStorage')) {
      return false;
    }
    try {
      return window.localStorage.getItem(galleryAutoScrollStorageKey) === '1';
    } catch (error) {
      return false;
    }
  }

  function setGalleryAutoScrollEnabled(enabled) {
    if (!projectStorageAvailable('localStorage')) {
      return;
    }
    try {
      window.localStorage.setItem(galleryAutoScrollStorageKey, enabled ? '1' : '0');
    } catch (error) {
      // Storage may be disabled; the current checkbox state still applies.
    }
  }

  function galleryAutoRefreshTargets(root) {
    const targets = [];
    if (root instanceof HTMLElement && root.matches('[data-gallery-auto-refresh="true"]')) {
      targets.push(root);
    }
    if (root && typeof root.querySelectorAll === 'function') {
      root.querySelectorAll('[data-gallery-auto-refresh="true"]').forEach((node) => {
        if (node instanceof HTMLElement) {
          targets.push(node);
        }
      });
    }
    return targets;
  }

  function galleryHasPendingPreviews(target) {
    return target instanceof HTMLElement
      && (target.dataset.galleryPending === '1'
        || target.querySelector('[data-gallery-photo-pending="1"]') instanceof HTMLElement);
  }

  function galleryHasPendingPreviewTiles(target) {
    return target instanceof HTMLElement
      && target.querySelector('[data-gallery-photo-pending="1"]') instanceof HTMLElement;
  }

  function galleryCardRefreshPayload(card, target) {
    const pageParams = new URL(window.location.href).searchParams;
    const cardKey = String(card.dataset.cardKey || '').trim();
    const pageField = String(target.dataset.galleryPageField || '').trim();
    const pageValue = Math.max(1, Number.parseInt(String(target.dataset.galleryPage || '1'), 10));
    const perPageField = String(target.dataset.galleryPerPageField || '').trim();
    const perPageValue = Math.max(1, Number.parseInt(String(target.dataset.galleryPerPage || '24'), 10));
    const payload = {
      _ajax: '1',
      _card_refresh: '1',
      page: pageParams.get('page') || 'gallery',
      cards: [cardKey],
    };

    if (pageField !== '') {
      payload[pageField] = String(pageValue);
    }
    if (perPageField !== '') {
      payload[perPageField] = String(perPageValue);
    }
    projectAppendSiteContextToPayload(payload);
    return payload;
  }

  function galleryResponseCard(response, card) {
    const html = String((response.cards || {})[card.id] || '').trim();
    if (html === '') {
      return null;
    }

    const template = document.createElement('template');
    template.innerHTML = html;
    const replacement = template.content.firstElementChild;
    return replacement instanceof HTMLElement ? replacement : null;
  }

  function replaceGalleryPendingTiles(target, replacementCard) {
    const replacementTarget = replacementCard instanceof HTMLElement
      ? replacementCard.querySelector('[data-gallery-auto-refresh="true"]')
      : null;
    if (!(target instanceof HTMLElement) || !(replacementTarget instanceof HTMLElement)) {
      return;
    }

    target.querySelectorAll('[data-gallery-photo-pending="1"][data-gallery-photo-id]').forEach((currentTile) => {
      if (!(currentTile instanceof HTMLElement)) {
        return;
      }

      const photoId = String(currentTile.dataset.galleryPhotoId || '').trim();
      const replacementTile = photoId !== ''
        ? Array.from(replacementTarget.querySelectorAll('[data-gallery-photo-id]')).find((node) => (
          node instanceof HTMLElement && String(node.dataset.galleryPhotoId || '').trim() === photoId
        ))
        : null;

      if (replacementTile instanceof HTMLElement) {
        currentTile.replaceWith(replacementTile);
      }
    });

    target.dataset.galleryPending = galleryHasPendingPreviewTiles(target) ? '1' : '0';
  }

  function galleryPendingStatusUrls(target) {
    if (!(target instanceof HTMLElement)) {
      return [];
    }

    const urls = [];
    target.querySelectorAll('[data-gallery-photo-pending="1"][data-gallery-photo-status-url]').forEach((node) => {
      if (!(node instanceof HTMLElement)) {
        return;
      }

      const statusUrl = String(node.dataset.galleryPhotoStatusUrl || '').trim();
      if (statusUrl !== '' && !urls.includes(statusUrl)) {
        urls.push(statusUrl);
      }
    });

    return urls;
  }

  async function pollGalleryPhotoStatuses(target) {
    const urls = galleryPendingStatusUrls(target);
    if (urls.length === 0) {
      return;
    }

    await Promise.allSettled(urls.map((url) => projectFetchJson(url)));
  }

  function initialiseGalleryAutoRefresh(root = document) {
    galleryAutoRefreshTargets(root).forEach((target) => {
      if (target.dataset.galleryAutoRefreshBound === '1') {
        return;
      }

      target.dataset.galleryAutoRefreshBound = '1';
      const card = target.closest('.card[data-card-key]');
      const refreshControl = card instanceof HTMLElement ? card.querySelector('[data-gallery-auto-refresh-toggle]') : null;
      const scrollControl = card instanceof HTMLElement ? card.querySelector('[data-gallery-auto-scroll-toggle]') : null;
      if (!(card instanceof HTMLElement)
        || !(refreshControl instanceof HTMLInputElement)
        || !(scrollControl instanceof HTMLInputElement)
      ) {
        return;
      }

      const state = {
        inFlight: false,
        lastCardRefreshAt: 0,
        timerId: null,
      };
      refreshControl.checked = galleryAutoRefreshEnabled();
      scrollControl.checked = galleryAutoScrollEnabled();

      const clearTimer = () => {
        if (state.timerId !== null) {
          window.clearTimeout(state.timerId);
          state.timerId = null;
        }
      };
      const shouldRefresh = () => card.isConnected
        && (scrollControl.checked || (refreshControl.checked && galleryHasPendingPreviews(target)));
      const schedule = () => {
        clearTimer();
        if (shouldRefresh()) {
          state.timerId = window.setTimeout(refresh, galleryAutoRefreshIntervalMs);
        }
      };
      const refresh = async () => {
        state.timerId = null;
        if (!shouldRefresh()) {
          return;
        }
        if (document.hidden || state.inFlight) {
          schedule();
          return;
        }

        state.inFlight = true;
        const shouldAutoScroll = scrollControl.checked;
        const shouldRefreshCard = shouldAutoScroll
          || Date.now() - state.lastCardRefreshAt >= galleryCardRefreshIntervalMs;

        try {
          if (shouldRefreshCard) {
            const response = await projectFetchJson(window.location.href, {
              method: 'POST',
              body: JSON.stringify(galleryCardRefreshPayload(card, target)),
              headers: { 'Content-Type': 'application/json' },
            });
            state.lastCardRefreshAt = Date.now();
            projectReplaceSiteContextSlots(response.site_context_html);
            if (shouldAutoScroll) {
              projectReplaceCards(response.cards);
            } else {
              replaceGalleryPendingTiles(target, galleryResponseCard(response, card));
            }
          } else {
            await pollGalleryPhotoStatuses(target);
          }
        } catch (error) {
          console.error('Failed to auto refresh gallery.', error);
          schedule();
        } finally {
          state.inFlight = false;
          if (!shouldAutoScroll || card.isConnected) {
            schedule();
          }
        }
      };

      refreshControl.addEventListener('change', () => {
        setGalleryAutoRefreshEnabled(refreshControl.checked);
        schedule();
      });
      scrollControl.addEventListener('change', () => {
        setGalleryAutoScrollEnabled(scrollControl.checked);
        schedule();
      });
      schedule();
    });
  }

  function setGalleryEventsPaneOpen(root, open) {
    if (!(root instanceof HTMLElement) && root !== document && root !== document.body) {
      root = document;
    }

    const pane = root.querySelector ? root.querySelector('[data-gallery-events-pane]') : null;
    const grid = root.querySelector ? root.querySelector('[data-gallery-events-grid]') : null;
    const toggle = root.querySelector ? root.querySelector('[data-gallery-events-toggle]') : null;
    if (pane instanceof HTMLElement) {
      pane.hidden = !open;
    }
    if (grid instanceof HTMLElement) {
      grid.classList.toggle('is-assigning-events', open);
    }
    if (toggle instanceof HTMLButtonElement) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.classList.toggle('primary', !open);
      toggle.textContent = open ? 'Close Events' : 'Events';
    }
    if (!open) {
      setGalleryAssignmentEvent(root, '');
      return;
    }
    updateGalleryEventCheckboxStates(root);
  }

  function toggleGalleryAssignmentEvent(button) {
    const root = button.closest('[data-page-stack-card], .card, body');
    if (!(root instanceof HTMLElement)) {
      return;
    }

    const eventId = String(button.value || '').trim();
    const currentEventId = galleryAssignmentEventId(root);
    setGalleryAssignmentEvent(root, currentEventId === eventId ? '' : eventId);
  }

  function setGalleryAssignmentEvent(root, eventId) {
    if (!(root instanceof HTMLElement) && root !== document && root !== document.body) {
      root = document;
    }

    eventId = String(eventId || '').trim();
    const form = root.querySelector ? root.querySelector('[data-gallery-event-immediate-form]') : null;
    const eventInput = form instanceof HTMLFormElement ? form.querySelector('[data-gallery-assignment-event-id]') : null;
    if (eventInput instanceof HTMLInputElement) {
      eventInput.value = eventId;
    }

    const grid = root.querySelector ? root.querySelector('[data-gallery-events-grid]') : null;
    if (grid instanceof HTMLElement) {
      grid.classList.toggle('has-selected-event', eventId !== '');
    }

    if (root.querySelectorAll) {
      root.querySelectorAll('[data-gallery-assignment-event]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
          return;
        }

        const selected = eventId !== '' && String(button.value || '').trim() === eventId;
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
      });
    }

    updateGalleryEventCheckboxStates(root);
  }

  function galleryAssignmentEventId(root) {
    const form = root && root.querySelector ? root.querySelector('[data-gallery-event-immediate-form]') : null;
    const eventInput = form instanceof HTMLFormElement ? form.querySelector('[data-gallery-assignment-event-id]') : null;
    return eventInput instanceof HTMLInputElement ? String(eventInput.value || '').trim() : '';
  }

  function updateGalleryEventCheckboxStates(root) {
    if (!(root instanceof HTMLElement) && root !== document && root !== document.body) {
      root = document;
    }

    const eventId = galleryAssignmentEventId(root);
    if (!root.querySelectorAll) {
      return;
    }

    root.querySelectorAll('[data-gallery-event-photo-checkbox]').forEach((checkbox) => {
      if (!(checkbox instanceof HTMLInputElement)) {
        return;
      }

      const tile = checkbox.closest('[data-gallery-photo-id]');
      const eventIds = galleryTileEventIds(tile);
      checkbox.checked = eventId !== '' && eventIds.includes(eventId);
    });
  }

  function galleryTileEventIds(tile) {
    if (!(tile instanceof HTMLElement)) {
      return [];
    }

    return String(tile.dataset.galleryEventIds || '')
      .split(',')
      .map((value) => value.trim())
      .filter((value) => value !== '');
  }

  function setGalleryTileEventId(tile, eventId, assigned) {
    if (!(tile instanceof HTMLElement) || eventId === '') {
      return;
    }

    const ids = galleryTileEventIds(tile).filter((id) => id !== eventId);
    if (assigned) {
      ids.push(eventId);
    }
    tile.dataset.galleryEventIds = Array.from(new Set(ids)).join(',');
  }

  async function submitGalleryEventCheckbox(checkbox) {
    const root = checkbox.closest('[data-page-stack-card], .card, body');
    if (!(root instanceof HTMLElement)) {
      return;
    }

    const form = root.querySelector('[data-gallery-event-immediate-form]');
    const eventId = galleryAssignmentEventId(root);
    const tile = checkbox.closest('[data-gallery-photo-id]');
    const photoId = checkbox.value;
    if (!(form instanceof HTMLFormElement) || !(tile instanceof HTMLElement) || eventId === '' || photoId === '') {
      checkbox.checked = !checkbox.checked;
      return;
    }

    const assigned = checkbox.checked;
    const previous = !assigned;
    const formData = new FormData(form);
    formData.set('_ajax', '1');
    formData.set('assignment_event_id', eventId);
    formData.set('assignment_state', assigned ? '1' : '0');
    formData.delete('photo_ids');
    formData.delete('photo_ids[]');
    formData.append('photo_ids[]', photoId);
    projectAppendSiteContextToFormData(formData, form);

    const payload = projectFormDataToJsonPayload(formData);
    const ajaxNonce = projectReserveAjaxNonce();
    if (ajaxNonce) {
      payload.ajax_nonce = ajaxNonce;
    }

    checkbox.disabled = true;
    let nonceCompleted = false;
    try {
      const response = await projectFetchJson(projectFormRequestUrl(form), {
        method: 'POST',
        body: JSON.stringify(payload),
        headers: { 'Content-Type': 'application/json' },
      });
      projectCompleteAjaxNonce(ajaxNonce, response?.ajax_nonce);
      nonceCompleted = true;
      if (!response || response.success === false) {
        throw projectCreateAjaxError(200, response);
      }
      setGalleryTileEventId(tile, eventId, assigned);
    } catch (error) {
      if (!nonceCompleted) {
        projectRestoreAjaxNonce(ajaxNonce);
      }
      checkbox.checked = previous;
      projectReplaceFlash(projectRenderErrorFlashHtml(error ? error.payload : null));
      projectHandleAjaxSecurityFailure(error ? error.payload : null);
      console.error(error);
    } finally {
      checkbox.disabled = false;
    }
  }

  function clearGalleryEventCreateModal(refocus = false) {
    document.querySelectorAll('.gallery-event-create-backdrop').forEach((node) => node.remove());
    document.querySelectorAll('.gallery-event-create-window').forEach((node) => node.remove());
    if (refocus && activeGalleryEventCreateButton instanceof HTMLButtonElement && activeGalleryEventCreateButton.isConnected) {
      activeGalleryEventCreateButton.focus();
    }
    activeGalleryEventCreateButton = null;
  }

  function hiddenInput(name, value) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    return input;
  }

  function showGalleryEventCreateModal(button) {
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }

    clearGalleryEventCreateModal(false);
    activeGalleryEventCreateButton = button;
    const card = button.closest('[data-page-stack-card], .card, body');
    const existingForm = card instanceof HTMLElement ? card.querySelector('#gallery-event-assignment-form') : null;
    const csrfInput = existingForm instanceof HTMLFormElement ? existingForm.querySelector('input[name="csrf_token"]') : null;
    const csrfToken = csrfInput instanceof HTMLInputElement ? csrfInput.value : '';

    const backdrop = document.createElement('div');
    backdrop.className = 'gallery-event-create-backdrop';
    backdrop.addEventListener('click', () => clearGalleryEventCreateModal(true));

    const windowShell = document.createElement('div');
    windowShell.className = 'gallery-event-create-window';
    windowShell.setAttribute('role', 'dialog');
    windowShell.setAttribute('aria-modal', 'true');
    windowShell.setAttribute('aria-labelledby', 'gallery-event-create-title');

    const title = document.createElement('h3');
    title.id = 'gallery-event-create-title';
    title.textContent = 'Add Event';

    const form = document.createElement('form');
    form.method = 'post';
    form.action = '?page=gallery';
    form.dataset.galleryEventCreateForm = 'true';
    form.className = 'gallery-event-create-form';

    const label = document.createElement('label');
    const labelText = document.createElement('span');
    labelText.textContent = 'Event name';
    const input = document.createElement('input');
    input.className = 'input';
    input.name = 'event_name';
    input.type = 'text';
    input.required = true;
    input.autocomplete = 'off';
    label.append(labelText, input);

    const actions = document.createElement('div');
    actions.className = 'gallery-event-create-actions';
    const add = document.createElement('button');
    add.className = 'button button-inline primary';
    add.type = 'submit';
    add.textContent = 'Add';
    const cancel = document.createElement('button');
    cancel.className = 'button button-inline';
    cancel.type = 'button';
    cancel.textContent = 'Cancel';
    cancel.addEventListener('click', () => clearGalleryEventCreateModal(true));

    actions.append(add, cancel);
    form.append(
      hiddenInput('card_action', 'EventPermissions'),
      hiddenInput('event_permissions_action', 'create_event'),
      hiddenInput('csrf_token', csrfToken),
      hiddenInput('cards[]', 'browse_gallery'),
      label,
      actions
    );
    windowShell.append(title, form);
    document.body.append(backdrop, windowShell);
    input.focus();
  }

  async function submitGalleryEventCreateForm(form) {
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    const formData = new FormData(form);
    formData.set('_ajax', '1');
    projectAppendSiteContextToFormData(formData, form);
    const payload = projectFormDataToJsonPayload(formData);
    const ajaxNonce = projectReserveAjaxNonce();
    if (ajaxNonce) {
      payload.ajax_nonce = ajaxNonce;
    }

    const submitter = form.querySelector('button[type="submit"]');
    const restoreProcessingState = projectButtonProcessingState(submitter);
    let nonceCompleted = false;
    try {
      const response = await projectFetchJson(projectFormRequestUrl(form), {
        method: 'POST',
        body: JSON.stringify(payload),
        headers: { 'Content-Type': 'application/json' },
      });
      projectCompleteAjaxNonce(ajaxNonce, response?.ajax_nonce);
      nonceCompleted = true;
      if (!response || response.success === false) {
        throw projectCreateAjaxError(200, response);
      }
      projectApplyAjaxPagePayload(response);
      clearGalleryEventCreateModal(false);
    } catch (error) {
      if (!nonceCompleted) {
        projectRestoreAjaxNonce(ajaxNonce);
      }
      projectReplaceFlash(projectRenderErrorFlashHtml(error ? error.payload : null));
      projectHandleAjaxSecurityFailure(error ? error.payload : null);
      console.error(error);
    } finally {
      restoreProcessingState();
    }
  }

  projectLoadAjaxNonceBootstrap();

  document.addEventListener('DOMContentLoaded', () => initialise(document));

  document.addEventListener('pointerover', galleryViewerPrefetchEnter);
  document.addEventListener('pointerout', galleryViewerPrefetchLeave);
  document.addEventListener('focusin', galleryViewerPrefetchEnter);
  document.addEventListener('focusout', galleryViewerPrefetchLeave);
  window.addEventListener('pagehide', () => cancelGalleryViewerPrefetch());

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.galleryEventCreateForm !== 'true') {
      return;
    }

    event.preventDefault();
    void submitGalleryEventCreateForm(form);
  });

  document.addEventListener('click', (event) => {
    cancelGalleryViewerPrefetch();

    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const eventUserPickerToggle = target.closest('[data-event-user-picker-toggle]');
    if (eventUserPickerToggle instanceof HTMLButtonElement) {
      event.preventDefault();
      const card = eventUserPickerToggle.closest('.event-permissions');
      const picker = card instanceof HTMLElement ? card.querySelector('[data-event-user-picker]') : null;
      if (picker instanceof HTMLElement) {
        picker.hidden = !picker.hidden;
      }
      return;
    }

    const galleryEventCreateToggle = target.closest('[data-gallery-event-create-toggle]');
    if (galleryEventCreateToggle instanceof HTMLButtonElement) {
      event.preventDefault();
      showGalleryEventCreateModal(galleryEventCreateToggle);
      return;
    }

    const galleryEventsToggle = target.closest('[data-gallery-events-toggle]');
    if (galleryEventsToggle instanceof HTMLButtonElement) {
      event.preventDefault();
      const card = galleryEventsToggle.closest('[data-page-stack-card], .card, body');
      if (card instanceof HTMLElement || card === document.body) {
        const pane = card.querySelector('[data-gallery-events-pane]');
        setGalleryEventsPaneOpen(card, pane instanceof HTMLElement ? pane.hidden : false);
      }
      return;
    }

    const assignmentButton = target.closest('[data-gallery-assignment-event]');
    if (assignmentButton instanceof HTMLButtonElement) {
      event.preventDefault();
      toggleGalleryAssignmentEvent(assignmentButton);
      return;
    }

    prepareInternalProfileMove(target.closest('[data-internal-profile-move-direction]'));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.querySelector('.gallery-event-create-window')) {
      event.preventDefault();
      clearGalleryEventCreateModal(true);
    }
  });

  document.addEventListener('change', (event) => {
    const target = event.target;
    if (target instanceof HTMLSelectElement && target.matches('[data-rawtherapee-default-profile-id]')) {
      syncRawTherapeeDefaultButton(target);
    }

    if (target instanceof HTMLSelectElement
      && (target.id === 'internal-profiles-image-type' || target.id === 'internal-profiles-profile-name')
    ) {
      syncInternalProfileAdjustmentForms(document);
    }

    const galleryEventCheckbox = target instanceof Element
      ? target.closest('[data-gallery-event-photo-checkbox]')
      : null;
    if (galleryEventCheckbox instanceof HTMLInputElement) {
      void submitGalleryEventCheckbox(galleryEventCheckbox);
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
