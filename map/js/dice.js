(() => {
  const root = document.querySelector('#campaign-dice-app');
  if (!root) return;

  const state = {
    rolls: [],
    selectedRollId: null,
    loading: false,
  };

  const campaignId = Number(root.dataset.campaignId);
  const apiUrl = root.dataset.apiUrl;
  const csrfToken = root.dataset.csrfToken;
  const form = document.querySelector('#dice-form');
  const expressionInput = document.querySelector('#dice-expression');
  const modeInput = document.querySelector('#dice-mode');
  const submitButton = document.querySelector('#dice-submit');
  const requestStatus = document.querySelector('#dice-request-status');
  const resultEmpty = document.querySelector('#dice-result-empty');
  const resultPanel = document.querySelector('#dice-result');
  const resultExpression = document.querySelector('#dice-result-expression');
  const resultTotal = document.querySelector('#dice-result-total');
  const resultDetails = document.querySelector('#dice-result-details');
  const resultSource = document.querySelector('#dice-result-source');
  const resultMeta = document.querySelector('#dice-result-meta');
  const fallbackReason = document.querySelector('#dice-fallback-reason');
  const historyStatus = document.querySelector('#dice-history-status');
  const historyContainer = document.querySelector('#dice-history');
  const refreshButton = document.querySelector('#dice-history-refresh');

  form.addEventListener('submit', rollDice);
  refreshButton.addEventListener('click', () => loadHistory(true));
  document.querySelectorAll('[data-dice-preset]').forEach((button) => {
    button.addEventListener('click', () => {
      expressionInput.value = button.dataset.dicePreset;
      modeInput.value = 'normal';
      expressionInput.focus();
    });
  });

  loadHistory();
  const refreshTimer = window.setInterval(() => {
    if (!document.hidden) loadHistory(false);
  }, 5000);
  window.addEventListener('beforeunload', () => window.clearInterval(refreshTimer));

  async function apiRequest(method, body = null) {
    const url = method === 'GET'
      ? `${apiUrl}?campaignId=${encodeURIComponent(campaignId)}&limit=50`
      : apiUrl;
    const options = {
      method,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    };

    if (body !== null) {
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = csrfToken;
      options.body = JSON.stringify(body);
    }

    const response = await fetch(url, options);
    let payload;

    try {
      payload = await response.json();
    } catch {
      throw new Error('Le serveur a renvoye une reponse illisible.');
    }

    if (!response.ok || payload.success !== true) {
      throw new Error(payload.error || 'Le lancer a echoue.');
    }

    return payload;
  }

  async function loadHistory(showLoading = true) {
    if (state.loading) return;
    state.loading = true;
    refreshButton.disabled = true;
    if (showLoading) historyStatus.textContent = 'Actualisation...';

    try {
      const payload = await apiRequest('GET');
      state.rolls = payload.data.rolls;
      historyStatus.textContent = `${state.rolls.length} lancer${state.rolls.length > 1 ? 's' : ''} recent${state.rolls.length > 1 ? 's' : ''}`;
      renderHistory();

      const selectedRoll = state.rolls.find((roll) => roll.id === state.selectedRollId);
      if (selectedRoll) {
        renderSelectedRoll(selectedRoll);
      } else if (state.rolls.length > 0) {
        selectRoll(state.rolls[0]);
      } else {
        showEmptyResult();
      }
    } catch (error) {
      historyStatus.textContent = error.message;
      historyStatus.classList.add('dice-status-error');
    } finally {
      state.loading = false;
      refreshButton.disabled = false;
    }
  }

  async function rollDice(event) {
    event.preventDefault();
    const expression = expressionInput.value.trim();
    if (!expression) return;

    submitButton.disabled = true;
    requestStatus.textContent = 'Generation du hasard et enregistrement...';
    requestStatus.dataset.status = 'pending';

    try {
      const payload = await apiRequest('POST', {
        action: 'roll',
        campaignId,
        expression,
        mode: modeInput.value,
      });
      const roll = payload.data.roll;
      state.rolls = [roll, ...state.rolls.filter((item) => item.id !== roll.id)].slice(0, 50);
      selectRoll(roll);
      renderHistory();
      historyStatus.textContent = `${state.rolls.length} lancer${state.rolls.length > 1 ? 's' : ''} recent${state.rolls.length > 1 ? 's' : ''}`;
      requestStatus.textContent = roll.randomSource === 'anu_quantum'
        ? 'Lancer quantique enregistre.'
        : 'Lancer cryptographique enregistre.';
      requestStatus.dataset.status = 'success';
    } catch (error) {
      requestStatus.textContent = error.message;
      requestStatus.dataset.status = 'error';
    } finally {
      submitButton.disabled = false;
      expressionInput.focus();
    }
  }

  function selectRoll(roll) {
    state.selectedRollId = roll.id;
    renderSelectedRoll(roll);
    renderHistory();
  }

  function showEmptyResult() {
    state.selectedRollId = null;
    resultPanel.classList.add('hidden');
    resultEmpty.classList.remove('hidden');
  }

  function renderSelectedRoll(roll) {
    resultEmpty.classList.add('hidden');
    resultPanel.classList.remove('hidden');
    resultExpression.textContent = roll.canonicalExpression;
    resultTotal.textContent = String(roll.total);
    resultDetails.replaceChildren();
    renderRollTerms(resultDetails, roll.result.terms);

    const quantum = roll.randomSource === 'anu_quantum';
    resultSource.textContent = quantum
      ? 'Quantique · ANU QRNG'
      : roll.fallbackReason
        ? 'Secours cryptographique'
        : 'Aleatoire cryptographique';
    resultSource.className = `dice-source-badge ${quantum ? 'dice-source-quantum' : 'dice-source-system'}`;
    resultMeta.textContent = `${roll.roller.displayName} · ${formatDate(roll.createdAt)} · ${roll.randomSourceDetail}`;
    fallbackReason.textContent = roll.fallbackReason ?? '';
    fallbackReason.classList.toggle('hidden', !roll.fallbackReason);
  }

  function renderRollTerms(container, terms) {
    terms.forEach((term, index) => {
      const row = document.createElement('div');
      row.className = 'dice-term-row';
      const label = document.createElement('strong');

      if (term.type === 'constant') {
        label.textContent = `${term.sign < 0 ? '−' : index === 0 ? '' : '+'}${term.value}`;
        row.append(label);
      } else {
        const sign = term.sign < 0 ? '−' : index === 0 ? '' : '+';
        const keep = term.keepMode ? `${term.keepMode}${term.keepCount}` : '';
        label.textContent = `${sign}${term.count}d${term.sides}${keep}`;
        row.append(label);

        const rolls = document.createElement('span');
        rolls.className = 'dice-values';
        term.rolls.forEach((die) => {
          const value = document.createElement('span');
          value.className = `dice-value ${die.kept ? 'dice-value-kept' : 'dice-value-dropped'}`;
          value.textContent = die.value;
          value.title = die.kept ? 'De conserve' : 'De ecarte';
          rolls.append(value);
        });
        row.append(rolls);
      }

      const subtotal = document.createElement('span');
      subtotal.className = 'dice-term-subtotal';
      subtotal.textContent = `= ${term.signedSubtotal}`;
      row.append(subtotal);
      container.append(row);
    });
  }

  function renderHistory() {
    historyContainer.replaceChildren();
    historyStatus.classList.remove('dice-status-error');

    if (state.rolls.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'empty-state';
      empty.textContent = 'Aucun lancer dans cette campagne.';
      historyContainer.append(empty);
      return;
    }

    state.rolls.forEach((roll) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'dice-history-item';
      if (roll.id === state.selectedRollId) button.classList.add('dice-history-item-active');
      button.addEventListener('click', () => selectRoll(roll));

      const total = document.createElement('strong');
      total.className = 'dice-history-total';
      total.textContent = roll.total;

      const body = document.createElement('span');
      body.className = 'dice-history-body';
      const expression = document.createElement('strong');
      expression.textContent = roll.canonicalExpression;
      const metadata = document.createElement('span');
      metadata.textContent = `${roll.roller.displayName} · ${formatDate(roll.createdAt)}`;
      body.append(expression, metadata);

      const source = document.createElement('span');
      source.className = `dice-history-source ${roll.randomSource === 'anu_quantum' ? 'dice-source-quantum' : 'dice-source-system'}`;
      source.textContent = roll.randomSource === 'anu_quantum' ? 'ANU' : 'Crypto';

      button.append(total, body, source);
      historyContainer.append(button);
    });
  }

  function formatDate(value) {
    const normalized = value.includes('T') ? value : `${value.replace(' ', 'T')}Z`;
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat('fr-FR', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    }).format(date);
  }
})();
