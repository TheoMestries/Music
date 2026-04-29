const apiEndpoint = "api.php";

const state = {
  campaigns: [],
  activeCampaignId: null,
  selectedFolderId: "root",
  selectedPlaylistId: null,
  openPlaylistIds: [],
  dragType: null,
  renameTarget: null,
  playlistRuns: new Map(),
  playlistCurrentTrackIds: new Map(),
  playlistOpenTimer: null,
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
const themeToggle = document.querySelector("#theme-toggle");
const folderModal = document.querySelector("#folder-modal");
const playlistModal = document.querySelector("#playlist-modal");
const trackModal = document.querySelector("#track-modal");
const renameModal = document.querySelector("#rename-modal");
const renameForm = document.querySelector("#rename-form");
const renameName = document.querySelector("#rename-name");
const renameLabel = document.querySelector("#rename-label");

initializeTheme();
themeToggle.addEventListener("click", toggleTheme);
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

  if ([folderModal, playlistModal, trackModal, renameModal].includes(event.target)) {
    closeModal(event.target.id);
  }
});

libraryTree.addEventListener("click", (event) => {
  const openFolderModalButton = event.target.closest("[data-open-folder-modal]");
  const openPlaylistModalButton = event.target.closest("[data-open-playlist-modal]");
  const openTrackModalButton = event.target.closest("[data-open-track-modal]");
  const folderMenuButton = event.target.closest("[data-folder-menu]");
  const folderAction = event.target.closest("[data-folder-action]");
  const playlistMenuButton = event.target.closest("[data-playlist-menu]");
  const playlistAction = event.target.closest("[data-playlist-action]");
  const folderButton = event.target.closest("[data-folder-select]");
  const playlistButton = event.target.closest("[data-playlist-select]");
  const actionButton = event.target.closest("[data-track-menu]");
  const action = event.target.closest("[data-track-action]");
  const playPlaylistButton = event.target.closest("[data-play-playlist]");
  const stopPlaylistButton = event.target.closest("[data-stop-playlist]");
  const closePlaylistDetailButton = event.target.closest("[data-close-playlist-detail]");
  const panel = event.target.closest(".track-action-panel");
  const playlistPanel = event.target.closest(".playlist-action-panel");
  const folderPanel = event.target.closest(".folder-action-panel");

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

  if (folderMenuButton) {
    toggleFolderMenu(folderMenuButton);
    return;
  }

  if (folderAction) {
    handleFolderAction(folderAction);
    return;
  }

  if (playlistMenuButton) {
    togglePlaylistMenu(playlistMenuButton);
    return;
  }

  if (playlistAction) {
    handlePlaylistAction(playlistAction);
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

  if (closePlaylistDetailButton) {
    closePlaylistDetail(closePlaylistDetailButton.dataset.closePlaylistDetail, true);
    return;
  }

  if (folderPanel) return;
  if (playlistPanel) return;
  if (panel) return;

  if (folderButton) {
    state.selectedFolderId = folderButton.dataset.folderSelect;
    renderActiveCampaign();
    return;
  }

  if (playlistButton) {
    window.clearTimeout(state.playlistOpenTimer);
    state.playlistOpenTimer = window.setTimeout(async () => {
      await openPlaylistDetailInView(playlistButton.dataset.playlistSelect);
    }, 220);
    return;
  }

  if (playPlaylistButton) {
    playPlaylist(playPlaylistButton.dataset.playPlaylist);
    return;
  }

  if (stopPlaylistButton) {
    stopPlaylist(stopPlaylistButton.dataset.stopPlaylist);
    return;
  }

  closeTrackMenus();
  closePlaylistMenus();
});

libraryTree.addEventListener("dblclick", (event) => {
  if (event.target.closest("button, input, select, audio, a, label, .playlist-action-panel, .playlist-actions")) return;

  const playlistButton = event.target.closest("[data-playlist-select]");
  if (!playlistButton) return;

  window.clearTimeout(state.playlistOpenTimer);
  const playlistId = playlistButton.dataset.playlistSelect;
  if (state.openPlaylistIds.includes(playlistId)) {
    closePlaylistDetail(playlistId, true);
    return;
  }

  openPlaylistDetailInView(playlistId).then(() => playPlaylist(playlistId));
});

libraryTree.addEventListener("change", (event) => {
  const playlistLoop = event.target.closest("[data-playlist-loop]");
  const playlistShuffle = event.target.closest("[data-playlist-shuffle]");
  const trackLoop = event.target.closest("[data-track-loop]");

  if (playlistLoop) {
    updatePlaylistLoop(playlistLoop);
    return;
  }

  if (playlistShuffle) {
    updatePlaylistShuffle(playlistShuffle);
    return;
  }

  if (trackLoop) {
    updateTrackLoop(trackLoop);
  }
});

libraryTree.addEventListener("pointerdown", (event) => {
  const interactiveControl = event.target.closest("button, input, select, audio, a, label, .track-action-panel, .track-actions");
  const draggableTrack = event.target.closest(".detail-track");
  if (!interactiveControl || !draggableTrack) return;

  event.stopPropagation();
  draggableTrack.draggable = false;
});

libraryTree.addEventListener("pointerover", (event) => {
  const player = event.target.closest("audio.track-player");
  const draggableTrack = player?.closest(".detail-track");
  if (draggableTrack) draggableTrack.draggable = false;
});

libraryTree.addEventListener("pointerout", (event) => {
  const player = event.target.closest("audio.track-player");
  const draggableTrack = player?.closest(".detail-track");
  if (draggableTrack) draggableTrack.draggable = true;
});

libraryTree.addEventListener("pointerup", restoreDraggableTracks);
libraryTree.addEventListener("pointercancel", restoreDraggableTracks);

libraryTree.addEventListener("dragstart", (event) => {
  const row = event.target.closest(".detail-track");
  const playlistTile = event.target.closest(".playlist-tile");
  const folderButton = event.target.closest(".folder-button");

  if (event.target.closest("audio.track-player")) {
    event.preventDefault();
    return;
  }

  if (row && event.target.closest("button, input, select, audio, a, label, .track-actions")) {
    event.preventDefault();
    return;
  }

  if (playlistTile && event.target.closest("button, input, select, label, .playlist-actions")) {
    event.preventDefault();
    return;
  }

  if (folderButton && event.target.closest("button, input, select, label, .folder-actions")) {
    event.preventDefault();
    return;
  }

  if (row) {
    state.dragType = "track";
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
    state.dragType = "playlist";
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
    state.dragType = "folder";
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

  if (playlistTile) {
    const placement = getHorizontalDropPlacement(playlistTile, event.clientX);
    playlistTile.classList.add(placement === "before" ? "drop-before" : "drop-after");
    return;
  }

  if (folderButton && state.dragType === "playlist") {
    folderButton.classList.add("drop-target");
    return;
  }

  if (folderButton && state.dragType === "folder" && folderButton.dataset.folderSelect !== "root") {
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

  if (payload.type === "playlist" && folderButton) {
    movePlaylistToFolder(payload.folderId, payload.playlistId, folderButton.dataset.folderSelect);
  }

  if (payload.type === "folder" && folderButton && folderButton.dataset.folderSelect !== "root") {
    const placement = getDropPlacement(folderButton, event.clientY);
    reorderFolder(payload.folderId, folderButton.dataset.folderSelect, placement);
  }

  clearDropMarkers();
  state.dragType = null;
});

libraryTree.addEventListener("dragend", () => {
  clearDropMarkers();
  document.querySelectorAll(".detail-track.dragging").forEach((row) => row.classList.remove("dragging"));
  document.querySelectorAll(".playlist-tile.dragging").forEach((tile) => tile.classList.remove("dragging"));
  document.querySelectorAll(".folder-button.dragging").forEach((button) => button.classList.remove("dragging"));
  state.dragType = null;
});

campaignForm.addEventListener("submit", (event) => {
  event.preventDefault();
  const name = campaignName.value.trim();
  if (!name) return;

  const campaign = {
    id: createId(),
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
  state.openPlaylistIds = [];
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
    id: createId(),
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
    id: createId(),
    name,
    loop: false,
    shuffleOnPlay: false,
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

  const trackId = createId();
  const track = {
    id: trackId,
    title,
    loop: false,
    fileName: file.name,
    fileType: file.type,
    fileSize: file.size,
  };

  try {
    track.audioUrl = await saveAudioFile(trackId, file);
  } catch (error) {
    showBackendError(error);
    return;
  }

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

renameForm.addEventListener("submit", (event) => {
  event.preventDefault();
  applyRename();
});

async function loadCampaigns() {
  const response = await fetch(`${apiEndpoint}?action=load`);
  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload.error || "Impossible de charger les campagnes.");
  }
  return normalizeCampaigns(payload.campaigns ?? []);
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
    shuffleOnPlay: playlist.shuffleOnPlay ?? playlist.autoplayShuffleOnOpen ?? false,
    tracks: (playlist.tracks ?? []).map(normalizeTrack),
  };
}

function normalizeTrack(track) {
  return {
    ...track,
    loop: track.loop ?? false,
    audioUrl: track.audioUrl ?? null,
    volume: normalizeVolume(track.volume),
  };
}

async function saveCampaigns() {
  const response = await fetch(`${apiEndpoint}?action=save`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      campaigns: state.campaigns,
    }),
  });
  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload.error || "Impossible de sauvegarder les campagnes.");
  }
}

