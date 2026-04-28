const storageKey = "music-campaigns-v1";
const audioDbName = "music-campaigns-audio";
const audioStoreName = "audio-files";

const state = {
  campaigns: loadCampaigns(),
  activeCampaignId: null,
  selectedFolderId: "root",
  selectedPlaylistId: null,
  audioUrls: new Map(),
};

const campaignForm = document.querySelector("#campaign-form");
const campaignName = document.querySelector("#campaign-name");
const campaignList = document.querySelector("#campaign-list");
const campaignCount = document.querySelector("#campaign-count");
const emptyState = document.querySelector("#empty-state");
const campaignView = document.querySelector("#campaign-view");
const activeCampaignName = document.querySelector("#active-campaign-name");
const folderForm = document.querySelector("#folder-form");
const folderName = document.querySelector("#folder-name");
const playlistForm = document.querySelector("#playlist-form");
const playlistName = document.querySelector("#playlist-name");
const playlistFolder = document.querySelector("#playlist-folder");
const trackForm = document.querySelector("#track-form");
const trackTitle = document.querySelector("#track-title");
const trackFile = document.querySelector("#track-file");
const trackPlaylist = document.querySelector("#track-playlist");
const libraryTree = document.querySelector("#library-tree");
const folderTotal = document.querySelector("#folder-total");
const playlistTotal = document.querySelector("#playlist-total");
const trackTotal = document.querySelector("#track-total");
const activeUpdated = document.querySelector("#active-updated");
const campaignModal = document.querySelector("#campaign-modal");
const campaignSwitch = document.querySelector("#campaign-switch");
const campaignModalClose = document.querySelector("#campaign-modal-close");
const folderModal = document.querySelector("#folder-modal");
const playlistModal = document.querySelector("#playlist-modal");
const trackModal = document.querySelector("#track-modal");

campaignSwitch.addEventListener("click", () => openCampaignModal());
campaignModalClose.addEventListener("click", () => closeCampaignModal());
campaignModal.addEventListener("click", (event) => {
  if (event.target === campaignModal && state.activeCampaignId) {
    closeCampaignModal();
  }
});
document.addEventListener("click", (event) => {
  const closeButton = event.target.closest("[data-close-modal]");
  if (closeButton) {
    closeModal(closeButton.dataset.closeModal);
  }

  if ([folderModal, playlistModal, trackModal].includes(event.target)) {
    closeModal(event.target.id);
  }
});

libraryTree.addEventListener("click", (event) => {
  const openFolderModalButton = event.target.closest("[data-open-folder-modal]");
  const openPlaylistModalButton = event.target.closest("[data-open-playlist-modal]");
  const openTrackModalButton = event.target.closest("[data-open-track-modal]");
  const folderButton = event.target.closest("[data-folder-select]");
  const playlistButton = event.target.closest("[data-playlist-select]");
  const actionButton = event.target.closest("[data-track-menu]");
  const action = event.target.closest("[data-track-action]");
  const playPlaylistButton = event.target.closest("[data-play-playlist]");
  const shufflePlaylistButton = event.target.closest("[data-shuffle-playlist]");
  const panel = event.target.closest(".track-action-panel");

  if (openFolderModalButton) {
    openFolderModal();
    return;
  }

  if (openPlaylistModalButton) {
    openPlaylistModal();
    return;
  }

  if (openTrackModalButton) {
    openTrackModal();
    return;
  }

  if (folderButton) {
    state.selectedFolderId = folderButton.dataset.folderSelect;
    state.selectedPlaylistId = null;
    renderActiveCampaign();
    return;
  }

  if (playlistButton) {
    state.selectedPlaylistId = playlistButton.dataset.playlistSelect;
    renderActiveCampaign();
    return;
  }

  if (shufflePlaylistButton) {
    playPlaylist(shufflePlaylistButton.dataset.shufflePlaylist, true);
    return;
  }

  if (playPlaylistButton) {
    playPlaylist(playPlaylistButton.dataset.playPlaylist, false);
    return;
  }

  if (actionButton) {
    toggleTrackMenu(actionButton);
    return;
  }

  if (action) {
    handleTrackAction(action);
    return;
  }

  if (panel) return;

  closeTrackMenus();
});

libraryTree.addEventListener("change", (event) => {
  const playlistLoop = event.target.closest("[data-playlist-loop]");
  const playlistAutoplay = event.target.closest("[data-playlist-autoplay]");
  const playlistAutoplayShuffle = event.target.closest("[data-playlist-autoplay-shuffle]");
  const trackLoop = event.target.closest("[data-track-loop]");

  if (playlistLoop) {
    updatePlaylistLoop(playlistLoop);
    return;
  }

  if (playlistAutoplay) {
    updatePlaylistAutoplay(playlistAutoplay);
    return;
  }

  if (playlistAutoplayShuffle) {
    updatePlaylistAutoplayShuffle(playlistAutoplayShuffle);
    return;
  }

  if (trackLoop) {
    updateTrackLoop(trackLoop);
  }
});

