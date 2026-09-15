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

    function visibilityState() {
        var state = document.visibilityState;
        return state === 'visible' || state === 'hidden' || state === 'prerender' ? state : 'unknown';
    }

    function stateFor(xhr) {
        if (requests && requests.has(xhr)) {
            return requests.get(xhr);
        }
        return {
            startedAt: window.performance && typeof window.performance.now === 'function' ? window.performance.now() : null,
            mutationSequence: mutationSequence,
            title: document.title
        };
    }

    function classifyUiSignal(before) {
        var titleChanged = before.title !== document.title;
        var domMutation = mutationSequence > before.mutationSequence;
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

    $(document).on('ajaxSend.wddtfGravityBrowserEvidence', function (_event, xhr) {
        if (!requests) {
            return;
        }
        requests.set(xhr, {
            startedAt: window.performance && typeof window.performance.now === 'function' ? window.performance.now() : null,
            mutationSequence: mutationSequence,
            title: document.title
        });
    });

    $(document).on('ajaxComplete.wddtfGravityBrowserEvidence', function (_event, xhr) {
        var sampleRef = '';
        try {
            sampleRef = xhr.getResponseHeader(config.headerName) || '';
        } catch (_error) {
            return;
        }

        if (!/^gb-[a-f0-9]{20}$/.test(sampleRef)) {
            return;
        }

        var before = stateFor(xhr);
        var finishedAt = window.performance && typeof window.performance.now === 'function' ? window.performance.now() : null;
        var duration = before.startedAt !== null && finishedAt !== null ? Math.max(0, finishedAt - before.startedAt) : null;
        var status = typeof xhr.status === 'number' ? xhr.status : 0;
        var outcome = status >= 200 && status < 400 ? 'success' : 'error';

        window.setTimeout(function () {
            var payload = {
                action: config.action,
                nonce: config.nonce,
                sample_ref: sampleRef,
                outcome: outcome,
                http_status: status,
                client_received_ms: Date.now(),
                visibility: visibilityState(),
                ui_signal: classifyUiSignal(before)
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
    });
}(jQuery, window, document));
