(() => {
  "use strict";

  const config = window.tikoolStorefrontFeedback || {};
  const duration = Math.max(2000, Number(config.duration) || 6000);
  const noticeSelector = [
    ".woocommerce-message",
    ".woocommerce-error",
    ".woocommerce-info",
    ".woocommerce-notice",
    ".wc-block-components-notice-banner",
    ".tikool-form-notice",
    ".tikool-review-notice",
  ].join(",");
  const buttonStates = new WeakMap();
  const recentNotices = new Map();
  let scanQueued = false;

  const toastRegion = () => {
    let region = document.querySelector("[data-tikool-toast-region]");
    if (!region) {
      region = document.createElement("div");
      region.className = "tikool-toast-region";
      region.dataset.tikoolToastRegion = "";
      region.setAttribute("aria-live", "polite");
      region.setAttribute("aria-atomic", "false");
      document.body.append(region);
    }
    return region;
  };

  const noticeType = (node) => {
    if (
      node.classList.contains("woocommerce-error") ||
      node.classList.contains("is-error") ||
      node.classList.contains("wc-block-components-notice-banner--error") ||
      node.classList.contains("tikool-form-notice--error") ||
      node.classList.contains("tikool-review-notice--error")
    ) {
      return "error";
    }
    if (
      node.classList.contains("woocommerce-info") ||
      node.classList.contains("is-info") ||
      node.classList.contains("wc-block-components-notice-banner--info")
    ) {
      return "info";
    }
    return "success";
  };

  const normalizedText = (value) => String(value || "").replace(/\s+/g, " ").trim();

  const readNotice = (node) => {
    const action = node.querySelector("a.button, a.wc-forward, .wc-block-components-notice-banner__content a");
    const clone = node.cloneNode(true);
    clone.querySelectorAll("a.button, a.wc-forward, button, svg").forEach((element) => element.remove());

    return {
      message: normalizedText(clone.textContent),
      actionHref: action?.href || "",
      actionLabel: normalizedText(action?.textContent),
    };
  };

  const dismissToast = (toast) => {
    if (!toast || toast.classList.contains("is-leaving")) return;
    toast.classList.add("is-leaving");
    window.setTimeout(() => toast.remove(), 220);
  };

  const showToast = (message, type = "info", options = {}) => {
    const cleanMessage = normalizedText(message);
    if (!cleanMessage) return null;

    const key = `${type}|${cleanMessage}|${options.actionHref || ""}`;
    const now = Date.now();
    const lastShown = recentNotices.get(key) || 0;
    if (now - lastShown < 1500) return null;
    recentNotices.set(key, now);
    window.setTimeout(() => recentNotices.delete(key), 2000);

    const toast = document.createElement("section");
    toast.className = `tikool-toast tikool-toast--${type}`;
    toast.setAttribute("role", type === "error" ? "alert" : "status");
    toast.style.setProperty("--tikool-toast-duration", `${duration}ms`);

    const icon = document.createElement("span");
    icon.className = "tikool-toast__icon";
    icon.setAttribute("aria-hidden", "true");
    icon.textContent = type === "success" ? "✓" : type === "error" ? "!" : "i";

    const content = document.createElement("div");
    content.className = "tikool-toast__content";
    const text = document.createElement("p");
    text.className = "tikool-toast__message";
    text.textContent = cleanMessage;
    content.append(text);

    if (options.actionHref && options.actionLabel) {
      const action = document.createElement("a");
      action.className = "tikool-toast__action";
      action.href = options.actionHref;
      action.textContent = options.actionLabel;
      content.append(action);
    }

    const close = document.createElement("button");
    close.className = "tikool-toast__close";
    close.type = "button";
    close.setAttribute("aria-label", config.closeNotification || "Close notification");
    close.innerHTML = "&times;";
    close.addEventListener("click", () => dismissToast(toast));

    toast.append(icon, content, close);
    toastRegion().append(toast);
    window.requestAnimationFrame(() => toast.classList.add("is-visible"));
    window.setTimeout(() => dismissToast(toast), duration);
    return toast;
  };

  window.tikoolToast = showToast;
  window.alert = (message) => showToast(message, "error");
  document.addEventListener("tikool:toast", (event) => {
    const detail = event.detail || {};
    showToast(detail.message, detail.type || "info", detail);
  });

  const convertNotice = (node) => {
    if (!(node instanceof Element) || node.dataset.tikoolToastProcessed !== undefined) return;
    if (node.closest("[data-tikool-toast-region]")) return;

    node.dataset.tikoolToastProcessed = "";
    const type = noticeType(node);
    const listItems = node.matches("ul, ol") ? Array.from(node.querySelectorAll(":scope > li")) : [];
    const sources = listItems.length ? listItems : [node];

    sources.forEach((source) => {
      const notice = readNotice(source);
      showToast(notice.message, type, notice);
    });

    node.classList.add("tikool-toast-source-hidden");
    node.setAttribute("aria-hidden", "true");
  };

  const scanNotices = (root = document) => {
    if (root instanceof Element && root.matches(noticeSelector)) convertNotice(root);
    root.querySelectorAll?.(noticeSelector).forEach(convertNotice);
  };

  const queueNoticeScan = () => {
    if (scanQueued) return;
    scanQueued = true;
    window.requestAnimationFrame(() => {
      scanQueued = false;
      scanNotices();
    });
  };

  const addSubmitProxy = (button, form) => {
    if (!button.name || !button.value || form.querySelector("[data-tikool-submit-proxy]")) return;
    const proxy = document.createElement("input");
    proxy.type = "hidden";
    proxy.name = button.name;
    proxy.value = button.value;
    proxy.dataset.tikoolSubmitProxy = "";
    form.append(proxy);
  };

  const lockButton = (button, form = null, mode = "submit") => {
    if (!(button instanceof HTMLElement) || buttonStates.has(button)) return;

    buttonStates.set(button, {
      html: button.innerHTML,
      value: button instanceof HTMLInputElement ? button.value : "",
      disabled: "disabled" in button ? button.disabled : false,
      mode,
      form,
    });
    if (form && button instanceof HTMLButtonElement) addSubmitProxy(button, form);

    const spinner = document.createElement("span");
    spinner.className = "tikool-cart-button__spinner";
    spinner.setAttribute("aria-hidden", "true");
    const label = document.createElement("span");
    label.textContent = config.addingToCart || "Adding to cart…";

    if (button instanceof HTMLInputElement) {
      button.value = label.textContent;
    } else {
      button.replaceChildren(spinner, label);
    }
    button.classList.add("is-tikool-submitting");
    button.setAttribute("aria-disabled", "true");
    button.setAttribute("aria-busy", "true");
    if ("disabled" in button) button.disabled = true;
  };

  const unlockButton = (button) => {
    const state = buttonStates.get(button);
    if (!state) return;

    if (button instanceof HTMLInputElement) button.value = state.value;
    else button.innerHTML = state.html;
    button.classList.remove("is-tikool-submitting");
    button.removeAttribute("aria-disabled");
    button.removeAttribute("aria-busy");
    if ("disabled" in button) button.disabled = state.disabled;
    state.form?.querySelectorAll("[data-tikool-submit-proxy]").forEach((proxy) => proxy.remove());
    buttonStates.delete(button);
  };

  document.addEventListener(
    "click",
    (event) => {
      const button = event.target.closest?.(".is-tikool-submitting");
      if (!button) return;
      event.preventDefault();
      event.stopImmediatePropagation();
    },
    true
  );

  document.addEventListener("submit", (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches("form.cart")) return;

    window.queueMicrotask(() => {
      if (event.defaultPrevented) return;
      const button =
        (event.submitter instanceof HTMLElement && event.submitter) ||
        form.querySelector(".single_add_to_cart_button, button[type='submit']");
      if (button) lockButton(button, form);
    });
  });

  window.addEventListener("pageshow", () => {
    document.querySelectorAll(".is-tikool-submitting").forEach(unlockButton);
  });

  const initialize = () => {
    scanNotices();
    const observer = new MutationObserver(queueNoticeScan);
    observer.observe(document.body, { childList: true, subtree: true });

    if (!window.jQuery) return;
    const $ = window.jQuery;
    $(document.body)
      .on("adding_to_cart", (event, $button) => {
        const button = $button?.get?.(0);
        if (button) lockButton(button, null, "ajax");
      })
      .on("added_to_cart wc_cart_button_updated", (event, fragments, cartHash, $button) => {
        const button = $button?.get?.(0);
        if (button) unlockButton(button);
        document.querySelectorAll(".is-tikool-submitting").forEach((candidate) => {
          if (buttonStates.get(candidate)?.mode === "ajax") unlockButton(candidate);
        });
      });

    $(document).ajaxError((event, request, settings) => {
      if (!String(settings?.url || "").includes("wc-ajax=add_to_cart")) return;
      document.querySelectorAll(".is-tikool-submitting").forEach((candidate) => {
        if (buttonStates.get(candidate)?.mode === "ajax") unlockButton(candidate);
      });
    });
  };

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initialize, { once: true });
  else initialize();
})();
