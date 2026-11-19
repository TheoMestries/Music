<?php
declare(strict_types=1);
require __DIR__.'/board_pages_test.php';

$before = mapBoardSnapshot(1, $playerOne);
$pageId = $before['playerPageId'];
$measurement = ['shape'=>'cone','origin'=>'center','coneEnd'=>'round','angle'=>60,'beamWidth'=>5,
    'lock'=>false,'linger'=>true,'start'=>[30,40],'end'=>[-10,80]];
$payload = ['pageId'=>$pageId,'layer'=>'players','measurement'=>$measurement];
$after = mapPublishBoardMeasurement(1, $payload, $playerOne);
assertNotesTest($after['revision'] === $before['revision'] + 1, 'La mesure déclenche la synchronisation.');
assertNotesTest($after['activePage']['measurements'] === [], 'La mesure locale ne revient pas à son auteur.');
$shared = mapBoardSnapshot(1, $playerTwo)['activePage']['measurements'][0];
assertNotesTest($shared['shape'] === 'cone' && $shared['end'] === [-10,80] && $shared['remainingMs'] > 0, 'Les autres participants reçoivent la mesure temporaire.');
expectNotesException(MapHttpException::class, 403, static fn()=>mapPublishBoardMeasurement(1, array_replace($payload,['layer'=>'gm']), $owner), 'Une mesure du calque MJ reste privée.');
mapGetDatabase()->exec('UPDATE board_measurements SET expires_at=0');
assertNotesTest(mapBoardSnapshot(1, $playerTwo)['activePage']['measurements'] === [], 'Une mesure expirée est masquée.');
echo "OK - partage temporaire des mesures et confidentialité.\n";
