<?php
/** Pure change planning and guarded, transactional application. */
class JsonProductImportService
{
    private $store;
    public function __construct($store) { $this->store = $store; }
    public static function value($field, $value)
    {
        if ($field === 'price') { return number_format((float)$value, 6, '.', ''); }
        if ($field === 'stock') { return (int)$value; }
        return (string)$value;
    }
    public function preview($input, $shop, $language)
    {
        $result = array('rows'=>array(), 'changes'=>array());
        $ids = array();
        foreach ($input as $index=>$row) {
            $data=$row['data'];
            $ref=isset($data['reference']) && is_string($data['reference']) ? $data['reference'] : '';
            $view=array('row'=>$index+1,'reference'=>$ref,'status'=>'Invalid','detail'=>implode(' ', $row['errors']),'notes'=>$row['notes']);
            if (!$row['errors']) {
                $matches=$this->store->lookup($ref,$shop,$language);
                $view['status']='Skipped';
                $view['detail']='No matching product in this shop. Creation is disabled.';
                if (count($matches)>1) { $view['detail']='Multiple products match this SKU.'; }
                elseif (count($matches)===1) {
                    $p=$matches[0];
                    $view['detail']=$p['blocked'];
                    if (!$p['blocked']) {
                        $before=array();$after=array();
                        foreach (array('name','price','stock') as $field) {
                            if ($field==='stock' && $data[$field]===null) { continue; }
                            $a=self::value($field,$data[$field]); $b=self::value($field,$p[$field]);
                            if ($a!==$b) { $before[$field]=$b; $after[$field]=$a; }
                        }
                        if (isset($after['stock']) && $p['stock_blocked']) { $view['detail']=$p['stock_blocked']; }
                        elseif (isset($ids[$p['id']])) { $view['detail']='This product was already matched by another input row.'; }
                        elseif (!$after) { $view['status']='Unchanged';$view['detail']='No differences. Missing stock stays unchanged.'; }
                        else {
                            $ids[$p['id']]=true;
                            $view['status']='Update #'.$p['id'];$view['detail']='';
                            $view['before']=$before;$view['after']=$after;
                            $result['changes'][]=array('id'=>(int)$p['id'],'reference'=>$ref,'before'=>$before,'after'=>$after);
                        }
                    }
                }
            }
            $result['rows'][]=$view;
        }
        return $result;
    }
    public function execute($id,$shop,$employee,$rollback)
    {
        $this->store->begin();
        try {
            $batch=$this->store->batch($id,$shop,$employee,true);
            if ($batch['status']!==($rollback?'applied':'preview')) { throw new RuntimeException('This batch has already been processed or is not available for this action.'); }
            if (!$rollback && time()-(int)$batch['created']>3600) { throw new RuntimeException('Preview expired. Upload again.'); }
            if (!$batch['changes']) { throw new RuntimeException('No changes to apply.'); }
            // Lock in deterministic order to reduce deadlocks. Check every row before writing any.
            usort($batch['changes'],function($a,$b){return $a['id']<=>$b['id'];});
            foreach ($batch['changes'] as $change) {
                $matches=$this->store->lookup($change['reference'],$shop,$batch['language'],true);
                if (count($matches)!==1 || (int)$matches[0]['id']!==$change['id'] || $matches[0]['blocked']) {
                    throw new RuntimeException('Product identity or eligibility changed for '.$change['reference'].'. Refresh the preview.');
                }
                $current=$matches[0];
                $expected=$rollback?$change['after']:$change['before'];
                if (isset($expected['stock']) && $current['stock_blocked']) { throw new RuntimeException('Stock is no longer eligible for '.$change['reference'].'.'); }
                foreach ($expected as $field=>$value) {
                    if (self::value($field,$current[$field])!==self::value($field,$value)) { throw new RuntimeException('Conflict: '.$change['reference'].' '.$field.' changed since '.($rollback?'apply':'preview').'. Nothing in this batch was changed.'); }
                }
            }
            foreach ($batch['changes'] as $change) {
                $target=$rollback?$change['before']:$change['after'];
                $this->store->write($change['id'],$shop,$batch['language'],$target);
                $read=$this->store->lookup($change['reference'],$shop,$batch['language'],true);
                if (count($read)!==1) { throw new RuntimeException('Post-update verification failed.'); }
                foreach ($target as $field=>$value) {
                    if (self::value($field,$read[0][$field])!==self::value($field,$value)) { throw new RuntimeException('Post-update verification failed for '.$change['reference'].'.'); }
                }
            }
            $batch['status']=$rollback?'rolled_back':'applied';
            $batch['updated']=time();
            $this->store->save($batch);
            $this->store->commit();
        } catch (Throwable $e) { $this->store->rollback(); throw $e; }
        // Catalog is committed: report maintenance failures separately, never claim it rolled back.
        try { $warning=$this->store->afterCommit($batch); }
        catch (Throwable $e) { $warning='Catalog saved, but cache/search maintenance failed. Ask an administrator to rebuild the search index and clear cache.'; }
        return array('batch'=>$batch,'warning'=>$warning);
    }
}
