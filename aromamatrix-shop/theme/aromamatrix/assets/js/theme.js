(() => {
  const accountConfig = window.aromamatrixAccount;
  const accountModal = document.querySelector("[data-account-modal]");

  if (accountConfig && accountModal && !accountConfig.isLoggedIn) {
    const dialog = accountModal.querySelector("[role='dialog']");
    const message = accountModal.querySelector("[data-account-message]");
    const intro = accountModal.querySelector("[data-account-modal-intro]");
    let lastFocusedElement = null;
    let pendingAction = null;

    const setMessage = (copy = "", isError = false) => {
      message.textContent = copy;
      message.classList.toggle("is-error", isError);
    };

    const setTab = (name) => {
      accountModal.querySelectorAll("[data-account-tab]").forEach((tab) => {
        const active = tab.dataset.accountTab === name;
        tab.setAttribute("aria-selected", String(active));
      });
      accountModal.querySelectorAll("[data-account-form]").forEach((form) => {
        form.hidden = form.dataset.accountForm !== name;
      });
      setMessage();
      accountModal.querySelector(`[data-account-form='${name}'] input`)?.focus();
    };

    const closeAccountModal = () => {
      accountModal.hidden = true;
      accountModal.setAttribute("aria-hidden", "true");
      document.body.classList.remove("aromamatrix-account-modal-open");
      lastFocusedElement?.focus();
    };

    const openAccountModal = (action = null) => {
      pendingAction = action;
      lastFocusedElement = document.activeElement;
      accountModal.hidden = false;
      accountModal.setAttribute("aria-hidden", "false");
      document.body.classList.add("aromamatrix-account-modal-open");
      intro.textContent = action ? accountConfig.messages.loginRequired : "Sign in to manage your orders and account details.";
      setTab("login");
      window.setTimeout(() => dialog?.focus(), 0);
    };

    const resumePendingAction = () => {
      const action = pendingAction;
      pendingAction = null;
      if (!action) return;

      if (action.type === "form") {
        action.form.requestSubmit?.(action.submitter);
      } else if (action.type === "link") {
        window.location.assign(action.url);
      }
    };

    const updateHeaderAccount = () => {
      document.querySelectorAll(".header-account").forEach((link) => {
        link.href = accountConfig.accountUrl;
        link.textContent = "My account";
        link.removeAttribute("data-account-modal-open");
      });
    };

    accountModal.querySelectorAll("[data-account-modal-close]").forEach((button) => {
      button.addEventListener("click", closeAccountModal);
    });

    accountModal.querySelectorAll("[data-account-tab]").forEach((tab) => {
      tab.addEventListener("click", () => setTab(tab.dataset.accountTab));
    });

    document.querySelectorAll("[data-account-modal-open]").forEach((trigger) => {
      trigger.addEventListener("click", (event) => {
        if (!trigger.hasAttribute("data-account-modal-open")) {
          return;
        }
        event.preventDefault();
        openAccountModal();
      });
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !accountModal.hidden) {
        closeAccountModal();
      }
    });

    accountModal.querySelectorAll("[data-account-form]").forEach((form) => {
      form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const password = form.elements.password?.value;
        const confirmation = form.elements.password_confirmation?.value;
        if (form.dataset.accountForm === "register" && password !== confirmation) {
          setMessage("The password confirmation does not match.", true);
          return;
        }
        const submitButton = form.querySelector("button[type='submit']");
        submitButton.disabled = true;
        setMessage(accountConfig.messages.working);

        try {
          const payload = new URLSearchParams(new FormData(form));
          payload.set("action", `aromamatrix_account_${form.dataset.accountForm === "register" ? "register" : "login"}`);
          payload.set("nonce", accountConfig.nonce);
          const response = await fetch(accountConfig.ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
            body: payload,
          });
          const result = await response.json();
          if (!response.ok || !result.success) {
            throw new Error(result.data?.message || accountConfig.messages.genericError);
          }
          accountConfig.isLoggedIn = true;
          updateHeaderAccount();
          closeAccountModal();
          resumePendingAction();
        } catch (error) {
          setMessage(error.message || accountConfig.messages.genericError, true);
        } finally {
          submitButton.disabled = false;
        }
      });
    });

    const isAddToCartForm = (form) => form?.matches("form.cart, .aromamatrix-loop-cart");
    document.addEventListener("submit", (event) => {
      if (!accountConfig.requiresLogin || accountConfig.isLoggedIn || !isAddToCartForm(event.target)) return;
      const submitter = event.submitter || event.target.querySelector("button[name='add-to-cart'], .single_add_to_cart_button");
      if (submitter?.disabled || submitter?.getAttribute("aria-disabled") === "true") return;
      event.preventDefault();
      event.stopImmediatePropagation();
      openAccountModal({ type: "form", form: event.target, submitter });
    }, true);

    document.addEventListener("click", (event) => {
      if (!accountConfig.requiresLogin || accountConfig.isLoggedIn) return;
      const link = event.target.closest("a.add_to_cart_button, a[href*='add-to-cart']");
      if (!link) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      openAccountModal({ type: "link", url: link.href });
    }, true);
  }

  const nativeAccountTabs = document.querySelector(".aromamatrix-native-account-tabs");
  const nativeAccount = document.querySelector("#customer_login");

  if (nativeAccountTabs && nativeAccount) {
    const columns = nativeAccount.querySelectorAll(":scope > .u-column1, :scope > .u-column2");
    if (columns.length === 2) {
      const [loginColumn, registerColumn] = columns;
      loginColumn.id = "aromamatrix-native-login";
      registerColumn.id = "aromamatrix-native-register";
      document.body.classList.add("aromamatrix-native-account-tabs-ready");

      const selectNativeTab = (name) => {
        const isLogin = name === "login";
        loginColumn.hidden = !isLogin;
        registerColumn.hidden = isLogin;
        nativeAccountTabs.querySelectorAll("[data-native-account-tab]").forEach((tab) => {
          tab.setAttribute("aria-selected", String(tab.dataset.nativeAccountTab === name));
        });
      };

      nativeAccountTabs.querySelectorAll("[data-native-account-tab]").forEach((tab) => {
        tab.addEventListener("click", () => selectNativeTab(tab.dataset.nativeAccountTab));
      });
      selectNativeTab("login");
    }
  }

  const toggle = document.querySelector(".menu-toggle");
  const navigation = document.querySelector(".primary-navigation");

  if (toggle && navigation) {
    const closeMenu = () => {
      navigation.classList.remove("is-open");
      toggle.setAttribute("aria-expanded", "false");
      document.body.classList.remove("menu-open");
    };

    toggle.addEventListener("click", () => {
      const willOpen = !navigation.classList.contains("is-open");
      navigation.classList.toggle("is-open", willOpen);
      toggle.setAttribute("aria-expanded", String(willOpen));
      document.body.classList.toggle("menu-open", willOpen);
    });

    navigation.addEventListener("click", (event) => {
      if (event.target.closest("a")) {
        closeMenu();
      }
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        closeMenu();
        toggle.focus();
      }
    });

    window.addEventListener("resize", () => {
      if (window.innerWidth > 860) {
        closeMenu();
      }
    });
  }

  const headerSearchToggle = document.querySelector("[data-header-search-open]");
  const headerSearchPanel = document.querySelector("[data-header-search-panel]");

  if (headerSearchToggle && headerSearchPanel) {
    const searchInput = headerSearchPanel.querySelector("input[type='search']");
    const closeHeaderSearch = () => {
      headerSearchPanel.hidden = true;
      headerSearchToggle.setAttribute("aria-expanded", "false");
    };

    headerSearchToggle.addEventListener("click", () => {
      const isOpen = !headerSearchPanel.hidden;
      headerSearchPanel.hidden = isOpen;
      headerSearchToggle.setAttribute("aria-expanded", String(!isOpen));

      if (!isOpen) {
        window.setTimeout(() => searchInput?.focus(), 0);
      }
    });

    headerSearchPanel.querySelectorAll("[data-header-search-close]").forEach((button) => {
      button.addEventListener("click", closeHeaderSearch);
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !headerSearchPanel.hidden) {
        closeHeaderSearch();
        headerSearchToggle.focus();
      }
    });
  }

  const cartCountNodes = () => document.querySelectorAll(".header-cart__count");
  let cartSyncTimer;

  const renderCartCount = (count) => {
    cartCountNodes().forEach((node) => {
      node.textContent = String(count);
    });
  };

  const syncCartCount = async () => {
    if (cartCountNodes().length === 0) {
      return;
    }

    try {
      const response = await fetch("/wp-json/wc/store/v1/cart", {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
      if (!response.ok) {
        return;
      }
      const cart = await response.json();
      if (Number.isFinite(cart.items_count)) {
        renderCartCount(cart.items_count);
      }
    } catch {
      // Keep the server-rendered value when Store API synchronization is unavailable.
    }
  };

  const scheduleCartSync = () => {
    window.clearTimeout(cartSyncTimer);
    cartSyncTimer = window.setTimeout(syncCartCount, 250);
  };

  document.body.addEventListener("wc-blocks_added_to_cart", scheduleCartSync);
  document.body.addEventListener("wc-blocks_removed_from_cart", scheduleCartSync);
  window.addEventListener("pageshow", scheduleCartSync);

  const showCartToast = (message, type = "success") => {
    let region = document.querySelector(".aromamatrix-cart-toasts");

    if (!region) {
      region = document.createElement("div");
      region.className = "aromamatrix-cart-toasts";
      region.setAttribute("aria-live", "polite");
      region.setAttribute("aria-atomic", "true");
      document.body.appendChild(region);
    }

    const toast = document.createElement("div");
    toast.className = `aromamatrix-cart-toast aromamatrix-cart-toast--${type}`;
    const copy = document.createElement("span");
    copy.textContent = message;
    toast.appendChild(copy);

    if (type === "success") {
      const viewCart = document.createElement("a");
      viewCart.href = document.querySelector(".header-cart")?.getAttribute("href") || "/cart/";
      viewCart.textContent = "View cart";
      toast.appendChild(viewCart);
    }

    const close = document.createElement("button");
    close.type = "button";
    close.setAttribute("aria-label", "Close notification");
    close.textContent = "×";
    close.addEventListener("click", () => toast.remove());
    toast.appendChild(close);
    region.appendChild(toast);

    window.setTimeout(() => toast.remove(), 5000);
  };

  const productNameFor = (element) => (
    element.closest("li.product")?.querySelector(".woocommerce-loop-product__title")?.textContent?.trim()
    || "Product"
  );

  const addLoopProductToCart = async (form, productId, quantity) => {
    const submitButton = form.querySelector("button[type='submit']");
    const endpoint = window.wc_add_to_cart_params?.wc_ajax_url
      ? window.wc_add_to_cart_params.wc_ajax_url.replace("%%endpoint%%", "add_to_cart")
      : `${window.location.origin}/?wc-ajax=add_to_cart`;
    const productName = productNameFor(form);

    submitButton?.setAttribute("aria-busy", "true");
    submitButton?.classList.add("is-loading");

    try {
      const response = await fetch(endpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: new URLSearchParams({ product_id: String(productId), quantity: String(quantity) }),
      });
      const result = await response.json();

      if (!response.ok || result.error) {
        throw new Error("Unable to add product to cart");
      }

      document.querySelectorAll(".woocommerce-notices-wrapper").forEach((wrapper) => {
        wrapper.replaceChildren();
      });
      scheduleCartSync();
      showCartToast(`${productName} added to cart`);
    } catch {
      showCartToast("Couldn’t add this product. Please try again.", "error");
    } finally {
      submitButton?.removeAttribute("aria-busy");
      submitButton?.classList.remove("is-loading");
    }
  };

  document.addEventListener("submit", (event) => {
    const form = event.target.closest(".aromamatrix-loop-cart");
    const submitButton = event.submitter || form?.querySelector("button[name='add-to-cart']");
    const productId = submitButton?.value || new URL(form?.action || window.location.href).searchParams.get("add-to-cart");

    if (!form || !productId) {
      return;
    }

    event.preventDefault();
    const quantity = form.querySelector("input.qty")?.value || "1";
    addLoopProductToCart(form, productId, quantity);
  });

  if (window.jQuery) {
    window.jQuery(document.body).on("added_to_cart", (_event, _fragments, _cartHash, button) => {
      scheduleCartSync();
      showCartToast(`${productNameFor(button)} added to cart`);
    });
  }

  const cartBlock = document.querySelector(".wp-block-woocommerce-cart");
  if (cartBlock) {
    new MutationObserver(scheduleCartSync).observe(cartBlock, {
      childList: true,
      subtree: true,
    });
  }

  // Product cards appear in the shop, archives, homepage, related products,
  // and upsells. Keep their quantity controls working in every context.
  document.addEventListener("click", (event) => {
    const button = event.target.closest(".aromamatrix-loop-cart [data-quantity-action]");
    if (!button) {
      return;
    }

    const quantity = button.parentElement?.querySelector("input.qty");
    if (!quantity) {
      return;
    }

    const current = Number(quantity.value) || 0;
    const step = Number(quantity.step) || 1;
    const minimum = Number(quantity.min) || 1;
    const maximum = Number(quantity.max) || Number.POSITIVE_INFINITY;
    const next = button.dataset.quantityAction === "increase"
      ? Math.min(maximum, current + step)
      : Math.max(minimum, current - step);

    quantity.value = String(next);
    quantity.dispatchEvent(new Event("change", { bubbles: true }));
  });

  /**
   * Match the category card quantity picker on purchasable product pages and
   * keep its total in sync without changing WooCommerce's native add-to-cart
   * fields or variation handling.
   */
  document.querySelectorAll(".woocommerce div.product form.cart").forEach((form) => {
    const total = form.parentElement?.querySelector(".aromamatrix-product-total");
    const quantity = form.querySelector(".quantity");

    if (!quantity || quantity.parentElement?.classList.contains("aromamatrix-single-quantity")) {
      return;
    }

    const input = quantity.querySelector("input.qty");
    if (!input) {
      return;
    }

    const control = document.createElement("div");
    control.className = "aromamatrix-single-quantity";

    const createQuantityButton = (action, label, symbol) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "aromamatrix-single-quantity__button";
      button.dataset.quantityAction = action;
      button.setAttribute("aria-label", label);
      button.textContent = symbol;
      return button;
    };

    const decrease = createQuantityButton("decrease", "Decrease quantity", "−");
    const increase = createQuantityButton("increase", "Increase quantity", "+");
    quantity.replaceWith(control);
    control.append(decrease, quantity, increase);

    const formatTotal = (amount) => {
      if (!total) {
        return "";
      }

      const currency = total.dataset.currency || "USD";
      const decimals = Number(total.dataset.decimals) || 2;

      try {
        return new Intl.NumberFormat(document.documentElement.lang || undefined, {
          style: "currency",
          currency,
          currencyDisplay: "narrowSymbol",
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals,
        }).format(amount);
      } catch {
        return amount.toFixed(decimals);
      }
    };

    const updateTotal = () => {
      if (!total) {
        return;
      }

      const unitPrice = Number(total.dataset.unitPrice);
      const selectedQuantity = Number(input.value);
      const totalValue = total.querySelector("[data-product-total]");

      if (!Number.isFinite(unitPrice) || !Number.isFinite(selectedQuantity) || !totalValue) {
        return;
      }

      totalValue.textContent = formatTotal(unitPrice * selectedQuantity);
    };

    const updateQuantity = (action) => {
      const current = Number(input.value) || 0;
      const step = Number(input.step) || 1;
      const minimum = Number(input.min) || 1;
      const maximum = Number(input.max) || Number.POSITIVE_INFINITY;
      const next = action === "increase"
        ? Math.min(maximum, current + step)
        : Math.max(minimum, current - step);

      input.value = String(next);
      input.dispatchEvent(new Event("change", { bubbles: true }));
    };

    control.addEventListener("click", (event) => {
      const button = event.target.closest("[data-quantity-action]");
      if (button) {
        updateQuantity(button.dataset.quantityAction);
      }
    });

    input.addEventListener("change", updateTotal);
    input.addEventListener("input", updateTotal);
    updateTotal();

    if (total && window.jQuery && form.matches(".variations_form")) {
      window.jQuery(form).on("found_variation", (_event, variation) => {
        const price = Number(variation?.display_price);
        if (!Number.isFinite(price)) {
          return;
        }

        total.dataset.unitPrice = String(price);
        total.hidden = false;
        updateTotal();
      });

      window.jQuery(form).on("reset_data hide_variation", () => {
        total.hidden = true;
      });
    }
  });

  /**
   * Turn the bottle capacity dropdown into direct-select tiles. WooCommerce
   * still owns the native select so availability and variation resolution
   * continue to work exactly as they do for the default control.
   */
  document.querySelectorAll("form.variations_form").forEach((form) => {
    const select = form.querySelector('select[name="attribute_capacity"]');

    if (!select || select.dataset.capacityTilesReady === "true") {
      return;
    }

    const options = Array.from(select.options).filter((option) => option.value);
    if (options.length === 0) {
      return;
    }

    select.dataset.capacityTilesReady = "true";
    select.classList.add("capacity-choice-native");
    select.tabIndex = -1;
    select.setAttribute("aria-hidden", "true");

    const choices = document.createElement("div");
    choices.className = "capacity-choice-group";
    choices.setAttribute("role", "group");
    choices.setAttribute("aria-label", "Capacity");

    const buttonByValue = new Map();
    options.forEach((option) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "capacity-choice";
      button.textContent = option.textContent.trim();
      button.dataset.value = option.value;
      button.setAttribute("aria-pressed", "false");
      choices.append(button);
      buttonByValue.set(option.value, button);
    });

    const syncChoices = () => {
      const hasSelection = Boolean(select.value);

      options.forEach((option) => {
        const button = buttonByValue.get(option.value);
        const selected = option.value === select.value;
        const unavailable = option.disabled;

        button.classList.toggle("is-selected", selected);
        button.classList.toggle("is-unavailable", unavailable);
        button.setAttribute("aria-pressed", String(selected));
        button.disabled = unavailable;
      });

      const addToCartButton = form.querySelector(".single_add_to_cart_button");
      if (addToCartButton) {
        addToCartButton.disabled = !hasSelection;
        addToCartButton.classList.toggle("disabled", !hasSelection);
        addToCartButton.setAttribute("aria-disabled", String(!hasSelection));
      }

      const variationDetails = form.querySelector(".woocommerce-variation");
      if (variationDetails) {
        variationDetails.hidden = !hasSelection;
      }
    };

    const setCapacity = (value) => {
      select.value = value;
      select.dispatchEvent(new Event("change", { bubbles: true }));

      // WooCommerce listens through jQuery for variation changes. Triggering
      // that event as well clears the resolved variation when a tile is
      // deselected and keeps the add-to-cart state accurate.
      if (window.jQuery) {
        window.jQuery(select).trigger("change");
      }

      syncChoices();
    };

    choices.addEventListener("click", (event) => {
      const button = event.target.closest(".capacity-choice");
      if (!button || button.disabled) {
        return;
      }

      // Selecting the active tile again returns the variation to its unset state.
      setCapacity(button.dataset.value === select.value ? "" : button.dataset.value);
    });

    select.addEventListener("change", syncChoices);
    choices.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && select.value) {
        setCapacity("");
      }
    });

    select.insertAdjacentElement("afterend", choices);
    syncChoices();

    if (window.jQuery) {
      window.jQuery(form).on(
        "woocommerce_update_variation_values found_variation reset_data hide_variation",
        syncChoices,
      );
    }
  });

  const shopFilterPanel = document.querySelector(".shop-filter-panel");
  const shopFilterToggle = document.querySelector("[data-shop-filter-open]");

  if (shopFilterPanel && shopFilterToggle) {
    const closeShopFilters = () => {
      document.body.classList.remove("shop-filters-open");
      shopFilterToggle.setAttribute("aria-expanded", "false");
    };

    shopFilterToggle.addEventListener("click", () => {
      document.body.classList.add("shop-filters-open");
      shopFilterToggle.setAttribute("aria-expanded", "true");
      shopFilterPanel.querySelector("input, button, summary")?.focus();
    });

    document.querySelectorAll("[data-shop-filter-close]").forEach((button) => {
      button.addEventListener("click", closeShopFilters);
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && document.body.classList.contains("shop-filters-open")) {
        closeShopFilters();
        shopFilterToggle.focus();
      }
    });

    window.addEventListener("resize", () => {
      if (window.innerWidth > 960) {
        closeShopFilters();
      }
    });
  }

  document.querySelectorAll(".shop-filter-category__parent input").forEach((input) => {
    input.addEventListener("click", () => {
      const group = input.closest(".shop-filter-category");
      const wasOpen = group?.open;

      window.setTimeout(() => {
        if (group) {
          group.open = wasOpen;
        }
      });
    });
  });

  const shopFilterForm = document.querySelector(".shop-filter-form");

  if (shopFilterForm) {
    shopFilterForm.querySelectorAll('input[type="checkbox"]').forEach((input) => {
      input.addEventListener("change", () => {
        if (window.innerWidth > 960) {
          shopFilterForm.requestSubmit();
        }
      });
    });
  }
})();
