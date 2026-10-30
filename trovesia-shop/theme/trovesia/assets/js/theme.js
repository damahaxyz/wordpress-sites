(() => {
  const toggle = document.querySelector(".menu-toggle");
  const navigation = document.querySelector(".primary-navigation");
  const header = document.querySelector(".site-header");
  const cartTrigger = document.querySelector(".header-cart");
  const cartDrawer = document.querySelector(".trovesia-cart-drawer");
  const cartClosers = document.querySelectorAll("[data-cart-drawer-close]");
  const accountMenu = document.querySelector('.trovesia-account-page .woocommerce-MyAccount-navigation ul');
  const accountCurrent = accountMenu?.querySelector('[aria-current="page"]');
  if (accountCurrent && accountMenu.scrollWidth > accountMenu.clientWidth) {
    const menuBounds = accountMenu.getBoundingClientRect();
    const itemBounds = accountCurrent.getBoundingClientRect();
    accountMenu.scrollLeft += itemBounds.left - menuBounds.left - (menuBounds.width - itemBounds.width) / 2;
  }
  const noticeSelector = [
    ".woocommerce-message",
    ".woocommerce-info",
    ".woocommerce-error",
    ".wc-block-components-notice-banner",
  ].join(",");
  const managedNotices = new Set();
  let noticeCollectionFrame = null;

  const noticeIsError = (notice) => (
    notice.classList.contains("woocommerce-error")
    || notice.classList.contains("is-error")
  );

  const activeNotices = () => {
    const notices = [...managedNotices].filter((notice) => notice.isConnected && !notice.classList.contains("is-closing"));
    [...managedNotices].forEach((notice) => {
      if (!notice.isConnected) {
        managedNotices.delete(notice);
      }
    });
    return notices;
  };

  const layoutNotices = () => {
    let offset = window.innerWidth <= 782 ? 16 : 112;
    activeNotices().forEach((notice) => {
      notice.style.setProperty("--trovesia-notice-offset", `${offset}px`);
      offset += notice.getBoundingClientRect().height + 12;
    });
  };

  const dismissNotice = (notice) => {
    if (!notice || notice.classList.contains("is-closing")) {
      return;
    }

    window.clearTimeout(notice.trovesiaNoticeTimer);
    notice.classList.add("is-closing");
    notice.classList.remove("is-visible");
    window.setTimeout(() => {
      managedNotices.delete(notice);
      notice.remove();
      layoutNotices();
    }, 280);
  };

  const startNoticeTimer = (notice, duration) => {
    window.clearTimeout(notice.trovesiaNoticeTimer);
    notice.trovesiaNoticeRemaining = duration;
    notice.trovesiaNoticeDeadline = Date.now() + duration;
    notice.trovesiaNoticeTimer = window.setTimeout(() => dismissNotice(notice), duration);
  };

  const pauseNoticeTimer = (notice) => {
    if (notice.classList.contains("is-paused")) {
      return;
    }
    notice.trovesiaNoticeRemaining = Math.max(500, notice.trovesiaNoticeDeadline - Date.now());
    window.clearTimeout(notice.trovesiaNoticeTimer);
    notice.classList.add("is-paused");
  };

  const resumeNoticeTimer = (notice) => {
    if (!notice.classList.contains("is-paused") || notice.classList.contains("is-closing")) {
      return;
    }
    notice.classList.remove("is-paused");
    notice.trovesiaNoticeDeadline = Date.now() + notice.trovesiaNoticeRemaining;
    notice.trovesiaNoticeTimer = window.setTimeout(
      () => dismissNotice(notice),
      notice.trovesiaNoticeRemaining,
    );
  };

  const initializeNotice = (notice) => {
    if (!(notice instanceof HTMLElement) || notice.dataset.trovesiaToastReady === "true") {
      return;
    }

    // Account empty states and verification instructions are page content.
    // Transaction feedback in the notices wrapper still uses dismissible toasts.
    if (notice.matches('.woocommerce-info, .wc-block-components-notice-banner.is-info')
      && notice.closest('.trovesia-account-page .woocommerce-MyAccount-content')
      && !notice.closest('.woocommerce-notices-wrapper')) {
      return;
    }

    const config = window.trovesiaCart || {};
    const isError = noticeIsError(notice);
    const duration = Number.parseInt(
      isError ? config.noticeErrorDuration : config.noticeDuration,
      10,
    ) || (isError ? 30000 : 18000);
    const closeButton = document.createElement("button");
    closeButton.type = "button";
    closeButton.className = "trovesia-toast-notice__close";
    closeButton.setAttribute("aria-label", config.noticeCloseLabel || "Close notification");
    closeButton.innerHTML = "&times;";

    if (notice.matches("ul, ol")) {
      const closeItem = document.createElement("li");
      closeItem.className = "trovesia-toast-notice__close-item";
      closeItem.append(closeButton);
      notice.append(closeItem);
    } else {
      notice.append(closeButton);
    }

    notice.dataset.trovesiaToastReady = "true";
    notice.classList.add("trovesia-toast-notice", isError ? "is-error" : "is-standard");
    notice.style.setProperty("--trovesia-notice-duration", `${duration}ms`);
    notice.setAttribute("aria-live", isError ? "assertive" : "polite");
    if (!notice.hasAttribute("role")) {
      notice.setAttribute("role", isError ? "alert" : "status");
    }

    managedNotices.add(notice);
    closeButton.addEventListener("click", () => dismissNotice(notice));
    notice.addEventListener("pointerenter", () => pauseNoticeTimer(notice));
    notice.addEventListener("pointerleave", () => resumeNoticeTimer(notice));
    notice.addEventListener("focusin", (event) => {
      if (event.target !== notice) {
        pauseNoticeTimer(notice);
      }
    });
    notice.addEventListener("focusout", () => {
      window.setTimeout(() => {
        if (!notice.contains(document.activeElement)) {
          resumeNoticeTimer(notice);
        }
      }, 0);
    });

    startNoticeTimer(notice, duration);
    window.requestAnimationFrame(() => {
      layoutNotices();
      notice.classList.add("is-visible");
    });
  };

  const collectNotices = () => {
    document.querySelectorAll(noticeSelector).forEach(initializeNotice);
    layoutNotices();
  };

  const queueNoticeCollection = () => {
    if (noticeCollectionFrame !== null) {
      return;
    }
    noticeCollectionFrame = window.requestAnimationFrame(() => {
      noticeCollectionFrame = null;
      collectNotices();
    });
  };

  const showNotice = (message, type = "error") => {
    const notice = document.createElement("div");
    notice.className = type === "error" ? "woocommerce-error" : "woocommerce-info";
    notice.textContent = message;
    document.body.append(notice);
    initializeNotice(notice);
  };

  collectNotices();
  new MutationObserver(queueNoticeCollection).observe(document.body, { childList: true, subtree: true });

  const setHeaderState = () => {
    if (header) {
      header.classList.toggle("is-scrolled", window.scrollY > 12);
    }
  };

  setHeaderState();
  window.addEventListener("scroll", setHeaderState, { passive: true });

  const syncMenuHeight = () => {
    if (!header || !navigation) {
      return;
    }

    const available = Math.max(0, window.innerHeight - header.getBoundingClientRect().bottom);
    navigation.style.setProperty("--trovesia-menu-height", `${available}px`);
  };

  const closeMenu = (restoreFocus = false) => {
    if (!toggle || !navigation) {
      return;
    }

    const wasOpen = navigation.classList.contains("is-open");
    navigation.classList.remove("is-open");
    toggle.setAttribute("aria-expanded", "false");
    document.body.classList.remove("menu-open");

    if (restoreFocus && wasOpen) {
      toggle.focus();
    }
  };

  const closeCart = (restoreFocus = false) => {
    if (!cartTrigger || !cartDrawer) {
      return;
    }

    const wasOpen = document.body.classList.contains("cart-drawer-open");
    document.body.classList.remove("cart-drawer-open");
    cartTrigger.setAttribute("aria-expanded", "false");
    cartDrawer.setAttribute("aria-hidden", "true");

    if (restoreFocus && wasOpen) {
      cartTrigger.focus();
    }
  };

  const openCart = () => {
    if (!cartTrigger || !cartDrawer) {
      return;
    }

    closeMenu();
    document.body.classList.add("cart-drawer-open");
    cartTrigger.setAttribute("aria-expanded", "true");
    cartDrawer.setAttribute("aria-hidden", "false");
    cartDrawer.querySelector("[data-cart-drawer-close]")?.focus();
  };

  if (toggle && navigation) {
    toggle.addEventListener("click", () => {
      const willOpen = !navigation.classList.contains("is-open");
      if (willOpen) {
        closeCart();
        syncMenuHeight();
      }
      navigation.classList.toggle("is-open", willOpen);
      toggle.setAttribute("aria-expanded", String(willOpen));
      document.body.classList.toggle("menu-open", willOpen);
    });

    navigation.addEventListener("click", (event) => {
      if (event.target.closest("a")) {
        closeMenu();
      }
    });
  }

  cartTrigger?.addEventListener("click", openCart);
  cartClosers.forEach((closer) => closer.addEventListener("click", () => closeCart(true)));

  if (window.jQuery) {
    window.jQuery(document.body).on("added_to_cart", openCart);
  }

  document.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-mini-cart-quantity-change]");
    if (!button || button.disabled) {
      return;
    }

    const control = button.closest("[data-cart-item-key]");
    const config = window.trovesiaCart || {};
    const current = Number.parseInt(control?.dataset.quantity || "1", 10);
    const direction = Number.parseInt(button.dataset.miniCartQuantityChange || "0", 10);
    const quantity = Math.max(1, current + direction);
    if (!control || !config.ajaxUrl || !config.nonce || quantity === current) {
      return;
    }

    cartDrawer?.classList.add("is-updating");
    control.querySelectorAll("button").forEach((item) => { item.disabled = true; });

    try {
      const body = new URLSearchParams({
        action: "trovesia_update_cart_quantity",
        nonce: config.nonce,
        cart_item_key: control.dataset.cartItemKey,
        quantity: String(quantity),
      });
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString(),
      });
      const payload = await response.json();
      if (!response.ok || !payload.fragments) {
        throw new Error(payload?.data?.message || config.error);
      }

      Object.entries(payload.fragments).forEach(([selector, html]) => {
        document.querySelectorAll(selector).forEach((node) => { node.outerHTML = html; });
      });
      window.jQuery?.(document.body).trigger("wc_fragments_refreshed");
    } catch (error) {
      showNotice(error.message || config.error);
    } finally {
      cartDrawer?.classList.remove("is-updating");
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeMenu(true);
      closeCart(true);
    }
  });

  window.addEventListener("resize", () => {
    syncMenuHeight();
    layoutNotices();
    if (window.innerWidth > 1020) {
      closeMenu();
    }
  });

  const syncQuantityButtons = (quantity) => {
    const input = quantity?.querySelector(".qty");
    if (!input) {
      return;
    }

    const value = Number.parseFloat(input.value || "0");
    const min = input.min === "" ? Number.NEGATIVE_INFINITY : Number.parseFloat(input.min);
    const max = input.max === "" ? Number.POSITIVE_INFINITY : Number.parseFloat(input.max);
    const minus = quantity.querySelector('[data-trovesia-qty-change="-1"]');
    const plus = quantity.querySelector('[data-trovesia-qty-change="1"]');

    if (minus) {
      minus.disabled = Number.isFinite(min) && value <= min;
    }
    if (plus) {
      plus.disabled = Number.isFinite(max) && value >= max;
    }
  };

  document.querySelectorAll(".woocommerce div.product form.cart .quantity").forEach((quantity) => {
    const input = quantity.querySelector(".qty");
    if (!input || !quantity.querySelector(".trovesia-qty-button")) {
      return;
    }

    quantity.classList.add("trovesia-quantity-stepper");
    syncQuantityButtons(quantity);

    quantity.addEventListener("click", (event) => {
      const button = event.target.closest("[data-trovesia-qty-change]");
      if (!button || button.disabled) {
        return;
      }

      if (button.dataset.trovesiaQtyChange === "1") {
        input.stepUp();
      } else {
        input.stepDown();
      }

      input.dispatchEvent(new Event("input", { bubbles: true }));
      input.dispatchEvent(new Event("change", { bubbles: true }));
      syncQuantityButtons(quantity);
    });

    input.addEventListener("input", () => syncQuantityButtons(quantity));
    input.addEventListener("change", () => syncQuantityButtons(quantity));
  });

  document.querySelectorAll("form.cart").forEach((form) => {
    const picker = form.querySelector(".trovesia-variant-picker");
    const attributeSelect = picker
      ? [...form.querySelectorAll(".variations select")].find((select) => select.name === picker.dataset.attributeName)
      : null;
    const variantCards = picker ? [...picker.querySelectorAll(".trovesia-variant-card")] : [];
    const addonCards = [...form.querySelectorAll(".trovesia-product-addon")];

    const choose = (select, value) => {
      const option = [...select.options].find((item) => item.value === value);
      if (!option || option.disabled) {
        return false;
      }

      select.value = value;
      select.dispatchEvent(new Event("change", { bubbles: true }));
      return true;
    };

    const syncAddons = () => {
      addonCards.forEach((card) => {
        const input = card.querySelector(".trovesia-product-addon__input");
        let allowed = [];
        try {
          allowed = JSON.parse(card.dataset.allowedValues || "[]");
        } catch (error) {
          allowed = [];
        }
        const currentValue = attributeSelect?.value || "";
        const isAllowed = !allowed.length || !currentValue || allowed.some(
          (value) => String(value).toLocaleLowerCase() === currentValue.toLocaleLowerCase(),
        );
        card.hidden = !isAllowed;
        if (!isAllowed && input) {
          input.checked = false;
        }
        card.classList.toggle("is-selected", Boolean(input?.checked));
      });
    };

    addonCards.forEach((card) => {
      card.querySelector(".trovesia-product-addon__input")?.addEventListener("change", syncAddons);
    });
    form.querySelectorAll(".trovesia-addon-group__none-input").forEach((input) => {
      input.addEventListener("change", syncAddons);
    });

    if (picker && attributeSelect && variantCards.length) {
      const syncVariants = () => {
        variantCards.forEach((card) => {
          const selected = card.dataset.attributeValue === attributeSelect.value;
          card.classList.toggle("is-selected", selected);
          card.setAttribute("aria-pressed", String(selected));
        });
        syncAddons();
      };

      variantCards.forEach((card) => {
        card.addEventListener("click", () => {
          choose(attributeSelect, card.dataset.attributeValue || "");
          syncVariants();
        });
      });

      if (!attributeSelect.value) {
        choose(attributeSelect, picker.dataset.defaultValue || variantCards.find((card) => !card.disabled)?.dataset.attributeValue || "");
      }

      attributeSelect.addEventListener("change", syncVariants);

      if (window.jQuery) {
        window.jQuery(form).on("found_variation reset_data woocommerce_variation_has_changed", syncVariants);
      }

      syncVariants();
      picker.classList.add("is-ready");
    } else {
      syncAddons();
    }
  });
})();
