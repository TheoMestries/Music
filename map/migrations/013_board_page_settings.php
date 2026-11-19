<?php
declare(strict_types=1);
return ['version'=>13,'name'=>'board_page_settings','up'=>static function(PDO $pdo):void {
    $pdo->exec("ALTER TABLE campaign_board_pages ADD COLUMN settings TEXT NOT NULL DEFAULT '{}'");
    $pdo->exec('ALTER TABLE campaign_board_pages ADD COLUMN settings_revision INTEGER NOT NULL DEFAULT 1');
    $update=$pdo->prepare('UPDATE campaign_board_pages SET settings=? WHERE id=?');
    foreach($pdo->query("SELECT id,image FROM campaign_board_pages WHERE image<>''") as $page){
        $encoded=explode(',',$page['image'],2)[1]??'';$bytes=base64_decode($encoded,true);
        $size=$bytes===false?false:@getimagesizefromstring($bytes);
        if($size)$update->execute([json_encode(['width'=>max(50,min(20000,$size[0])),'height'=>max(50,min(20000,$size[1]))],JSON_THROW_ON_ERROR),$page['id']]);
    }
}];