libraryTree.addEventListener("dragstart", (event) => {
  const row = event.target.closest(".detail-track");
  const playlistTile = event.target.closest(".playlist-tile");
  const folderButton = event.target.closest(".folder-button");

  if (row && event.target.closest("button, input, select, audio, a, label, .track-actions")) {
    event.preventDefault();
    return;
  }

  if (row) {
    row.classList.add("dragging");
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData("text/plain", JSON.stringify({
      type: "track",
      playlistId: row.dataset.playlistId,
      trackId: row.dataset.trackId,
    }));
    return;
  }

  if (playlistTile) {
    playlistTile.classList.add("dragging");
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData("text/plain", JSON.stringify({
      type: "playlist",
      folderId: state.selectedFolderId,
      playlistId: playlistTile.dataset.playlistSelect,
    }));
    return;
  }

  if (folderButton && folderButton.dataset.folderSelect !== "root") {
    folderButton.classList.add("dragging");
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData("text/plain", JSON.stringify({
      type: "folder",
      folderId: folderButton.dataset.folderSelect,
    }));
  }
});

libraryTree.addEventListener("dragover", (event) => {
  const row = event.target.closest(".detail-track");
  const playlistTile = event.target.closest(".playlist-tile");
  const folderButton = event.target.closest(".folder-button");
  if (!row && !playlistTile && !folderButton) return;

  event.preventDefault();
  clearDropMarkers();

  if (row) {
    const placement = getDropPlacement(row, event.clientY);
    row.classList.add(placement === "before" ? "drop-before" : "drop-after");
    return;
  }

  const placement = getHorizontalDropPlacement(playlistTile, event.clientX);
  playlistTile.classList.add(placement === "before" ? "drop-before" : "drop-after");

  if (folderButton && folderButton.dataset.folderSelect !== "root") {
    const placement = getDropPlacement(folderButton, event.clientY);
    folderButton.classList.add(placement === "before" ? "drop-before" : "drop-after");
  }
});

libraryTree.addEventListener("dragleave", (event) => {
  if (!libraryTree.contains(event.relatedTarget)) {
    clearDropMarkers();
  }
});

libraryTree.addEventListener("drop", (event) => {
  const row = event.target.closest(".detail-track");
  const playlistTile = event.target.closest(".playlist-tile");
  const folderButton = event.target.closest(".folder-button");
  if (!row && !playlistTile && !folderButton) return;

  event.preventDefault();
  const payload = parseDragPayload(event.dataTransfer.getData("text/plain"));
  if (!payload) return;

  if (payload.type === "track" && row) {
    const placement = getDropPlacement(row, event.clientY);
    reorderTrackInPlaylist(payload.playlistId, payload.trackId, row.dataset.trackId, placement);
  }

  if (payload.type === "playlist" && playlistTile) {
    const placement = getHorizontalDropPlacement(playlistTile, event.clientX);
    reorderPlaylistInSelectedFolder(payload.folderId, payload.playlistId, playlistTile.dataset.playlistSelect, placement);
  }

  if (payload.type === "folder" && folderButton && folderButton.dataset.folderSelect !== "root") {
    const placement = getDropPlacement(folderButton, event.clientY);
    reorderFolder(payload.folderId, folderButton.dataset.folderSelect, placement);
  }

  clearDropMarkers();
});

libraryTree.addEventListener("dragend", () => {
  clearDropMarkers();
  document.querySelectorAll(".detail-track.dragging").forEach((row) => row.classList.remove("dragging"));
  document.querySelectorAll(".playlist-tile.dragging").forEach((tile) => tile.classList.remove("dragging"));
  document.querySelectorAll(".folder-button.dragging").forEach((button) => button.classList.remove("dragging"));
});

campaignForm.addEventListener("submit", (event) => {
  event.preventDefault();
  const name = campaignName.value.trim();
  if (!name) return;

  const campaign = {
    id: crypto.randomUUID(),
    name,
    createdAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
    playlists: [],
    tracks: [],
    folders: [],
  };

  state.campaigns.unshift(campaign);
  state.activeCampaignId = campaign.id;
  state.selectedFolderId = "root";
  state.selectedPlaylistId = null;
  campaignName.value = "";
  persistAndRender();
  closeCampaignModal();
});

folderForm.addEventListener("submit", (event) => {
  event.preventDefault();
  const campaign = getActiveCampaign();
  const name = folderName.value.trim();
  if (!campaign || !name) return;

  campaign.folders.push({
    id: crypto.randomUUID(),
    name,
    playlists: [],
  });

  folderName.value = "";
  touch(campaign);
  persistAndRender();
  closeModal("folder-modal");
});

playlistForm.addEventListener("submit", (event) => {
  event.preventDefault();
  const campaign = getActiveCampaign();
  const name = playlistName.value.trim();
  if (!campaign || !name) return;

  const playlist = {
    id: crypto.randomUUID(),
    name,
    loop: false,
    autoplayOnOpen: false,
    autoplayShuffleOnOpen: false,
    tracks: [],
  };

  if (playlistFolder.value === "root") {
    campaign.playlists.push(playlist);
  } else {
    const folder = campaign.folders.find((item) => item.id === playlistFolder.value);
    if (!folder) return;
    folder.playlists.push(playlist);
  }

  playlistName.value = "";
  touch(campaign);
  persistAndRender();
  closeModal("playlist-modal");
});

trackForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const campaign = getActiveCampaign();
  const title = trackTitle.value.trim();
  const file = trackFile.files[0];
  if (!campaign || !title || !file) return;

  const trackId = crypto.randomUUID();
  const track = {
    id: trackId,
    title,
    loop: false,
    fileName: file.name,
    fileType: file.type,
    fileSize: file.size,
  };

  await saveAudioFile(trackId, file);

  if (trackPlaylist.value === "root") {
    campaign.tracks.push(track);
  } else {
    const playlist = findPlaylist(campaign, trackPlaylist.value);
    if (!playlist) return;
    playlist.tracks.push(track);
  }

  trackTitle.value = "";
  trackFile.value = "";
  touch(campaign);
  persistAndRender();
  closeModal("track-modal");
});

function loadCampaigns() {
  try {
    return normalizeCampaigns(JSON.parse(localStorage.getItem(storageKey)) ?? []);
  } catch {
    return [];
  }
}

function normalizeCampaigns(campaigns) {
  return campaigns.map((campaign) => ({
    ...campaign,
    playlists: (campaign.playlists ?? []).map(normalizePlaylist),
    tracks: (campaign.tracks ?? []).map(normalizeTrack),
    folders: (campaign.folders ?? []).map((folder) => ({
      ...folder,
      playlists: (folder.playlists ?? []).map(normalizePlaylist),
    })),
  }));
}

function normalizePlaylist(playlist) {
  return {
    ...playlist,
    loop: playlist.loop ?? false,
    autoplayOnOpen: playlist.autoplayOnOpen ?? false,
    autoplayShuffleOnOpen: playlist.autoplayShuffleOnOpen ?? false,
    tracks: (playlist.tracks ?? []).map(normalizeTrack),
  };
}

function normalizeTrack(track) {
  return {
    ...track,
    loop: track.loop ?? false,
  };
}

function saveCampaigns() {
  localStorage.setItem(storageKey, JSON.stringify(state.campaigns));
}

function persistAndRender() {
  saveCampaigns();
  render();
}

function render() {
  renderCampaigns();
  renderActiveCampaign();
  campaignSwitch.classList.toggle("hidden", !state.activeCampaignId);
  if (!state.activeCampaignId) {
    openCampaignModal();
  }
}

function renderCampaigns() {
  campaignCount.textContent = state.campaigns.length;
  campaignList.innerHTML = "";

  if (state.campaigns.length === 0) {
    const note = document.createElement("p");
    note.className = "empty-note";
    note.textContent = "Aucune campagne pour le moment.";
    campaignList.append(note);
    return;
  }

  state.campaigns.forEach((campaign) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = `campaign-button${campaign.id === state.activeCampaignId ? " active" : ""}`;
    button.innerHTML = `
      <strong>${escapeHtml(campaign.name)}</strong>
      <span>${countPlaylists(campaign)} playlists · ${countTracks(campaign)} musiques</span>
    `;
    button.addEventListener("click", () => {
      state.activeCampaignId = campaign.id;
      state.selectedFolderId = "root";
      state.selectedPlaylistId = null;
      closeCampaignModal();
      render();
    });
    campaignList.append(button);
  });
}

function openCampaignModal() {
  campaignModal.classList.remove("hidden");
  campaignModalClose.classList.toggle("hidden", !state.activeCampaignId);
  campaignName.focus();
}

function closeCampaignModal() {
  if (!state.activeCampaignId) return;
  campaignModal.classList.add("hidden");
}

function openFolderModal() {
  openModal("folder-modal");
  folderName.focus();
}

function openPlaylistModal() {
  playlistFolder.value = state.selectedFolderId ?? "root";
  openModal("playlist-modal");
  playlistName.focus();
}

function openTrackModal() {
  trackPlaylist.value = state.selectedPlaylistId ?? "root";
  openModal("track-modal");
  trackTitle.focus();
}

function openModal(modalId) {
  document.querySelector(`#${modalId}`)?.classList.remove("hidden");
}

function closeModal(modalId) {
  document.querySelector(`#${modalId}`)?.classList.add("hidden");
}

async function renderActiveCampaign() {
  const campaign = getActiveCampaign();
  emptyState.classList.toggle("hidden", Boolean(campaign));
  campaignView.classList.toggle("hidden", !campaign);
  if (!campaign) return;

  activeCampaignName.textContent = campaign.name;
  folderTotal.textContent = campaign.folders.length;
  playlistTotal.textContent = countPlaylists(campaign);
  trackTotal.textContent = countTracks(campaign);
  activeUpdated.textContent = `Mis à jour ${formatDate(campaign.updatedAt)}`;

  renderFolderOptions(campaign);
  renderPlaylistOptions(campaign);
  ensureSelection(campaign);
  await renderTree(campaign);
}

function renderFolderOptions(campaign) {
  playlistFolder.innerHTML = "";
  playlistFolder.append(new Option("Sans dossier de tri", "root"));
  campaign.folders.forEach((folder) => {
    playlistFolder.append(new Option(folder.name, folder.id));
  });
  playlistForm.querySelector("button").disabled = false;
  playlistName.disabled = false;
  playlistFolder.disabled = false;
}

