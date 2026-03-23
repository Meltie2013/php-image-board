(function ()
{
    const configElement = document.getElementById('gallery-live-config');
    if (!configElement)
    {
        return;
    }

    const config = {
        hash: configElement.dataset.hash || '',
        csrfToken: configElement.dataset.csrfToken || '',
        pollUrl: configElement.dataset.pollUrl || '',
        websocketUrl: configElement.dataset.websocketUrl || '',
        tick: Number(configElement.dataset.tick || 0)
    };

    if (!config.pollUrl)
    {
        return;
    }

    let currentTick = Number(config.tick || 0);
    let socket = null;
    let socketReady = false;
    let reconnectTimer = null;
    let fallbackPollTimer = null;

    function setText(selector, value)
    {
        document.querySelectorAll(selector).forEach(function (node)
        {
            node.textContent = value;
        });
    }

    function setActiveButton(selector, active, solidClass, regularClass)
    {
        document.querySelectorAll(selector).forEach(function (button)
        {
            const icon = button.querySelector('i');
            if (!icon)
            {
                return;
            }

            if (active)
            {
                icon.classList.remove(regularClass);
                icon.classList.add(solidClass);
                button.setAttribute('data-live-active', '1');
                button.setAttribute('disabled', 'disabled');
            }
        });
    }

    function applyState(state)
    {
        if (!state)
        {
            return;
        }

        setText('[data-live-votes-count="1"]', state.votes_display || '0');
        setText('[data-live-favorites-count="1"]', state.favorites_display || '0');
        setText('[data-live-views-count="1"]', state.views_display || '0');
        setText('[data-live-comments-count="1"]', state.comments_display || String(state.comments || 0));

        if (state.has_voted)
        {
            setActiveButton('[data-live-vote-button="1"]', true, 'fa-solid', 'fa-regular');
            document.querySelectorAll('[data-live-vote-button="1"] i').forEach(function (icon)
            {
                icon.classList.remove('fa-regular');
                icon.classList.add('fa-solid');
            });
        }

        if (state.has_favorited)
        {
            setActiveButton('[data-live-favorite-button="1"]', true, 'fa-solid', 'fa-regular');
            document.querySelectorAll('[data-live-favorite-button="1"] i').forEach(function (icon)
            {
                icon.classList.remove('fa-regular');
                icon.classList.add('fa-solid');
            });
        }
    }

    function fetchLiveState(force)
    {
        const separator = config.pollUrl.indexOf('?') === -1 ? '?' : '&';
        const url = config.pollUrl + separator + 'since=' + encodeURIComponent(String(currentTick)) + '&_=' + String(Date.now());

        fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(function (response)
            {
                return response.json();
            })
            .then(function (payload)
            {
                console.log('Gallery live poll payload:', payload);

                if (!payload || !payload.ok)
                {
                    return;
                }

                if (typeof payload.tick !== 'undefined')
                {
                    currentTick = Number(payload.tick || 0);
                }

                if (force || (payload.changed && payload.state))
                {
                    applyState(payload.state || null);
                }
            })
            .catch(function (error)
            {
                console.error('Gallery live polling failed:', error);
            });
    }

    function startFallbackPolling()
    {
        if (fallbackPollTimer !== null)
        {
            return;
        }

        fallbackPollTimer = window.setInterval(function ()
        {
            fetchLiveState(false);
        }, 15000);
    }

    function stopFallbackPolling()
    {
        if (fallbackPollTimer !== null)
        {
            window.clearInterval(fallbackPollTimer);
            fallbackPollTimer = null;
        }
    }

    function scheduleReconnect()
    {
        if (reconnectTimer !== null || !config.websocketUrl)
        {
            return;
        }

        reconnectTimer = window.setTimeout(function ()
        {
            reconnectTimer = null;
            connectWebSocket();
        }, 3000);
    }

    function connectWebSocket()
    {
        if (!config.websocketUrl || typeof window.WebSocket === 'undefined')
        {
            startFallbackPolling();
            return;
        }

        try
        {
            socket = new window.WebSocket(config.websocketUrl);
        }
        catch (error)
        {
            startFallbackPolling();
            scheduleReconnect();
            return;
        }

        socket.addEventListener('open', function ()
        {
            console.log('Gallery live WebSocket connected:', config.websocketUrl);
            socketReady = true;
            socket.send(JSON.stringify({
                action: 'subscribe',
                image: config.hash
            }));
            fetchLiveState(false);
        });

        socket.addEventListener('message', function (event)
        {
            console.log('Gallery live WebSocket message:', event.data);
            let payload = null;

            try
            {
                payload = JSON.parse(event.data);
            }
            catch (error)
            {
                return;
            }

            if (!payload)
            {
                return;
            }

            if (typeof payload.tick !== 'undefined')
            {
                const nextTick = Number(payload.tick || 0);
                if (nextTick > currentTick)
                {
                    currentTick = nextTick;
                }
            }

            if (payload.type === 'image_update')
            {
                fetchLiveState(true);
            }
        });

        socket.addEventListener('close', function (event)
        {
            console.warn('Gallery live WebSocket closed:', event);
            socketReady = false;
            socket = null;
            startFallbackPolling();
            scheduleReconnect();
        });

        socket.addEventListener('error', function (error)
        {
            console.error('Gallery live WebSocket error:', error);
            socketReady = false;
            startFallbackPolling();
        });
    }

    document.querySelectorAll('[data-live-action-form]').forEach(function (form)
    {
        form.addEventListener('submit', function (event)
        {
            event.preventDefault();

            const formData = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response)
                {
                    return response.json().then(function (payload)
                    {
                        return {
                            ok: response.ok,
                            status: response.status,
                            payload: payload
                        };
                    });
                })
                .then(function (result)
                {
                    const payload = result && result.payload ? result.payload : null;
                    if (!payload || !payload.ok)
                    {
                        if (payload && payload.message)
                        {
                            window.alert(payload.message);
                        }
                        else if (result && result.status === 403)
                        {
                            window.location.href = form.action.replace(/\/(favorite|upvote)$/i, '');
                        }
                        return;
                    }

                    if (payload.state)
                    {
                        applyState(payload.state);
                    }

                    if (typeof payload.tick !== 'undefined')
                    {
                        currentTick = Number(payload.tick || currentTick);
                    }

                    fetchLiveState(true);
                })
                .catch(function () {
                    window.location.href = form.action.replace(/\/(favorite|upvote)$/i, '');
                });
        });
    });

    connectWebSocket();
    startFallbackPolling();
    fetchLiveState(false);
})();
