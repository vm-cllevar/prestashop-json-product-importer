<?php
require __DIR__.'/../jsonproductpreview/classes/JsonProductPreviewValidator.php';
$file=__DIR__.'/../jsonproductpreview/classes/JsonProductImportJobInput.php';
if(file_exists($file)){require $file;}
function jcheck($v,$msg){if(!$v){throw new RuntimeException($msg);}}
jcheck(class_exists('JsonProductImportJobInput'),'Job input handler missing');
$rows=array();for($i=0;$i<201;$i++){$rows[]=array('sku'=>'DEMO-'.$i,'title'=>'Demo','retail_price'=>1,'suppliers'=>array(array('stock'=>null)));}
$chunks=JsonProductImportJobInput::chunks(json_encode($rows),0,201);
jcheck(count($chunks)===3 && count($chunks[2])===1,'100-record boundary');
jcheck($chunks[0][0]['data']['stock']===null,'Missing stock preserved');
foreach(array(array('[]',0,1),array(json_encode($rows),1,202),array(json_encode($rows),0,200),array('{}',0,100),array(str_repeat(' ',1048577),0,100)) as $bad){$ok=false;try{JsonProductImportJobInput::chunks(...$bad);}catch(InvalidArgumentException $e){$ok=true;}jcheck($ok,'Reject malformed or misaligned chunks');}
$last=JsonProductImportJobInput::chunks(json_encode(array($rows[0])),200,201);jcheck(count($last)===1,'Final partial chunk accepted');
$ok=false;try{JsonProductImportJobInput::chunks(json_encode(array($rows[0])),0,201);}catch(InvalidArgumentException $e){$ok=true;}jcheck($ok,'Nonfinal partial chunk rejected');
echo "Job input checks passed\n";