function renderPlaylistOptions(campaign) {
  trackPlaylist.innerHTML = "";
  trackPlaylist.append(new Option("Sans playlist", "root"));
  campaign.playlists.forEach((playlist) => {
    trackPlaylist.append(new Option(`Sans dossier / ${playlist.name}`, playlist.id));
  });
  campaign.folders.forEach((folder) => {
    folder.playlists.forEach((playlist) => {
      trackPlaylist.append(new Option(`${folder.name} / ${playlist.name}`, playlist.id));
    });
  });

  trackForm.querySelector("button").disabled = false;
  trackTitle.disabled = false;
  trackFile.disabled = false;
  trackPlaylist.disabled = false;
}

async function renderTree(campaign) {
  libraryTree.innerHTML = "";

  if (campaign.folders.length === 0 && campaign.playlists.length === 0 && campaign.tracks.length === 0) {
    const note = document.createElement("p");
    note.className = "empty-note";
    note.textContent = "Ajoute une musique, une playlist ou un dossier de tri pour structurer la campagne.";
    libraryTree.append(note);
    return;
  }

  const interfaceView = document.createElement("div");
  interfaceView.className = "library-interface";

  interfaceView.append(renderFolderPanel(campaign));
  interfaceView.append(renderPlaylistPanel(campaign));
  interfaceView.append(await renderPlaylistDetailPanel(campaign));
  libraryTree.append(interfaceView);
}

function ensureSelection(campaign) {
  const folderIds = ["root", ...campaign.folders.map((folder) => folder.id)];
  if (!folderIds.includes(state.selectedFolderId)) {
    state.selectedFolderId = "root";
  }

  const playlists = getSelectedFolderPlaylists(campaign);
  if (!playlists.some((playlist) => playlist.id === state.selectedPlaylistId)) {
    state.selectedPlaylistId = playlists[0]?.id ?? null;
  }
}

function renderFolderPanel(campaign) {
  const panel = document.createElement("aside");
  panel.className = "folder-panel";
  panel.innerHTML = `
    <div class="panel-heading">
      <h3>Dossiers</h3>
      <button type="button" class="panel-add-button" data-open-folder-modal aria-label="Ajouter un dossier">+</button>
    </div>
  `;

  panel.append(renderFolderButton("root", "Sans dossier", campaign.playlists.length));
  campaign.folders.forEach((folder) => {
    panel.append(renderFolderButton(folder.id, folder.name, folder.playlists.length));
  });

  return panel;
}

function renderFolderButton(folderId, name, playlistCount) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = `folder-button${state.selectedFolderId === folderId ? " active" : ""}`;
  button.draggable = folderId !== "root";
  button.dataset.folderSelect = folderId;
  button.innerHTML = `
    <span>${escapeHtml(name)}</span>
    <small>${playlistCount} playlist${playlistCount > 1 ? "s" : ""}</small>
  `;
  return button;
}

function renderPlaylistPanel(campaign) {
  const panel = document.createElement("section");
  panel.className = "playlist-panel";
  panel.innerHTML = `
    <div class="panel-heading">
      <h3>Playlists</h3>
      <button type="button" class="panel-add-button" data-open-playlist-modal aria-label="Ajouter une playlist">+</button>
    </div>
  `;

  const grid = document.createElement("div");
  grid.className = "playlist-button-grid";
  const playlists = getSelectedFolderPlaylists(campaign);

  if (playlists.length === 0) {
    const note = document.createElement("p");
    note.className = "empty-note";
    note.textContent = "Aucune playlist dans ce dossier.";
    grid.append(note);
  } else {
    playlists.forEach((playlist) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = `playlist-tile${state.selectedPlaylistId === playlist.id ? " active" : ""}`;
      button.draggable = true;
      button.dataset.playlistSelect = playlist.id;
      button.innerHTML = `
        <span class="playlist-disc" aria-hidden="true"></span>
        <strong>${escapeHtml(playlist.name)}</strong>
        <small>${playlist.tracks.length} musique${playlist.tracks.length > 1 ? "s" : ""}</small>
      `;
      grid.append(button);
    });
  }

  panel.append(grid);
  return panel;
}

async function renderLooseTracks(tracks, campaign) {
  const card = document.createElement("article");
  card.className = "folder-card";
  card.innerHTML = `
    <div class="folder-header">
      <strong>Musiques sans playlist</strong>
      <span>${tracks.length} musique${tracks.length > 1 ? "s" : ""}</span>
    </div>
  `;

  const body = document.createElement("div");
  body.className = "playlist-list";
  const list = document.createElement("ul");
  list.className = "track-list";
  for (const track of tracks) {
    list.append(await renderTrack(track, campaign, "root", null));
  }
  body.append(list);
  card.append(body);
  return card;
}

