(function () {
  function isDark() {
    return document.documentElement.getAttribute('data-theme') === 'dark';
  }

  function updateBtn() {
    var dark = isDark();
    document.querySelectorAll('.theme-btn').forEach(function (btn) {
      btn.textContent = dark ? '\u2600\uFE0F' : '\uD83C\uDF19';
      btn.setAttribute('title', dark ? 'Light mode' : 'Dark mode');
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
