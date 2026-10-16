/**
 * TUDOR e-Stock: remembers a visit coming from tudorwatch.com (utm_source=tudorwatch.com) in the
 * first-party cookie `tudorsync_utm`, so the order placed afterwards can be attributed to the
 * programme. Same cookie and format as the WooCommerce plugin:
 * `tudorwatch.com|<utm_medium>|<utm_campaign>|<unix time of the visit>`, kept 30 days.
 *
 * Consent comes from CookieScript (cookie-script.com), where the cookie is declared in the
 * `performance` category: it is only written once that category is accepted, and deleted when
 * the visitor rejects it. Until then the referral waits in sessionStorage and is written as soon
 * as consent is given (or dropped with the tab).
 *
 * Last click wins: a visit with another utm_source deletes the cookie; a visit without
 * utm_source changes nothing.
 *
 * Settings come from `tudorsyncUtm` (Media::addJsDef in Tudorsync::hookActionFrontControllerSetMedia).
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

    function acceptedCategories() {
        var cookieScript = window.CookieScript;
        if (!cookieScript || !cookieScript.instance || typeof cookieScript.instance.currentState !== 'function') {
            return [];
        }
        var categories = cookieScript.instance.currentState().categories;
        if (typeof categories === 'string') {
            try {
                categories = JSON.parse(categories);
            } catch (e) {
                categories = categories.split(',');
            }
        }
        return Array.isArray(categories) ? categories : [];
    }

    function hasConsent() {
        return settings.consent === 'none' || acceptedCategories().indexOf(settings.category) !== -1;
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

    function forget() {
        pending(null);
        writeCookie('', 0);
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
            forget();
        }
    }

    window.addEventListener('CookieScriptReject', forget);
    window.addEventListener('CookieScriptAccept', function (event) {
        var categories = event && event.detail && event.detail.categories;
        if (Array.isArray(categories) && categories.indexOf(settings.category) === -1) {
            forget();
        } else {
            flushPending();
        }
    });
    window.addEventListener('CookieScriptAcceptAll', flushPending);
    window.addEventListener('CookieScriptCategory-' + settings.category, flushPending);
    window.addEventListener('CookieScriptLoaded', flushPending);

    if (flushPending()) {
        return;
    }

    // Consent still missing: write it as soon as it is given on this page (the events above
    // cover CookieScript; this covers a banner that loads late or answers without them).
    var started = Date.now();
    var timer = window.setInterval(function () {
        if (flushPending() || Date.now() - started > CHECK_FOR_MS) {
            window.clearInterval(timer);
        }
    }, CHECK_EVERY_MS);
})();
