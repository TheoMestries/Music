<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/presentations.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE campaign_presentations (campaign_id INTEGER PRIMARY KEY,event_id TEXT,title TEXT,content TEXT,image TEXT,rich INTEGER)');
function checkPresentation(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
checkPresentation(mapWaitForPresentation($pdo, 1, null) === null, 'Initial empty response');
$pdo->exec("INSERT INTO campaign_presentations VALUES (1,'event-a','Document','Content','',0)");
$start = microtime(true);
$result = mapWaitForPresentation($pdo, 1, 'previous', 1);
checkPresentation($result['event_id'] === 'event-a', 'New presentation returned');
checkPresentation(microtime(true) - $start < 0.5, 'New presentation must not wait for timeout');
$start = microtime(true);
checkPresentation(mapWaitForPresentation($pdo, 1, 'event-a', 0.2) === null, 'Unchanged presentation is not resent');
checkPresentation(microtime(true) - $start >= 0.2, 'Unchanged presentation waits');
checkPresentation(mapWaitForPresentation($pdo, 2, null) === null, 'Campaign isolation');
$pdo->exec("UPDATE campaign_presentations SET event_id='event-b'");
checkPresentation(mapWaitForPresentation($pdo, 1, 'event-a')['event_id'] === 'event-b', 'Repeated sharing of same document creates a new event');
echo "OK - immediate delivery, timeout, campaign isolation, repeated presentation.\n";