function persistAndRender() {
  saveCampaigns().catch(showBackendError);
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

async function initializeApp() {
  try {
    state.campaigns = await loadCampaigns();
  } catch (error) {
    showBackendError(error);
  }
  render();
}

function showBackendError(error) {
  console.error(error);
  alert(error.message || "Une erreur serveur est survenue.");
}

function initializeTheme() {
  const savedTheme = localStorage.getItem("suszic-theme") ?? "light";
  document.documentElement.dataset.theme = savedTheme;
  updateThemeToggle(savedTheme);
}

function toggleTheme() {
  const nextTheme = document.documentElement.dataset.theme === "dark" ? "light" : "dark";
  document.documentElement.dataset.theme = nextTheme;
  localStorage.setItem("suszic-theme", nextTheme);
  updateThemeToggle(nextTheme);
}

function updateThemeToggle(theme) {
  themeToggle.textContent = theme === "dark" ? "Mode clair" : "Mode sombre";
}

function createId() {
  if (crypto?.randomUUID) {
    return crypto.randomUUID();
  }

  const randomValues = new Uint8Array(16);
  if (crypto?.getRandomValues) {
    crypto.getRandomValues(randomValues);
  } else {
    for (let index = 0; index < randomValues.length; index += 1) {
      randomValues[index] = Math.floor(Math.random() * 256);
    }
  }

  randomValues[6] = (randomValues[6] & 0x0f) | 0x40;
  randomValues[8] = (randomValues[8] & 0x3f) | 0x80;

  const hex = [...randomValues].map((value) => value.toString(16).padStart(2, "0")).join("");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
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
      state.openPlaylistIds = [];
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

function openRenameModal(type, id, currentName) {
  state.renameTarget = { type, id };
  const labels = {
    folder: "Nom du dossier",
    playlist: "Nom de la playlist",
    track: "Nom de la musique",
  };
  renameLabel.textContent = labels[type] ?? "Nom";
  renameName.value = currentName;
  openModal("rename-modal");
  renameName.focus();
  renameName.select();
}

function applyRename() {
  const campaign = getActiveCampaign();
  const target = state.renameTarget;
  const name = renameName.value.trim();
  if (!campaign || !target || !name) return;

  if (target.type === "folder") {
    if (target.id === "root") {
      closeModal("rename-modal");
      return;
    }
    const folder = campaign.folders.find((item) => item.id === target.id);
    if (folder) folder.name = name;
  }

  if (target.type === "playlist") {
    const playlist = findPlaylist(campaign, target.id);
    if (playlist) playlist.name = name;
  }

  if (target.type === "track") {
    updateTrackEverywhere(campaign, target.id, (track) => {
      track.title = name;
    });
  }

  state.renameTarget = null;
  touch(campaign);
  persistAndRender();
  closeModal("rename-modal");
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

  const interfaceView = document.createElement("div");
  interfaceView.className = "library-interface";

  interfaceView.append(renderFolderPanel(campaign));
  interfaceView.append(renderPlaylistPanel(campaign));
  interfaceView.append(await renderPlaylistDetailStack(campaign));
  libraryTree.append(interfaceView);
}

function ensureSelection(campaign) {
  const folderIds = ["root", ...campaign.folders.map((folder) => folder.id)];
  if (!folderIds.includes(state.selectedFolderId)) {
    state.selectedFolderId = "root";
  }

  state.openPlaylistIds = state.openPlaylistIds.filter((playlistId) => findPlaylist(campaign, playlistId));
  if (state.selectedPlaylistId && !findPlaylist(campaign, state.selectedPlaylistId)) {
    state.selectedPlaylistId = null;
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
  const button = document.createElement("div");
  button.className = `folder-button${state.selectedFolderId === folderId ? " active" : ""}`;
  button.draggable = folderId !== "root";
  button.dataset.folderSelect = folderId;
  button.role = "button";
  button.tabIndex = 0;
  button.innerHTML = `
    <div class="folder-button-header">
      <span>${escapeHtml(name)}</span>
      <div class="folder-actions">
        <button type="button" class="folder-menu-button" data-folder-menu aria-label="Actions pour ${escapeHtml(name)}">⋮</button>
        <div class="folder-action-panel hidden">
          <button type="button" data-folder-action="rename" ${folderId === "root" ? "disabled" : ""}>Modifier le nom</button>
          <button type="button" data-folder-action="duplicate" ${folderId === "root" ? "disabled" : ""}>Duppliquer le dossier</button>
          <button type="button" data-folder-action="delete" ${folderId === "root" ? "disabled" : ""}>Supprimer le dossier</button>
        </div>
      </div>
    </div>
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
      const button = document.createElement("div");
      button.className = `playlist-tile${state.openPlaylistIds.includes(playlist.id) ? " active" : ""}`;
      button.draggable = true;
      button.dataset.playlistSelect = playlist.id;
      button.dataset.folderId = state.selectedFolderId;
      button.role = "button";
      button.tabIndex = 0;
      button.innerHTML = `
        <div class="playlist-tile-top">
          <span></span>
          <div class="playlist-actions">
            <button type="button" class="playlist-menu-button" data-playlist-menu aria-label="Actions pour ${escapeHtml(playlist.name)}">⋮</button>
            <div class="playlist-action-panel hidden">
              <button type="button" data-playlist-action="rename">Modifier le nom</button>
              <button type="button" data-playlist-action="delete">Supprimer la playlist</button>
              <label>
                <span>Duppliquer la playlist dans</span>
                <select data-playlist-duplicate-target></select>
              </label>
              <button type="button" data-playlist-action="duplicate">Duppliquer</button>
            </div>
          </div>
        </div>
        <span class="playlist-disc" aria-hidden="true"></span>
        <strong>${escapeHtml(playlist.name)}</strong>
        <small>${playlist.tracks.length} musique${playlist.tracks.length > 1 ? "s" : ""}</small>
      `;
      const targetSelect = button.querySelector("[data-playlist-duplicate-target]");
      getFolderOptions(campaign).forEach((folder) => {
        targetSelect.append(new Option(folder.label, folder.id));
      });
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
          <input type="checkbox" data-playlist-shuffle="${playlist.id}" ${playlist.shuffleOnPlay ? "checked" : ""}>
          <span>Lancé en aléatoire</span>
        </label>
        <button type="button" class="playlist-play-button" data-play-playlist="${playlist.id}" aria-label="Lancer ${escapeHtml(playlist.name)}">▶</button>
        <button type="button" class="playlist-stop-button" data-stop-playlist="${playlist.id}" aria-label="Arrêter ${escapeHtml(playlist.name)}">Stop</button>
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

async function renderPlaylistDetailStack(campaign) {
  const stack = document.createElement("section");
  stack.className = "playlist-detail-stack";

  if (state.openPlaylistIds.length === 0) {
    stack.innerHTML = `
      <div class="detail-empty">
        <div class="panel-heading">
          <h3>Playlists ouvertes</h3>
          <button type="button" class="panel-add-button" data-open-track-modal aria-label="Ajouter une musique">+</button>
        </div>
        <p>Aucune playlist ouverte.</p>
      </div>
    `;
    return stack;
  }

  for (const playlistId of state.openPlaylistIds) {
    const playlist = findPlaylist(campaign, playlistId);
    if (playlist) {
      stack.append(await renderPlaylistDetailCard(playlist, campaign));
    }
  }

  return stack;
}

async function renderPlaylistDetailCard(playlist, campaign) {
  const panel = document.createElement("article");
  panel.className = "playlist-detail-panel";
  panel.dataset.playlistId = playlist.id;
  panel.innerHTML = `
    <div class="playlist-detail-header">
      <div>
        <h3>${escapeHtml(playlist.name)}</h3>
        <p>${playlist.tracks.length} musique${playlist.tracks.length > 1 ? "s" : ""}</p>
      </div>
      <div class="playlist-controls">
        <button type="button" class="panel-add-button" data-open-track-modal aria-label="Ajouter une musique">+</button>
        <button type="button" class="playlist-close-button" data-close-playlist-detail="${playlist.id}" aria-label="Fermer ${escapeHtml(playlist.name)}">×</button>
        <label class="loop-toggle">
          <input type="checkbox" data-playlist-loop="${playlist.id}" ${playlist.loop ? "checked" : ""}>
          <span>Boucle</span>
        </label>
        <button type="button" class="playlist-play-button" data-play-playlist="${playlist.id}" aria-label="Lancer ${escapeHtml(playlist.name)}">▶</button>
        <button type="button" class="playlist-stop-button" data-stop-playlist="${playlist.id}" aria-label="Arrêter ${escapeHtml(playlist.name)}">Stop</button>
      </div>
    </div>
    <div class="playlist-open-options">
      <label class="loop-toggle">
        <input type="checkbox" data-playlist-shuffle="${playlist.id}" ${playlist.shuffleOnPlay ? "checked" : ""}>
        <span>Lancé en aléatoire</span>
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
    if (state.playlistCurrentTrackIds.get(playlist.id) === track.id) {
      row.classList.add("is-playing");
    }
    row.draggable = true;
    row.dataset.playlistId = playlist.id;
    list.append(row);
  }

  panel.append(list);
  return panel;
}

function openPlaylistDetail(playlistId) {
  const campaign = getActiveCampaign();
  const playlist = findPlaylist(campaign, playlistId);
  if (!playlist) return;

  const wasOpen = state.openPlaylistIds.includes(playlistId);
  if (!wasOpen) {
    state.openPlaylistIds.push(playlistId);
  }
  state.selectedPlaylistId = playlistId;

}

async function openPlaylistDetailInView(playlistId) {
  const campaign = getActiveCampaign();
  const playlist = findPlaylist(campaign, playlistId);
  if (!campaign || !playlist) return;

  openPlaylistDetail(playlistId);
  document.querySelectorAll(`[data-playlist-select="${CSS.escape(playlistId)}"]`).forEach((tile) => {
    tile.classList.add("active");
  });

  const stack = libraryTree.querySelector(".playlist-detail-stack");
  if (!stack || stack.querySelector(`[data-playlist-id="${CSS.escape(playlistId)}"]`)) return;

  const empty = stack.querySelector(".detail-empty");
  if (empty) empty.remove();
  stack.append(await renderPlaylistDetailCard(playlist, campaign));
}

async function closePlaylistDetail(playlistId, fade = false) {
  if (fade) {
    await fadeOutPlaylist(playlistId);
  } else {
    stopPlaylist(playlistId);
  }

  state.openPlaylistIds = state.openPlaylistIds.filter((id) => id !== playlistId);
  if (state.selectedPlaylistId === playlistId) {
    state.selectedPlaylistId = state.openPlaylistIds.at(-1) ?? null;
  }

  const playlistPanel = libraryTree.querySelector(`[data-playlist-id="${CSS.escape(playlistId)}"]`);
  if (!fade) stopPanelPlayers(playlistPanel);
  playlistPanel?.remove();
  document.querySelectorAll(`[data-playlist-select="${CSS.escape(playlistId)}"]`).forEach((tile) => {
    tile.classList.remove("active");
  });

  const stack = libraryTree.querySelector(".playlist-detail-stack");
  if (stack && state.openPlaylistIds.length === 0) {
    stack.innerHTML = `
      <div class="detail-empty">
        <div class="panel-heading">
          <h3>Playlists ouvertes</h3>
          <button type="button" class="panel-add-button" data-open-track-modal aria-label="Ajouter une musique">+</button>
        </div>
        <p>Aucune playlist ouverte.</p>
      </div>
    `;
  }
}

function playPlaylist(playlistId) {
  const campaign = getActiveCampaign();
  const playlist = findPlaylist(campaign, playlistId);
  if (!playlist) return;

  const playlistPanel = libraryTree.querySelector(`[data-playlist-id="${CSS.escape(playlistId)}"]`);
  if (!playlistPanel) return;

  const rows = shuffleArray([...playlistPanel.querySelectorAll(".detail-track")], playlist.shuffleOnPlay);
  const queue = rows
    .map((row) => ({
      trackId: row.dataset.trackId,
      player: row.querySelector("audio.track-player"),
    }))
    .filter((item) => item.player);
  if (queue.length === 0) return;

  stopPlaylist(playlistId);

  queue.forEach(({ player }, index) => {
    player.onended = () => {
      const nextPlayer = queue[index + 1];
      if (nextPlayer) {
        playQueuedTrack(playlistId, nextPlayer);
      } else if (playlist?.loop) {
        playQueuedTrack(playlistId, queue[0]);
      } else {
        state.playlistCurrentTrackIds.delete(playlistId);
        updatePlayingTrackDisplay(playlistId);
      }
    };
  });

  state.playlistRuns.set(playlistId, queue.map((item) => item.player));
  playQueuedTrack(playlistId, queue[0]);
}

function stopPlaylist(playlistId) {
  const players = state.playlistRuns.get(playlistId);
  if (!players) return;

  stopPlayers(players);
  state.playlistRuns.delete(playlistId);
  state.playlistCurrentTrackIds.delete(playlistId);
  updatePlayingTrackDisplay(playlistId);
}

function stopPanelPlayers(playlistPanel) {
  if (!playlistPanel) return;
  stopPlayers([...playlistPanel.querySelectorAll("audio.track-player")]);
}

function stopPlayers(players) {
  players.forEach((player) => {
    player.pause();
    player.currentTime = 0;
    player.onended = null;
    setPlayerVolume(player, getSavedPlayerVolume(player), true);
  });
}

async function fadeOutPlaylist(playlistId) {
  const playlistPanel = libraryTree.querySelector(`[data-playlist-id="${CSS.escape(playlistId)}"]`);
  const players = new Set(state.playlistRuns.get(playlistId) ?? []);
  playlistPanel?.querySelectorAll("audio.track-player").forEach((player) => {
    if (!player.paused && !player.ended) players.add(player);
  });

  if (players.size === 0) {
    stopPlaylist(playlistId);
    stopPanelPlayers(playlistPanel);
    return;
  }

  await fadeOutPlayers([...players], 650);
  state.playlistRuns.delete(playlistId);
  state.playlistCurrentTrackIds.delete(playlistId);
  updatePlayingTrackDisplay(playlistId);
}

function fadeOutPlayers(players, duration) {
  const activePlayers = players.filter((player) => !player.paused && !player.ended);
  if (activePlayers.length === 0) return Promise.resolve();

  const startVolumes = new Map(activePlayers.map((player) => [player, player.volume]));
  const startedAt = performance.now();

  return new Promise((resolve) => {
    function tick(now) {
      const progress = Math.min(Math.max((now - startedAt) / duration, 0), 1);
      activePlayers.forEach((player) => {
        player.dataset.fadingVolume = "true";
        setPlayerVolume(player, (startVolumes.get(player) ?? 1) * (1 - progress), true);
      });

      if (progress < 1) {
        requestAnimationFrame(tick);
        return;
      }

      stopPlayers(activePlayers);
      activePlayers.forEach((player) => {
        delete player.dataset.fadingVolume;
      });
      resolve();
    }

    requestAnimationFrame(tick);
  });
}

function clampVolume(value) {
  return Math.min(Math.max(value, 0), 1);
}

function normalizeVolume(value) {
  const number = Number(value);
  return Number.isFinite(number) ? clampVolume(number) : 1;
}

function getSavedPlayerVolume(player) {
  const item = player.closest(".track-item");
  const campaign = getActiveCampaign();
  const track = item && campaign ? findTrack(campaign, item.dataset.trackId) : null;
  return normalizeVolume(track?.volume);
}

function playQueuedTrack(playlistId, item) {
  state.playlistCurrentTrackIds.set(playlistId, item.trackId);
  updatePlayingTrackDisplay(playlistId);
  setPlayerVolume(item.player, getSavedPlayerVolume(item.player), true);
  item.player.currentTime = 0;
  item.player.play().catch(() => {});
}

function updatePlayingTrackDisplay(playlistId) {
  const playlistPanel = libraryTree.querySelector(`[data-playlist-id="${CSS.escape(playlistId)}"]`);
  if (!playlistPanel) return;

  const currentTrackId = state.playlistCurrentTrackIds.get(playlistId);
  playlistPanel.querySelectorAll(".detail-track").forEach((row) => {
    row.classList.toggle("is-playing", Boolean(currentTrackId) && row.dataset.trackId === currentTrackId);
  });
}

function syncPlayerPlayingState(player, isPlaying) {
  const row = player.closest(".track-item");
  if (!row) return;

  row.classList.toggle("is-playing", isPlaying && !player.paused && !player.ended);
}

function saveTrackVolume(player) {
  if (player.dataset.restoringVolume === "true" || player.dataset.fadingVolume === "true") return;

  const item = player.closest(".track-item");
  const campaign = getActiveCampaign();
  if (!item || !campaign) return;

  const volume = normalizeVolume(player.volume);
  updateTrackEverywhere(campaign, item.dataset.trackId, (track) => {
    track.volume = volume;
  });

  document.querySelectorAll(`[data-track-id="${CSS.escape(item.dataset.trackId)}"] audio.track-player`).forEach((otherPlayer) => {
    if (otherPlayer === player || otherPlayer.dataset.fadingVolume === "true") return;
    setPlayerVolume(otherPlayer, volume, true);
  });

  touch(campaign);
  saveCampaigns().catch(showBackendError);
}

function setPlayerVolume(player, volume, restoring = false) {
  if (restoring) player.dataset.restoringVolume = "true";
  player.volume = normalizeVolume(volume);
  if (restoring) {
    requestAnimationFrame(() => {
      delete player.dataset.restoringVolume;
    });
  }
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
    <button type="button" data-track-action="rename">Modifier le nom</button>
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

  const audioUrl = getAudioUrl(track);
  if (audioUrl) {
    const player = document.createElement("audio");
    player.className = "track-player";
    player.controls = true;
    player.draggable = false;
    player.loop = Boolean(track.loop);
    player.preload = "metadata";
    player.src = audioUrl;
    player.volume = normalizeVolume(track.volume);
    player.addEventListener("play", () => syncPlayerPlayingState(player, true));
    player.addEventListener("pause", () => syncPlayerPlayingState(player, false));
    player.addEventListener("ended", () => syncPlayerPlayingState(player, false));
    player.addEventListener("volumechange", () => saveTrackVolume(player));
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
  saveCampaigns().catch(showBackendError);
}

function updatePlaylistShuffle(input) {
  const campaign = getActiveCampaign();
  const playlist = findPlaylist(campaign, input.dataset.playlistShuffle);
  if (!campaign || !playlist) return;

  playlist.shuffleOnPlay = input.checked;
  document.querySelectorAll(`[data-playlist-shuffle="${CSS.escape(playlist.id)}"]`).forEach((checkbox) => {
    checkbox.checked = playlist.shuffleOnPlay;
  });

  touch(campaign);
  saveCampaigns().catch(showBackendError);
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
  saveCampaigns().catch(showBackendError);
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
  closePlaylistMenus();
  closeFolderMenus();
  panel.classList.toggle("hidden", !isHidden);
}

function closeTrackMenus() {
  document.querySelectorAll(".track-action-panel").forEach((panel) => {
    panel.classList.add("hidden");
  });
}

function togglePlaylistMenu(button) {
  const panel = button.parentElement.querySelector(".playlist-action-panel");
  const isHidden = panel.classList.contains("hidden");
  closePlaylistMenus();
  closeTrackMenus();
  closeFolderMenus();
  panel.classList.toggle("hidden", !isHidden);
}

function closePlaylistMenus() {
  document.querySelectorAll(".playlist-action-panel").forEach((panel) => {
    panel.classList.add("hidden");
  });
}

function toggleFolderMenu(button) {
  const panel = button.parentElement.querySelector(".folder-action-panel");
  const isHidden = panel.classList.contains("hidden");
  closeFolderMenus();
  closePlaylistMenus();
  closeTrackMenus();
  panel.classList.toggle("hidden", !isHidden);
}

function closeFolderMenus() {
  document.querySelectorAll(".folder-action-panel").forEach((panel) => {
    panel.classList.add("hidden");
  });
}

function handleFolderAction(actionButton) {
  const campaign = getActiveCampaign();
  const folderCard = actionButton.closest(".folder-button");
  if (!campaign || !folderCard) return;

  const folderId = folderCard.dataset.folderSelect;
  if (actionButton.dataset.folderAction === "rename") {
    const folder = folderId === "root" ? { name: "Sans dossier" } : campaign.folders.find((item) => item.id === folderId);
    openRenameModal("folder", folderId, folder?.name ?? "");
    return;
  }

  if (folderId === "root") return;

  if (actionButton.dataset.folderAction === "duplicate") {
    duplicateFolder(campaign, folderId);
  }

  if (actionButton.dataset.folderAction === "delete") {
    deleteFolder(campaign, folderId);
  }
}

function duplicateFolder(campaign, folderId) {
  const folder = campaign.folders.find((item) => item.id === folderId);
  if (!folder) return;

  const duplicate = {
    ...folder,
    id: createId(),
    name: `${folder.name} copie`,
    playlists: folder.playlists.map(clonePlaylist),
  };

  campaign.folders.push(duplicate);
  state.selectedFolderId = duplicate.id;
  state.selectedPlaylistId = duplicate.playlists[0]?.id ?? null;
  touch(campaign);
  persistAndRender();
}

function deleteFolder(campaign, folderId) {
  const folderIndex = campaign.folders.findIndex((folder) => folder.id === folderId);
  if (folderIndex < 0) return;

  const [folder] = campaign.folders.splice(folderIndex, 1);
  folder.playlists.forEach((playlist) => {
    stopPlaylist(playlist.id);
    state.openPlaylistIds = state.openPlaylistIds.filter((id) => id !== playlist.id);
  });
  if (state.selectedFolderId === folderId) {
    state.selectedFolderId = "root";
    state.selectedPlaylistId = state.openPlaylistIds.at(-1) ?? null;
  }

  touch(campaign);
  persistAndRender();
}

function handlePlaylistAction(actionButton) {
  const campaign = getActiveCampaign();
  const tile = actionButton.closest(".playlist-tile");
  if (!campaign || !tile) return;

  if (actionButton.dataset.playlistAction === "rename") {
    const playlist = findPlaylist(campaign, tile.dataset.playlistSelect);
    openRenameModal("playlist", tile.dataset.playlistSelect, playlist?.name ?? "");
    return;
  }

  if (actionButton.dataset.playlistAction === "delete") {
    deletePlaylistFromFolder(campaign, tile.dataset.folderId, tile.dataset.playlistSelect);
  }

  if (actionButton.dataset.playlistAction === "duplicate") {
    const targetFolderId = tile.querySelector("[data-playlist-duplicate-target]").value;
    duplicatePlaylistToFolder(campaign, tile.dataset.folderId, tile.dataset.playlistSelect, targetFolderId);
  }
}

function deletePlaylistFromFolder(campaign, folderId, playlistId) {
  const playlists = getPlaylistsByFolderId(campaign, folderId);
  if (!playlists) return;

  const playlistIndex = playlists.findIndex((playlist) => playlist.id === playlistId);
  if (playlistIndex < 0) return;

  playlists.splice(playlistIndex, 1);
  stopPlaylist(playlistId);
  state.openPlaylistIds = state.openPlaylistIds.filter((id) => id !== playlistId);
  if (state.selectedPlaylistId === playlistId) {
    state.selectedPlaylistId = state.openPlaylistIds.at(-1) ?? null;
  }

  touch(campaign);
  persistAndRender();
}

function duplicatePlaylistToFolder(campaign, sourceFolderId, playlistId, targetFolderId) {
  const sourcePlaylists = getPlaylistsByFolderId(campaign, sourceFolderId);
  const targetPlaylists = getPlaylistsByFolderId(campaign, targetFolderId);
  if (!sourcePlaylists || !targetPlaylists) return;

  const playlist = sourcePlaylists.find((item) => item.id === playlistId);
  if (!playlist) return;

  const duplicate = {
    ...clonePlaylist(playlist),
    name: `${playlist.name} copie`,
  };

  targetPlaylists.push(duplicate);
  state.selectedFolderId = targetFolderId;
  state.selectedPlaylistId = duplicate.id;
  touch(campaign);
  persistAndRender();
}

function clonePlaylist(playlist) {
  return {
    ...playlist,
    id: createId(),
    tracks: playlist.tracks.map((track) => ({ ...track })),
  };
}

async function handleTrackAction(actionButton) {
  const campaign = getActiveCampaign();
  const item = actionButton.closest(".track-item");
  if (!campaign || !item) return;

  const trackId = item.dataset.trackId;
  const sourceTrack = findTrack(campaign, trackId);
  if (!sourceTrack) return;

  if (actionButton.dataset.trackAction === "rename") {
    openRenameModal("track", trackId, sourceTrack.title);
    return;
  }

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

async function saveAudioFile(trackId, file) {
  const formData = new FormData();
  formData.append("trackId", trackId);
  formData.append("audio", file);

  const response = await fetch(`${apiEndpoint}?action=upload`, {
    method: "POST",
    body: formData,
  });
  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload.error || "Impossible d'uploader le fichier audio.");
  }
  return payload.url;
}

function getAudioUrl(track) {
  return track.audioUrl ?? null;
}

async function deleteAudioFile(trackId) {
  const response = await fetch(`${apiEndpoint}?action=deleteAudio`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ trackId }),
  });
  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload.error || "Impossible de supprimer le fichier audio.");
  }
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

