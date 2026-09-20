(() => {
  'use strict';

  const restoreMediaIds = () => {
    document.querySelectorAll('[data-trovesia-media-field]').forEach((field) => {
      const input = field.querySelector('[data-trovesia-media-id]');
      if (input && !input.value && field.dataset.trovesiaMediaCurrentId) {
        input.value = field.dataset.trovesiaMediaCurrentId;
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
    const selectButton = event.target.closest('[data-trovesia-media-select]');
    const removeButton = event.target.closest('[data-trovesia-media-remove]');

    if (selectButton) {
      event.preventDefault();
      const field = selectButton.closest('[data-trovesia-media-field]');
      const frame = wp.media({
        title: trovesiaHomeBannerAdmin.title,
        button: { text: trovesiaHomeBannerAdmin.button },
        library: { type: 'image' },
        multiple: false,
      });

      frame.on('select', () => {
        const attachment = frame.state().get('selection').first().toJSON();
        const previewUrl = attachment.sizes?.medium?.url || attachment.url;
        field.querySelector('[data-trovesia-media-id]').value = attachment.id;
        field.dataset.trovesiaMediaCurrentId = attachment.id;
        field.querySelector('[data-trovesia-media-preview]').innerHTML = `<img src="${previewUrl}" alt="">`;
        field.querySelector('[data-trovesia-media-remove]').hidden = false;
      });

      frame.open();
    }

    if (removeButton) {
      event.preventDefault();
      const field = removeButton.closest('[data-trovesia-media-field]');
      field.querySelector('[data-trovesia-media-id]').value = '0';
      field.dataset.trovesiaMediaCurrentId = '';
      field.querySelector('[data-trovesia-media-preview]').innerHTML = '';
      removeButton.hidden = true;
    }
  });
})();
