<?php
declare(strict_types=1);
function mapDocumentOptions(array $note): array {
 return json_decode($note['options'] ?? '{}',true) ?: [];
}
function mapDocumentGrant(array $note, array $user, string $kind): bool {
 $ids = mapDocumentOptions($note)[$kind] ?? [];
 return in_array('*',$ids,true) || in_array((string)$user['user_id'],$ids,true);
}
function mapValidateDocumentOptions(array $options, array $note, array $access, array $user): array {
 $previous = mapDocumentOptions($note);
 $manager = mapCanUseGmNotes($access) || (int)$note['author_user_id'] === (int)$user['user_id'];
 foreach (['readers','editors'] as $key) {
  if (!$manager) { $options[$key] = $previous[$key] ?? []; continue; }
  $ids = $options[$key] ?? [];
  if (!is_array($ids) || count($ids)>100) throw new DomainException('Liste de permissions invalide.');
  $allowed = array_map(static fn($m)=>(string)$m['user_id'],mapGetCampaignMembers((int)$note['campaign_id']));
  $allowed[] = (string)$access['campaign']['owner_user_id']; $allowed[]='*';
  foreach($ids as $id) if (!is_string($id) || !in_array($id,$allowed,true)) throw new DomainException('Participant invalide.');
  $options[$key]=array_values(array_unique($ids));
 }
 $options['gmNotes'] = mapCanUseGmNotes($access) ? mapDocumentHtml(mapValidateNoteContent((string)($options['gmNotes'] ?? ''))) : ($previous['gmNotes'] ?? '');
 $image = mapValidateDocumentImage((string)($options['image'] ?? ''));
 $tokenImage = mapValidateDocumentImage((string)($options['tokenImage'] ?? ($previous['tokenImage'] ?? '')));
 return ['readers'=>$options['readers'],'editors'=>$options['editors'],'gmNotes'=>$options['gmNotes'],'image'=>$image, 'rich'=>!empty($options['rich']), 'type'=>$previous['type'] ?? 'document', 'tokenImage'=>$tokenImage];
}

function mapValidateDocumentImage(string $image): string {
 if ($image !== '') {
  if (!preg_match('~^data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=]+)$~D',$image,$m)) throw new DomainException('Image invalide.');
  $bytes=base64_decode($m[2],true);
  if ($bytes===false || strlen($bytes)>2097152 || !($info=@getimagesizefromstring($bytes)) || $info['mime'] !== 'image/'.$m[1]) throw new DomainException('Image invalide ou supérieure à 2 Mo.');
 }
 return $image;
}

function mapDocumentHtml(string $html): string {
 $html = strip_tags($html, '<p><br><div><strong><b><em><i><u><s><strike><sup><sub><ul><ol><li><h2><h3><blockquote><hr><table><tbody><tr><td><th>');
 // No attributes or external resources are accepted in formatted text.
 return preg_replace('/<([a-z0-9]+)\b[^>]*>/i', '<$1>', $html) ?? '';
}
