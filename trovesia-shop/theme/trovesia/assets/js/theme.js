(() => {
  const toggle = document.querySelector(".menu-toggle");
  const navigation = document.querySelector(".primary-navigation");
  const header = document.querySelector(".site-header");

  const setHeaderState = () => {
    if (header) {
      header.classList.toggle("is-scrolled", window.scrollY > 12);
    }
  };

  setHeaderState();
  window.addEventListener("scroll", setHeaderState, { passive: true });

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

  if (toggle && navigation) {
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
  }

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeMenu(true);
    }
  });

  window.addEventListener("resize", () => {
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
    const bundleSelect = form.querySelector('select[name="attribute_bundle"]');
    const bundleCards = picker ? [...picker.querySelectorAll(".trovesia-bundle-card")] : [];
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
      card.querySelector(".trovesia-product-addon__input")?.addEventListener("change", syncAddons);
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
