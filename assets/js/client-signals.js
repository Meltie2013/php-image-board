(function () {
    'use strict';

    function bucketNumber(value, fallback, max) {
        var num = Number(value);
        if (!Number.isFinite(num) || num <= 0) {
            return fallback;
        }

        if (typeof max === 'number' && max > 0) {
            num = Math.min(num, max);
        }

        return String(num);
    }

    function bucketScreen(value) {
        var num = Number(value);
        if (!Number.isFinite(num) || num <= 0) {
            return '0';
        }

        return String(Math.round(num / 100) * 100);
    }

    function bucketDpr(value) {
        var num = Number(value);
        if (!Number.isFinite(num) || num <= 0) {
            return '1';
        }

        if (num < 1.25) {
            return '1';
        }
        if (num < 1.75) {
            return '1.5';
        }
        if (num < 2.5) {
            return '2';
        }
        if (num < 3.5) {
            return '3';
        }

        return '4+';
    }

    function buildSignals() {
        var timezone = 'unknown';
        try {
            timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'unknown';
        } catch (e) {
            timezone = 'unknown';
        }

        var timezoneOffset = String(new Date().getTimezoneOffset());
        var languagesCount = Array.isArray(navigator.languages) ? navigator.languages.length : 1;
        var platform = '';

        if (navigator.userAgentData && typeof navigator.userAgentData.platform === 'string' && navigator.userAgentData.platform !== '') {
            platform = navigator.userAgentData.platform;
        } else if (typeof navigator.platform === 'string') {
            platform = navigator.platform;
        }

        return {
            tz: timezone,
            tzoff: timezoneOffset,
            sw: bucketScreen(window.screen ? window.screen.width : 0),
            sh: bucketScreen(window.screen ? window.screen.height : 0),
            dpr: bucketDpr(window.devicePixelRatio || 1),
            touch: bucketNumber(navigator.maxTouchPoints || 0, '0', 10),
            langs: bucketNumber(languagesCount, '1', 10),
            pl: platform || 'unknown',
            hw: bucketNumber(navigator.hardwareConcurrency || 0, '0', 64),
            cd: bucketNumber(window.screen ? window.screen.colorDepth : 0, '0', 64),
            mobile: navigator.userAgentData && typeof navigator.userAgentData.mobile === 'boolean'
                ? (navigator.userAgentData.mobile ? '1' : '0')
                : (/mobi/i.test(navigator.userAgent) ? '1' : '0')
        };
    }

    function serializeSignals(signals) {
        var keys = Object.keys(signals).sort();
        var parts = [];

        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            var value = String(signals[key] || '')
                .replace(/\|/g, '')
                .replace(/=/g, '')
                .trim();

            if (value === '') {
                continue;
            }

            parts.push(key + '=' + value);
        }

        return parts.join('|');
    }

    function setCookie(name, value, days) {
        var expires = new Date(Date.now() + (days * 86400000)).toUTCString();
        var secure = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = name + '=' + encodeURIComponent(value)
            + '; Expires=' + expires
            + '; Path=/'
            + '; SameSite=Lax'
            + secure;
    }

    try {
        var serialized = serializeSignals(buildSignals());
        if (serialized !== '') {
            setCookie('pg_client_signals', serialized, 30);
        }
    } catch (e) {
        // Ignore signal collection failures so page behavior is never affected.
    }
})();
