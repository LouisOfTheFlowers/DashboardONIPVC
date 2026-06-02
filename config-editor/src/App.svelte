<script>
  const API_URL = './config-api.php';

  let files = [];
  let selectedFile = '';
  let selectedLabel = '';
  let config = {};
  let originalConfig = '';
  let loading = true;
  let saving = false;
  let error = '';
  let notice = '';
  let activeSection = 'ranges';

  $: rangeSections = collectRangeSections(config);
  $: palette = config?.card_colors && typeof config.card_colors === 'object' ? config.card_colors : {};
  $: hasChanges = JSON.stringify(config) !== originalConfig;
  $: sectionCounts = {
    ranges: rangeSections.length,
    palette: Object.keys(palette).length,
    raw: Object.keys(config ?? {}).length
  };

  loadFiles();

  async function requestJson(url, options = {}) {
    const response = await fetch(url, options);
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
      throw new Error(payload.error || 'O pedido falhou.');
    }

    return payload;
  }

  async function loadFiles() {
    loading = true;
    error = '';

    try {
      const payload = await requestJson(API_URL);
      files = payload.files ?? [];
      selectedFile = files[0]?.file ?? '';

      if (selectedFile) {
        await loadConfig(selectedFile);
      }
    } catch (exception) {
      error = exception.message;
    } finally {
      loading = false;
    }
  }

  async function loadConfig(file) {
    if (!file) return;

    loading = true;
    error = '';
    notice = '';

    try {
      const payload = await requestJson(`${API_URL}?file=${encodeURIComponent(file)}`);
      selectedFile = payload.file;
      selectedLabel = payload.label;
      config = payload.config ?? {};
      originalConfig = JSON.stringify(config);
    } catch (exception) {
      error = exception.message;
    } finally {
      loading = false;
    }
  }

  async function saveConfig() {
    saving = true;
    error = '';
    notice = '';

    try {
      const payload = await requestJson(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file: selectedFile, config })
      });

      config = payload.config ?? config;
      originalConfig = JSON.stringify(config);
      notice = payload.message || 'Configuração guardada.';
    } catch (exception) {
      error = exception.message;
    } finally {
      saving = false;
    }
  }

  function collectRangeSections(source) {
    const sections = [];

    Object.entries(source ?? {}).forEach(([key, value]) => {
      if (isRangeArray(value)) {
        sections.push({
          id: key,
          title: humanize(key),
          subtitle: 'Intervalo global',
          ranges: value,
          path: [key]
        });
      }
    });

    (source?.card_panels ?? []).forEach((panel, panelIndex) => {
      (panel.items ?? []).forEach((item, itemIndex) => {
        ['ranges', 'table_ranges'].forEach((rangeKey) => {
          const ranges = item?.[rangeKey];
          if (!isRangeArray(ranges)) return;

          sections.push({
            id: `${panelIndex}-${itemIndex}-${rangeKey}`,
            title: item.label || item.key || humanize(rangeKey),
            subtitle: `${panel.title || panel.group || 'Painel'} · ${rangeKey === 'table_ranges' ? 'Tabela' : 'Cartão'}`,
            ranges,
            path: ['card_panels', panelIndex, 'items', itemIndex, rangeKey]
          });
        });
      });
    });

    return sections;
  }

  function isRangeArray(value) {
    return Array.isArray(value) && value.every((item) => {
      return item && typeof item === 'object' && ('min' in item || 'max' in item || 'color' in item);
    });
  }

  function humanize(value) {
    return String(value)
      .replace(/_/g, ' ')
      .replace(/\b\w/g, (letter) => letter.toUpperCase());
  }

  function updateRange(path, index, field, value) {
    config = updateAtPath(config, path, (ranges) => {
      return ranges.map((range, rangeIndex) => {
        if (rangeIndex !== index) return range;

        return {
          ...range,
          [field]: field === 'color' || field === 'label' ? value : Number(value)
        };
      });
    });
  }

  function addRange(path) {
    config = updateAtPath(config, path, (ranges) => {
      const last = ranges.at(-1);
      const nextMin = Number.isFinite(Number(last?.max)) ? Number(last.max) + 1 : 0;

      return [
        ...ranges,
        {
          min: nextMin,
          max: nextMin + 10,
          color: Object.keys(palette)[0] || '#2563eb'
        }
      ];
    });
  }

  function removeRange(path, index) {
    config = updateAtPath(config, path, (ranges) => ranges.filter((_, rangeIndex) => rangeIndex !== index));
  }

  function updatePaletteColor(key, color) {
    config = {
      ...config,
      card_colors: {
        ...(config.card_colors ?? {}),
        [key]: color
      }
    };
  }

  function updateAtPath(source, path, callback) {
    if (path.length === 0) return callback(source);

    const [head, ...tail] = path;
    const clone = Array.isArray(source) ? [...source] : { ...source };
    clone[head] = updateAtPath(clone[head], tail, callback);

    return clone;
  }
</script>