function getFolderOptions(campaign) {
  return [
    {
      id: "root",
      label: "Sans dossier",
    },
    ...campaign.folders.map((folder) => ({
      id: folder.id,
      label: folder.name,
    })),
  ];
}

function getSelectedFolderPlaylists(campaign) {
  return getPlaylistsByFolderId(campaign, state.selectedFolderId) ?? [];
}

function getPlaylistsByFolderId(campaign, folderId) {
  if (folderId === "root") return campaign.playlists;

  const folder = campaign.folders.find((item) => item.id === folderId);
  return folder?.playlists ?? null;
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

function movePlaylistToFolder(sourceFolderId, playlistId, targetFolderId) {
  const campaign = getActiveCampaign();
  if (!campaign || sourceFolderId === targetFolderId) return;

  const sourcePlaylists = getPlaylistsByFolderId(campaign, sourceFolderId);
  const targetPlaylists = getPlaylistsByFolderId(campaign, targetFolderId);
  if (!sourcePlaylists || !targetPlaylists) return;

  const playlistIndex = sourcePlaylists.findIndex((playlist) => playlist.id === playlistId);
  if (playlistIndex < 0) return;

  const [playlist] = sourcePlaylists.splice(playlistIndex, 1);
  targetPlaylists.push(playlist);
  state.selectedFolderId = targetFolderId;
  state.selectedPlaylistId = playlist.id;
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
  document.querySelectorAll(".drop-before, .drop-after, .drop-target").forEach((row) => {
    row.classList.remove("drop-before", "drop-after", "drop-target");
  });
}

function restoreDraggableTracks() {
  document.querySelectorAll(".detail-track").forEach((row) => {
    row.draggable = true;
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

initializeApp();
