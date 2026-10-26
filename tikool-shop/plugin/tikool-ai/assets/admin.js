(() => {
  const profiles = document.querySelector('[data-tikool-ai-profiles]');
  const template = document.querySelector('[data-tikool-ai-template]');
  const addButton = document.querySelector('[data-tikool-ai-add]');

  if (!profiles || !template || !addButton) {
    return;
  }

  let nextIndex = profiles.querySelectorAll('[data-tikool-ai-profile]').length;

  addButton.addEventListener('click', () => {
    const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
    profiles.insertAdjacentHTML('beforeend', html);
    const added = profiles.lastElementChild;
    added?.querySelector('input')?.focus();
  });

  profiles.addEventListener('click', (event) => {
    const chooseButton = event.target.closest('[data-tikool-ai-preview-choose]');
    if (chooseButton && window.wp?.media) {
      event.preventDefault();
      const preview = chooseButton.closest('[data-tikool-ai-preview]');
      const input = preview?.querySelector('[data-tikool-ai-preview-id]');
      const imageWrap = preview?.querySelector('[data-tikool-ai-preview-image]');
      const clearButton = preview?.querySelector('[data-tikool-ai-preview-clear]');
      if (!input || !imageWrap || !clearButton) return;

      const frame = window.wp.media({
        title: '选择前台风格示例图',
        button: { text: '使用这张图片' },
        library: { type: 'image' },
        multiple: false,
      });
      frame.on('select', () => {
        const attachment = frame.state().get('selection').first()?.toJSON();
        if (!attachment?.id || !attachment?.url) return;
        const source = attachment.sizes?.thumbnail?.url || attachment.url;
        input.value = String(attachment.id);
        imageWrap.innerHTML = `<img src="${source}" alt="">`;
        imageWrap.hidden = false;
        clearButton.hidden = false;
        chooseButton.textContent = '更换图片';
      });
      frame.open();
      return;
    }

    const clearButton = event.target.closest('[data-tikool-ai-preview-clear]');
    if (clearButton) {
      event.preventDefault();
      const preview = clearButton.closest('[data-tikool-ai-preview]');
      const input = preview?.querySelector('[data-tikool-ai-preview-id]');
      const imageWrap = preview?.querySelector('[data-tikool-ai-preview-image]');
      const chooseButton = preview?.querySelector('[data-tikool-ai-preview-choose]');
      if (!input || !imageWrap || !chooseButton) return;
      input.value = '';
      imageWrap.innerHTML = '';
      imageWrap.hidden = true;
      clearButton.hidden = true;
      chooseButton.textContent = '选择图片';
      return;
    }

    const removeButton = event.target.closest('[data-tikool-ai-remove]');
    if (!removeButton) {
      return;
    }

    removeButton.closest('[data-tikool-ai-profile]')?.remove();
  });
})();
