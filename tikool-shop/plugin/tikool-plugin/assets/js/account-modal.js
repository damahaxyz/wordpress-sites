(() => {
  const modal = document.querySelector("[data-tikool-account-modal]");
  if (!modal) {
    return;
  }

  const config = window.tikoolAccount || {};
  const dialog = modal.querySelector('[role="dialog"]');
  const notice = modal.querySelector("[data-account-notice]");
  const tabs = [...modal.querySelectorAll("[data-account-tab]")];
  const panels = [...modal.querySelectorAll("[data-account-panel]")];
  let opener = null;

  const focusableSelector = [
    "a[href]",
    "button:not([disabled])",
    "input:not([disabled])",
    "select:not([disabled])",
    "textarea:not([disabled])",
    '[tabindex]:not([tabindex="-1"])',
  ].join(",");

  const setNotice = (message = "", type = "") => {
    if (!notice) {
      return;
    }
    notice.textContent = message;
    notice.hidden = !message;
    notice.classList.toggle("is-error", type === "error");
  };

  const selectTab = (name, focusTab = false) => {
    const target = panels.some((panel) => panel.dataset.accountPanel === name) ? name : "login";
    tabs.forEach((tab) => {
      const selected = tab.dataset.accountTab === target;
      tab.classList.toggle("is-active", selected);
      tab.setAttribute("aria-selected", String(selected));
      tab.tabIndex = selected ? 0 : -1;
      if (selected && focusTab) {
        tab.focus();
      }
    });
    panels.forEach((panel) => {
      panel.hidden = panel.dataset.accountPanel !== target;
    });
    setNotice();
  };

  const closeModal = (restoreFocus = true) => {
    if (modal.hidden) {
      return;
    }
    modal.hidden = true;
    document.body.classList.remove("tikool-account-modal-open");
    setNotice();
    if (restoreFocus && opener instanceof HTMLElement) {
      opener.focus();
    }
    opener = null;
  };

  const openModal = (tab = "login", trigger = null) => {
    opener = trigger instanceof HTMLElement ? trigger : document.activeElement;
    document.body.classList.remove("menu-open", "cart-drawer-open");
    document.querySelector(".primary-navigation")?.classList.remove("is-open");
    document.querySelector(".menu-toggle")?.setAttribute("aria-expanded", "false");
    document.querySelector(".header-cart")?.setAttribute("aria-expanded", "false");
    document.querySelector(".tikool-cart-drawer")?.setAttribute("aria-hidden", "true");
    selectTab(tab);
    modal.hidden = false;
    document.body.classList.add("tikool-account-modal-open");
    window.requestAnimationFrame(() => {
      const activePanel = modal.querySelector(`[data-account-panel="${tab}"]:not([hidden])`)
        || modal.querySelector('[data-account-panel="login"]');
      activePanel?.querySelector("input:not([type='hidden'])")?.focus();
    });
  };

  const isAccountLink = (element) => {
    if (!(element instanceof HTMLAnchorElement) || element.closest("[data-tikool-account-modal]")) {
      return false;
    }
    try {
      const url = new URL(element.href, window.location.href);
      const configuredPath = String(config.myAccountPath || "/my-account/").replace(/\/+$/, "");
      return url.origin === window.location.origin && url.pathname.replace(/\/+$/, "") === configuredPath;
    } catch (error) {
      return false;
    }
  };

  document.addEventListener("click", (event) => {
    const explicitTrigger = event.target.closest("[data-tikool-account-trigger]");
    const accountLink = event.target.closest("a");
    const checkoutLogin = event.target.closest(".wc-block-checkout__login-prompt, .showlogin");

    if (explicitTrigger || checkoutLogin || isAccountLink(accountLink)) {
      event.preventDefault();
      openModal(explicitTrigger?.dataset.tikoolAccountTrigger || "login", explicitTrigger || checkoutLogin || accountLink);
      return;
    }

    const closer = event.target.closest("[data-account-modal-close]");
    if (closer) {
      closeModal(true);
      return;
    }

    const tab = event.target.closest("[data-account-tab]");
    if (tab) {
      selectTab(tab.dataset.accountTab || "login", true);
      const panel = modal.querySelector(`[data-account-panel="${tab.dataset.accountTab}"]`);
      panel?.querySelector("input:not([type='hidden'])")?.focus();
      return;
    }

    const passwordToggle = event.target.closest("[data-password-toggle]");
    if (passwordToggle) {
      const input = passwordToggle.parentElement?.querySelector("input");
      if (!input) {
        return;
      }
      const showing = input.type === "text";
      input.type = showing ? "password" : "text";
      passwordToggle.textContent = showing ? "Show" : "Hide";
      passwordToggle.setAttribute("aria-label", showing ? "Show password" : "Hide password");
      input.focus();
    }
  });

  tabs.forEach((tab, index) => {
    tab.addEventListener("keydown", (event) => {
      if (!["ArrowLeft", "ArrowRight"].includes(event.key)) {
        return;
      }
      event.preventDefault();
      const next = event.key === "ArrowRight" ? (index + 1) % tabs.length : (index - 1 + tabs.length) % tabs.length;
      selectTab(tabs[next].dataset.accountTab || "login", true);
    });
  });

  modal.querySelectorAll("[data-account-form]").forEach((form) => {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!form.reportValidity() || !config.ajaxUrl || !config.nonce) {
        return;
      }

      const submit = form.querySelector('[type="submit"]');
      const originalLabel = submit?.textContent || "";
      const body = new URLSearchParams(new FormData(form));
      body.set("action", form.dataset.accountForm || "");
      body.set("nonce", config.nonce);
      setNotice();
      if (submit) {
        submit.disabled = true;
        submit.textContent = config.workingMessage || "Please wait…";
      }

      try {
        const response = await fetch(config.ajaxUrl, {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
          body: body.toString(),
        });
        const payload = await response.json();
        const message = payload?.data?.message || config.errorMessage || "Something went wrong. Please try again.";
        if (!response.ok || !payload.success) {
          throw new Error(message);
        }

        setNotice(message, "success");
        if (payload.data?.reload !== false) {
          window.setTimeout(() => window.location.reload(), 550);
        } else {
          window.setTimeout(() => selectTab("login"), 800);
        }
      } catch (error) {
        setNotice(error.message || config.errorMessage || "Something went wrong. Please try again.", "error");
      } finally {
        if (submit) {
          submit.disabled = false;
          submit.textContent = originalLabel;
        }
      }
    });
  });

  document.addEventListener("keydown", (event) => {
    if (modal.hidden) {
      return;
    }

    if (event.key === "Escape") {
      event.preventDefault();
      closeModal(true);
      return;
    }

    if (event.key === "Tab" && dialog) {
      const focusable = [...dialog.querySelectorAll(focusableSelector)].filter((element) => !element.closest("[hidden]"));
      if (!focusable.length) {
        return;
      }
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
})();
