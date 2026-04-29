const themeToggle = document.querySelector("#theme-toggle");

initializeTheme();
if (themeToggle) {
  themeToggle.addEventListener("click", toggleTheme);
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
  if (!themeToggle) return;
  themeToggle.textContent = theme === "dark" ? "Mode clair" : "Mode sombre";
}