async function renderPlaylist(playlist, campaign) {
  const playlistCard = document.createElement("div");
  playlistCard.className = "playlist-card";
  playlistCard.dataset.playlistId = playlist.id;
  playlistCard.innerHTML = `
    <div class="playlist-header">
      <h3>${escapeHtml(playlist.name)}</h3>
      <div class="playlist-controls">
        <label class="loop-toggle">
          <input type="checkbox" data-playlist-loop="${playlist.id}" ${playlist.loop ? "checked" : ""}>
          <span>Boucle</span>
        </label>
        <label class="loop-toggle">
          <input type="checkbox" data-playlist-autoplay="${playlist.id}" ${playlist.autoplayOnOpen ? "checked" : ""}>
          <span>Lancer à l'ouverture</span>
        </label>
        <label class="loop-toggle">
          <input type="checkbox" data-playlist-autoplay-shuffle="${playlist.id}" ${playlist.autoplayShuffleOnOpen ? "checked" : ""}>
          <span>Lancer en aléatoire à l'ouverture</span>
        </label>
        <button type="button" class="playlist-play-button" data-play-playlist="${playlist.id}" aria-label="Lancer ${escapeHtml(playlist.name)}">▶</button>
        <button type="button" class="playlist-play-button" data-shuffle-playlist="${playlist.id}" aria-label="Lancer ${escapeHtml(playlist.name)} en aléatoire">⤨</button>
      </div>
    </div>
  `;

  if (playlist.tracks.length === 0) {
    const empty = document.createElement("p");
    empty.className = "playlist-empty";
    empty.textContent = "Aucune musique ajoutée.";
    playlistCard.append(empty);
    return playlistCard;
  }

  const list = document.createElement("ul");
  list.className = "track-list";
  for (const track of playlist.tracks) {
    list.append(await renderTrack(track, campaign, "playlist", playlist.id));
  }

  playlistCard.append(list);
  return playlistCard;
}

async function renderPlaylistDetailPanel(campaign) {
  const panel = document.createElement("section");
  panel.className = "playlist-detail-panel";

  const playlist = findPlaylist(campaign, state.selectedPlaylistId);
  if (!playlist) {
    panel.innerHTML = `
      <div class="detail-empty">
        <div class="panel-heading">
          <h3>Détail de la playlist</h3>
          <button type="button" class="panel-add-button" data-open-track-modal aria-label="Ajouter une musique">+</button>
        </div>
        <p>Sélectionne une playlist pour configurer son ordre de lecture.</p>
      </div>
    `;
    return panel;
  }

  panel.dataset.playlistId = playlist.id;
  panel.innerHTML = `
    <div class="playlist-detail-header">
      <div>
        <h3>${escapeHtml(playlist.name)}</h3>
        <p>${playlist.tracks.length} musique${playlist.tracks.length > 1 ? "s" : ""}</p>
      </div>
      <div class="playlist-controls">
        <button type="button" class="panel-add-button" data-open-track-modal aria-label="Ajouter une musique">+</button>
        <label class="loop-toggle">
          <input type="checkbox" data-playlist-loop="${playlist.id}" ${playlist.loop ? "checked" : ""}>
          <span>Boucle</span>
        </label>
        <button type="button" class="playlist-play-button" data-play-playlist="${playlist.id}" aria-label="Lancer ${escapeHtml(playlist.name)}">▶</button>
        <button type="button" class="playlist-play-button" data-shuffle-playlist="${playlist.id}" aria-label="Lancer ${escapeHtml(playlist.name)} en aléatoire">⤨</button>
      </div>
    </div>
    <div class="playlist-open-options">
      <label class="loop-toggle">
        <input type="checkbox" data-playlist-autoplay="${playlist.id}" ${playlist.autoplayOnOpen ? "checked" : ""}>
        <span>Lancer à l'ouverture</span>
      </label>
      <label class="loop-toggle">
        <input type="checkbox" data-playlist-autoplay-shuffle="${playlist.id}" ${playlist.autoplayShuffleOnOpen ? "checked" : ""}>
        <span>Lancer en aléatoire à l'ouverture</span>
      </label>
    </div>
  `;

  const list = document.createElement("ol");
  list.className = "playlist-detail-list";

  if (playlist.tracks.length === 0) {
    const note = document.createElement("p");
    note.className = "empty-note";
    note.textContent = "Aucune musique dans cette playlist.";
    panel.append(note);
    return panel;
  }

  for (const track of playlist.tracks) {
    const row = await renderTrack(track, campaign, "playlist", playlist.id);
    row.classList.add("detail-track");
    row.draggable = true;
    row.dataset.playlistId = playlist.id;
    list.append(row);
  }

  panel.append(list);
  if (playlist.autoplayOnOpen || playlist.autoplayShuffleOnOpen) {
    requestAnimationFrame(() => playPlaylist(playlist.id, playlist.autoplayShuffleOnOpen));
  }
  return panel;
}

function playPlaylist(playlistId, shuffle) {
  const playlistCard = libraryTree.querySelector(`[data-playlist-id="${CSS.escape(playlistId)}"]`);
  const campaign = getActiveCampaign();
  const playlist = findPlaylist(campaign, playlistId);
  if (!playlistCard) return;

  const players = shuffleArray([...playlistCard.querySelectorAll("audio.track-player")], shuffle);
  if (players.length === 0) return;

  document.querySelectorAll("audio").forEach((player) => {
    player.pause();
    player.currentTime = 0;
    player.onended = null;
  });

  players.forEach((player, index) => {
    player.onended = () => {
      const nextPlayer = players[index + 1];
      if (nextPlayer) {
        nextPlayer.currentTime = 0;
        nextPlayer.play().catch(() => {});
      } else if (playlist?.loop) {
        players[0].currentTime = 0;
        players[0].play().catch(() => {});
      }
    };
  });

  players[0].currentTime = 0;
  players[0].play().catch(() => {});
}

