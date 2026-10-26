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
    '8x10' => ['label' => '8 × 10 in', 'outer' => [223, 273], 'inner' => [198, 248], 'opening' => [142, 193]],
    '12x16' => ['label' => '12 × 16 in', 'outer' => [323, 425], 'inner' => [298, 400], 'opening' => [230, 330]],
    '16x20' => ['label' => '16 × 20 in', 'outer' => [424, 526], 'inner' => [399, 501], 'opening' => [320, 420]],
];

$finishes = [
    'natural' => ['label' => 'Natural Wood', 'horizontal' => $textureDir . '/natural-horizontal.jpg', 'vertical' => $textureDir . '/natural-vertical.jpg', 'edge' => '#8f5c29'],
    'walnut' => ['label' => 'Walnut', 'horizontal' => $textureDir . '/walnut-horizontal.jpg', 'vertical' => $textureDir . '/walnut-vertical.jpg', 'edge' => '#4b2515'],
    'black' => ['label' => 'Black Wood', 'horizontal' => $textureDir . '/black-horizontal.jpg', 'vertical' => $textureDir . '/black-vertical.jpg', 'edge' => '#171819'],
];

$portraits = [
    'cat' => $sourceDir . '/orange-tabby.png',
    'golden' => $sourceDir . '/golden-retriever.png',
    'collie' => $sourceDir . '/border-collie.png',
];

function q(string $value): string
{
    return escapeshellarg($value);
}

function runCommand(array $parts): void
{
    $lines = [];
    $status = 0;
    exec(implode(' ', $parts) . ' 2>&1', $lines, $status);
    if ($status !== 0) {
        throw new RuntimeException("ImageMagick failed:\n" . implode("\n", $lines));
    }
}

function geometry(array $profile): array
{
    $canvas = 1600;
    $outerH = 1260;
    $scale = $outerH / $profile['outer'][1];
    $outerW = (int) round($profile['outer'][0] * $scale);
    $innerW = (int) round($profile['inner'][0] * $scale);
    $innerH = (int) round($profile['inner'][1] * $scale);
    $openingW = (int) round($profile['opening'][0] * $scale);
    $openingH = (int) round($profile['opening'][1] * $scale);
    $outerX = (int) round(($canvas - $outerW) / 2);
    $outerY = (int) round(($canvas - $outerH) / 2);
    $innerX = (int) round(($canvas - $innerW) / 2);
    $innerY = (int) round(($canvas - $innerH) / 2);
    $openingX = (int) round(($canvas - $openingW) / 2);
    $openingY = (int) round(($canvas - $openingH) / 2);
    return compact('canvas', 'outerW', 'outerH', 'innerW', 'innerH', 'openingW', 'openingH', 'outerX', 'outerY', 'innerX', 'innerY', 'openingX', 'openingY');
}

