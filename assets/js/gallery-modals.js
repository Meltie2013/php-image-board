(function ()
{
    function bindModal(openSelector, overlaySelector, closeSelector, autoOpenCheck)
    {
        const openBtn = document.querySelector(openSelector);
        const overlay = document.querySelector(overlaySelector);
        const closeBtn = document.querySelector(closeSelector);

        if (!openBtn || !overlay || !closeBtn)
            return;

        function openModal(e)
        {
            if (e)
                e.preventDefault();

            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeModal()
        {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        openBtn.addEventListener('click', openModal);
        closeBtn.addEventListener('click', closeModal);

        overlay.addEventListener('click', function (e)
        {
            if (e.target === overlay)
                closeModal();
        });

        document.addEventListener('keydown', function (e)
        {
            if (e.key === 'Escape')
                closeModal();
        });

        if (typeof autoOpenCheck === 'function' && autoOpenCheck())
        {
            openModal();
        }
    }

    // Edit Image Modal
    bindModal(
        '.js-open-edit-image',
        '.js-edit-image-overlay',
        '.js-close-edit-image'
    );

    // Comments Modal (supports auto-open from pagination)
    bindModal(
        '.js-open-comments',
        '.js-comments-overlay',
        '.js-close-comments',
        function ()
        {
            const params = new URLSearchParams(window.location.search);
            return (params.get('show_comments') === '1');
        }
    );
})();
