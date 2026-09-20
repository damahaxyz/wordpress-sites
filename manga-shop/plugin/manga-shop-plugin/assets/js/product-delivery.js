(() => {
  const notice = document.querySelector('[data-manga-delivery-estimate]');
  const message = notice?.querySelector('[data-manga-delivery-message]');
  const config = window.mangaShopDelivery;

  if (!notice || !message || !config?.ajaxUrl || !config?.action) return;

  const body = new URLSearchParams({ action: config.action });

  fetch(config.ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    body: body.toString(),
  })
    .then((response) => {
      if (!response.ok) throw new Error(`Delivery estimate request failed: ${response.status}`);
      return response.json();
    })
    .then((payload) => {
      if (!payload?.success || !payload?.data?.eligible || !payload.data.message) return;
      message.textContent = payload.data.message;
      notice.hidden = false;
    })
    .catch(() => {
      notice.hidden = true;
    });
})();
