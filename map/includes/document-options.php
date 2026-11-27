<?php
declare(strict_types=1);

const MAP_DOCUMENT_IMAGE_BYTES = 2 * 1024 * 1024;
const MAP_LIBRARY_PORTRAIT_BYTES = 16 * 1024 * 1024;
const MAP_LIBRARY_PORTRAIT_SOURCE_BYTES = 64 * 1024 * 1024;

function mapDocumentOptions(array $note): array {
 return json_decode($note['options'] ?? '{}',true) ?: [];
}
function mapDocumentGrant(array $note, array $user, string $kind): bool {
 $ids = mapDocumentOptions($note)[$kind] ?? [];
 return in_array('*',$ids,true) || in_array((string)$user['user_id'],$ids,true);
}
function mapValidateDocumentOptions(array $options, array $note, array $access, array $user): array {
 $previous = mapDocumentOptions($note);
 $linked = !empty($note['codex_entry_id']);
 $manager = mapCanUseGmNotes($access) || (int)$note['author_user_id'] === (int)$user['user_id'];
 foreach (['readers','editors'] as $key) {
  if ($linked) { $options[$key] = []; continue; }
  if (!$manager) { $options[$key] = $previous[$key] ?? []; continue; }
  $ids = $options[$key] ?? [];
  if (!is_array($ids) || count($ids)>100) throw new DomainException('Liste de permissions invalide.');
  $allowed = array_map(static fn($m)=>(string)$m['user_id'],mapGetCampaignMembers((int)$note['campaign_id']));
  $allowed[] = (string)$access['campaign']['owner_user_id']; $allowed[]='*';
  foreach($ids as $id) if (!is_string($id) || !in_array($id,$allowed,true)) throw new DomainException('Participant invalide.');
  $options[$key]=array_values(array_unique($ids));
 }
 $options['gmNotes'] = mapCanUseGmNotes($access) ? mapDocumentHtml(mapValidateNoteContent((string)($options['gmNotes'] ?? ''))) : ($previous['gmNotes'] ?? '');
 $previousImage = $previous['image'] ?? '';
 $imageValue = $options['image'] ?? $previousImage;
 $imageLimit = $linked ? MAP_DOCUMENT_IMAGE_BYTES : MAP_LIBRARY_PORTRAIT_BYTES;
 $image = $imageValue === $previousImage ? $previousImage : mapValidateDocumentImage((string) $imageValue, $imageLimit);
 $tokenImage = mapValidateDocumentImage((string)($options['tokenImage'] ?? ($previous['tokenImage'] ?? '')));
 return ['readers'=>$options['readers'],'editors'=>$options['editors'],'gmNotes'=>$options['gmNotes'],'image'=>$image, 'rich'=>!empty($options['rich']), 'type'=>$previous['type'] ?? 'document', 'tokenImage'=>$tokenImage];
}

function mapValidateDocumentImage(string $image, int $maxBytes = MAP_DOCUMENT_IMAGE_BYTES): string {
 if ($image !== '') {
  if (strlen($image) > 32 + 4 * intdiv($maxBytes + 2, 3)) throw new DomainException('L’image dépasse ' . intdiv($maxBytes, 1024 * 1024) . ' Mio.');
  [$format, $encoded] = array_pad(explode(',', $image, 2), 2, '');
  if (!preg_match('~^data:(image/(?:png|jpeg|gif|webp));base64$~D', $format, $mime)
      || $encoded === '' || strspn($encoded, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=') !== strlen($encoded)) {
      throw new DomainException('Image invalide.');
  }
  $bytes=base64_decode($encoded,true);
  if ($bytes!==false && strlen($bytes)>$maxBytes) throw new DomainException('L’image dépasse ' . intdiv($maxBytes, 1024 * 1024) . ' Mio.');
  if ($bytes===false || !($info=@getimagesizefromstring($bytes)) || $info['mime'] !== $mime[1]) throw new DomainException('Image invalide.');
 }
 return $image;
}

function mapDocumentHtml(string $html): string {
 $html = strip_tags($html, '<p><br><div><strong><b><em><i><u><s><strike><sup><sub><ul><ol><li><h2><h3><blockquote><hr><table><tbody><tr><td><th>');
 // No attributes or external resources are accepted in formatted text.
 return preg_replace('/<([a-z0-9]+)\b[^>]*>/i', '<$1>', $html) ?? '';
}
