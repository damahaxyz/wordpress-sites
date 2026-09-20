# Tikool Frame Customizer

Reusable WooCommerce frame personalization for Tikool products.

## Product setup

1. Enable **Tikool Frame Customizer** in the product General settings.
2. Select a customizer template such as **Wall frame** or **Desktop frame**.
3. Map the product's size, frame, mat, and orientation attribute slugs.
4. Enter product-level default geometry in millimetres.
5. Open each variation and enter only the production values that differ from the product defaults.

For matted SKUs, **print size** is the physical photo supplied behind the mat and
**visible artwork size** is the mat opening. The opening should be smaller than the
print by twice the overlap/bleed value. The bundled wall-frame profile uses the
supplier's real millimetre dimensions for 8 × 10, 12 × 16, and 16 × 20 inch SKUs;
it does not substitute theoretical inch conversions.

The effective configuration is resolved in this order:

1. Built-in reusable template defaults.
2. Product defaults.
3. Variation/SKU overrides.

## Stored order data

The plugin stores the customer original image, a crop preview, normalized crop coordinates, source-image quality, and a versioned production-geometry snapshot in a private upload directory. Existing orders created by the former Tikool pet-photo workflow remain downloadable.

## Extending templates

Use the `tikool_frame_customizer_templates` filter to register additional layouts without modifying the editor or order pipeline.
