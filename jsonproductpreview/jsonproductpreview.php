<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__.'/classes/JsonProductPreviewValidator.php';
require_once __DIR__.'/classes/JsonProductImportService.php';
require_once __DIR__.'/classes/JsonProductImportStore.php';
require_once __DIR__.'/classes/JsonProductImportJobs.php';

class Jsonproductpreview extends Module
{
    public function __construct()
    {
        $this->name='jsonproductpreview';$this->tab='administration';$this->version='0.4.0';
        $this->author='Sandbox Tools';$this->bootstrap=true;$this->need_instance=0;
        $this->ps_versions_compliancy=array('min'=>'8.2.0','max'=>'8.99.99');
        parent::__construct();
        $this->displayName='JSON Product Importer';
        $this->description='Preview actual product differences, apply sandbox updates, and roll back recorded changes.';
    }
    private function esc($v) { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    private function formStart()
    {
        return '<form method="post" action="'.$this->esc($this->context->link->getAdminLink('AdminModules',true).'&configure='.$this->name).'"><input type="hidden" name="preview_token" value="'.$this->esc(Tools::getAdminTokenLite('AdminModules')).'">';
    }
    private function button($batch,$rollback)
    {
        return $this->formStart().'<input type="hidden" name="batch_id" value="'.$this->esc($batch['id']).'"><button class="btn '.($rollback?'btn-default':'btn-primary').'" name="'.($rollback?'rollbackJsonProducts':'applyJsonProducts').'" value="1" type="submit">'.($rollback?'Roll back this batch':'Apply '.count($batch['changes']).' product updates').'</button></form>';
    }
    private function productLink($change)
    {
        $url=$this->context->link->getAdminLink('AdminProducts',true,array('id_product'=>(int)$change['id'],'updateproduct'=>1));
        return '<a href="'.$this->esc($url).'" target="_blank" rel="noopener noreferrer">'.$this->esc($change['reference']).' (#'.(int)$change['id'].') — Edit product</a>';
    }
    private function productLinks($changes)
    {
        $html='<ul>';
        foreach ($changes as $change) { $html.='<li>'.$this->productLink($change).'</li>'; }
        return $html.'</ul>';
    }
    private function diff($before,$after)
    {
        $html='';
        foreach ($after as $field=>$value) {
            $html.='<div><strong>'.$this->esc(ucfirst($field)).':</strong> <del style="white-space:pre-wrap">'.$this->esc($before[$field]).'</del> → <strong style="white-space:pre-wrap">'.$this->esc($value).'</strong></div>';
            if ($field==='name' && preg_replace('/\s+/u',' ',trim($before[$field]))===preg_replace('/\s+/u',' ',trim($value))) { $html.='<div class="help-block">Whitespace change — repeated or surrounding spaces differ.</div>'; }
        }
        return $html;
    }
    private function jobsPanel()
    {
        $endpoint=$this->context->link->getAdminLink('AdminModules',true).'&configure='.$this->name;
        return '<section id="jpp-jobs" data-endpoint="'.$this->esc($endpoint).'" data-token="'.$this->esc(Tools::getAdminTokenLite('AdminModules')).'"><h3>Full-file import jobs</h3><p>Up to 64 MiB / 100,000 records. Upload and preview do not change products. Apply uses 100-record transactions; pause and resume at batch boundaries. Rollback reverses applied batches and stops on conflicts. Job previews expire after 24 hours.</p><label>Full JSON file <input type="file" data-ui="file" accept=".json,application/json"></label> <button type="button" class="btn btn-primary" data-ui="upload">Upload and preview full file</button> <button type="button" class="btn btn-default" data-ui="new">New job</button><p><label>Saved jobs <select data-ui="jobs"></select></label> <button type="button" class="btn btn-default" data-ui="load">Load job</button></p><div data-ui="status" role="status" aria-live="polite">Choose a file or load a saved job.</div><p data-ui="summary"></p><p><button type="button" class="btn btn-default" data-ui="preview">Resume preview</button> <button type="button" class="btn btn-primary" data-ui="apply">Apply reviewed updates</button> <button type="button" class="btn btn-default" data-ui="rollback">Roll back job</button> <button type="button" class="btn btn-default" data-ui="stop">Pause</button></p><p><button type="button" class="btn btn-default" data-ui="previous" disabled>Previous batch</button> <span data-ui="page-label"></span> <button type="button" class="btn btn-default" data-ui="next" disabled>Next batch</button></p><div class="table-responsive"><table class="table"><thead><tr><th>Row / SKU</th><th>Result</th><th>Actual differences</th></tr></thead><tbody data-ui="rows"></tbody></table></div><p>Normalized input, previews and rollback originals are retained in the database. Rollback is atomic per batch, not for the whole file; previously completed batches remain committed if a later batch conflicts.</p></section><script src="'.$this->esc($this->_path.'views/js/jobs.js?v=0.4.0').'" defer></script><hr>';
    }
    public function getContent()
    {
        $employee=$this->context->employee;
        if (!$employee || !$employee->isLoggedBack()) { return $this->displayError('Administrator login required.'); }
        $html='<div class="panel"><h2>JSON Product Importer <small>0.4.0 · sandbox</small></h2>';
        if (Shop::getContext()!==Shop::CONTEXT_SHOP) { return $html.'<p>Select one shop above, then reopen Configure.</p></div>'; }
        $shop=(int)$this->context->shop->id;
        $domain=Db::getInstance()->getValue('SELECT domain FROM `'._DB_PREFIX_.'shop_url` WHERE id_shop='.$shop.' AND main=1',false);
        if ($domain!=='test.4shooters.com' || strtolower(explode(':',isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'')[0])!=='test.4shooters.com') {
            return $html.$this->displayError('This build only runs in the test.4shooters.com sandbox shop.').'</div>';
        }
        if (!$this->getPermission('configure',$employee) || ((int)$employee->id_profile!==(int)_PS_ADMIN_PROFILE_ && !Access::isGranted('ROLE_MOD_TAB_ADMINPRODUCTS_UPDATE',$employee->id_profile))) {
            return $html.$this->displayError('Module configuration and product edit permissions are required.').'</div>';
        }
        $language=(int)Configuration::get('PS_LANG_DEFAULT',null,null,$shop);
        $currency=new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT',null,null,$shop));
        $html.='<p>Shop: <strong>'.$this->esc($this->context->shop->name).'</strong> (ID '.$shop.'). Existing products only. Names: default language '.$language.'. Prices: '.$this->esc($currency->iso_code).' base price, used directly; checkout tax rules stay unchanged.</p><p>Preview makes no catalog changes. Apply updates only the differences listed below. Missing stock stays unchanged. Each applied batch records its original values for rollback.</p>';
        $store=new JsonProductImportStore();$service=new JsonProductImportService($store);
        $jobAction=Tools::getValue('jpp_job_action');
        try {
            $store->setup();
            if ($jobAction) {
                try {
                    $token=Tools::getValue('preview_token');
                    if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || !is_string($token) || !hash_equals(Tools::getAdminTokenLite('AdminModules'),$token)) { throw new RuntimeException('Invalid session token. Reload Configure.'); }
                    $jobs=new JsonProductImportJobs($store,$shop,(int)$employee->id,$language);$jobs->setup();
                    $response=array_merge(array('ok'=>true),$jobs->dispatch($jobAction,$_POST));
                } catch (Throwable $e) { $response=array('ok'=>false,'error'=>$e->getMessage()); }
                header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
                echo json_encode($response,JSON_INVALID_UTF8_SUBSTITUTE);exit;
            }
            $html.=$this->jobsPanel();
            $preview=Tools::isSubmit('previewJsonProducts');$apply=Tools::isSubmit('applyJsonProducts');$rollback=Tools::isSubmit('rollbackJsonProducts');
            if ($preview || $apply || $rollback) {
                $token=Tools::getValue('preview_token');
                if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || !is_string($token) || !hash_equals(Tools::getAdminTokenLite('AdminModules'),$token) || ((int)$preview+(int)$apply+(int)$rollback)!==1) { throw new RuntimeException('Invalid request. Reopen Configure and try again.'); }
                if ($apply || $rollback) {
                    $selectedBatch=$store->batch(Tools::getValue('batch_id'),$shop,(int)$employee->id);
                    if(isset($selectedBatch['job_id'])) { throw new RuntimeException('Use the full-file job controls for this batch.'); }
                    $result=$service->execute(Tools::getValue('batch_id'),$shop,(int)$employee->id,$rollback);
                    $html.='<div class="alert alert-success">'.($rollback?'Rollback complete: original values restored for ':'Applied updates to ').count($result['batch']['changes']).' product(s).'.$this->productLinks($result['batch']['changes']).'</div>';
                    if ($result['warning']) { $html.='<div class="alert alert-warning">'.$this->esc($result['warning']).'</div>'; }
                } else {
                    $file=isset($_FILES['products_json'])?$_FILES['products_json']:null;
                    if (!$file || !isset($file['error'],$file['tmp_name'],$file['size']) || $file['error']!==UPLOAD_ERR_OK || $file['size']>1048576 || !is_uploaded_file($file['tmp_name'])) { throw new RuntimeException('Upload a JSON file of at most 1 MiB.'); }
                    $rows=JsonProductPreviewValidator::parse(file_get_contents($file['tmp_name'],false,null,0,1048577));
                    $plan=$service->preview($rows,$shop,$language);
                    $counts=array('Unchanged'=>0,'Skipped'=>0,'Invalid'=>0);
                    foreach ($plan['rows'] as $r) { if(isset($counts[$r['status']])) {++$counts[$r['status']];} }
                    $html.='<div class="alert alert-info"><strong>'.count($rows).' records: '.count($plan['changes']).' updates, '.$counts['Unchanged'].' unchanged, '.$counts['Skipped'].' skipped, '.$counts['Invalid'].' invalid.</strong> Nothing imported.</div>';
                    $html.='<div class="table-responsive"><table class="table"><thead><tr><th>Row / SKU</th><th>Result</th><th>Actual differences</th></tr></thead><tbody>';
                    foreach ($plan['rows'] as $r) {
                        $html.='<tr><td>'.$r['row'].' / '.$this->esc($r['reference']).'</td><td>'.$this->esc($r['status']).'</td><td>'.(isset($r['after'])?$this->diff($r['before'],$r['after']):$this->esc($r['detail']));
                        if ($r['notes']) { $html.='<div class="help-block">'.$this->esc(implode(' ',$r['notes'])).'</div>'; }
                        $html.='</td></tr>';
                    }
                    $html.='</tbody></table></div>';
                    if ($plan['changes']) {
                        $batch=$store->create($plan['changes'],$shop,(int)$employee->id,$language);
                        $html.='<p>Only the '.count($plan['changes']).' changed, eligible products above will be updated. This preview expires in one hour. Apply stops if any changed field or SKU match has changed.</p>'.$this->button($batch,false);
                    } else { $html.='<p><strong>No eligible differences to apply.</strong></p>'; }
                }
            }
            $html.='<hr><h3>Preview a JSON file</h3>'.str_replace('<form method="post"','<form enctype="multipart/form-data" method="post"',$this->formStart()).'<label for="products_json">JSON file (1 MiB, 100 records maximum)</label><input id="products_json" name="products_json" type="file" accept=".json,application/json" required><br><button class="btn btn-primary" name="previewJsonProducts" value="1" type="submit">Validate and preview</button></form><p>Accepts reference/name/price/stock or sku/title/retail_price/suppliers. Single-supplier numeric stock is an absolute quantity; multiple suppliers and availability flags leave stock unchanged. New products, combinations and ambiguous SKUs are skipped.</p>';
            $html.='<hr><h3>Recent batches</h3><p>Last 10 batches for your account in this shop. Rollback restores only fields changed by that batch and stops if those fields have since changed. History is retained when the module is uninstalled.</p>';
            $history=$store->history($shop,(int)$employee->id);
            if (!$history) { $html.='<p>No batches yet.</p>'; }
            foreach ($history as $b) {
                $html.='<details><summary><strong>'.$this->esc($b['status']).'</strong> · '.count($b['changes']).' product(s) · '.$this->esc(gmdate('Y-m-d H:i:s',$b['created'])).' UTC · '.$this->esc(substr($b['id'],0,8)).'</summary><div style="padding:12px">';
                foreach ($b['changes'] as $c) { $html.='<p><strong>'.$this->productLink($c).'</strong></p>'.$this->diff($c['before'],$c['after']); }
                if (isset($b['job_id'])) { $html.='<p>Managed by full-file job '. $this->esc(substr($b['job_id'],0,8)).'. Use the job controls above.</p>'; }
                elseif ($b['status']==='applied') { $html.=$this->button($b,true); }
                elseif ($b['status']==='preview') { $html.='<p>Not applied. Upload the file again to review a fresh preview.</p>'; }
                $html.='</div></details>';
            }
        } catch (InvalidArgumentException $e) { $html.=$this->displayError($this->esc($e->getMessage())); }
        catch (RuntimeException $e) { $html.=$this->displayError($this->esc($e->getMessage())); }
        catch (Throwable $e) {
            PrestaShopLogger::addLog('JSON Product Importer: '.$e->getMessage(),3);
            $html.=$this->displayError('Operation failed. Check the batch history before retrying; details are in the administrator logs.');
        }
        return $html.'</div>';
    }
}
