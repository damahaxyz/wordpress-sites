<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assetRoot = $root . '/product-assets/custom-pet-portrait/generated-product-images';
$skuDir = $assetRoot . '/sku';
$galleryDir = $assetRoot . '/gallery';
$cutoutDir = $assetRoot . '/cutouts';
$sourceDir = $assetRoot . '/source-art';
$textureDir = $assetRoot . '/textures';

foreach ([$skuDir, $galleryDir, $cutoutDir] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create ' . $directory);
    }
}

$profiles = [
    '8x10' => [
        'label' => '8 × 10 in',
        'outer' => [223.0, 273.0],
        'inner' => [198.0, 248.0],
        'opening' => [142.0, 193.0],
    ],
    '12x16' => [
        'label' => '12 × 16 in',
        'outer' => [323.0, 425.0],
        'inner' => [298.0, 400.0],
        'opening' => [230.0, 330.0],
    ],
    '16x20' => [
        'label' => '16 × 20 in',
        'outer' => [424.0, 526.0],
        'inner' => [399.0, 501.0],
        'opening' => [320.0, 420.0],
    ],
];

$finishes = [
    'natural' => [
        'label' => 'Natural Wood',
        'horizontal' => $textureDir . '/natural-horizontal.jpg',
        'vertical' => $textureDir . '/natural-vertical.jpg',
        'edge' => '#8f5c29',
    ],
    'walnut' => [
        'label' => 'Walnut',
        'horizontal' => $textureDir . '/walnut-horizontal.jpg',
        'vertical' => $textureDir . '/walnut-vertical.jpg',
        'edge' => '#4b2515',
    ],
    'black' => [
        'label' => 'Black Wood',
        'horizontal' => $textureDir . '/black-horizontal.jpg',
        'vertical' => $textureDir . '/black-vertical.jpg',
        'edge' => '#0f1010',
    ],
];

$portraits = [
    'cat' => $sourceDir . '/orange-tabby.png',
    'golden' => $sourceDir . '/golden-retriever.png',
    'collie' => $sourceDir . '/border-collie.png',
];

function dataUri(string $path): string
{
    $absolute = realpath($path);
    if ($absolute === false) {
        throw new RuntimeException('Unable to resolve ' . $path);
    }
    return 'file://' . str_replace('%2F', '/', rawurlencode($absolute));
}

function n(float $value): string
{
    return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
}

/**
 * Render a front-facing frame with geometry scaled directly from supplier millimetres.
 * The SVG is deterministic so the frame, mat, and opening proportions cannot drift.
 */