<svelte:head>
  <title>Configuração de Alertas</title>
</svelte:head>

<main class="shell">
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark">ON</span>
      <div>
        <h1>Configuração</h1>
        <p>Alertas e intervalos</p>
      </div>
    </div>

    <label class="field">
      <span>Ficheiro</span>
      <select value={selectedFile} onchange={(event) => loadConfig(event.currentTarget.value)} disabled={loading || saving}>
        {#each files as file}
          <option value={file.file}>{file.label}</option>
        {/each}
      </select>
    </label>

    <nav class="tabs" aria-label="Secções">
      <button class:active={activeSection === 'ranges'} onclick={() => activeSection = 'ranges'}>
        Intervalos <span>{sectionCounts.ranges}</span>
      </button>
      <button class:active={activeSection === 'palette'} onclick={() => activeSection = 'palette'}>
        Cores <span>{sectionCounts.palette}</span>
      </button>
      <button class:active={activeSection === 'raw'} onclick={() => activeSection = 'raw'}>
        JSON <span>{sectionCounts.raw}</span>
      </button>
    </nav>
  </aside>

  <section class="content">
    <header class="topbar">
      <div>
        <p class="eyebrow">{selectedFile}</p>
        <h2>{selectedLabel || 'Configuração de alertas'}</h2>
      </div>

      <div class="actions">
        {#if hasChanges}
          <span class="dirty">Alterações por guardar</span>
        {/if}
        <button class="secondary" onclick={() => loadConfig(selectedFile)} disabled={loading || saving || !hasChanges}>Repor</button>
        <button class="primary" onclick={saveConfig} disabled={loading || saving || !hasChanges}>
          {saving ? 'A guardar...' : 'Guardar'}
        </button>
      </div>
    </header>

    {#if error}
      <div class="alert error">{error}</div>
    {/if}

    {#if notice}
      <div class="alert success">{notice}</div>
    {/if}

    {#if loading}
      <div class="empty">A carregar configuração...</div>
    {:else if activeSection === 'ranges'}
      <div class="range-grid">
        {#each rangeSections as section}
          <article class="panel">
            <div class="panel-heading">
              <div>
                <h3>{section.title}</h3>
                <p>{section.subtitle}</p>
              </div>
              <button class="icon-button" title="Adicionar intervalo" onclick={() => addRange(section.path)}>+</button>
            </div>

            <div class="range-table">
              <div class="range-row heading">
                <span>Mín.</span>
                <span>Máx.</span>
                <span>Cor</span>
                <span>Legenda</span>
                <span></span>
              </div>

              {#each section.ranges as range, index}
                <div class="range-row">
                  <input type="number" value={range.min ?? 0} oninput={(event) => updateRange(section.path, index, 'min', event.currentTarget.value)} />
                  <input type="number" value={range.max ?? 0} oninput={(event) => updateRange(section.path, index, 'max', event.currentTarget.value)} />
                  <div class="color-cell">
                    <span class="swatch" style:background={palette[range.color] || range.color || '#64748b'}></span>
                    <input value={range.color ?? ''} oninput={(event) => updateRange(section.path, index, 'color', event.currentTarget.value)} />
                  </div>
                  <input value={range.label ?? ''} placeholder="Opcional" oninput={(event) => updateRange(section.path, index, 'label', event.currentTarget.value)} />
                  <button class="icon-button danger" title="Remover intervalo" onclick={() => removeRange(section.path, index)}>×</button>
                </div>
              {/each}
            </div>
          </article>
        {/each}
      </div>
    {:else if activeSection === 'palette'}
      <div class="palette-grid">
        {#each Object.entries(palette) as [key, color]}
          <label class="color-card">
            <span class="swatch large" style:background={color}></span>
            <span>{key}</span>
            <input type="color" value={color} oninput={(event) => updatePaletteColor(key, event.currentTarget.value)} />
            <input value={color} oninput={(event) => updatePaletteColor(key, event.currentTarget.value)} />
          </label>
        {/each}
      </div>
    {:else}
      <textarea
        class="raw-editor"
        spellcheck="false"
        value={JSON.stringify(config, null, 2)}
        oninput={(event) => {
          try {
            config = JSON.parse(event.currentTarget.value);
            error = '';
          } catch {
            error = 'O JSON ainda não é válido.';
          }
        }}
      ></textarea>
    {/if}
  </section>
</main>

<style>
  :global(*) {
    box-sizing: border-box;
  }

  :global(body) {
    margin: 0;
    min-height: 100vh;
    color: #182033;
    background: #f6f7f9;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  }

  button,
  input,
  select,
  textarea {
    font: inherit;
  }

  .shell {
    display: grid;
    grid-template-columns: 300px minmax(0, 1fr);
    min-height: 100vh;
  }

  .sidebar {
    display: flex;
    flex-direction: column;
    gap: 26px;
    padding: 28px 22px;
    border-right: 1px solid #dde2ea;
    background: #ffffff;
  }

  .brand {
    display: flex;
    align-items: center;
    gap: 14px;
  }

  .brand-mark {
    display: grid;
    width: 48px;
    height: 48px;
    place-items: center;
    border-radius: 8px;
    color: #ffffff;
    background: #174a7c;
    font-weight: 800;
  }

  h1,
  h2,
  h3,
  p {
    margin: 0;
  }

  h1 {
    font-size: 22px;
  }

  .brand p,
  .panel-heading p,
  .eyebrow {
    color: #687386;
    font-size: 13px;
  }

  .field {
    display: grid;
    gap: 8px;
    color: #425066;
    font-size: 13px;
    font-weight: 700;
  }

  select,
  input,
  textarea {
    width: 100%;
    border: 1px solid #ccd4df;
    border-radius: 6px;
    color: #182033;
    background: #ffffff;
  }

  select,
  input {
    min-height: 38px;
    padding: 8px 10px;
  }

  .tabs {
    display: grid;
    gap: 8px;
  }

  .tabs button {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 42px;
    border: 1px solid transparent;
    border-radius: 6px;
    padding: 0 12px;
    color: #344054;
    background: transparent;
    cursor: pointer;
    text-align: left;
  }

  .tabs button.active {
    border-color: #b8c7da;
    background: #eef4fb;
    color: #174a7c;
    font-weight: 800;
  }

  .tabs span {
    min-width: 28px;
    border-radius: 999px;
    padding: 2px 8px;
    background: #e3e8ef;
    text-align: center;
    font-size: 12px;
  }

  .content {
    min-width: 0;
    padding: 28px;
  }

  .topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 22px;
  }

  h2 {
    margin-top: 4px;
    font-size: 28px;
  }

  .actions {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .dirty {
    color: #a15c07;
    font-size: 13px;
    font-weight: 700;
  }

  .primary,
  .secondary,
  .icon-button {
    min-height: 38px;
    border: 1px solid transparent;
    border-radius: 6px;
    padding: 0 14px;
    cursor: pointer;
    font-weight: 800;
  }

  .primary {
    color: #ffffff;
    background: #174a7c;
  }

  .secondary,
  .icon-button {
    color: #1f2a3d;
    background: #ffffff;
    border-color: #ccd4df;
  }

  button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
  }

  .range-grid {
    display: grid;
    gap: 16px;
  }

  .panel {
    border: 1px solid #dde2ea;
    border-radius: 8px;
    background: #ffffff;
    overflow: hidden;
  }

  .panel-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 18px;
    border-bottom: 1px solid #edf0f4;
  }

  h3 {
    font-size: 17px;
  }

  .range-table {
    display: grid;
    min-width: 720px;
  }

  .range-row {
    display: grid;
    grid-template-columns: 110px 110px minmax(180px, 1fr) minmax(160px, 1fr) 48px;
    gap: 10px;
    align-items: center;
    padding: 10px 14px;
    border-bottom: 1px solid #edf0f4;
  }

  .range-row:last-child {
    border-bottom: 0;
  }

  .range-row.heading {
    color: #687386;
    background: #fafbfc;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
  }

  .color-cell {
    display: grid;
    grid-template-columns: 24px minmax(0, 1fr);
    gap: 8px;
    align-items: center;
  }

  .swatch {
    display: block;
    width: 22px;
    height: 22px;
    border: 1px solid #cbd5e1;
    border-radius: 50%;
  }

  .swatch.large {
    width: 36px;
    height: 36px;
  }

  .danger {
    color: #b42318;
  }

  .palette-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 14px;
  }

  .color-card {
    display: grid;
    grid-template-columns: 40px minmax(0, 1fr);
    gap: 10px 12px;
    align-items: center;
    padding: 14px;
    border: 1px solid #dde2ea;
    border-radius: 8px;
    background: #ffffff;
    font-weight: 800;
  }

  .color-card input[type="color"] {
    padding: 2px;
  }

  .color-card input:last-child {
    grid-column: 1 / -1;
  }

  .alert,
  .empty {
    margin-bottom: 16px;
    border-radius: 8px;
    padding: 12px 14px;
    font-weight: 700;
  }

  .alert.error {
    color: #9f1d1d;
    background: #feecec;
  }

  .alert.success {
    color: #17633a;
    background: #e9f8ef;
  }

  .empty {
    color: #425066;
    background: #ffffff;
    border: 1px solid #dde2ea;
  }

  .raw-editor {
    min-height: calc(100vh - 170px);
    padding: 16px;
    font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace;
    font-size: 13px;
    line-height: 1.55;
    resize: vertical;
  }

  @media (max-width: 900px) {
    .shell {
      grid-template-columns: 1fr;
    }

    .sidebar {
      border-right: 0;
      border-bottom: 1px solid #dde2ea;
    }

    .topbar,
    .actions {
      align-items: stretch;
      flex-direction: column;
    }

    .content {
      padding: 18px;
      overflow-x: auto;
    }
  }
</style>