function shuffleArray(items, shouldShuffle) {
  if (!shouldShuffle) return items;

  const shuffled = [...items];
  for (let index = shuffled.length - 1; index > 0; index -= 1) {
    const targetIndex = Math.floor(Math.random() * (index + 1));
    [shuffled[index], shuffled[targetIndex]] = [shuffled[targetIndex], shuffled[index]];
  }
  return shuffled;
}

async function renderTrack(track, campaign, locationType, playlistId) {
  const item = document.createElement("li");
  item.className = "track-item";
  item.dataset.trackId = track.id;
  item.dataset.locationType = locationType;
  if (playlistId) item.dataset.playlistId = playlistId;

  const header = document.createElement("div");
  header.className = "track-card-header";

  const title = document.createElement("span");
  title.className = "track-title";
  title.textContent = track.title;
  header.append(title);

  const actions = document.createElement("div");
  actions.className = "track-actions";

  const loopLabel = document.createElement("label");
  loopLabel.className = "loop-toggle";
  loopLabel.innerHTML = `
    <input type="checkbox" data-track-loop="${track.id}" ${track.loop ? "checked" : ""}>
    <span>Boucle</span>
  `;
  actions.append(loopLabel);

  const menuButton = document.createElement("button");
  menuButton.type = "button";
  menuButton.className = "track-menu-button";
  menuButton.dataset.trackMenu = "";
  menuButton.setAttribute("aria-label", `Actions pour ${track.title}`);
  menuButton.textContent = "...";
  actions.append(menuButton);

  const panel = document.createElement("div");
  panel.className = "track-action-panel hidden";
  panel.innerHTML = `
    <button type="button" data-track-action="remove-current">Supprimer la musique de la playlist</button>
    <label>
      <span>Ajouter la musique à une autre Playlist</span>
      <select data-track-target-playlist></select>
    </label>
    <button type="button" data-track-action="add-to-playlist">Ajouter</button>
    <button type="button" data-track-action="remove-all">Supprimer la musique de toute les playlist</button>
  `;

  const targetSelect = panel.querySelector("[data-track-target-playlist]");
  const playlists = getPlaylistOptions(campaign).filter((playlist) => playlist.id !== playlistId);
  if (playlists.length === 0) {
    targetSelect.append(new Option("Aucune autre playlist", ""));
    targetSelect.disabled = true;
    panel.querySelector("[data-track-action='add-to-playlist']").disabled = true;
  } else {
    playlists.forEach((playlist) => targetSelect.append(new Option(playlist.label, playlist.id)));
  }

  actions.append(panel);
  header.append(actions);
  item.append(header);

  const audioUrl = await getAudioUrl(track.id);
  if (audioUrl) {
    const player = document.createElement("audio");
    player.className = "track-player";
    player.controls = true;
    player.loop = Boolean(track.loop);
    player.preload = "metadata";
    player.src = audioUrl;
    item.append(player);
  } else {
    const missing = document.createElement("span");
    missing.className = "track-missing";
    missing.textContent = "Fichier audio introuvable";
    item.append(missing);
  }

  return item;
}

function updatePlaylistLoop(input) {
  const campaign = getActiveCampaign();
  const playlist = findPlaylist(campaign, input.dataset.playlistLoop);
  if (!campaign || !playlist) return;

  playlist.loop = input.checked;
  touch(campaign);
  saveCampaigns();
}

function updatePlaylistAutoplay(input) {
  const campaign = getActiveCampaign();
  const playlist = findPlaylist(campaign, input.dataset.playlistAutoplay);
  if (!campaign || !playlist) return;

  playlist.autoplayOnOpen = input.checked;
  if (!input.checked) playlist.autoplayShuffleOnOpen = false;

  const shuffleInput = document.querySelector(`[data-playlist-autoplay-shuffle="${CSS.escape(playlist.id)}"]`);
  if (shuffleInput) shuffleInput.checked = playlist.autoplayShuffleOnOpen;

  touch(campaign);
  saveCampaigns();
}

function updatePlaylistAutoplayShuffle(input) {
  const campaign = getActiveCampaign();
  const playlist = findPlaylist(campaign, input.dataset.playlistAutoplayShuffle);
  if (!campaign || !playlist) return;

  playlist.autoplayShuffleOnOpen = input.checked;
  if (input.checked) playlist.autoplayOnOpen = true;

  const autoplayInput = document.querySelector(`[data-playlist-autoplay="${CSS.escape(playlist.id)}"]`);
  if (autoplayInput) autoplayInput.checked = playlist.autoplayOnOpen;

  touch(campaign);
  saveCampaigns();
}

function updateTrackLoop(input) {
  const campaign = getActiveCampaign();
  if (!campaign) return;

  updateTrackEverywhere(campaign, input.dataset.trackLoop, (track) => {
    track.loop = input.checked;
  });

  const item = input.closest(".track-item");
  const player = item?.querySelector("audio");
  if (player) player.loop = input.checked;
  document.querySelectorAll(`[data-track-loop="${CSS.escape(input.dataset.trackLoop)}"]`).forEach((checkbox) => {
    checkbox.checked = input.checked;
    const checkboxPlayer = checkbox.closest(".track-item")?.querySelector("audio");
    if (checkboxPlayer) checkboxPlayer.loop = input.checked;
  });

  touch(campaign);
  saveCampaigns();
}

