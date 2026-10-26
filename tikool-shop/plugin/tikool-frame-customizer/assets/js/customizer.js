(() => {
  const settings = window.tikoolFrameCustomizer || {};
  const text = settings.i18n || {};
  const ready = (callback) => {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", callback, { once: true });
    } else {
      callback();
    }
  };

  const parseConfig = (value) => {
    try {
      return typeof value === "string" ? JSON.parse(value) : value;
    } catch (error) {
      return null;
    }
  };

  const asNumber = (value, fallback = 0) => {
    const number = Number.parseFloat(value);
    return Number.isFinite(number) ? number : fallback;
  };
  const clamp = (value, minimum, maximum) => Math.min(maximum, Math.max(minimum, value));
  const nameFonts = {
    georgia: 'Georgia, "Times New Roman", serif',
    arial: 'Arial, Helvetica, sans-serif',
    trebuchet: '"Trebuchet MS", Arial, sans-serif',
    brush: '"Brush Script MT", "Segoe Script", cursive',
    oswald: '"Tikool Oswald", "Arial Narrow", Arial, sans-serif',
    playfair: '"Tikool Playfair Display", Georgia, serif',
    cormorant: '"Tikool Cormorant Garamond", Georgia, serif',
    montserrat: '"Tikool Montserrat", Arial, sans-serif',
    "great-vibes": '"Tikool Great Vibes", "Brush Script MT", cursive',
  };
  const nameFontWeights = {
    oswald: 700,
    playfair: 600,
    cormorant: 600,
    montserrat: 600,
    "great-vibes": 400,
  };
  const customFontFaces = {
    oswald: "Tikool Oswald",
    playfair: "Tikool Playfair Display",
    cormorant: "Tikool Cormorant Garamond",
    montserrat: "Tikool Montserrat",
    "great-vibes": "Tikool Great Vibes",
  };
  const normalizeHexColor = (value, fallback) => /^#[0-9a-f]{6}$/i.test(value || "") ? value : fallback;
  const AI_SOURCE_MAX_EDGE = 1600;
  const AI_SOURCE_MAX_BYTES = 2 * 1024 * 1024;

  const canvasToBlob = (canvas, quality) => new Promise((resolve, reject) => {
    canvas.toBlob((blob) => {
      if (blob) {
        resolve(blob);
      } else {
        reject(new Error("AI preview image compression failed"));
      }
    }, "image/jpeg", quality);
  });

  const prepareAIImageFile = async (file, image) => {
    const width = image?.naturalWidth || 0;
    const height = image?.naturalHeight || 0;
    if (!file || width < 1 || height < 1) return file;
    if (file.size <= AI_SOURCE_MAX_BYTES && Math.max(width, height) <= AI_SOURCE_MAX_EDGE) return file;

    const scale = Math.min(1, AI_SOURCE_MAX_EDGE / Math.max(width, height));
    const targetWidth = Math.max(1, Math.round(width * scale));
    const targetHeight = Math.max(1, Math.round(height * scale));
    const compressionCanvas = document.createElement("canvas");
    compressionCanvas.width = targetWidth;
    compressionCanvas.height = targetHeight;
    const context = compressionCanvas.getContext("2d", { alpha: false });
    if (!context) return file;
    context.fillStyle = "#fff";
    context.fillRect(0, 0, targetWidth, targetHeight);
    context.drawImage(image, 0, 0, targetWidth, targetHeight);

    let qualityLevel = 0.82;
    let blob = await canvasToBlob(compressionCanvas, qualityLevel);
    while (blob.size > AI_SOURCE_MAX_BYTES && qualityLevel > 0.58) {
      qualityLevel -= 0.08;
      blob = await canvasToBlob(compressionCanvas, qualityLevel);
    }
    const baseName = (file.name || "pet-photo").replace(/\.[^.]+$/, "");
    return new File([blob], `${baseName}-ai-preview.jpg`, {
      type: "image/jpeg",
      lastModified: file.lastModified || Date.now(),
    });
  };

  ready(() => {
    document.querySelectorAll("form.cart").forEach((form) => {
      const modal = form.querySelector("[data-tikool-fc-modal]");
      if (!modal) return;

      const variationForm = form.matches(".variations_form") ? form : null;
      const nfcNotice = form.querySelector("[data-tikool-fc-nfc-notice]");
      const nfcNoticeAnchor = nfcNotice ? document.createComment("tikool-nfc-notice") : null;
      if (nfcNotice && nfcNoticeAnchor) nfcNotice.before(nfcNoticeAnchor);
      const variationId = form.querySelector('input[name="variation_id"]');
      const variationSelects = [...form.querySelectorAll("table.variations select")];
      const addButton = form.querySelector(".single_add_to_cart_button");
      const quantity = form.querySelector(".quantity");
      const purchaseSlot = modal.querySelector("[data-tikool-fc-purchase]");
      const preview = modal.querySelector("[data-tikool-fc-preview]");
      const canvasWrap = modal.querySelector("[data-tikool-fc-canvas-wrap]");
      const canvas = modal.querySelector("[data-tikool-fc-canvas]");
      const placeholder = modal.querySelector("[data-tikool-fc-placeholder]");
      const summary = modal.querySelector("[data-tikool-fc-summary]");
      const nameInput = modal.querySelector('input[name="tikool_fc_name"]');
      const nameOverlayInput = modal.querySelector("[data-tikool-fc-name-overlay]");
      const nameOptions = modal.querySelector("[data-tikool-fc-name-options]");
      const nameFontInput = modal.querySelector("[data-tikool-fc-name-font]");
      const nameSizeInput = modal.querySelector("[data-tikool-fc-name-size]");
      const nameSizeValue = modal.querySelector("[data-tikool-fc-name-size-value]");
      const nameColorInput = modal.querySelector("[data-tikool-fc-name-color]");
      const nameOutlineInput = modal.querySelector("[data-tikool-fc-name-outline]");
      const nameOutlineColorInput = modal.querySelector("[data-tikool-fc-name-outline-color]");
      const nameOutlineColorWrap = modal.querySelector("[data-tikool-fc-name-outline-color-wrap]");
      const photoInput = modal.querySelector("[data-tikool-fc-photo]");
      const previewInput = modal.querySelector("[data-tikool-fc-preview-file]");
      const cartPreviewInput = modal.querySelector("[data-tikool-fc-cart-preview-file]");
      const filename = modal.querySelector("[data-tikool-fc-filename]");
      const styleInputs = [...modal.querySelectorAll("[data-tikool-fc-style]")];
      const stylesFieldset = modal.querySelector("[data-tikool-fc-styles]");
      const styleStatus = modal.querySelector("[data-tikool-fc-style-status]");
      const styleRetry = modal.querySelector("[data-tikool-fc-style-retry]");
      const variantsPanel = modal.querySelector("[data-tikool-fc-variants]");
      const variantsGrid = modal.querySelector("[data-tikool-fc-variants-grid]");
      const aiLoading = modal.querySelector("[data-tikool-fc-ai-loading]");
      const aiLoadingTitle = modal.querySelector("[data-tikool-fc-ai-loading-title]");
      const aiLoadingMessage = modal.querySelector("[data-tikool-fc-ai-loading-message]");
      const viewLargeButton = modal.querySelector("[data-tikool-fc-view-large]");
      const viewSourceLink = modal.querySelector("[data-tikool-fc-view-source]");
      const lightbox = modal.querySelector("[data-tikool-fc-lightbox]");
      const largeImage = modal.querySelector("[data-tikool-fc-large-image]");
      const largeStage = modal.querySelector("[data-tikool-fc-large-stage]");
      const largeZoomOut = modal.querySelector("[data-tikool-fc-large-zoom-out]");
      const largeZoomIn = modal.querySelector("[data-tikool-fc-large-zoom-in]");
      const largeZoomValue = modal.querySelector("[data-tikool-fc-large-zoom-value]");
      const largeActual = modal.querySelector("[data-tikool-fc-large-actual]");
      const largeFit = modal.querySelector("[data-tikool-fc-large-fit]");
      const lightboxCloseButtons = [...modal.querySelectorAll("[data-tikool-fc-lightbox-close]")];
      const nonceInput = form.querySelector('input[name="tikool_fc_nonce"]');
      const zoomInput = modal.querySelector("[data-tikool-fc-zoom]");
      const zoomControl = zoomInput?.closest(".tikool-fc-preview__zoom");
      const quality = modal.querySelector("[data-tikool-fc-quality]");
      const recipeInput = modal.querySelector("[data-tikool-fc-recipe]");
      const errorBox = modal.querySelector("[data-tikool-fc-error]");
      const closeButtons = [...modal.querySelectorAll("[data-tikool-fc-close]")];
      const keepsakeViewButtons = [...modal.querySelectorAll("[data-tikool-fc-keepsake-view]")];
      const required = [addButton, purchaseSlot, preview, canvasWrap, canvas, placeholder, summary, nameInput, nameOverlayInput, nameOptions, nameFontInput, nameSizeInput, nameSizeValue, nameColorInput, nameOutlineInput, nameOutlineColorInput, nameOutlineColorWrap, photoInput, previewInput, cartPreviewInput, filename, stylesFieldset, styleStatus, styleRetry, variantsPanel, variantsGrid, aiLoading, aiLoadingTitle, aiLoadingMessage, viewLargeButton, viewSourceLink, lightbox, largeImage, largeStage, largeZoomOut, largeZoomIn, largeZoomValue, largeActual, largeFit, nonceInput, zoomInput, quality, recipeInput, errorBox];
      if (required.some((element) => !element)) return;

      form.enctype = "multipart/form-data";
      const purchaseContainer = form.querySelector(".woocommerce-variation-add-to-cart") || addButton.parentElement;
      const nextArea = document.createElement("div");
      nextArea.className = "tikool-fc-step-one";
      nextArea.innerHTML = `<button class="tikool-fc-next" type="button">${text.next || "Next: upload photo"}</button><p class="tikool-fc-step-one__error" role="alert" aria-live="polite"></p>`;
      if (purchaseContainer && purchaseContainer !== form) {
        purchaseContainer.insertAdjacentElement("beforebegin", nextArea);
      } else {
        form.insertBefore(nextArea, quantity || addButton);
      }
      const nextButton = nextArea.querySelector(".tikool-fc-next");
      const stepError = nextArea.querySelector(".tikool-fc-step-one__error");
      purchaseSlot.append(...[quantity, addButton].filter(Boolean));
      addButton.textContent = text.addToCart || "Add to cart";

      let config = parseConfig(modal.dataset.initialConfig) || {};
      let lastFocused = null;
      let preparing = false;
      let generatingStyle = false;
      let loadingMessageTimer = 0;
      let retryCooldownTimer = 0;
      let retryCooldownUntil = 0;
      let failedStyleInput = null;
      let failedVariantKey = "";
      let largePreviewLastFocused = null;
      let largePreviewObjectUrl = "";
      let largeScale = 1;
      let largeFitScale = 1;
      let largePanX = 0;
      let largePanY = 0;
      let largeDragStart = null;
      let largePinchStart = null;
      let preparedSignature = "";
      let aiSourcePromise = null;
      const assetCache = new Map();
      const styleCache = new Map();
      const selectedVariantByStyle = new Map();
      const largePointers = new Map();
      const state = {
        image: null,
        objectUrl: "",
        sourceName: "customer-photo",
        imageWidth: 0,
        imageHeight: 0,
        centerX: 0,
        centerY: 0,
        zoom: 1,
        pointerId: null,
        pointerX: 0,
        pointerY: 0,
        startCenterX: 0,
        startCenterY: 0,
        nameX: 0.5,
        nameY: 0.94,
        startNameX: 0.5,
        startNameY: 0.94,
        nameBounds: null,
        pointerMode: null,
        styleKey: "original",
        variantKey: "",
        cacheKey: "original",
        layout: null,
        previewMode: "inside",
      };

      const clearErrors = () => {
        errorBox.textContent = "";
        stepError.textContent = "";
      };

      const showError = (message, target = null) => {
        errorBox.textContent = message;
        target?.focus?.();
      };

      const selectedStyleInput = () => styleInputs.find((input) => input.checked) || styleInputs[0];
      const selectedStyle = () => config?.supportsAI === false ? "original" : (selectedStyleInput()?.value || "original");
      const cacheKeyFor = (styleKey, variantKey = "") => variantKey ? `${styleKey}::${variantKey}` : styleKey;
      const variantsForInput = (input) => {
        if (!input?.dataset?.styleVariants) return [];
        try {
          const parsed = JSON.parse(input.dataset.styleVariants);
          return Array.isArray(parsed)
            ? parsed.filter((variant) => variant && typeof variant.key === "string" && variant.key)
            : [];
        } catch (error) {
          return [];
        }
      };
      const variantsForStyle = (styleKey) => variantsForInput(styleInputs.find((input) => input.value === styleKey));
      const styleIsReady = (input) => {
        const variants = variantsForInput(input);
        return variants.length
          ? variants.some((variant) => styleCache.has(cacheKeyFor(input.value, variant.key)))
          : styleCache.has(input.value);
      };

      const setStyleStatus = (message, isError = false) => {
        styleStatus.textContent = message;
        styleStatus.classList.toggle("is-error", isError);
      };

      const formatText = (template, values) => values.reduce(
        (result, value) => result.replace("%s", String(value)),
        template,
      );

      const setRetryStyle = (input = null, variantKey = "") => {
        failedStyleInput = input;
        failedVariantKey = input ? variantKey : "";
        styleRetry.hidden = !input;
      };

      const saveCurrentStyleView = () => {
        const cached = styleCache.get(state.cacheKey);
        if (!cached || !state.image) return;
        cached.centerX = state.centerX;
        cached.centerY = state.centerY;
        cached.zoom = state.zoom;
      };

      const renderVariantChoices = (styleKey) => {
        const variants = variantsForStyle(styleKey);
        variantsGrid.replaceChildren();
        if (!variants.length || selectedStyle() !== styleKey) {
          variantsPanel.hidden = true;
          preview.classList.remove("has-variants");
          return;
        }
        const selectedVariant = state.styleKey === styleKey
          ? state.variantKey
          : (selectedVariantByStyle.get(styleKey) || variants[0].key);
        variants.forEach((variant) => {
          const cached = styleCache.get(cacheKeyFor(styleKey, variant.key));
          const button = document.createElement("button");
          button.type = "button";
          button.className = "tikool-fc-variant";
          button.dataset.variantKey = variant.key;
          button.disabled = generatingStyle || (Date.now() < retryCooldownUntil && !cached);
          button.classList.toggle("is-selected", selectedVariant === variant.key);
          button.classList.toggle("is-ready", Boolean(cached));
          button.setAttribute("aria-pressed", String(selectedVariant === variant.key));
          button.setAttribute("aria-label", variant.label || variant.key);
          button.title = variant.label || variant.key;
          const swatch = document.createElement("span");
          swatch.className = "tikool-fc-variant__swatch";
          swatch.setAttribute("aria-hidden", "true");
          button.append(swatch);
          const label = document.createElement("span");
          label.className = "tikool-fc-variant__label";
          label.textContent = variant.label || variant.key;
          button.append(label);
          button.addEventListener("click", () => {
            if (generatingStyle || (Date.now() < retryCooldownUntil && !cached)) return;
            const input = styleInputs.find((item) => item.value === styleKey);
            if (!input) return;
            const previousStyle = state.styleKey;
            const previousVariant = state.variantKey;
            selectedVariantByStyle.set(styleKey, variant.key);
            input.checked = true;
            if (cached) {
              activateStyle(styleKey, variant.key);
              setStyleStatus(formatText(text.backgroundReady || "%s background is ready. Use the buttons beside the preview to switch.", [variant.label || variant.key]));
              return;
            }
            renderVariantChoices(styleKey);
            generateStyle(input, previousStyle, previousVariant, variant.key);
          });
          variantsGrid.append(button);
        });
        variantsPanel.hidden = false;
        preview.classList.add("has-variants");
      };

      const activateStyle = (styleKey, variantKey = "") => {
        const variants = variantsForStyle(styleKey);
        if (!variantKey && variants.length) {
          variantKey = variants.find((variant) => styleCache.has(cacheKeyFor(styleKey, variant.key)))?.key || "";
        }
        const cacheKey = cacheKeyFor(styleKey, variantKey);
        const cached = styleCache.get(cacheKey);
        if (!cached) return false;
        saveCurrentStyleView();
        state.styleKey = styleKey;
        state.variantKey = variantKey;
        state.cacheKey = cacheKey;
        if (variantKey) selectedVariantByStyle.set(styleKey, variantKey);
        state.image = cached.image;
        state.imageWidth = cached.width;
        state.imageHeight = cached.height;
        state.centerX = Number.isFinite(cached.centerX) ? cached.centerX : cached.width / 2;
        state.centerY = Number.isFinite(cached.centerY) ? cached.centerY : cached.height / 2;
        state.zoom = Number.isFinite(cached.zoom) ? cached.zoom : 1;
        zoomInput.value = String(state.zoom);
        zoomInput.disabled = false;
        const sourceUrl = typeof cached.sourceUrl === "string" ? cached.sourceUrl : "";
        viewSourceLink.hidden = !sourceUrl;
        if (sourceUrl) {
          viewSourceLink.href = sourceUrl;
        } else {
          viewSourceLink.removeAttribute("href");
        }
        const input = styleInputs.find((item) => item.value === styleKey);
        if (input) input.checked = true;
        renderVariantChoices(styleKey);
        preparedSignature = "";
        render();
        return true;
      };

      const enableStyleInputs = (enabled = true) => {
        const cooldownActive = Date.now() < retryCooldownUntil;
        styleInputs.forEach((input) => {
          const needsGeneration = input.value !== "original" && !styleIsReady(input);
          input.disabled = generatingStyle || (config?.supportsAI === false && input.value !== "original") || (!enabled && input.value !== "original") || (cooldownActive && needsGeneration);
        });
      };

      const formatWait = (seconds) => {
        const safeSeconds = Math.max(1, Math.ceil(seconds));
        if (safeSeconds < 60) return `${safeSeconds}s`;
        const minutes = Math.floor(safeSeconds / 60);
        const remainder = safeSeconds % 60;
        return remainder ? `${minutes}m ${remainder}s` : `${minutes}m`;
      };

      const startRetryCooldown = (seconds, message) => {
        window.clearInterval(retryCooldownTimer);
        retryCooldownUntil = Date.now() + Math.max(1, seconds) * 1000;
        const update = () => {
          const remaining = Math.ceil((retryCooldownUntil - Date.now()) / 1000);
          if (remaining <= 0) {
            window.clearInterval(retryCooldownTimer);
            retryCooldownTimer = 0;
            retryCooldownUntil = 0;
            errorBox.textContent = "";
            enableStyleInputs(Boolean(photoInput.files?.length));
            renderVariantChoices(selectedStyle());
            setStyleStatus(text.retryReady || "You can try an AI style again now.");
            return;
          }
          const countdown = (text.retryCountdown || "You can try another AI style in %s.").replace("%s", formatWait(remaining));
          setStyleStatus(`${message} ${countdown}`, true);
          enableStyleInputs(Boolean(photoInput.files?.length));
          renderVariantChoices(selectedStyle());
        };
        update();
        retryCooldownTimer = window.setInterval(update, 1000);
      };

      const loadingMessages = Array.isArray(text.aiLoadingMessages) && text.aiLoadingMessages.length
        ? text.aiLoadingMessages
        : [
          "Studying your pet’s unique markings…",
          "Building the composition and background…",
          "Refining the fur, eyes, and small details…",
          "Preparing your high-resolution preview…",
        ];

      const startAILoading = (styleLabel) => {
        window.clearInterval(loadingMessageTimer);
        let messageIndex = 0;
        aiLoadingTitle.textContent = (text.aiLoadingTitle || "Creating your %s portrait").replace("%s", styleLabel);
        aiLoadingMessage.textContent = loadingMessages[messageIndex];
        aiLoading.hidden = false;
        preview.setAttribute("aria-busy", "true");
        stylesFieldset.setAttribute("aria-busy", "true");
        photoInput.disabled = true;
        viewLargeButton.disabled = true;
        loadingMessageTimer = window.setInterval(() => {
          messageIndex = (messageIndex + 1) % loadingMessages.length;
          aiLoadingMessage.classList.remove("is-changing");
          window.requestAnimationFrame(() => {
            aiLoadingMessage.textContent = loadingMessages[messageIndex];
            aiLoadingMessage.classList.add("is-changing");
          });
        }, 5500);
      };

      const stopAILoading = () => {
        window.clearInterval(loadingMessageTimer);
        loadingMessageTimer = 0;
        aiLoading.hidden = true;
        preview.removeAttribute("aria-busy");
        stylesFieldset.removeAttribute("aria-busy");
        photoInput.disabled = false;
        viewLargeButton.disabled = !state.image;
      };

      const currentNameOverlay = () => ({
        enabled: config?.supportsNameOverlay !== false && nameOverlayInput.checked,
        text: nameInput.value.trim(),
        font: Object.prototype.hasOwnProperty.call(nameFonts, nameFontInput.value) ? nameFontInput.value : "georgia",
        sizePercent: Math.min(12, Math.max(3, asNumber(nameSizeInput.value, 6))),
        positionX: state.nameX,
        positionY: state.nameY,
        color: normalizeHexColor(nameColorInput.value, "#2d2522"),
        outlineEnabled: nameOutlineInput.checked,
        outlineColor: normalizeHexColor(nameOutlineColorInput.value, "#ffffff"),
      });

      const ensureNameFont = () => {
        const key = nameFontInput.value;
        const face = customFontFaces[key];
        if (!face || !document.fonts?.load) return Promise.resolve();
        return document.fonts.load(`${nameFontWeights[key] || 400} 16px "${face}"`);
      };

      const loadAsset = (url) => {
        if (!url) return null;
        if (assetCache.has(url)) return assetCache.get(url);
        const image = new Image();
        image.crossOrigin = "anonymous";
        image.onload = render;
        image.onerror = () => assetCache.set(url, null);
        image.src = url;
        assetCache.set(url, image);
        return image;
      };

      const isBlanket = () => config?.renderer === "blanket";
      const isKeepsake = () => config?.renderer === "keepsake";

      const geometry = () => {
        const source = config?.geometry || {};
        const innerW = Math.max(1, asNumber(source.inner_width_mm, 203.2));
        const innerH = Math.max(1, asNumber(source.inner_height_mm, 254));
        return {
          outerW: Math.max(innerW + 2, asNumber(source.outer_width_mm, innerW + 25.4)),
          outerH: Math.max(innerH + 2, asNumber(source.outer_height_mm, innerH + 25.4)),
          innerW,
          innerH,
          artworkW: Math.min(innerW, Math.max(1, asNumber(source.artwork_width_mm, innerW))),
          artworkH: Math.min(innerH, Math.max(1, asNumber(source.artwork_height_mm, innerH))),
          printW: Math.max(1, asNumber(source.print_width_mm, innerW)),
          printH: Math.max(1, asNumber(source.print_height_mm, innerH)),
          matTop: Math.max(0, asNumber(source.mat_top_mm)),
          matRight: Math.max(0, asNumber(source.mat_right_mm)),
          matBottom: Math.max(0, asNumber(source.mat_bottom_mm)),
          matLeft: Math.max(0, asNumber(source.mat_left_mm)),
          matThickness: Math.max(0, asNumber(source.mat_thickness_mm, 1)),
          minDpi: Math.max(72, asNumber(source.min_dpi, 180)),
          frameColor: source.frame_color || "#c89552",
          matColor: source.mat_color || "#f7f3eb",
          frameTexture: source.frame_texture_url || "",
          overlay: source.overlay_url || "",
          blanketColor: source.blanket_color || "#eee7df",
          blanketTexture: source.blanket_texture_url || "",
          boxHeight: Math.max(1, asNumber(source.box_height_mm, 35)),
          vialCount: Math.max(1, Math.round(asNumber(source.vial_count, 2))),
          engravingW: Math.max(1, asNumber(source.engraving_width_mm, 36)),
          engravingH: Math.max(1, asNumber(source.engraving_height_mm, 36)),
          woodColor: source.wood_color || source.frame_color || "#6b3f25",
          woodTexture: source.wood_texture_url || "",
        };
      };

      const computeLayout = (width, height) => {
        const g = geometry();
        const margin = Math.max(16, Math.min(width, height) * 0.045);
        const usableW = width - (margin * 2);
        const usableH = height - (margin * 2);
        if (isKeepsake()) {
          const engraving = state.previewMode === "engraving";
          const side = engraving
            ? Math.min(usableW, usableH)
            : Math.min(usableW / 1.16, usableH / 1.5);
          const outer = {
            width: side,
            height: side,
            x: engraving ? (width - side) / 2 : (width - side) / 2 - side * 0.08,
            y: engraving ? (height - side) / 2 : margin,
          };
          const artworkSide = side * Math.min(0.84, g.artworkW / g.outerW);
          const artwork = {
            width: artworkSide,
            height: artworkSide,
            x: outer.x + (side - artworkSide) / 2,
            y: outer.y + (side - artworkSide) / 2,
          };
          const base = engraving ? null : {
            width: side,
            height: side,
            x: outer.x + side * 0.16,
            y: outer.y + side * 0.54,
          };
          return { g, scale: side / g.outerW, outer, inner: { ...artwork }, artwork, boxBase: base, mode: state.previewMode, width, height };
        }
        const scale = Math.min(usableW / g.outerW, usableH / g.outerH);
        const outer = {
          width: g.outerW * scale,
          height: g.outerH * scale,
        };
        outer.x = (width - outer.width) / 2;
        outer.y = margin;
        const inner = {
          width: g.innerW * scale,
          height: g.innerH * scale,
        };
        inner.x = outer.x + ((outer.width - inner.width) / 2);
        inner.y = outer.y + ((outer.height - inner.height) / 2);
        const hasMat = Boolean(config?.hasMat);
        const artwork = hasMat ? {
          x: inner.x + (g.matLeft * scale),
          y: inner.y + (g.matTop * scale),
          width: g.artworkW * scale,
          height: g.artworkH * scale,
        } : { ...inner };
        if (artwork.x + artwork.width > inner.x + inner.width + 0.5 || artwork.y + artwork.height > inner.y + inner.height + 0.5) {
          artwork.width = Math.min(inner.width, g.artworkW * scale);
          artwork.height = Math.min(inner.height, g.artworkH * scale);
          artwork.x = inner.x + ((inner.width - artwork.width) / 2);
          artwork.y = inner.y + ((inner.height - artwork.height) / 2);
        }
        return { g, scale, outer, inner, artwork, width, height };
      };

      const clampCrop = (layout) => {
        if (!state.imageWidth || !state.imageHeight) return null;
        const art = layout.artwork;
        const imageScale = Math.max(art.width / state.imageWidth, art.height / state.imageHeight) * state.zoom;
        const halfVisibleW = art.width / (2 * imageScale);
        const halfVisibleH = art.height / (2 * imageScale);
        state.centerX = Math.min(state.imageWidth - halfVisibleW, Math.max(halfVisibleW, state.centerX));
        state.centerY = Math.min(state.imageHeight - halfVisibleH, Math.max(halfVisibleH, state.centerY));
        return { imageScale, halfVisibleW, halfVisibleH };
      };

      const drawFrame = (context, layout) => {
        const { g, outer, inner } = layout;
        context.save();
        context.fillStyle = g.frameColor;
        context.beginPath();
        context.rect(outer.x, outer.y, outer.width, outer.height);
        context.rect(inner.x, inner.y, inner.width, inner.height);
        context.fill("evenodd");
        const texture = loadAsset(g.frameTexture);
        if (texture?.complete && texture.naturalWidth) {
          const pattern = context.createPattern(texture, "repeat");
          if (pattern) {
            context.globalAlpha = 0.5;
            context.fillStyle = pattern;
            context.beginPath();
            context.rect(outer.x, outer.y, outer.width, outer.height);
            context.rect(inner.x, inner.y, inner.width, inner.height);
            context.fill("evenodd");
          }
        } else {
          context.globalAlpha = 0.16;
          context.strokeStyle = "#ffffff";
          const spacing = Math.max(5, Math.min(11, outer.width / 42));
          for (let x = outer.x + spacing; x < outer.x + outer.width; x += spacing) {
            context.beginPath();
            context.moveTo(x, outer.y + 1);
            context.lineTo(x + Math.sin(x) * 2, inner.y - 1);
            context.stroke();
          }
        }
        context.globalAlpha = 1;
        context.strokeStyle = "rgba(30, 20, 15, .24)";
        context.lineWidth = 1;
        context.strokeRect(outer.x + 0.5, outer.y + 0.5, outer.width - 1, outer.height - 1);
        context.strokeRect(inner.x - 0.5, inner.y - 0.5, inner.width + 1, inner.height + 1);
        context.restore();
      };

      const roundedRectPath = (context, x, y, width, height, radius) => {
        const resolvedRadius = Math.max(0, Math.min(radius, width / 2, height / 2));
        if (typeof context.roundRect === "function") {
          context.roundRect(x, y, width, height, resolvedRadius);
          return;
        }
        context.moveTo(x + resolvedRadius, y);
        context.lineTo(x + width - resolvedRadius, y);
        context.quadraticCurveTo(x + width, y, x + width, y + resolvedRadius);
        context.lineTo(x + width, y + height - resolvedRadius);
        context.quadraticCurveTo(x + width, y + height, x + width - resolvedRadius, y + height);
        context.lineTo(x + resolvedRadius, y + height);
        context.quadraticCurveTo(x, y + height, x, y + height - resolvedRadius);
        context.lineTo(x, y + resolvedRadius);
        context.quadraticCurveTo(x, y, x + resolvedRadius, y);
        context.closePath();
      };

      const drawPhoto = (context, layout) => {
        const { artwork } = layout;
        context.save();
        context.beginPath();
        if (isBlanket()) {
          const radius = Math.min(artwork.width, artwork.height) * 0.035;
          roundedRectPath(context, artwork.x, artwork.y, artwork.width, artwork.height, radius);
        } else {
          context.rect(artwork.x, artwork.y, artwork.width, artwork.height);
        }
        context.clip();
        if (state.image) {
          const crop = clampCrop(layout);
          const drawWidth = state.imageWidth * crop.imageScale;
          const drawHeight = state.imageHeight * crop.imageScale;
          const drawX = artwork.x + (artwork.width / 2) - (state.centerX * crop.imageScale);
          const drawY = artwork.y + (artwork.height / 2) - (state.centerY * crop.imageScale);
          context.drawImage(state.image, drawX, drawY, drawWidth, drawHeight);
        } else {
          context.fillStyle = "#e7ded7";
          context.fillRect(artwork.x, artwork.y, artwork.width, artwork.height);
        }
        context.restore();
      };

      const drawBlanketBase = (context, layout) => {
        const { g, outer } = layout;
        const radius = Math.min(outer.width, outer.height) * 0.035;
        context.save();
        context.shadowColor = "rgba(58, 39, 31, .24)";
        context.shadowBlur = Math.max(12, Math.min(34, outer.width * 0.045));
        context.shadowOffsetY = Math.max(6, outer.height * 0.018);
        context.fillStyle = g.blanketColor;
        context.beginPath();
        roundedRectPath(context, outer.x, outer.y, outer.width, outer.height, radius);
        context.fill();
        context.restore();
      };

      const drawBlanketFinish = (context, layout) => {
        const { g, outer } = layout;
        const radius = Math.min(outer.width, outer.height) * 0.035;
        context.save();
        context.beginPath();
        roundedRectPath(context, outer.x, outer.y, outer.width, outer.height, radius);
        context.clip();

        const texture = loadAsset(g.blanketTexture);
        if (texture?.complete && texture.naturalWidth) {
          const pattern = context.createPattern(texture, "repeat");
          if (pattern) {
            context.globalAlpha = 0.24;
            context.globalCompositeOperation = "multiply";
            context.fillStyle = pattern;
            context.fillRect(outer.x, outer.y, outer.width, outer.height);
          }
        }

        context.globalAlpha = 1;
        context.globalCompositeOperation = "multiply";
        context.lineCap = "round";
        [0.18, 0.42, 0.68, 0.86].forEach((position, index) => {
          const x = outer.x + outer.width * position;
          const direction = index % 2 === 0 ? 1 : -1;
          context.beginPath();
          context.moveTo(x, outer.y - outer.height * 0.03);
          context.bezierCurveTo(
            x + direction * outer.width * 0.045,
            outer.y + outer.height * 0.28,
            x - direction * outer.width * 0.04,
            outer.y + outer.height * 0.72,
            x + direction * outer.width * 0.02,
            outer.y + outer.height * 1.03,
          );
          context.strokeStyle = "rgba(76, 57, 49, .075)";
          context.lineWidth = Math.max(3, outer.width * 0.018);
          context.shadowColor = "rgba(255, 255, 255, .16)";
          context.shadowBlur = Math.max(3, outer.width * 0.012);
          context.stroke();
        });

        const sheen = context.createLinearGradient(outer.x, outer.y, outer.x + outer.width, outer.y + outer.height);
        sheen.addColorStop(0, "rgba(255,255,255,.13)");
        sheen.addColorStop(0.45, "rgba(255,255,255,0)");
        sheen.addColorStop(1, "rgba(55,35,28,.1)");
        context.globalCompositeOperation = "source-over";
        context.fillStyle = sheen;
        context.fillRect(outer.x, outer.y, outer.width, outer.height);
        context.restore();

        context.save();
        context.beginPath();
        roundedRectPath(context, outer.x + 1, outer.y + 1, outer.width - 2, outer.height - 2, radius);
        context.strokeStyle = "rgba(74, 50, 39, .35)";
        context.lineWidth = Math.max(1, outer.width * 0.004);
        context.stroke();
        context.setLineDash([Math.max(3, outer.width * 0.012), Math.max(2, outer.width * 0.008)]);
        context.beginPath();
        roundedRectPath(
          context,
          outer.x + outer.width * 0.018,
          outer.y + outer.width * 0.018,
          outer.width * 0.964,
          outer.height - outer.width * 0.036,
          Math.max(2, radius * 0.7),
        );
        context.strokeStyle = "rgba(255, 255, 255, .5)";
        context.lineWidth = Math.max(1, outer.width * 0.0025);
        context.stroke();
        context.restore();
      };

      const drawWoodPanel = (context, panel, color, radius, inset = false) => {
        context.save();
        context.shadowColor = inset ? "rgba(37, 20, 10, .15)" : "rgba(45, 24, 12, .3)";
        context.shadowBlur = inset ? 5 : Math.max(10, panel.width * 0.06);
        context.shadowOffsetY = inset ? 2 : Math.max(5, panel.height * 0.035);
        const gradient = context.createLinearGradient(panel.x, panel.y, panel.x + panel.width, panel.y + panel.height);
        gradient.addColorStop(0, color);
        gradient.addColorStop(0.48, color);
        gradient.addColorStop(1, "rgba(38, 19, 8, .92)");
        context.fillStyle = gradient;
        context.beginPath();
        roundedRectPath(context, panel.x, panel.y, panel.width, panel.height, radius);
        context.fill();
        context.restore();

        context.save();
        context.beginPath();
        roundedRectPath(context, panel.x, panel.y, panel.width, panel.height, radius);
        context.clip();
        const texture = loadAsset(geometry().woodTexture);
        if (texture?.complete && texture.naturalWidth) {
          const pattern = context.createPattern(texture, "repeat");
          if (pattern) {
            context.globalAlpha = 0.42;
            context.globalCompositeOperation = "multiply";
            context.fillStyle = pattern;
            context.fillRect(panel.x, panel.y, panel.width, panel.height);
          }
        } else {
          context.globalAlpha = 0.18;
          context.strokeStyle = "#2d160c";
          context.lineWidth = Math.max(0.6, panel.width * 0.003);
          const spacing = Math.max(7, panel.width / 18);
          for (let grainY = panel.y + spacing; grainY < panel.y + panel.height; grainY += spacing) {
            context.beginPath();
            context.moveTo(panel.x - 4, grainY);
            context.bezierCurveTo(
              panel.x + panel.width * 0.3,
              grainY + Math.sin(grainY) * 3,
              panel.x + panel.width * 0.68,
              grainY - Math.cos(grainY) * 3,
              panel.x + panel.width + 4,
              grainY + Math.sin(grainY * 0.5) * 2,
            );
            context.stroke();
          }
        }
        context.globalAlpha = 1;
        context.globalCompositeOperation = "source-over";
        context.strokeStyle = "rgba(45, 22, 10, .5)";
        context.lineWidth = Math.max(1, panel.width * 0.008);
        context.beginPath();
        roundedRectPath(context, panel.x + 0.5, panel.y + 0.5, panel.width - 1, panel.height - 1, radius);
        context.stroke();
        context.restore();
      };

      const drawPaw = (context, centerX, centerY, size, color) => {
        context.save();
        context.translate(centerX, centerY);
        context.rotate(-0.05);
        context.fillStyle = color;
        const toe = (x, y, rx, ry, rotation) => {
          context.save();
          context.translate(x * size, y * size);
          context.rotate(rotation);
          context.beginPath();
          context.ellipse(0, 0, rx * size, ry * size, 0, 0, Math.PI * 2);
          context.fill();
          context.restore();
        };
        toe(-0.29, -0.25, 0.11, 0.15, -0.35);
        toe(-0.1, -0.38, 0.105, 0.155, -0.12);
        toe(0.12, -0.38, 0.105, 0.155, 0.12);
        toe(0.3, -0.24, 0.11, 0.15, 0.35);
        context.beginPath();
        context.moveTo(-0.33 * size, 0.12 * size);
        context.bezierCurveTo(-0.29 * size, -0.12 * size, -0.12 * size, -0.17 * size, 0, -0.02 * size);
        context.bezierCurveTo(0.12 * size, -0.17 * size, 0.29 * size, -0.12 * size, 0.33 * size, 0.12 * size);
        context.bezierCurveTo(0.36 * size, 0.35 * size, 0.18 * size, 0.48 * size, 0, 0.4 * size);
        context.bezierCurveTo(-0.18 * size, 0.48 * size, -0.36 * size, 0.35 * size, -0.33 * size, 0.12 * size);
        context.fill();
        context.restore();
      };

      const drawKeepsake = (context, layout) => {
        const { g, outer, artwork, boxBase, mode } = layout;
        const radius = outer.width * 0.075;
        const engravingColor = g.woodColor.toLocaleLowerCase() === "#6b3f25" ? "#25130b" : "#5c3218";
        if (mode === "engraving") {
          drawWoodPanel(context, outer, g.woodColor, radius);
          const safeScale = Math.min(g.engravingW / g.outerW, g.engravingH / g.outerH, 0.82);
          drawPaw(context, outer.x + outer.width / 2, outer.y + outer.height * 0.4, outer.width * safeScale * 0.36, engravingColor);
          const displayName = nameInput.value.trim() || (text.petNamePlaceholder || "PET NAME");
          let fontSize = outer.width * 0.105;
          context.save();
          context.fillStyle = engravingColor;
          context.textAlign = "center";
          context.textBaseline = "middle";
          context.font = `600 ${fontSize}px ${nameFonts.montserrat}`;
          const maxTextWidth = outer.width * safeScale;
          const measured = context.measureText(displayName).width;
          if (measured > maxTextWidth) fontSize *= maxTextWidth / measured;
          context.font = `600 ${fontSize}px ${nameFonts.montserrat}`;
          context.fillText(displayName, outer.x + outer.width / 2, outer.y + outer.height * 0.7);
          context.restore();
          return null;
        }

        drawWoodPanel(context, outer, g.woodColor, radius);
        context.save();
        context.fillStyle = "rgba(25, 12, 6, .26)";
        context.beginPath();
        roundedRectPath(context, artwork.x - outer.width * 0.025, artwork.y - outer.width * 0.025, artwork.width + outer.width * 0.05, artwork.height + outer.width * 0.05, radius * 0.45);
        context.fill();
        context.restore();
        drawPhoto(context, layout);
        context.save();
        context.strokeStyle = "rgba(255,255,255,.35)";
        context.lineWidth = Math.max(1, outer.width * 0.008);
        context.strokeRect(artwork.x + 0.5, artwork.y + 0.5, artwork.width - 1, artwork.height - 1);
        context.restore();

        if (boxBase) {
          drawWoodPanel(context, boxBase, g.woodColor, radius);
          const cavity = {
            x: boxBase.x + boxBase.width * 0.1,
            y: boxBase.y + boxBase.height * 0.13,
            width: boxBase.width * 0.8,
            height: boxBase.height * 0.73,
          };
          context.save();
          context.fillStyle = "rgba(30, 15, 8, .34)";
          context.beginPath();
          roundedRectPath(context, cavity.x, cavity.y, cavity.width, cavity.height, radius * 0.45);
          context.fill();
          context.restore();
          const vialCount = g.vialCount;
          const slotWidth = cavity.width / vialCount;
          for (let index = 0; index < vialCount; index += 1) {
            if (index > 0) {
              const dividerX = cavity.x + slotWidth * index;
              context.fillStyle = g.woodColor;
              context.fillRect(dividerX - boxBase.width * 0.018, cavity.y, boxBase.width * 0.036, cavity.height);
              context.strokeStyle = "rgba(44, 21, 9, .5)";
              context.lineWidth = 1;
              context.strokeRect(dividerX - boxBase.width * 0.018, cavity.y, boxBase.width * 0.036, cavity.height);
            }
            const centerX = cavity.x + slotWidth * (index + 0.5);
            const bottleWidth = Math.min(slotWidth * 0.48, cavity.width * 0.22);
            const bottleHeight = cavity.height * 0.62;
            const bottleY = cavity.y + cavity.height * 0.25;
            context.save();
            context.fillStyle = "rgba(235, 246, 244, .48)";
            context.strokeStyle = "rgba(238, 251, 250, .82)";
            context.lineWidth = Math.max(1, bottleWidth * 0.06);
            context.beginPath();
            roundedRectPath(context, centerX - bottleWidth / 2, bottleY, bottleWidth, bottleHeight, bottleWidth * 0.22);
            context.fill();
            context.stroke();
            context.fillStyle = "#b9824d";
            context.fillRect(centerX - bottleWidth * 0.32, bottleY - bottleHeight * 0.12, bottleWidth * 0.64, bottleHeight * 0.18);
            context.restore();
          }
          context.save();
          context.strokeStyle = "rgba(55, 28, 13, .72)";
          context.lineWidth = Math.max(2, outer.width * 0.015);
          context.beginPath();
          context.moveTo(outer.x + outer.width * 0.23, outer.y + outer.height);
          context.lineTo(boxBase.x + boxBase.width * 0.2, boxBase.y);
          context.moveTo(outer.x + outer.width * 0.77, outer.y + outer.height);
          context.lineTo(boxBase.x + boxBase.width * 0.8, boxBase.y);
          context.stroke();
          context.restore();
        }
        return null;
      };

      const drawName = (context, artwork, overlay = currentNameOverlay()) => {
        if (!overlay.enabled || !overlay.text) return null;
        context.save();
        context.beginPath();
        context.rect(artwork.x, artwork.y, artwork.width, artwork.height);
        context.clip();
        const condensed = overlay.font === "oswald";
        const displayText = condensed ? overlay.text.toLocaleUpperCase() : overlay.text;
        const letters = condensed ? Array.from(displayText) : [];
        const fontWeight = nameFontWeights[overlay.font] || 400;
        const fontAtSize = (size) => `${fontWeight} ${size}px ${nameFonts[overlay.font] || nameFonts.georgia}`;
        const measuredWidthAtSize = (size) => {
          context.font = fontAtSize(size);
          if (!condensed) return context.measureText(displayText).width;
          return letters.reduce((total, letter) => total + context.measureText(letter).width, 0)
            + Math.max(0, letters.length - 1) * size * 0.16;
        };
        const requestedSize = artwork.width * overlay.sizePercent / 100;
        const maxWidth = artwork.width * 0.86;
        const measuredWidth = measuredWidthAtSize(requestedSize);
        const fittedSize = measuredWidth > maxWidth ? requestedSize * maxWidth / measuredWidth : requestedSize;
        const displayWidth = measuredWidthAtSize(fittedSize);
        const metrics = context.measureText(displayText);
        const ascent = metrics.actualBoundingBoxAscent || fittedSize * 0.8;
        const descent = metrics.actualBoundingBoxDescent || fittedSize * 0.2;
        const padding = Math.max(2, fittedSize * 0.08);
        context.textAlign = condensed ? "left" : "center";
        context.textBaseline = "alphabetic";
        context.lineJoin = "round";
        context.lineWidth = Math.max(1, fittedSize * 0.11);
        context.strokeStyle = normalizeHexColor(overlay.outlineColor, "#ffffff");
        context.fillStyle = normalizeHexColor(overlay.color, "#2d2522");
        const x = artwork.x + clamp(
          asNumber(overlay.positionX, 0.5) * artwork.width,
          displayWidth / 2 + padding,
          artwork.width - displayWidth / 2 - padding,
        );
        const y = artwork.y + clamp(
          asNumber(overlay.positionY, 0.94) * artwork.height,
          ascent + padding,
          artwork.height - descent - padding,
        );
        if (condensed) {
          let letterX = x - displayWidth / 2;
          letters.forEach((letter, index) => {
            if (overlay.outlineEnabled) context.strokeText(letter, letterX, y);
            context.fillText(letter, letterX, y);
            letterX += context.measureText(letter).width + (index < letters.length - 1 ? fittedSize * 0.16 : 0);
          });
        } else {
          if (overlay.outlineEnabled) context.strokeText(displayText, x, y);
          context.fillText(displayText, x, y);
        }
        context.restore();
        return {
          left: x - displayWidth / 2,
          right: x + displayWidth / 2,
          top: y - ascent,
          bottom: y + descent,
          positionX: (x - artwork.x) / artwork.width,
          positionY: (y - artwork.y) / artwork.height,
        };
      };

      const drawMat = (context, layout) => {
        if (!config?.hasMat) return;
        const { g, inner, artwork, scale } = layout;
        context.save();
        context.fillStyle = g.matColor;
        context.beginPath();
        context.rect(inner.x, inner.y, inner.width, inner.height);
        context.rect(artwork.x, artwork.y, artwork.width, artwork.height);
        context.fill("evenodd");
        const edge = Math.max(0.5, Math.min(2, g.matThickness * scale));
        context.strokeStyle = "rgba(82, 67, 59, .22)";
        context.lineWidth = edge;
        context.strokeRect(artwork.x + (edge / 2), artwork.y + (edge / 2), artwork.width - edge, artwork.height - edge);
        context.shadowColor = "rgba(82, 67, 59, .12)";
        context.shadowBlur = Math.max(0.5, edge);
        context.shadowOffsetY = Math.max(0.5, edge * 0.5);
        context.strokeStyle = "rgba(255, 255, 255, .8)";
        context.lineWidth = Math.max(0.5, edge * 0.7);
        context.strokeRect(artwork.x - (edge / 2), artwork.y - (edge / 2), artwork.width + edge, artwork.height + edge);
        context.restore();
      };

      const drawProductPreview = (context, layout, nameOverlay = currentNameOverlay()) => {
        let nameBounds = null;
        if (isKeepsake()) {
          nameBounds = drawKeepsake(context, layout);
        } else if (isBlanket()) {
          drawBlanketBase(context, layout);
          drawPhoto(context, layout);
          nameBounds = drawName(context, layout.artwork, nameOverlay);
          drawBlanketFinish(context, layout);
        } else {
          context.save();
          context.shadowColor = "rgba(56, 35, 25, .18)";
          context.shadowBlur = 16;
          context.shadowOffsetY = 8;
          context.fillStyle = "rgba(255,255,255,.01)";
          context.fillRect(layout.outer.x, layout.outer.y, layout.outer.width, layout.outer.height);
          context.restore();
          context.fillStyle = layout.g.matColor;
          context.fillRect(layout.inner.x, layout.inner.y, layout.inner.width, layout.inner.height);
          drawPhoto(context, layout);
          nameBounds = drawName(context, layout.artwork, nameOverlay);
          drawMat(context, layout);
          drawFrame(context, layout);
        }
        const overlay = loadAsset(layout.g.overlay);
        if (overlay?.complete && overlay.naturalWidth) {
          context.drawImage(overlay, layout.outer.x, layout.outer.y, layout.outer.width, layout.outer.height);
        }
        return nameBounds;
      };

      function render() {
        viewLargeButton.disabled = !state.image || generatingStyle;
        const rect = canvasWrap.getBoundingClientRect();
        if (!rect.width || !rect.height) return;
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        const width = rect.width;
        const height = rect.height;
        if (canvas.width !== Math.round(width * dpr) || canvas.height !== Math.round(height * dpr)) {
          canvas.width = Math.round(width * dpr);
          canvas.height = Math.round(height * dpr);
          canvas.style.width = `${width}px`;
          canvas.style.height = `${height}px`;
        }
        const context = canvas.getContext("2d");
        context.setTransform(dpr, 0, 0, dpr, 0, 0);
        context.clearRect(0, 0, width, height);
        context.fillStyle = "#f1dfd5";
        context.fillRect(0, 0, width, height);
        const layout = computeLayout(width, height);
        state.layout = layout;
        state.nameBounds = drawProductPreview(context, layout);
        if (state.nameBounds) {
          state.nameX = state.nameBounds.positionX;
          state.nameY = state.nameBounds.positionY;
        }
        placeholder.hidden = Boolean(state.image) || (isKeepsake() && state.previewMode === "engraving");
        updateRecipe();
        updateQuality();
      }

      const currentRecipe = () => {
        if (!state.image || !state.layout) return null;
        const crop = clampCrop(state.layout);
        const sourceWidth = state.layout.artwork.width / crop.imageScale;
        const sourceHeight = state.layout.artwork.height / crop.imageScale;
        const sourceX = state.centerX - (sourceWidth / 2);
        const sourceY = state.centerY - (sourceHeight / 2);
        return {
          x: Math.max(0, sourceX / state.imageWidth),
          y: Math.max(0, sourceY / state.imageHeight),
          width: Math.min(1, sourceWidth / state.imageWidth),
          height: Math.min(1, sourceHeight / state.imageHeight),
          zoom: state.zoom,
          style: selectedStyle(),
          variant: state.styleKey === selectedStyle() ? state.variantKey : "",
          nameOverlay: currentNameOverlay(),
        };
      };

      const updateRecipe = () => {
        const recipe = currentRecipe();
        recipeInput.value = recipe ? JSON.stringify(recipe) : "";
      };

      const updateQuality = () => {
        if (!state.image || !config?.geometry || selectedStyle() !== "original" || state.styleKey !== "original") {
          quality.textContent = "";
          quality.classList.remove("is-low");
          return;
        }
        const g = geometry();
        const recipe = currentRecipe();
        if (!recipe) return;
        const cropPixelsW = state.imageWidth * recipe.width;
        const cropPixelsH = state.imageHeight * recipe.height;
        const dpi = Math.min(cropPixelsW / (g.printW / 25.4), cropPixelsH / (g.printH / 25.4));
        const low = dpi < g.minDpi;
        quality.textContent = low
          ? `${text.lowResolution || "This image may be too small for the selected print size."} (${Math.round(dpi)} DPI)`
          : "";
        quality.classList.toggle("is-low", low);
      };

      const cleanLabel = (name) => name
        .replace(/^attribute_/, "")
        .replace(/^pa_/, "")
        .replaceAll("_", " ")
        .replaceAll("-", " ")
        .replace(/\b\w/g, (letter) => letter.toUpperCase());

      const getDisplayedPrice = (priceRoot) => {
        if (!priceRoot) return "";
        const saleAmount = priceRoot.querySelector("ins .woocommerce-Price-amount, ins .amount");
        if (saleAmount?.textContent?.trim()) return saleAmount.textContent.trim();
        const regularAmount = priceRoot.querySelector(".woocommerce-Price-amount, .amount");
        return regularAmount?.textContent?.trim() || priceRoot.textContent?.trim() || "";
      };

      const syncSummary = () => {
        const attributes = config?.roles || {};
        const entries = (isKeepsake() ? [
          ["Size", attributes.size],
          ["Wood", attributes.wood],
        ] : isBlanket() ? [
          ["Size", attributes.size],
          ["Orientation", attributes.orientation],
        ] : [
          ["Size", attributes.size],
          ["Frame", attributes.frame],
          ["Mat", attributes.mat],
          ["Orientation", attributes.orientation],
        ]).filter((entry) => entry[1]);
        if (!entries.length && config?.attributes) {
          Object.entries(config.attributes).forEach(([key, value]) => entries.push([cleanLabel(key), value]));
        }
        const variationPrice = getDisplayedPrice(form.querySelector(".single_variation .woocommerce-variation-price .price"))
          || getDisplayedPrice(form.closest(".summary")?.querySelector(":scope > .price"))
          || "—";
        entries.push(["Price", variationPrice]);
        summary.innerHTML = entries.map(([label, value]) => `<div><span>${label}</span><strong>${String(value || "—")}</strong></div>`).join("");
        const aspect = isKeepsake() ? 1 : geometry().outerW / geometry().outerH;
        canvasWrap.style.setProperty("--tikool-fc-aspect", String(aspect));
        render();
      };

      const setConfiguration = (nextConfig) => {
        if (!nextConfig) return;
        config = nextConfig;
        nameInput.maxLength = Math.max(1, Number.parseInt(config.nameMaxLength || "100", 10) || 100);
        preparedSignature = "";
        syncSummary();
      };

      const clampLargePan = () => {
        const scaledWidth = largeImage.naturalWidth * largeScale;
        const scaledHeight = largeImage.naturalHeight * largeScale;
        const maxX = Math.max(0, (scaledWidth - largeStage.clientWidth) / 2);
        const maxY = Math.max(0, (scaledHeight - largeStage.clientHeight) / 2);
        largePanX = clamp(largePanX, -maxX, maxX);
        largePanY = clamp(largePanY, -maxY, maxY);
      };

      const updateLargePreviewTransform = () => {
        if (!largeImage.naturalWidth) return;
        clampLargePan();
        largeImage.style.transform = `translate(-50%, -50%) translate3d(${largePanX}px, ${largePanY}px, 0) scale(${largeScale})`;
        largeZoomValue.value = `${Math.round(largeScale * 100)}%`;
        largeZoomValue.textContent = largeZoomValue.value;
        largeZoomOut.disabled = largeScale <= largeFitScale + 0.001;
        largeZoomIn.disabled = largeScale >= 4 - 0.001;
      };

      const calculateLargeFitScale = () => {
        if (!largeImage.naturalWidth || !largeStage.clientWidth || !largeStage.clientHeight) return 1;
        return Math.min(
          1,
          Math.max(0.05, (largeStage.clientWidth - 24) / largeImage.naturalWidth),
          Math.max(0.05, (largeStage.clientHeight - 24) / largeImage.naturalHeight),
        );
      };

      const resetLargePreview = (actualSize = false) => {
        largeFitScale = calculateLargeFitScale();
        largeScale = actualSize ? 1 : largeFitScale;
        largePanX = 0;
        largePanY = 0;
        updateLargePreviewTransform();
      };

      const setLargeZoom = (nextScale, focalX = largeStage.clientWidth / 2, focalY = largeStage.clientHeight / 2) => {
        const previousScale = largeScale;
        const resolved = clamp(nextScale, largeFitScale, 4);
        if (Math.abs(resolved - previousScale) < 0.0001) return;
        const offsetX = focalX - (largeStage.clientWidth / 2);
        const offsetY = focalY - (largeStage.clientHeight / 2);
        const ratio = resolved / previousScale;
        largePanX = offsetX - ((offsetX - largePanX) * ratio);
        largePanY = offsetY - ((offsetY - largePanY) * ratio);
        largeScale = resolved;
        updateLargePreviewTransform();
      };

      const renderLargePreviewBlob = () => new Promise((resolve, reject) => {
        const recipe = currentRecipe();
        if (!recipe || !state.image) {
          reject(new Error("Missing preview source"));
          return;
        }
        const g = geometry();
        const artworkPixelsWide = Math.max(1, state.imageWidth * recipe.width);
        const artworkPixelsHigh = Math.max(1, state.imageHeight * recipe.height);
        const requestedWidth = artworkPixelsWide * g.outerW / g.artworkW;
        const requestedHeight = artworkPixelsHigh * g.outerH / g.artworkH;
        const outputScale = Math.min(1, 4096 / requestedWidth, 4096 / requestedHeight);
        const outputWidth = Math.max(1, Math.round(requestedWidth * outputScale));
        const outputHeight = Math.max(1, Math.round(requestedHeight * outputScale));
        const output = document.createElement("canvas");
        output.width = outputWidth;
        output.height = outputHeight;
        const context = output.getContext("2d", { alpha: false });
        context.fillStyle = "#f1dfd5";
        context.fillRect(0, 0, outputWidth, outputHeight);
        const layout = computeLayout(outputWidth, outputHeight);
        drawProductPreview(context, layout);
        output.toBlob((blob) => (blob ? resolve(blob) : reject(new Error("Large preview export failed"))), "image/jpeg", 0.96);
      });

      const closeLargePreview = () => {
        if (lightbox.hidden) return;
        lightbox.hidden = true;
        lightbox.setAttribute("aria-hidden", "true");
        modal.classList.remove("has-large-preview");
        largePointers.clear();
        largeDragStart = null;
        largePinchStart = null;
        largeStage.classList.remove("is-dragging");
        if (largePreviewObjectUrl) {
          URL.revokeObjectURL(largePreviewObjectUrl);
          largePreviewObjectUrl = "";
          largeImage.removeAttribute("src");
        }
        largePreviewLastFocused?.focus?.();
        largePreviewLastFocused = null;
      };

      const openLargePreview = async () => {
        if (!state.image || generatingStyle) return;
        clearErrors();
        try {
          await ensureNameFont();
          render();
          const blob = await renderLargePreviewBlob();
          if (largePreviewObjectUrl) URL.revokeObjectURL(largePreviewObjectUrl);
          largePreviewObjectUrl = URL.createObjectURL(blob);
          largeImage.src = largePreviewObjectUrl;
          largePreviewLastFocused = document.activeElement;
          lightbox.hidden = false;
          lightbox.setAttribute("aria-hidden", "false");
          modal.classList.add("has-large-preview");
          if (!largeImage.complete) {
            await new Promise((resolve, reject) => {
              largeImage.addEventListener("load", resolve, { once: true });
              largeImage.addEventListener("error", reject, { once: true });
            });
          }
          window.requestAnimationFrame(() => resetLargePreview(false));
          lightbox.querySelector(".tikool-fc-lightbox__close")?.focus();
        } catch (error) {
          showError(text.largePreviewError || "We could not open the large preview. Please try again.", viewLargeButton);
        }
      };

      const openModal = () => {
        clearErrors();
        syncSummary();
        lastFocused = document.activeElement;
        modal.hidden = false;
        document.body.classList.add("tikool-fc-open");
        window.requestAnimationFrame(() => {
          render();
          modal.querySelector(".tikool-fc-modal__close")?.focus();
        });
      };

      const closeModal = () => {
        closeLargePreview();
        modal.hidden = true;
        document.body.classList.remove("tikool-fc-open");
        lastFocused?.focus?.();
      };

      const largePoint = (event) => {
        const rect = largeStage.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
      };
      const largeDistance = (first, second) => Math.hypot(second.x - first.x, second.y - first.y);

      largeStage.addEventListener("pointerdown", (event) => {
        if (!largeImage.naturalWidth) return;
        event.preventDefault();
        const point = largePoint(event);
        largePointers.set(event.pointerId, point);
        largeStage.setPointerCapture(event.pointerId);
        largeStage.classList.add("is-dragging");
        if (largePointers.size === 1) {
          largeDragStart = { pointerId: event.pointerId, x: point.x, y: point.y, panX: largePanX, panY: largePanY };
        } else if (largePointers.size >= 2) {
          const [first, second] = [...largePointers.values()];
          largePinchStart = {
            distance: Math.max(1, largeDistance(first, second)),
            midpointX: (first.x + second.x) / 2,
            midpointY: (first.y + second.y) / 2,
          };
          largeDragStart = null;
        }
      });

      largeStage.addEventListener("pointermove", (event) => {
        if (!largePointers.has(event.pointerId)) return;
        event.preventDefault();
        largePointers.set(event.pointerId, largePoint(event));
        if (largePointers.size >= 2) {
          const [first, second] = [...largePointers.values()];
          const distance = Math.max(1, largeDistance(first, second));
          const midpointX = (first.x + second.x) / 2;
          const midpointY = (first.y + second.y) / 2;
          if (largePinchStart) {
            largePanX += midpointX - largePinchStart.midpointX;
            largePanY += midpointY - largePinchStart.midpointY;
            setLargeZoom(largeScale * distance / largePinchStart.distance, midpointX, midpointY);
          }
          largePinchStart = { distance, midpointX, midpointY };
          return;
        }
        if (largeDragStart?.pointerId === event.pointerId) {
          const point = largePointers.get(event.pointerId);
          largePanX = largeDragStart.panX + point.x - largeDragStart.x;
          largePanY = largeDragStart.panY + point.y - largeDragStart.y;
          updateLargePreviewTransform();
        }
      });

      const finishLargePointer = (event) => {
        if (!largePointers.has(event.pointerId)) return;
        largePointers.delete(event.pointerId);
        if (largeStage.hasPointerCapture(event.pointerId)) largeStage.releasePointerCapture(event.pointerId);
        largePinchStart = null;
        const remaining = [...largePointers.entries()][0];
        if (remaining) {
          largeDragStart = {
            pointerId: remaining[0],
            x: remaining[1].x,
            y: remaining[1].y,
            panX: largePanX,
            panY: largePanY,
          };
        } else {
          largeDragStart = null;
          largeStage.classList.remove("is-dragging");
        }
      };
      largeStage.addEventListener("pointerup", finishLargePointer);
      largeStage.addEventListener("pointercancel", finishLargePointer);

      largeStage.addEventListener("wheel", (event) => {
        if (!largeImage.naturalWidth) return;
        event.preventDefault();
        const point = largePoint(event);
        setLargeZoom(largeScale * Math.exp(-event.deltaY * 0.0015), point.x, point.y);
      }, { passive: false });

      largeStage.addEventListener("dblclick", (event) => {
        const point = largePoint(event);
        if (Math.abs(largeScale - largeFitScale) < 0.01) {
          setLargeZoom(1, point.x, point.y);
        } else {
          resetLargePreview(false);
        }
      });

      largeStage.addEventListener("keydown", (event) => {
        if (["+", "="].includes(event.key)) {
          event.preventDefault();
          setLargeZoom(largeScale * 1.25);
        } else if (event.key === "-") {
          event.preventDefault();
          setLargeZoom(largeScale / 1.25);
        } else if (event.key === "0") {
          event.preventDefault();
          resetLargePreview(false);
        } else if (event.key === "1") {
          event.preventDefault();
          resetLargePreview(true);
        } else if (["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(event.key)) {
          event.preventDefault();
          const movement = event.shiftKey ? 80 : 30;
          if (event.key === "ArrowLeft") largePanX += movement;
          if (event.key === "ArrowRight") largePanX -= movement;
          if (event.key === "ArrowUp") largePanY += movement;
          if (event.key === "ArrowDown") largePanY -= movement;
          updateLargePreviewTransform();
        }
      });

      largeZoomOut.addEventListener("click", () => setLargeZoom(largeScale / 1.25));
      largeZoomIn.addEventListener("click", () => setLargeZoom(largeScale * 1.25));
      largeActual.addEventListener("click", () => resetLargePreview(true));
      largeFit.addEventListener("click", () => resetLargePreview(false));
      window.addEventListener("resize", () => {
        if (lightbox.hidden || !largeImage.naturalWidth) return;
        const wasFitted = Math.abs(largeScale - largeFitScale) < 0.01;
        largeFitScale = calculateLargeFitScale();
        if (wasFitted) largeScale = largeFitScale;
        updateLargePreviewTransform();
      });

      viewLargeButton.addEventListener("click", openLargePreview);
      lightboxCloseButtons.forEach((button) => button.addEventListener("click", closeLargePreview));

      nextButton.addEventListener("click", () => {
        if (variationForm && (!variationId?.value || variationSelects.some((select) => !select.value))) {
          stepError.textContent = text.missingVariation || "Choose all product options before continuing.";
          variationSelects.find((select) => !select.value)?.focus();
          return;
        }
        openModal();
      });
      closeButtons.forEach((button) => button.addEventListener("click", closeModal));

      modal.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
          event.preventDefault();
          if (!lightbox.hidden) {
            closeLargePreview();
            return;
          }
          closeModal();
          return;
        }
        if (event.key !== "Tab") return;
        const focusRoot = lightbox.hidden ? (modal.querySelector(".tikool-fc-modal__dialog") || modal) : lightbox;
        const focusable = [...focusRoot.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), [tabindex="0"]')]
          .filter((element) => element.offsetParent !== null);
        if (!focusable.length) return;
        if (event.shiftKey && document.activeElement === focusable[0]) {
          event.preventDefault();
          focusable.at(-1).focus();
        } else if (!event.shiftKey && document.activeElement === focusable.at(-1)) {
          event.preventDefault();
          focusable[0].focus();
        }
      });

      photoInput.addEventListener("change", () => {
        clearErrors();
        setRetryStyle();
        const file = photoInput.files?.[0];
        if (!file) return;
        styleCache.clear();
        selectedVariantByStyle.clear();
        aiSourcePromise = null;
        state.styleKey = "original";
        state.variantKey = "";
        state.cacheKey = "original";
        variantsPanel.hidden = true;
        variantsGrid.replaceChildren();
        styleInputs.forEach((input) => {
          input.checked = input.value === "original";
          input.disabled = input.value !== "original";
        });
        setStyleStatus(text.uploadingPhoto || "Preparing your photo…");
        const accepted = ["image/jpeg", "image/png", "image/webp"];
        if (!accepted.includes(file.type) || file.size > (settings.maxUploadBytes || 20 * 1024 * 1024)) {
          photoInput.value = "";
          previewInput.value = "";
          cartPreviewInput.value = "";
          if (state.objectUrl) URL.revokeObjectURL(state.objectUrl);
          state.objectUrl = "";
          state.image = null;
          state.imageWidth = 0;
          state.imageHeight = 0;
          filename.textContent = text.noPhoto || "No photo selected";
          zoomInput.disabled = true;
          setStyleStatus(text.missingPhoto || "Upload a photo to unlock AI styles.", true);
          render();
          showError(text.invalidPhoto || "Choose a supported image within the upload limit.", photoInput);
          return;
        }
        if (state.objectUrl) URL.revokeObjectURL(state.objectUrl);
        state.objectUrl = URL.createObjectURL(file);
        state.sourceName = file.name;
        state.image = null;
        state.imageWidth = 0;
        state.imageHeight = 0;
        state.centerX = 0;
        state.centerY = 0;
        state.zoom = 1;
        zoomInput.value = "1";
        zoomInput.disabled = true;
        preparedSignature = "";
        render();
        const image = new Image();
        image.onload = () => {
          styleCache.set("original", {
            image,
            width: image.naturalWidth,
            height: image.naturalHeight,
            centerX: image.naturalWidth / 2,
            centerY: image.naturalHeight / 2,
            zoom: 1,
            sourceUrl: "",
          });
          aiSourcePromise = config?.supportsAI === false ? Promise.resolve(file) : prepareAIImageFile(file, image).catch(() => file);
          activateStyle("original");
          enableStyleInputs(config?.supportsAI !== false);
          preparedSignature = "";
          filename.textContent = file.name;
          setStyleStatus(config?.supportsAI === false ? "" : (text.chooseStyle || "Choose a style. AI styles are generated once for this photo."));
          render();
          canvasWrap.focus();
        };
        image.onerror = () => {
          photoInput.value = "";
          previewInput.value = "";
          cartPreviewInput.value = "";
          if (state.objectUrl) URL.revokeObjectURL(state.objectUrl);
          state.objectUrl = "";
          state.image = null;
          state.imageWidth = 0;
          state.imageHeight = 0;
          aiSourcePromise = null;
          filename.textContent = text.noPhoto || "No photo selected";
          zoomInput.disabled = true;
          setStyleStatus(text.missingPhoto || "Upload a photo to unlock AI styles.", true);
          render();
          showError(text.invalidPhoto || "Choose a supported image.", photoInput);
        };
        image.src = state.objectUrl;
      });
      placeholder.addEventListener("click", () => photoInput.click());

      const loadGeneratedStyle = (styleKey, variantKey, url) => new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => {
          styleCache.set(cacheKeyFor(styleKey, variantKey), {
            styleKey,
            variantKey,
            image,
            width: image.naturalWidth,
            height: image.naturalHeight,
            centerX: image.naturalWidth / 2,
            centerY: image.naturalHeight / 2,
            zoom: 1,
            sourceUrl: url,
          });
          resolve(image);
        };
        image.onerror = () => reject(new Error("Generated preview could not be loaded"));
        image.src = url;
      });

      const wait = (milliseconds) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

      const pollStyleJob = async (jobToken, jobAccessToken = "") => {
        const deadline = Date.now() + 8 * 60 * 1000;
        let consecutiveConnectionErrors = 0;
        let consecutiveNotFoundErrors = 0;
        while (Date.now() < deadline) {
          const body = new FormData();
          body.append("action", "tikool_fc_ai_job_status");
          body.append("nonce", nonceInput.value);
          body.append("product_id", String(config?.productId || settings.productId || ""));
          body.append("job_token", jobToken);
          body.append("job_access_token", jobAccessToken);
          let response;
          let payload;
          try {
            response = await fetch(settings.ajaxUrl, { method: "POST", body, credentials: "same-origin" });
            payload = await response.json();
            consecutiveConnectionErrors = 0;
          } catch (error) {
            consecutiveConnectionErrors += 1;
            if (consecutiveConnectionErrors >= 3) {
              throw new Error(text.jobStatusError || "We temporarily lost the status connection. The portrait may still be processing.");
            }
            await wait(3000);
            continue;
          }
          if (!response.ok || !payload?.success) {
            if (response.status === 404 && consecutiveNotFoundErrors < 2) {
              consecutiveNotFoundErrors += 1;
              await wait(3000);
              continue;
            }
            const statusError = new Error(payload?.data?.message || text.jobStatusError || "Portrait status could not be checked.");
            statusError.httpStatus = response.status;
            throw statusError;
          }
          consecutiveNotFoundErrors = 0;
          const data = payload.data || {};
          if (data.status === "success" && data.previewUrl) return data.previewUrl;
          if (data.status === "failed") {
            const jobError = new Error(data.message || text.styleFailed || "AI style generation failed.");
            jobError.code = data.code || "tikool_fc_generation_failed";
            throw jobError;
          }
          await wait(Math.max(2, Number.parseInt(data.pollAfter || "3", 10) || 3) * 1000);
        }
        throw new Error(text.jobStatusError || "The portrait is taking longer than expected. Use Try again to reconnect to it.");
      };

      const requestGeneratedStyle = async (styleKey, variantKey = "") => {
        const originalFile = photoInput.files?.[0];
        if (!originalFile) throw new Error(text.missingPhoto || "Choose a photo first.");
        const aiSourceFile = await (aiSourcePromise || Promise.resolve(originalFile));
        const body = new FormData();
        body.append("action", "tikool_fc_generate_style");
        body.append("nonce", nonceInput.value);
        body.append("product_id", String(config?.productId || settings.productId || ""));
        body.append("variation_id", variationId?.value || String(config?.variationId || ""));
        body.append("style", styleKey);
        if (variantKey) body.append("variant", variantKey);
        body.append("image", aiSourceFile, aiSourceFile.name || originalFile.name);
        const response = await fetch(settings.ajaxUrl, {
          method: "POST",
          body,
          credentials: "same-origin",
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload?.success) {
          const requestError = new Error(payload?.data?.message || text.styleFailed || "AI style generation failed.");
          requestError.code = payload?.data?.code || "";
          requestError.httpStatus = response.status;
          requestError.retryAfter = Number.parseInt(payload?.data?.retryAfter || "0", 10) || 0;
          throw requestError;
        }
        let previewUrl = payload?.data?.previewUrl || "";
        if (!previewUrl && payload?.data?.jobToken) {
          previewUrl = await pollStyleJob(payload.data.jobToken, payload.data.jobAccessToken || "");
        }
        if (!previewUrl) throw new Error(text.styleFailed || "AI style generation failed.");
        return previewUrl;
      };

      const generateStyle = async (input, previousStyle, previousVariant = "", requestedVariant = "") => {
        const styleKey = input.value;
        const styleLabel = input.dataset.styleLabel || styleKey;
        const variants = variantsForInput(input);
        const targetVariant = variants.length
          ? (variants.find((variant) => variant.key === requestedVariant) || variants[0])
          : { key: "", label: styleLabel };
        if (targetVariant.key) selectedVariantByStyle.set(styleKey, targetVariant.key);
        renderVariantChoices(styleKey);
        updateQuality();

        if (activateStyle(styleKey, targetVariant.key)) {
          setStyleStatus(variants.length
            ? formatText(text.backgroundReady || "%s background is ready. Use the buttons beside the preview to switch.", [targetVariant.label || targetVariant.key])
            : (text.styleReady || "%s preview is ready. You can reposition or zoom it before adding to cart.").replace("%s", styleLabel));
          return;
        }
        if (!photoInput.files?.[0]) {
          const previousInput = styleInputs.find((item) => item.value === previousStyle);
          if (previousInput) previousInput.checked = true;
          showError(text.missingPhoto || "Choose a photo first.", photoInput);
          return;
        }

        generatingStyle = true;
        modal.classList.add("is-generating");
        enableStyleInputs(true);
        addButton.disabled = true;
        startAILoading(styleLabel);
        clearErrors();
        setRetryStyle();
        renderVariantChoices(styleKey);
        setStyleStatus(variants.length
          ? formatText(text.creatingSelectedBackground || "Creating %s background…", [targetVariant.label || targetVariant.key])
          : (text.generatingStyle || "Creating your %s portrait… This usually takes about 50 seconds.").replace("%s", styleLabel));

        try {
          const previewUrl = await requestGeneratedStyle(styleKey, targetVariant.key);
          await loadGeneratedStyle(styleKey, targetVariant.key, previewUrl);
          activateStyle(styleKey, targetVariant.key);
          setRetryStyle();
          setStyleStatus(variants.length
            ? formatText(text.backgroundReady || "%s background is ready. Use the buttons beside the preview to switch.", [targetVariant.label || targetVariant.key])
            : (text.styleReady || "%s preview is ready. You can reposition or zoom it before adding to cart.").replace("%s", styleLabel));
        } catch (error) {
          const previousCacheKey = cacheKeyFor(previousStyle, previousVariant);
          const fallbackStyle = styleCache.has(previousCacheKey) ? previousStyle : "original";
          const fallbackVariant = fallbackStyle === previousStyle ? previousVariant : "";
          activateStyle(fallbackStyle, fallbackVariant);
          const fallbackInput = styleInputs.find((item) => item.value === fallbackStyle);
          if (fallbackInput) fallbackInput.checked = true;
          const message = error instanceof Error && error.message ? error.message : (text.styleFailed || "We could not generate that style.");
          const retryAfter = error instanceof Error ? Math.max(0, Number(error.retryAfter || 0)) : 0;
          if (retryAfter > 0) {
            startRetryCooldown(retryAfter, message);
          } else {
            setStyleStatus(message, true);
          }
          setRetryStyle(input, targetVariant.key);
        } finally {
          generatingStyle = false;
          modal.classList.remove("is-generating");
          stopAILoading();
          enableStyleInputs(Boolean(photoInput.files?.length));
          addButton.disabled = false;
          renderVariantChoices(selectedStyle());
        }
      };

      styleRetry.addEventListener("click", () => {
        if (!failedStyleInput || generatingStyle || Date.now() < retryCooldownUntil || !photoInput.files?.[0]) return;
        const input = failedStyleInput;
        const previousStyle = state.styleKey;
        const previousVariant = state.variantKey;
        input.checked = true;
        generateStyle(input, previousStyle, previousVariant, failedVariantKey);
      });

      styleInputs.forEach((input) => {
        input.addEventListener("change", () => {
          if (!input.checked || generatingStyle) return;
          const previousStyle = state.styleKey;
          const previousVariant = state.variantKey;
          if (input.value === "original") {
            activateStyle("original");
            renderVariantChoices("original");
            setStyleStatus(input.dataset.styleLabel || "Original");
            return;
          }
          generateStyle(input, previousStyle, previousVariant);
        });
      });

      zoomInput.addEventListener("input", () => {
        state.zoom = asNumber(zoomInput.value, 1);
        preparedSignature = "";
        render();
      });

      const syncNameOptions = () => {
        nameOptions.hidden = !nameOverlayInput.checked;
        nameOverlayInput.setAttribute("aria-expanded", String(nameOverlayInput.checked));
        nameInput.disabled = false;
        nameInput.required = true;
        nameFontInput.style.fontFamily = nameFonts[nameFontInput.value] || nameFonts.georgia;
        nameFontInput.style.fontWeight = String(nameFontWeights[nameFontInput.value] || 400);
        nameSizeValue.textContent = `${nameSizeInput.value}%`;
        nameOutlineColorInput.disabled = !nameOutlineInput.checked;
        nameOutlineColorWrap.classList.toggle("is-disabled", !nameOutlineInput.checked);
        preparedSignature = "";
        render();
        ensureNameFont().then(() => {
          preparedSignature = "";
          render();
        });
      };
      [...nameFontInput.options].forEach((option) => {
        option.style.fontFamily = nameFonts[option.value] || nameFonts.georgia;
        option.style.fontWeight = String(nameFontWeights[option.value] || 400);
      });
      nameOverlayInput.addEventListener("change", () => {
        syncNameOptions();
        if (nameOverlayInput.checked) nameFontInput.focus();
      });
      nameFontInput.addEventListener("change", syncNameOptions);
      nameSizeInput.addEventListener("input", syncNameOptions);
      nameColorInput.addEventListener("input", syncNameOptions);
      nameOutlineInput.addEventListener("change", syncNameOptions);
      nameOutlineColorInput.addEventListener("input", syncNameOptions);
      nameInput.addEventListener("input", () => {
        preparedSignature = "";
        render();
      });
      syncNameOptions();

      keepsakeViewButtons.forEach((button) => {
        button.addEventListener("click", () => {
          const nextMode = button.dataset.tikoolFcKeepsakeView;
          if (!isKeepsake() || !["inside", "engraving"].includes(nextMode)) return;
          state.previewMode = nextMode;
          keepsakeViewButtons.forEach((candidate) => {
            const active = candidate === button;
            candidate.classList.toggle("is-active", active);
            candidate.setAttribute("aria-pressed", String(active));
          });
          canvasWrap.classList.toggle("is-engraving-view", nextMode === "engraving");
          if (zoomControl) zoomControl.hidden = nextMode === "engraving";
          preparedSignature = "";
          render();
        });
      });

      canvasWrap.addEventListener("pointerdown", (event) => {
        if (!state.image || !state.layout || (isKeepsake() && state.previewMode === "engraving")) return;
        const rect = canvasWrap.getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;
        const art = state.layout.artwork;
        if (x < art.x || x > art.x + art.width || y < art.y || y > art.y + art.height) return;
        const bounds = state.nameBounds;
        const grabPadding = 16;
        state.pointerMode = bounds && x >= bounds.left - grabPadding && x <= bounds.right + grabPadding
          && y >= bounds.top - grabPadding && y <= bounds.bottom + grabPadding ? "name" : "photo";
        canvasWrap.classList.toggle("is-name-target", state.pointerMode === "name");
        state.pointerId = event.pointerId;
        state.pointerX = event.clientX;
        state.pointerY = event.clientY;
        state.startCenterX = state.centerX;
        state.startCenterY = state.centerY;
        state.startNameX = state.nameX;
        state.startNameY = state.nameY;
        canvasWrap.setPointerCapture(event.pointerId);
      });

      canvasWrap.addEventListener("pointermove", (event) => {
        if (!state.layout || (isKeepsake() && state.previewMode === "engraving")) return;
        if (state.pointerId !== event.pointerId) {
          const rect = canvasWrap.getBoundingClientRect();
          const x = event.clientX - rect.left;
          const y = event.clientY - rect.top;
          const bounds = state.nameBounds;
          canvasWrap.classList.toggle("is-name-target", Boolean(bounds && x >= bounds.left - 16 && x <= bounds.right + 16
            && y >= bounds.top - 16 && y <= bounds.bottom + 16));
          return;
        }
        if (state.pointerMode === "name") {
          state.nameX = clamp(state.startNameX + (event.clientX - state.pointerX) / state.layout.artwork.width, 0, 1);
          state.nameY = clamp(state.startNameY + (event.clientY - state.pointerY) / state.layout.artwork.height, 0, 1);
          preparedSignature = "";
          render();
          return;
        }
        const crop = clampCrop(state.layout);
        state.centerX = state.startCenterX - ((event.clientX - state.pointerX) / crop.imageScale);
        state.centerY = state.startCenterY - ((event.clientY - state.pointerY) / crop.imageScale);
        preparedSignature = "";
        render();
      });

      const finishPointer = (event) => {
        if (state.pointerId !== event.pointerId) return;
        state.pointerId = null;
        state.pointerMode = null;
        canvasWrap.classList.remove("is-name-target");
        if (canvasWrap.hasPointerCapture(event.pointerId)) canvasWrap.releasePointerCapture(event.pointerId);
      };
      canvasWrap.addEventListener("pointerup", finishPointer);
      canvasWrap.addEventListener("pointercancel", finishPointer);
      canvasWrap.addEventListener("pointerleave", () => canvasWrap.classList.remove("is-name-target"));

      canvasWrap.addEventListener("keydown", (event) => {
        if (!state.image || !state.layout || (isKeepsake() && state.previewMode === "engraving") || !["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(event.key)) return;
        event.preventDefault();
        if (event.altKey && nameOverlayInput.checked && nameInput.value.trim()) {
          const movement = event.shiftKey ? 20 : 5;
          if (event.key === "ArrowLeft") state.nameX = clamp(state.nameX - movement / state.layout.artwork.width, 0, 1);
          if (event.key === "ArrowRight") state.nameX = clamp(state.nameX + movement / state.layout.artwork.width, 0, 1);
          if (event.key === "ArrowUp") state.nameY = clamp(state.nameY - movement / state.layout.artwork.height, 0, 1);
          if (event.key === "ArrowDown") state.nameY = clamp(state.nameY + movement / state.layout.artwork.height, 0, 1);
          preparedSignature = "";
          render();
          return;
        }
        const crop = clampCrop(state.layout);
        const movement = (event.shiftKey ? 30 : 10) / crop.imageScale;
        if (event.key === "ArrowLeft") state.centerX -= movement;
        if (event.key === "ArrowRight") state.centerX += movement;
        if (event.key === "ArrowUp") state.centerY -= movement;
        if (event.key === "ArrowDown") state.centerY += movement;
        preparedSignature = "";
        render();
      });

      const canvasBlob = (outputCanvas) => new Promise((resolve, reject) => {
        outputCanvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error("Canvas export failed"))), "image/jpeg", 0.94);
      });

      const preparePreview = async () => {
        await ensureNameFont();
        if (document.fonts?.ready) await document.fonts.ready;
        render();
        const recipe = currentRecipe();
        if (!recipe || !state.image) throw new Error("Missing crop");
        const sourceX = recipe.x * state.imageWidth;
        const sourceY = recipe.y * state.imageHeight;
        const sourceWidth = recipe.width * state.imageWidth;
        const sourceHeight = recipe.height * state.imageHeight;
        const maxPreview = 4200;
        const outputScale = Math.min(1, maxPreview / sourceWidth, maxPreview / sourceHeight);
        const outputWidth = Math.max(1, Math.round(sourceWidth * outputScale));
        const outputHeight = Math.max(1, Math.round(sourceHeight * outputScale));
        const outputCanvas = document.createElement("canvas");
        outputCanvas.width = outputWidth;
        outputCanvas.height = outputHeight;
        const context = outputCanvas.getContext("2d", { alpha: false });
        context.fillStyle = "#ffffff";
        context.fillRect(0, 0, outputWidth, outputHeight);
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = "high";
        context.drawImage(state.image, sourceX, sourceY, sourceWidth, sourceHeight, 0, 0, outputWidth, outputHeight);
        drawName(context, { x: 0, y: 0, width: outputWidth, height: outputHeight }, recipe.nameOverlay);
        const blob = await canvasBlob(outputCanvas);
        const safeName = (nameInput.value.trim() || "custom")
          .toLocaleLowerCase()
          .replace(/[^a-z0-9]+/g, "-")
          .replace(/^-|-$/g, "") || "custom";
        const file = new File([blob], `${safeName}-artwork-preview.jpg`, { type: "image/jpeg", lastModified: Date.now() });
        const transfer = new DataTransfer();
        transfer.items.add(file);
        previewInput.files = transfer.files;
        let framedBlob = blob;
        try {
          framedBlob = await canvasBlob(canvas);
        } catch (error) {
          // An unavailable cross-origin decorative overlay must not block checkout.
        }
        const framedFile = new File([framedBlob], `${safeName}-framed-preview.jpg`, { type: "image/jpeg", lastModified: Date.now() });
        const framedTransfer = new DataTransfer();
        framedTransfer.items.add(framedFile);
        cartPreviewInput.files = framedTransfer.files;
        recipeInput.value = JSON.stringify(recipe);
        preparedSignature = JSON.stringify({ recipe, style: selectedStyle(), variation: variationId?.value || config?.variationId || config?.productId });
      };

      addButton.addEventListener("click", (event) => {
        clearErrors();
        if (generatingStyle) {
          event.preventDefault();
          showError(text.waitForStyle || "Wait for the AI portrait preview to finish.", styleStatus);
        } else if (!nameInput.value.trim()) {
          event.preventDefault();
          showError(text.missingName || "Enter your pet’s name.", nameInput);
        } else if (!state.image || !photoInput.files?.length) {
          event.preventDefault();
          showError(text.missingPhoto || "Choose a photo.", photoInput);
        }
      });

      form.addEventListener("submit", async (event) => {
        if (preparing) {
          event.preventDefault();
          return;
        }
        if (generatingStyle) {
          event.preventDefault();
          showError(text.waitForStyle || "Wait for the AI portrait preview to finish.", styleStatus);
          return;
        }
        if (!nameInput.value.trim()) {
          event.preventDefault();
          showError(text.missingName || "Enter your pet’s name.", nameInput);
          return;
        }
        if (!state.image || !photoInput.files?.length) {
          event.preventDefault();
          showError(text.missingPhoto || "Choose a photo.", photoInput);
          return;
        }
        const currentSignature = JSON.stringify({ recipe: currentRecipe(), style: selectedStyle(), variation: variationId?.value || config?.variationId || config?.productId });
        if (preparedSignature === currentSignature && previewInput.files?.length) return;

        event.preventDefault();
        preparing = true;
        modal.classList.add("is-preparing");
        addButton.disabled = true;
        const originalLabel = addButton.textContent;
        addButton.textContent = text.preparing || "Preparing preview…";
        try {
          await preparePreview();
          preparing = false;
          addButton.disabled = false;
          addButton.textContent = originalLabel;
          modal.classList.remove("is-preparing");
          form.requestSubmit(addButton);
        } catch (error) {
          preparing = false;
          addButton.disabled = false;
          addButton.textContent = originalLabel;
          modal.classList.remove("is-preparing");
          showError(text.cropError || "We could not prepare this crop.", photoInput);
        }
      });

      if (variationForm && window.jQuery) {
        const placeNfcNotice = () => {
          if (!nfcNotice) return;
          const variation = variationForm.querySelector(".single_variation");
          const description = variation?.querySelector(".woocommerce-variation-description");
          if (description) {
            description.insertAdjacentElement("afterend", nfcNotice);
          } else if (variation) {
            variation.prepend(nfcNotice);
          }
        };
        const restoreNfcNotice = () => {
          if (nfcNotice && nfcNoticeAnchor?.parentNode) {
            nfcNoticeAnchor.parentNode.insertBefore(nfcNotice, nfcNoticeAnchor.nextSibling);
          }
        };
        window.jQuery(variationForm).on("found_variation", (event, variation) => {
          if (variation?.tikool_frame_customizer) setConfiguration(variation.tikool_frame_customizer);
        });
        window.jQuery(variationForm).on("show_variation", placeNfcNotice);
        window.jQuery(variationForm).on("reset_data", () => {
          restoreNfcNotice();
          setConfiguration(parseConfig(modal.dataset.initialConfig) || config);
        });
      }

      const resizeObserver = "ResizeObserver" in window ? new ResizeObserver(() => render()) : null;
      resizeObserver?.observe(canvasWrap);
      window.addEventListener("resize", render, { passive: true });
      syncSummary();
    });
  });
})();
