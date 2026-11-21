const broadcastEndpoint = "broadcast.php";
const room = new URLSearchParams(window.location.search).get("room") ?? "";
const roomQuery = `room=${encodeURIComponent(room)}`;
const audioFadeOutDuration = 1000;

const diffusionState = {
  enabledByUser: false,
  players: new Map(),
  fadeFrames: new Map(),
  lastState: null,
  lastStateReceivedAt: 0,
  responseTravelTime: 0,
};

const playButton = document.querySelector("#diffusion-play");
const volumeInput = document.querySelector("#diffusion-volume");
const statusText = document.querySelector("#diffusion-status-text");
const liveDot = document.querySelector("#diffusion-live-dot");
const title = document.querySelector("#diffusion-title");
const timeLabel = document.querySelector("#diffusion-time");

playButton.addEventListener("click", async () => {
  diffusionState.enabledByUser = true;
  await applyBroadcastState(diffusionState.lastState, true);
});

volumeInput.addEventListener("input", () => {
  updateListenerVolume();
});

async function pollBroadcast() {
  try {
    const requestedAt = performance.now();
    const response = await fetch(`${broadcastEndpoint}?action=state&${roomQuery}`, { cache: "no-store" });
    if (response.status === 404) {
      clearPlayers();
      showOfflineState("Salle de diffusion introuvable");
      return;
    }
    if (!response.ok) throw new Error("Diffusion inaccessible");
    const state = await response.json();
    diffusionState.lastState = state;
    diffusionState.lastStateReceivedAt = performance.now();
    diffusionState.responseTravelTime = (diffusionState.lastStateReceivedAt - requestedAt) / 2000;
    applyBroadcastState(state, false).catch(() => {
      clearPlayers();
      showOfflineState("Lecture interrompue");
    });
  } catch (error) {
    clearPlayers();
    showOfflineState("Connexion interrompue");
  }
  window.setTimeout(pollBroadcast, 1500);
}

async function applyBroadcastState(state, userRequestedPlay) {
  const tracks = getStateTracks(state);
  if (!state?.live) {
    clearPlayers();
    showOfflineState("Diffusion hors ligne");
    return;
  }

  playButton.disabled = tracks.length === 0;
  const activeKeys = new Set();
  const playPromises = [];
  tracks.forEach((track) => {
    const key = getTrackKey(track);
    activeKeys.add(key);
    const player = getOrCreatePlayer(key, track);
    const expectedTime = getExpectedTime(track, state);

    player.dataset.sourceVolume = String(normalizeVolume(track.volume));
    updatePlayerVolume(player);

    if (Math.abs(player.currentTime - expectedTime) > 1.2) {
      player.currentTime = expectedTime;
    }

    if (diffusionState.enabledByUser || userRequestedPlay) {
      playPromises.push(player.play());
    }
  });

  removeInactivePlayers(activeKeys);
  updateStatus(tracks, state);

  if (playPromises.length === 0) return;

  try {
    await Promise.all(playPromises);
  } catch (error) {
    diffusionState.enabledByUser = false;
    playButton.textContent = "Écouter";
  }
}

function getStateTracks(state) {
  if (Array.isArray(state?.tracks)) {
    return state.tracks.filter((track) => track?.file);
  }

  if (state?.file) {
    return [{
      trackId: state.trackId,
      title: state.title,
      file: state.file,
      currentTime: state.currentTime,
      duration: state.duration,
      volume: state.volume,
    }];
  }

  return [];
}

function getOrCreatePlayer(key, track) {
  const existing = diffusionState.players.get(key);
  if (existing) {
    cancelPlayerFade(key, existing);
    return existing;
  }

  const player = new Audio(`audio.php?${roomQuery}&file=${encodeURIComponent(track.file)}`);
  player.preload = "auto";
  player.dataset.sourceVolume = String(normalizeVolume(track.volume));
  updatePlayerVolume(player);
  diffusionState.players.set(key, player);
  return player;
}

