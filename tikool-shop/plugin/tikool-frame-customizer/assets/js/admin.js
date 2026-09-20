(() => {
  document.addEventListener("click", (event) => {
    const button = event.target.closest(".tikool-fc-choose-media");
    if (!button || !window.wp?.media) return;
    event.preventDefault();
    const input = document.getElementById(button.dataset.target);
    if (!input) return;

    const frame = window.wp.media({
      title: "Choose preview image",
      button: { text: "Use this image" },
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
