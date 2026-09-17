(() => {
    'use strict';

    const placeProviderLlmPanel = () => {
        const source = document.getElementById('wddtf-provider-llm-export');
        const target = document.getElementById('wddtf-gpp-diagnostics');
        if (!source || !target || source.parentElement === target) {
            return;
        }
        target.appendChild(source);
        source.hidden = false;
    };

    const copyText = async (textarea) => {
        const text = textarea.value || textarea.textContent || '';
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }

        textarea.focus();
        textarea.select();
        const copied = document.execCommand('copy');
        textarea.setSelectionRange(0, 0);
        textarea.blur();
        if (!copied) {
            throw new Error('copy_failed');
        }
    };

    document.addEventListener('DOMContentLoaded', placeProviderLlmPanel);
    placeProviderLlmPanel();

    document.addEventListener('click', async (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const toggle = event.target.closest('[data-wddtf-toggle-target]');
        if (toggle) {
            const targetId = toggle.getAttribute('data-wddtf-toggle-target');
            const target = targetId ? document.getElementById(targetId) : null;
            if (target) {
                const opening = target.hidden;
                target.hidden = !opening;
                toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
            }
            return;
        }

        const button = event.target.closest('[data-wddtf-copy-target]');
        if (!button) {
            return;
        }

        const targetId = button.getAttribute('data-wddtf-copy-target');
        const target = targetId ? document.getElementById(targetId) : null;
        if (!(target instanceof HTMLTextAreaElement)) {
            return;
        }

        const originalText = button.textContent;
        button.disabled = true;
        try {
            await copyText(target);
            button.textContent = button.getAttribute('data-wddtf-copied-label') || 'Copied';
        } catch (error) {
            button.textContent = button.getAttribute('data-wddtf-copy-failed-label') || 'Copy failed';
        }

        window.setTimeout(() => {
            button.textContent = originalText;
            button.disabled = false;
        }, 1800);
    });
})();