function frameSvg(array $profile, array $finish, bool $withMat, string $portraitPath, bool $background = true): string
{
    $canvas = 1600.0;
    $outerHeight = 1260.0;
    $scale = $outerHeight / (float) $profile['outer'][1];
    $outerWidth = (float) $profile['outer'][0] * $scale;
    $innerWidth = (float) $profile['inner'][0] * $scale;
    $innerHeight = (float) $profile['inner'][1] * $scale;
    $openingWidth = (float) $profile['opening'][0] * $scale;
    $openingHeight = (float) $profile['opening'][1] * $scale;

    $outerX = ($canvas - $outerWidth) / 2;
    $outerY = ($canvas - $outerHeight) / 2;
    $innerX = ($canvas - $innerWidth) / 2;
    $innerY = ($canvas - $innerHeight) / 2;
    $openingX = ($canvas - $openingWidth) / 2;
    $openingY = ($canvas - $openingHeight) / 2;

    $photoX = $withMat ? $openingX : $innerX;
    $photoY = $withMat ? $openingY : $innerY;
    $photoWidth = $withMat ? $openingWidth : $innerWidth;
    $photoHeight = $withMat ? $openingHeight : $innerHeight;

    $outerRight = $outerX + $outerWidth;
    $outerBottom = $outerY + $outerHeight;
    $innerRight = $innerX + $innerWidth;
    $innerBottom = $innerY + $innerHeight;

    $horizontalTexture = dataUri($finish['horizontal']);
    $verticalTexture = dataUri($finish['vertical']);
    $portrait = dataUri($portraitPath);
    $backgroundMarkup = $background
        ? '<rect width="1600" height="1600" fill="url(#background)"/><ellipse cx="800" cy="1460" rx="560" ry="42" fill="#4d3422" opacity=".12"/>'
        : '';

    $top = implode(' ', [n($outerX) . ',' . n($outerY), n($outerRight) . ',' . n($outerY), n($innerRight) . ',' . n($innerY), n($innerX) . ',' . n($innerY)]);
    $right = implode(' ', [n($outerRight) . ',' . n($outerY), n($outerRight) . ',' . n($outerBottom), n($innerRight) . ',' . n($innerBottom), n($innerRight) . ',' . n($innerY)]);
    $bottom = implode(' ', [n($outerRight) . ',' . n($outerBottom), n($outerX) . ',' . n($outerBottom), n($innerX) . ',' . n($innerBottom), n($innerRight) . ',' . n($innerBottom)]);
    $left = implode(' ', [n($outerX) . ',' . n($outerBottom), n($outerX) . ',' . n($outerY), n($innerX) . ',' . n($innerY), n($innerX) . ',' . n($innerBottom)]);

    $matMarkup = $withMat
        ? '<rect x="' . n($innerX) . '" y="' . n($innerY) . '" width="' . n($innerWidth) . '" height="' . n($innerHeight) . '" fill="#f7f4ec" filter="url(#paper)"/>'
        : '';
    $openingStroke = $withMat
        ? '<rect x="' . n($openingX) . '" y="' . n($openingY) . '" width="' . n($openingWidth) . '" height="' . n($openingHeight) . '" fill="none" stroke="#d1cbc0" stroke-width="3" filter="url(#openingShadow)"/><path d="M ' . n($openingX) . ' ' . n($openingY + $openingHeight) . ' V ' . n($openingY) . ' H ' . n($openingX + $openingWidth) . '" fill="none" stroke="#fff" stroke-width="2" opacity=".92"/>'
        : '';

    return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1600" height="1600" viewBox="0 0 1600 1600">'
        . '<defs>'
        . '<linearGradient id="background" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#fffdf9"/><stop offset="1" stop-color="#f2e9df"/></linearGradient>'
        . '<linearGradient id="glass" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#fff" stop-opacity=".13"/><stop offset=".42" stop-color="#fff" stop-opacity="0"/><stop offset=".72" stop-color="#fff" stop-opacity=".07"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>'
        . '<filter id="frameShadow" x="-30%" y="-30%" width="160%" height="170%"><feDropShadow dx="0" dy="18" stdDeviation="18" flood-color="#3d2a1c" flood-opacity=".28"/></filter>'
        . '<filter id="openingShadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="3" stdDeviation="2.2" flood-color="#756c60" flood-opacity=".34"/></filter>'
        . '<filter id="paper" x="-10%" y="-10%" width="120%" height="120%"><feTurbulence type="fractalNoise" baseFrequency=".8" numOctaves="2" seed="7" result="noise"/><feColorMatrix in="noise" type="saturate" values="0" result="mono"/><feBlend in="SourceGraphic" in2="mono" mode="soft-light"/></filter>'
        . '<clipPath id="photoClip"><rect x="' . n($photoX) . '" y="' . n($photoY) . '" width="' . n($photoWidth) . '" height="' . n($photoHeight) . '"/></clipPath>'
        . '<pattern id="topTexture" patternUnits="userSpaceOnUse" x="' . n($outerX) . '" y="' . n($outerY) . '" width="' . n($outerWidth) . '" height="' . n($innerY - $outerY) . '"><image xlink:href="' . $horizontalTexture . '" x="' . n($outerX) . '" y="' . n($outerY) . '" width="' . n($outerWidth) . '" height="' . n($innerY - $outerY) . '" preserveAspectRatio="none"/></pattern>'
        . '<pattern id="bottomTexture" patternUnits="userSpaceOnUse" x="' . n($outerX) . '" y="' . n($innerBottom) . '" width="' . n($outerWidth) . '" height="' . n($outerBottom - $innerBottom) . '"><image xlink:href="' . $horizontalTexture . '" x="' . n($outerX) . '" y="' . n($innerBottom) . '" width="' . n($outerWidth) . '" height="' . n($outerBottom - $innerBottom) . '" preserveAspectRatio="none"/></pattern>'
        . '<pattern id="leftTexture" patternUnits="userSpaceOnUse" x="' . n($outerX) . '" y="' . n($outerY) . '" width="' . n($innerX - $outerX) . '" height="' . n($outerHeight) . '"><image xlink:href="' . $verticalTexture . '" x="' . n($outerX) . '" y="' . n($outerY) . '" width="' . n($innerX - $outerX) . '" height="' . n($outerHeight) . '" preserveAspectRatio="none"/></pattern>'
        . '<pattern id="rightTexture" patternUnits="userSpaceOnUse" x="' . n($innerRight) . '" y="' . n($outerY) . '" width="' . n($outerRight - $innerRight) . '" height="' . n($outerHeight) . '"><image xlink:href="' . $verticalTexture . '" x="' . n($innerRight) . '" y="' . n($outerY) . '" width="' . n($outerRight - $innerRight) . '" height="' . n($outerHeight) . '" preserveAspectRatio="none"/></pattern>'
        . '</defs>'
        . $backgroundMarkup
        . '<g filter="url(#frameShadow)">'
        . $matMarkup
        . '<image xlink:href="' . $portrait . '" x="' . n($photoX) . '" y="' . n($photoY) . '" width="' . n($photoWidth) . '" height="' . n($photoHeight) . '" preserveAspectRatio="xMidYMid slice" clip-path="url(#photoClip)"/>'
        . '<rect x="' . n($photoX) . '" y="' . n($photoY) . '" width="' . n($photoWidth) . '" height="' . n($photoHeight) . '" fill="url(#glass)" clip-path="url(#photoClip)"/>'
        . $openingStroke
        . '<polygon points="' . $top . '" fill="url(#topTexture)" stroke="' . $finish['edge'] . '" stroke-width="1.4"/>'
        . '<polygon points="' . $right . '" fill="url(#rightTexture)" stroke="' . $finish['edge'] . '" stroke-width="1.4"/>'
        . '<polygon points="' . $bottom . '" fill="url(#bottomTexture)" stroke="' . $finish['edge'] . '" stroke-width="1.4"/>'
        . '<polygon points="' . $left . '" fill="url(#leftTexture)" stroke="' . $finish['edge'] . '" stroke-width="1.4"/>'
        . '<rect x="' . n($outerX) . '" y="' . n($outerY) . '" width="' . n($outerWidth) . '" height="' . n($outerHeight) . '" fill="none" stroke="#fff" stroke-opacity=".24" stroke-width="2"/>'
        . '<rect x="' . n($innerX) . '" y="' . n($innerY) . '" width="' . n($innerWidth) . '" height="' . n($innerHeight) . '" fill="none" stroke="#2e2119" stroke-opacity=".38" stroke-width="3"/>'
        . '</g></svg>';
}

