<?php
/** PrestaShop persistence. Catalog writes and journal transition use one DB connection. */
class JsonProductImportStore
{
    private $db;
    public function __construct() { $this->db=Db::getInstance(); }
    private function sql($sql) { if (!$this->db->execute($sql)) { throw new RuntimeException('Database operation failed. No successful batch was recorded.'); } }
    private function rows($sql) { $rows=$this->db->executeS($sql,true,false); if ($rows===false) { throw new RuntimeException('Database lookup failed.'); } return $rows; }
    public function setup()
    {
        $this->sql('CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'jsonproductpreview_batch` (
            id VARCHAR(64) NOT NULL PRIMARY KEY, id_shop INT UNSIGNED NOT NULL,
            id_employee INT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL, payload LONGTEXT NOT NULL,
            KEY owner_history(id_shop,id_employee,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    public function begin()
    {
        foreach (array('product','product_shop','product_lang','stock_available','jsonproductpreview_batch') as $table) {
            $r=$this->rows("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".pSQL(_DB_PREFIX_.$table)."'");
            if (count($r)!==1 || strtoupper($r[0]['ENGINE'])!=='INNODB') { throw new RuntimeException('Apply requires InnoDB tables for reliable rollback.'); }
        }
        $this->sql('START TRANSACTION');
    }
    public function commit() { $this->sql('COMMIT'); }
    public function rollback() { $this->sql('ROLLBACK'); }
    public function lookup($reference,$shop,$lang,$lock=false)
    {
        $suffix=$lock?' FOR UPDATE':'';
        $rows=$this->rows('SELECT p.id_product AS id,p.reference,p.id_shop_default,p.price AS base_price,p.advanced_stock_management,ps.price,ps.unit_price,pl.name
            FROM `'._DB_PREFIX_.'product` p INNER JOIN `'._DB_PREFIX_.'product_shop` ps ON ps.id_product=p.id_product AND ps.id_shop='.(int)$shop.'
            LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON pl.id_product=p.id_product AND pl.id_shop='.(int)$shop.' AND pl.id_lang='.(int)$lang.
            " WHERE p.reference='".pSQL($reference)."'".$suffix);
        foreach ($rows as &$p) {
            $id=(int)$p['id'];$p['blocked']='';$p['stock_blocked']='';$p['stock']=0;
            $attrs=$this->rows('SELECT id_product_attribute FROM `'._DB_PREFIX_.'product_attribute` WHERE id_product='.$id.$suffix);
            if ($attrs) { $p['blocked']='Product has combinations; updates are excluded.'; }
            if ($p['name']===null) { $p['blocked']='Default-language product name is missing.'; }
            if ((float)$p['unit_price']!=0) { $p['blocked']='Product uses unit pricing; administrator review required.'; }
            if ((int)$p['id_shop_default']===(int)$shop && JsonProductImportService::value('price',$p['base_price'])!==JsonProductImportService::value('price',$p['price'])) {
                $p['blocked']='Base price and default-shop price disagree; administrator review required.';
            }
            $stock=$this->rows('SELECT id_stock_available,quantity,id_shop,depends_on_stock FROM `'._DB_PREFIX_.'stock_available` WHERE id_product='.$id.' AND id_product_attribute=0 AND (id_shop='.(int)$shop.' OR (id_shop=0 AND id_shop_group='.(int)Context::getContext()->shop->id_shop_group.'))'.$suffix);
            if (count($stock)===1) { $p['stock']=(int)$stock[0]['quantity']; }
            if (count($stock)!==1 || (int)$stock[0]['id_shop']!==(int)$shop || $stock[0]['depends_on_stock'] || $p['advanced_stock_management']) { $p['stock_blocked']='Stock is shared, managed, or missing; this row needs manual review.'; }
            $packs=$this->rows('SELECT id_product_pack FROM `'._DB_PREFIX_.'pack` WHERE id_product_pack='.$id.' OR id_product_item='.$id.$suffix);
            if ($packs) { $p['stock_blocked']='Pack or pack component stock requires manual review.'; }
        }
        unset($p);
        return $rows;
    }
    public function create($changes,$shop,$employee,$language)
    {
        $b=array('id'=>bin2hex(random_bytes(24)),'shop'=>(int)$shop,'employee'=>(int)$employee,'language'=>(int)$language,'created'=>time(),'status'=>'preview','changes'=>$changes);
        $payload=json_encode($b,JSON_THROW_ON_ERROR);
        $this->sql('INSERT INTO `'._DB_PREFIX_."jsonproductpreview_batch` (id,id_shop,id_employee,status,created_at,payload) VALUES ('".pSQL($b['id'])."',".(int)$shop.','.(int)$employee.",'preview',NOW(),'".pSQL($payload,true)."')");
        return $b;
    }
    public function batch($id,$shop,$employee,$lock=false)
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{48}$/D',$id)) { throw new RuntimeException('Invalid batch.'); }
        $rows=$this->rows('SELECT payload FROM `'._DB_PREFIX_."jsonproductpreview_batch` WHERE id='".pSQL($id)."' AND id_shop=".(int)$shop.' AND id_employee='.(int)$employee.($lock?' FOR UPDATE':''));
        if (count($rows)!==1) { throw new RuntimeException('Batch not found for this employee and shop.'); }
        return json_decode($rows[0]['payload'],true,512,JSON_THROW_ON_ERROR);
    }
    public function save($b)
    {
        $this->sql('UPDATE `'._DB_PREFIX_."jsonproductpreview_batch` SET status='".pSQL($b['status'])."',payload='".pSQL(json_encode($b,JSON_THROW_ON_ERROR),true)."' WHERE id='".pSQL($b['id'])."' AND id_shop=".(int)$b['shop'].' AND id_employee='.(int)$b['employee']);
    }
    public function history($shop,$employee)
    {
        $rows=$this->rows('SELECT payload FROM `'._DB_PREFIX_.'jsonproductpreview_batch` WHERE id_shop='.(int)$shop.' AND id_employee='.(int)$employee.' ORDER BY created_at DESC,id DESC LIMIT 10');
        return array_map(function($r){return json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);},$rows);
    }
    public function write($id,$shop,$lang,$values)
    {
        $id=(int)$id;$shop=(int)$shop;$lang=(int)$lang;
        // Deliberately avoid Product::update: it updates unit ratios across all shops and fires broad save hooks.
        if (isset($values['name'])) {
            $this->sql('UPDATE `'._DB_PREFIX_."product_lang` SET name='".pSQL($values['name'])."' WHERE id_product=$id AND id_shop=$shop AND id_lang=$lang");
        }
        if (isset($values['price'])) {
            $price=JsonProductImportService::value('price',$values['price']);
            $this->sql('UPDATE `'._DB_PREFIX_."product_shop` SET price='$price' WHERE id_product=$id AND id_shop=$shop");
            $this->sql('UPDATE `'._DB_PREFIX_."product` SET price='$price' WHERE id_product=$id AND id_shop_default=$shop");
        }
        if (array_key_exists('stock',$values)) {
            if (StockAvailable::setQuantity($id,0,(int)$values['stock'],$shop,false)===false) { throw new RuntimeException('Stock update failed.'); }
        }
        $this->sql('UPDATE `'._DB_PREFIX_."product_shop` SET date_upd=NOW() WHERE id_product=$id AND id_shop=$shop");
        $this->sql('UPDATE `'._DB_PREFIX_."product` SET date_upd=NOW() WHERE id_product=$id");
    }
    public function afterCommit($batch)
    {
        Cache::clean('Product::*');Cache::clean('StockAvailable::*');
        Product::flushPriceCache();
        $warning='';
        foreach ($batch['changes'] as $change) {
            $p=new Product($change['id'],false,null,$batch['shop']); $p->clearCache();
            if (isset($change['after']['name']) && Search::indexation(false,$change['id'])===false) { $warning='Catalog saved; search index rebuild is required.'; }
        }
        Tools::clearSmartyCache();
        return $warning;
    }
}
