(function () {
  function isDark() {
    return document.documentElement.getAttribute('data-theme') === 'dark';
  }

  // Build the toggle's inner markup once (track icons + sliding knob).
  // The knob itself shows no icon — it's a pure 3D sun/moon-coloured orb,
  // matching the reference design — the track icons sit either side and
  // fade in/out depending on theme.
  function renderToggle(el) {
    if (el.dataset.rendered === '1') return;
    el.innerHTML =
      '<span class="toggle-track-icon sun-track">☀️</span>' +
      '<span class="toggle-track-icon moon-track">🌙</span>' +
      '<span class="toggle-knob"></span>';
    el.dataset.rendered = '1';
  }

  function updateBtn() {
    document.querySelectorAll('.theme-toggle').forEach(function (el) {
      renderToggle(el);
      el.setAttribute('aria-checked', isDark() ? 'true' : 'false');
      el.setAttribute('title', isDark() ? 'Switch to light mode' : 'Switch to dark mode');
    });
  }

  updateBtn();

  window.toggleTheme = function () {
    var dark = isDark();
    document.documentElement.setAttribute('data-theme', dark ? '' : 'dark');
    localStorage.setItem('theme', dark ? 'light' : 'dark');
    updateBtn();
  };

  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
    if (!localStorage.getItem('theme')) {
      document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : '');
      updateBtn();
    }
  });
})();
