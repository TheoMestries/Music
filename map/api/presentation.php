<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/notes.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/presentations.php';
require_once __DIR__ . '/../includes/board-pages.php';
try {
 $user = mapGetCurrentUser();
 if ($user === null) mapJsonError('Authentification requise.', 401);
 $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
 if (!in_array($method, ['GET','POST'], true)) { mapJsonError('Méthode non autorisée.',405); }
 $body = $method === 'POST' ? mapReadJsonBody() : $_GET;
 $id = mapValidatePositiveInt($body['campaignId'] ?? null, 'Campagne');
 if ($method === 'POST') mapEnforceCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
 session_write_close();
 $access = mapRequireCampaignAccess($id, $user);
 $pdo = mapGetDatabase();
 mapEnsureBoard($id);
 if ($method === 'POST') {
  if (!mapCanUseGmNotes($access)) { mapThrowForbidden('Seul un MJ peut afficher un document à la table.'); }
  $note = mapGetCampaignNote($id, mapValidatePositiveInt($body['noteId'] ?? null, 'Document'), $user);
  $stmt = $pdo->prepare('INSERT INTO campaign_presentations (campaign_id,event_id,title,content,image,rich) VALUES (?,?,?,?,?,?) ON CONFLICT(campaign_id) DO UPDATE SET event_id=excluded.event_id,title=excluded.title,content=excluded.content,image=excluded.image,rich=excluded.rich');
  $stmt->execute([$id, bin2hex(random_bytes(16)), $note['title'], $note['content'], $note['options']['image'] ?? '', !empty($note['options']['rich']) ? 1 : 0]);
 }
 $after = $method === 'GET' && isset($_GET['after']) ? (string) $_GET['after'] : null;
 if ($after !== null && $after !== '' && !preg_match('/^[a-f0-9]{32}$/D', $after)) {
  throw new DomainException('Identifiant de présentation invalide.');
 }
 $afterBoard = isset($_GET['afterBoard']) ? mapValidatePositiveInt($_GET['afterBoard'], 'Révision du plateau') : null;
 $presentation = mapReadPresentation($pdo, $id, $after);
 $version = $pdo->prepare('SELECT revision FROM campaign_board_state WHERE campaign_id=?');$version->execute([$id]);$boardRevision=(int)$version->fetchColumn();$version->closeCursor();
 mapTableTimingMark('sync_lookup');
 $board = $afterBoard === null || $afterBoard !== $boardRevision ? mapBoardSnapshot($id, $user) : null;
 mapJsonSuccess(['presentation' => $presentation, 'board' => $board]);
} catch (MapHttpException $e) { mapJsonError($e->getMessage(), $e->getStatusCode());
} catch (DomainException $e) { mapJsonError($e->getMessage(),422);
} catch (Throwable $e) { error_log('[presentation] '.$e->getMessage()); mapJsonError('Impossible de charger la présentation.',500); }
