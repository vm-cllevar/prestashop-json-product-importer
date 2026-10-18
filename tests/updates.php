<?php
require_once __DIR__ . '/../jsonproductpreview/classes/JsonProductPreviewValidator.php';
$service = __DIR__ . '/../jsonproductpreview/classes/JsonProductImportService.php';
if (file_exists($service)) { require_once $service; }
function expect($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
expect(class_exists('JsonProductImportService'), 'FAIL: update service not implemented');
class MemoryStore {
    public $products = array(); public $batches = array(); public $writes = 0; public $fail = false; private $backup;
    public function lookup($reference, $shop, $lang, $lock = false) { return array_values(array_filter($this->products, function ($p) use ($reference) { return $p['reference'] === $reference; })); }
    public function begin() { $this->backup = array($this->products, $this->batches, $this->writes); }
    public function commit() { $this->backup = null; }
    public function rollback() { list($this->products, $this->batches, $this->writes) = $this->backup; }
    public function batch($id, $shop, $employee, $lock = false) { $b = isset($this->batches[$id]) ? $this->batches[$id] : null; if (!$b || $b['shop'] != $shop || $b['employee'] != $employee) { throw new RuntimeException('Batch unavailable'); } return $b; }
    public function save($b) { $this->batches[$b['id']] = $b; }
    public function write($id, $shop, $lang, $values) { ++$this->writes; if ($this->fail) { throw new RuntimeException('Write failed'); } foreach ($values as $k=>$v) { $this->products[$id][$k] = $v; } }
    public function afterCommit($batch) { return ''; }
}
function product($id = 1) { return array('id'=>$id,'reference'=>'SKU-'.$id,'name'=>'Original','price'=>'19.950000','stock'=>7,'blocked'=>'','stock_blocked'=>''); }
function input($id = 1, $name='Original', $price=19.95, $stock=null) { return array('data'=>array('reference'=>'SKU-'.$id,'name'=>$name,'price'=>$price,'stock'=>$stock),'errors'=>array(),'notes'=>array()); }
function rejects($fn, $message) { $failed=false; try {$fn();} catch (RuntimeException $e) {$failed=true;} expect($failed,$message); }
$s = new MemoryStore(); $s->products[1] = product(); $service = new JsonProductImportService($s);
$p = $service->preview(array(input()),1,1);
expect($p['rows'][0]['status']==='Unchanged' && count($p['changes'])===0,'Equal decimal prices and missing stock are no-op');
$p = $service->preview(array(input(1,'New',20)),1,1);
expect(array_keys($p['changes'][0]['after'])===array('name','price'),'Only changed fields included');
expect($p['changes'][0]['before']['price']==='19.950000','Original precision retained');
$p0=$service->preview(array(input(1,'Original',19.95,0)),1,1);
expect($p0['changes'][0]['after']['stock']===0,'Explicit zero is a stock change');
$s->products[1]['stock_blocked']='Shared stock';
expect(count($service->preview(array(input(1,'New',20,0)),1,1)['changes'])===0,'Unsafe stock skips row');
$s->products[1]['stock_blocked']='';
$s->products[1]['blocked']='Combinations';
expect(count($service->preview(array(input(1,'New')),1,1)['changes'])===0,'Combinations excluded');
$s->products[1]['blocked']='';
$s->products[2]=product(2); $s->products[2]['reference']='SKU-1';
expect(count($service->preview(array(input(1,'New')),1,1)['changes'])===0,'Ambiguous SKU excluded'); unset($s->products[2]);
expect(count($service->preview(array(input(9)),1,1)['changes'])===0,'Unknown SKU never creates');
$invalid=input(); $invalid['errors']=array('Missing SKU');
expect($service->preview(array($invalid),1,1)['rows'][0]['status']==='Invalid','Invalid excluded');
function batchOf($p) { return array('id'=>'batch','shop'=>1,'employee'=>3,'language'=>1,'created'=>time(),'status'=>'preview','changes'=>$p['changes']); }
$s->save(batchOf($p));
$s->products[1]['stock']=9; // untouched stock can change after preview
$service->execute('batch',1,3,false);
expect($s->products[1]['name']==='New' && $s->products[1]['price']==='20.000000' && $s->products[1]['stock']===9,'Apply only intended changes');
expect($s->batches['batch']['status']==='applied','Applied journal');
rejects(function()use($service){$service->execute('batch',1,3,false);},'Replay blocked');
$s->products[1]['stock']=11;
$service->execute('batch',1,3,true);
expect($s->products[1]['name']==='Original' && $s->products[1]['stock']===11,'Rollback preserves unrelated later stock changes');
expect($s->batches['batch']['status']==='rolled_back','Rollback journal');
rejects(function()use($service){$service->execute('batch',1,3,true);},'Rollback replay blocked');
$s->save(batchOf($p)); $s->products[1]['name']='Later edit';
rejects(function()use($service){$service->execute('batch',1,3,false);},'Stale apply rejected');
expect($s->products[1]['name']==='Later edit','Conflict leaves data untouched');
$s->products[1]['name']='Original'; $s->fail=true;
rejects(function()use($service){$service->execute('batch',1,3,false);},'Write failure rejected');
expect($s->batches['batch']['status']==='preview' && $s->products[1]['name']==='Original','Failed batch rolled back'); $s->fail=false;
$service->execute('batch',1,3,false); $s->products[1]['price']='21.000000';
rejects(function()use($service){$service->execute('batch',1,3,true);},'Rollback conflict rejected');
expect($s->products[1]['name']==='New','Rollback all-or-nothing preflight');
rejects(function()use($service){$service->execute('batch',2,3,true);},'Shop isolation');
rejects(function()use($service){$service->execute('batch',1,4,true);},'Employee isolation');
$s->save(batchOf($p));$s->batches['batch']['created']=time()-3601;
rejects(function()use($service){$service->execute('batch',1,3,false);},'Expired preview blocked');
echo "Update service checks passed\n";
// A second write failure must undo the first write and the batch transition.
class LateFailureStore extends MemoryStore {
 public function write($id,$shop,$lang,$values) { if ($id===2) { throw new RuntimeException('Second write failed'); } parent::write($id,$shop,$lang,$values); }
}
$late=new LateFailureStore();$late->products=array(1=>product(1),2=>product(2));$ls=new JsonProductImportService($late);
$lp=$ls->preview(array(input(1,'Changed one'),input(2,'Changed two')),1,1);$late->save(batchOf($lp));
rejects(function()use($ls){$ls->execute('batch',1,3,false);},'Late failure aborts');
expect($late->products[1]['name']==='Original' && $late->batches['batch']['status']==='preview','Earlier write reverted on late failure');
$zero=new MemoryStore();$zero->products[1]=product();$zs=new JsonProductImportService($zero);$zero->save(batchOf($zs->preview(array(input(1,'Original',19.95,0)),1,1)));
$zs->execute('batch',1,3,false);expect($zero->products[1]['stock']===0,'Zero applied');$zs->execute('batch',1,3,true);expect($zero->products[1]['stock']===7,'Zero rollback restores previous stock');
echo "Late failure and stock rollback checks passed\n";
