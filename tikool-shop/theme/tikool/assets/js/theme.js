(() => {
  const toggle = document.querySelector(".menu-toggle");
  const navigation = document.querySelector(".primary-navigation");
  const header = document.querySelector(".site-header");
  const cartTrigger = document.querySelector(".header-cart");
  const cartDrawer = document.querySelector(".tikool-cart-drawer");
  const cartClosers = document.querySelectorAll("[data-cart-drawer-close]");

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
    navigation.style.setProperty("--tikool-menu-height", `${available}px`);
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
    const config = window.tikoolCart || {};
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
        action: "tikool_update_cart_quantity",
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
      window.alert(error.message || config.error);
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
    const minus = quantity.querySelector('[data-tikool-qty-change="-1"]');
    const plus = quantity.querySelector('[data-tikool-qty-change="1"]');

    if (minus) {
      minus.disabled = Number.isFinite(min) && value <= min;
    }
    if (plus) {
      plus.disabled = Number.isFinite(max) && value >= max;
    }
  };

  document.querySelectorAll(".woocommerce div.product form.cart .quantity").forEach((quantity) => {
    const input = quantity.querySelector(".qty");
    if (!input || !quantity.querySelector(".tikool-qty-button")) {
      return;
    }

    quantity.classList.add("tikool-quantity-stepper");
    syncQuantityButtons(quantity);

    quantity.addEventListener("click", (event) => {
      const button = event.target.closest("[data-tikool-qty-change]");
      if (!button || button.disabled) {
        return;
      }

      if (button.dataset.tikoolQtyChange === "1") {
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
    const picker = form.querySelector(".tikool-variant-picker");
    const bundleSelect = form.querySelector('select[name="attribute_bundle"]');
    const bundleCards = picker ? [...picker.querySelectorAll(".tikool-bundle-card")] : [];
    const addonCards = [...form.querySelectorAll(".tikool-product-addon")];

    const choose = (select, value) => {
      const option = [...select.options].find((item) => item.value === value);
      if (!option || option.disabled) {
        return false;
      }

      select.value = value;
      select.dispatchEvent(new Event("change", { bubbles: true }));
      return true;
    };

    const variationsTable = form.querySelector("table.variations");
    const variationSelects = variationsTable ? [...variationsTable.querySelectorAll("select")] : [];

    if (variationsTable && variationSelects.length) {
      const reset = variationsTable.querySelector(".reset_variations");
      if (reset) {
        reset.classList.add("tikool-reset-variations");
        form.insertBefore(reset, variationsTable);
      }

      const optionGroups = variationSelects.map((select) => {
        const row = select.closest("tr");
        const label = row?.querySelector("label")?.textContent?.trim() || "Product option";
        const group = document.createElement("div");
        group.className = "tikool-attribute-options";
        group.setAttribute("role", "group");
        group.setAttribute("aria-label", label);

        [...select.options].forEach((option) => {
          if (!option.value) {
            return;
          }

          const button = document.createElement("button");
          button.type = "button";
          button.className = "tikool-attribute-option";
          button.dataset.value = option.value;
          button.setAttribute("aria-pressed", "false");

          if (select.name.includes("frame-color")) {
            const normalized = option.value.toLocaleLowerCase();
            const swatch = document.createElement("span");
            swatch.className = "tikool-attribute-option__swatch";
            swatch.setAttribute("aria-hidden", "true");
            if (normalized.includes("black")) {
              swatch.style.setProperty("--tikool-swatch", "#2a2928");
            } else if (normalized.includes("walnut")) {
              swatch.style.setProperty("--tikool-swatch", "#6f4028");
            } else {
              swatch.style.setProperty("--tikool-swatch", "#c89a5b");
            }
            button.append(swatch);
          }

          const text = document.createElement("span");
          text.textContent = option.textContent.trim();
          button.append(text);
          button.addEventListener("click", () => choose(select, option.value));
          group.append(button);
        });

        select.insertAdjacentElement("afterend", group);

        const sync = () => {
          [...group.querySelectorAll(".tikool-attribute-option")].forEach((button) => {
            const option = [...select.options].find((item) => item.value === button.dataset.value);
            const selected = select.value === button.dataset.value;
            button.disabled = !option || option.disabled;
            button.classList.toggle("is-selected", selected);
            button.setAttribute("aria-pressed", String(selected));
          });
        };

        select.addEventListener("change", sync);
        return { select, sync };
      });

      const syncVariationOptions = () => {
        window.requestAnimationFrame(() => optionGroups.forEach(({ sync }) => sync()));
      };

      if (window.jQuery) {
        window.jQuery(form).on(
          "woocommerce_update_variation_values found_variation reset_data woocommerce_variation_has_changed",
          syncVariationOptions,
        );
      }

      optionGroups.forEach(({ sync }) => sync());
      variationsTable.classList.add("tikool-expanded-variations", "is-ready");
    }

    const syncAddons = () => {
      addonCards.forEach((card) => {
        const input = card.querySelector(".tikool-product-addon__input");
        let allowed = [];
        try {
          allowed = JSON.parse(card.dataset.allowedValues || "[]");
        } catch (error) {
          allowed = [];
        }
        const currentBundle = bundleSelect?.value || "";
        const isAllowed = !allowed.length || !currentBundle || allowed.some(
          (value) => String(value).toLocaleLowerCase() === currentBundle.toLocaleLowerCase(),
        );
        card.hidden = !isAllowed;
        if (!isAllowed && input) {
          input.checked = false;
        }
        card.classList.toggle("is-selected", Boolean(input?.checked));
      });
    };

    addonCards.forEach((card) => {
      card.querySelector(".tikool-product-addon__input")?.addEventListener("change", syncAddons);
    });

    if (picker && bundleSelect && bundleCards.length) {
      const syncBundles = () => {
        bundleCards.forEach((card) => {
          const selected = card.dataset.attributeValue === bundleSelect.value;
          card.classList.toggle("is-selected", selected);
          card.setAttribute("aria-pressed", String(selected));
        });
        syncAddons();
      };

      bundleCards.forEach((card) => {
        card.addEventListener("click", () => {
          choose(bundleSelect, card.dataset.attributeValue || "");
          syncBundles();
        });
      });

      if (!bundleSelect.value) {
        choose(bundleSelect, picker.dataset.defaultBundle || bundleCards[0].dataset.attributeValue || "");
      }

      bundleSelect.addEventListener("change", syncBundles);

      if (window.jQuery) {
        window.jQuery(form).on("found_variation reset_data woocommerce_variation_has_changed", syncBundles);
      }

      syncBundles();
      picker.classList.add("is-ready");
    } else {
      syncAddons();
    }
  });
})();