function renderFrame(array $profile, array $finish, bool $withMat, string $portrait, string $output, bool $background): void
{
    $g = geometry($profile);
    $outerRight = $g['outerX'] + $g['outerW'];
    $outerBottom = $g['outerY'] + $g['outerH'];
    $innerRight = $g['innerX'] + $g['innerW'];
    $innerBottom = $g['innerY'] + $g['innerH'];
    $topH = $g['innerY'] - $g['outerY'];
    $sideW = $g['innerX'] - $g['outerX'];
    $photoX = $withMat ? $g['openingX'] : $g['innerX'];
    $photoY = $withMat ? $g['openingY'] : $g['innerY'];
    $photoW = $withMat ? $g['openingW'] : $g['innerW'];
    $photoH = $withMat ? $g['openingH'] : $g['innerH'];

    $base = $background ? 'gradient:#fffdf9-#f2e9df' : 'xc:none';
    $cmd = ['magick', '-size', '1600x1600', q($base)];
    if ($background) {
        $cmd = array_merge($cmd, ['-fill', q('rgba(70,44,25,0.13)'), '-draw', q('ellipse 800,1460 560,42 0,360')]);
    }
    if ($withMat) {
        $cmd = array_merge($cmd, ['-fill', q('#f7f4ec'), '-stroke', q('#e4ded3'), '-strokewidth', '2', '-draw', q(sprintf('rectangle %d,%d %d,%d', $g['innerX'], $g['innerY'], $innerRight, $innerBottom))]);
    }
    $cmd = array_merge($cmd, [
        '\\(', q($portrait), '-auto-orient', '-resize', q($photoW . 'x' . $photoH . '^'), '-gravity', 'center', '-extent', q($photoW . 'x' . $photoH), '\\)',
        '-gravity', 'northwest', '-geometry', q('+' . $photoX . '+' . $photoY), '-composite',
    ]);
    if ($withMat) {
        $cmd = array_merge($cmd, [
            '-fill', 'none', '-stroke', q('rgba(105,94,82,0.30)'), '-strokewidth', '3', '-draw', q(sprintf('rectangle %d,%d %d,%d', $g['openingX'], $g['openingY'], $g['openingX'] + $g['openingW'], $g['openingY'] + $g['openingH'])),
            '-stroke', q('rgba(255,255,255,0.88)'), '-strokewidth', '2', '-draw', q(sprintf('line %d,%d %d,%d line %d,%d %d,%d', $g['openingX'], $g['openingY'], $g['openingX'] + $g['openingW'], $g['openingY'], $g['openingX'], $g['openingY'], $g['openingX'], $g['openingY'] + $g['openingH'])),
        ]);
    }

    $cmd = array_merge($cmd, [
        '\\(', q($finish['horizontal']), '-resize', q($g['outerW'] . 'x' . $topH . '!'), '\\)', '-geometry', q('+' . $g['outerX'] . '+' . $g['outerY']), '-composite',
        '\\(', q($finish['horizontal']), '-resize', q($g['outerW'] . 'x' . $topH . '!'), '-flip', '\\)', '-geometry', q('+' . $g['outerX'] . '+' . $innerBottom), '-composite',
        '\\(', q($finish['vertical']), '-resize', q($sideW . 'x' . $g['innerH'] . '!'), '\\)', '-geometry', q('+' . $g['outerX'] . '+' . $g['innerY']), '-composite',
        '\\(', q($finish['vertical']), '-resize', q($sideW . 'x' . $g['innerH'] . '!'), '-flop', '\\)', '-geometry', q('+' . $innerRight . '+' . $g['innerY']), '-composite',
        '-fill', 'none', '-stroke', q($finish['edge']), '-strokewidth', '2', '-draw', q(sprintf(
            'rectangle %d,%d %d,%d line %d,%d %d,%d line %d,%d %d,%d line %d,%d %d,%d line %d,%d %d,%d',
            $g['outerX'], $g['outerY'], $outerRight, $outerBottom,
            $g['outerX'], $g['outerY'], $g['innerX'], $g['innerY'],
            $outerRight, $g['outerY'], $innerRight, $g['innerY'],
            $outerRight, $outerBottom, $innerRight, $innerBottom,
            $g['outerX'], $outerBottom, $g['innerX'], $innerBottom
        )),
        '-stroke', q('rgba(40,28,21,0.38)'), '-strokewidth', '3', '-draw', q(sprintf('rectangle %d,%d %d,%d', $g['innerX'], $g['innerY'], $innerRight, $innerBottom)),
    ]);
    if (!$background) {
        $cmd = array_merge($cmd, ['-trim', '+repage']);
    }
    $cmd = array_merge($cmd, ['-quality', '90', q($output)]);
    runCommand($cmd);
}

function svgShell(string $content): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1600" viewBox="0 0 1600 1600"><rect width="1600" height="1600" fill="#faf5ef"/>' . $content . '</svg>';
}

function renderGallery(string $baseContent, array $placements, string $output): void
{
    $svg = tempnam(sys_get_temp_dir(), 'tikool-gallery-');
    if ($svg === false) {
        throw new RuntimeException('Unable to create temporary gallery SVG.');
    }
    $svgPath = $svg . '.svg';
    @unlink($svg);
    file_put_contents($svgPath, svgShell($baseContent));
    $cmd = ['magick', q($svgPath)];
    foreach ($placements as [$path, $x, $bottom, $height]) {
        [$widthPx, $heightPx] = getimagesize($path);
        $width = (int) round($height * ($widthPx / $heightPx));
        $y = $bottom - $height;
        $cmd = array_merge($cmd, ['\\(', q($path), '-resize', q('x' . $height), '\\)', '-geometry', q('+' . $x . '+' . $y), '-composite']);
    }
    $cmd = array_merge($cmd, ['-quality', '90', q($output)]);
    runCommand($cmd);
    @unlink($svgPath);
}

foreach ($profiles as $sizeSlug => $profile) {
    foreach ($finishes as $finishSlug => $finish) {
        foreach ([false, true] as $withMat) {
            $presentation = $withMat ? 'classic-white-mat' : 'no-mat';
            renderFrame($profile, $finish, $withMat, $portraits['cat'], sprintf('%s/sku-%s-%s-%s.webp', $skuDir, $sizeSlug, $finishSlug, $presentation), true);
        }
    }
}

$cutouts = [
    '20-natural-mat-cat' => ['16x20', 'natural', true, 'cat'],
    '20-natural-mat-golden' => ['16x20', 'natural', true, 'golden'],
    '20-walnut-mat-cat' => ['16x20', 'walnut', true, 'cat'],
    '20-black-mat-collie' => ['16x20', 'black', true, 'collie'],
    '20-walnut-no-mat-cat' => ['16x20', 'walnut', false, 'cat'],
    '8-natural-mat-cat' => ['8x10', 'natural', true, 'cat'],
    '12-natural-mat-cat' => ['12x16', 'natural', true, 'cat'],
    '12-natural-no-mat-golden' => ['12x16', 'natural', false, 'golden'],
    '12-walnut-no-mat-collie' => ['12x16', 'walnut', false, 'collie'],
    '12-black-no-mat-cat' => ['12x16', 'black', false, 'cat'],
];
foreach ($cutouts as $name => [$size, $finish, $mat, $portrait]) {
    renderFrame($profiles[$size], $finishes[$finish], $mat, $portraits[$portrait], $cutoutDir . '/' . $name . '.png', false);
}

