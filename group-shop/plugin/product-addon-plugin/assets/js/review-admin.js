(() => {
  document.querySelectorAll(".trovesia-review-media-editor").forEach((editor) => {
    const input = editor.querySelector(".trovesia-review-media-editor__id");
    const preview = editor.querySelector(".trovesia-review-media-editor__preview");
    const choose = editor.querySelector(".trovesia-review-media-editor__choose");
    const remove = editor.querySelector(".trovesia-review-media-editor__remove");

    if (!input || !preview || !choose || !window.wp?.media) {
      return;
    }

    let frame;

    choose.addEventListener("click", () => {
      if (!frame) {
        frame = window.wp.media({
          title: "Choose review image",
          button: { text: "Use this image" },
          library: { type: "image" },
          multiple: false,
        });

        frame.on("select", () => {
          const attachment = frame.state().get("selection").first()?.toJSON();
          if (!attachment) {
            return;
          }

          const source = attachment.sizes?.medium?.url || attachment.sizes?.thumbnail?.url || attachment.url;
          input.value = String(attachment.id);
          preview.replaceChildren(Object.assign(document.createElement("img"), { src: source, alt: "" }));
          if (remove) {
            remove.hidden = false;
          }
        });
      }

      frame.open();
    });

    remove?.addEventListener("click", () => {
      input.value = "";
      const empty = document.createElement("span");
      empty.textContent = "No image selected";
      preview.replaceChildren(empty);
      remove.hidden = true;
    });
  });
})();
