(() => {
  const form = document.querySelector("[data-memory-page-form]");
  if (!form) return;

  const list = form.querySelector("[data-timeline-list]");
  const template = form.querySelector("[data-timeline-template]");
  const addButton = form.querySelector("[data-add-timeline]");
  const copyButton = document.querySelector("[data-copy-memory-url]");
  const memoryUrl = document.querySelector("[data-memory-url]");
  const limits = {
    photos: 2 * 1024 * 1024,
    videos: 90 * 1024 * 1024,
    audio: 20 * 1024 * 1024,
  };

  const message = (key, fallback) => window.tikoolMemoryPage?.[key] || fallback;

  copyButton?.addEventListener("click", async () => {
    const url = memoryUrl?.textContent?.trim();
    if (!url) return;
    try {
      await navigator.clipboard.writeText(url);
      copyButton.textContent = message("copiedLabel", "Copied");
      copyButton.classList.add("is-copied");
      window.setTimeout(() => {
        copyButton.textContent = message("copyLabel", "Copy link");
        copyButton.classList.remove("is-copied");
      }, 1800);
    } catch (error) {
      window.prompt(message("copyLabel", "Copy link"), url);
    }
  });

  const readDuration = (file) => new Promise((resolve, reject) => {
    const element = document.createElement(file.type.startsWith("audio/") ? "audio" : "video");
    const url = URL.createObjectURL(file);
    const finish = (callback, value) => {
      URL.revokeObjectURL(url);
      element.removeAttribute("src");
      callback(value);
    };
    element.preload = "metadata";
    element.addEventListener("loadedmetadata", () => finish(resolve, element.duration), { once: true });
    element.addEventListener("error", () => finish(reject, new Error("metadata")), { once: true });
    element.src = url;
  });

  const validateFiles = async (input) => {
    const kind = input.dataset.mediaKind;
    const output = form.querySelector(`[data-media-validation="${kind}"]`);
    const files = Array.from(input.files || []);
    const limitedGroup = input.closest("[data-limited-media-group]");
    if (limitedGroup) {
      const max = Number.parseInt(limitedGroup.dataset.maxItems || "0", 10);
      const activeExisting = Array.from(limitedGroup.querySelectorAll("[data-existing-media-card]"))
        .filter((card) => !card.querySelector("[data-remove-existing-media]")?.checked).length;
      const count = activeExisting + files.length;
      const countOutput = limitedGroup.querySelector("[data-media-count]");
      const addControl = limitedGroup.querySelector("[data-media-add-control]");
      const suffixKey = kind === "videos" ? "videoCountSuffix" : "audioCountSuffix";
      if (countOutput) countOutput.textContent = `${count} / ${max} ${message(suffixKey, kind)}`;
      if (addControl) addControl.hidden = count >= max;
      if (count > max) {
        const limitKey = kind === "videos" ? "videoLimitError" : "audioLimitError";
        const error = message(limitKey, `You can add up to ${max} files.`);
        input.setCustomValidity(error);
        if (output) output.textContent = error;
        return;
      }
    }
    const sizeErrorKey = kind === "photos" ? "photoSizeError" : kind === "videos" ? "videoSizeError" : "audioSizeError";
    const tooLarge = files.some((file) => file.size > limits[kind]);
    if (tooLarge) {
      const error = message(sizeErrorKey, "The selected file is too large.");
      input.setCustomValidity(error);
      if (output) output.textContent = error;
      return;
    }

    if (kind !== "audio" || files.length === 0) {
      input.setCustomValidity("");
      if (output) output.textContent = "";
      return;
    }

    input.setCustomValidity(message("checkingLabel", "Checking media…"));
    if (output) output.textContent = message("checkingLabel", "Checking media…");
    try {
      const durations = await Promise.all(files.map(readDuration));
      if (durations.some((duration) => !Number.isFinite(duration))) throw new Error("metadata");
      if (durations.some((duration) => duration > 60.05)) {
        const error = message("audioDurationError", "Each audio file must be no longer than 1 minute.");
        input.setCustomValidity(error);
        if (output) output.textContent = error;
        return;
      }
      input.setCustomValidity("");
      if (output) output.textContent = "";
    } catch (error) {
      const text = message("metadataError", "This media file could not be read.");
      input.setCustomValidity(text);
      if (output) output.textContent = text;
    }
  };

  const setInputFiles = (input, files) => {
    if (typeof DataTransfer === "undefined") return false;
    const transfer = new DataTransfer();
    files.forEach((file) => transfer.items.add(file));
    input.files = transfer.files;
    return true;
  };

  const createDragHandle = () => {
    const handle = document.createElement("button");
    handle.type = "button";
    handle.className = "tikool-memory-photo-card__drag";
    handle.dataset.mediaDragHandle = "";
    handle.draggable = true;
    handle.setAttribute("aria-label", message("dragLabel", "Drag to reorder. You can also use the arrow keys."));
    handle.textContent = "⠿";
    return handle;
  };

  const initMediaOrdering = (manager, grid, addCard, kind, selected) => {
    const orderInputs = manager.querySelector("[data-media-order-inputs]");
    let draggedCard = null;
    let pointerCard = null;
    let pointerHandle = null;

    const cards = () => Array.from(grid.querySelectorAll("[data-sortable-media-card]"));
    const sync = () => {
      if (!orderInputs) return;
      orderInputs.replaceChildren();
      cards().filter((card) => !card.classList.contains("is-pending-removal")).forEach((card) => {
        let token = "";
        if (card.dataset.mediaExisting) {
          token = `existing:${card.dataset.mediaExisting}`;
        } else if (card.dataset.mediaNewId) {
          const id = Number.parseInt(card.dataset.mediaNewId, 10);
          const index = selected.findIndex((item) => item.id === id);
          if (index >= 0) token = `new:${index}`;
        }
        if (!token) return;
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = `memory[media_order][${kind}][]`;
        input.value = token;
        orderInputs.append(input);
      });
    };
    const positionCard = (card, target, clientX, clientY) => {
      if (!card || !target || card === target || !grid.contains(target)) return;
      const rect = target.getBoundingClientRect();
      const sameRow = clientY >= rect.top && clientY <= rect.bottom;
      const after = sameRow ? clientX > rect.left + rect.width / 2 : clientY > rect.top + rect.height / 2;
      grid.insertBefore(card, after ? target.nextSibling : target);
    };
    const finishPointerSort = () => {
      if (pointerCard) pointerCard.classList.remove("is-dragging");
      if (pointerHandle?.hasPointerCapture?.(pointerHandle.dataset.activePointerId)) {
        pointerHandle.releasePointerCapture(pointerHandle.dataset.activePointerId);
      }
      pointerCard = null;
      pointerHandle = null;
      sync();
    };
    const bindCard = (card) => {
      if (card.dataset.orderingBound) return;
      card.dataset.orderingBound = "true";
      const handle = card.querySelector("[data-media-drag-handle]");
      if (!handle) return;
      handle.draggable = true;

      handle.addEventListener("dragstart", (event) => {
        draggedCard = card;
        card.classList.add("is-dragging");
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = "move";
          event.dataTransfer.setData("text/plain", kind);
        }
      });
      handle.addEventListener("dragend", () => {
        card.classList.remove("is-dragging");
        draggedCard = null;
        sync();
      });
      card.addEventListener("dragover", (event) => {
        if (!draggedCard) return;
        event.preventDefault();
        positionCard(draggedCard, card, event.clientX, event.clientY);
      });
      card.addEventListener("drop", (event) => {
        if (!draggedCard) return;
        event.preventDefault();
        positionCard(draggedCard, card, event.clientX, event.clientY);
        sync();
      });
      handle.addEventListener("keydown", (event) => {
        const previous = event.key === "ArrowLeft" || event.key === "ArrowUp";
        const next = event.key === "ArrowRight" || event.key === "ArrowDown";
        if (!previous && !next) return;
        event.preventDefault();
        const orderedCards = cards();
        const index = orderedCards.indexOf(card);
        const target = orderedCards[index + (previous ? -1 : 1)];
        if (!target) return;
        grid.insertBefore(card, previous ? target : target.nextSibling);
        sync();
        handle.focus();
      });
      handle.addEventListener("pointerdown", (event) => {
        if (event.pointerType === "mouse") return;
        pointerCard = card;
        pointerHandle = handle;
        handle.dataset.activePointerId = String(event.pointerId);
        handle.setPointerCapture(event.pointerId);
        card.classList.add("is-dragging");
        event.preventDefault();
      });
      handle.addEventListener("pointermove", (event) => {
        if (pointerCard !== card) return;
        const target = document.elementFromPoint(event.clientX, event.clientY)?.closest("[data-sortable-media-card]");
        positionCard(card, target, event.clientX, event.clientY);
      });
      handle.addEventListener("pointerup", finishPointerSort);
      handle.addEventListener("pointercancel", finishPointerSort);
    };
    const refresh = () => {
      cards().forEach(bindCard);
      sync();
      if (addCard !== grid.lastElementChild) grid.append(addCard);
    };
    return { refresh, sync };
  };

  const initPhotoManager = (manager) => {
    const input = manager.querySelector('input[type="file"][data-media-kind="photos"]');
    const grid = manager.querySelector("[data-photo-grid]");
    const addCard = manager.querySelector("[data-photo-add-card]");
    const countOutput = manager.querySelector("[data-photo-count]");
    const validation = manager.querySelector('[data-media-validation="photos"]');
    const existingCards = Array.from(manager.querySelectorAll("[data-existing-photo-card]"));
    const max = Number.parseInt(manager.dataset.maxPhotos || "0", 10);
    const selected = [];
    let nextId = 1;
    let fileListSupported = true;
    let ordering = null;
    if (!input || !grid || !addCard || max < 1) return;

    const activeExistingCount = () => existingCards.filter((card) => !card.querySelector("[data-remove-existing-photo]")?.checked).length;
    const currentCount = () => activeExistingCount() + selected.length;
    const showMessage = (text = "") => {
      if (validation) validation.textContent = text;
    };
    const syncInput = () => {
      if (!setInputFiles(input, selected.map((item) => item.file))) {
        fileListSupported = false;
        const error = message("metadataError", "Your browser could not prepare these files. Please select them again.");
        input.setCustomValidity(error);
        if (validation) validation.textContent = error;
      } else {
        fileListSupported = true;
        input.setCustomValidity("");
      }
    };
    const syncState = () => {
      const count = currentCount();
      if (countOutput) countOutput.textContent = `${count} / ${max} ${message("photoCountSuffix", "photos")}`;
      addCard.hidden = count >= max;
      if (count > max) {
        const error = message("photoLimitError", `A Memory Page can contain up to ${max} photos.`);
        input.setCustomValidity(error);
        if (validation) validation.textContent = error;
      } else if (fileListSupported) {
        input.setCustomValidity("");
      }
      ordering?.sync();
    };
    const removeNewPhoto = (id) => {
      const index = selected.findIndex((item) => item.id === id);
      if (index < 0) return;
      URL.revokeObjectURL(selected[index].url);
      selected.splice(index, 1);
      grid.querySelector(`[data-new-photo-id="${id}"]`)?.remove();
      syncInput();
      showMessage();
      syncState();
    };
    const addPreviewCard = (item) => {
      const card = document.createElement("article");
      card.className = "tikool-memory-photo-card tikool-memory-photo-card--new";
      card.dataset.newPhotoId = String(item.id);
      card.dataset.sortableMediaCard = "";
      card.dataset.mediaNewId = String(item.id);

      const preview = document.createElement("div");
      preview.className = "tikool-memory-photo-card__preview";
      const image = document.createElement("img");
      image.src = item.url;
      image.alt = item.file.name;
      preview.append(image);
      preview.append(createDragHandle());

      const badge = document.createElement("span");
      badge.className = "tikool-memory-photo-card__new-badge";
      badge.textContent = message("newPhotoLabel", "New");
      preview.append(badge);

      const footer = document.createElement("div");
      footer.className = "tikool-memory-photo-card__footer";
      const name = document.createElement("span");
      name.className = "tikool-memory-photo-card__name";
      name.textContent = item.file.name;
      name.title = item.file.name;
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "tikool-memory-photo-card__remove";
      remove.textContent = message("removeLabel", "Remove");
      remove.addEventListener("click", () => removeNewPhoto(item.id));
      footer.append(name, remove);
      card.append(preview, footer);
      grid.insertBefore(card, addCard);
      ordering?.refresh();
    };

    existingCards.forEach((card) => {
      const checkbox = card.querySelector("[data-remove-existing-photo]");
      const button = card.querySelector("[data-toggle-existing-photo]");
      if (!checkbox || !button) return;
      button.addEventListener("click", () => {
        if (checkbox.checked && currentCount() >= max) {
          showMessage(message("photoLimitError", `A Memory Page can contain up to ${max} photos.`));
          return;
        }
        checkbox.checked = !checkbox.checked;
        card.classList.toggle("is-pending-removal", checkbox.checked);
        button.textContent = checkbox.checked
          ? message("undoRemoveLabel", "Undo remove")
          : message("removeLabel", "Remove");
        showMessage();
        syncState();
      });
    });

    input.addEventListener("change", () => {
      const picked = Array.from(input.files || []);
      const available = Math.max(0, max - currentCount());
      const known = new Set(selected.map((item) => `${item.file.name}|${item.file.size}|${item.file.lastModified}`));
      const valid = picked.filter((file) => file.size <= limits.photos && !known.has(`${file.name}|${file.size}|${file.lastModified}`));
      const accepted = valid.slice(0, available);
      accepted.forEach((file) => {
        const item = { id: nextId++, file, url: URL.createObjectURL(file) };
        selected.push(item);
        addPreviewCard(item);
      });
      syncInput();
      if (picked.some((file) => file.size > limits.photos)) {
        showMessage(message("photoSizeError", "Each photo must be 2 MB or smaller."));
      } else if (valid.length > available) {
        showMessage(message("photoLimitError", `A Memory Page can contain up to ${max} photos.`));
      } else {
        showMessage();
      }
      syncState();
    });

    ordering = initMediaOrdering(manager, grid, addCard, "photos", selected);
    ordering.refresh();
    window.addEventListener("pagehide", () => selected.forEach((item) => URL.revokeObjectURL(item.url)), { once: true });
    syncState();
  };

  const initVideoManager = (manager) => {
    const input = manager.querySelector('input[type="file"][data-media-kind="videos"]');
    const grid = manager.querySelector("[data-video-grid]");
    const addCard = manager.querySelector("[data-video-add-card]");
    const countOutput = manager.querySelector("[data-video-count]");
    const validation = manager.querySelector('[data-media-validation="videos"]');
    const existingCards = Array.from(manager.querySelectorAll("[data-existing-video-card]"));
    const max = Number.parseInt(manager.dataset.maxVideos || "0", 10);
    const selected = [];
    let nextId = 1;
    let fileListSupported = true;
    let ordering = null;
    if (!input || !grid || !addCard || max < 1) return;

    const activeExistingCount = () => existingCards.filter((card) => !card.querySelector("[data-remove-existing-video]")?.checked).length;
    const currentCount = () => activeExistingCount() + selected.length;
    const showMessage = (text = "") => {
      if (validation) validation.textContent = text;
    };
    const syncInput = () => {
      if (!setInputFiles(input, selected.map((item) => item.file))) {
        fileListSupported = false;
        const error = message("metadataError", "Your browser could not prepare these files. Please select them again.");
        input.setCustomValidity(error);
        if (validation) validation.textContent = error;
      } else {
        fileListSupported = true;
        input.setCustomValidity("");
      }
    };
    const syncState = () => {
      const count = currentCount();
      if (countOutput) countOutput.textContent = `${count} / ${max} ${message("videoCountSuffix", "videos")}`;
      addCard.hidden = count >= max;
      if (count > max) {
        const error = message("videoLimitError", `A Memory Page can contain up to ${max} videos.`);
        input.setCustomValidity(error);
        if (validation) validation.textContent = error;
      } else if (fileListSupported) {
        input.setCustomValidity("");
      }
      ordering?.sync();
    };
    const removeNewVideo = (id) => {
      const index = selected.findIndex((item) => item.id === id);
      if (index < 0) return;
      grid.querySelector(`[data-new-video-id="${id}"] video`)?.pause();
      URL.revokeObjectURL(selected[index].url);
      selected.splice(index, 1);
      grid.querySelector(`[data-new-video-id="${id}"]`)?.remove();
      syncInput();
      showMessage();
      syncState();
    };
    const addPreviewCard = (item) => {
      const card = document.createElement("article");
      card.className = "tikool-memory-photo-card tikool-memory-video-card tikool-memory-video-card--new";
      card.dataset.newVideoId = String(item.id);
      card.dataset.sortableMediaCard = "";
      card.dataset.mediaNewId = String(item.id);

      const preview = document.createElement("div");
      preview.className = "tikool-memory-photo-card__preview tikool-memory-video-card__preview";
      const video = document.createElement("video");
      video.src = item.url;
      video.controls = true;
      video.preload = "metadata";
      preview.append(video);
      preview.append(createDragHandle());

      const badge = document.createElement("span");
      badge.className = "tikool-memory-photo-card__new-badge";
      badge.textContent = message("newVideoLabel", "New");
      preview.append(badge);

      const footer = document.createElement("div");
      footer.className = "tikool-memory-photo-card__footer";
      const name = document.createElement("span");
      name.className = "tikool-memory-photo-card__name";
      name.textContent = item.file.name;
      name.title = item.file.name;
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "tikool-memory-photo-card__remove";
      remove.textContent = message("removeLabel", "Remove");
      remove.addEventListener("click", () => removeNewVideo(item.id));
      footer.append(name, remove);
      card.append(preview, footer);
      grid.insertBefore(card, addCard);
      ordering?.refresh();
    };

    existingCards.forEach((card) => {
      const checkbox = card.querySelector("[data-remove-existing-video]");
      const button = card.querySelector("[data-toggle-existing-video]");
      if (!checkbox || !button) return;
      button.addEventListener("click", () => {
        if (checkbox.checked && currentCount() >= max) {
          showMessage(message("videoLimitError", `A Memory Page can contain up to ${max} videos.`));
          return;
        }
        checkbox.checked = !checkbox.checked;
        card.classList.toggle("is-pending-removal", checkbox.checked);
        if (checkbox.checked) card.querySelector("video")?.pause();
        button.textContent = checkbox.checked
          ? message("undoRemoveLabel", "Undo remove")
          : message("removeLabel", "Remove");
        showMessage();
        syncState();
      });
    });

    input.addEventListener("change", () => {
      const picked = Array.from(input.files || []);
      const available = Math.max(0, max - currentCount());
      const known = new Set(selected.map((item) => `${item.file.name}|${item.file.size}|${item.file.lastModified}`));
      const valid = picked.filter((file) => file.size <= limits.videos && !known.has(`${file.name}|${file.size}|${file.lastModified}`));
      const accepted = valid.slice(0, available);
      accepted.forEach((file) => {
        const item = { id: nextId++, file, url: URL.createObjectURL(file) };
        selected.push(item);
        addPreviewCard(item);
      });
      syncInput();
      if (picked.some((file) => file.size > limits.videos)) {
        showMessage(message("videoSizeError", "Each source video must be 90 MB or smaller."));
      } else if (valid.length > available) {
        showMessage(message("videoLimitError", `A Memory Page can contain up to ${max} videos.`));
      } else {
        showMessage();
      }
      syncState();
    });

    ordering = initMediaOrdering(manager, grid, addCard, "videos", selected);
    ordering.refresh();
    window.addEventListener("pagehide", () => selected.forEach((item) => URL.revokeObjectURL(item.url)), { once: true });
    syncState();
  };

  const initAudioManager = (manager) => {
    const input = manager.querySelector('input[type="file"][data-media-kind="audio"]');
    const grid = manager.querySelector("[data-audio-grid]");
    const addCard = manager.querySelector("[data-audio-add-card]");
    const countOutput = manager.querySelector("[data-audio-count]");
    const validation = manager.querySelector('[data-media-validation="audio"]');
    const existingCards = Array.from(manager.querySelectorAll("[data-existing-audio-card]"));
    const max = Number.parseInt(manager.dataset.maxAudio || "0", 10);
    const selected = [];
    let nextId = 1;
    let selectionVersion = 0;
    let fileListSupported = true;
    let ordering = null;
    if (!input || !grid || !addCard || max < 1) return;

    const activeExistingCount = () => existingCards.filter((card) => !card.querySelector("[data-remove-existing-audio]")?.checked).length;
    const currentCount = () => activeExistingCount() + selected.length;
    const showMessage = (text = "") => {
      if (validation) validation.textContent = text;
    };
    const syncInput = () => {
      if (!setInputFiles(input, selected.map((item) => item.file))) {
        fileListSupported = false;
        const error = message("metadataError", "Your browser could not prepare these files. Please select them again.");
        input.setCustomValidity(error);
        showMessage(error);
      } else {
        fileListSupported = true;
        input.setCustomValidity("");
      }
    };
    const syncState = () => {
      const count = currentCount();
      if (countOutput) countOutput.textContent = `${count} / ${max} ${message("audioCountSuffix", "audio clips")}`;
      addCard.hidden = count >= max;
      if (count > max) {
        const error = message("audioLimitError", `A Memory Page can contain up to ${max} audio clips.`);
        input.setCustomValidity(error);
        showMessage(error);
      } else if (fileListSupported) {
        input.setCustomValidity("");
      }
      ordering?.sync();
    };
    const removeNewAudio = (id) => {
      const index = selected.findIndex((item) => item.id === id);
      if (index < 0) return;
      grid.querySelector(`[data-new-audio-id="${id}"] audio`)?.pause();
      URL.revokeObjectURL(selected[index].url);
      selected.splice(index, 1);
      grid.querySelector(`[data-new-audio-id="${id}"]`)?.remove();
      syncInput();
      showMessage();
      syncState();
    };
    const addPreviewCard = (item) => {
      const card = document.createElement("article");
      card.className = "tikool-memory-photo-card tikool-memory-audio-card tikool-memory-audio-card--new";
      card.dataset.newAudioId = String(item.id);
      card.dataset.sortableMediaCard = "";
      card.dataset.mediaNewId = String(item.id);

      const preview = document.createElement("div");
      preview.className = "tikool-memory-photo-card__preview tikool-memory-audio-card__preview";
      const art = document.createElement("span");
      art.className = "tikool-memory-audio-card__art";
      art.setAttribute("aria-hidden", "true");
      art.textContent = "♪";
      const audio = document.createElement("audio");
      audio.src = item.url;
      audio.controls = true;
      audio.preload = "metadata";
      preview.append(art, audio);
      preview.append(createDragHandle());

      const badge = document.createElement("span");
      badge.className = "tikool-memory-photo-card__new-badge";
      badge.textContent = message("newAudioLabel", "New");
      preview.append(badge);

      const footer = document.createElement("div");
      footer.className = "tikool-memory-photo-card__footer";
      const name = document.createElement("span");
      name.className = "tikool-memory-photo-card__name";
      name.textContent = item.file.name;
      name.title = item.file.name;
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "tikool-memory-photo-card__remove";
      remove.textContent = message("removeLabel", "Remove");
      remove.addEventListener("click", () => removeNewAudio(item.id));
      footer.append(name, remove);
      card.append(preview, footer);
      grid.insertBefore(card, addCard);
      ordering?.refresh();
    };

    existingCards.forEach((card) => {
      const checkbox = card.querySelector("[data-remove-existing-audio]");
      const button = card.querySelector("[data-toggle-existing-audio]");
      if (!checkbox || !button) return;
      button.addEventListener("click", () => {
        if (checkbox.checked && currentCount() >= max) {
          showMessage(message("audioLimitError", `A Memory Page can contain up to ${max} audio clips.`));
          return;
        }
        checkbox.checked = !checkbox.checked;
        card.classList.toggle("is-pending-removal", checkbox.checked);
        if (checkbox.checked) card.querySelector("audio")?.pause();
        button.textContent = checkbox.checked
          ? message("undoRemoveLabel", "Undo remove")
          : message("removeLabel", "Remove");
        showMessage();
        syncState();
      });
    });

    input.addEventListener("change", async () => {
      const version = ++selectionVersion;
      const picked = Array.from(input.files || []);
      const available = Math.max(0, max - currentCount());
      const known = new Set(selected.map((item) => `${item.file.name}|${item.file.size}|${item.file.lastModified}`));
      const unique = picked.filter((file) => !known.has(`${file.name}|${file.size}|${file.lastModified}`));
      const sizeValid = unique.filter((file) => file.size <= limits.audio);
      showMessage(sizeValid.length > 0 ? message("checkingLabel", "Checking media…") : "");
      input.setCustomValidity(sizeValid.length > 0 ? message("checkingLabel", "Checking media…") : "");

      const checked = await Promise.all(sizeValid.map(async (file) => {
        try {
          return { file, duration: await readDuration(file), readable: true };
        } catch (error) {
          return { file, duration: 0, readable: false };
        }
      }));
      if (version !== selectionVersion) return;

      const valid = checked.filter((item) => item.readable && Number.isFinite(item.duration) && item.duration <= 60.05);
      const accepted = valid.slice(0, available);
      accepted.forEach(({ file }) => {
        const item = { id: nextId++, file, url: URL.createObjectURL(file) };
        selected.push(item);
        addPreviewCard(item);
      });
      syncInput();

      if (picked.some((file) => file.size > limits.audio)) {
        showMessage(message("audioSizeError", "Each audio file must be 20 MB or smaller."));
      } else if (checked.some((item) => item.readable && item.duration > 60.05)) {
        showMessage(message("audioDurationError", "Each audio file must be no longer than 1 minute."));
      } else if (checked.some((item) => !item.readable || !Number.isFinite(item.duration))) {
        showMessage(message("metadataError", "This media file could not be read. Please choose another file."));
      } else if (valid.length > available) {
        showMessage(message("audioLimitError", `A Memory Page can contain up to ${max} audio clips.`));
      } else {
        showMessage();
      }
      syncState();
    });

    ordering = initMediaOrdering(manager, grid, addCard, "audio", selected);
    ordering.refresh();
    window.addEventListener("pagehide", () => selected.forEach((item) => URL.revokeObjectURL(item.url)), { once: true });
    syncState();
  };

  const initLimitedMediaGroup = (group) => {
    const input = group.querySelector("[data-media-kind]");
    if (!input) return;
    const max = Number.parseInt(group.dataset.maxItems || "0", 10);
    const validation = group.querySelector(`[data-media-validation="${input.dataset.mediaKind}"]`);
    const limitKey = input.dataset.mediaKind === "videos" ? "videoLimitError" : "audioLimitError";
    const activeExistingCount = () => Array.from(group.querySelectorAll("[data-existing-media-card]"))
      .filter((card) => !card.querySelector("[data-remove-existing-media]")?.checked).length;
    group.querySelectorAll("[data-remove-existing-media]").forEach((checkbox) => {
      checkbox.addEventListener("change", () => {
        if (!checkbox.checked && activeExistingCount() + (input.files?.length || 0) > max) {
          checkbox.checked = true;
          if (validation) validation.textContent = message(limitKey, `You can add up to ${max} files.`);
          return;
        }
        validateFiles(input);
      });
    });
    input.addEventListener("change", async () => {
      const available = Math.max(0, max - activeExistingCount());
      const files = Array.from(input.files || []);
      const trimmed = files.length > available;
      if (trimmed) setInputFiles(input, files.slice(0, available));
      await validateFiles(input);
      if (trimmed && validation) validation.textContent = message(limitKey, `You can add up to ${max} files.`);
    });
    validateFiles(input);
  };

  const bindRemove = (scope) => {
    scope.querySelectorAll("[data-remove-timeline]").forEach((button) => {
      if (button.dataset.bound) return;
      button.dataset.bound = "true";
      button.addEventListener("click", () => button.closest(".tikool-memory-timeline-row")?.remove());
    });
  };

  addButton?.addEventListener("click", () => {
    if (!list || !template) return;
    const index = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
    const wrapper = document.createElement("div");
    wrapper.innerHTML = template.innerHTML.replaceAll("__INDEX__", index).trim();
    const row = wrapper.firstElementChild;
    if (row) {
      list.append(row);
      bindRemove(row);
      row.querySelector("input")?.focus();
    }
  });

  bindRemove(form);
  form.querySelectorAll("[data-photo-manager]").forEach(initPhotoManager);
  form.querySelectorAll("[data-video-manager]").forEach(initVideoManager);
  form.querySelectorAll("[data-audio-manager]").forEach(initAudioManager);
  form.querySelectorAll("[data-limited-media-group]").forEach(initLimitedMediaGroup);

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }
    const button = form.querySelector("[data-save-button]");
    const panel = form.querySelector("[data-upload-progress]");
    const bar = form.querySelector("[data-progress-bar]");
    const label = form.querySelector("[data-progress-label]");
    const value = form.querySelector("[data-progress-value]");
    const setProgress = (percent, text) => {
      const rounded = Math.max(0, Math.min(100, Math.round(percent)));
      if (bar) bar.value = rounded;
      if (label) label.textContent = text;
      if (value) value.textContent = `${rounded}%`;
    };
    const fail = (text) => {
      if (panel) panel.classList.add("is-error");
      setProgress(0, text);
      if (button) {
        button.disabled = false;
        button.textContent = "Save & publish";
      }
    };

    if (button) {
      button.disabled = true;
      button.textContent = "Saving & publishing…";
    }
    if (panel) {
      panel.hidden = false;
      panel.classList.remove("is-error");
    }
    setProgress(2, message("uploadingLabel", "Uploading your media…"));

    const request = new XMLHttpRequest();
    // Read the attribute directly: the hidden WooCommerce field named
    // "action" can DOM-clobber the corresponding form property.
    const endpoint = form.getAttribute("action") || window.location.href;
    const method = (form.getAttribute("method") || "POST").toUpperCase();
    request.open(method, endpoint, true);
    request.setRequestHeader("X-Requested-With", "XMLHttpRequest");
    request.upload.addEventListener("progress", (progress) => {
      if (progress.lengthComputable) {
        setProgress(2 + (progress.loaded / progress.total) * 88, message("uploadingLabel", "Uploading your media…"));
      }
    });
    request.upload.addEventListener("load", () => {
      setProgress(94, message("preparingLabel", "Upload complete. Preparing your Memory Page for publishing…"));
    });
    request.addEventListener("load", () => {
      if (request.status < 200 || request.status >= 400) {
        fail(message("requestError", "The upload could not be completed. Please try again."));
        return;
      }
      setProgress(100, message("completedLabel", "Saved. Opening your Memory Page…"));
      let redirect = "";
      try {
        const response = JSON.parse(request.responseText);
        redirect = response?.data?.redirect || "";
      } catch (error) {
        redirect = "";
      }
      window.setTimeout(() => {
        window.location.assign(redirect || window.location.href);
      }, 350);
    });
    request.addEventListener("error", () => fail(message("requestError", "The upload could not be completed. Please try again.")));
    request.addEventListener("abort", () => fail(message("requestError", "The upload could not be completed. Please try again.")));
    request.send(new FormData(form));
  });
})();
