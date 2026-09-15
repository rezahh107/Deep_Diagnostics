(function ($, window, document) {
    'use strict';

    var config = window.WDDTFGravityBrowserEvidence;
    if (!config || !config.ajaxUrl || !config.action || !config.nonce || !config.headerName) {
        return;
    }

    var requests = typeof WeakMap === 'function' ? new WeakMap() : null;
    var mutationSequence = 0;
    var observer = null;

    if (window.MutationObserver && document.documentElement) {
        observer = new window.MutationObserver(function (records) {
            if (records && records.length) {
                mutationSequence += records.length;
            }
        });
        observer.observe(document.documentElement, {
            childList: true,
            subtree: true,
            attributes: true
        });
    }

    function performanceNow() {
        return window.performance && typeof window.performance.now === 'function'
            ? window.performance.now()
            : null;
    }

    function visibilityState() {
        var state = document.visibilityState;
        return state === 'visible' || state === 'hidden' || state === 'prerender' ? state : 'unknown';
    }

    function classifyUiSignal(responseBaseline) {
        var titleChanged = responseBaseline.title !== document.title;
        var domMutation = mutationSequence > responseBaseline.mutationSequence;
        if (titleChanged && domMutation) {
            return 'both';
        }
        if (titleChanged) {
            return 'title_change';
        }
        if (domMutation) {
            return 'dom_mutation';
        }
        return 'none';
    }

    function scheduleTaggedEvidence(sampleRef, status, outcome, startedAt, finishedAt) {
        if (!/^gb-[a-f0-9]{20}$/.test(sampleRef)) {
            return;
        }

        var duration = startedAt !== null && finishedAt !== null
            ? Math.max(0, finishedAt - startedAt)
            : null;
        var clientReceivedMs = Date.now();
        var responseBaseline = {
            mutationSequence: mutationSequence,
            title: document.title
        };

        window.setTimeout(function () {
            var payload = {
                action: config.action,
                nonce: config.nonce,
                sample_ref: sampleRef,
                outcome: outcome,
                http_status: status,
                client_received_ms: clientReceivedMs,
                visibility: visibilityState(),
                ui_signal: classifyUiSignal(responseBaseline)
            };
            if (duration !== null && isFinite(duration)) {
                payload.duration_ms = Math.round(duration * 100) / 100;
            }

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: payload,
                dataType: 'json'
            });
        }, Math.max(0, Math.min(500, Number(config.uiObservationWindowMs) || 0)));
    }

    function observeCompletion(xhr) {
        var requestState = requests && requests.has(xhr) ? requests.get(xhr) : null;
        if (requests && requests.has(xhr)) {
            requests.delete(xhr);
        }

        var sampleRef = '';
        try {
            sampleRef = xhr.getResponseHeader(config.headerName) || '';
        } catch (_error) {
            return;
        }

        var finishedAt = performanceNow();
        var status = typeof xhr.status === 'number' ? xhr.status : 0;
        scheduleTaggedEvidence(
            sampleRef,
            status,
            status >= 200 && status < 400 ? 'success' : 'error',
            requestState ? requestState.startedAt : null,
            finishedAt
        );
    }

    // Gravity Flow 3.1.0 performs Inbox Live Data Refresh with window.fetch().
    // Observe only responses explicitly tagged by DEEP's bounded server-side
    // candidate correlation; do not inspect request URLs, bodies or response content.
    if (typeof window.fetch === 'function') {
        var nativeFetch = window.fetch.bind(window);
        window.fetch = function () {
            var startedAt = performanceNow();
            return nativeFetch.apply(window, arguments).then(function (response) {
                var sampleRef = '';
                try {
                    sampleRef = response && response.headers && typeof response.headers.get === 'function'
                        ? (response.headers.get(config.headerName) || '')
                        : '';
                } catch (_error) {
                    return response;
                }

                if (/^gb-[a-f0-9]{20}$/.test(sampleRef)) {
                    var finishedAt = performanceNow();
                    var status = response && typeof response.status === 'number' ? response.status : 0;
                    scheduleTaggedEvidence(
                        sampleRef,
                        status,
                        status >= 200 && status < 400 ? 'success' : 'error',
                        startedAt,
                        finishedAt
                    );
                }
                return response;
            });
        };
    }

    $.ajaxPrefilter(function (_options, _originalOptions, jqXHR) {
        jqXHR.always(function () {
            observeCompletion(jqXHR);
        });
    });

    $(document).on('ajaxSend.wddtfGravityBrowserEvidence', function (_event, xhr) {
        if (!requests) {
            return;
        }
        requests.set(xhr, {
            startedAt: performanceNow()
        });
    });
}(jQuery, window, document));
