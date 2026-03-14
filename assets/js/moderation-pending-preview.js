(function () {
    function initModerationPendingPreview()
    {
        var portal = document.getElementById('moderation-preview-portal');

        if (!portal)
        {
            return;
        }

        var portalLink = portal.querySelector('.moderation-preview-portal-link');
        var portalImage = portal.querySelector('.moderation-preview-portal-image');
        var triggers = document.querySelectorAll('.moderation-preview-trigger');
        var activeTrigger = null;
        var hideTimer = null;

        if (!portalLink || !portalImage || !triggers.length)
        {
            return;
        }

        function clearHideTimer()
        {
            if (hideTimer)
            {
                window.clearTimeout(hideTimer);
                hideTimer = null;
            }
        }

        function clamp(value, min, max)
        {
            return Math.min(Math.max(value, min), max);
        }

        function positionPortal(trigger)
        {
            if (!trigger || portal.getAttribute('aria-hidden') === 'true')
            {
                return;
            }

            var triggerRect = trigger.getBoundingClientRect();
            var portalRect = portal.getBoundingClientRect();
            var offset = 14;
            var viewportPadding = 12;
            var left = triggerRect.left - portalRect.width - offset;

            if (left < viewportPadding)
            {
                left = triggerRect.right + offset;
            }

            if (left + portalRect.width > window.innerWidth - viewportPadding)
            {
                left = window.innerWidth - portalRect.width - viewportPadding;
            }

            left = Math.max(viewportPadding, left);

            var top = triggerRect.top + (triggerRect.height / 2) - (portalRect.height / 2);
            top = clamp(top, viewportPadding, window.innerHeight - portalRect.height - viewportPadding);

            portal.style.left = left + 'px';
            portal.style.top = top + 'px';
        }

        function showPortal(trigger)
        {
            var previewSrc = trigger.getAttribute('data-preview-src');
            var previewHref = trigger.getAttribute('data-preview-href') || trigger.getAttribute('href');

            if (!previewSrc || !previewHref)
            {
                return;
            }

            clearHideTimer();

            activeTrigger = trigger;
            portalLink.setAttribute('href', previewHref);
            portalImage.setAttribute('src', previewSrc);
            portalImage.setAttribute('alt', trigger.getAttribute('aria-label') || 'Image preview');
            portal.setAttribute('aria-hidden', 'false');
            portal.classList.add('is-visible');

            window.requestAnimationFrame(function () {
                positionPortal(trigger);
            });
        }

        function hidePortal()
        {
            clearHideTimer();
            activeTrigger = null;
            portal.classList.remove('is-visible');
            portal.setAttribute('aria-hidden', 'true');
            portal.style.left = '';
            portal.style.top = '';
            portalImage.setAttribute('src', '');
            portalLink.setAttribute('href', '#');
        }

        function scheduleHide()
        {
            clearHideTimer();
            hideTimer = window.setTimeout(hidePortal, 100);
        }

        Array.prototype.forEach.call(triggers, function (trigger) {
            trigger.addEventListener('mouseenter', function () {
                showPortal(trigger);
            });

            trigger.addEventListener('mouseleave', scheduleHide);
            trigger.addEventListener('focus', function () {
                showPortal(trigger);
            });
            trigger.addEventListener('blur', scheduleHide);
        });

        portal.addEventListener('mouseenter', clearHideTimer);
        portal.addEventListener('mouseleave', scheduleHide);

        portalImage.addEventListener('load', function () {
            if (activeTrigger)
            {
                positionPortal(activeTrigger);
            }
        });

        window.addEventListener('scroll', function () {
            if (activeTrigger)
            {
                positionPortal(activeTrigger);
            }
        }, true);

        window.addEventListener('resize', function () {
            if (activeTrigger)
            {
                positionPortal(activeTrigger);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape')
            {
                hidePortal();
            }
        });
    }

    if (document.readyState === 'loading')
    {
        document.addEventListener('DOMContentLoaded', initModerationPendingPreview);
        return;
    }

    initModerationPendingPreview();
})();
