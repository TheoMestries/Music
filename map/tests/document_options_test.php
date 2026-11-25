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
$created = mapCreateCampaignNote(1, 'Document complet', '<b class="large">Texte</b>', null, ['brouillon'], 'personal', $owner, 'document', [
    'readers' => ['3'], 'editors' => ['4'], 'gmNotes' => 'Secret initial', 'rich' => true,
]);
assertNotesTest($created['revision'] === 1 && $created['content'] === '<b>Texte</b>', 'Le premier enregistrement crée directement le document formaté.');
assertNotesTest($created['options']['gmNotes'] === 'Secret initial' && $created['tags'] === ['brouillon'], 'Les options et tags sont enregistrés à la création.');
assertNotesTest(mapGetCampaignNote(1, $created['id'], $playerTwo)['canEdit'], 'Les permissions sont actives dès la création.');
$before = count(mapListCampaignNotes(1, $owner));
expectNotesException(DomainException::class, 0, static fn()=>mapCreateCampaignNote(1, 'Création refusée', '', null, [], 'personal', $owner, 'character', [
    'image' => 'invalide',
]), 'Une image invalide empêche la création.');
assertNotesTest(count(mapListCampaignNotes(1, $owner)) === $before, 'Un premier enregistrement refusé ne laisse pas de note.');
echo "OK - options, permissions individuelles, secrets MJ et HTML.
";
