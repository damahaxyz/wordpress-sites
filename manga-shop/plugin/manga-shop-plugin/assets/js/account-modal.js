(() => {
  const modal = document.querySelector('[data-manga-account-modal]');
  if (!modal) return;

  const body = document.body;
  const root = document.documentElement;
  const config = window.mangaShopAccount || {};
  const dialog = modal.querySelector('[role="dialog"]');
  const notice = modal.querySelector('[data-account-notice]');
  const tabs = [...modal.querySelectorAll('[data-account-tab]')];
  const panels = [...modal.querySelectorAll('[data-account-panel]')];
  let opener = null;
  let scrollPosition = 0;
  let bodyStyles = null;

  const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
  ].join(',');

  const setNotice = (message = '', type = '') => {
    if (!notice) return;
    notice.textContent = message;
    notice.hidden = !message;
    notice.classList.toggle('is-error', type === 'error');
    notice.classList.toggle('is-success', type === 'success');
  };

  const selectTab = (name, focusTab = false) => {
    const target = panels.some((panel) => panel.dataset.accountPanel === name) ? name : 'login';
    tabs.forEach((tab) => {
      const selected = tab.dataset.accountTab === target;
      tab.classList.toggle('is-active', selected);
      tab.setAttribute('aria-selected', String(selected));
      tab.tabIndex = selected ? 0 : -1;
      if (selected && focusTab) tab.focus();
    });
    panels.forEach((panel) => {
      panel.hidden = panel.dataset.accountPanel !== target;
    });
    setNotice();
  };

  const lockPage = () => {
    if (bodyStyles) return;
    scrollPosition = window.scrollY;
    bodyStyles = {
      position: body.style.position,
      top: body.style.top,
      right: body.style.right,
      left: body.style.left,
      width: body.style.width,
      paddingRight: body.style.paddingRight,
    };
    const adminBarOffset = body.classList.contains('admin-bar')
      ? Math.max(0, Number.parseFloat(window.getComputedStyle(root).marginTop) || 0)
      : 0;
    const scrollbarWidth = Math.max(0, window.innerWidth - root.clientWidth);
    body.style.position = 'fixed';
    body.style.top = `${adminBarOffset - scrollPosition}px`;
    body.style.right = '0';
    body.style.left = '0';
    body.style.width = '100%';
    if (scrollbarWidth) body.style.paddingRight = `${scrollbarWidth}px`;
  };

  const unlockPage = () => {
    if (!bodyStyles) return;
    const rootScrollBehavior = root.style.scrollBehavior;
    const bodyScrollBehavior = body.style.scrollBehavior;
    root.style.scrollBehavior = 'auto';
    body.style.scrollBehavior = 'auto';
    Object.entries(bodyStyles).forEach(([property, value]) => {
      body.style[property] = value;
    });
    bodyStyles = null;
    window.scrollTo({ top: scrollPosition, left: 0, behavior: 'instant' });
    window.requestAnimationFrame(() => {
      root.style.scrollBehavior = rootScrollBehavior;
      body.style.scrollBehavior = bodyScrollBehavior;
    });
  };

  const closeModal = (restoreFocus = true) => {
    if (modal.hidden) return;
    modal.hidden = true;
    modal.inert = true;
    body.classList.remove('manga-account-modal-open');
    document.querySelectorAll('[data-manga-account-trigger]').forEach((trigger) => {
      trigger.setAttribute('aria-expanded', 'false');
    });
    unlockPage();
    setNotice();
    if (restoreFocus && opener instanceof HTMLElement) opener.focus();
    opener = null;
  };

  const closeCompetingPanels = () => {
    if (body.classList.contains('mini-cart-open')) {
      document.querySelector('.mini-cart-panel__close')?.click();
    }
    body.classList.remove('menu-open', 'search-open');
    document.querySelector('.primary-navigation')?.classList.remove('is-open');
    document.querySelector('.menu-toggle')?.setAttribute('aria-expanded', 'false');
    document.querySelector('.search-toggle')?.setAttribute('aria-expanded', 'false');
    const searchPanel = document.querySelector('.site-search-panel');
    if (searchPanel) searchPanel.hidden = true;
  };

  const openModal = (panel = 'login', trigger = null) => {
    opener = trigger instanceof HTMLElement ? trigger : document.activeElement;
    closeCompetingPanels();
    selectTab(panel);
    lockPage();
    modal.hidden = false;
    modal.inert = false;
    body.classList.add('manga-account-modal-open');
    document.querySelectorAll('[data-manga-account-trigger]').forEach((accountTrigger) => {
      accountTrigger.setAttribute('aria-expanded', 'true');
    });
    window.requestAnimationFrame(() => {
      const activePanel = modal.querySelector(`[data-account-panel="${panel}"]:not([hidden])`)
        || modal.querySelector('[data-account-panel="login"]');
      activePanel?.querySelector('input:not([type="hidden"])')?.focus();
    });
  };

  const isAccountLink = (element) => {
    if (!(element instanceof HTMLAnchorElement) || element.closest('[data-manga-account-modal]')) return false;
    try {
      const url = new URL(element.href, window.location.href);
      const configuredPath = String(config.myAccountPath || '/my-account/').replace(/\/+$/, '');
      return url.origin === window.location.origin && url.pathname.replace(/\/+$/, '') === configuredPath;
    } catch (error) {
      return false;
    }
  };

  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    const explicitTrigger = event.target.closest('[data-manga-account-trigger]');
    const checkoutLogin = event.target.closest('.wc-block-checkout__login-prompt, .showlogin');
    const accountLink = event.target.closest('a');

    if (explicitTrigger || checkoutLogin || isAccountLink(accountLink)) {
      event.preventDefault();
      openModal(explicitTrigger?.dataset.mangaAccountTrigger || 'login', explicitTrigger || checkoutLogin || accountLink);
      return;
    }

    if (event.target.closest('[data-account-modal-close]')) {
      closeModal(true);
      return;
    }

    const tab = event.target.closest('[data-account-tab]');
    if (tab) {
      selectTab(tab.dataset.accountTab || 'login', true);
      modal.querySelector(`[data-account-panel="${tab.dataset.accountTab}"] input:not([type="hidden"])`)?.focus();
      return;
    }

    const passwordToggle = event.target.closest('[data-password-toggle]');
    if (passwordToggle) {
      const input = passwordToggle.parentElement?.querySelector('input');
      if (!input) return;
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      passwordToggle.textContent = showing ? (config.show || 'Show') : (config.hide || 'Hide');
      passwordToggle.setAttribute('aria-label', showing
        ? (config.showPassword || 'Show password')
        : (config.hidePassword || 'Hide password'));
      input.focus();
    }
  });

  tabs.forEach((tab, index) => {
    tab.addEventListener('keydown', (event) => {
      if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
      event.preventDefault();
      const next = event.key === 'ArrowRight'
        ? (index + 1) % tabs.length
        : (index - 1 + tabs.length) % tabs.length;
      selectTab(tabs[next].dataset.accountTab || 'login', true);
    });
  });

  modal.querySelectorAll('[data-account-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!form.reportValidity() || !config.ajaxUrl || !config.nonce) return;

      const submit = form.querySelector('[type="submit"]');
      const originalLabel = submit?.textContent || '';
      const requestBody = new URLSearchParams(new FormData(form));
      requestBody.set('action', form.dataset.accountForm || '');
      requestBody.set('nonce', config.nonce);
      setNotice();
      if (submit) {
        submit.disabled = true;
        submit.textContent = config.workingMessage || 'Please wait…';
      }

      try {
        const response = await fetch(config.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: requestBody.toString(),
        });
        const payload = await response.json();
        const message = payload?.data?.message || config.errorMessage || 'Something went wrong. Please try again.';
        if (!response.ok || !payload.success) throw new Error(message);

        setNotice(message, 'success');
        if (payload.data?.reload !== false) {
          window.setTimeout(() => window.location.reload(), 550);
        } else {
          window.setTimeout(() => selectTab('login'), 800);
        }
      } catch (error) {
        setNotice(error.message || config.errorMessage || 'Something went wrong. Please try again.', 'error');
      } finally {
        if (submit) {
          submit.disabled = false;
          submit.textContent = originalLabel;
        }
      }
    });
  });

  document.addEventListener('keydown', (event) => {
    if (modal.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal(true);
      return;
    }
    if (event.key !== 'Tab' || !dialog) return;

    const focusable = [...dialog.querySelectorAll(focusableSelector)]
      .filter((element) => !element.closest('[hidden]') && element.getClientRects().length);
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  modal.inert = true;
})();
