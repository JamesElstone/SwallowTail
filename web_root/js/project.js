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
    const image = panel.querySelector('[data-rawtheapee-profile-state-image="true"]');
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
    setText(panel.querySelector('[data-rawtheapee-profile-status="true"]'), text);
    setStatusImage(panel, text);
  }

  function setImageShown(panel, text) {
    setText(panel.querySelector('[data-rawtheapee-profile-image-shown="true"]'), text);
  }

  function setDisplayFields(panel, url, type) {
    panel.querySelectorAll('[data-rawtheapee-display-url-field="true"]').forEach((urlField) => {
      if (urlField instanceof HTMLInputElement) {
        urlField.value = String(url || '');
      }
    });

    panel.querySelectorAll('[data-rawtheapee-display-type-field="true"]').forEach((typeField) => {
      if (typeField instanceof HTMLInputElement) {
        typeField.value = String(type || 'none');
      }
    });
  }

  function syncDisplayFields(panel, image) {
    if (!(image instanceof HTMLImageElement)) {
      return;
    }

    setDisplayFields(panel, image.getAttribute('src') || '', String(image.dataset.rawtheapeeProfileImageType || 'none'));
  }

  function imageLoaded(image) {
    return image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0;
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
    const rawTheapeeUrl = String(payload.rawtheapee_sample_url || '').trim();
    if (rawTheapeeUrl !== '') {
      return { type: 'rawtheapee', url: rawTheapeeUrl };
    }

    const previewUrl = String(payload.preview_url || '').trim();
    if (previewUrl !== '') {
      return { type: 'preview', url: previewUrl };
    }

    return { type: 'none', url: '' };
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
      const image = panel.querySelector('[data-rawtheapee-profile-image="true"]');
      if ((status === 'succeeded' || payload.ready === true) && resolved.url !== '' && image instanceof HTMLImageElement) {
        if ((image.getAttribute('src') || '') === resolved.url && imageLoaded(image)) {
          image.dataset.rawtheapeeProfileImageType = resolved.type;
          setImageShown(panel, resolved.type);
          setDisplayFields(panel, resolved.url, resolved.type);
          setStatus(panel, 'Ready');
          return;
        }

        setStatus(panel, 'Loading');
        setImageShown(panel, resolved.type);
        image.dataset.rawtheapeeProfileImageType = resolved.type;
        image.addEventListener('load', () => {
          setDisplayFields(panel, resolved.url, resolved.type);
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

  function initialiseRawTheapeeProfilePanel(panel) {
    if (!(panel instanceof HTMLElement) || panels.has(panel)) {
      return;
    }

    panels.add(panel);
    const image = panel.querySelector('[data-rawtheapee-profile-image="true"]');
    const statusUrl = String(panel.dataset.rawtheapeeProfileStatusUrl || '').trim();

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

    root.querySelectorAll('[data-rawtheapee-profile-panel="true"]').forEach(initialiseRawTheapeeProfilePanel);
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

  document.addEventListener('DOMContentLoaded', () => initialise(document));

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    prepareInternalProfileMove(target.closest('[data-internal-profile-move-direction]'));
  });

  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (node instanceof HTMLElement) {
          if (node.matches('[data-rawtheapee-profile-panel="true"]')) {
            initialiseRawTheapeeProfilePanel(node);
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
    document.querySelectorAll('[data-rawtheapee-profile-panel="true"]').forEach((panel) => {
      const timer = pollTimers.get(panel);
      if (typeof timer === 'number') {
        window.clearTimeout(timer);
      }
    });
  });
}());
