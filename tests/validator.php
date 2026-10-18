<?php
require __DIR__ . '/../jsonproductpreview/classes/JsonProductPreviewValidator.php';
$n = 0;
function check($condition) { global $n; ++$n; if (!$condition) { throw new RuntimeException('Test failed: ' . $n); } }
$valid = '{"reference":"SKU-1","name":"Test","price":19.95,"stock":5}';
check(JsonProductPreviewValidator::parse('[' . $valid . ']')[0]['errors'] === array());
foreach (array('null', '{}', '[]', '[', '[' . str_repeat($valid . ',', 100) . $valid . ']', str_repeat(' ', 1048577)) as $bad) {
    $failed = false; try { JsonProductPreviewValidator::parse($bad); } catch (InvalidArgumentException $e) { $failed = true; } check($failed);
}
foreach (array('null', '[]', '{}', '{"reference":"SKU","name":"<script>","price":-1,"stock":1.5}', '{"reference":"SKU","name":"Test","price":"19.95","stock":1}', '{"reference":"SKU","name":"Test","price":19.95,"stock":1,"extra":true}', '{"reference":"SKU","name":"Test","price":1e400,"stock":1}', '{"reference":"SKU","name":"Test","price":0.1234567,"stock":1}') as $bad) {
    check(count(JsonProductPreviewValidator::parse('[' . $bad . ']')[0]['errors']) > 0);
}
$dupes = JsonProductPreviewValidator::parse('[' . $valid . ',' . str_replace('SKU-1', 'sku-1', $valid) . ']');
check(count($dupes[0]['errors']) > 0 && count($dupes[1]['errors']) > 0);
check(JsonProductPreviewValidator::parse('[{"reference":"ZERO","name":"Zero","price":0,"stock":0}]')[0]['errors'] === array());
echo "$n validation checks passed\n";
