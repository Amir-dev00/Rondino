<?php
declare(strict_types=1);
// Requires a NEW, disposable MySQL database with a name ending _test. Never uses app credentials.
$name = getenv('RONDINO_TEST_DB');
if (!$name || !preg_match('/^[a-zA-Z0-9_]+_test$/', $name)) { fwrite(STDERR,"Set RONDINO_TEST_DB to an empty *_test database\n"); exit(2); }
require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $name, 'root', (string) getenv('RONDINO_TEST_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if ($pdo->query('SHOW TABLES')->fetch()) throw new RuntimeException('Test database must be empty');
$property = new ReflectionProperty(Database::class, 'pdo'); $property->setValue(null,$pdo);
Database::importSqlFile($pdo,dirname(__DIR__) . '/database/rondino-install.sql');
function check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); echo "OK $label\n"; }
class ResumeFixture extends BaseAdapter {
    public bool $fail = false;
    public bool $repeat = false;
    public function slug(): string {return 'rond-ir';}
    public function name(): string {return 'Fixture';}
    public function baseUrl(): string {return 'https://fixture.invalid';}
    public function seedUrls(): array {return ['https://fixture.invalid/0'];}
    public function fetchPage(string $url,int $timeout=25): ?array {
        return ['ok'=>!$this->fail,'status'=>$this->fail?503:200,'body'=>$url,'final_url'=>$url,'error'=>$this->fail?'connection reset':null];
    }
    public function parseListPage(string $html,string $url): array {
        $id = $this->repeat ? '0' : basename($url);
        return [$this->listing(['external_id'=>'ad-'.$id,'sim_number'=>'09121111111','canonical_url'=>$url,'price_amount'=>1000,'price_type'=>'fixed','seller_key'=>'seller-'.$id])];
    }
    public function nextPageUrl(string $html,string $url,int $index): ?string {return str_ends_with($url,'/0')?'https://fixture.invalid/1':null;}
}
$adapter=new ResumeFixture(); Registry::all();
$map=new ReflectionProperty(Registry::class,'map'); $all=$map->getValue(); $all['rond-ir']=$adapter; $map->setValue(null,$all);
$engine=new CrawlEngine();
$first=$engine->advanceSource('rond-ir',true,1);
check($first['pass_status']==='paused','first page pauses with cursor');
$adapter->fail=true;
$second=$engine->advanceSource('rond-ir',false,1);
check($second['pass_status']==='paused','failed next page is retry pending, not complete');
$pass=$pdo->query("SELECT * FROM crawl_passes WHERE source_id=(SELECT id FROM sources WHERE slug='rond-ir')")->fetch();
$state=json_decode($pass['branch_state'],true);
check($state['branches'][0]['url']==='https://fixture.invalid/1','failed cursor retained');
check($state['unique_offers']===1,'failure does not inflate unique count');
$state['branches'][0]['retry_at']=0;
$pdo->prepare('UPDATE crawl_passes SET branch_state=? WHERE id=?')->execute([json_encode($state),$pass['id']]);
$adapter->fail=false;
$third=(new CrawlEngine())->advanceSource('rond-ir',false,1);
check($third['pass_status']==='completed','new engine resumes to verified end');
check((int)$pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn()===2,'two sellers of same SIM remain separate');
$observations=(int)$pdo->query('SELECT COUNT(*) FROM price_observations')->fetchColumn();
$engine->advanceSource('rond-ir',true,1); $engine->advanceSource('rond-ir',false,1);
check((int)$pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn()===2,'refresh creates no duplicate offers');
check((int)$pdo->query('SELECT COUNT(*) FROM price_observations')->fetchColumn()===$observations,'unchanged prices create no observations');
$adapter->repeat=true;
$engine->advanceSource('rond-ir',true,1);
$repeat=$engine->advanceSource('rond-ir',false,1);
check($repeat['pass_status']==='partial','repeated page is partial, not complete');
check((int)$pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn()===2,'partial crawl preserves historical offers');
$pdo->exec("UPDATE sources SET is_enabled=0 WHERE slug='rond-ir'");
check($engine->advanceSource('rond-ir',true,1)['pass_status']==='disabled','disabled source skipped');
$html='<a href="tel:02111111111">footer</a><script type="application/ld+json">'.json_encode(['@type'=>'Product','sku'=>'ad-0','name'=>'09121111111','offers'=>['seller'=>['telephone'=>'09351111111']]]).'</script>';
check(RondContacts::parse($html,'ad-0','09121111111')===['09351111111'],'seller contact explicitly associated');
check(RondContacts::parse($html,'other-ad','09121111111')===[],'other ad and footer contacts rejected');
RondContacts::ensureSchema($pdo);
echo "All crawl resume regressions passed\n";
