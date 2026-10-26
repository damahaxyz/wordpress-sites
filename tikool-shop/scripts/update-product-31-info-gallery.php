<?php

$product_id = 31;
$content = get_post_field('post_content', $product_id);

$images = array(
    array(55, 'Real mat board versus printed white border', 'Comparison of a real 1 mm mat board and a flat printed white border around pet portraits'),
    array(54, 'Slim solid-wood frame profile', 'Slim solid-wood pet portrait frame showing 15 mm front width and 28 mm frame depth'),
    array(56, 'What is inside your frame', 'Exploded view of solid-wood frame, acrylic glazing, pet portrait and backing board'),
    array(59, 'Layered construction with a real mat', 'Layered construction with backing board, pet portrait, real mat board, acrylic glazing and solid-wood frame'),
    array(57, 'How frame dimensions are measured', 'Diagram explaining visible area, photo insert size and overall frame size'),
    array(58, 'Solid-wood frame specifications', 'Solid-wood frame specifications including 15 mm front width, 28 mm depth and included components'),
);

$cards = '';
foreach ($images as $image) {
    $url = wp_get_attachment_image_url($image[0], 'full');
    if (!$url) {
        throw new RuntimeException('Missing attachment ' . $image[0]);
    }

    $cards .= sprintf(
        '<figure class="tikool-product-info-card"><img src="%s" alt="%s" width="1254" height="1254" loading="lazy" decoding="async"><figcaption>%s</figcaption></figure>',
        esc_url($url),
        esc_attr($image[2]),
        esc_html($image[1])
    );
}

$gallery = '<section class="tikool-product-info-visuals" aria-labelledby="tikool-product-details-title">'
    . '<h3 id="tikool-product-details-title">Explore the frame</h3>'
    . '<p class="tikool-product-info-visuals__intro">See the real mat-board difference, frame construction, included components, and the measurements used throughout this page.</p>'
    . '<div class="tikool-product-info-visuals__grid">' . $cards . '</div>'
    . '</section>';

$existing_pattern = '#<section class="tikool-product-info-visuals".*?</section>\s*#s';
if (preg_match($existing_pattern, $content)) {
    $updated_content = preg_replace($existing_pattern, $gallery . "\n", $content, 1);
} else {
    $insert_before = '<section class="tikool-spec-section" aria-labelledby="tikool-frame-dimensions-title">';
    if (strpos($content, $insert_before) === false) {
        throw new RuntimeException('Frame dimensions section was not found; product content was not changed.');
    }
    $updated_content = str_replace($insert_before, $gallery . "\n" . $insert_before, $content);
}

$result = wp_update_post(
    array(
        'ID' => $product_id,
        'post_content' => $updated_content,
    ),
    true
);

if (is_wp_error($result)) {
    throw new RuntimeException($result->get_error_message());
}

echo 'updated-product-' . $result;
