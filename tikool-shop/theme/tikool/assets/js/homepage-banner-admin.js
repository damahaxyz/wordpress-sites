(() => {
  'use strict';

  const restoreMediaIds = () => {
    document.querySelectorAll('[data-tikool-media-field]').forEach((field) => {
      const input = field.querySelector('[data-tikool-media-id]');
      if (input && !input.value && field.dataset.tikoolMediaCurrentId) {
        input.value = field.dataset.tikoolMediaCurrentId;
      }
    });
  };

  restoreMediaIds();
  document.addEventListener('DOMContentLoaded', restoreMediaIds, { once: true });

  const mediaFieldObserver = new MutationObserver(() => {
    restoreMediaIds();
  });
  mediaFieldObserver.observe(document.documentElement, { childList: true, subtree: true });
  window.setInterval(restoreMediaIds, 500);

  document.addEventListener('click', (event) => {
    const selectButton = event.target.closest('[data-tikool-media-select]');
    const removeButton = event.target.closest('[data-tikool-media-remove]');

    if (selectButton) {
      event.preventDefault();
      const field = selectButton.closest('[data-tikool-media-field]');
      const frame = wp.media({
        title: tikoolHomeBannerAdmin.title,
        button: { text: tikoolHomeBannerAdmin.button },
        library: { type: 'image' },
        multiple: false,
      });

      frame.on('select', () => {
        const attachment = frame.state().get('selection').first().toJSON();
        const previewUrl = attachment.sizes?.medium?.url || attachment.url;
        field.querySelector('[data-tikool-media-id]').value = attachment.id;
        field.dataset.tikoolMediaCurrentId = attachment.id;
        field.querySelector('[data-tikool-media-preview]').innerHTML = `<img src="${previewUrl}" alt="">`;
        field.querySelector('[data-tikool-media-remove]').hidden = false;
      });

      frame.open();
    }

    if (removeButton) {
      event.preventDefault();
      const field = removeButton.closest('[data-tikool-media-field]');
      field.querySelector('[data-tikool-media-id]').value = '0';
      field.dataset.tikoolMediaCurrentId = '';
      field.querySelector('[data-tikool-media-preview]').innerHTML = '';
      removeButton.hidden = true;
    }
  });
})();