function removeInactivePlayers(activeKeys) {
  diffusionState.players.forEach((player, key) => {
    if (activeKeys.has(key)) return;
    fadeOutAndRemovePlayer(key, player);
  });
}

function clearPlayers() {
  removeInactivePlayers(new Set());
}

function updateStatus(tracks, state) {
  const count = tracks.length;
  const liveStatus = count > 1 ? `${count} musiques en direct` : "Diffusion en direct";
  statusText.textContent = count === 0 ? "En direct · en attente de musique" : liveStatus;
  liveDot.classList.add("is-live");
  playButton.textContent = diffusionState.enabledByUser ? "Synchroniser" : "Écouter";

  title.textContent = state.campaignName || "Campagne en direct";

  const expectedTimes = tracks.map((track) => getExpectedTime(track, state));
  timeLabel.textContent = formatTime(Math.max(0, ...expectedTimes));
}

function showOfflineState(message) {
  statusText.textContent = message;
  title.textContent = "Aucune musique";
  liveDot.classList.remove("is-live");
  playButton.textContent = "Écouter";
  playButton.disabled = true;
  timeLabel.textContent = "00:00";
}

function updateListenerVolume() {
  diffusionState.players.forEach(updatePlayerVolume);
}

function updatePlayerVolume(player) {
  const listenerVolume = normalizeVolume(volumeInput.value);
  const sourceVolume = normalizeVolume(player.dataset.sourceVolume);
  const fadeVolume = normalizeVolume(player.dataset.fadeVolume ?? 1);
  player.volume = listenerVolume * sourceVolume * fadeVolume;
}

function fadeOutAndRemovePlayer(key, player) {
  if (player.dataset.fadingOut === "true") return;

  player.dataset.fadingOut = "true";
  const startedAt = performance.now();

  function tick(now) {
    if (diffusionState.players.get(key) !== player) return;

    const progress = Math.min(Math.max((now - startedAt) / audioFadeOutDuration, 0), 1);
    player.dataset.fadeVolume = String(1 - progress);
    updatePlayerVolume(player);

    if (progress < 1) {
      diffusionState.fadeFrames.set(key, requestAnimationFrame(tick));
      return;
    }

    player.pause();
    player.removeAttribute("src");
    player.load();
    diffusionState.players.delete(key);
    diffusionState.fadeFrames.delete(key);
  }

  diffusionState.fadeFrames.set(key, requestAnimationFrame(tick));
}

function cancelPlayerFade(key, player) {
  const frame = diffusionState.fadeFrames.get(key);
  if (frame) cancelAnimationFrame(frame);
  diffusionState.fadeFrames.delete(key);
  delete player.dataset.fadingOut;
  delete player.dataset.fadeVolume;
  updatePlayerVolume(player);
}

function getExpectedTime(track, state) {
  const serverTime = Number(state.serverTime || state.updatedAt || 0);
  const timeSinceReceipt = (performance.now() - diffusionState.lastStateReceivedAt) / 1000;
  const elapsed = serverTime - Number(state.updatedAt || serverTime) + diffusionState.responseTravelTime + timeSinceReceipt;
  const currentTime = Number(track.currentTime || 0) + Math.max(elapsed, 0);
  const duration = Number(track.duration || 0);
  return duration > 0 ? Math.min(currentTime, duration) : currentTime;
}

function getTrackKey(track) {
  return `${track.instanceId || track.trackId || track.file}:${track.file}`;
}

function normalizeVolume(value) {
  const number = Number(value);
  return Number.isFinite(number) ? Math.min(Math.max(number, 0), 1) : 1;
}

function formatTime(seconds) {
  const safeSeconds = Math.max(0, Math.floor(Number(seconds) || 0));
  const minutes = String(Math.floor(safeSeconds / 60)).padStart(2, "0");
  const remainder = String(safeSeconds % 60).padStart(2, "0");
  return `${minutes}:${remainder}`;
}

pollBroadcast();
