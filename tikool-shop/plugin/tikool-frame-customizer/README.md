# Tikool Product Customizer

Reusable WooCommerce frame, blanket, and keepsake-box personalization for Tikool products.

## Product setup

1. Enable **Tikool Product Customizer** in the product General settings.
2. Select a customizer template such as **Wall frame**, **Desktop frame**, **Custom blanket**, or **Pet keepsake box**.
3. Map the product's size, frame, mat, orientation, and (for keepsake boxes) wood attribute slugs.
4. Enter product-level default geometry in millimetres.
5. Open each variation and enter only the production values that differ from the product defaults.

## Blanket setup

Choose the **Custom blanket** template and map the product's size attribute. The
renderer recognizes `50 × 60 in` and `60 × 80 in` (including `x` and `×`
spellings) and converts them to 1270 × 1524 mm and 1524 × 2032 mm production
snapshots. Explicit product or variation dimensions override the automatic size
profile.

Blankets use the same private upload, crop, AI-job, cart, checkout, and order
pipeline as frames, but render with a rounded textile surface, stitching, soft
folds, and an optional transparent texture/overlay. The flat cropped artwork is
stored separately from the textured customer mockup so production does not
receive preview shadows or folds.

The bundled blanket styles keep their palette inside the AI prompt: Warm
Minimalist uses cream and beige, while Pastel Watercolor uses blush, dusty blue,
sage, cream, and muted lavender. Shoppers select a style rather than a separate
background-color control.

## Keepsake-box setup

Choose the **Pet keepsake box** template and map the product's `size` and `wood`
attributes. The renderer recognizes the small 5 × 5 × 3.5 cm / two-vial SKU and
the large 6 × 6 × 4 cm / three-vial SKU. It automatically produces square 4 × 4
cm and 5 × 5 cm inside-lid photo crops respectively. Walnut and beech attributes
also select the appropriate preview tone; exact texture images can be configured
at product or variation level.

The shopper switches between **Inside photo** and **Lid engraving** views. The
photo view supports square cropping and zoom. The engraving view uses a fixed,
trusted paw vector plus the required pet name (maximum 15 characters). AI styles
and the optional name-on-photo controls are deliberately disabled for this
template. Bottle compartments are rendered with a wooden divider between every
vial.

Each keepsake order stores the original photo, the production crop, the customer
mockup, a versioned size/wood/vial snapshot, an editable engraving SVG, and a
high-resolution black-and-white engraving PNG in the private artwork directory.
WooCommerce order items provide separate download buttons for all production
assets. The NFC Memory Page remains owned by the separate Tikool Memory Page
plugin and is enabled independently through its product setting.

For every SKU, **print size** is the physical photo inserted into the frame and
**visible artwork size** is what remains visible after the frame lip or mat overlaps
the photo. The current wall-frame profile uses a 5 mm mat overlap on every edge.
The bundled wall-frame profile uses the supplier's measured
outer-frame, photo, and visible-area dimensions for 8 × 10, 12 × 16, and 16 × 20
inch SKUs; it does not substitute theoretical inch conversions.

The effective configuration is resolved in this order:

1. Built-in reusable template defaults.
2. Product defaults.
3. Variation/SKU overrides.

## Stored order data

The plugin stores the customer original image, a crop preview, normalized crop coordinates, source-image quality, and a versioned production-geometry snapshot in a private upload directory. Existing orders created by the former Tikool pet-photo workflow remain downloadable.

Customers can optionally print their pet's name inside the visible artwork area. The editor offers nine font styles (including locally bundled Playfair Display, Cormorant Garamond, Montserrat, Great Vibes, and the bold, spaced, all-capitals Oswald style), custom text color, optional outline color, a 3–12% relative text-size slider, and direct dragging to position the name within the photo. Alt + arrow keys can also move the name when the canvas has focus. The name is rendered into the private artwork preview and its font, size, colors, outline setting, text, and normalized position are preserved in the crop recipe. The order production summary includes the printed text and style. With the toggle off, the pet name remains an order field only; existing orders and no-name artwork are unchanged.

Each product can define its own ordered portrait-style list in the WooCommerce product editor. Original never calls AI and is always retained. Other rows choose an `ai_feature` from a dropdown populated by Tikool AI prompt profiles, so products can expose different labels, descriptions, and AI styles without code changes. Products without a saved configuration keep the bundled Original Photo, Minimalist, Watercolor, and Classic Oil Painting defaults.

Selecting an AI style uploads a preview-only copy of the source photo to the server-side Tikool AI service, downloads the temporary provider result into the protected customer-artwork directory, and displays it through a short-lived same-origin preview URL. Large uploads are compressed in the browser and normalized again on the server to a maximum 1600 px edge before the provider request; the customer's original file remains unchanged for the order. DPI warnings apply only while Original is selected, because generated images are treated as web previews and can be regenerated with a production model or upscaled before printing. Generated styles are cached for the current browser session and photo, while changing the source photo resets the selection to Original. The selected style is stored with cart and order production data. Royal and Memorial portraits remain separate product concepts rather than options on this product.

If a Tikool AI prompt profile defines variants, the editor selects and generates
the first variant by default, then displays background buttons beside the preview.
Selecting an ungenerated background makes one new API request; selecting a cached
background switches immediately. Each variant has its own job/cache fingerprint
and rate-limit reservation. Profiles without variants use their main prompt
directly and show no background controls. The selected variant key and
customer-facing label are saved in the crop recipe, cart details, and order
production metadata.

AI style generation is nonce-protected, limited to eight requests per customer per hour, and never exposes provider credentials to the browser. Temporary AI sources are deleted after two hours. The confirmed cropped artwork remains stored through the existing private order workflow.

Oswald, Playfair Display, Cormorant Garamond, Montserrat, and Great Vibes are bundled locally under the SIL Open Font License 1.1; see the matching `assets/fonts/OFL-*.txt` files. Their sources are the corresponding family directories in the [Google Fonts repository](https://github.com/google/fonts/tree/main/ofl).

The cart drawer and WooCommerce Cart block show a separate, framed preview of the customer's crop, mat, frame, and optional name instead of the generic product image. The high-resolution cropped artwork remains a separate production file. Both previews remain private; the cart image is served only when the requesting WooCommerce session still owns that cart item. Older carts without a framed preview fall back to the cropped artwork.

PayPal and Stripe express-payment buttons are withheld from frame-customizer product pages so a shopper must upload and review the photo before adding the product to the cart. Their cart and checkout payment methods remain available.

## Cart and checkout compatibility

The shop theme hides only WooCommerce's product-description element on the Cart and Checkout pages. It does not hide variation choices or the visible `woocommerce_get_item_data` rows, so future mug and rug customizers can show their own concise options there.

Each customizer should keep its cart payload under a distinct key, append only its own customer-facing rows to `woocommerce_get_item_data`, and leave unrelated cart lines unchanged. To show a personalized image in both classic and block-based carts, implement both `woocommerce_cart_item_thumbnail` and `woocommerce_store_api_cart_item_images`; return the incoming thumbnail/images for items the customizer does not own. Serve customer artwork from a session-scoped private URL, not a public upload path. Store production-only data in private cart/order metadata rather than in visible item rows.

## Extending templates

Use the `tikool_frame_customizer_templates` filter to register additional layouts without modifying the editor or order pipeline.