function renderWebp(string $svg, string $output): void
{
    $temporary = tempnam(sys_get_temp_dir(), 'tikool-frame-');
    if ($temporary === false || file_put_contents($temporary . '.svg', $svg) === false) {
        throw new RuntimeException('Unable to create temporary SVG.');
    }
    @unlink($temporary);
    $temporary .= '.svg';
    $command = 'magick -background none ' . escapeshellarg($temporary) . ' -quality 88 ' . escapeshellarg($output) . ' 2>&1';
    exec($command, $lines, $status);
    @unlink($temporary);
    if ($status !== 0) {
        throw new RuntimeException("ImageMagick failed:\n" . implode("\n", $lines));
    }
}

function renderCutout(string $svg, string $output): void
{
    $temporary = tempnam(sys_get_temp_dir(), 'tikool-cutout-');
    if ($temporary === false || file_put_contents($temporary . '.svg', $svg) === false) {
        throw new RuntimeException('Unable to create temporary SVG.');
    }
    @unlink($temporary);
    $temporary .= '.svg';
    $command = 'magick -background none ' . escapeshellarg($temporary) . ' -trim +repage ' . escapeshellarg($output) . ' 2>&1';
    exec($command, $lines, $status);
    @unlink($temporary);
    if ($status !== 0) {
        throw new RuntimeException("ImageMagick failed:\n" . implode("\n", $lines));
    }
}

