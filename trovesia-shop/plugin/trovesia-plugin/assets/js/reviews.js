(() => {
  document.querySelectorAll(".trovesia-featured-reviews").forEach((section) => {
    const carousel = section.querySelector("[data-review-carousel]");
    const previous = section.querySelector("[data-review-carousel-previous]");
    const next = section.querySelector("[data-review-carousel-next]");

    if (!carousel || !previous || !next) {
      return;
    }

    const step = () => {
      const card = carousel.querySelector(".trovesia-review-card");
      const gap = Number.parseFloat(window.getComputedStyle(carousel).columnGap || "0");
      return (card?.getBoundingClientRect().width || carousel.clientWidth) + gap;
    };

    const sync = () => {
      previous.disabled = carousel.scrollLeft <= 2;
      next.disabled = carousel.scrollLeft + carousel.clientWidth >= carousel.scrollWidth - 2;
    };

    previous.addEventListener("click", () => carousel.scrollBy({ left: -step(), behavior: "smooth" }));
    next.addEventListener("click", () => carousel.scrollBy({ left: step(), behavior: "smooth" }));
    carousel.addEventListener("scroll", sync, { passive: true });
    window.addEventListener("resize", sync);
    sync();
  });

  const modal = document.querySelector("#trovesia-review-modal");
  const openers = document.querySelectorAll("[data-review-modal-open]");

  if (!modal || !openers.length) {
    return;
  }

  let returnFocus = null;

  const openModal = (trigger) => {
    returnFocus = trigger;
    if (typeof modal.showModal === "function") {
      modal.showModal();
    } else {
      modal.setAttribute("open", "");
    }
    document.body.classList.add("trovesia-review-modal-opened");
  };

  const closeModal = () => {
    if (typeof modal.close === "function" && modal.open) {
      modal.close();
    } else {
      modal.removeAttribute("open");
    }
    document.body.classList.remove("trovesia-review-modal-opened");
    returnFocus?.focus();
  };

  openers.forEach((opener) => opener.addEventListener("click", () => openModal(opener)));
  modal.querySelector("[data-review-modal-close]")?.addEventListener("click", closeModal);
  modal.addEventListener("cancel", (event) => {
    event.preventDefault();
    closeModal();
  });
  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });

  const input = modal.querySelector("[data-review-image-input]");
  const preview = modal.querySelector("[data-review-image-preview]");
  let previewUrl = "";

  input?.addEventListener("change", () => {
    if (previewUrl) {
      URL.revokeObjectURL(previewUrl);
      previewUrl = "";
    }

    const file = input.files?.[0];
    if (!file || !preview) {
      if (preview) {
        preview.hidden = true;
        preview.replaceChildren();
      }
      return;
    }

    previewUrl = URL.createObjectURL(file);
    const image = document.createElement("img");
    image.src = previewUrl;
    image.alt = "Selected review image preview";
    preview.replaceChildren(image);
    preview.hidden = false;
  });
})();
