export function mountSeasonSetup(form) {
    if (!form || form.dataset.mounted) return;
    form.dataset.mounted = 'true';
    const definitions = JSON.parse(form.dataset.definitions);
    const field = name => form.elements.namedItem(name);
    const rows = [...form.querySelectorAll('[data-season-team]')];
    const refresh = (resetGroups = false, resetNames = resetGroups) => {
        const count = Number(field('team_count').value), layout = field('layout').value;
        const groups = definitions[count][layout];
        const lengths = count === 4 ? [3,6] : [7,10,11,14,16,17];
        const length = Number(field('games').value);
        field('games').replaceChildren(...lengths.map(n => new Option(String(n), String(n))));
        field('games').value = String(lengths.includes(length) ? length : lengths.at(-1));
        const selected = rows.filter(row => row.querySelector('[name="teams[]"]').checked);
        const assignments = Object.entries(groups).flatMap(([key, group]) => Array(group.size).fill(key));
        let i = 0;
        rows.forEach(row => {
            const included = row.querySelector('[name="teams[]"]').checked;
            const control = row.querySelector('[name="human[]"]');
            control.disabled = !included;
            const group = row.querySelector('select'), previous = group.value;
            group.replaceChildren(...Object.entries(groups).map(([key,value]) => new Option(`${value.conference} · ${value.division} (${value.size})`, key)));
            group.value = !resetGroups && groups[previous] ? previous : (assignments[i] || Object.keys(groups)[0]);
            group.disabled = !included;
            if (included) i++;
        });
        form.querySelector('[data-team-count]').textContent = `${selected.length} of ${count} teams selected. Unchecked Human means CPU. Control can change later.`;
        const conferences = layout === 'flat' ? 1 : 2;
        const divisions = Object.keys(groups).length / conferences;
        [...field('playoffs').options].forEach(option => {
            const value = option.value;
            option.disabled = /^\d+$/.test(value) ? Number(value) > count :
                value === 'nfl10' ? layout !== 'divisions' || divisions !== 3 || count < 10 :
                value === 'nfl12' ? layout === 'flat' || count < 12 || divisions > 6 :
                value === 'nfl14' ? layout !== 'divisions' || divisions !== 4 || count < 14 : false;
        });
        if (field('playoffs').selectedOptions[0]?.disabled) field('playoffs').value = '4';
        if (resetNames) {
            const container = form.querySelector('[data-group-names]');
            container.replaceChildren();
            const seen = new Set();
            const add = (labelText, name, value) => {
                const label = document.createElement('label'); label.textContent = labelText;
                const input = document.createElement('input'); input.name = name; input.value = value; input.required = true; input.maxLength = 40;
                label.append(input); container.append(label);
            };
            Object.entries(groups).forEach(([key, group]) => {
                const conference = layout === 'flat' ? 'league' : key[0];
                if (!seen.has(conference)) { add(group.conference, `conference_names[${conference}]`, group.conference); seen.add(conference); }
                add(`${group.conference} · ${group.division} (${group.size} teams)`, `division_names[${key}]`, group.division);
            });
        }
    };
    field('team_count').addEventListener('change', () => refresh(true));
    field('layout').addEventListener('change', () => refresh(true));
    rows.forEach(row => row.querySelector('[name="teams[]"]').addEventListener('change', () => refresh(true, false)));
    refresh();
}
