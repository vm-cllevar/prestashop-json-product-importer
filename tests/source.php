<?php
require __DIR__.'/../jsonproductpreview/classes/JsonProductPreviewValidator.php';
function verify($v) { if (!$v) { throw new RuntimeException('Assertion failed'); } }
foreach (array(null, 0, '0', 5, 'Y') as $stock) {
 $v=array(array('sku'=>'A','title'=>'Test','retail_price'=>1,'suppliers'=>array(array('stock'=>$stock))));
 $r=JsonProductPreviewValidator::parse(json_encode($v));
 verify(!$r[0]['errors']);
 verify($r[0]['data']['stock'] === (($stock===null || $stock==='Y') ? null : (int)$stock));
}
$r=JsonProductPreviewValidator::parse('[{"sku":"A","title":"Test","retail_price":1,"suppliers":[{"stock":5},{"stock":6}]}]');verify($r[0]['data']['stock']===null);
$r=JsonProductPreviewValidator::parse(file_get_contents(isset($argv[1]) ? $argv[1] : __DIR__.'/fixtures/approved-matches-100.json'));
$invalid=array_filter($r,function($x){return count($x['errors'])>0;});
verify(count($r) === 100 && count($invalid) === 1);
echo 'Source mapping assertions passed. Rows: '.count($r).'; valid: '.(count($r)-count($invalid)).'; invalid: '.count($invalid)."\n";
foreach($invalid as $i=>$v){echo ($i+1).': '.implode(' ', $v['errors'])."\n";}
