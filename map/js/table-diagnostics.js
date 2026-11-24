(() => {
  const button = document.querySelector('#table-diagnostics-toggle');
  if (!button) return;

  const maxEvents = 2000;
  let recording = null;
  let observer = null;
  const ms = value => Math.round(value * 10) / 10;

  function add(report, start, event) {
    if (report !== recording) return;
    if (report.events.length === maxEvents) {
      report.events.shift();
      report.droppedEvents++;
    }
    report.events.push({atMs: ms(start - report.start), ...event});
  }

  async function json(label, url, options) {
    const report = recording;
    if (!report) {
      const response = await fetch(url, options);
      return {response, payload: await response.json()};
    }

    const headers = new Headers(options?.headers);
    headers.set('X-Map-Diagnostic', '1');
    const start = performance.now();
    const event = {kind:'request', label, method:options?.method || 'GET'};
    try {
      const response = await fetch(url, {...options, headers});
      const received = performance.now();
      event.status = response.status;
      event.headersMs = ms(received - start);
      event.serverTiming = response.headers.get('Server-Timing');
      const payload = await response.json();
      event.bodyAndParseMs = ms(performance.now() - received);
      const entry = performance.getEntriesByName(new URL(url, location.href).href, 'resource')
        .find(item => item.initiatorType === 'fetch' && item.startTime >= start && item.startTime <= received);
      if (entry) {
        event.decodedBytes = entry.decodedBodySize;
        event.transferredBytes = entry.transferSize;
        event.beforeNetworkRequestMs = ms(entry.requestStart - entry.startTime);
        event.networkWaitMs = ms(entry.responseStart - entry.requestStart);
        event.networkDownloadMs = ms(entry.responseEnd - entry.responseStart);
      }
      return {response, payload};
    } catch (error) {
      event.error = error.name;
      throw error;
    } finally {
      event.totalMs = ms(performance.now() - start);
      add(report, start, event);
    }
  }

  function measure(label, callback, details) {
    const report = recording;
    if (!report) return callback();
    const start = performance.now();
    try {
      return callback();
    } finally {
      add(report, start, {kind:'render', label, durationMs:ms(performance.now() - start), ...details});
    }
  }

  function recordLongTasks(report, entries) {
    for (const entry of entries) {
      if (entry.startTime >= report.start) {
        add(report, entry.startTime, {kind:'long-task', durationMs:ms(entry.duration)});
      }
    }
  }

  button.addEventListener('click', () => {
    if (recording) {
      if (observer) {
        recordLongTasks(recording, observer.takeRecords());
        observer.disconnect();
        observer = null;
      }
      const {start, ...report} = recording;
      recording = null;
      button.textContent = 'Démarrer le diagnostic';
      const file = new Blob([JSON.stringify({...report, durationMs:ms(performance.now() - start)}, null, 2)], {type:'application/json'});
      const url = URL.createObjectURL(file);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'diagnostic-table-' + report.startedAt.replace(/[:.]/g, '-') + '.json';
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      return;
    }

    recording = {version:1, startedAt:new Date().toISOString(), start:performance.now(), droppedEvents:0, events:[]};
    performance.setResourceTimingBufferSize?.(3000);
    if (window.PerformanceObserver?.supportedEntryTypes?.includes('longtask')) {
      const report = recording;
      observer = new PerformanceObserver(list => recordLongTasks(report, list.getEntries()));
      observer.observe({entryTypes:['longtask']});
    }
    button.textContent = 'Télécharger le diagnostic';
  });

  window.MapTableDiagnostics = {json, measure};
  button.hidden = false;
})();
