(() => {
  const styleList = document.querySelector("[data-tikool-fc-style-list]");
  const styleTemplate = document.querySelector("[data-tikool-fc-style-template]");
  const addStyleButton = document.querySelector("[data-tikool-fc-add-style]");
  let nextStyleIndex = styleList?.querySelectorAll("[data-tikool-fc-style-row]").length || 0;

  const styleRows = () => styleList ? [...styleList.querySelectorAll("[data-tikool-fc-style-row]")] : [];

  const syncStyleControls = () => {
    const rows = styleRows();
    rows.forEach((row, index) => {
      const original = row.dataset.original === "1";
      const previousIsOriginal = index > 0 && rows[index - 1]?.dataset.original === "1";
      const up = row.querySelector("[data-tikool-fc-style-up]");
      const down = row.querySelector("[data-tikool-fc-style-down]");
      if (up) up.disabled = original || index === 0 || previousIsOriginal;
      if (down) down.disabled = original || index === rows.length - 1;
    });
  };

  const updateStyleTitle = (row) => {
    const title = row?.querySelector("[data-tikool-fc-style-title]");
    if (!title || row.dataset.original === "1") return;
    const shortLabel = row.querySelector("[data-tikool-fc-style-short-label]")?.value.trim();
    const label = row.querySelector("[data-tikool-fc-style-label]")?.value.trim();
    const key = row.querySelector("[data-tikool-fc-style-key]")?.value.trim();
    title.textContent = shortLabel || label || key || "新风格";
  };

  addStyleButton?.addEventListener("click", () => {
    if (!styleList || !styleTemplate) return;
    const index = nextStyleIndex++;
    styleList.insertAdjacentHTML("beforeend", styleTemplate.innerHTML.replaceAll("__INDEX__", String(index)));
    const row = styleList.lastElementChild;
    const key = row?.querySelector("[data-tikool-fc-style-key]");
    if (key) key.value = `custom-style-${index}`;
    updateStyleTitle(row);
    syncStyleControls();
    key?.focus();
  });

  styleList?.addEventListener("click", (event) => {
    const row = event.target.closest("[data-tikool-fc-style-row]");
    if (!row || row.dataset.original === "1") return;
    if (event.target.closest("[data-tikool-fc-remove-style]")) {
      row.remove();
      syncStyleControls();
      return;
    }
    if (event.target.closest("[data-tikool-fc-style-up]")) {
      const previous = row.previousElementSibling;
      if (previous && previous.dataset.original !== "1") styleList.insertBefore(row, previous);
      syncStyleControls();
      return;
    }
    if (event.target.closest("[data-tikool-fc-style-down]")) {
      const next = row.nextElementSibling;
      if (next) styleList.insertBefore(next, row);
      syncStyleControls();
    }
  });

  styleList?.addEventListener("input", (event) => {
    const row = event.target.closest("[data-tikool-fc-style-row]");
    updateStyleTitle(row);
  });

  styleList?.addEventListener("focusout", (event) => {
    if (!event.target.matches("[data-tikool-fc-style-key]") || event.target.readOnly) return;
    event.target.value = event.target.value
      .toLocaleLowerCase()
      .replace(/[^a-z0-9_-]+/g, "-")
      .replace(/^-+|-+$/g, "");
    updateStyleTitle(event.target.closest("[data-tikool-fc-style-row]"));
  });

  syncStyleControls();

  const templateSelect = document.getElementById("_tikool_fc_template");
  const syncRendererFields = () => {
    const renderer = templateSelect?.value === "blanket"
      ? "blanket"
      : (templateSelect?.value === "keepsake-box" ? "keepsake" : "frame");
    document.querySelectorAll(".tikool-fc-renderer-frame").forEach((field) => {
      field.hidden = renderer !== "frame";
    });
    document.querySelectorAll(".tikool-fc-renderer-blanket").forEach((field) => {
      field.hidden = renderer !== "blanket";
    });
    document.querySelectorAll(".tikool-fc-renderer-keepsake").forEach((field) => {
      field.hidden = renderer !== "keepsake";
    });
    document.querySelectorAll(".tikool-fc-renderer-ai").forEach((field) => {
      field.hidden = renderer === "keepsake";
    });
  };
  templateSelect?.addEventListener("change", syncRendererFields);
  syncRendererFields();

  document.addEventListener("click", (event) => {
    const button = event.target.closest(".tikool-fc-choose-media");
    if (!button || !window.wp?.media) return;
    event.preventDefault();
    const input = document.getElementById(button.dataset.target);
    if (!input) return;

    const frame = window.wp.media({
      title: "选择预览图片",
      button: { text: "使用这张图片" },
      library: { type: "image" },
      multiple: false,
    });
    frame.on("select", () => {
      const attachment = frame.state().get("selection").first()?.toJSON();
      if (!attachment?.url) return;
      input.value = attachment.url;
      input.dispatchEvent(new Event("change", { bubbles: true }));
    });
    frame.open();
  });
})();
