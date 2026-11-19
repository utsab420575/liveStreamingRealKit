import './bootstrap';

/**
 * Copy-to-clipboard buttons: <button data-copy-target="input-id">.
 * Uses the async Clipboard API with a fallback for insecure contexts.
 */
async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // fall through to legacy fallback
        }
    }
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch {
        ok = false;
    }
    ta.remove();
    return ok;
}

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-target]');
    if (!button) return;

    const input = document.getElementById(button.dataset.copyTarget);
    if (!input) return;

    const original = button.textContent;
    const ok = await copyText(input.value);
    if (!ok) {
        // Last resort: select the text so the user can copy manually.
        input.focus();
        input.select();
    }
    button.textContent = ok ? 'Copied!' : 'Press Ctrl+C';
    setTimeout(() => (button.textContent = original), 2000);
});

/** Prevent double submission of forms marked with data-single-submit. */
document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-single-submit]');
    if (!form) return;
    if (form.dataset.submitting === 'true') {
        event.preventDefault();
        return;
    }
    form.dataset.submitting = 'true';
    form.querySelectorAll('button[type="submit"]').forEach((b) => {
        b.disabled = true;
        b.dataset.originalText = b.textContent;
        b.textContent = 'Please wait…';
    });
});

// Re-enable forms when the page is restored from the back/forward cache.
window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-single-submit]').forEach((form) => {
        form.dataset.submitting = 'false';
        form.querySelectorAll('button[type="submit"]').forEach((b) => {
            if (b.dataset.originalText) b.textContent = b.dataset.originalText;
            b.disabled = false;
        });
    });
});
