(() => {
  const ready = (callback) => {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", callback, { once: true });
    } else {
      callback();
    }
  };

  ready(() => {
    document.querySelectorAll("form.variations_form.cart").forEach((form) => {
      const modal = form.querySelector("[data-tikool-personalization-modal]");
      if (!modal) {
        return;
      }

      const variationsTable = form.querySelector("table.variations");
      const singleVariationWrap = form.querySelector(".single_variation_wrap");
      const variationId = form.querySelector('input[name="variation_id"]');
      const variationSelects = variationsTable ? [...variationsTable.querySelectorAll("select")] : [];
      const purchaseContainer = form.querySelector(".woocommerce-variation-add-to-cart");
      const purchaseSlot = modal.querySelector("[data-tikool-purchase-slot]");
      const quantity = purchaseContainer?.querySelector(".quantity");
      const addButton = purchaseContainer?.querySelector(".single_add_to_cart_button");
      const petName = modal.querySelector('input[name="tikool_pet_name"]');
      const photoInput = modal.querySelector("[data-tikool-photo-input]");
      const photoFilename = modal.querySelector("[data-tikool-photo-filename]");
      const zoomInput = modal.querySelector("[data-tikool-crop-zoom]");
      const cropData = modal.querySelector("[data-tikool-crop-data]");
      const preview = modal.querySelector("[data-tikool-crop-preview]");
      const viewport = modal.querySelector("[data-tikool-crop-viewport]");
      const cropImage = modal.querySelector("[data-tikool-crop-image]");
      const modalError = modal.querySelector("[data-tikool-personalization-error]");
      const closeButtons = [...modal.querySelectorAll("[data-tikool-modal-close]")];
      const required = [variationsTable, singleVariationWrap, variationId, purchaseContainer, purchaseSlot, quantity, addButton, petName, photoInput, zoomInput, cropData, preview, viewport, cropImage, modalError];
      if (required.some((element) => !element)) {
        return;
      }

      form.enctype = "multipart/form-data";
      const nextArea = document.createElement("div");
      nextArea.className = "tikool-personalization-step-one";
      nextArea.innerHTML = `
        <button class="tikool-personalization-next" type="button">Next: upload photo</button>
        <p class="tikool-personalization-step-one__error" role="alert" aria-live="polite"></p>
      `;
      purchaseContainer.insertAdjacentElement("beforebegin", nextArea);
      const nextButton = nextArea.querySelector(".tikool-personalization-next");
      const stepOneError = nextArea.querySelector(".tikool-personalization-step-one__error");

      purchaseSlot.append(quantity, addButton);
      addButton.textContent = "Add to cart";

      const selectByName = (fragment) => variationSelects.find(
        (select) => select.name.toLocaleLowerCase().includes(fragment),
      );
      const sizeSelect = selectByName("size");
      const frameSelect = selectByName("frame");
      const matSelect = selectByName("mat");
      const summarySize = modal.querySelector("[data-tikool-summary-size]");
      const summaryFrame = modal.querySelector("[data-tikool-summary-frame]");
      const summaryMat = modal.querySelector("[data-tikool-summary-mat]");
      const summaryPrice = modal.querySelector("[data-tikool-summary-price]");

      const crop = {
        imageWidth: 0,
        imageHeight: 0,
        centerX: 0,
        centerY: 0,
        zoom: 1,
        scale: 1,
        pointerId: null,
        dragX: 0,
        dragY: 0,
        startCenterX: 0,
        startCenterY: 0,
        objectUrl: "",
        sourceName: "pet-photo",
      };

      let lastFocused = null;
      let finalCropReady = false;
      let preparing = false;

      const selectedText = (select) => select?.selectedOptions?.[0]?.textContent?.trim() || "—";

      const cropRatio = () => {
        const size = selectedText(sizeSelect).toLocaleLowerCase().replaceAll(" ", "");
        const hasMat = !selectedText(matSelect).toLocaleLowerCase().includes("no mat");
        if (hasMat) {
          if (size.includes("8×10") || size.includes("8x10")) return 5 / 7;
          if (size.includes("12×16") || size.includes("12x16")) return 2 / 3;
          if (size.includes("16×20") || size.includes("16x20")) return 3 / 4;
        }
        if (size.includes("12×16") || size.includes("12x16")) return 3 / 4;
        return 4 / 5;
      };

      const frameKind = () => {
        const value = selectedText(frameSelect).toLocaleLowerCase();
        if (value.includes("black")) return "black";
        if (value.includes("walnut")) return "walnut";
        return "natural";
      };

      const hasMat = () => !selectedText(matSelect).toLocaleLowerCase().includes("no mat");

      const variationPrice = () => {
        const variation = form.querySelector(".single_variation .woocommerce-variation-price .price");
        const product = form.closest(".summary")?.querySelector(":scope > .price");
        return variation?.textContent?.trim() || product?.textContent?.trim() || "—";
      };

      const clampCrop = () => {
        if (!crop.imageWidth || !crop.imageHeight) return;
        const rect = viewport.getBoundingClientRect();
        const minimumScale = Math.max(rect.width / crop.imageWidth, rect.height / crop.imageHeight);
        crop.scale = minimumScale * crop.zoom;
        const halfVisibleWidth = rect.width / (2 * crop.scale);
        const halfVisibleHeight = rect.height / (2 * crop.scale);
        crop.centerX = Math.min(
          crop.imageWidth - halfVisibleWidth,
          Math.max(halfVisibleWidth, crop.centerX),
        );
        crop.centerY = Math.min(
          crop.imageHeight - halfVisibleHeight,
          Math.max(halfVisibleHeight, crop.centerY),
        );
      };

      const renderCrop = () => {
        if (!crop.imageWidth || !crop.imageHeight) return;
        clampCrop();
        const rect = viewport.getBoundingClientRect();
        cropImage.style.width = `${crop.imageWidth * crop.scale}px`;
        // Set only one rendered dimension. The browser derives height from the
        // image's intrinsic ratio, so no theme rule or crop shape can stretch it.
        cropImage.style.removeProperty("height");
        cropImage.style.aspectRatio = `${crop.imageWidth} / ${crop.imageHeight}`;
        cropImage.style.left = `${(rect.width / 2) - (crop.centerX * crop.scale)}px`;
        cropImage.style.top = `${(rect.height / 2) - (crop.centerY * crop.scale)}px`;
      };

      const syncSummary = () => {
        summarySize.textContent = selectedText(sizeSelect);
        summaryFrame.textContent = selectedText(frameSelect);
        summaryMat.textContent = selectedText(matSelect);
        summaryPrice.textContent = variationPrice();
        preview.dataset.frame = frameKind();
        preview.dataset.mat = hasMat() ? "mat" : "no-mat";
        viewport.style.setProperty("--tikool-crop-ratio", String(cropRatio()));
        window.requestAnimationFrame(renderCrop);
      };

      const showError = (message, target = null) => {
        modalError.textContent = message;
        if (target) target.focus();
      };

      const clearErrors = () => {
        modalError.textContent = "";
        stepOneError.textContent = "";
      };

      const openModal = () => {
        clearErrors();
        syncSummary();
        lastFocused = document.activeElement;
        modal.hidden = false;
        document.body.classList.add("tikool-personalization-open");
        window.requestAnimationFrame(() => {
          renderCrop();
          modal.querySelector(".tikool-personalization-modal__close")?.focus();
        });
      };

      const closeModal = () => {
        modal.hidden = true;
        document.body.classList.remove("tikool-personalization-open");
        lastFocused?.focus?.();
      };

      nextButton.addEventListener("click", () => {
        if (!variationId.value || variationSelects.some((select) => !select.value)) {
          stepOneError.textContent = modal.dataset.missingVariation;
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

      photoInput.addEventListener("change", () => {
        clearErrors();
        const file = photoInput.files?.[0];
        if (!file) return;
        const accepted = ["image/jpeg", "image/png", "image/webp"];
        if (!accepted.includes(file.type) || file.size > 10 * 1024 * 1024) {
          photoInput.value = "";
          preview.classList.remove("has-photo");
          zoomInput.disabled = true;
          showError(modal.dataset.invalidPhoto, photoInput);
          return;
        }

        if (crop.objectUrl) URL.revokeObjectURL(crop.objectUrl);
        crop.objectUrl = URL.createObjectURL(file);
        crop.sourceName = file.name;
        cropImage.onload = () => {
          crop.imageWidth = cropImage.naturalWidth;
          crop.imageHeight = cropImage.naturalHeight;
          crop.centerX = crop.imageWidth / 2;
          crop.centerY = crop.imageHeight / 2;
          crop.zoom = 1;
          zoomInput.value = "1";
          zoomInput.disabled = false;
          preview.classList.add("has-photo");
          finalCropReady = false;
          window.requestAnimationFrame(() => {
            renderCrop();
            viewport.focus();
          });
        };
        cropImage.onerror = () => {
          preview.classList.remove("has-photo");
          zoomInput.disabled = true;
          showError(modal.dataset.invalidPhoto, photoInput);
        };
        cropImage.src = crop.objectUrl;
        photoFilename.textContent = file.name;
      });

      zoomInput.addEventListener("input", () => {
        crop.zoom = Number.parseFloat(zoomInput.value || "1");
        finalCropReady = false;
        renderCrop();
      });

      viewport.addEventListener("pointerdown", (event) => {
        if (!crop.imageWidth) return;
        crop.pointerId = event.pointerId;
        crop.dragX = event.clientX;
        crop.dragY = event.clientY;
        crop.startCenterX = crop.centerX;
        crop.startCenterY = crop.centerY;
        viewport.setPointerCapture(event.pointerId);
      });

      viewport.addEventListener("pointermove", (event) => {
        if (crop.pointerId !== event.pointerId) return;
        crop.centerX = crop.startCenterX - ((event.clientX - crop.dragX) / crop.scale);
        crop.centerY = crop.startCenterY - ((event.clientY - crop.dragY) / crop.scale);
        finalCropReady = false;
        renderCrop();
      });

      const endDrag = (event) => {
        if (crop.pointerId !== event.pointerId) return;
        crop.pointerId = null;
        if (viewport.hasPointerCapture(event.pointerId)) viewport.releasePointerCapture(event.pointerId);
      };
      viewport.addEventListener("pointerup", endDrag);
      viewport.addEventListener("pointercancel", endDrag);

      viewport.addEventListener("keydown", (event) => {
        if (!crop.imageWidth || !["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(event.key)) return;
        event.preventDefault();
        const movement = event.shiftKey ? 30 : 10;
        if (event.key === "ArrowLeft") crop.centerX -= movement / crop.scale;
        if (event.key === "ArrowRight") crop.centerX += movement / crop.scale;
        if (event.key === "ArrowUp") crop.centerY -= movement / crop.scale;
        if (event.key === "ArrowDown") crop.centerY += movement / crop.scale;
        finalCropReady = false;
        renderCrop();
      });

      const canvasBlob = (canvas) => new Promise((resolve, reject) => {
        canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error("Canvas export failed"))), "image/jpeg", 0.93);
      });

      const prepareFinalCrop = async () => {
        clampCrop();
        const rect = viewport.getBoundingClientRect();
        const sourceWidth = rect.width / crop.scale;
        const sourceHeight = rect.height / crop.scale;
        const sourceX = crop.centerX - (sourceWidth / 2);
        const sourceY = crop.centerY - (sourceHeight / 2);
        const outputScale = Math.min(1, 3000 / sourceWidth, 3000 / sourceHeight);
        const outputWidth = Math.max(1, Math.round(sourceWidth * outputScale));
        const outputHeight = Math.max(1, Math.round(sourceHeight * outputScale));
        const canvas = document.createElement("canvas");
        canvas.width = outputWidth;
        canvas.height = outputHeight;
        const context = canvas.getContext("2d", { alpha: false });
        context.fillStyle = "#ffffff";
        context.fillRect(0, 0, outputWidth, outputHeight);
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = "high";
        context.drawImage(
          cropImage,
          sourceX,
          sourceY,
          sourceWidth,
          sourceHeight,
          0,
          0,
          outputWidth,
          outputHeight,
        );
        const blob = await canvasBlob(canvas);
        const safeName = (petName.value.trim() || "pet").toLocaleLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || "pet";
        const file = new File([blob], `${safeName}-final-crop.jpg`, { type: "image/jpeg", lastModified: Date.now() });
        const transfer = new DataTransfer();
        transfer.items.add(file);
        photoInput.files = transfer.files;
        cropData.value = JSON.stringify({
          source: crop.sourceName,
          width: outputWidth,
          height: outputHeight,
          size: selectedText(sizeSelect),
          frame: selectedText(frameSelect),
          mat: selectedText(matSelect),
        });
        finalCropReady = true;
      };

      addButton.addEventListener("click", (event) => {
        clearErrors();
        if (!petName.value.trim()) {
          event.preventDefault();
          showError(modal.dataset.missingName, petName);
        } else if (!crop.imageWidth || !photoInput.files?.length) {
          event.preventDefault();
          showError(modal.dataset.missingPhoto, photoInput);
        }
      });

      form.addEventListener("submit", async (event) => {
        if (finalCropReady || preparing) return;
        event.preventDefault();
        if (!petName.value.trim()) {
          showError(modal.dataset.missingName, petName);
          return;
        }
        if (!crop.imageWidth || !photoInput.files?.length) {
          showError(modal.dataset.missingPhoto, photoInput);
          return;
        }

        preparing = true;
        modal.classList.add("is-preparing");
        addButton.disabled = true;
        const originalLabel = addButton.textContent;
        addButton.textContent = "Preparing photo…";
        try {
          await prepareFinalCrop();
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
          showError(modal.dataset.cropError, photoInput);
        }
      });

      variationSelects.forEach((select) => select.addEventListener("change", () => {
        finalCropReady = false;
        window.requestAnimationFrame(syncSummary);
      }));
      if (window.jQuery) {
        window.jQuery(form).on("found_variation reset_data woocommerce_variation_has_changed", syncSummary);
      }
      window.addEventListener("resize", () => window.requestAnimationFrame(renderCrop), { passive: true });
      syncSummary();
    });
  });
})();
