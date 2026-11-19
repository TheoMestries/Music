(() => {
  const link = document.querySelector('#invite-url');
  if (link) {
    link.value = new URL(link.value,location.href).href;
    const output = document.querySelector('#invite-status');
    document.querySelector('#copy-invite').addEventListener('click',async () => {
      try {
        await navigator.clipboard.writeText(link.value);
        output.textContent = 'Lien copié.';
      } catch {
        link.focus();
        link.select();
        output.textContent = 'Copiez le lien sélectionné.';
      }
    });
  }
  const search = document.querySelector('#member-search');
  if (search) {
    const members = [...document.querySelectorAll('[data-member]')];
    search.addEventListener('input',() => {
      const query = search.value.trim().toLocaleLowerCase('fr');
      for (const member of members) {
        member.hidden = !member.querySelector('[data-member-name]').textContent.toLocaleLowerCase('fr').includes(query);
      }
    });
  }
})();
