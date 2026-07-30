<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/password_reset.php';

// Step 3 of the password-reset wizard needs a CAPTCHA before any
// verification email can be sent. This renders a self-hosted distorted-text
// image with GD — no external CAPTCHA service/API keys involved, consistent
// with the rest of this app never loading assets from outside the server.

$text = pr_captcha_new();

$width  = 160;
$height = 56;
$image  = imagecreatetruecolor($width, $height);

$bg     = imagecolorallocate($image, 243, 251, 242); // matches the light brand-green tint used elsewhere
$fg     = imagecolorallocate($image, 10, 92, 8);
$noise  = imagecolorallocate($image, 180, 214, 178);
imagefilledrectangle($image, 0, 0, $width, $height, $bg);

// Noise lines behind the text — cheap bot resistance against naive OCR.
for ($i = 0; $i < 6; $i++) {
    imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $noise);
}

$len  = strlen($text);
$slot = (int) ($width / $len);
for ($i = 0; $i < $len; $i++) {
    $x = $i * $slot + random_int(4, 10);
    $y = (int) ($height / 2) - 10 + random_int(-4, 4);
    imagestring($image, 5, $x, $y, $text[$i], $fg);
}
// imagestring() has no rotation/size control, so scattered dot noise stands
// in for per-glyph distortion — still enough to defeat naive text scraping.
for ($i = 0; $i < 80; $i++) {
    imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $noise);
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
imagepng($image);
