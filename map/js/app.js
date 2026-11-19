(() => {
  window.MapApp = Object.freeze({
    name: 'Sus20',
    version: '0.4.1-anu',
    campaignApi: 'api/campaigns.php'
  });

  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const message = form.dataset.confirm;
      if (message && !window.confirm(message)) event.preventDefault();
    });
  });
})();
