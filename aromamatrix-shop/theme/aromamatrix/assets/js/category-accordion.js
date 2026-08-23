(() => {
  const accordion = document.querySelector("[data-category-accordion]");

  if (accordion) {
    accordion.querySelectorAll(".aromamatrix-category-row__toggle").forEach((toggle) => {
      toggle.addEventListener("click", () => {
        const row = toggle.closest(".aromamatrix-category-row");
        const panel = document.getElementById(toggle.getAttribute("aria-controls"));

        if (!row || !panel) {
          return;
        }

        const willOpen = toggle.getAttribute("aria-expanded") !== "true";
        toggle.setAttribute("aria-expanded", String(willOpen));
        row.classList.toggle("is-open", willOpen);
        panel.hidden = !willOpen;
      });
    });
  }
})();
