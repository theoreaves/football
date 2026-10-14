import {filterPersonnel} from './practice/one-play-personnel.js';

export function mountTeamPlayEditors(doc = document) {
    doc.querySelectorAll('[data-team-play-editor]').forEach(form => {
        if (form.dataset.mounted) return;
        form.dataset.mounted = 'true';
        const catalogue = JSON.parse(form.dataset.catalogue), pool = JSON.parse(form.dataset.players), draft = JSON.parse(form.dataset.draft);
        const field = name => form.elements.namedItem(name);
        const positions = form.querySelector('[data-team-play-positions]'), search = form.querySelector('[data-team-play-search]');
        let selections = {...draft.personnel};
        const playerOptions = (select, role) => {
            const current = selections[role] || '';
            const players = filterPersonnel(pool, search.value);
            const selected = pool.find(player => player.id === Number(current));
            if (selected && !players.some(player => player.id === selected.id)) players.unshift(selected);
            select.replaceChildren(new Option('Automatic', ''));
            players.forEach(player => {
                const option = new Option(`#${player.number ?? '—'} ${player.name} · ${player.position}`, String(player.id));
                option.disabled = Object.entries(selections).some(([other,id]) => other !== role && Number(id) === player.id);
                select.append(option);
            });
            select.value = String(current);
        };
        const renderRoles = () => {
            positions.replaceChildren();
            const roles = catalogue[field('kind').value]?.[field('formation').value]?.roles || [];
            for (const role of roles) {
                const label = document.createElement('label'); label.textContent = role;
                const select = document.createElement('select'); select.name = `personnel[${role}]`;
                playerOptions(select,role);
                select.addEventListener('change', () => {
                    if (select.value) selections[role] = Number(select.value); else delete selections[role];
                    positions.querySelectorAll('select').forEach(item => playerOptions(item,item.name.slice(10,-1)));
                });
                label.append(select); positions.append(label);
            }
        };
        const renderCalls = () => {
            const previous = field('call').value;
            const calls = catalogue[field('kind').value]?.[field('formation').value]?.calls || [];
            field('call').replaceChildren(...calls.map(call => new Option(call.replaceAll('_',' '),call)));
            if (calls.includes(previous)) field('call').value = previous;
        };
        field('kind').addEventListener('change', () => {
            selections = {};
            field('formation').replaceChildren(...Object.entries(catalogue[field('kind').value]).map(([value,entry])=>new Option(entry.label,value)));
            form.querySelectorAll('[data-defense-profile]').forEach(label => {label.hidden=field('kind').value!=='defense';});
            renderCalls(); renderRoles();
        });
        field('formation').addEventListener('change', () => {selections={};renderCalls();renderRoles();});
        search.addEventListener('input', () => positions.querySelectorAll('select').forEach(item => playerOptions(item,item.name.slice(10,-1))));
        search.addEventListener('keydown', event => {if(event.key==='Enter')event.preventDefault();});
        renderRoles();
    });
}
