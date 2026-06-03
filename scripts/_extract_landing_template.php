<?php
$html = file_get_contents(__DIR__ . '/../public/landing-spa.php');
if (!preg_match('/id="landing-template"[^>]*>(.*?)<\/script>/s', $html, $m)) {
    fwrite(STDERR, "template not found\n");
    exit(1);
}
$out = __DIR__ . '/_landing_template.html';
file_put_contents($out, trim($m[1]));
echo "written " . strlen($m[1]) . " bytes to $out\n";
