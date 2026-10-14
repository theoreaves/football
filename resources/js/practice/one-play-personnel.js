export function personnelRequest(form, choices) {
    const data = new FormData(form);
    // Keep only assignments that are still offered by the current formation.
    for (const key of [...data.keys()]) if (key.startsWith('one_play[')) data.delete(key);
    for (const [side, roles] of Object.entries(choices)) {
        for (const [role, id] of Object.entries(roles)) if (id) data.set(`one_play[${side}][${role}]`, String(id));
    }
    return data;
}

export function filterPersonnel(pool, query = '') {
    const term = query.trim().toLowerCase();
    return pool.filter(player => `${player.number ?? ''} ${player.name} ${player.position}`.toLowerCase().includes(term));
}

export function prunePersonnel(choices, teams) {
    return Object.fromEntries(Object.entries(choices).map(([side, roles]) => [side,
        Object.fromEntries(Object.entries(roles).filter(([role]) => teams[side]?.players[role]))]));
}

export function createOnePlayPersonnel(root, form) {
    if (!root.dataset.personnelUrl) return {render() {}, cancel() {}, ready: () => true};
    const inputs = document.createElement('div');
    inputs.hidden = true;
    form.append(inputs);
    let choices = {}, host, submit, controller, generation = 0, pending = false, failed = false;
    let search = '', expanded = false, signature = '';
    const names = JSON.parse(root.dataset.teamNames || '{}');
    form.addEventListener('submit', event => {
        if (pending || failed) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
    const count = () => Object.values(choices).reduce((n, roles) => n + Object.keys(roles).length, 0);
    const sync = () => {
        inputs.replaceChildren();
        for (const [side, roles] of Object.entries(choices)) for (const [role, id] of Object.entries(roles)) {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = `one_play[${side}][${role}]`; input.value = id;
            inputs.append(input);
        }
    };
    function show(data) {
        host.replaceChildren();
        if (!data.supported || !Object.keys(data.teams).length) {
            choices = {}; sync();
            host.textContent = 'One-play personnel changes are available for ordinary scrimmage plays.';
            return;
        }
        choices = prunePersonnel(choices, data.teams); sync();
        const details = document.createElement('details'); details.open = expanded;
        details.className = 'personnel-details';
        details.addEventListener('toggle', () => { expanded = details.open; });
        const summary = document.createElement('summary');
        summary.textContent = `Personnel${count() ? ` · ${count()} changes` : ' · View players / replace for this play'}`;
        details.append(summary);
        const note = document.createElement('p');
        note.textContent = 'For this play only. Automatic uses the selected Team Play, current lineup and specialists. Any roster position can fill a role; actual player attributes apply.';
        note.className = 'personnel-note'; details.append(note);
        const searchLabel = document.createElement('label'); searchLabel.textContent = 'Find a replacement';
        const searchInput = document.createElement('input'); searchInput.type = 'search'; searchInput.placeholder = 'Name, number or position'; searchInput.value = search;
        searchInput.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); });
        searchLabel.append(searchInput); details.append(searchLabel);
        const selects = [];
        function options(select, team, role) {
            const selected = choices[team]?.[role] || '';
            select.replaceChildren(new Option('Automatic / current lineup', ''));
            const pool = data.teams[team].pool;
            const visible = filterPersonnel(pool, search);
            const chosen = pool.find(player => player.id === Number(selected));
            if (chosen && !visible.some(player => player.id === chosen.id)) visible.unshift(chosen);
            for (const player of visible) {
                const otherRole = Object.entries(choices[team] || {}).find(([key, id]) => key !== role && Number(id) === player.id)?.[0];
                const option = new Option(`#${player.number ?? '—'} ${player.name} · ${player.position}${player.unavailable ? ` · ${player.unavailable}` : ` · ${player.fatigue}% fatigue`}${otherRole ? ` · selected at ${otherRole}` : ''}`, String(player.id));
                option.disabled = Boolean(player.unavailable || otherRole); select.append(option);
            }
            select.value = String(selected);
        }
        for (const [team, unit] of Object.entries(data.teams)) {
            const section = document.createElement('section'); section.className = 'personnel-team';
            const title = document.createElement('h3'); title.textContent = `${names[team] || team} · ${unit.unit}`; section.append(title);
            const list = document.createElement('div'); list.className = 'personnel-grid'; section.append(list);
            for (const [role, player] of Object.entries(unit.players)) {
                const row = document.createElement('label'); row.className = 'personnel-row';
                const position = document.createElement('strong'); position.textContent = role;
                const current = document.createElement('span'); current.textContent = `#${player.number ?? '—'} ${player.name} · ${player.position}`;
                const select = document.createElement('select'); select.setAttribute('aria-label', `${unit.unit} ${role} replacement for this play`);
                options(select, team, role); selects.push({select, team, role});
                select.addEventListener('change', () => {
                    choices[team] ??= {};
                    if (select.value) choices[team][role] = Number(select.value); else delete choices[team][role];
                    expanded = true; sync(); refresh();
                });
                row.append(position, current, select); list.append(row);
            }
            details.append(section);
        }
        searchInput.addEventListener('input', () => { search = searchInput.value; selects.forEach(({select, team, role}) => options(select, team, role)); });
        const reset = document.createElement('button'); reset.type = 'button'; reset.textContent = 'Reset all to Automatic';
        reset.addEventListener('click', () => { choices = {}; sync(); refresh(); }); details.append(reset);
        for (const notice of data.notices || []) {
            const message = document.createElement('p'); message.className = 'personnel-note'; message.textContent = notice; host.append(message);
        }
        host.append(details);
    }
    async function refresh() {
        controller?.abort(); controller = new AbortController();
        const revision = ++generation;
        pending = true; failed = false; submit.disabled = true;
        host.setAttribute('aria-busy', 'true');
        host.replaceChildren(); host.textContent = 'Loading current personnel…';
        try {
            const response = await fetch(root.dataset.personnelUrl, {method:'POST', headers:{Accept:'application/json'}, body:personnelRequest(form, choices), signal:controller.signal});
            const data = await response.json();
            if (!response.ok) throw new Error(data.errors ? Object.values(data.errors).flat().join(' ') : (data.message || 'Unable to load personnel.'));
            if (revision !== generation) return;
            show(data);
        } catch (error) {
            if (revision !== generation || error.name === 'AbortError') return;
            failed = true; host.replaceChildren();
            const message = document.createElement('p'); message.setAttribute('role', 'alert'); message.textContent = error.message || 'Unable to load personnel. Try again.';
            const retry = document.createElement('button'); retry.type = 'button'; retry.textContent = 'Retry'; retry.onclick = refresh;
            const reset = document.createElement('button'); reset.type = 'button'; reset.textContent = 'Clear replacements and retry'; reset.onclick = () => {choices = {}; sync(); refresh();};
            host.append(message, retry, reset);
        } finally {
            if (revision === generation) {pending = false; host.removeAttribute('aria-busy'); submit.disabled = failed;}
        }
    }
    return {
        render(container, button) {
            host = container; submit = button;
            const nextSignature = ['call','offense_formation','defense_formation','team_play[home]','team_play[away]'].map(key => form.elements.namedItem(key)?.value || '').join(':');
            if (signature && nextSignature !== signature) { choices = {}; sync(); }
            signature = nextSignature;
            refresh();
        },
        cancel() { ++generation; controller?.abort(); pending = false; },
        ready: () => !pending && !failed,
    };
}