function updateTrackEverywhere(campaign, trackId, updater) {
  campaign.tracks.forEach((track) => {
    if (track.id === trackId) updater(track);
  });

  campaign.playlists.forEach((playlist) => {
    playlist.tracks.forEach((track) => {
      if (track.id === trackId) updater(track);
    });
  });

  campaign.folders.forEach((folder) => {
    folder.playlists.forEach((playlist) => {
      playlist.tracks.forEach((track) => {
        if (track.id === trackId) updater(track);
      });
    });
  });
}

function toggleTrackMenu(button) {
  const panel = button.parentElement.querySelector(".track-action-panel");
  const isHidden = panel.classList.contains("hidden");
  closeTrackMenus();
  panel.classList.toggle("hidden", !isHidden);
}

function closeTrackMenus() {
  document.querySelectorAll(".track-action-panel").forEach((panel) => {
    panel.classList.add("hidden");
  });
}

async function handleTrackAction(actionButton) {
  const campaign = getActiveCampaign();
  const item = actionButton.closest(".track-item");
  if (!campaign || !item) return;

  const trackId = item.dataset.trackId;
  const sourceTrack = findTrack(campaign, trackId);
  if (!sourceTrack) return;

  if (actionButton.dataset.trackAction === "remove-current") {
    removeTrackFromCurrentLocation(campaign, item);
  }

  if (actionButton.dataset.trackAction === "add-to-playlist") {
    const targetPlaylistId = item.querySelector("[data-track-target-playlist]").value;
    const targetPlaylist = findPlaylist(campaign, targetPlaylistId);
    if (targetPlaylist && !targetPlaylist.tracks.some((track) => track.id === sourceTrack.id)) {
      targetPlaylist.tracks.push({ ...sourceTrack });
    }
  }

  if (actionButton.dataset.trackAction === "remove-all") {
    removeTrackEverywhere(campaign, trackId);
    await deleteAudioFile(trackId);
  }

  touch(campaign);
  persistAndRender();
}

function removeTrackFromCurrentLocation(campaign, item) {
  const trackId = item.dataset.trackId;

  if (item.dataset.locationType === "root") {
    campaign.tracks = campaign.tracks.filter((track) => track.id !== trackId);
    return;
  }

  const playlist = findPlaylist(campaign, item.dataset.playlistId);
  if (playlist) {
    playlist.tracks = playlist.tracks.filter((track) => track.id !== trackId);
  }
}

function removeTrackEverywhere(campaign, trackId) {
  campaign.tracks = campaign.tracks.filter((track) => track.id !== trackId);
  campaign.playlists.forEach((playlist) => {
    playlist.tracks = playlist.tracks.filter((track) => track.id !== trackId);
  });
  campaign.folders.forEach((folder) => {
    folder.playlists.forEach((playlist) => {
      playlist.tracks = playlist.tracks.filter((track) => track.id !== trackId);
    });
  });
}

function openAudioDb() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(audioDbName, 1);

    request.onupgradeneeded = () => {
      request.result.createObjectStore(audioStoreName);
    };

    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function saveAudioFile(trackId, file) {
  const db = await openAudioDb();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction(audioStoreName, "readwrite");
    transaction.objectStore(audioStoreName).put(file, trackId);
    transaction.oncomplete = () => {
      db.close();
      resolve();
    };
    transaction.onerror = () => {
      db.close();
      reject(transaction.error);
    };
  });
}

async function getAudioFile(trackId) {
  const db = await openAudioDb();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction(audioStoreName, "readonly");
    const request = transaction.objectStore(audioStoreName).get(trackId);
    request.onsuccess = () => resolve(request.result ?? null);
    request.onerror = () => reject(request.error);
    transaction.oncomplete = () => db.close();
  });
}

async function getAudioUrl(trackId) {
  if (state.audioUrls.has(trackId)) return state.audioUrls.get(trackId);

  const file = await getAudioFile(trackId);
  if (!file) return null;

  const url = URL.createObjectURL(file);
  state.audioUrls.set(trackId, url);
  return url;
}

async function deleteAudioFile(trackId) {
  const existingUrl = state.audioUrls.get(trackId);
  if (existingUrl) {
    URL.revokeObjectURL(existingUrl);
    state.audioUrls.delete(trackId);
  }

  const db = await openAudioDb();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction(audioStoreName, "readwrite");
    transaction.objectStore(audioStoreName).delete(trackId);
    transaction.oncomplete = () => {
      db.close();
      resolve();
    };
    transaction.onerror = () => {
      db.close();
      reject(transaction.error);
    };
  });
}

function findTrack(campaign, trackId) {
  const rootTrack = campaign.tracks.find((track) => track.id === trackId);
  if (rootTrack) return rootTrack;

  for (const playlist of campaign.playlists) {
    const track = playlist.tracks.find((item) => item.id === trackId);
    if (track) return track;
  }

  for (const folder of campaign.folders) {
    for (const playlist of folder.playlists) {
      const track = playlist.tracks.find((item) => item.id === trackId);
      if (track) return track;
    }
  }

  return null;
}

