<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "OK $label\n";
}

$sim = '09126996994';
$code = '121354885520';

$ld = '<script type="application/ld+json">' . json_encode([
    '@type' => 'Product',
    'sku' => $code,
    'name' => 'سیم کارت ' . $sim,
    'offers' => [
        'seller' => ['telephone' => ['09121112233']],
    ],
], JSON_UNESCAPED_UNICODE) . '</script>';
$got = RondContacts::parse($ld, $code, $sim);
check($got === ['09121112233'], '1 JSON-LD matching sku+sim accepts seller telephone');

$wrongSku = str_replace($code, '999', $ld);
check(RondContacts::parse($wrongSku, $code, $sim) === [], '2 JSON-LD wrong sku rejected');

$htmlSeller = '<html><body><h1>سیم کارت ' . $sim . '</h1>'
    . '<div>اطلاعات تماس فروشنده <a href="tel:09361010124">09361010124</a> '
    . 'مشاهده همه سیم‌کارت‌های این فروشنده</div>'
    . '<a href="tel:02112345678">footer</a></body></html>';
$got = RondContacts::parse($htmlSeller, $code, $sim);
check($got === ['09361010124'], '3 HTML seller tel: near label accepted');

$htmlTextOnly = '<html><body>شماره: ' . $sim . ' اطلاعات تماس فروشنده 09127689278 مشاهده همه</body></html>';
$got = RondContacts::parse($htmlTextOnly, $code, $sim);
check($got === ['09127689278'], '4 HTML seller plain phone after label accepted');

$htmlNoSim = '<html><body>اطلاعات تماس فروشنده 09127689278</body></html>';
check(RondContacts::parse($htmlNoSim, $code, $sim) === [], '5 seller phone without listing SIM rejected');

$htmlFooterOnly = '<html><body>شماره: ' . $sim . ' <a href="tel:02112345678">تماس با ما</a></body></html>';
check(RondContacts::parse($htmlFooterOnly, $code, $sim) === [], '6 unrelated tel without seller label rejected');

// Angular shell may embed the seller label string inside scripts before hydration.
$shellOnly = '<html><body>شماره: ' . $sim
    . '<script>const t="اطلاعات تماس فروشنده"; const fake="tel:09121112233";</script>'
    . '</body></html>';
check(RondContacts::parse($shellOnly, $code, $sim) === [], '7 script-only seller label does not yield a phone');

$spaced = '<html><body>شماره: ' . $sim
    . '<div>اطلاعات تماس فروشنده 0912 768 9278 مشاهده همه</div></body></html>';
check(RondContacts::parse($spaced, $code, $sim) === ['09127689278'], '8 spaced seller phone in visible text accepted');

$liveCard = '<html><body>شماره:' . $sim
    . '<h3>اطلاعات تماس فروشنده</h3><app-seller-shop-info-card>'
    . '<div>ShopName.rond.ir</div>'
    . '<a class="contact-button contact-button-advert" href="tel:09354000066">'
    . '<span>09354000066</span></a>'
    . '<a class="contact-button contact-button-advert" href="tel:02166502579">'
    . '<span>02166502579</span></a>'
    . '</app-seller-shop-info-card><h3>مشخصات آگهی</h3></body></html>';
$got = RondContacts::parse($liveCard, $code, $sim);
sort($got);
check($got === ['02166502579', '09354000066'], '9 live seller card tel buttons accepted');

$saved = dirname(__DIR__) . '/storage/logs/debug-one-contact.html';
if (is_file($saved) && filesize($saved) > 1000) {
    $got = RondContacts::parse((string) file_get_contents($saved), '121036837376', '09120911890');
    check(in_array('09354000066', $got, true), '10 saved live HTML yields seller mobile');
}

echo "All contact parse regressions passed\n";
