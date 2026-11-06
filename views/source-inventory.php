<?php
declare(strict_types=1);
$pdo = Database::pdo();
$slug = (string) (get_q('slug', '') ?? '');
$sourceStmt = $pdo->prepare('SELECT * FROM sources WHERE slug = ? LIMIT 1');
$sourceStmt->execute([$slug]);
$source = $sourceStmt->fetch();
if (!$source) {
    http_response_code(404);
    echo 'منبع پیدا نشد.';
    exit;
}
$q = trim((string) (get_q('q', '') ?? ''));
$contact = (string) (get_q('contact', 'all') ?? 'all');
$availability = (string) (get_q('availability', 'all') ?? 'all');
$sort = (string) (get_q('sort', 'recent') ?? 'recent');
$page = max(1, (int) Digits::only(Digits::normalize((string) (get_q('page', '1') ?? '1'))));
$perPage = min(100, max(25, (int) Digits::only(Digits::normalize((string) (get_q('per_page', '50') ?? '50')))));
$where = ' WHERE o.source_id = ?';
$params = [(int) $source['id']];
if ($q !== '') {
    $digits = Digits::only(Digits::normalize($q));
    $where .= $digits !== '' ? ' AND n.number LIKE ?' : ' AND o.external_id LIKE ?';
    $params[] = '%' . ($digits !== '' ? $digits : $q) . '%';
}
if ($contact === 'with') $where .= ' AND EXISTS (SELECT 1 FROM offer_contacts c WHERE c.offer_id=o.id)';
if ($contact === 'without') $where .= ' AND NOT EXISTS (SELECT 1 FROM offer_contacts c WHERE c.offer_id=o.id)';
if ($availability === 'available') $where .= " AND o.availability='available'";
if ($availability === 'unavailable') $where .= " AND o.availability<>'available'";
$count = $pdo->prepare("SELECT COUNT(*) FROM offers o JOIN sim_numbers n ON n.id=o.sim_number_id {$where}");
$count->execute($params);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$order = match ($sort) {
 'price_asc' => 'o.price_amount IS NULL, o.price_amount ASC',
 'price_desc' => 'o.price_amount DESC',
 'number' => 'n.number ASC',
 default => 'o.last_checked_at DESC, o.id DESC'
};
$stmt = $pdo->prepare("SELECT o.*,n.number AS sim_number,n.code_digit FROM offers o JOIN sim_numbers n ON n.id=o.sim_number_id {$where} ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$offers = $stmt->fetchAll();
$contacts = [];
$checks = [];
if ($offers) {
 $ids = array_map('intval', array_column($offers, 'id'));
 $in = implode(',', array_fill(0,count($ids),'?'));
 $cs = $pdo->prepare("SELECT * FROM offer_contacts WHERE offer_id IN ({$in}) ORDER BY offer_id,scope,phone");
 $cs->execute($ids);
 foreach ($cs->fetchAll() as $row) $contacts[(int)$row['offer_id']][]=$row;
 try {
  $ck=$pdo->prepare("SELECT offer_id,status,attempts,checked_at,retry_at,error FROM rond_contact_checks WHERE offer_id IN ({$in})");
  $ck->execute($ids);
  foreach($ck->fetchAll() as $row) $checks[(int)$row['offer_id']]=$row;
 } catch(Throwable) {}
}
$verified = (int)$pdo->prepare("SELECT COUNT(DISTINCT o.id) FROM offers o JOIN offer_contacts c ON c.offer_id=o.id WHERE o.source_id=?")->execute([(int)$source['id']]) ? 0 : 0;
$verifiedStmt=$pdo->prepare("SELECT COUNT(DISTINCT o.id) FROM offers o JOIN offer_contacts c ON c.offer_id=o.id WHERE o.source_id=?");
$verifiedStmt->execute([(int)$source['id']]); $verified=(int)$verifiedStmt->fetchColumn();
$phoneStmt=$pdo->prepare("SELECT COUNT(*) FROM offer_contacts c JOIN offers o ON o.id=c.offer_id WHERE o.source_id=?");
$phoneStmt->execute([(int)$source['id']]); $phoneCount=(int)$phoneStmt->fetchColumn();
$base=['r'=>'source','slug'=>$slug,'q'=>$q,'contact'=>$contact,'availability'=>$availability,'sort'=>$sort,'per_page'=>$perPage];
$base=array_filter($base,static fn($v)=>$v!==''&&$v!==null);
$pageUrl=static fn(int $p):string=>'/?'.http_build_query(array_merge($base,['page'=>$p]));
ob_start();
?>
<section class="ui-page-head"><div><div class="eyebrow">SOURCE INVENTORY · RONDINO</div><h1><?=e($source['name'])?></h1><p>فهرست کامل آگهی‌های ذخیره‌شده این منبع، همراه با شماره‌های تماس ثبت‌شده</p></div><a class="ui-button ui-button-ghost" href="/?r=sources">بازگشت به منابع</a></section>
<section class="ui-stat-grid">
 <article class="ui-stat-card"><span>کل آگهی‌های این منبع</span><strong><?=number_format((int)$total)?></strong><small>تعداد نتیجه با فیلتر فعلی</small></article>
 <article class="ui-stat-card"><span>آگهی دارای تماس</span><strong><?=number_format($verified)?></strong><small>دارای حداقل یک شماره</small></article>
 <article class="ui-stat-card"><span>شماره‌های ثبت‌شده</span><strong><?=number_format($phoneCount)?></strong><small>ردیف‌های تماس</small></article>
 <article class="ui-stat-card"><span>صفحه</span><strong><?=number_format($page)?> <small>/ <?=number_format($pages)?></small></strong><small><?=number_format($perPage)?> آگهی در هر صفحه</small></article>
</section>
<section class="ui-panel">
 <div class="ui-panel-heading"><div><div class="eyebrow">FILTERS</div><h2>فیلتر آگهی‌های <?=e($source['name'])?></h2></div></div>
 <form method="get" class="ui-filter-form"><input type="hidden" name="r" value="source"><input type="hidden" name="slug" value="<?=e($slug)?>">
  <label class="ui-field ui-field-wide">شماره سیم‌کارت یا شناسه آگهی<input class="ltr-num" name="q" value="<?=e($q)?>" placeholder="0912…"></label>
  <label class="ui-field">وضعیت تماس<select name="contact"><option value="all">همه</option><option value="with" <?=$contact==='with'?'selected':''?>>دارای شماره</option><option value="without" <?=$contact==='without'?'selected':''?>>بدون شماره</option></select></label>
  <label class="ui-field">وضعیت آگهی<select name="availability"><option value="all">همه</option><option value="available" <?=$availability==='available'?'selected':''?>>فعال</option><option value="unavailable" <?=$availability==='unavailable'?'selected':''?>>غیرفعال</option></select></label>
  <label class="ui-field">مرتب‌سازی<select name="sort"><option value="recent" <?=$sort==='recent'?'selected':''?>>جدیدترین بررسی</option><option value="price_asc" <?=$sort==='price_asc'?'selected':''?>>ارزان‌ترین</option><option value="price_desc" <?=$sort==='price_desc'?'selected':''?>>گران‌ترین</option><option value="number" <?=$sort==='number'?'selected':''?>>شماره سیم‌کارت</option></select></label>
  <label class="ui-field">تعداد در صفحه<select name="per_page"><?php foreach([25,50,100] as $n):?><option value="<?=$n?>" <?=$perPage===$n?'selected':''?>><?=$n?></option><?php endforeach;?></select></label>
  <div class="ui-filter-actions"><button>اعمال فیلتر</button><a class="ui-button ui-button-ghost" href="/?r=source&amp;slug=<?=e($slug)?>">پاک‌کردن</a></div>
 </form>
</section>
<section class="ui-panel"><div class="ui-panel-heading"><div><div class="eyebrow">LISTINGS</div><h2>آگهی‌ها و شماره‌های تماس</h2></div><span class="ui-results-caption">نمایش <?=number_format($total?($offset+1):0)?> تا <?=number_format(min($offset+$perPage,$total))?> از <?=number_format($total)?></span></div>
<?php if(!$offers):?><div class="ui-empty"><strong>آگهی‌ای پیدا نشد</strong><span>فیلترها را تغییر بده.</span></div><?php else:?>
<div class="ui-offer-grid ui-source-offer-grid">
<?php foreach($offers as $offer):$cs=$contacts[(int)$offer['id']]??[];$check=$checks[(int)$offer['id']]??null;?>
<article class="ui-offer-card">
 <div class="ui-offer-card-head"><div><strong class="ui-sim-number"><?=ltr_sim($offer['sim_number'])?></strong><span><?=e(condition_label($offer['condition_normalized']))?> · <?=e($offer['city']?:'شهر نامشخص')?></span></div><strong class="ui-price"><?=e(format_price_display($offer['price_amount']!==null?(int)$offer['price_amount']:null,$offer['price_type']))?></strong></div>
 <div class="ui-phone-list"><?php if(!$cs):?><span class="ui-muted">شماره تماس ثبت نشده</span><?php else:foreach($cs as $c):?><a class="ui-phone-pill" href="tel:<?=e($c['phone'])?>"><?=ltr_phone($c['phone'])?><small><?=e(market_contact_label((string)$c['scope']))?></small></a><?php endforeach;endif;?></div>
 <div class="ui-offer-foot"><span class="ui-muted">شناسه: <bdi dir="ltr"><?=e($offer['external_id'])?></bdi></span><span class="ui-muted"><?=e($check['status']??($cs?'verified':'بررسی‌نشده'))?></span><a href="<?=e($offer['canonical_url'])?>" target="_blank" rel="noopener">آگهی اصلی ↗</a></div>
</article>
<?php endforeach;?>
</div><?php endif;?>
<?php if($pages>1):?><nav class="ui-pagination"><a class="<?=$page<=1?'disabled':''?>" href="<?=$page>1?e($pageUrl($page-1)):'#'?>">قبلی</a><?php for($p=max(1,$page-2);$p<=min($pages,$page+2);$p++):?><a class="<?=$p===$page?'active':''?>" href="<?=e($pageUrl($p))?>"><?=number_format($p)?></a><?php endfor;?><a class="<?=$page>=$pages?'disabled':''?>" href="<?=$page<$pages?e($pageUrl($page+1)):'#'?>">بعدی</a></nav><?php endif;?>
</section>
<?php
$content=ob_get_clean();$title=$source['name'].' — موجودی روندینو';$active='sources';require __DIR__.'/layout.php';