foreach ($profiles as $sizeSlug => $profile) {
    foreach ($finishes as $finishSlug => $finish) {
        foreach ([false, true] as $withMat) {
            $presentation = $withMat ? 'classic-white-mat' : 'no-mat';
            $filename = sprintf('sku-%s-%s-%s.webp', $sizeSlug, $finishSlug, $presentation);
            renderWebp(frameSvg($profile, $finish, $withMat, $portraits['cat'], true), $skuDir . '/' . $filename);
        }
    }
}

$cutoutSpecs = [
    '20-natural-mat-cat' => ['16x20', 'natural', true, 'cat'],
    '20-natural-mat-golden' => ['16x20', 'natural', true, 'golden'],
    '20-walnut-mat-cat' => ['16x20', 'walnut', true, 'cat'],
    '20-walnut-mat-golden' => ['16x20', 'walnut', true, 'golden'],
    '20-walnut-no-mat-cat' => ['16x20', 'walnut', false, 'cat'],
    '20-black-mat-collie' => ['16x20', 'black', true, 'collie'],
    '8-natural-mat-cat' => ['8x10', 'natural', true, 'cat'],
    '12-natural-mat-cat' => ['12x16', 'natural', true, 'cat'],
    '12-natural-no-mat-golden' => ['12x16', 'natural', false, 'golden'],
    '12-walnut-no-mat-collie' => ['12x16', 'walnut', false, 'collie'],
    '12-black-no-mat-cat' => ['12x16', 'black', false, 'cat'],
];

foreach ($cutoutSpecs as $name => [$size, $finish, $mat, $portrait]) {
    renderCutout(frameSvg($profiles[$size], $finishes[$finish], $mat, $portraits[$portrait], false), $cutoutDir . '/' . $name . '.png');
}

function embeddedImage(string $path, float $x, float $bottom, float $height): string
{
    [$widthPx, $heightPx] = getimagesize($path);
    $width = $height * ($widthPx / $heightPx);
    $y = $bottom - $height;
    return '<image xlink:href="' . dataUri($path) . '" x="' . n($x) . '" y="' . n($y) . '" width="' . n($width) . '" height="' . n($height) . '"/>';
}

function galleryShell(string $content, string $extraDefs = ''): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1600" height="1600" viewBox="0 0 1600 1600"><defs><linearGradient id="wall" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#fffdf8"/><stop offset=".78" stop-color="#f2e8de"/><stop offset="1" stop-color="#dbc2a9"/></linearGradient><filter id="soft"><feDropShadow dx="0" dy="16" stdDeviation="18" flood-color="#4e3322" flood-opacity=".22"/></filter>' . $extraDefs . '</defs><rect width="1600" height="1600" fill="url(#wall)"/>' . $content . '</svg>';
}

$mainContent = '<text x="800" y="112" text-anchor="middle" font-family="Georgia,serif" font-size="58" fill="#34231c" letter-spacing="3">THREE SOLID-WOOD FINISHES</text><text x="800" y="166" text-anchor="middle" font-family="Arial,sans-serif" font-size="25" fill="#78675e" letter-spacing="2">REAL WHITE MAT · ACCURATE 16 × 20 PROPORTIONS</text><path d="M0 1390 H1600 V1600 H0Z" fill="#c88b54" opacity=".24"/>'
    . embeddedImage($cutoutDir . '/20-black-mat-collie.png', 70, 1398, 1010)
    . embeddedImage($cutoutDir . '/20-walnut-mat-cat.png', 480, 1410, 1080)
    . embeddedImage($cutoutDir . '/20-natural-mat-golden.png', 885, 1422, 1150);
renderWebp(galleryShell($mainContent), $galleryDir . '/01-product-main-three-finishes.webp');

