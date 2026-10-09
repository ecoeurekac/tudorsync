/**
 * TUDOR e-Stock: remembers a visit coming from tudorwatch.com (utm_source=tudorwatch.com) in the
 * first-party cookie `tudorsync_utm`, so the order placed afterwards can be attributed to the
 * programme. Runs in the browser because pages are usually served from a page cache.
 *
 * Last click wins: a visit with another utm_source deletes the cookie; a visit without
 * utm_source changes nothing. When consent is required and not given yet, the referral waits in
 * sessionStorage and is written as soon as consent is given (or dropped with the tab).
 *
 * Settings come from the inline object `tudorsyncUtm` (Attribution\UtmCapture).
 */
(function () {
    'use strict';

    var settings = window.tudorsyncUtm;
    if (!settings || !settings.cookie) {
        return;
    }

    var PENDING_KEY = 'tudorsync_utm_pending';
    var CHECK_EVERY_MS = 3000;
    var CHECK_FOR_MS = 30 * 60 * 1000;

    function clean(value) {
        return String(value || '').replace(/[^A-Za-z0-9._-]/g, '_').slice(0, 100);
    }

    function readCookie(name) {
        var parts = document.cookie ? document.cookie.split('; ') : [];
        for (var i = 0; i < parts.length; i++) {
            var index = parts[i].indexOf('=');
            if (index > 0 && decodeURIComponent(parts[i].slice(0, index)) === name) {
                return decodeURIComponent(parts[i].slice(index + 1));
            }
        }
        return null;
    }

    function writeCookie(value, maxAge) {
        document.cookie = settings.cookie + '=' + encodeURIComponent(value)
            + '; max-age=' + maxAge + '; path=/; SameSite=Lax'
            + (window.location.protocol === 'https:' ? '; Secure' : '');
    }

    var memory = null; // fallback when sessionStorage is blocked

    function pending(value) {
        if (value !== undefined) {
            memory = value;
        }
        try {
            if (value === undefined) {
                return window.sessionStorage.getItem(PENDING_KEY);
            }
            if (value === null) {
                window.sessionStorage.removeItem(PENDING_KEY);
            } else {
                window.sessionStorage.setItem(PENDING_KEY, value);
            }
        } catch (e) {
            // Storage blocked: the referral is kept for this page only.
        }
        return memory;
    }

    function hasConsent() {
        switch (settings.consent) {
            case 'wp_consent_api':
                return typeof window.wp_has_consent === 'function' && window.wp_has_consent(settings.category);
            case 'cookie':
                return readCookie(settings.consentCookie) === settings.consentValue;
            default:
                return true;
        }
    }

    function flushPending() {
        var value = pending();
        if (value && hasConsent()) {
            writeCookie(value, settings.maxAge);
            pending(null);
            return true;
        }
        return !value;
    }

    var params = new URLSearchParams(window.location.search);
    var source = params.get('utm_source');

    if (source !== null) {
        if (source.toLowerCase() === settings.source) {
            pending([
                settings.source,
                clean(params.get('utm_medium')),
                clean(params.get('utm_campaign')),
                Math.floor(Date.now() / 1000)
            ].join('|'));
        } else {
            // Another campaign: the last click wins.
            pending(null);
            writeCookie('', 0);
        }
    }

    if (flushPending()) {
        return;
    }

    // Consent still missing: write it as soon as it is given on this page.
    document.addEventListener('wp_listen_for_consent_change', flushPending);
    var started = Date.now();
    var timer = window.setInterval(function () {
        if (flushPending() || Date.now() - started > CHECK_FOR_MS) {
            window.clearInterval(timer);
        }
    }, CHECK_EVERY_MS);
})();