$mainText = '<text x="800" y="112" text-anchor="middle" font-family="Georgia,serif" font-size="48" fill="#34231c">THREE SOLID-WOOD FINISHES</text><text x="800" y="166" text-anchor="middle" font-family="Arial,sans-serif" font-size="25" fill="#78675e">REAL WHITE MAT · ACCURATE 16 × 20 PROPORTIONS</text><path d="M0 1390 H1600 V1600 H0Z" fill="#c88b54" opacity=".24"/>';
renderGallery($mainText, [[$cutoutDir . '/20-black-mat-collie.png', 70, 1398, 1010], [$cutoutDir . '/20-walnut-mat-cat.png', 480, 1410, 1080], [$cutoutDir . '/20-natural-mat-golden.png', 885, 1422, 1150]], $galleryDir . '/01-product-main-three-finishes.webp');

$comparisonText = '<text x="800" y="110" text-anchor="middle" font-family="Georgia,serif" font-size="60" fill="#34231c">CHOOSE YOUR PRESENTATION</text><text x="400" y="205" text-anchor="middle" font-family="Arial,sans-serif" font-size="30" font-weight="700" fill="#5a3421">NO MAT</text><text x="1200" y="205" text-anchor="middle" font-family="Arial,sans-serif" font-size="30" font-weight="700" fill="#5a3421">CLASSIC WHITE MAT</text><text x="1200" y="1515" text-anchor="middle" font-family="Arial,sans-serif" font-size="23" fill="#78675e">320 × 420 mm visible opening</text>';
renderGallery($comparisonText, [[$cutoutDir . '/20-walnut-no-mat-cat.png', 80, 1440, 1160], [$cutoutDir . '/20-walnut-mat-cat.png', 875, 1440, 1160]], $galleryDir . '/02-product-mat-comparison.webp');

$sizeText = '<text x="800" y="105" text-anchor="middle" font-family="Georgia,serif" font-size="60" fill="#34231c">MAT PROPORTIONS BY SIZE</text><text x="800" y="160" text-anchor="middle" font-family="Arial,sans-serif" font-size="25" fill="#78675e">Actual frame, visible-area, and mat-opening ratios</text><path d="M70 1360 H1530" stroke="#b87a48" stroke-width="8" opacity=".35"/><text x="305" y="1460" text-anchor="middle" font-family="Arial,sans-serif" font-size="28" font-weight="700" fill="#4a3428">8 × 10 in</text><text x="770" y="1460" text-anchor="middle" font-family="Arial,sans-serif" font-size="28" font-weight="700" fill="#4a3428">12 × 16 in</text><text x="1290" y="1460" text-anchor="middle" font-family="Arial,sans-serif" font-size="28" font-weight="700" fill="#4a3428">16 × 20 in</text>';
renderGallery($sizeText, [[$cutoutDir . '/8-natural-mat-cat.png', 130, 1355, 610], [$cutoutDir . '/12-natural-mat-cat.png', 535, 1355, 900], [$cutoutDir . '/20-natural-mat-cat.png', 1040, 1355, 1115]], $galleryDir . '/03-product-size-proportions.webp');

$lifestyleText = '<rect x="0" y="1180" width="1600" height="420" fill="#bf7b45" opacity=".22"/><rect x="170" y="1315" width="1260" height="55" rx="12" fill="#9d6033" opacity=".7"/><circle cx="265" cy="455" r="120" fill="#809276" opacity=".25"/><circle cx="180" cy="600" r="90" fill="#809276" opacity=".2"/><text x="800" y="92" text-anchor="middle" font-family="Georgia,serif" font-size="48" fill="#34231c">REAL 1 mm WHITE MAT</text><text x="800" y="145" text-anchor="middle" font-family="Arial,sans-serif" font-size="24" fill="#78675e">Balanced 40 mm borders on the 16 × 20 in frame</text>';
renderGallery($lifestyleText, [[$cutoutDir . '/20-natural-mat-golden.png', 336, 1325, 1160]], $galleryDir . '/04-product-natural-wood-lifestyle.webp');

$noMatText = '<text x="800" y="110" text-anchor="middle" font-family="Georgia,serif" font-size="60" fill="#34231c">FULL-BLEED, NO-MAT FINISHES</text><text x="800" y="165" text-anchor="middle" font-family="Arial,sans-serif" font-size="25" fill="#78675e">Portrait fills the visible frame opening</text><path d="M0 1390 H1600 V1600 H0Z" fill="#c88b54" opacity=".22"/>';
renderGallery($noMatText, [[$cutoutDir . '/12-black-no-mat-cat.png', 70, 1395, 1030], [$cutoutDir . '/12-walnut-no-mat-collie.png', 535, 1405, 1080], [$cutoutDir . '/12-natural-no-mat-golden.png', 995, 1415, 1130]], $galleryDir . '/05-product-no-mat-finishes.webp');

echo "Rendered 18 exact-proportion SKU images and 5 gallery images.\n";
