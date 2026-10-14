import {isSpecialTeamCall} from './special-teams-options.js';
export function teamPlayOptions(playbooks, side, kind, formation) {
    return Object.values(playbooks[side] || {}).filter(play => play.kind === kind && play.formation === formation);
}

export function mountTeamPlaySelections(root, form) {
    const books = JSON.parse(root.dataset.teamPlays || '{}');
    const state = JSON.parse(root.dataset.afterState || '{}');
    const offenseSide = state.possession || 'home', defenseSide = offenseSide === 'home' ? 'away' : 'home';
    const field = name => form.elements.namedItem(name);
    const selected = {};
    const inputs = {};
    for (const side of ['home','away']) {
        const input = document.createElement('input'); input.type='hidden'; input.name=`team_play[${side}]`;
        form.append(input); inputs[side]=input;
    }
    const sideFor = kind => kind === 'offense' ? offenseSide : defenseSide;
    const clear = kind => {const side=sideFor(kind);delete selected[side];inputs[side].value='';};
    ['offense_formation','call'].forEach(name => field(name)?.addEventListener('change',()=>clear('offense')));
    ['defense_formation','defense','expect','blitz'].forEach(name => field(name)?.addEventListener('change',()=>clear('defense')));
    form.querySelector('[data-coach-offense]')?.addEventListener('click',()=>clear('offense'));
    form.querySelector('[data-coach-defense]')?.addEventListener('click',()=>clear('defense'));
    return {
        options(kind) {
            const call = field('call')?.value || (root.dataset.cpuSpecial === 'true' ? 'kickoff' : 'inside_run');
            if ((state.phase || 'scrimmage') !== 'scrimmage' || isSpecialTeamCall(call)) return [];
            return teamPlayOptions(books,sideFor(kind),kind,field(kind==='offense'?'offense_formation':'defense_formation')?.value);},
        choose(play) {
            const kind=play.kind,side=sideFor(kind),call=field(kind==='offense'?'call':'defense');
            call.value=play.call;call.dispatchEvent(new Event('change',{bubbles:true}));
            if(kind==='defense') {field('expect').value=play.expect || 'balanced';field('blitz').checked=Boolean(play.blitz);}
            selected[side]=play;inputs[side].value=play.id;
        },
        clear,
        current(kind) {return selected[sideFor(kind)];},
        selected() {return Object.values(selected);},
    };
}
