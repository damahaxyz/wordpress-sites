(() => {
  const body = document.body;
  const header = document.querySelector('[data-site-header]');
  const announcement = document.querySelector('.announcement-bar');
  const toggle = document.querySelector('.menu-toggle');
  const navigation = document.querySelector('.primary-navigation');
  const menuBackdrop = document.querySelector('.mobile-menu-backdrop');
  const searchToggle = document.querySelector('.search-toggle');
  const searchPanel = document.querySelector('.site-search-panel');
  const searchClose = document.querySelector('.search-close');
  const cartToggle = document.querySelector('[data-mini-cart-open]');
  const miniCart = document.querySelector('.mini-cart-panel');
  const miniCartBackdrop = document.querySelector('.mini-cart-backdrop');
  const miniCartClose = document.querySelector('.mini-cart-panel__close');
  let miniCartPreviousFocus = null;
  let miniCartHeaderPlaceholder = null;
  let searchHeaderPlaceholder = null;
  let miniCartScrollPosition = 0;
  let miniCartBodyStyles = null;
  let toastRegion = null;

  document.documentElement.classList.add('js-ready');

  const closeMenu = () => {
    if (!toggle || !navigation) return;
    toggle.setAttribute('aria-expanded', 'false');
    navigation.classList.remove('is-open');
    body.classList.remove('menu-open');
  };

  const closeSearch = () => {
    if (!searchToggle || !searchPanel) return;
    searchToggle.setAttribute('aria-expanded', 'false');
    searchPanel.hidden = true;
    body.classList.remove('search-open');
    searchHeaderPlaceholder?.remove();
    searchHeaderPlaceholder = null;
    body.style.removeProperty('--search-header-top');
  };

  const lockSearchHeader = () => {
    if (!header || searchHeaderPlaceholder) return;
    body.style.setProperty('--search-header-top', `${Math.max(0, header.getBoundingClientRect().top)}px`);
    searchHeaderPlaceholder = document.createElement('div');
    searchHeaderPlaceholder.className = 'search-header-placeholder';
    searchHeaderPlaceholder.setAttribute('aria-hidden', 'true');
    searchHeaderPlaceholder.style.height = `${header.getBoundingClientRect().height}px`;
    header.after(searchHeaderPlaceholder);
  };

  const unlockMiniCartHeader = () => {
    miniCartHeaderPlaceholder?.remove();
    miniCartHeaderPlaceholder = null;
    body.classList.remove('mini-cart-announcement-visible');
    body.style.removeProperty('--mini-cart-header-top');
  };

  const lockMiniCartHeader = () => {
    if (!header || miniCartHeaderPlaceholder) return;
    const headerTop = Math.max(0, header.getBoundingClientRect().top);
    const stickyTop = Number.parseFloat(window.getComputedStyle(header).top) || 0;
    body.style.setProperty('--mini-cart-header-top', `${headerTop}px`);
    body.classList.toggle(
      'mini-cart-announcement-visible',
      Boolean(announcement) && headerTop > stickyTop + 0.5
    );
    miniCartHeaderPlaceholder = document.createElement('div');
    miniCartHeaderPlaceholder.className = 'mini-cart-header-placeholder';
    miniCartHeaderPlaceholder.setAttribute('aria-hidden', 'true');
    miniCartHeaderPlaceholder.style.height = `${header.getBoundingClientRect().height}px`;
    header.after(miniCartHeaderPlaceholder);
  };

  const unlockMiniCartPage = () => {
    if (!miniCartBodyStyles) return;
    const root = document.documentElement;
    const rootScrollBehavior = root.style.scrollBehavior;
    const bodyScrollBehavior = body.style.scrollBehavior;
    root.style.scrollBehavior = 'auto';
    body.style.scrollBehavior = 'auto';
    Object.entries(miniCartBodyStyles).forEach(([property, value]) => {
      body.style[property] = value;
    });
    miniCartBodyStyles = null;
    window.scrollTo({
      top: miniCartScrollPosition,
      left: 0,
      behavior: 'instant',
    });
    window.requestAnimationFrame(() => {
      root.style.scrollBehavior = rootScrollBehavior;
      body.style.scrollBehavior = bodyScrollBehavior;
    });
  };

  const lockMiniCartPage = () => {
    if (miniCartBodyStyles) return;
    miniCartScrollPosition = window.scrollY;
    miniCartBodyStyles = {
      position: body.style.position,
      top: body.style.top,
      right: body.style.right,
      left: body.style.left,
      width: body.style.width,
      paddingRight: body.style.paddingRight,
    };
    // WordPress offsets logged-in pages with a top margin for the admin bar.
    // A fixed body otherwise ignores that document offset and slides the
    // announcement beneath the admin bar, leaving a visible gap above header.
    const adminBarOffset = body.classList.contains('admin-bar')
      ? Math.max(0, Number.parseFloat(window.getComputedStyle(document.documentElement).marginTop) || 0)
      : 0;
    const scrollbarWidth = Math.max(0, window.innerWidth - document.documentElement.clientWidth);
    body.style.position = 'fixed';
    body.style.top = `${adminBarOffset - miniCartScrollPosition}px`;
    body.style.right = '0';
    body.style.left = '0';
    body.style.width = '100%';
    if (scrollbarWidth) body.style.paddingRight = `${scrollbarWidth}px`;
  };

  const closeMiniCart = ({ restoreFocus = true } = {}) => {
    if (!cartToggle || !miniCart) return;
    const wasOpen = body.classList.contains('mini-cart-open');
    body.classList.remove('mini-cart-open');
    cartToggle.setAttribute('aria-expanded', 'false');
    miniCart.setAttribute('aria-hidden', 'true');
    miniCart.inert = true;
    unlockMiniCartHeader();
    unlockMiniCartPage();
    if (wasOpen && restoreFocus && miniCartPreviousFocus instanceof HTMLElement) {
      miniCartPreviousFocus.focus();
    }
    if (wasOpen) miniCartPreviousFocus = null;
  };

  const openMiniCart = () => {
    if (!cartToggle || !miniCart) return;
    closeMenu();
    closeSearch();
    miniCartPreviousFocus = document.activeElement;
    lockMiniCartHeader();
    lockMiniCartPage();
    body.classList.add('mini-cart-open');
    cartToggle.setAttribute('aria-expanded', 'true');
    miniCart.setAttribute('aria-hidden', 'false');
    miniCart.inert = false;
    window.requestAnimationFrame(() => miniCartClose?.focus());
  };

  toggle?.addEventListener('click', () => {
    const opening = toggle.getAttribute('aria-expanded') !== 'true';
    closeSearch();
    closeMiniCart({ restoreFocus: false });
    toggle.setAttribute('aria-expanded', String(opening));
    navigation?.classList.toggle('is-open', opening);
    body.classList.toggle('menu-open', opening);
  });
  menuBackdrop?.addEventListener('click', closeMenu);

  searchToggle?.addEventListener('click', () => {
    const opening = searchToggle.getAttribute('aria-expanded') !== 'true';
    closeMenu();
    closeMiniCart({ restoreFocus: false });
    if (opening) lockSearchHeader();
    searchToggle.setAttribute('aria-expanded', String(opening));
    if (searchPanel) searchPanel.hidden = !opening;
    body.classList.toggle('search-open', opening);
    if (opening) {
      window.requestAnimationFrame(() => searchPanel?.querySelector('input[type="search"]')?.focus());
    } else {
      searchHeaderPlaceholder?.remove();
      searchHeaderPlaceholder = null;
      body.style.removeProperty('--search-header-top');
    }
  });

  searchClose?.addEventListener('click', closeSearch);
  navigation?.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeMenu));
  cartToggle?.addEventListener('click', (event) => {
    event.preventDefault();
    if (body.classList.contains('mini-cart-open')) {
      closeMiniCart();
    } else {
      openMiniCart();
    }
  });
  miniCartBackdrop?.addEventListener('click', () => closeMiniCart());
  miniCartClose?.addEventListener('click', () => closeMiniCart());

  const getToastRegion = () => {
    if (toastRegion) return toastRegion;
    toastRegion = document.createElement('div');
    toastRegion.className = 'manga-toast-region';
    toastRegion.setAttribute('aria-live', 'polite');
    toastRegion.setAttribute('aria-label', 'Store notifications');
    body.append(toastRegion);
    return toastRegion;
  };

  const dismissToast = (toast) => {
    if (!toast || toast.classList.contains('is-leaving')) return;
    toast.classList.add('is-leaving');
    window.setTimeout(() => toast.remove(), 220);
  };

  const presentToast = (notice) => {
    if (!notice || notice.dataset.mangaToast === 'true') return;
    notice.dataset.mangaToast = 'true';
    notice.classList.add('manga-toast');
    notice.setAttribute('role', 'status');

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'manga-toast__close';
    closeButton.setAttribute('aria-label', 'Dismiss notification');
    closeButton.textContent = '×';
    notice.append(closeButton);
    closeButton.addEventListener('click', () => dismissToast(notice));

    getToastRegion().append(notice);
    let timer = window.setTimeout(() => dismissToast(notice), 4500);
    notice.addEventListener('mouseenter', () => window.clearTimeout(timer));
    notice.addEventListener('mouseleave', () => {
      timer = window.setTimeout(() => dismissToast(notice), 1800);
    });
  };

  const createAddedToCartToast = (productName = '') => {
    const notice = document.createElement('div');
    notice.className = 'woocommerce-message';
    const message = productName ? `“${productName}” has been added to your cart.` : 'Product added to your cart.';
    notice.append(document.createTextNode(message));

    const cartUrl = window.wc_add_to_cart_params?.cart_url;
    if (cartUrl) {
      const cartLink = document.createElement('a');
      cartLink.className = 'button wc-forward';
      cartLink.href = cartUrl;
      cartLink.textContent = 'View cart';
      notice.prepend(cartLink);
    }
    presentToast(notice);
  };

  document.querySelectorAll('.woocommerce-notices-wrapper .woocommerce-message').forEach(presentToast);

  const noticeObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (!(node instanceof HTMLElement)) return;
        if (node.matches('.woocommerce-message')) presentToast(node);
        node.querySelectorAll?.('.woocommerce-message').forEach(presentToast);
      });
    });
  });
  noticeObserver.observe(body, { childList: true, subtree: true });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeMenu();
      closeSearch();
      closeMiniCart();
    }

    if (event.key === 'Tab' && body.classList.contains('mini-cart-open') && miniCart) {
      const focusable = [...miniCart.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])')];
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
    }
  });

  if (window.jQuery) {
    window.jQuery(document.body).on('added_to_cart', (event, fragments, cartHash, button) => {
      const productName = button?.closest('li.product')?.find('.woocommerce-loop-product__title')?.first()?.text()?.trim() || '';
      createAddedToCartToast(productName);
    });
  }

  const updateHeader = () => header?.classList.toggle('is-scrolled', window.scrollY > 16);
  updateHeader();
  window.addEventListener('scroll', updateHeader, { passive: true });
  window.addEventListener('resize', () => {
    if (window.innerWidth > 880) closeMenu();
    if (miniCartHeaderPlaceholder && header) {
      miniCartHeaderPlaceholder.style.height = `${header.getBoundingClientRect().height}px`;
    }
  });

  const revealItems = document.querySelectorAll('[data-reveal]');
  if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    revealItems.forEach((item) => observer.observe(item));
  } else {
    revealItems.forEach((item) => item.classList.add('is-visible'));
  }

  /**
   * Turn native WooCommerce variation selects into glanceable option buttons.
   * The original select remains in the form for WooCommerce availability,
   * pricing and add-to-cart validation.
   */
  document.querySelectorAll('form.variations_form .variations select').forEach((select) => {
    if (select.dataset.mangaOptionsReady === 'true') return;

    const valueCell = select.closest('td.value');
    if (!valueCell) return;

    const label = select.closest('tr')?.querySelector('label')?.textContent?.trim() || select.name;
    const optionList = document.createElement('div');
    optionList.className = 'manga-variation-options';
    optionList.setAttribute('role', 'radiogroup');
    optionList.setAttribute('aria-label', label);

    [...select.options].filter((option) => option.value !== '').forEach((option) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'manga-variation-option';
      button.dataset.value = option.value;
      button.setAttribute('role', 'radio');
      button.textContent = option.textContent.trim();
      optionList.append(button);

      button.addEventListener('click', () => {
        if (button.disabled) return;
        select.value = button.dataset.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });

    const syncOptions = () => {
      const options = new Map([...select.options].map((option) => [option.value, option]));
      optionList.querySelectorAll('.manga-variation-option').forEach((button) => {
        const selected = button.dataset.value === select.value;
        const sourceOption = options.get(button.dataset.value);
        const disabled = !sourceOption || sourceOption.disabled;
        button.classList.toggle('is-selected', selected);
        button.disabled = disabled;
        button.setAttribute('aria-checked', String(selected));
        button.setAttribute('aria-disabled', String(disabled));
      });
    };

    select.dataset.mangaOptionsReady = 'true';
    select.classList.add('manga-variation-select--enhanced');
    valueCell.classList.add('has-manga-variation-options');
    valueCell.insertBefore(optionList, select);
    select.addEventListener('change', () => window.requestAnimationFrame(syncOptions));
    new MutationObserver(syncOptions).observe(select, {
      attributes: true,
      childList: true,
      subtree: true,
      attributeFilter: ['disabled', 'selected'],
    });
    syncOptions();
  });

  /**
   * Keep the purchase panel height stable while WooCommerce switches variants.
   * Variant pricing replaces the primary price instead of inserting another
   * price row above the quantity and add-to-cart controls.
   */
  document.querySelectorAll('form.variations_form').forEach((form) => {
    const summary = form.closest('.summary');
    const primaryPrice = summary?.querySelector(':scope > p.price');
    const variationPanel = form.querySelector('.single_variation');

    if (!primaryPrice || !variationPanel || typeof window.jQuery !== 'function') return;

    const originalPrice = primaryPrice.innerHTML;
    const syncVariationDetails = () => {
      const description = variationPanel.querySelector('.woocommerce-variation-description')?.textContent?.trim();
      const availability = variationPanel.querySelector('.woocommerce-variation-availability')?.textContent?.trim();
      variationPanel.classList.toggle('has-manga-variation-details', Boolean(description || availability));
    };

    form.classList.add('manga-variation-price-synced');
    window.jQuery(form)
      .on('show_variation', (_event, variation) => {
        primaryPrice.innerHTML = variation?.price_html || originalPrice;
        window.requestAnimationFrame(syncVariationDetails);
      })
      .on('hide_variation reset_data', () => {
        primaryPrice.innerHTML = originalPrice;
        window.requestAnimationFrame(syncVariationDetails);
      });

    syncVariationDetails();
  });

  /**
   * Add accessible quantity controls to the single-product purchase panel.
   * Values still flow through WooCommerce's native number input so stock,
   * variation limits and add-to-cart validation continue to work normally.
   */
  const initializeQuantitySteppers = (scope = document) => {
    scope.querySelectorAll('.woocommerce div.product form.cart .quantity').forEach((quantity) => {
      const input = quantity.querySelector('input.qty');
      if (!input || quantity.dataset.mangaStepperReady === 'true') return;

      const decrease = document.createElement('button');
      const increase = document.createElement('button');

      decrease.type = 'button';
      decrease.className = 'manga-quantity-stepper__button manga-quantity-stepper__button--decrease';
      decrease.setAttribute('aria-label', 'Decrease quantity');
      decrease.textContent = '−';

      increase.type = 'button';
      increase.className = 'manga-quantity-stepper__button manga-quantity-stepper__button--increase';
      increase.setAttribute('aria-label', 'Increase quantity');
      increase.textContent = '+';

      const getNumber = (value, fallback) => {
        const parsed = Number.parseFloat(value);
        return Number.isFinite(parsed) ? parsed : fallback;
      };
      const getPrecision = (value) => {
        const stringValue = String(value);
        return stringValue.includes('.') ? stringValue.split('.')[1].length : 0;
      };
      const getLimits = () => {
        const step = Math.max(getNumber(input.step, 1), Number.EPSILON);
        const min = getNumber(input.min, 0);
        const max = input.max === '' ? Number.POSITIVE_INFINITY : getNumber(input.max, Number.POSITIVE_INFINITY);
        const precision = Math.max(getPrecision(input.step), getPrecision(input.min));
        return { step, min, max, precision };
      };
      const updateButtons = () => {
        const { min, max } = getLimits();
        const value = getNumber(input.value, min);
        decrease.disabled = input.disabled || value <= min;
        increase.disabled = input.disabled || value >= max;
      };
      const changeQuantity = (direction) => {
        const { step, min, max, precision } = getLimits();
        const current = getNumber(input.value, min);
        const next = Math.min(max, Math.max(min, current + (direction * step)));
        input.value = next.toFixed(precision);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        updateButtons();
      };

      decrease.addEventListener('click', () => changeQuantity(-1));
      increase.addEventListener('click', () => changeQuantity(1));
      input.addEventListener('input', updateButtons);
      input.addEventListener('change', updateButtons);

      quantity.dataset.mangaStepperReady = 'true';
      quantity.classList.add('manga-quantity-stepper');
      input.before(decrease);
      input.after(increase);
      new MutationObserver(updateButtons).observe(input, {
        attributes: true,
        attributeFilter: ['disabled', 'max', 'min', 'step'],
      });
      updateButtons();
    });
  };

  initializeQuantitySteppers();
  if (window.jQuery) {
    window.jQuery(document.body).on('found_variation reset_data updated_wc_div', () => {
      window.requestAnimationFrame(() => initializeQuantitySteppers());
    });
  }

  const filterPanel = document.querySelector('.shop-filter-panel');
  const filterToggle = document.querySelector('[data-shop-filter-open]');

  if (filterPanel && filterToggle) {
    const closeFilters = () => {
      body.classList.remove('shop-filters-open');
      filterToggle.setAttribute('aria-expanded', 'false');
    };

    filterToggle.addEventListener('click', () => {
      body.classList.add('shop-filters-open');
      filterToggle.setAttribute('aria-expanded', 'true');
      window.requestAnimationFrame(() => filterPanel.querySelector('input, summary, button')?.focus());
    });

    document.querySelectorAll('[data-shop-filter-close]').forEach((control) => {
      control.addEventListener('click', closeFilters);
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && body.classList.contains('shop-filters-open')) {
        closeFilters();
        filterToggle.focus();
      }
    });

    window.addEventListener('resize', () => {
      if (window.innerWidth > 980) closeFilters();
    });
  }

  document.querySelectorAll('.shop-filter-form input[type="number"]').forEach((input) => {
    const fieldName = input.name;
    const syncPriceField = () => {
      if (input.value.trim() === '') {
        input.removeAttribute('name');
      } else {
        input.name = fieldName;
      }
    };
    input.addEventListener('input', syncPriceField);
    syncPriceField();
  });
})();
