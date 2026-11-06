<?php
/** Run/resume the full inventory independently: php scripts/crawl-rond.php [--restart] [--contacts] */
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$options = getopt('', ['restart', 'contacts', 'retry-failed']);
$engine = new CrawlEngine();
$pdo = Database::pdo();
// Reset only failed branch retry state when explicitly requested; preserve successful-page checkpoints.
if (isset($options['retry-failed'])) {
    $lock = $pdo->query("SELECT GET_LOCK(CONCAT('rondino-source-',(SELECT id FROM sources WHERE slug='rond-ir')),0)")->fetchColumn();
    if ((int) $lock !== 1) { fwrite(STDERR,"Source is busy\n"); exit(1); }
    try {
        $row = $pdo->query("SELECT * FROM crawl_passes WHERE source_id=(SELECT id FROM sources WHERE slug='rond-ir')")->fetch();
        if ($row) {
            $state = json_decode($row['branch_state'], true);
            foreach ($state['branches'] as &$b) if (in_array($b['status'], ['failed','retry'],true)) {
                $b['status']='active'; $b['attempts']=0; $b['retry_at']=null; $b['error']=null;
            }
            unset($b);
            $pdo->prepare("UPDATE crawl_passes SET status='paused',branch_state=?,lock_token=NULL,locked_until=NULL,finished_at=NULL WHERE id=?")
                ->execute([json_encode($state,JSON_UNESCAPED_UNICODE),$row['id']]);
        }
    } finally { $pdo->query("SELECT RELEASE_LOCK(CONCAT('rondino-source-',(SELECT id FROM sources WHERE slug='rond-ir')))"); }
}
$restart = isset($options['restart']);
while (!is_file(dirname(__DIR__) . '/storage/logs/crawl-worker.stop')) {
    $result = $engine->advanceSource('rond-ir', $restart, 1);
    $restart = false;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $status = $result['pass_status'] ?? '';
    if (in_array($status, ['completed','blocked','partial','disabled'], true)) {
        if ($status !== 'completed') exit(1);
        break;
    }
    sleep(2);
}
if (isset($options['contacts'])) {
    while (!is_file(dirname(__DIR__) . '/storage/logs/crawl-worker.stop') && RondContacts::advance()) {
        echo "contact item checked\n";
        sleep(2);
    }
    echo "Contact queue has no immediately eligible items; unresolved/retry items may remain.\n";
}
