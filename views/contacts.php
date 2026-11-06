<?php
declare(strict_types=1);
$pdo=Database::pdo();
$q=trim((string)(get_q('q','')??''));
$sourceId=(string)(get_q('source','')??'');
$scope=(string)(get_q('scope','')??'');
$page=max(1,(int)Digits::only(Digits::normalize((string)(get_q('page','1')??'1'))));
$perPage=100;
$where=' WHERE 1=1';$params=[];
if($q!==''){$digits=Digits::only(Digits::normalize($q));$where.=' AND (c.phone LIKE ? OR n.number LIKE ?)';$params[]='%'.($digits?:$q).'%';$params[]='%'.($digits?:$q).'%';}
if($sourceId!==''&&ctype_digit($sourceId)){$where.=' AND o.source_id=?';$params[]=(int)$sourceId;}
if($scope!==''){$where.=' AND c.scope=?';$params[]=$scope;}
$sources=$pdo->query('SELECT id,name FROM sources ORDER BY name')->fetchAll();
$count=$pdo->prepare("SELECT COUNT(*) FROM offer_contacts c JOIN offers o ON o.id=c.offer_id JOIN sim_numbers n ON n.id=o.sim_number_id {$where}");$count->execute($params);$total=(int)$count->fetchColumn();
$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$offset=($page-1)*$perPage;
$stmt=$pdo->prepare("SELECT c.*,o.external_id,o.canonical_url,o.price_amount,o.price_type,o.availability,n.number AS sim_number,s.name AS source_name,s.slug AS source_slug FROM offer_contacts c JOIN offers o ON o.id=c.offer_id JOIN sim_numbers n ON n.id=o.sim_number_id JOIN sources s ON s.id=o.source_id {$where} ORDER BY c.phone ASC,o.id DESC LIMIT {$perPage} OFFSET {$offset}");$stmt->execute($params);$rows=$stmt->fetchAll();
$base=array_filter(['r'=>'contacts','q'=>$q,'source'=>$sourceId,'scope'=>$scope],static fn($v)=>$v!=='');$url=static fn(int $p):string=>'/?'.http_build_query(array_merge($base,['page'=>$p]));
ob_start();
?>
<section class="ui-page-head"><div><div class="eyebrow">CONTACT DIRECTORY · RONDINO</div><h1>دفتر شماره‌های تماس</h1><p>تمام شماره‌های ثبت‌شده در منابع، با امکان فیلتر و مراجعه به آگهی اصلی</p></div></section>
<section class="ui-stat-grid"><article class="ui-stat-card"><span>کل شماره‌های ثبت‌شده</span><strong><?=number_format($total)?></strong><small>پس از فیلتر</small></article><article class="ui-stat-card"><span>تعداد صفحات</span><strong><?=number_format($pages)?></strong><small>۱۰۰ ردیف در هر صفحه</small></article></section>
<section class="ui-panel"><form method="get" class="ui-filter-form"><input type="hidden" name="r" value="contacts">
<label class="ui-field ui-field-wide">جست‌وجوی شماره تماس یا سیم‌کارت<input class="ltr-num" name="q" value="<?=e($q)?>" placeholder="09…"></label>
<label class="ui-field">منبع<select name="source"><option value="">همه منابع</option><?php foreach($sources as $s):?><option value="<?=(int)$s['id']?>" <?=$sourceId===(string)$s['id']?'selected':''?>><?=e($s['name'])?></option><?php endforeach;?></select></label>
<label class="ui-field">نوع تماس<select name="scope"><option value="">همه</option><?php foreach(['seller'=>'فروشنده','listing'=>'آگهی','store'=>'فروشگاه'] as $k=>$v):?><option value="<?=e($k)?>" <?=$scope===$k?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></label>
<div class="ui-filter-actions"><button>جست‌وجو</button><a class="ui-button ui-button-ghost" href="/?r=contacts">پاک‌کردن</a></div></form></section>
<section class="ui-panel"><div class="ui-panel-heading"><h2>شماره‌های تماس</h2><span class="ui-results-caption"><?=number_format($total)?> ردیف</span></div>
<div class="ui-table-scroll"><table class="ui-data-table"><thead><tr><th>شماره تماس</th><th>شماره سیم‌کارت</th><th>منبع</th><th>نوع تماس</th><th>قیمت آگهی</th><th>آگهی</th></tr></thead><tbody>
<?php foreach($rows as $row):?><tr><td><a class="ui-phone-pill" href="tel:<?=e($row['phone'])?>"><?=ltr_phone($row['phone'])?></a></td><td><?=ltr_sim($row['sim_number'])?></td><td><a href="/?r=source&amp;slug=<?=e($row['source_slug'])?>"><?=e($row['source_name'])?></a></td><td><?=e(market_contact_label((string)$row['scope']))?></td><td><?=e(format_price_display($row['price_amount']!==null?(int)$row['price_amount']:null,$row['price_type']))?></td><td><a href="<?=e($row['canonical_url'])?>" target="_blank" rel="noopener">مشاهده ↗</a></td></tr><?php endforeach;?>
</tbody></table></div>
<?php if($pages>1):?><nav class="ui-pagination"><a class="<?=$page<=1?'disabled':''?>" href="<?=$page>1?e($url($page-1)):'#'?>">قبلی</a><?php for($p=max(1,$page-2);$p<=min($pages,$page+2);$p++):?><a class="<?=$p===$page?'active':''?>" href="<?=e($url($p))?>"><?=number_format($p)?></a><?php endfor;?><a class="<?=$page>=$pages?'disabled':''?>" href="<?=$page<$pages?e($url($page+1)):'#'?>">بعدی</a></nav><?php endif;?>
</section>
<?php $content=ob_get_clean();$title='دفتر تماس — روندینو';$active='contacts';require __DIR__.'/layout.php';