$comparisonContent = '<text x="800" y="110" text-anchor="middle" font-family="Georgia,serif" font-size="60" fill="#34231c">CHOOSE YOUR PRESENTATION</text><text x="400" y="205" text-anchor="middle" font-family="Arial,sans-serif" font-size="30" font-weight="700" fill="#5a3421">NO MAT</text><text x="1200" y="205" text-anchor="middle" font-family="Arial,sans-serif" font-size="30" font-weight="700" fill="#5a3421">CLASSIC WHITE MAT</text>'
    . embeddedImage($cutoutDir . '/20-walnut-no-mat-cat.png', 80, 1440, 1160)
    . embeddedImage($cutoutDir . '/20-walnut-mat-cat.png', 875, 1440, 1160)
    . '<text x="1200" y="1515" text-anchor="middle" font-family="Arial,sans-serif" font-size="23" fill="#78675e">320 × 420 mm visible opening</text>';
renderWebp(galleryShell($comparisonContent), $galleryDir . '/02-product-mat-comparison.webp');

$sizeContent = '<text x="800" y="105" text-anchor="middle" font-family="Georgia,serif" font-size="60" fill="#34231c">MAT PROPORTIONS BY SIZE</text><text x="800" y="160" text-anchor="middle" font-family="Arial,sans-serif" font-size="25" fill="#78675e">Each frame uses its actual outer, visible-area, and mat-opening ratio</text><path d="M70 1360 H1530" stroke="#b87a48" stroke-width="8" opacity=".35"/>'
    . embeddedImage($cutoutDir . '/8-natural-mat-cat.png', 130, 1355, 610)
    . embeddedImage($cutoutDir . '/12-natural-mat-cat.png', 535, 1355, 900)
    . embeddedImage($cutoutDir . '/20-natural-mat-cat.png', 1040, 1355, 1115)
    . '<text x="305" y="1460" text-anchor="middle" font-family="Arial,sans-serif" font-size="28" font-weight="700" fill="#4a3428">8 × 10 in</text><text x="770" y="1460" text-anchor="middle" font-family="Arial,sans-serif" font-size="28" font-weight="700" fill="#4a3428">12 × 16 in</text><text x="1290" y="1460" text-anchor="middle" font-family="Arial,sans-serif" font-size="28" font-weight="700" fill="#4a3428">16 × 20 in</text>';
renderWebp(galleryShell($sizeContent), $galleryDir . '/03-product-size-proportions.webp');

$lifestyleContent = '<rect x="0" y="1180" width="1600" height="420" fill="#bf7b45" opacity=".22"/><rect x="170" y="1315" width="1260" height="55" rx="12" fill="#9d6033" opacity=".7"/><circle cx="265" cy="455" r="120" fill="#809276" opacity=".25"/><circle cx="180" cy="600" r="90" fill="#809276" opacity=".2"/>'
    . embeddedImage($cutoutDir . '/20-natural-mat-golden.png', 475, 1325, 1160)
    . '<text x="1215" y="330" text-anchor="middle" font-family="Georgia,serif" font-size="48" fill="#34231c">A BALANCED</text><text x="1215" y="388" text-anchor="middle" font-family="Georgia,serif" font-size="48" fill="#34231c">40 mm MAT BORDER</text><text x="1215" y="445" text-anchor="middle" font-family="Arial,sans-serif" font-size="23" fill="#78675e">16 × 20 in frame</text>';
renderWebp(galleryShell($lifestyleContent), $galleryDir . '/04-product-natural-wood-lifestyle.webp');

$noMatContent = '<text x="800" y="110" text-anchor="middle" font-family="Georgia,serif" font-size="60" fill="#34231c">FULL-BLEED, NO-MAT FINISHES</text><text x="800" y="165" text-anchor="middle" font-family="Arial,sans-serif" font-size="25" fill="#78675e">Portrait fills the visible frame opening</text><path d="M0 1390 H1600 V1600 H0Z" fill="#c88b54" opacity=".22"/>'
    . embeddedImage($cutoutDir . '/12-black-no-mat-cat.png', 70, 1395, 1030)
    . embeddedImage($cutoutDir . '/12-walnut-no-mat-collie.png', 535, 1405, 1080)
    . embeddedImage($cutoutDir . '/12-natural-no-mat-golden.png', 995, 1415, 1130);
renderWebp(galleryShell($noMatContent), $galleryDir . '/05-product-no-mat-finishes.webp');

echo 'Generated 18 SKU images and 5 gallery images.' . PHP_EOL;
