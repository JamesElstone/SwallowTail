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

  function setStatus(panel, text) {
    setText(panel.querySelector('[data-rawtheapee-profile-status="true"]'), text);
  }

  function setImageShown(panel, text) {
    setText(panel.querySelector('[data-rawtheapee-profile-image-shown="true"]'), text);
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
      return;
    }

    setStatus(panel, 'Loading');
    image.addEventListener('load', () => setStatus(panel, 'Ready'), { once: true });
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

      const imageUrl = String(payload.rawtheapee_sample_url || '').trim();
      const image = panel.querySelector('[data-rawtheapee-profile-image="true"]');
      if ((status === 'succeeded' || payload.ready === true) && imageUrl !== '' && image instanceof HTMLImageElement) {
        setStatus(panel, 'Loading');
        setImageShown(panel, 'rawtheapee');
        image.addEventListener('load', () => setStatus(panel, 'Ready'), { once: true });
        image.src = imageUrl;
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
      bindImageLoad(panel, image);
    }
  }

  function initialise(root) {
    if (!root || typeof root.querySelectorAll !== 'function') {
      return;
    }

    root.querySelectorAll('[data-rawtheapee-profile-panel="true"]').forEach(initialiseRawTheapeeProfilePanel);
  }

  document.addEventListener('DOMContentLoaded', () => initialise(document));

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
