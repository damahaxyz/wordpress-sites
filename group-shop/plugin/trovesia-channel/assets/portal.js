(function () {
    'use strict';
    const ranges = document.querySelector('.trovesia-channel-ranges');
    const selectedRange = ranges?.querySelector('.is-active');
    if (selectedRange && ranges.scrollWidth > ranges.clientWidth) {
        ranges.scrollLeft += selectedRange.getBoundingClientRect().left - ranges.getBoundingClientRect().left - (ranges.clientWidth - selectedRange.offsetWidth) / 2;
    }
    document.querySelectorAll('[data-channel-copy]').forEach(function (button) {
        const label = button.textContent;
        let timer;
        button.addEventListener('click', async function () {
            const control = button.closest('.trovesia-channel-copy-control');
            const input = control.querySelector('input');
            const feedback = control.querySelector('.trovesia-channel-copy-feedback');
            try {
                if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
                await navigator.clipboard.writeText(input.value);
                button.textContent = button.dataset.copiedLabel;
                feedback.textContent = button.dataset.copiedLabel;
            } catch (error) {
                input.focus();
                input.select();
                button.textContent = button.dataset.fallbackLabel;
                feedback.textContent = button.dataset.fallbackLabel;
            }
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { button.textContent = label; feedback.textContent = ''; }, 3500);
        });
    });
}());

(function () {
    'use strict';
    const dialog = document.getElementById('trovesia-channel-delete-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const form = dialog.querySelector('[data-channel-delete-form]');
    const cancel = dialog.querySelector('[data-channel-delete-cancel]');
    let opener = null;
    document.querySelectorAll('[data-channel-delete-open]').forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', function () {
            opener = button;
            form.elements.campaign_id.value = button.dataset.campaignId;
            form.elements._wpnonce.value = button.dataset.campaignNonce;
            dialog.querySelector('[data-channel-delete-name]').textContent = button.dataset.campaignName;
            dialog.showModal();
            cancel.focus();
        });
    });
    dialog.querySelectorAll('[data-channel-delete-close]').forEach(function (button) {
        button.addEventListener('click', function () { dialog.close(); });
    });
    dialog.addEventListener('click', function (event) {
        if (event.target !== dialog) return;
        const box = dialog.getBoundingClientRect();
        if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close();
    });
    dialog.addEventListener('close', function () { form.reset(); opener?.focus(); });
}());

(function () {
    'use strict';

    const dialog = document.getElementById('trovesia-channel-password-dialog');
    const form = document.getElementById('trovesia-channel-password-form');
    const opener = document.querySelector('[data-channel-password-open]');
    if (!dialog || !form || !opener) return;

    const notice = form.querySelector('.trovesia-channel-password-notice');
    const submit = form.querySelector('[type="submit"]');
    const submitLabel = submit.textContent;
    let busy = false;
    let updated = false;

    opener.addEventListener('click', function () {
        form.reset();
        notice.hidden = true;
        submit.disabled = false;
        submit.textContent = submitLabel;
        updated = false;
        dialog.showModal();
        document.getElementById('channel-current-password').focus();
    });
    dialog.querySelectorAll('[data-channel-password-close]').forEach(function (button) {
        button.addEventListener('click', function () { if (!busy) dialog.close(); });
    });
    dialog.addEventListener('click', function (event) {
        if (event.target !== dialog || busy) return;
        const box = dialog.getBoundingClientRect();
        if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close();
    });
    dialog.addEventListener('cancel', function (event) { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', function () {
        form.reset();
        notice.hidden = true;
        opener.focus();
    });
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy || updated || !form.reportValidity()) return;
        busy = true;
        submit.disabled = true;
        submit.textContent = form.dataset.savingLabel;
        form.setAttribute('aria-busy', 'true');
        notice.hidden = true;
        try {
            const response = await fetch(form.action, {
                method: 'POST', credentials: 'same-origin',
                body: new URLSearchParams(new FormData(form)),
                headers: { Accept: 'application/json' },
            });
            const result = await response.json();
            updated = response.ok && result.success === true;
            notice.textContent = result.data && result.data.message ? result.data.message : form.dataset.errorMessage;
            notice.classList.toggle('is-success', updated);
            notice.hidden = false;
            if (updated) form.reset();
            notice.focus();
        } catch (error) {
            notice.textContent = form.dataset.errorMessage;
            notice.classList.remove('is-success');
            notice.hidden = false;
            notice.focus();
        } finally {
            busy = false;
            submit.disabled = updated;
            submit.textContent = submitLabel;
            form.removeAttribute('aria-busy');
        }
    });
}());
