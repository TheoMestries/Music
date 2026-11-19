<?php
declare(strict_types=1);
require __DIR__.'/notes_test.php';
$n=mapCreateCampaignNote(1,'Document','',null,[],'personal',$owner);
$n=mapUpdateCampaignNote(1,$n['id'],1,'Document','<b onclick="alert(1)">Public</b>',null,[],'personal',$owner,[
 'readers'=>['3'],'editors'=>['4'],'gmNotes'=>'Secret MJ','rich'=>true,'image'=>''
]);
$r=mapGetCampaignNote(1,$n['id'],$playerOne);
assertNotesTest(!$r['canEdit'],'Lecteur ne doit pas modifier.');
assertNotesTest($r['options']['gmNotes']==='','Secret MJ masqué.');
assertNotesTest($r['content']==='<b>Public</b>','Attribut HTML supprimé.');
$e=mapGetCampaignNote(1,$n['id'],$playerTwo);
assertNotesTest($e['canEdit'],'Editeur doit pouvoir modifier.');
$e=mapUpdateCampaignNote(1,$n['id'],$e['revision'],'Modifié','Texte',null,[],'personal',$playerTwo,[
 'readers'=>['*'],'editors'=>['*'],'gmNotes'=>'Faux','rich'=>true,'image'=>''
]);
$check=mapGetCampaignNote(1,$n['id'],$owner);
assertNotesTest($check['options']['gmNotes']==='Secret MJ','Editeur joueur ne modifie pas les secrets.');
assertNotesTest($check['options']['readers']===['3'],'Editeur ne change pas les permissions.');
assertNotesTest(count(array_filter(mapListCampaignNotes(1,$playerOne),fn($r)=>$r['id']===$n['id']))===1,'Document accordé présent dans la liste.');
echo "OK - options, permissions individuelles, secrets MJ et HTML.
";