function getPlaylistOptions(campaign) {
  const playlists = campaign.playlists.map((playlist) => ({
    id: playlist.id,
    label: `Sans dossier / ${playlist.name}`,
  }));

  campaign.folders.forEach((folder) => {
    folder.playlists.forEach((playlist) => {
      playlists.push({
        id: playlist.id,
        label: `${folder.name} / ${playlist.name}`,
      });
    });
  });

  return playlists;
}

function getSelectedFolderPlaylists(campaign) {
  if (state.selectedFolderId === "root") return campaign.playlists;

  const folder = campaign.folders.find((item) => item.id === state.selectedFolderId);
  return folder?.playlists ?? [];
}

function reorderTrackInPlaylist(playlistId, draggedTrackId, targetTrackId, placement) {
  const campaign = getActiveCampaign();
  const playlist = findPlaylist(campaign, playlistId);
  if (!campaign || !playlist || draggedTrackId === targetTrackId) return;

  const currentIndex = playlist.tracks.findIndex((track) => track.id === draggedTrackId);
  if (currentIndex < 0 || !playlist.tracks.some((track) => track.id === targetTrackId)) return;

  const [track] = playlist.tracks.splice(currentIndex, 1);
  const targetIndexAfterRemoval = playlist.tracks.findIndex((item) => item.id === targetTrackId);
  const insertIndex = placement === "after" ? targetIndexAfterRemoval + 1 : targetIndexAfterRemoval;

  playlist.tracks.splice(insertIndex, 0, track);
  touch(campaign);
  persistAndRender();
}

function reorderPlaylistInSelectedFolder(folderId, draggedPlaylistId, targetPlaylistId, placement) {
  const campaign = getActiveCampaign();
  if (!campaign || folderId !== state.selectedFolderId || draggedPlaylistId === targetPlaylistId) return;

  const playlists = getSelectedFolderPlaylists(campaign);
  const currentIndex = playlists.findIndex((playlist) => playlist.id === draggedPlaylistId);
  if (currentIndex < 0 || !playlists.some((playlist) => playlist.id === targetPlaylistId)) return;

  const [playlist] = playlists.splice(currentIndex, 1);
  const targetIndexAfterRemoval = playlists.findIndex((item) => item.id === targetPlaylistId);
  const insertIndex = placement === "after" ? targetIndexAfterRemoval + 1 : targetIndexAfterRemoval;

  playlists.splice(insertIndex, 0, playlist);
  touch(campaign);
  persistAndRender();
}

function reorderFolder(draggedFolderId, targetFolderId, placement) {
  const campaign = getActiveCampaign();
  if (!campaign || draggedFolderId === targetFolderId) return;

  const currentIndex = campaign.folders.findIndex((folder) => folder.id === draggedFolderId);
  if (currentIndex < 0 || !campaign.folders.some((folder) => folder.id === targetFolderId)) return;

  const [folder] = campaign.folders.splice(currentIndex, 1);
  const targetIndexAfterRemoval = campaign.folders.findIndex((item) => item.id === targetFolderId);
  const insertIndex = placement === "after" ? targetIndexAfterRemoval + 1 : targetIndexAfterRemoval;

  campaign.folders.splice(insertIndex, 0, folder);
  touch(campaign);
  persistAndRender();
}

function parseDragPayload(value) {
  try {
    const payload = JSON.parse(value || "{}");
    if (payload.type === "track" && payload.playlistId && payload.trackId) return payload;
    if (payload.type === "playlist" && payload.folderId !== undefined && payload.playlistId) return payload;
    if (payload.type === "folder" && payload.folderId) return payload;
    return null;
  } catch {
    return null;
  }
}

function getDropPlacement(row, pointerY) {
  const rect = row.getBoundingClientRect();
  return pointerY < rect.top + rect.height / 2 ? "before" : "after";
}

function getHorizontalDropPlacement(element, pointerX) {
  const rect = element.getBoundingClientRect();
  return pointerX < rect.left + rect.width / 2 ? "before" : "after";
}

function clearDropMarkers() {
  document.querySelectorAll(".drop-before, .drop-after").forEach((row) => {
    row.classList.remove("drop-before", "drop-after");
  });
}

function getActiveCampaign() {
  return state.campaigns.find((campaign) => campaign.id === state.activeCampaignId) ?? null;
}

function findPlaylist(campaign, playlistId) {
  if (!campaign) return null;
  const rootPlaylist = campaign.playlists.find((item) => item.id === playlistId);
  if (rootPlaylist) return rootPlaylist;
  for (const folder of campaign.folders) {
    const playlist = folder.playlists.find((item) => item.id === playlistId);
    if (playlist) return playlist;
  }
  return null;
}

function countPlaylists(campaign) {
  return campaign.playlists.length + campaign.folders.reduce((total, folder) => total + folder.playlists.length, 0);
}

function countTracks(campaign) {
  return campaign.tracks.length + campaign.playlists.reduce((total, playlist) => total + playlist.tracks.length, 0) + campaign.folders.reduce((total, folder) => {
    return total + folder.playlists.reduce((sum, playlist) => sum + playlist.tracks.length, 0);
  }, 0);
}

function touch(campaign) {
  campaign.updatedAt = new Date().toISOString();
}

function formatDate(value) {
  return new Intl.DateTimeFormat("fr-FR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(value));
}

function escapeHtml(value) {
  const element = document.createElement("span");
  element.textContent = value;
  return element.innerHTML;
}

render();
