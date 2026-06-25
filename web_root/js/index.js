/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
(() => {
    const body = document.body;
    let cardBodySequence = 0;
    const flashBaseTimeoutMs = 5000;
    const flashCascadeTimeoutMs = 2000;
    const flashDismissTransitionMs = 450;
    const flashHistoryLimit = 50;
    const flashHistory = [];
    let activeChickenCheckButton = null;
    const afStorageKey = 'af_client_device_id';
    const afPersistentCookieName = 'af_client_device_id';
    const tableCondensedStoragePrefix = 'table_condensed_view:';
    const galleryAutoRefreshStorageKey = 'gallery:auto-refresh:browse_gallery';
    const cardMaximizedStorageKey = 'card:maximized';
    const galleryAutoRefreshIntervalMs = 5000;
    let afEphemeralDeviceId = null;
    const ajaxNonceBootstrapId = 'ajax-security-bootstrap';
    const ajaxNonceState = {
        available: [],
        inFlight: new Set(),
    };
    const cardAutoRefreshState = new WeakMap();
    const afHeaderMap = {
        'Client-Browser-JS-User-Agent': 'X-AntiFraud-Client-Browser-JS-User-Agent',
        'Client-Device-ID': 'X-AntiFraud-Client-Device-ID',
        'Client-Screens': 'X-AntiFraud-Client-Screens',
        'Client-Timezone': 'X-AntiFraud-Client-Timezone',
        'Client-Window-Size': 'X-AntiFraud-Client-Window-Size',
    };

    function resolvePageLoadDurationMs() {
        if (!window.performance) {
            return null;
        }

        const navigationEntry = typeof window.performance.getEntriesByType === 'function'
            ? window.performance.getEntriesByType('navigation')[0]
            : null;
        if (navigationEntry && typeof navigationEntry.duration === 'number' && navigationEntry.duration > 0) {
            return navigationEntry.duration;
        }

        const timing = window.performance.timing;
        if (timing && typeof timing.navigationStart === 'number' && timing.navigationStart > 0) {
            const completedAt = typeof timing.loadEventEnd === 'number' && timing.loadEventEnd > 0
                ? timing.loadEventEnd
                : Date.now();
            const duration = completedAt - timing.navigationStart;

            if (Number.isFinite(duration) && duration > 0) {
                return duration;
            }
        }

        const nowDuration = typeof window.performance.now === 'function'
            ? window.performance.now()
            : null;
        if (Number.isFinite(nowDuration) && nowDuration > 0) {
            return nowDuration;
        }

        return null;
    }

    function renderPageLoadTime() {
        const node = document.getElementById('page-load-time');
        if (!(node instanceof HTMLElement)) {
            return;
        }

        const duration = resolvePageLoadDurationMs();
        if (!Number.isFinite(duration) || duration <= 0) {
            node.textContent = 'Page load time unavailable';
            return;
        }

        node.textContent = `Page loaded in ${(duration / 1000).toFixed(2)}s`;
    }

    function updateSidebarToggleState(toggleButton) {
        if (!(toggleButton instanceof HTMLButtonElement)) {
            return;
        }

        toggleButton.setAttribute(
            'aria-expanded',
            body.classList.contains('sidebar-collapsed') ? 'false' : 'true'
        );
    }

    function updateNavScrollHints(shell) {
        if (!(shell instanceof HTMLElement)) {
            return;
        }

        const navGroup = shell.querySelector('.nav-group');
        if (!(navGroup instanceof HTMLElement)) {
            shell.classList.remove('has-overflow-top', 'has-overflow-bottom');
            return;
        }

        const hasOverflowTop = navGroup.scrollTop > 2;
        const hasOverflowBottom = (navGroup.scrollTop + navGroup.clientHeight) < (navGroup.scrollHeight - 2);

        shell.classList.toggle('has-overflow-top', hasOverflowTop);
        shell.classList.toggle('has-overflow-bottom', hasOverflowBottom);
    }

    function centeredNavScrollTop(navLink) {
        if (!(navLink instanceof HTMLElement)) {
            return null;
        }

        const navGroup = navLink.closest('.nav-group');
        if (!(navGroup instanceof HTMLElement)) {
            return null;
        }

        const targetScrollTop = navLink.offsetTop - ((navGroup.clientHeight - navLink.offsetHeight) / 2);
        const maxScrollTop = Math.max(0, navGroup.scrollHeight - navGroup.clientHeight);

        return Math.max(0, Math.min(targetScrollTop, maxScrollTop));
    }

    function easeInOutCubic(progress) {
        if (progress < 0.5) {
            return 4 * progress * progress * progress;
        }

        return 1 - Math.pow(-2 * progress + 2, 3) / 2;
    }

    function animateNavScroll(navGroup, targetScrollTop, durationMs = 320) {
        if (!(navGroup instanceof HTMLElement)) {
            return Promise.resolve();
        }

        if (navGroup.dataset.navScrollAnimationFrame) {
            window.cancelAnimationFrame(Number(navGroup.dataset.navScrollAnimationFrame));
            delete navGroup.dataset.navScrollAnimationFrame;
        }

        const startScrollTop = navGroup.scrollTop;
        const distance = targetScrollTop - startScrollTop;

        if (Math.abs(distance) < 1 || durationMs <= 0) {
            navGroup.scrollTop = targetScrollTop;
            return Promise.resolve();
        }

        const startTime = window.performance && typeof window.performance.now === 'function'
            ? window.performance.now()
            : Date.now();

        return new Promise((resolve) => {
            const step = (now) => {
                const elapsed = now - startTime;
                const progress = Math.min(1, elapsed / durationMs);
                const easedProgress = easeInOutCubic(progress);

                navGroup.scrollTop = startScrollTop + (distance * easedProgress);

                if (progress < 1) {
                    navGroup.dataset.navScrollAnimationFrame = String(window.requestAnimationFrame(step));
                    return;
                }

                delete navGroup.dataset.navScrollAnimationFrame;
                navGroup.scrollTop = targetScrollTop;
                resolve();
            };

            navGroup.dataset.navScrollAnimationFrame = String(window.requestAnimationFrame(step));
        });
    }

    function centerNavLinkInView(navLink, behaviour = 'smooth') {
        if (!(navLink instanceof HTMLElement)) {
            return;
        }

        const navGroup = navLink.closest('.nav-group');
        if (!(navGroup instanceof HTMLElement)) {
            return;
        }

        const nextScrollTop = centeredNavScrollTop(navLink);
        if (!Number.isFinite(nextScrollTop)) {
            return;
        }

        if (behaviour === 'auto') {
            navGroup.scrollTop = nextScrollTop;
            return Promise.resolve();
        }

        return animateNavScroll(navGroup, nextScrollTop);
    }

    function initialiseSidebar(scope = document) {
        const sidebar = scope.querySelector ? scope.querySelector('#sidebar-shell') : null;
        if (!(sidebar instanceof HTMLElement)) {
            return;
        }

        const toggle = sidebar.querySelector('#sidebar-toggle');
        if (toggle instanceof HTMLButtonElement && toggle.dataset.sidebarToggleBound !== 'true') {
            toggle.addEventListener('click', () => {
                body.classList.toggle('sidebar-collapsed');
                updateSidebarToggleState(toggle);
                const navShell = sidebar.querySelector('.nav-scroll-shell');
                if (navShell instanceof HTMLElement) {
                    updateNavScrollHints(navShell);
                }
            });
            toggle.dataset.sidebarToggleBound = 'true';
        }

        updateSidebarToggleState(toggle);

        const navShell = sidebar.querySelector('.nav-scroll-shell');
        const navGroup = navShell instanceof HTMLElement ? navShell.querySelector('.nav-group') : null;

        if (navShell instanceof HTMLElement && navGroup instanceof HTMLElement) {
            navShell.classList.remove('is-ready');
            navShell.classList.remove('is-animated');

            const activeNavLink = navGroup.querySelector('.nav-link.active');

            if (activeNavLink instanceof HTMLElement) {
                centerNavLinkInView(activeNavLink, 'auto');
            }

            if (navGroup.dataset.navHintsBound !== 'true') {
                navGroup.addEventListener('scroll', () => {
                    updateNavScrollHints(navShell);
                }, { passive: true });

                window.addEventListener('resize', () => {
                    updateNavScrollHints(navShell);
                });

                navGroup.dataset.navHintsBound = 'true';
            }

            window.setTimeout(() => {
                updateNavScrollHints(navShell);
                navShell.classList.add('is-ready');
                window.requestAnimationFrame(() => {
                    navShell.classList.add('is-animated');
                });
            }, 0);
        }
    }

    function afStorageAvailable(storageName) {
        try {
            const storage = window[storageName];
            const probe = '__af_probe__';

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

    function afGetCookie(name) {
        const prefix = `${name}=`;
        const parts = document.cookie ? document.cookie.split(';') : [];

        for (const partValue of parts) {
            const part = partValue.trim();

            if (part.indexOf(prefix) === 0) {
                return decodeURIComponent(part.substring(prefix.length));
            }
        }

        return null;
    }

    function afSetCookie(name, value, maxAgeSeconds) {
        let cookie = `${name}=${encodeURIComponent(value)}; path=/; SameSite=Lax; max-age=${String(maxAgeSeconds)}`;

        if (window.location.protocol === 'https:') {
            cookie += '; Secure';
        }

        document.cookie = cookie;
    }

    function afGenerateUuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        const template = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx';

        return template.replace(/[xy]/g, (character) => {
            const random = Math.random() * 16 | 0;
            const value = character === 'x' ? random : (random & 0x3 | 0x8);

            return value.toString(16);
        });
    }

    function afGetDeviceId() {
        let resolvedId = null;

        if (afStorageAvailable('localStorage')) {
            try {
                let stored = window.localStorage.getItem(afStorageKey);

                if (stored) {
                    resolvedId = stored;
                } else {
                    stored = afGenerateUuid();
                    window.localStorage.setItem(afStorageKey, stored);
                    resolvedId = stored;
                }
            } catch (error) {
                // Fall through to cookie or in-memory storage.
            }
        }

        if (!resolvedId) {
            const cookieValue = afGetCookie(afPersistentCookieName);

            if (cookieValue) {
                resolvedId = cookieValue;
            }
        }

        if (!resolvedId && !afEphemeralDeviceId) {
            afEphemeralDeviceId = afGenerateUuid();
        }

        if (!resolvedId) {
            resolvedId = afEphemeralDeviceId;
        }

        if (resolvedId) {
            afSetCookie(afPersistentCookieName, resolvedId, 31536000);
        }

        return resolvedId;
    }

    function initialiseLoginCountdown() {
        const container = document.querySelector('[data-login-countdown]');
        if (!(container instanceof HTMLElement)) {
            return;
        }

        const valueNode = container.querySelector('[data-login-countdown-value]');
        const form = container.closest('form');
        const submit = form instanceof HTMLFormElement
            ? form.querySelector('[data-login-submit-disabled="true"], button[type="submit"]')
            : null;
        let remaining = Number.parseInt(container.dataset.loginCountdown || '0', 10);

        if (!Number.isFinite(remaining) || remaining <= 0 || !(valueNode instanceof HTMLElement)) {
            return;
        }

        if (submit instanceof HTMLButtonElement) {
            submit.disabled = true;
        }

        const tick = () => {
            valueNode.textContent = String(Math.max(0, remaining));

            if (remaining <= 0) {
                container.remove();

                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                    submit.removeAttribute('data-login-submit-disabled');
                }

                return;
            }

            remaining -= 1;
            window.setTimeout(tick, 1000);
        };

        tick();
    }

    function normaliseDigitsOnlyInput(input) {
        if (!(input instanceof HTMLInputElement) || input.dataset.digitsOnly !== 'true') {
            return;
        }

        const maxLength = Number.parseInt(input.getAttribute('maxlength') || '0', 10);
        const digits = input.value.replace(/\D/g, '');
        const nextValue = maxLength > 0 ? digits.substring(0, maxLength) : digits;

        if (input.value !== nextValue) {
            input.value = nextValue;
        }
    }

    function afFormatTimezone() {
        const offsetMinutes = -new Date().getTimezoneOffset();
        const sign = offsetMinutes >= 0 ? '+' : '-';
        const absoluteMinutes = Math.abs(offsetMinutes);
        const hours = String(Math.floor(absoluteMinutes / 60)).padStart(2, '0');
        const minutes = String(absoluteMinutes % 60).padStart(2, '0');

        return `UTC${sign}${hours}:${minutes}`;
    }

    function afBuildPairString(values) {
        const parts = [];

        Object.keys(values).forEach((key) => {
            const value = values[key];

            if (value === null || value === undefined || value === '') {
                return;
            }

            parts.push(`${key}=${String(value)}`);
        });

        return parts.join('&');
    }

    function afIsSameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (error) {
            return false;
        }
    }

    function afApplyHeaders(headers, values) {
        Object.keys(values).forEach((fieldName) => {
            const value = values[fieldName];
            const headerName = afHeaderMap[fieldName];

            if (!value || !headerName) {
                return;
            }

            headers.set(headerName, value);
        });
    }

    async function afGatherAntiFraudValues() {
        const screenValue = window.screen || null;
        const screenWidth = screenValue && typeof screenValue.width === 'number' ? screenValue.width : null;
        const screenHeight = screenValue && typeof screenValue.height === 'number' ? screenValue.height : null;
        const colourDepth = screenValue && typeof screenValue.colorDepth === 'number' ? screenValue.colorDepth : null;
        const pixelRatio = typeof window.devicePixelRatio === 'number' ? window.devicePixelRatio : null;
        const innerWidth = typeof window.innerWidth === 'number' ? window.innerWidth : null;
        const innerHeight = typeof window.innerHeight === 'number' ? window.innerHeight : null;

        return {
            'Client-Browser-JS-User-Agent': navigator.userAgent || null,
            'Client-Device-ID': afGetDeviceId(),
            'Client-Screens': afBuildPairString({
                width: screenWidth,
                height: screenHeight,
                'scaling-factor': pixelRatio,
                'colour-depth': colourDepth,
            }) || null,
            'Client-Timezone': afFormatTimezone(),
            'Client-Window-Size': afBuildPairString({
                width: innerWidth,
                height: innerHeight,
            }) || null,
        };
    }

    async function afBuildHeaders(url, optionsHeaders) {
        const headers = new Headers(optionsHeaders || {});
        headers.set('X-Requested-With', 'XMLHttpRequest');
        headers.set('Accept', 'application/json');

        if (afIsSameOrigin(url)) {
            const values = await afGatherAntiFraudValues();
            afApplyHeaders(headers, values);
        }

        return headers;
    }

    function createAjaxError(status, payload = null) {
        const error = new Error(`Request failed with status ${status}`);

        error.status = status;
        error.payload = payload;

        return error;
    }

    function loadAjaxNonceBootstrap() {
        const node = document.getElementById(ajaxNonceBootstrapId);
        if (!(node instanceof HTMLElement)) {
            return;
        }

        try {
            const payload = JSON.parse(node.dataset.noncePayload || '{}');
            replaceAjaxNoncePool(payload?.nonce_pool);
        } catch (error) {
            console.error(error);
        }
    }

    function replaceAjaxNoncePool(noncePool) {
        ajaxNonceState.available = Array.isArray(noncePool)
            ? noncePool
                .map((nonce) => String(nonce || '').trim())
                .filter((nonce) => nonce !== '')
            : [];
        ajaxNonceState.inFlight.clear();
    }

    function appendAjaxNonce(nonce) {
        const value = String(nonce || '').trim();
        if (value === '') {
            return;
        }

        if (!ajaxNonceState.available.includes(value) && !ajaxNonceState.inFlight.has(value)) {
            ajaxNonceState.available.push(value);
        }
    }

    function requiresAjaxNonce(method, payload) {
        const methodName = String(method || 'GET').toUpperCase();

        if (methodName !== 'POST' || !payload || typeof payload !== 'object') {
            return false;
        }

        const ajaxFlag = String(payload._ajax || '').trim();
        const action = String(payload.action || '').trim();
        const cardAction = String(payload.card_action || '').trim();
        const tableExportPrepare = String(payload._table_export_prepare || '').trim();

        return ajaxFlag === '1' && (action !== '' || cardAction !== '' || tableExportPrepare !== '');
    }

    function reserveAjaxNonce() {
        const nonce = String(ajaxNonceState.available.shift() || '').trim();

        if (nonce === '') {
            return null;
        }

        ajaxNonceState.inFlight.add(nonce);

        return nonce;
    }

    function restoreAjaxNonce(nonce) {
        const value = String(nonce || '').trim();
        if (value === '') {
            return;
        }

        ajaxNonceState.inFlight.delete(value);
        if (!ajaxNonceState.available.includes(value)) {
            ajaxNonceState.available.unshift(value);
        }
    }

    function completeAjaxNonce(usedNonce, replacementNonce) {
        const usedValue = String(usedNonce || '').trim();
        const replacementValue = String(replacementNonce || '').trim();

        if (usedValue !== '') {
            ajaxNonceState.inFlight.delete(usedValue);
        }

        if (replacementValue !== '') {
            appendAjaxNonce(replacementValue);
        }
    }

    function normaliseAjaxErrors(payload) {
        if (!payload || !Array.isArray(payload.errors)) {
            return [];
        }

        return payload.errors
            .map((message) => String(message).trim())
            .filter((message) => message !== '');
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function escapeCssIdentifier(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(String(value));
        }

        return String(value).replace(/["\\]/g, '\\$&');
    }

    function tableCondensedStorageKey(toggle) {
        if (!(toggle instanceof HTMLButtonElement)) {
            return '';
        }

        const tableKey = String(toggle.dataset.tableKey || '').trim();

        return tableKey !== '' ? `${tableCondensedStoragePrefix}${tableKey}` : '';
    }

    function findCondensedTableTarget(toggle) {
        if (!(toggle instanceof HTMLButtonElement)) {
            return null;
        }

        const toolbar = toggle.closest('.card-toolbar');
        let sibling = toolbar instanceof HTMLElement ? toolbar.nextElementSibling : null;

        while (sibling instanceof HTMLElement) {
            if (sibling.matches('.table-scroll, .table-scroll-mini, table')) {
                return sibling;
            }

            const tableTarget = sibling.querySelector('.table-scroll, .table-scroll-mini, table');
            if (tableTarget instanceof HTMLElement) {
                return tableTarget;
            }

            sibling = sibling.nextElementSibling;
        }

        return null;
    }

    function tableCondensedEnabled(toggle) {
        const key = tableCondensedStorageKey(toggle);
        if (key === '' || !afStorageAvailable('localStorage')) {
            return false;
        }

        try {
            return window.localStorage.getItem(key) === '1';
        } catch (error) {
            return false;
        }
    }

    function setTableCondensed(toggle, condensed, persist = true) {
        if (!(toggle instanceof HTMLButtonElement)) {
            return;
        }

        const enabled = Boolean(condensed);
        const target = findCondensedTableTarget(toggle);

        if (target instanceof HTMLElement) {
            target.classList.toggle('table-condensed', enabled);
        }

        toggle.classList.toggle('primary', enabled);
        toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');

        const key = tableCondensedStorageKey(toggle);
        if (!persist || key === '' || !afStorageAvailable('localStorage')) {
            return;
        }

        try {
            window.localStorage.setItem(key, enabled ? '1' : '0');
        } catch (error) {
            // Storage may be disabled; the current page state has still been updated.
        }
    }

    function initialiseTableCondensedControls(root = document) {
        const toggles = root.querySelectorAll ? root.querySelectorAll('.table-condensed-toggle') : [];

        toggles.forEach((toggle) => {
            if (!(toggle instanceof HTMLButtonElement)) {
                return;
            }

            setTableCondensed(toggle, tableCondensedEnabled(toggle), false);

            if (toggle.dataset.tableCondensedBound === '1') {
                return;
            }

            toggle.addEventListener('click', () => {
                setTableCondensed(toggle, !toggle.classList.contains('primary'));
            });
            toggle.dataset.tableCondensedBound = '1';
        });
    }

    function renderErrorFlashHtml(payload) {
        const errors = normaliseAjaxErrors(payload);

        if (errors.length === 0) {
            return '';
        }

        return errors
            .map((message) => `<div class="alert error">${escapeHtml(message)}</div>`)
            .join('');
    }

    async function sendXhr(url, options = {}) {
        const headers = await afBuildHeaders(url, options.headers);

        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open(options.method || 'GET', url, true);

            headers.forEach((value, name) => {
                try {
                    xhr.setRequestHeader(name, value);
                } catch (error) {
                    // Ignore header-setting errors so the request can still continue.
                }
            });

            if (xhr.upload && typeof options.onUploadProgress === 'function') {
                xhr.upload.addEventListener('progress', options.onUploadProgress);
            }

            xhr.onload = () => {
                let payload = null;

                try {
                    payload = xhr.responseText !== '' ? JSON.parse(xhr.responseText) : null;
                } catch (error) {
                    if (xhr.status < 200 || xhr.status >= 300) {
                        reject(createAjaxError(xhr.status));
                        return;
                    }

                    reject(error);
                    return;
                }

                if (xhr.status < 200 || xhr.status >= 300) {
                    reject(createAjaxError(xhr.status, payload));
                    return;
                }

                resolve(payload);
            };

            xhr.onerror = () => reject(new Error('Request failed.'));
            xhr.send(options.body ?? null);
        });
    }

    async function sendAjax(url, options = {}) {
        options = ajaxOptionsWithSiteContext(options);

        if (options.transport === 'xhr') {
            return sendXhr(url, options);
        }

        const headers = await afBuildHeaders(url, options.headers);
        const response = await fetch(url, {
            ...options,
            credentials: 'same-origin',
            headers,
        });

        const payload = await response.json();

        if (!response.ok) {
            throw createAjaxError(response.status, payload);
        }

        return payload;
    }

    function ajaxOptionsWithSiteContext(options = {}) {
        const method = String(options.method || 'GET').toUpperCase();
        if (method === 'GET' || typeof options.body !== 'string') {
            return options;
        }

        const contentType = ajaxOptionsContentType(options.headers);
        if (!contentType.includes('application/json')) {
            return options;
        }

        try {
            const payload = JSON.parse(options.body || '{}');
            if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
                return options;
            }

            appendSiteContextSelectionsToPayload(payload);

            return {
                ...options,
                body: JSON.stringify(payload),
            };
        } catch (error) {
            return options;
        }
    }

    function ajaxOptionsContentType(headers) {
        if (headers instanceof Headers) {
            return String(headers.get('Content-Type') || '').toLowerCase();
        }

        if (!headers || typeof headers !== 'object') {
            return '';
        }

        const entries = Object.entries(headers);
        const match = entries.find(([name]) => String(name).toLowerCase() === 'content-type');

        return String(match ? match[1] : '').toLowerCase();
    }

    function formRequestUrl(form) {
        const action = form.getAttribute('action');

        if (typeof action === 'string' && action.trim() !== '') {
            return action;
        }

        return window.location.href;
    }

    function requestUrlWithFormData(url, formData) {
        const requestUrl = new URL(url, window.location.href);

        formData.forEach((value, key) => {
            requestUrl.searchParams.delete(key);
        });

        formData.forEach((value, key) => {
            requestUrl.searchParams.append(key, String(value));
        });

        return requestUrl.toString();
    }

    function currentPageId() {
        const main = document.querySelector('main[data-current-page]');

        return main instanceof HTMLElement ? String(main.dataset.currentPage || '').trim() : '';
    }

    function navigateToAjaxPayloadPage(payload) {
        const nextPage = String(payload?.page || '').trim();
        const nextUrl = String(payload?.url || '').trim();

        if (nextPage === '' || nextUrl === '' || nextPage === currentPageId()) {
            return false;
        }

        window.location.href = nextUrl;

        return true;
    }

    function triggerFileDownload(url) {
        const downloadUrl = String(url || '').trim();
        if (downloadUrl === '') {
            return;
        }

        const link = document.createElement('a');
        link.href = downloadUrl;
        link.rel = 'noopener';
        link.hidden = true;
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    function appendCurrentPageCardKeys(formData, form = null) {
        if (!(formData instanceof FormData)) {
            return;
        }

        formData.delete('cards[]');
        formData.delete('cards');

        if (form instanceof HTMLFormElement && form.dataset.invalidatePage === 'true') {
            return;
        }

        const cardNodes = document.querySelectorAll('.card[data-card-key]');

        cardNodes.forEach((node) => {
            if (!(node instanceof HTMLElement)) {
                return;
            }

            const cardKey = (node.dataset.cardKey || '').trim();

            if (cardKey !== '') {
                formData.append('cards[]', cardKey);
            }
        });
    }

    function appendRequestedVisibleCard(formData, submitter) {
        if (!(formData instanceof FormData)) {
            return;
        }

        const cardKey = submitter instanceof HTMLElement
            ? String(submitter.dataset.showCard || '').trim()
            : '';

        if (cardKey !== '') {
            formData.set('show_card', cardKey);
        }
    }

    function collectSiteContextSelections() {
        const selections = [];
        const selects = document.querySelectorAll('.site-context-slot select[data-site-context-key]');

        selects.forEach((select) => {
            if (!(select instanceof HTMLSelectElement)) {
                return;
            }

            const key = String(select.dataset.siteContextKey || '').trim();
            if (key === '') {
                return;
            }

            const inputName = normaliseSiteContextInputName(select.dataset.siteContextInputName);
            selections.push({
                key,
                inputName,
                value: String(select.value ?? ''),
            });
        });

        return selections;
    }

    function normaliseSiteContextInputName(inputName) {
        const value = String(inputName || '').trim();

        return /^[A-Za-z_][A-Za-z0-9_]*$/.test(value) ? value : '';
    }

    function appendSiteContextSelectionsToFormData(formData, form = null) {
        if (!(formData instanceof FormData)) {
            return;
        }

        formData.delete('site_context_keys[]');
        formData.delete('site_context_keys');
        formData.delete('site_context_values[]');
        formData.delete('site_context_values');

        collectSiteContextSelections().forEach((selection) => {
            formData.append('site_context_keys[]', selection.key);
            formData.append('site_context_values[]', selection.value);

            if (selection.inputName !== '' && !formHasEnabledNamedField(form, selection.inputName)) {
                formData.set(selection.inputName, selection.value);
            }
        });
    }

    function syncSiteContextFieldsToForm(form) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        form.querySelectorAll('input[data-site-context-submit-field="true"]').forEach((node) => {
            node.remove();
        });

        collectSiteContextSelections().forEach((selection) => {
            const keyField = document.createElement('input');
            keyField.type = 'hidden';
            keyField.name = 'site_context_keys[]';
            keyField.value = selection.key;
            keyField.dataset.siteContextSubmitField = 'true';

            const valueField = document.createElement('input');
            valueField.type = 'hidden';
            valueField.name = 'site_context_values[]';
            valueField.value = selection.value;
            valueField.dataset.siteContextSubmitField = 'true';

            form.append(keyField, valueField);

            if (selection.inputName !== '' && !formHasEnabledNamedField(form, selection.inputName)) {
                const namedField = document.createElement('input');
                namedField.type = 'hidden';
                namedField.name = selection.inputName;
                namedField.value = selection.value;
                namedField.dataset.siteContextSubmitField = 'true';
                form.append(namedField);
            }
        });
    }

    function appendSiteContextSelectionsToPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const selections = collectSiteContextSelections();
        if (selections.length === 0) {
            delete payload.site_context_keys;
            delete payload.site_context_values;
            return;
        }

        payload.site_context_keys = selections.map((selection) => selection.key);
        payload.site_context_values = selections.map((selection) => selection.value);

        selections.forEach((selection) => {
            if (selection.inputName !== '' && !Object.prototype.hasOwnProperty.call(payload, selection.inputName)) {
                payload[selection.inputName] = selection.value;
            }
        });
    }

    function formHasEnabledNamedField(form, fieldName) {
        if (!(form instanceof HTMLFormElement)) {
            return false;
        }

        const escapedName = escapeCssIdentifier(fieldName);
        const fields = form.querySelectorAll(`[name="${escapedName}"]`);

        return Array.from(fields).some((field) => {
            if (!(field instanceof HTMLInputElement)
                && !(field instanceof HTMLSelectElement)
                && !(field instanceof HTMLTextAreaElement)
                && !(field instanceof HTMLButtonElement)) {
                return false;
            }

            return !field.disabled && field.dataset.siteContextSubmitField !== 'true';
        });
    }

    function resolveSelfVisibleCardField(form) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const field = form.querySelector('input[name="show_card"]');
        const requestedCard = field instanceof HTMLInputElement
            ? String(field.value || '').trim()
            : '';

        if (requestedCard !== '.self') {
            return;
        }

        const card = form.closest('.card[data-card-key]');
        const cardKey = card instanceof HTMLElement
            ? String(card.dataset.cardKey || '').trim()
            : '';

        if (cardKey !== '') {
            field.value = cardKey;
        }
    }

    function formDataToJsonPayload(formData) {
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

    function handleAjaxSecurityFailure(payload) {
        if (!payload || !payload.reload_required) {
            return;
        }

        window.setTimeout(() => {
            window.location.reload();
        }, 150);
    }

    function initStateWatchers(root = document) {
        const nodes = root.querySelectorAll ? root.querySelectorAll('[data-state-fields]') : [];

        nodes.forEach((node) => {
            if (!(node instanceof HTMLElement) || node.dataset.stateBound === '1') {
                return;
            }

            const fieldIds = (node.dataset.stateFields || '')
                .split(',')
                .map((value) => value.trim())
                .filter((value) => value !== '');
            const targetId = node.dataset.stateTarget || '';
            const target = document.getElementById(targetId);

            if (!(target instanceof HTMLButtonElement) || fieldIds.length === 0) {
                return;
            }

            const fields = fieldIds
                .map((id) => document.getElementById(id))
                .filter((field) => field instanceof HTMLElement);

            if (fields.length === 0) {
                return;
            }

            const defaults = new Map();
            const stateValue = (field) => {
                if (field instanceof HTMLInputElement && field.type === 'checkbox') {
                    return field.checked ? '1' : '0';
                }

                return field.value;
            };

            fields.forEach((field) => {
                defaults.set(field, field.dataset.stateDefault ?? stateValue(field));
            });

            const sync = () => {
                const changed = fields.some((field) => stateValue(field) !== defaults.get(field));
                target.disabled = !changed;
            };

            fields.forEach((field) => {
                field.addEventListener('change', sync);
                field.addEventListener('input', sync);
            });

            sync();
            node.dataset.stateBound = '1';
        });
    }

    function isFormControl(node) {
        return node instanceof HTMLInputElement
            || node instanceof HTMLSelectElement
            || node instanceof HTMLTextAreaElement
            || node instanceof HTMLButtonElement;
    }

    function visibleWhenFieldSelector(fieldName) {
        const escapedAttributeValue = String(fieldName).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
        const escapedFieldName = escapeCssIdentifier(fieldName);

        return `[name="${escapedAttributeValue}"], #${escapedFieldName}`;
    }

    function visibleWhenSourceScope(target) {
        if (!(target instanceof HTMLElement)) {
            return document;
        }

        const form = target.closest('form');

        return form instanceof HTMLFormElement ? form : document;
    }

    function visibleWhenSourceControls(target) {
        if (!(target instanceof HTMLElement)) {
            return [];
        }

        const fieldName = String(target.dataset.visibleWhenField || '').trim();
        if (fieldName === '') {
            return [];
        }

        try {
            return Array.from(visibleWhenSourceScope(target).querySelectorAll(visibleWhenFieldSelector(fieldName)))
                .filter((node) => isFormControl(node));
        } catch (error) {
            console.error('Failed to resolve visible-when source field.', error);

            return [];
        }
    }

    function visibleWhenControlValues(control) {
        if (control instanceof HTMLSelectElement && control.multiple) {
            return Array.from(control.selectedOptions).map((option) => option.value);
        }

        if (control instanceof HTMLInputElement && (control.type === 'checkbox' || control.type === 'radio')) {
            return control.checked ? [control.value] : [];
        }

        return [control.value ?? ''];
    }

    function visibleWhenFieldMatches(target) {
        const expectedValue = String(target.dataset.visibleWhenValue ?? '');
        const controls = visibleWhenSourceControls(target);

        return controls.some((control) => visibleWhenControlValues(control).includes(expectedValue));
    }

    function restoreVisibleWhenControl(control) {
        if (!isFormControl(control) || control.dataset.visibleWhenDisabled !== '1') {
            return;
        }

        control.disabled = control.dataset.visibleWhenWasDisabled === 'true';
        delete control.dataset.visibleWhenDisabled;
        delete control.dataset.visibleWhenWasDisabled;
    }

    function syncVisibleWhenTarget(target) {
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const visible = visibleWhenFieldMatches(target);
        const disableNestedControls = String(target.dataset.visibleWhenDisableControls || '').trim().toLowerCase() !== 'false';
        const nestedControls = target.querySelectorAll('input, select, textarea, button');

        target.hidden = !visible;
        target.setAttribute('aria-hidden', visible ? 'false' : 'true');

        nestedControls.forEach((control) => {
            if (!isFormControl(control)) {
                return;
            }

            if (visible || !disableNestedControls) {
                restoreVisibleWhenControl(control);
                return;
            }

            if (control.dataset.visibleWhenDisabled !== '1') {
                control.dataset.visibleWhenWasDisabled = control.disabled ? 'true' : 'false';
            }

            control.dataset.visibleWhenDisabled = '1';
            control.disabled = true;
        });
    }

    function syncVisibleWhenField(field) {
        if (!isFormControl(field)) {
            return;
        }

        const identifiers = new Set();
        const fieldName = String(field.getAttribute('name') || '').trim();
        const fieldId = String(field.id || '').trim();

        if (fieldName !== '') {
            identifiers.add(fieldName);
        }

        if (fieldId !== '') {
            identifiers.add(fieldId);
        }

        if (identifiers.size === 0) {
            return;
        }

        document.querySelectorAll('[data-visible-when-field]').forEach((target) => {
            if (!(target instanceof HTMLElement)) {
                return;
            }

            const targetFieldName = String(target.dataset.visibleWhenField || '').trim();
            if (identifiers.has(targetFieldName)) {
                syncVisibleWhenTarget(target);
            }
        });
    }

    function initialiseVisibleWhenControls(root = document) {
        const targets = [];

        if (root instanceof HTMLElement && root.matches('[data-visible-when-field]')) {
            targets.push(root);
        }

        if (root.querySelectorAll) {
            root.querySelectorAll('[data-visible-when-field]').forEach((node) => {
                targets.push(node);
            });
        }

        targets.forEach((target) => {
            if (target instanceof HTMLElement) {
                syncVisibleWhenTarget(target);
            }
        });
    }

    function initialiseDirtyActionControls(root = document) {
        const fields = root.querySelectorAll ? root.querySelectorAll('[data-dirty-action-target]') : [];

        fields.forEach((field) => {
            if (
                !(
                    field instanceof HTMLInputElement
                    || field instanceof HTMLSelectElement
                    || field instanceof HTMLTextAreaElement
                )
            ) {
                return;
            }

            const sync = () => {
                const targetSelector = String(field.dataset.dirtyActionTarget || '').trim();
                if (targetSelector === '') {
                    return;
                }

                const formId = String(field.getAttribute('form') || '').trim();
                const scope = formId !== ''
                    ? document.getElementById(formId)
                    : field.closest('form');

                if (!(scope instanceof HTMLElement)) {
                    return;
                }

                const initialValue = field.dataset.initialValue ?? field.defaultValue ?? '';
                const currentValue = field.value ?? '';
                const hasChanged = currentValue !== initialValue;
                const hasRequiredValue = field.dataset.dirtyRequireValue === '1'
                    ? currentValue.trim() !== ''
                    : true;

                try {
                    scope.querySelectorAll(targetSelector).forEach((button) => {
                        if (!(button instanceof HTMLButtonElement)) {
                            return;
                        }

                        const enableMode = String(button.dataset.dirtyEnableMode || field.dataset.dirtyEnableMode || 'changed').trim();
                        const enabled = enableMode === 'selected'
                            ? hasRequiredValue
                            : hasChanged && hasRequiredValue;

                        button.disabled = !enabled;
                        syncButtonTitleVisibility(button);
                    });
                } catch (error) {
                    console.error('Failed to sync dirty action controls.', error);
                }
            };

            sync();

            if (field.dataset.dirtyActionBound === '1') {
                return;
            }

            field.dataset.dirtyActionBound = '1';
            field.addEventListener('change', sync);
            field.addEventListener('input', sync);
        });
    }

    function triggerStateSync(field) {
        if (!(field instanceof HTMLElement)) {
            return;
        }

        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function initDangerZoneConfirmationControls(root = document) {
        const forms = root.querySelectorAll ? root.querySelectorAll('form[data-ajax="true"]') : [];

        forms.forEach((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const clearInput = form.querySelector('[data-clear-confirm-input]');
            const clearButton = form.querySelector('#clear-imported-data-button');
            const deleteCheckbox = form.querySelector('[data-delete-confirm-checkbox]');
            const deleteInput = form.querySelector('[data-delete-confirm-input]');
            const deleteButton = form.querySelector('[data-delete-confirm-button]');

            if (
                !(clearInput instanceof HTMLInputElement)
                && !(deleteCheckbox instanceof HTMLInputElement)
                && !(deleteInput instanceof HTMLInputElement)
            ) {
                return;
            }

            const syncExpectedValueConfirmation = (input, button, options = {}) => {
                if (!(input instanceof HTMLInputElement) || !(button instanceof HTMLButtonElement)) {
                    return;
                }

                const controllingCheckbox = options.checkbox instanceof HTMLInputElement ? options.checkbox : null;
                const enabled = controllingCheckbox === null ? true : controllingCheckbox.checked;
                const expectedValue = String(input.dataset.expectedValue || '').trim();
                const enteredValue = input.value.trim();

                input.disabled = !enabled;
                if (enabled) {
                    input.removeAttribute('disabled');
                } else {
                    input.setAttribute('disabled', 'disabled');
                }

                if (!enabled && options.clearWhenDisabled !== false) {
                    input.value = '';
                }

                button.disabled = !enabled || expectedValue === '' || enteredValue !== expectedValue;
            };

            const syncClearConfirmation = () => {
                syncExpectedValueConfirmation(clearInput, clearButton, {
                    clearWhenDisabled: false,
                });
            };

            const syncDeleteConfirmation = () => {
                syncExpectedValueConfirmation(deleteInput, deleteButton, {
                    checkbox: deleteCheckbox,
                });
            };

            if (form.dataset.dangerZoneBound !== '1') {
                if (clearInput instanceof HTMLInputElement) {
                    clearInput.addEventListener('input', syncClearConfirmation);
                    clearInput.addEventListener('change', syncClearConfirmation);
                }

                if (deleteCheckbox instanceof HTMLInputElement) {
                    deleteCheckbox.addEventListener('change', syncDeleteConfirmation);
                }

                if (deleteInput instanceof HTMLInputElement) {
                    deleteInput.addEventListener('input', syncDeleteConfirmation);
                    deleteInput.addEventListener('change', syncDeleteConfirmation);
                }

                form.dataset.dangerZoneBound = '1';
            }

            if (deleteCheckbox instanceof HTMLInputElement && !deleteCheckbox.checked) {
                deleteInput?.setAttribute('disabled', 'disabled');
            }

            syncClearConfirmation();
            syncDeleteConfirmation();

            window.requestAnimationFrame(() => {
                syncClearConfirmation();
                syncDeleteConfirmation();
            });
        });
    }

    function updateUploadSelection(dropzone, input) {
        if (!(dropzone instanceof HTMLElement) || !(input instanceof HTMLInputElement)) {
            return;
        }

        const files = input.files ? Array.from(input.files) : [];
        const form = dropzone.closest('form');
        const scope = form instanceof HTMLFormElement ? form : dropzone;
        const list = scope.querySelector('[data-upload-file-list]');
        const summary = scope.querySelector('[data-upload-selection-summary]');
        const maxFiles = Number(dropzone.dataset.uploadMaxFiles || '12');
        const fileLabel = String(dropzone.dataset.uploadFileLabel || 'file').trim() || 'file';
        const maxReached = files.length > maxFiles;

        if (summary instanceof HTMLElement) {
            if (files.length === 0) {
                summary.textContent = 'No files selected yet.';
            } else if (maxReached) {
                summary.textContent = `Too many files selected.\nPlease keep it to ${String(maxFiles)} ${fileLabel}${maxFiles === 1 ? '' : 's'} or fewer.`;
            } else {
                summary.textContent = `${String(files.length)} file${files.length > 1 ? 's' : ''} selected:`;
            }
        }

        if (!(list instanceof HTMLElement)) {
            return;
        }

        list.innerHTML = '';

        if (files.length === 0) {
            list.hidden = true;
            return;
        }

        files.forEach((file) => {
            const item = document.createElement('li');
            item.textContent = file.name || 'Unnamed file';
            list.appendChild(item);
        });

        list.hidden = false;
    }

    function assignUploadFiles(input, files) {
        if (!(input instanceof HTMLInputElement) || !files || typeof DataTransfer !== 'function') {
            return false;
        }

        const dataTransfer = new DataTransfer();

        Array.from(files).forEach((file) => {
            dataTransfer.items.add(file);
        });

        input.files = dataTransfer.files;
        return true;
    }

    function syncUploadSubmitState(form, input, accountSelect) {
        if (!(form instanceof HTMLFormElement) || !(input instanceof HTMLInputElement)) {
            return;
        }

        const submitButton = form.querySelector('[data-upload-submit]');
        if (!(submitButton instanceof HTMLButtonElement)) {
            return;
        }

        const hasAccount = accountSelect instanceof HTMLSelectElement
            ? String(accountSelect.value || '').trim() !== ''
            : true;
        const hasFiles = input.files instanceof FileList && input.files.length > 0;
        const shouldDisable = !hasAccount || !hasFiles;

        submitButton.disabled = shouldDisable;
        syncButtonTitleVisibility(submitButton);
    }

    function initialiseUploadDropzones(root = document) {
        const dropzones = root.querySelectorAll ? root.querySelectorAll('[data-upload-dropzone]') : [];

        dropzones.forEach((dropzone) => {
            if (!(dropzone instanceof HTMLElement)) {
                return;
            }

            const form = dropzone.closest('form');
            const input = form instanceof HTMLFormElement
                ? form.querySelector('[data-upload-input]')
                : null;
            const accountSelect = form instanceof HTMLFormElement ? form.querySelector('#upload_account_id') : null;
            let dragDepth = 0;

            if (!(input instanceof HTMLInputElement)) {
                return;
            }

            updateUploadSelection(dropzone, input);
            syncUploadSubmitState(form, input, accountSelect);

            if (dropzone.dataset.uploadBound === '1') {
                return;
            }

            dropzone.dataset.uploadBound = '1';

            input.addEventListener('change', () => {
                updateUploadSelection(dropzone, input);
                syncUploadSubmitState(form, input, accountSelect);
            });

            dropzone.addEventListener('dragenter', (event) => {
                event.preventDefault();
                event.stopPropagation();
                dragDepth += 1;
                dropzone.classList.add('is-dragover');
            });

            dropzone.addEventListener('dragover', (event) => {
                event.preventDefault();
                event.stopPropagation();

                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'copy';
                }

                dropzone.classList.add('is-dragover');
            });

            ['dragleave', 'dragend'].forEach((eventName) => {
                dropzone.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    dragDepth = Math.max(0, dragDepth - 1);

                    if (dragDepth === 0) {
                        dropzone.classList.remove('is-dragover');
                    }
                });
            });

            dropzone.addEventListener('drop', (event) => {
                const droppedFiles = event.dataTransfer ? event.dataTransfer.files : null;

                event.preventDefault();
                event.stopPropagation();
                dragDepth = 0;
                dropzone.classList.remove('is-dragover');

                if (!droppedFiles || droppedFiles.length === 0) {
                    return;
                }

                if (!assignUploadFiles(input, droppedFiles)) {
                    return;
                }

                updateUploadSelection(dropzone, input);
                syncUploadSubmitState(form, input, accountSelect);
            });

            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            if (accountSelect instanceof HTMLSelectElement && accountSelect.dataset.uploadAccountBound !== '1') {
                accountSelect.dataset.uploadAccountBound = '1';

                accountSelect.addEventListener('invalid', () => {
                    accountSelect.classList.add('input-missing-required');
                });

                accountSelect.addEventListener('change', () => {
                    if (accountSelect.value) {
                        accountSelect.classList.remove('input-missing-required');
                    }

                    syncUploadSubmitState(form, input, accountSelect);
                });
            }

            if (form.dataset.uploadFormBound === '1') {
                return;
            }

            form.dataset.uploadFormBound = '1';

            form.addEventListener('submit', (event) => {
                const maxFiles = Number(dropzone.dataset.uploadMaxFiles || '12');
                const fileLabel = String(dropzone.dataset.uploadFileLabel || 'file').trim() || 'file';

                if (accountSelect instanceof HTMLSelectElement && !accountSelect.value) {
                    accountSelect.classList.add('input-missing-required');
                }

                if (input.files && input.files.length > maxFiles) {
                    event.preventDefault();
                    window.alert(`Please upload no more than ${String(maxFiles)} ${fileLabel}${maxFiles === 1 ? '' : 's'} at once.`);
                }
            });
        });
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

    function rawUploadInput(form) {
        return form instanceof HTMLFormElement ? form.querySelector('[data-upload-input]') : null;
    }

    function rawUploadDropzone(form) {
        return form instanceof HTMLFormElement ? form.querySelector('[data-upload-dropzone]') : null;
    }

    function validateRawUploadForm(form) {
        const input = rawUploadInput(form);
        const dropzone = rawUploadDropzone(form);
        const maxFiles = Number(dropzone instanceof HTMLElement ? dropzone.dataset.uploadMaxFiles || '3' : '3');
        const files = input instanceof HTMLInputElement && input.files ? Array.from(input.files) : [];

        if (files.length === 0) {
            return 'Choose at least one CR2 file to upload.';
        }

        if (files.length > maxFiles) {
            return `Upload no more than ${String(maxFiles)} CR2 files at once.`;
        }

        const invalidFile = files.find((file) => !String(file.name || '').toLowerCase().endsWith('.cr2'));
        if (invalidFile) {
            return `${invalidFile.name || 'Selected file'} is not a CR2 file.`;
        }

        return '';
    }

    function resetRawUploadForm(form) {
        const input = rawUploadInput(form);
        const dropzone = rawUploadDropzone(form);
        const accountSelect = form instanceof HTMLFormElement ? form.querySelector('#upload_account_id') : null;

        if (input instanceof HTMLInputElement) {
            input.value = '';
        }

        updateUploadSelection(dropzone, input);
        syncUploadSubmitState(form, input, accountSelect);
    }

    function applyAjaxPagePayload(payload) {
        if (navigateToAjaxPayloadPage(payload)) {
            return;
        }

        applyAjaxPayloadFragment('sidebar', () => replaceSidebar(payload.sidebar_html));
        applyAjaxPayloadFragment('site context', () => replaceSiteContextSlots(payload.site_context_html));
        applyAjaxPayloadFragment('developer options status', () => replaceDeveloperOptionsStatus(payload.developer_options_status_html));
        applyAjaxPayloadFragment('cards', () => replaceCards(payload.cards));
        applyAjaxPayloadFragment('flash', () => replaceFlash(payload.flash_html));
        applyAjaxPayloadFragment('visible card', () => showPageCardTabForCard(payload.show_card));
    }

    function initialiseRawUploadForms(root = document) {
        const forms = root.querySelectorAll ? root.querySelectorAll('[data-raw-upload-form="true"]') : [];

        forms.forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.rawUploadBound === '1') {
                return;
            }

            form.dataset.rawUploadBound = '1';
            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                const validationError = validateRawUploadForm(form);
                if (validationError !== '') {
                    setRawUploadStatus(form, validationError, 'error');
                    return;
                }

                const formData = new FormData(form);
                formData.set('_ajax', '1');
                appendCurrentPageCardKeys(formData, form);
                appendSiteContextSelectionsToFormData(formData, form);

                const ajaxNonce = reserveAjaxNonce();
                if (ajaxNonce) {
                    formData.set('ajax_nonce', ajaxNonce);
                }

                const submitter = event.submitter instanceof HTMLButtonElement
                    ? event.submitter
                    : form.querySelector('[data-upload-submit]');
                const restoreProcessingState = beginButtonProcessingState(submitter);

                setRawUploadStatus(form, 'Uploading...', '');

                try {
                    const payload = await sendAjax(formRequestUrl(form), {
                        method: 'POST',
                        body: formData,
                        transport: 'xhr',
                        onUploadProgress: (progressEvent) => {
                            if (!progressEvent.lengthComputable || progressEvent.total <= 0) {
                                setRawUploadStatus(form, 'Uploading...', '');
                                return;
                            }

                            const percent = Math.max(0, Math.min(100, Math.round((progressEvent.loaded / progressEvent.total) * 100)));
                            setRawUploadStatus(form, `Uploading ${String(percent)}%...`, '');
                        },
                    });

                    completeAjaxNonce(ajaxNonce, payload?.ajax_nonce);
                    setRawUploadStatus(form, 'Upload complete.', 'success');
                    resetRawUploadForm(form);
                    applyAjaxPagePayload(payload);
                } catch (error) {
                    restoreAjaxNonce(ajaxNonce);
                    const flashHtml = error && error.payload && typeof error.payload.flash_html === 'string'
                        ? error.payload.flash_html
                        : renderErrorFlashHtml(error ? error.payload : null);

                    if (flashHtml !== '') {
                        replaceFlash(flashHtml);
                    }

                    setRawUploadStatus(form, 'Upload failed.', 'error');
                    handleAjaxSecurityFailure(error ? error.payload : null);
                    console.error(error);
                } finally {
                    restoreProcessingState();
                }
            });
        });
    }

    function syncPasswordRequirementPanel(panel) {
        if (!(panel instanceof HTMLElement)) {
            return;
        }

        const form = panel.closest('form');
        const inputId = String(panel.dataset.passwordRequirementsFor || '').trim();
        const passwordInput = inputId !== ''
            ? document.getElementById(inputId)
            : form instanceof HTMLFormElement
                ? form.querySelector('input[type="password"][pattern]')
                : null;

        if (!(passwordInput instanceof HTMLInputElement)) {
            return;
        }

        panel.hidden = passwordInput.value !== '' && passwordInput.validity.valid;
    }

    function initialisePasswordRequirementPanels(root = document) {
        const panels = root.querySelectorAll ? root.querySelectorAll('[data-password-requirements-panel]') : [];

        panels.forEach((panel) => {
            if (!(panel instanceof HTMLElement)) {
                return;
            }

            const form = panel.closest('form');
            const inputId = String(panel.dataset.passwordRequirementsFor || '').trim();
            const passwordInput = inputId !== ''
                ? document.getElementById(inputId)
                : form instanceof HTMLFormElement
                    ? form.querySelector('input[type="password"][pattern]')
                    : null;

            if (!(passwordInput instanceof HTMLInputElement)) {
                return;
            }

            syncPasswordRequirementPanel(panel);

            if (panel.dataset.passwordRequirementsBound === '1') {
                return;
            }

            panel.dataset.passwordRequirementsBound = '1';
            passwordInput.addEventListener('input', () => syncPasswordRequirementPanel(panel));
            passwordInput.addEventListener('change', () => syncPasswordRequirementPanel(panel));
        });
    }

    function syncSubmitAction(submitter) {
        if (!(submitter instanceof HTMLButtonElement) || !submitter.form) {
            return;
        }

        const actionValue = submitter.dataset.submitAction;
        if (typeof actionValue !== 'string' || actionValue === '') {
            return;
        }

        const actionField = submitter.form.querySelector('#settings_action_field');
        if (actionField instanceof HTMLInputElement) {
            actionField.value = actionValue;
        }
    }

    function syncSubmitField(submitter) {
        if (!(submitter instanceof HTMLButtonElement) || !submitter.form) {
            return;
        }

        const fieldName = String(submitter.dataset.submitField || '').trim();
        if (fieldName === '') {
            return;
        }

        const field = submitter.form.querySelector(`[name="${escapeCssIdentifier(fieldName)}"]`);
        if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
            field.value = String(submitter.dataset.submitValue ?? '1');
        }
    }

    function syncButtonTitleVisibility(root = document) {
        const buttons = root instanceof HTMLButtonElement
            ? [root]
            : (root.querySelectorAll ? Array.from(root.querySelectorAll('button')) : []);

        buttons.forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            if (button.dataset.preserveTitle === 'true') {
                return;
            }

            const currentTitle = String(button.getAttribute('title') || '').trim();
            if (currentTitle !== '' && !button.dataset.disabledTitle) {
                button.dataset.disabledTitle = currentTitle;
            }

            const disabledTitle = String(button.dataset.disabledTitle || '').trim();
            if (button.disabled) {
                if (disabledTitle !== '') {
                    button.setAttribute('title', disabledTitle);
                }
                return;
            }

            button.removeAttribute('title');
        });
    }

    function initialisePictureEditors(root = document) {
        const editors = root.querySelectorAll ? root.querySelectorAll('[data-picture-editor="true"]') : [];

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
            const profileState = editor.querySelector('[data-picture-editor-profile-state]');
            const profileUrl = String(editor.dataset.profileUrl || '').trim();
            const profileStatusUrl = String(editor.dataset.profileStatusUrl || '').trim();
            const sourceWidth = Math.max(1, Number.parseInt(String(editor.dataset.sourceWidth || '1'), 10));
            const sourceHeight = Math.max(1, Number.parseInt(String(editor.dataset.sourceHeight || '1'), 10));
            let imageNode = editor.querySelector('[data-picture-editor-image]');
            let requestSequence = 0;
            let submitTimer = null;
            let pollTimer = null;
            let baselinePollTimer = null;
            let dragState = null;
            let displayedPreviewStage = String(editor.dataset.previewType || '').trim();
            let displayedImageType = displayedPreviewStage;
            let baselineReady = editor.dataset.baselineReady === '1';

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
                return baselineReady && displayedPreviewStage !== 'thumbnail' && settings.crop.enabled;
            }

            function setStatus(message, state = '') {
                if (!(statusNode instanceof HTMLElement)) {
                    return;
                }

                statusNode.textContent = `Photo: ${message}`;
                statusNode.dataset.pictureEditorState = state;
            }

            function normaliseDisplayType(type) {
                const value = String(type || '').trim().toLowerCase();
                return ['embedded', 'thumbnail', 'original', 'filtered'].includes(value) ? value : '';
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
                    cropState.textContent = displayedPreviewStage === 'thumbnail'
                        ? 'Crop disabled while thumbnail preview is displayed.'
                        : (baselineReady ? 'Crop follows original/filtered previews.' : 'Crop waiting for baseline profile.');
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
                    const number = editor.querySelector(`[data-picture-editor-number="${escapeCssIdentifier(key)}"]`);

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
                editor.querySelectorAll('[data-picture-editor-field], [data-picture-editor-number], [data-picture-editor-check], [data-picture-editor-revert]').forEach((field) => {
                    if (field instanceof HTMLInputElement || field instanceof HTMLButtonElement) {
                        field.disabled = !enabled;
                    }
                });
                if (profileState instanceof HTMLElement) {
                    profileState.textContent = `Profile: ${enabled ? 'Ready' : 'Preparing'}`;
                    profileState.dataset.pictureEditorProfileReady = enabled ? '1' : '0';
                }
                renderCrop();
            }

            function clearPoll() {
                if (pollTimer !== null) {
                    window.clearTimeout(pollTimer);
                    pollTimer = null;
                }
            }

            function scheduleSubmit() {
                if (!baselineReady) {
                    return;
                }
                if (submitTimer !== null) {
                    window.clearTimeout(submitTimer);
                }

                setStatus('Queued', 'queued');
                submitTimer = window.setTimeout(() => {
                    submitTimer = null;
                    submitPreview();
                }, 500);
            }

            async function submitPreview() {
                const sequence = ++requestSequence;
                displayedPreviewStage = '';
                clearPoll();
                setStatus('Rendering', 'processing');

                try {
                    const response = await sendAjax(profileUrl, {
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

            async function pollPreviewStatus(statusUrl, sequence, attempt, startedAt) {
                if (sequence !== requestSequence || !editor.isConnected) {
                    return;
                }

                if ((Date.now() - startedAt) > 60000) {
                    setStatus('Timed out', 'failed');
                    return;
                }

                try {
                    const response = await sendAjax(statusUrl);

                    if (sequence !== requestSequence) {
                        return;
                    }

                    const state = String(response?.status || 'queued');
                    if (state === 'succeeded' && response?.preview_url) {
                        swapPreviewImage(String(response.preview_url), 'filtered');
                        setStatus('Ready', 'ready');
                        return;
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
                    imageNode.addEventListener('load', renderCrop);
                    stage.insertBefore(imageNode, cropNode);
                }

                if (emptyNode instanceof HTMLElement) {
                    emptyNode.remove();
                }

                imageNode.src = url;
                if (stageType !== '') {
                    displayedPreviewStage = stageType;
                    setDisplayType(stageType);
                    editor.dataset.previewType = stageType;
                    renderCrop();
                }
            }

            function revertToBaseline() {
                settings = cloneSettings(baselineSettings);
                settings.crop = normaliseCrop(settings.crop);
                syncControls();
                renderCrop();
                scheduleSubmit();
            }

            editor.querySelectorAll('[data-picture-editor-field]').forEach((field) => {
                if (!(field instanceof HTMLInputElement)) {
                    return;
                }

                const key = String(field.dataset.pictureEditorField || '');
                const number = editor.querySelector(`[data-picture-editor-number="${escapeCssIdentifier(key)}"]`);
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
                imageNode.addEventListener('load', renderCrop);
            }
            if (revertButton instanceof HTMLButtonElement) {
                revertButton.addEventListener('click', revertToBaseline);
            }
            window.addEventListener('resize', renderCrop);
            syncControls();
            setEditorEnabled(baselineReady);
            if (!baselineReady && profileStatusUrl !== '') {
                pollProfileStatus(0);
            }
            renderCrop();

            async function pollProfileStatus(attempt) {
                if (baselineReady || !editor.isConnected) {
                    return;
                }
                try {
                    const response = await sendAjax(profileStatusUrl);
                    const status = String(response?.baseline?.status || '');
                    if (response?.baseline?.ready && response?.settings) {
                        baselineReady = true;
                        settings = normaliseSettings(response.settings);
                        baselineSettings = cloneSettings(settings);
                        syncControls();
                        setEditorEnabled(true);
                        return;
                    }
                    if (profileState instanceof HTMLElement) {
                        profileState.textContent = `Profile: ${status === 'failed' ? 'Failed' : 'Preparing'}`;
                        profileState.dataset.pictureEditorProfileReady = '0';
                    }
                    const delay = attempt < 5 ? 1000 : 2500;
                    baselinePollTimer = window.setTimeout(() => pollProfileStatus(attempt + 1), delay);
                } catch (error) {
                    const delay = attempt < 5 ? 1500 : 3000;
                    baselinePollTimer = window.setTimeout(() => pollProfileStatus(attempt + 1), delay);
                    console.error(error);
                }
            }
        });
    }

    function initialiseButtonTitleVisibility() {
        let syncingButtonTitles = false;
        const syncSafely = (root = document) => {
            if (syncingButtonTitles) {
                return;
            }

            syncingButtonTitles = true;
            try {
                syncButtonTitleVisibility(root);
            } finally {
                syncingButtonTitles = false;
            }
        };

        syncSafely(document);

        if (body.dataset.buttonTitleObserverBound === '1') {
            return;
        }

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.type === 'attributes' && mutation.target instanceof HTMLButtonElement) {
                    syncSafely(mutation.target);
                    return;
                }

                mutation.addedNodes.forEach((node) => {
                    if (node instanceof HTMLButtonElement || node instanceof HTMLElement) {
                        syncSafely(node);
                    }
                });
            });
        });

        observer.observe(body, {
            attributes: true,
            attributeFilter: ['disabled'],
            childList: true,
            subtree: true,
        });

        body.dataset.buttonTitleObserverBound = '1';
    }

    function replaceCards(cards) {
        const entries = Object.entries(cards || {});
        const pageStack = document.querySelector('.page-stack');

        entries.forEach(([domId, html], index) => {
            try {
                const current = document.getElementById(domId);

                if (typeof html !== 'string' || html.trim() === '') {
                    if (current) {
                        current.remove();
                        updateCardMaximizedBodyState();
                    }
                    return;
                }

                const template = document.createElement('template');
                template.innerHTML = html.trim();
                const replacement = template.content.firstElementChild;

                if (replacement instanceof HTMLElement && current) {
                    const wasMaximized = current.classList.contains('card-maximized');
                    current.replaceWith(replacement);
                    if (wasMaximized) {
                        setCardMaximized(replacement, true);
                    }
                    initialisePageCardTabs(replacement);
                    initialiseCardToggles(replacement);
                    initStateWatchers(replacement);
                    initialiseVisibleWhenControls(replacement);
                    initialiseDirtyActionControls(replacement);
                    initDangerZoneConfirmationControls(replacement);
                    initialiseUploadDropzones(replacement);
                    initialiseRawUploadForms(replacement);
                    initialisePasswordRequirementPanels(replacement);
                    initialiseTableCondensedControls(replacement);
                    initialisePictureEditors(replacement);
                    initialiseGalleryAutoRefresh(replacement);
                    initialiseCardAutoRefresh(replacement);
                    updateCardMaximizedBodyState();
                    return;
                }

                if (replacement instanceof HTMLElement && pageStack instanceof HTMLElement) {
                    const nextEntry = entries
                        .slice(index + 1)
                        .find(([nextDomId]) => document.getElementById(nextDomId));
                    const anchor = nextEntry ? document.getElementById(nextEntry[0]) : null;

                    pageStack.insertBefore(replacement, anchor instanceof HTMLElement ? anchor : null);
                    initialisePageCardTabs(replacement);
                    initialiseCardToggles(replacement);
                    initStateWatchers(replacement);
                    initialiseVisibleWhenControls(replacement);
                    initialiseDirtyActionControls(replacement);
                    initDangerZoneConfirmationControls(replacement);
                    initialiseUploadDropzones(replacement);
                    initialiseRawUploadForms(replacement);
                    initialisePasswordRequirementPanels(replacement);
                    initialiseTableCondensedControls(replacement);
                    initialisePictureEditors(replacement);
                    initialiseGalleryAutoRefresh(replacement);
                    initialiseCardAutoRefresh(replacement);
                }
            } catch (error) {
                console.error(`Failed to replace AJAX card ${domId}.`, error);
            }
        });

        initialiseVisibleWhenControls(document);
    }

    function cardAutoRefreshNodes(root) {
        const nodes = [];

        if (root instanceof HTMLElement && root.matches('.card[data-card-refresh-ms][data-card-key]')) {
            nodes.push(root);
        }

        if (root && typeof root.querySelectorAll === 'function') {
            root.querySelectorAll('.card[data-card-refresh-ms][data-card-key]').forEach((node) => {
                if (node instanceof HTMLElement) {
                    nodes.push(node);
                }
            });
        }

        return nodes;
    }

    function initialiseCardAutoRefresh(root = document) {
        cardAutoRefreshNodes(root).forEach((card) => {
            if (cardAutoRefreshState.has(card)) {
                return;
            }

            const intervalMs = Math.max(5000, Number.parseInt(String(card.dataset.cardRefreshMs || ''), 10));
            const cardKey = String(card.dataset.cardKey || '').trim();
            if (!Number.isFinite(intervalMs) || cardKey === '') {
                return;
            }

            const state = {
                inFlight: false,
                timerId: null,
            };
            cardAutoRefreshState.set(card, state);

            const schedule = () => {
                if (!card.isConnected) {
                    return;
                }

                state.timerId = window.setTimeout(refresh, intervalMs);
            };

            const refresh = async () => {
                if (!card.isConnected) {
                    return;
                }

                if (document.hidden || state.inFlight) {
                    schedule();
                    return;
                }

                state.inFlight = true;
                const payload = {
                    _ajax: '1',
                    _card_refresh: '1',
                    cards: [cardKey],
                };
                const refreshFact = String(card.dataset.cardRefreshFact || '').trim();
                if (refreshFact !== '') {
                    payload._invalidate_fact = refreshFact;
                }
                appendSiteContextSelectionsToPayload(payload);

                try {
                    const response = await sendAjax(window.location.href, {
                        method: 'POST',
                        body: JSON.stringify(payload),
                        headers: { 'Content-Type': 'application/json' },
                    });

                    applyAjaxPayloadFragment('site context', () => replaceSiteContextSlots(response.site_context_html));
                    applyAjaxPayloadFragment('cards', () => replaceCards(response.cards));
                } catch (error) {
                    console.error(`Failed to refresh card ${cardKey}.`, error);
                } finally {
                    state.inFlight = false;
                    schedule();
                }
            };

            schedule();
        });
    }

    function galleryAutoRefreshEnabled() {
        if (!afStorageAvailable('localStorage')) {
            return false;
        }

        try {
            return window.localStorage.getItem(galleryAutoRefreshStorageKey) === '1';
        } catch (error) {
            return false;
        }
    }

    function setGalleryAutoRefreshEnabled(enabled) {
        if (!afStorageAvailable('localStorage')) {
            return;
        }

        try {
            window.localStorage.setItem(galleryAutoRefreshStorageKey, enabled ? '1' : '0');
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
        if (!(target instanceof HTMLElement)) {
            return false;
        }

        return target.dataset.galleryPending === '1'
            || target.querySelector('[data-gallery-photo-pending="1"]') instanceof HTMLElement;
    }

    function initialiseGalleryAutoRefresh(root = document) {
        galleryAutoRefreshTargets(root).forEach((target) => {
            if (target.dataset.galleryAutoRefreshBound === '1') {
                return;
            }

            target.dataset.galleryAutoRefreshBound = '1';
            const card = target.closest('.card[data-card-key]');
            const control = card instanceof HTMLElement
                ? card.querySelector('[data-gallery-auto-refresh-toggle]')
                : null;

            if (!(card instanceof HTMLElement) || !(control instanceof HTMLInputElement)) {
                return;
            }

            const state = {
                inFlight: false,
                timerId: null,
            };
            control.checked = galleryAutoRefreshEnabled();

            const clearTimer = () => {
                if (state.timerId !== null) {
                    window.clearTimeout(state.timerId);
                    state.timerId = null;
                }
            };

            const shouldRefresh = () => (
                card.isConnected
                && control.checked
                && galleryHasPendingPreviews(target)
            );

            const schedule = () => {
                clearTimer();
                if (!shouldRefresh()) {
                    return;
                }

                state.timerId = window.setTimeout(refresh, galleryAutoRefreshIntervalMs);
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

                try {
                    const response = await sendAjax(window.location.href, {
                        method: 'POST',
                        body: JSON.stringify(payload),
                        headers: { 'Content-Type': 'application/json' },
                    });

                    applyAjaxPayloadFragment('site context', () => replaceSiteContextSlots(response.site_context_html));
                    applyAjaxPayloadFragment('cards', () => replaceCards(response.cards));
                } catch (error) {
                    console.error('Failed to auto refresh gallery.', error);
                    schedule();
                } finally {
                    state.inFlight = false;
                }
            };

            control.addEventListener('change', () => {
                setGalleryAutoRefreshEnabled(control.checked);
                schedule();
            });
            schedule();
        });
    }

    function activatePageCardTab(tab) {
        if (!(tab instanceof HTMLButtonElement)) {
            return;
        }

        const tablist = tab.closest('[role="tablist"]');
        const tabsRoot = tab.closest('.page-card-tabs');
        const panelId = String(tab.dataset.pageCardTab || '').trim();
        const panel = panelId !== '' ? document.getElementById(panelId) : null;

        if (!(tablist instanceof HTMLElement) || !(tabsRoot instanceof HTMLElement) || !(panel instanceof HTMLElement)) {
            return;
        }

        tablist.querySelectorAll('[role="tab"]').forEach((node) => {
            const button = node instanceof HTMLButtonElement ? node : null;
            if (!button) {
                return;
            }

            const selected = button === tab;
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-selected', selected ? 'true' : 'false');
            button.tabIndex = selected ? 0 : -1;
        });

        tabsRoot.querySelectorAll('.page-card-tab-panel').forEach((node) => {
            if (node instanceof HTMLElement) {
                node.hidden = node !== panel;
            }
        });
    }

    function showPageCardTabForCard(cardKey) {
        const key = String(cardKey || '').trim();
        if (key === '') {
            return;
        }

        const card = document.querySelector(`.card[data-card-key="${escapeCssIdentifier(key)}"]`);
        const panel = card instanceof HTMLElement ? card.closest('.page-card-tab-panel') : null;
        const tab = panel instanceof HTMLElement && panel.id
            ? document.querySelector(`.page-card-tab[data-page-card-tab="${escapeCssIdentifier(panel.id)}"]`)
            : null;

        if (tab instanceof HTMLButtonElement) {
            activatePageCardTab(tab);
        }
    }

    function activatePageCardTabByLabel(control) {
        if (!(control instanceof HTMLElement)) {
            return;
        }

        const label = String(control.dataset.pageCardSwitchTab || '').trim().toLowerCase();
        const tabsRoot = control.closest('.page-card-tabs') || document.querySelector('.page-card-tabs');
        if (label === '' || !(tabsRoot instanceof HTMLElement)) {
            return;
        }

        const tab = Array.from(tabsRoot.querySelectorAll('.page-card-tab'))
            .find((node) => node instanceof HTMLButtonElement && String(node.textContent || '').trim().toLowerCase() === label);

        if (tab instanceof HTMLButtonElement) {
            activatePageCardTab(tab);
            tab.focus();
        }
    }

    function initialisePageCardTabs(root = document) {
        const tablists = root.querySelectorAll ? root.querySelectorAll('.page-card-tablist') : [];

        tablists.forEach((tablist) => {
            if (!(tablist instanceof HTMLElement) || tablist.dataset.pageCardTabsBound === '1') {
                return;
            }

            const tabs = Array.from(tablist.querySelectorAll('.page-card-tab'))
                .filter((node) => node instanceof HTMLButtonElement);

            tabs.forEach((tab, index) => {
                tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1;

                tab.addEventListener('click', () => {
                    activatePageCardTab(tab);
                });

                tab.addEventListener('keydown', (event) => {
                    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                        return;
                    }

                    event.preventDefault();
                    let nextIndex = index;

                    if (event.key === 'Home') {
                        nextIndex = 0;
                    } else if (event.key === 'End') {
                        nextIndex = tabs.length - 1;
                    } else {
                        const offset = event.key === 'ArrowRight' ? 1 : -1;
                        nextIndex = (index + offset + tabs.length) % tabs.length;
                    }

                    const nextTab = tabs[nextIndex];
                    if (nextTab instanceof HTMLButtonElement) {
                        activatePageCardTab(nextTab);
                        nextTab.focus();
                    }
                });
            });

            tablist.dataset.pageCardTabsBound = '1';
        });

        const switchers = root.querySelectorAll ? root.querySelectorAll('[data-page-card-switch-tab]') : [];
        switchers.forEach((switcher) => {
            if (!(switcher instanceof HTMLElement) || switcher.dataset.pageCardSwitchTabBound === '1') {
                return;
            }

            switcher.addEventListener('click', () => {
                activatePageCardTabByLabel(switcher);
            });
            switcher.dataset.pageCardSwitchTabBound = '1';
        });
    }

    function initialiseCardToggles(scope = document) {
        const cards = [];

        if (scope instanceof HTMLElement && scope.matches('.card')) {
            cards.push(scope);
        }

        if (scope.querySelectorAll) {
            scope.querySelectorAll('.card').forEach((card) => {
                if (card instanceof HTMLElement) {
                    cards.push(card);
                }
            });
        }

        cards.forEach((card) => {
            const title = card.querySelector('.card-title');
            const cardBody = card.querySelector('.card-body');

            if (!(title instanceof HTMLElement) || !(cardBody instanceof HTMLElement)) {
                return;
            }

            if (!cardBody.id) {
                cardBodySequence += 1;
                cardBody.id = `card-body-${cardBodySequence}`;
            }

            title.classList.add('card-title-toggle');
            title.setAttribute('role', 'button');
            title.setAttribute('tabindex', '0');
            title.setAttribute('aria-controls', cardBody.id);
            title.setAttribute('aria-expanded', cardBody.hidden ? 'false' : 'true');

            restoreStoredCardMaximized(card);
        });
    }

    function updateCardMaximizedBodyState() {
        if (!(body instanceof HTMLElement)) {
            return;
        }

        body.classList.toggle('card-maximized-active', Boolean(document.querySelector('.card.card-maximized')));
    }

    function currentPageId() {
        const main = document.querySelector('.main[data-current-page]');

        return main instanceof HTMLElement ? String(main.dataset.currentPage || '').trim() : '';
    }

    function cardStorageIdentity(card) {
        if (!(card instanceof HTMLElement)) {
            return '';
        }

        const cardKey = String(card.dataset.cardKey || '').trim();
        const pageId = currentPageId();

        return pageId !== '' && cardKey !== '' ? `${pageId}:${cardKey}` : '';
    }

    function storedMaximizedCardIdentity() {
        if (!afStorageAvailable('localStorage')) {
            return '';
        }

        try {
            return String(window.localStorage.getItem(cardMaximizedStorageKey) || '').trim();
        } catch (error) {
            return '';
        }
    }

    function setStoredMaximizedCard(card, maximized) {
        if (!afStorageAvailable('localStorage')) {
            return;
        }

        const identity = cardStorageIdentity(card);
        if (identity === '') {
            return;
        }

        try {
            if (maximized) {
                window.localStorage.setItem(cardMaximizedStorageKey, identity);
                return;
            }

            if (storedMaximizedCardIdentity() === identity) {
                window.localStorage.removeItem(cardMaximizedStorageKey);
            }
        } catch (error) {
            // Storage may be disabled; the visible card state still applies.
        }
    }

    function restoreStoredCardMaximized(card) {
        const identity = cardStorageIdentity(card);

        if (identity !== '' && storedMaximizedCardIdentity() === identity) {
            setCardMaximized(card, true, false, false);
        }
    }

    function setCardMaximized(card, maximized, focusToggle = false, persist = true) {
        if (!(card instanceof HTMLElement)) {
            return;
        }

        card.classList.toggle('card-maximized', maximized);

        if (persist) {
            setStoredMaximizedCard(card, maximized);
        }

        const toggle = card.querySelector('[data-card-size-toggle]');
        if (toggle instanceof HTMLButtonElement) {
            toggle.setAttribute('aria-pressed', maximized ? 'true' : 'false');
            toggle.setAttribute('aria-label', maximized ? 'Minimize card' : 'Maximize card');

            if (focusToggle) {
                toggle.focus({preventScroll: true});
            }
        }

        updateCardMaximizedBodyState();
    }

    function toggleCardMaximized(toggle) {
        if (!(toggle instanceof HTMLButtonElement)) {
            return;
        }

        const card = toggle.closest('.card');
        if (!(card instanceof HTMLElement)) {
            return;
        }

        const nextMaximized = !card.classList.contains('card-maximized');
        if (nextMaximized) {
            document.querySelectorAll('.card.card-maximized').forEach((maximizedCard) => {
                if (maximizedCard instanceof HTMLElement && maximizedCard !== card) {
                    setCardMaximized(maximizedCard, false);
                }
            });
        }

        setCardMaximized(card, nextMaximized);
    }

    function toggleCardBody(title) {
        if (!(title instanceof HTMLElement)) {
            return;
        }

        const card = title.closest('.card');
        const cardBody = card ? card.querySelector('.card-body') : null;

        if (!(cardBody instanceof HTMLElement)) {
            return;
        }

        const nextHidden = !cardBody.hidden;
        cardBody.hidden = nextHidden;
        title.setAttribute('aria-expanded', nextHidden ? 'false' : 'true');
        card.classList.toggle('card-collapsed', nextHidden);
    }

    function replaceFlash(html) {
        const flash = document.getElementById('flash-messages');
        if (flash) {
            flash.innerHTML = html || '';
            logFlashMessages(flash);
            scheduleFlashDismissals(flash);
        }
    }

    function logFlashMessages(flashContainer) {
        if (!(flashContainer instanceof HTMLElement)) {
            return;
        }

        const messages = Array.from(flashContainer.querySelectorAll('.alert'));

        messages.forEach((message) => {
            if (!(message instanceof HTMLElement) || message.dataset.flashHistoryLogged === '1') {
                return;
            }

            const type = message.classList.contains('error') ? 'error' : 'success';
            const text = (message.innerText || message.textContent || '')
                .trim()
                .replace(/\s*\n\s*/g, ' - ');

            if (text === '') {
                return;
            }

            message.dataset.flashHistoryLogged = '1';
            flashHistory.unshift({
                timestamp: new Date(),
                type,
                text,
            });

            if (flashHistory.length > flashHistoryLimit) {
                flashHistory.length = flashHistoryLimit;
            }

            updateFlashHistoryPopover();
            console.log(`[flash:${type}] ${text}`);
        });
    }

    function formatFlashHistoryTimestamp(date) {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
            return '';
        }

        return date.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    }

    function updateFlashHistoryPopover() {
        const popover = document.getElementById('flash-history-popover');
        if (!(popover instanceof HTMLElement)) {
            return;
        }

        if (flashHistory.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'flash-history-empty';
            empty.textContent = 'No flash messages yet.';
            popover.replaceChildren(empty);
            return;
        }

        const list = document.createElement('ul');
        list.className = 'flash-history-list';

        flashHistory.forEach((entry) => {
            const item = document.createElement('li');
            item.className = `flash-history-item ${entry.type}`;

            const timestamp = document.createElement('span');
            timestamp.className = 'flash-history-time';
            timestamp.textContent = formatFlashHistoryTimestamp(entry.timestamp);

            const text = document.createElement('span');
            text.className = 'flash-history-text';
            text.textContent = entry.text;

            item.append(timestamp, text);
            list.appendChild(item);
        });

        popover.replaceChildren(list);
    }

    function dismissFlashMessage(message) {
        if (!(message instanceof HTMLElement) || !message.isConnected || message.classList.contains('is-dismissing')) {
            return;
        }

        message.classList.add('is-dismissing');

        window.setTimeout(() => {
            if (!message.isConnected) {
                return;
            }

            message.remove();
        }, flashDismissTransitionMs);
    }

    function scheduleFlashDismissals(flashContainer) {
        if (!(flashContainer instanceof HTMLElement)) {
            return;
        }

        const messages = Array.from(flashContainer.querySelectorAll('.alert'));

        messages.forEach((message, index) => {
            const timeoutMs = flashBaseTimeoutMs + (index * flashCascadeTimeoutMs);

            window.setTimeout(() => {
                dismissFlashMessage(message);
            }, timeoutMs);
        });
    }

    function replaceSidebar(html) {
        if (typeof html !== 'string' || html.trim() === '') {
            return;
        }

        const current = document.getElementById('sidebar-shell');
        if (!current) {
            return;
        }

        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const replacement = template.content.firstElementChild;

        if (replacement) {
            current.replaceWith(replacement);
            initialiseSidebar(document);
        }
    }

    function replaceSiteContextSlots(slotHtml) {
        if (!slotHtml || typeof slotHtml !== 'object') {
            return;
        }

        Object.entries(slotHtml).forEach(([slot, html]) => {
            const slotName = String(slot || '').trim();
            if (slotName === '') {
                return;
            }

            const current = document.getElementById(`site-context-${slotName}-slot`);
            if (!(current instanceof HTMLElement)) {
                return;
            }

            current.innerHTML = typeof html === 'string' ? html : '';
        });
    }

    function replaceDeveloperOptionsStatus(html) {
        const current = document.getElementById('developer-options-status-slot');
        if (!(current instanceof HTMLElement)) {
            return;
        }

        current.innerHTML = typeof html === 'string' ? html : '';
    }

    function applyAjaxPayloadFragment(name, callback) {
        try {
            callback();
        } catch (error) {
            console.error(`Failed to apply AJAX ${name} update.`, error);
        }
    }

    function beginButtonProcessingState(submitter) {
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

    function clearChickenCheck(refocus = false) {
        document.querySelectorAll('.chicken-check-backdrop').forEach((node) => node.remove());
        document.querySelectorAll('.chicken-check-window').forEach((node) => node.remove());

        if (activeChickenCheckButton instanceof HTMLButtonElement) {
            delete activeChickenCheckButton.dataset.chickenArmed;
            if (refocus && activeChickenCheckButton.isConnected) {
                activeChickenCheckButton.focus();
            }
        }

        activeChickenCheckButton = null;
    }

    function passChickenCheck(submitter) {
        if (!(submitter instanceof HTMLButtonElement) || submitter.dataset.chickenCheck !== 'true') {
            return true;
        }

        const form = submitter.form;
        if (!(form instanceof HTMLFormElement)) {
            return true;
        }

        if (submitter.dataset.chickenArmed === 'true') {
            clearChickenCheck(false);
            return true;
        }

        clearChickenCheck(false);

        const backdrop = document.createElement('div');
        backdrop.className = 'chicken-check-backdrop';

        const windowShell = document.createElement('div');
        windowShell.className = 'warn chicken-check-window';
        windowShell.setAttribute('role', 'alertdialog');

        const title = document.createElement('div');
        title.className = 'chicken-check-title';
        title.textContent = String(submitter.dataset.chickenTitle || 'Confirm delete');

        const message = document.createElement('div');
        message.className = 'chicken-check-message';
        message.textContent = String(submitter.dataset.chickenMessage || 'Press the button again to confirm.')
            .replace(/<br\s*\/?>/gi, '\n');

        const actions = document.createElement('div');
        actions.className = 'chicken-check-actions';

        const confirm = document.createElement('button');
        confirm.className = String(submitter.dataset.chickenButtonClass || 'button danger');
        confirm.type = 'button';
        confirm.textContent = String(submitter.dataset.chickenConfirmText || submitter.textContent || 'Confirm');
        confirm.addEventListener('click', () => {
            submitter.dataset.chickenArmed = 'true';
            form.requestSubmit(submitter);
        });

        const cancel = document.createElement('button');
        cancel.className = 'button button-inline';
        cancel.type = 'button';
        cancel.textContent = 'Cancel';
        cancel.addEventListener('click', () => clearChickenCheck(true));

        actions.append(confirm, cancel);
        windowShell.append(title, message, actions);

        submitter.dataset.chickenArmed = 'true';
        activeChickenCheckButton = submitter;
        document.body.appendChild(backdrop);
        document.body.appendChild(windowShell);
        submitter.focus();

        return false;
    }

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        resolveSelfVisibleCardField(form);
        syncSiteContextFieldsToForm(form);

        if (form.dataset.ajax !== 'true') {
            return;
        }

        event.preventDefault();

        if (!passChickenCheck(event.submitter)) {
            return;
        }

        syncSubmitAction(event.submitter);
        syncSubmitField(event.submitter);

        const formData = new FormData(form);

        formData.set('_ajax', '1');
        appendCurrentPageCardKeys(formData, form);
        appendRequestedVisibleCard(formData, event.submitter);
        appendSiteContextSelectionsToFormData(formData, form);

        const method = (form.method || 'POST').toUpperCase();

        const requestUrl = method === 'GET'
            ? requestUrlWithFormData(formRequestUrl(form), formData)
            : formRequestUrl(form);

        if (event.submitter instanceof HTMLButtonElement && event.submitter.name) {
            formData.append(event.submitter.name, event.submitter.value);
        }

        const requestBody = method === 'GET' ? null : JSON.stringify(formDataToJsonPayload(formData));
        const requestHeaders = method === 'GET' ? undefined : { 'Content-Type': 'application/json' };
        const requestPayload = method === 'GET' ? null : formDataToJsonPayload(formData);
        const ajaxNonce = requiresAjaxNonce(method, requestPayload) ? reserveAjaxNonce() : null;

        if (ajaxNonce && requestPayload) {
            requestPayload.ajax_nonce = ajaxNonce;
        }

        const restoreProcessingState = beginButtonProcessingState(event.submitter);

        try {
            const payload = await sendAjax(requestUrl, {
                method,
                body: method === 'GET' ? null : JSON.stringify(requestPayload),
                headers: requestHeaders,
                transport: form.dataset.ajaxTransport === 'xhr' ? 'xhr' : 'fetch',
            });

            completeAjaxNonce(ajaxNonce, payload?.ajax_nonce);

            if (navigateToAjaxPayloadPage(payload)) {
                return;
            }

            if (payload && typeof payload.download_url === 'string' && payload.download_url.trim() !== '') {
                triggerFileDownload(payload.download_url);
                return;
            }

            applyAjaxPayloadFragment('sidebar', () => replaceSidebar(payload.sidebar_html));
            applyAjaxPayloadFragment('site context', () => replaceSiteContextSlots(payload.site_context_html));
            applyAjaxPayloadFragment('developer options status', () => replaceDeveloperOptionsStatus(payload.developer_options_status_html));
            applyAjaxPayloadFragment('cards', () => replaceCards(payload.cards));
            applyAjaxPayloadFragment('flash', () => replaceFlash(payload.flash_html));
            applyAjaxPayloadFragment('visible card', () => showPageCardTabForCard(payload.show_card));

        } catch (error) {
            restoreAjaxNonce(ajaxNonce);
            const flashHtml = error && error.payload && typeof error.payload.flash_html === 'string'
                ? error.payload.flash_html
                : renderErrorFlashHtml(error ? error.payload : null);

            if (flashHtml !== '') {
                replaceFlash(flashHtml);
            }

            handleAjaxSecurityFailure(error ? error.payload : null);

            console.error(error);
        } finally {
            restoreProcessingState();
        }
    });

    document.addEventListener('click', async (event) => {
        const cardSizeToggle = event.target instanceof Element ? event.target.closest('[data-card-size-toggle]') : null;
        if (cardSizeToggle instanceof HTMLButtonElement) {
            event.preventDefault();
            toggleCardMaximized(cardSizeToggle);
            return;
        }

        const link = event.target instanceof Element ? event.target.closest('[data-ajax-link="true"]') : null;
        if (!(link instanceof HTMLAnchorElement)) {
            const title = event.target instanceof Element ? event.target.closest('.card-title-toggle') : null;

            if (title instanceof HTMLElement) {
                event.preventDefault();
                toggleCardBody(title);
            }

            return;
        }

        event.preventDefault();
        if (link.closest('.nav-group')) {
            await centerNavLinkInView(link);
        }
        window.location.href = link.href;
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            const maximizedCard = document.querySelector('.card.card-maximized');
            if (maximizedCard instanceof HTMLElement) {
                event.preventDefault();
                setCardMaximized(maximizedCard, false, true);
            }

            return;
        }

        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        const title = event.target instanceof Element ? event.target.closest('.card-title-toggle') : null;
        if (!(title instanceof HTMLElement)) {
            return;
        }

        event.preventDefault();
        toggleCardBody(title);
    });

    document.addEventListener('change', (event) => {
        if (isFormControl(event.target)) {
            syncVisibleWhenField(event.target);
        }

        const submitOnChangeControl = event.target instanceof Element
            ? event.target.closest('[data-submit-on-change="true"]')
            : null;

        if (submitOnChangeControl instanceof HTMLElement) {
            const form = submitOnChangeControl.closest('form[data-ajax="true"]');
            if (form instanceof HTMLFormElement) {
                form.requestSubmit();
                return;
            }
        }

        const select = event.target;
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        if (select.dataset.noSubmitOnChange === 'true') {
            return;
        }

        const form = select.closest('form[data-ajax="true"]');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        form.requestSubmit();
    });

    document.addEventListener('input', (event) => {
        normaliseDigitsOnlyInput(event.target);

        if (isFormControl(event.target)) {
            syncVisibleWhenField(event.target);
        }
    });

    initialiseSidebar(document);
    initialisePageCardTabs(document);
    initialiseCardToggles();
    initStateWatchers(document);
    initialiseVisibleWhenControls(document);
    initialiseDirtyActionControls(document);
    initDangerZoneConfirmationControls(document);
    initialiseUploadDropzones(document);
    initialiseRawUploadForms(document);
    initialisePasswordRequirementPanels(document);
    initialiseTableCondensedControls(document);
    initialisePictureEditors(document);
    initialiseGalleryAutoRefresh(document);
    initialiseCardAutoRefresh(document);
    initialiseButtonTitleVisibility();
    logFlashMessages(document.getElementById('flash-messages'));
    scheduleFlashDismissals(document.getElementById('flash-messages'));
    loadAjaxNonceBootstrap();
    afGetDeviceId();
    initialiseLoginCountdown();

    if (document.readyState === 'complete') {
        renderPageLoadTime();
    } else {
        window.addEventListener('load', () => {
            renderPageLoadTime();
            window.setTimeout(renderPageLoadTime, 0);
        }, { once: true });
    }
})();
