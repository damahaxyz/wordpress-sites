# Tikool AI

Tikool AI is the shared, server-side AI configuration layer for Tikool product
customizers. It currently calls Volcengine Ark's image-generation endpoint and
keeps provider credentials, model settings, and feature prompts out of individual
customizer plugins.

## Configuration

Activate **Tikool AI**, then open **Settings → Tikool AI**. Configure the endpoint,
model, output defaults, and one prompt profile for each customizer feature.

The **Generation records** tab keeps an audit row for every call, including early
validation failures and provider failures. It records the feature, caller,
product context, rendered prompt, model, size, elapsed time, HTTP status, request
ID, result URL, usage, or error. API keys and source-image URLs/data URIs are never
written to the log. Provider result URLs can expire and are not a permanent image
archive.

The bundled Custom Pet Portrait integration registers editable defaults for
`pet_portrait_minimalist`, `pet_portrait_watercolor`, and `pet_portrait_oil`.
These prompts emphasize preservation of the pet's identity and forbid added text,
frames, people, or extra animals. They can be disabled or edited on the settings
page without changing the frame customizer.

The bundled watercolor profile also defines four generation variants: Warm Ivory,
Mist Blue, Soft Sage, and Dusty Blush. Variants are configured one per line as
`key|customer label|prompt fragment`; the profile template inserts the selected
fragment through `{{background_prompt}}`. The product customizer selects the
first variant by default and makes one API request for that result. Other variants
are generated on demand from the background buttons below the preview. Profiles
without variants use their main prompt directly and show no background control.

The six bundled blanket profiles follow the same model. Warm Minimalist, Pastel
Watercolor, Modern Studio, Playful Pattern, Memorial Garden, and Pop Art each
provide four style-specific background variants. Their templates preserve every
subject and the relationships between multiple subjects, reserve a print-safe
area, require full-bleed flat artwork, and explicitly prohibit product mockups,
fabric folds, generated text, and unrelated props.

The product customizer resolves `{{orientation}}`, `{{aspect_ratio}}`, and
`{{print_dimensions}}` from the selected WooCommerce variation's configured
artwork or print geometry. Prompt profiles should use these variables instead of
hard-coding one canvas ratio. Generated-preview cache keys include the resolved
composition values so different print formats cannot reuse an incompatible image.

Enter the API key directly on the settings page. It is stored in the WordPress
options table, is not echoed back into the password field, and remains unchanged
when that field is left blank. Never expose resolved settings or the API key to
browser JavaScript.

## Customizer integration

Call the stable procedural API from a customizer's authenticated, nonce-protected
server-side handler:

```php
$image = tikool_ai_image_data_url($private_upload_path);
if (is_wp_error($image)) {
    return $image;
}

$result = tikool_ai_generate_image([
    'feature' => 'pet_portrait_preview',
    'source' => 'my-customizer',
    'image' => $image,
    'variables' => [
        'style_prompt' => '水彩插画，浅色纸张肌理，柔和自然光',
        'user_prompt' => '让宠物坐在画面中央',
        'product_name' => $product->get_name(),
    ],
    'context' => [
        'source' => 'my-customizer',
        'product_id' => $product->get_id(),
    ],
]);

if (is_wp_error($result)) {
    // Convert the error into the customizer's own customer-safe response.
    return $result;
}

$preview_url = $result['url'];
```

The `image` argument accepts an HTTPS URL, an image data URI, or an array of up to
four values. Private customer uploads should normally be converted with
`tikool_ai_image_data_url()` so they do not need to become public.

Each calling customizer remains responsible for nonce/session validation, per-user
rate limits, usage quotas, consent, its browser UI, and saving the selected result
to the cart/order. Provider result URLs may expire, so a selected image must be
downloaded promptly and stored in the customizer's private order-artwork area; do
not save the remote URL as permanent order data. Tikool AI intentionally does not
expose a public REST or AJAX generation endpoint.

## Extension points

- `tikool_ai_prompt_profiles` registers code-owned feature prompts.
- `tikool_ai_rendered_prompt` adjusts a rendered prompt.
- `tikool_ai_image_payload` adjusts the provider request body.
- `tikool_ai_request_completed` receives success, status, duration, and request ID
  for privacy-safe metrics. It does not include the image, prompt, or API key.
