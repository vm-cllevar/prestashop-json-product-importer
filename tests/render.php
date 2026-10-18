<?php
define('_PS_VERSION_','8.2.8');
class Module {}
require __DIR__.'/../jsonproductpreview/jsonproductpreview.php';
$r=new ReflectionClass('Jsonproductpreview');$m=$r->newInstanceWithoutConstructor();$diff=$r->getMethod('diff');
$html=$diff->invoke($m,array('name'=>'Two  spaces'),array('name'=>'Two spaces'));
if (strpos($html,'white-space:pre-wrap')===false || strpos($html,'Whitespace change')===false) {throw new RuntimeException('Whitespace differences must be visible');}
$html=$diff->invoke($m,array('name'=>'<script>'),array('name'=>'Safe & sound'));
if (strpos($html,'<script>')!==false || strpos($html,'&lt;script&gt;')===false) {throw new RuntimeException('Output must be escaped');}
echo "Diff rendering checks passed\n";
