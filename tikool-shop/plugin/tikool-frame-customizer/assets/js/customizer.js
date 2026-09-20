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

  ready(() => {
    document.querySelectorAll("form.cart").forEach((form) => {
      const modal = form.querySelector("[data-tikool-fc-modal]");
      if (!modal) return;

      const variationForm = form.matches(".variations_form") ? form : null;
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
      const photoInput = modal.querySelector("[data-tikool-fc-photo]");
      const previewInput = modal.querySelector("[data-tikool-fc-preview-file]");
      const filename = modal.querySelector("[data-tikool-fc-filename]");
      const zoomInput = modal.querySelector("[data-tikool-fc-zoom]");
      const quality = modal.querySelector("[data-tikool-fc-quality]");
      const recipeInput = modal.querySelector("[data-tikool-fc-recipe]");
      const errorBox = modal.querySelector("[data-tikool-fc-error]");
      const closeButtons = [...modal.querySelectorAll("[data-tikool-fc-close]")];
      const required = [addButton, purchaseSlot, preview, canvasWrap, canvas, placeholder, summary, nameInput, photoInput, previewInput, filename, zoomInput, quality, recipeInput, errorBox];
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
      let preparedSignature = "";
      const assetCache = new Map();
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
        layout: null,
      };

      const clearErrors = () => {
        errorBox.textContent = "";
        stepError.textContent = "";
      };

      const showError = (message, target = null) => {
        errorBox.textContent = message;
        target?.focus?.();
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
        };
      };

      const computeLayout = (width, height) => {
        const g = geometry();
        const standRoom = config?.stand ? Math.min(height * 0.12, 52) : 0;
        const margin = Math.max(16, Math.min(width, height) * 0.045);
        const usableW = width - (margin * 2);
        const usableH = height - (margin * 2) - standRoom;
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

      const drawStand = (context, layout) => {
        if (!config?.stand) return;
        const { outer } = layout;
        context.save();
        context.fillStyle = "rgba(67, 47, 37, .22)";
        context.beginPath();
        context.moveTo(outer.x + (outer.width * 0.38), outer.y + outer.height - 3);
        context.lineTo(outer.x + (outer.width * 0.2), outer.y + outer.height + Math.min(50, outer.height * 0.16));
        context.lineTo(outer.x + (outer.width * 0.48), outer.y + outer.height + Math.min(50, outer.height * 0.16));
        context.closePath();
        context.fill();
        context.restore();
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

      const drawPhoto = (context, layout) => {
        const { artwork } = layout;
        context.save();
        context.beginPath();
        context.rect(artwork.x, artwork.y, artwork.width, artwork.height);
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

      function render() {
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
        drawStand(context, layout);
        context.save();
        context.shadowColor = "rgba(56, 35, 25, .18)";
        context.shadowBlur = 16;
        context.shadowOffsetY = 8;
        context.fillStyle = "rgba(255,255,255,.01)";
        context.fillRect(layout.outer.x, layout.outer.y, layout.outer.width, layout.outer.height);
        context.restore();
        context.fillStyle = geometry().matColor;
        context.fillRect(layout.inner.x, layout.inner.y, layout.inner.width, layout.inner.height);
        drawPhoto(context, layout);
        drawMat(context, layout);
        drawFrame(context, layout);
        const overlay = loadAsset(layout.g.overlay);
        if (overlay?.complete && overlay.naturalWidth) {
          context.drawImage(overlay, layout.outer.x, layout.outer.y, layout.outer.width, layout.outer.height);
        }
        placeholder.hidden = Boolean(state.image);
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
        };
      };

      const updateRecipe = () => {
        const recipe = currentRecipe();
        recipeInput.value = recipe ? JSON.stringify(recipe) : "";
      };

      const updateQuality = () => {
        if (!state.image || !config?.geometry) {
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
          : `${text.recommended || "Recommended image quality"}: ${Math.round(dpi)} DPI`;
        quality.classList.toggle("is-low", low);
      };

      const cleanLabel = (name) => name
        .replace(/^attribute_/, "")
        .replace(/^pa_/, "")
        .replaceAll("_", " ")
        .replaceAll("-", " ")
        .replace(/\b\w/g, (letter) => letter.toUpperCase());

      const syncSummary = () => {
        const attributes = config?.roles || {};
        const entries = [
          ["Size", attributes.size],
          ["Frame", attributes.frame],
          ["Mat", attributes.mat],
          ["Orientation", attributes.orientation],
        ].filter((entry) => entry[1]);
        if (!entries.length && config?.attributes) {
          Object.entries(config.attributes).forEach(([key, value]) => entries.push([cleanLabel(key), value]));
        }
        const variationPrice = form.querySelector(".single_variation .woocommerce-variation-price .price")?.textContent?.trim()
          || form.closest(".summary")?.querySelector(":scope > .price")?.textContent?.trim()
          || "—";
        entries.push(["Price", variationPrice]);
        summary.innerHTML = entries.map(([label, value]) => `<div><span>${label}</span><strong>${String(value || "—")}</strong></div>`).join("");
        const aspect = geometry().outerW / geometry().outerH;
        canvasWrap.style.setProperty("--tikool-fc-aspect", String(aspect));
        render();
      };

      const setConfiguration = (nextConfig) => {
        if (!nextConfig) return;
        config = nextConfig;
        preparedSignature = "";
        syncSummary();
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
        modal.hidden = true;
        document.body.classList.remove("tikool-fc-open");
        lastFocused?.focus?.();
      };

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
          closeModal();
          return;
        }
        if (event.key !== "Tab") return;
        const focusable = [...modal.querySelectorAll('button:not(:disabled), input:not(:disabled), [tabindex="0"]')]
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
        const file = photoInput.files?.[0];
        if (!file) return;
        const accepted = ["image/jpeg", "image/png", "image/webp"];
        if (!accepted.includes(file.type) || file.size > (settings.maxUploadBytes || 20 * 1024 * 1024)) {
          photoInput.value = "";
          previewInput.value = "";
          zoomInput.disabled = true;
          showError(text.invalidPhoto || "Choose a supported image within the upload limit.", photoInput);
          return;
        }
        if (state.objectUrl) URL.revokeObjectURL(state.objectUrl);
        state.objectUrl = URL.createObjectURL(file);
        state.sourceName = file.name;
        const image = new Image();
        image.onload = () => {
          state.image = image;
          state.imageWidth = image.naturalWidth;
          state.imageHeight = image.naturalHeight;
          state.centerX = state.imageWidth / 2;
          state.centerY = state.imageHeight / 2;
          state.zoom = 1;
          zoomInput.value = "1";
          zoomInput.disabled = false;
          preparedSignature = "";
          filename.textContent = file.name;
          render();
          canvasWrap.focus();
        };
        image.onerror = () => showError(text.invalidPhoto || "Choose a supported image.", photoInput);
        image.src = state.objectUrl;
      });

      zoomInput.addEventListener("input", () => {
        state.zoom = asNumber(zoomInput.value, 1);
        preparedSignature = "";
        render();
      });

      canvasWrap.addEventListener("pointerdown", (event) => {
        if (!state.image || !state.layout) return;
        const rect = canvasWrap.getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;
        const art = state.layout.artwork;
        if (x < art.x || x > art.x + art.width || y < art.y || y > art.y + art.height) return;
        state.pointerId = event.pointerId;
        state.pointerX = event.clientX;
        state.pointerY = event.clientY;
        state.startCenterX = state.centerX;
        state.startCenterY = state.centerY;
        canvasWrap.setPointerCapture(event.pointerId);
      });

      canvasWrap.addEventListener("pointermove", (event) => {
        if (state.pointerId !== event.pointerId || !state.layout) return;
        const crop = clampCrop(state.layout);
        state.centerX = state.startCenterX - ((event.clientX - state.pointerX) / crop.imageScale);
        state.centerY = state.startCenterY - ((event.clientY - state.pointerY) / crop.imageScale);
        preparedSignature = "";
        render();
      });

      const finishPointer = (event) => {
        if (state.pointerId !== event.pointerId) return;
        state.pointerId = null;
        if (canvasWrap.hasPointerCapture(event.pointerId)) canvasWrap.releasePointerCapture(event.pointerId);
      };
      canvasWrap.addEventListener("pointerup", finishPointer);
      canvasWrap.addEventListener("pointercancel", finishPointer);

      canvasWrap.addEventListener("keydown", (event) => {
        if (!state.image || !state.layout || !["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(event.key)) return;
        event.preventDefault();
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
        const recipe = currentRecipe();
        if (!recipe || !state.image) throw new Error("Missing crop");
        const sourceX = recipe.x * state.imageWidth;
        const sourceY = recipe.y * state.imageHeight;
        const sourceWidth = recipe.width * state.imageWidth;
        const sourceHeight = recipe.height * state.imageHeight;
        const maxPreview = 2400;
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
        const blob = await canvasBlob(outputCanvas);
        const safeName = (nameInput.value.trim() || "custom")
          .toLocaleLowerCase()
          .replace(/[^a-z0-9]+/g, "-")
          .replace(/^-|-$/g, "") || "custom";
        const file = new File([blob], `${safeName}-crop-preview.jpg`, { type: "image/jpeg", lastModified: Date.now() });
        const transfer = new DataTransfer();
        transfer.items.add(file);
        previewInput.files = transfer.files;
        recipeInput.value = JSON.stringify(recipe);
        preparedSignature = JSON.stringify({ recipe, variation: variationId?.value || config?.variationId || config?.productId });
      };

      addButton.addEventListener("click", (event) => {
        clearErrors();
        if (config?.nameRequired && !nameInput.value.trim()) {
          event.preventDefault();
          showError(text.missingName || "Complete the required name field.", nameInput);
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
        if (config?.nameRequired && !nameInput.value.trim()) {
          event.preventDefault();
          showError(text.missingName || "Complete the required name field.", nameInput);
          return;
        }
        if (!state.image || !photoInput.files?.length) {
          event.preventDefault();
          showError(text.missingPhoto || "Choose a photo.", photoInput);
          return;
        }
        const currentSignature = JSON.stringify({ recipe: currentRecipe(), variation: variationId?.value || config?.variationId || config?.productId });
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
        window.jQuery(variationForm).on("found_variation", (event, variation) => {
          if (variation?.tikool_frame_customizer) setConfiguration(variation.tikool_frame_customizer);
        });
        window.jQuery(variationForm).on("reset_data", () => {
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
