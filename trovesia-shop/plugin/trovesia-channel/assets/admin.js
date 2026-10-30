(function () {
    'use strict';

    const modal = document.getElementById('trovesia-channel-modal');
    const addButton = document.getElementById('trovesia-add-channel');
    const form = document.getElementById('trovesia-channel-form');
    const title = document.getElementById('trovesia-channel-modal-title');
    const submitButton = document.getElementById('trovesia-channel-submit');

    if (!modal || !addButton || !form || !title || !submitButton) {
        return;
    }

    const fields = {
        channelId: document.getElementById('trovesia-channel-id'),
        name: document.getElementById('trovesia-channel-name'),
        code: document.getElementById('trovesia-channel-code'),
        status: document.getElementById('trovesia-channel-status'),
        commissionRate: document.getElementById('trovesia-channel-commission-rate'),
        email: document.getElementById('manager-email'),
        username: document.getElementById('manager-username'),
        password: document.getElementById('manager-password'),
    };

    let previouslyFocused = null;

    function openModal() {
        previouslyFocused = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('trovesia-channel-modal-open');
        window.setTimeout(function () {
            fields.name.focus();
        }, 0);
    }

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('trovesia-channel-modal-open');
        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
            previouslyFocused.focus();
        }
    }

    function openNewChannel() {
        form.reset();
        fields.channelId.value = '0';
        fields.name.value = '';
        fields.code.value = '';
        fields.status.value = 'active';
        fields.commissionRate.value = '0.2';
        fields.email.value = '';
        fields.username.value = '';
        fields.password.value = '';
        title.textContent = trovesiaChannelAdmin.addTitle;
        submitButton.textContent = trovesiaChannelAdmin.createLabel;
        openModal();
    }

    function openExistingChannel(button) {
        fields.channelId.value = button.dataset.channelId || '0';
        fields.name.value = button.dataset.name || '';
        fields.code.value = button.dataset.code || '';
        fields.status.value = button.dataset.status || 'active';
        fields.commissionRate.value = button.dataset.commissionRate || '0.2';
        fields.email.value = button.dataset.managerEmail || '';
        fields.username.value = button.dataset.managerUsername || '';
        fields.password.value = '';
        title.textContent = trovesiaChannelAdmin.editTitle;
        submitButton.textContent = trovesiaChannelAdmin.updateLabel;
        openModal();
    }

    addButton.addEventListener('click', openNewChannel);
    document.querySelectorAll('.trovesia-edit-channel').forEach(function (button) {
        button.addEventListener('click', function () {
            openExistingChannel(button);
        });
    });
    modal.querySelectorAll('[data-trovesia-modal-close]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    if (!modal.hidden) {
        document.body.classList.add('trovesia-channel-modal-open');
        fields.name.focus();
    }
}());
