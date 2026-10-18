<?php
require_once __DIR__.'/JsonProductImportJobInput.php';
/** Durable full-file jobs. One advisory lock serializes requests for each job. */
class JsonProductImportJobs
{
    private $db;private $store;private $shop;private $employee;private $language;
    public function __construct($store,$shop,$employee,$language){$this->db=Db::getInstance();$this->store=$store;$this->shop=(int)$shop;$this->employee=(int)$employee;$this->language=(int)$language;}
    private function rows($q){$r=$this->db->executeS($q,true,false);if($r===false){throw new RuntimeException('Job database lookup failed.');}return $r;}
    private function sql($q){if(!$this->db->execute($q)){throw new RuntimeException('Job database write failed.');}}
    private function table($name){return '`'._DB_PREFIX_.'jsonproductpreview_'.$name.'`';}
    public function setup()
    {
        $collation=$this->rows("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".pSQL(_DB_PREFIX_.'product')."' AND COLUMN_NAME='reference'");
        $collation=isset($collation[0]['COLLATION_NAME'])?$collation[0]['COLLATION_NAME']:'';
        if(!preg_match('/^[a-z0-9]+_[a-z0-9_]+$/D',$collation)){throw new RuntimeException('Cannot determine SKU collation.');}
        $charset=explode('_',$collation)[0];
        $this->sql('CREATE TABLE IF NOT EXISTS '.$this->table('job')." (id VARCHAR(48) PRIMARY KEY,id_shop INT UNSIGNED NOT NULL,id_employee INT UNSIGNED NOT NULL,id_lang INT UNSIGNED NOT NULL,filename VARCHAR(255) NOT NULL,file_hash CHAR(64) NOT NULL,total INT UNSIGNED NOT NULL,received INT UNSIGNED NOT NULL DEFAULT 0,status VARCHAR(20) NOT NULL,created_at DATETIME NOT NULL,KEY owner_jobs(id_shop,id_employee,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->sql('CREATE TABLE IF NOT EXISTS '.$this->table('chunk')." (job_id VARCHAR(48) NOT NULL,seq INT UNSIGNED NOT NULL,source_hash CHAR(64) NOT NULL,payload LONGTEXT NOT NULL,plan LONGTEXT NULL,batch_id VARCHAR(48) NULL,updates INT NOT NULL DEFAULT 0,unchanged INT NOT NULL DEFAULT 0,skipped INT NOT NULL DEFAULT 0,invalid INT NOT NULL DEFAULT 0,PRIMARY KEY(job_id,seq),KEY batch_id(batch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->sql('CREATE TABLE IF NOT EXISTS '.$this->table('ref')." (job_id VARCHAR(48) NOT NULL,ordinal INT UNSIGNED NOT NULL,reference VARCHAR(64) CHARACTER SET $charset COLLATE $collation NOT NULL,duplicate TINYINT NOT NULL DEFAULT 0,PRIMARY KEY(job_id,ordinal),KEY sku(job_id,reference)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    private function job($id)
    {
        if(!is_string($id)||!preg_match('/^[a-f0-9]{48}$/D',$id)){throw new InvalidArgumentException('Invalid job ID.');}
        $r=$this->rows('SELECT * FROM '.$this->table('job')." WHERE id='".pSQL($id)."' AND id_shop=$this->shop AND id_employee=$this->employee");
        if(count($r)!==1){throw new RuntimeException('Job unavailable for this employee and shop.');}return $r[0];
    }
    private function state($id,$state){$this->sql('UPDATE '.$this->table('job')." SET status='".pSQL($state)."' WHERE id='".pSQL($id)."'");}
    public function summary($id)
    {
        $j=$this->job($id);
        $r=$this->rows('SELECT COUNT(*) chunks,SUM(c.plan IS NOT NULL) previewed,COALESCE(SUM(c.updates),0) updates,COALESCE(SUM(c.unchanged),0) unchanged,COALESCE(SUM(c.skipped),0) skipped,COALESCE(SUM(c.invalid),0) invalid,COALESCE(SUM(b.status="applied"),0) applied_batches,COALESCE(SUM(IF(b.status="applied",c.updates,0)),0) applied_products,COALESCE(SUM(b.status="rolled_back"),0) rolled_back_batches FROM '.$this->table('chunk').' c LEFT JOIN '.$this->table('batch')." b ON b.id=c.batch_id WHERE c.job_id='".pSQL($id)."'");
        return array_merge($j,$r[0]);
    }
    public function dispatch($action,$args)
    {
        if($action==='list'){
            $rows=$this->rows('SELECT id,filename,status,created_at FROM '.$this->table('job')." WHERE id_shop=$this->shop AND id_employee=$this->employee ORDER BY created_at DESC,id DESC LIMIT 20");return array('jobs'=>$rows);
        }
        if($action==='create'){
            $total=(int)($args['total']??0);$hash=$args['hash']??'';$name=$args['filename']??'';
            if($total<1||$total>100000||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||!is_string($name)||strlen($name)>255){throw new InvalidArgumentException('Invalid file metadata. Maximum 100,000 records.');}
            $id=bin2hex(random_bytes(24));
            $this->sql('INSERT INTO '.$this->table('job')." (id,id_shop,id_employee,id_lang,filename,file_hash,total,status,created_at) VALUES ('$id',$this->shop,$this->employee,$this->language,'".pSQL($name)."','$hash',$total,'uploading',NOW())");return array('job'=>$this->summary($id));
        }
        $id=$args['job']??'';$j=$this->job($id);
        if($action==='status'){return array('job'=>$this->summary($id));}
        if($action==='page'){
            $seq=max(0,(int)($args['seq']??0));
            $r=$this->rows('SELECT seq,plan,batch_id FROM '.$this->table('chunk')." WHERE job_id='$id' AND seq=$seq");
            $plan=$r && $r[0]['plan']!==null?json_decode($r[0]['plan'],true,512,JSON_THROW_ON_ERROR):null;
            if($plan){foreach($plan['rows'] as &$row){if(preg_match('/^Update #(\d+)$/D',$row['status'],$m)){$row['url']=Context::getContext()->link->getAdminLink('AdminProducts',true,array('id_product'=>(int)$m[1],'updateproduct'=>1));}}unset($row);}
            return array('job'=>$this->summary($id),'page'=>$plan,'seq'=>$seq);
        }
        $lock=$this->rows("SELECT GET_LOCK('jpp_$id',0) acquired");
        if((int)$lock[0]['acquired']!==1){throw new RuntimeException('This job is busy in another request. Wait, then resume.');}
        try{
            $j=$this->job($id);
            switch($action){
                case 'upload':$this->upload($j,$args);break;
                case 'finish':$this->finish($j);break;
                case 'preview':$this->preview($j);break;
                case 'apply':$this->applyOrRollback($j,false);break;
                case 'rollback':$this->applyOrRollback($j,true);break;
                default:throw new InvalidArgumentException('Unknown job action.');
            }
            return array('job'=>$this->summary($id));
        }finally{$this->rows("SELECT RELEASE_LOCK('jpp_$id') released");}
    }
    private function upload($j,$args)
    {
        if($j['status']!=='uploading'){throw new RuntimeException('Upload is already closed.');}
        $offset=(int)($args['offset']??-1);$raw=$args['rows']??'';
        $chunks=JsonProductImportJobInput::chunks($raw,$offset,(int)$j['total']);$id=$j['id'];
        if($offset>(int)$j['received']){throw new RuntimeException('Upload gap. Resume from the recorded offset.');}
        $this->sql('START TRANSACTION');
        try{
            $count=0;
            foreach($chunks as $n=>$chunk){
                $seq=(int)($offset/100)+$n;$payload=json_encode($chunk,JSON_THROW_ON_ERROR);$hash=hash('sha256',$payload);
                $existing=$this->rows('SELECT source_hash FROM '.$this->table('chunk')." WHERE job_id='$id' AND seq=$seq");
                if($existing){if(!hash_equals($existing[0]['source_hash'],$hash)){throw new RuntimeException('Upload retry differs from saved data. Start a new job.');}}
                else{
                    $this->sql('INSERT INTO '.$this->table('chunk')." (job_id,seq,source_hash,payload) VALUES ('$id',$seq,'$hash','".pSQL($payload,true)."')");
                    $refs=array();
                    foreach($chunk as $k=>$row){$ref=$row['data']['reference']??null;if(is_string($ref)&&strlen($ref)<=256&&preg_match_all('/./us',$ref)<=64){$refs[]="('$id',".($seq*100+$k).",'".pSQL($ref)."')";}}
                    if($refs){$this->sql('INSERT INTO '.$this->table('ref').' (job_id,ordinal,reference) VALUES '.implode(',',$refs));}
                }
                $count+=count($chunk);
            }
            $received=max((int)$j['received'],$offset+$count);
            $this->sql('UPDATE '.$this->table('job')." SET received=$received WHERE id='$id'");$this->sql('COMMIT');
        }catch(Throwable $e){$this->sql('ROLLBACK');throw $e;}
    }
    private function finish($j)
    {
        if($j['status']!=='uploading'){return;}
        if((int)$j['received']!==(int)$j['total']){throw new RuntimeException('Upload incomplete. Reselect the same file to resume.');}
        $id=$j['id'];
        $this->sql('UPDATE '.$this->table('ref').' r JOIN (SELECT job_id,reference FROM '.$this->table('ref')." WHERE job_id='$id' GROUP BY job_id,reference HAVING COUNT(*)>1) d ON d.job_id=r.job_id AND d.reference=r.reference SET r.duplicate=1 WHERE r.job_id='$id'");
        $this->state($id,'ready');
    }
    private function preview($j)
    {
        if($j['status']==='previewed'){return;}
        if(!in_array($j['status'],array('ready','previewing'),true)){throw new RuntimeException('This job cannot be previewed in its current state.');}
        $id=$j['id'];$this->state($id,'previewing');$start=microtime(true);
        // Bound each request by five chunks and an approximate four-second work budget.
        for($n=0;$n<5;$n++){
            $r=$this->rows('SELECT seq,payload FROM '.$this->table('chunk')." WHERE job_id='$id' AND plan IS NULL ORDER BY seq LIMIT 1");
            if(!$r){$this->state($id,'previewed');break;}
            $seq=(int)$r[0]['seq'];$rows=json_decode($r[0]['payload'],true,512,JSON_THROW_ON_ERROR);
            $dupes=$this->rows('SELECT ordinal FROM '.$this->table('ref')." WHERE job_id='$id' AND duplicate=1 AND ordinal>=".($seq*100).' AND ordinal<'.(($seq+1)*100));
            foreach($dupes as $d){$rows[(int)$d['ordinal']-$seq*100]['errors'][]='Duplicate SKU in this full file; every occurrence is excluded.';}
            $service=new JsonProductImportService($this->store);$plan=$service->preview($rows,$this->shop,(int)$j['id_lang']);
            $counts=array('unchanged'=>0,'skipped'=>0,'invalid'=>0);
            foreach($plan['rows'] as &$row){$row['row']+=$seq*100;$key=strtolower($row['status']);if(isset($counts[$key])){++$counts[$key];}}unset($row);
            $this->sql('START TRANSACTION');
            try{
                $batchId='NULL';
                if($plan['changes']){$b=$this->store->create($plan['changes'],$this->shop,$this->employee,(int)$j['id_lang']);$b['job_id']=$id;$b['expires']=time()+86400;$this->store->save($b);$batchId="'".$b['id']."'";}
                $this->sql('UPDATE '.$this->table('chunk')." SET plan='".pSQL(json_encode($plan,JSON_THROW_ON_ERROR),true)."',batch_id=$batchId,updates=".count($plan['changes']).',unchanged='.$counts['unchanged'].',skipped='.$counts['skipped'].',invalid='.$counts['invalid']." WHERE job_id='$id' AND seq=$seq");
                $this->sql('COMMIT');
            }catch(Throwable $e){$this->sql('ROLLBACK');throw $e;}
            if(microtime(true)-$start>4){break;}
        }
    }
    private function applyOrRollback($j,$rollback)
    {
        $id=$j['id'];
        if($rollback){
            if($j['status']==='rolled_back'){return;}
            if(!in_array($j['status'],array('applying','applied','rolling_back'),true)){throw new RuntimeException('No applied job to roll back.');}
            $this->state($id,'rolling_back');$want='applied';$direction='DESC';
        }else{
            if($j['status']==='applied'){return;}
            if(!in_array($j['status'],array('previewed','applying'),true)){throw new RuntimeException('Finish the entire preview before applying.');}
            $this->state($id,'applying');$want='preview';$direction='ASC';
        }
        $r=$this->rows('SELECT c.batch_id FROM '.$this->table('chunk').' c INNER JOIN '.$this->table('batch')." b ON b.id=c.batch_id WHERE c.job_id='$id' AND b.status='$want' ORDER BY c.seq $direction LIMIT 1");
        if(!$r){$this->state($id,$rollback?'rolled_back':'applied');return;}
        $service=new JsonProductImportService($this->store);$result=$service->execute($r[0]['batch_id'],$this->shop,$this->employee,$rollback);
        if($result['warning']){throw new RuntimeException($result['warning'].' The batch committed; refresh status before continuing.');}
    }
}
