import { scoreboardText } from './scoreboard.js';
export function reviewCountdown() {
    let remaining = null;
    return {
        start: () => { remaining = 5; },
        cancel: () => { remaining = null; },
        tick(delta, active) {
            if (remaining === null || !active) return null;
            remaining = Math.max(0, remaining - delta);
            const seconds = Math.ceil(remaining);
            if (remaining === 0) remaining = null;
            return seconds;
        },
    };
}
const special = value => ['punt', 'field_goal', 'kickoff', 'extra_point'].includes(value);
const escape = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[char]));

export function formationPoints(side, formation) {
    const offense = { QB:[-5,26.7], C:[-1,26.7], LG:[-1,24.5], RG:[-1,28.9], LT:[-1,22.3], RT:[-1,31.1], RB:[-7,29], TE:[-1,34], WR1:[-1,9], WR2:[-1,43], WR3:[-3,16] };
    const defense = { DE1:[1,22], DT1:[1,25], DT2:[1,28], DE2:[1,31], LB1:[5,22], LB2:[5,27], LB3:[5,33], CB1:[3,9], CB2:[3,43], S1:[11,20], S2:[12,34] };
    if (formation === 'singleback') { offense.QB[0]=-2; offense.RB=[-7,26.7]; }
    if (formation === 'spread') { offense.WR1[1]=4; offense.WR2[1]=49; offense.TE[1]=39; }
    if (formation === 'i_form') { offense.QB[0]=-2; offense.RB=[-7,26.7]; offense.WR3=[-4,26.7]; }
    if (formation === 'pistol') { offense.QB[0]=-4; offense.RB=[-8,26.7]; }
    if (formation === 'trips') { offense.WR1[1]=40; offense.WR2[1]=47; offense.WR3=[-2,35]; }
    if (formation === 'two_high') { defense.S1=[14,16]; defense.S2=[14,38]; }
    if (formation === 'single_high') { defense.S1=[14,26.7]; defense.S2=[4,34]; }
    if (formation === 'base_3_5') { defense.DE1=[1,21]; defense.DT1=[1,27]; defense.DE2=[1,33]; delete defense.DT2; delete defense.S2; defense.LB4=[4,16]; defense.LB5=[4,38]; }
    if (formation === 'nickel') { delete defense.LB3; defense.CB3=[5,16]; }
    if (formation === 'base_3_4') { delete defense.DT2; defense.LB4=[4,33]; defense.LB3=[4,17]; }
    if (formation === 'dime') { delete defense.LB2; delete defense.LB3; defense.CB4=[7,39]; defense.CB3=[7,16]; }
    if (formation === 'punt') { delete offense.QB; offense.P=[-12,26.7]; offense.RB=[-5,26.7]; }
    if (formation === 'field_goal') {
        delete offense.QB; delete offense.RB;
        offense.H=[-7,26.7]; offense.K=[-10,30];
        offense.WR1=[-1,19]; offense.WR2=[-1,36]; offense.WR3=[-2,21];
    }
    return side === 'offense' ? offense : defense;
}

export function playGraphic(kind, value, formation = 'shotgun') {
    const defensive = kind.startsWith('defense');
    const points = formationPoints(defensive ? 'defense' : 'offense', formation);
    const xy = ([depth, width]) => [20 + width * 5, 140 - depth * 5];
    const marks = Object.entries(points).map(([role, point]) => {
        const [x,y] = xy(point);
        return '<circle cx="'+x+'" cy="'+y+'" r="6" fill="'+(defensive?'#fb7185':'#93c5fd')+'"/><text x="'+x+'" y="'+(y+16)+'" text-anchor="middle" fill="#e2e8f0" font-size="9">'+role+'</text>';
    }).join('');
    const route = (role, depth, width, color='#facc15') => {
        const [x,y]=xy(points[role] || points.QB || points.LB2);
        const [endX,endY]=xy([depth,width]);
        const tailY=endY+(endY<y?5:-5);
        return '<path d="M '+x+' '+y+' L '+x+' '+((y+endY)/2)+' L '+endX+' '+endY+'" fill="none" stroke="'+color+'" stroke-width="3"/><path d="M '+(endX-4)+' '+tailY+' L '+endX+' '+endY+' L '+(endX+4)+' '+tailY+'" fill="none" stroke="'+color+'" stroke-width="2"/>';
    };
    let art = '';
    if (kind === 'motion' && value !== 'none') {
        const [x,y]=xy(points[value] || points.WR1);
        art='<path d="M '+x+' '+y+' Q 150 '+(y+30)+' '+(x>150?60:245)+' '+y+'" fill="none" stroke="#facc15" stroke-width="3" stroke-dasharray="6 4"/>';
    } else if (kind === 'play') {
        if (['inside_run','draw','two_point_run','kneel','spike'].includes(value)) art=route(value==='kneel'||value==='spike'?'QB':'RB',value==='kneel'?-6:7,26.7);
        else if (value==='outside_run') art=route('RB',6,44);
        else if (value==='screen') art=route('RB',1,43);
        else if (value==='slant') art=route('WR1',8,25);
        else if (['short_pass','medium_pass','deep_pass','two_point_pass'].includes(value)) art=route('WR1',value==='deep_pass'?23:value==='medium_pass'?14:6,9);
        else art='<path d="M 150 180 Q 120 25 150 20" fill="none" stroke="#facc15" stroke-width="3"/><text x="150" y="60" fill="#facc15" text-anchor="middle">KICK</text>';
    } else if (kind === 'defense-coverage') {
        if (value==='zone') art=[9,27,43].map(width=>{const [x,y]=xy([9,width]);return '<ellipse cx="'+x+'" cy="'+y+'" rx="38" ry="35" fill="#38bdf8" fill-opacity=".18" stroke="#38bdf8" stroke-dasharray="4 3"/>';}).join('');
        else if (value==='man_to_man') art=route('CB1',-1,9,'#38bdf8')+route('CB2',-1,43,'#38bdf8');
        else art=route('LB2',-2,26.7);
    } else if (kind === 'defense-type') {
        const [expect,blitz]=value.split(':');
        if(expect==='run') art=route('LB1',0,24,'#38bdf8')+route('LB2',0,29,'#38bdf8');
        if(expect==='pass') art=route('LB1',12,16,'#38bdf8')+route('LB3',12,38,'#38bdf8');
        if(expect==='balanced') art='<text x="150" y="35" text-anchor="middle" fill="#38bdf8" font-size="14">RUN + PASS</text>';
        if(blitz==='blitz') art+=route('LB2',-5,26.7,'#facc15');
    }
    return '<svg viewBox="0 0 310 215" aria-hidden="true"><rect width="310" height="215" rx="8" fill="#153c2c"/><path d="M 10 140 H 300" stroke="#fff" stroke-dasharray="5 4" opacity=".5"/>'+art+marks+'</svg>';
}

export function wizardSteps(humanOffense, humanDefense, call, calls = []) {
    const list=[];
    if(humanOffense) {
        const onlySpecial=calls.length>0 && calls.every(value=>['kickoff','extra_point'].includes(value)||value.startsWith('two_point'));
        if(!onlySpecial) list.push({title:'Offense · Formation',kind:'formation',name:'offense_formation'});
        list.push({title:'Offense · Play',kind:'play',name:'call'});
        if(!special(call)&&!['kneel','spike'].includes(call)) list.push({title:'Offense · Motion',kind:'motion',name:'motion'});
    }
    if(humanDefense) {
        if(!special(call)) list.push({title:'Defense · Formation',kind:'defense-formation',name:'defense_formation'});
        list.push({title:special(call)?'Defense · Special teams':'Defense · Coverage',kind:'defense-coverage',name:'defense'});
        if(!special(call)) list.push({title:'Defense · Type',kind:'defense-type'});
    }
    list.push({title:'Review & call play',kind:'review'});
    return list;
}

export function mountPlayWizard(root) {
    const form = root.querySelector('[data-call-form]');
    if (!form || (!form.elements.call && !form.elements.defense)) return;
    const dialog = document.createElement('dialog');
    dialog.className='game-dialog play-wizard'; dialog.dataset.playWizard='';
    const launch = document.createElement('div');
    launch.className='game-play-panel'; launch.dataset.hiddenResult='';
    launch.hidden=form.hidden;
    const open=document.createElement('button'); open.type='button'; open.textContent='Call play';
    open.className='bg-blue-700 rounded px-6 py-2';
    launch.append(open); form.before(launch);
    form.className='play-wizard-form'; dialog.append(form); root.append(dialog);
    const source = document.createElement('div');
    source.className='play-wizard-source';
    [...form.children].filter(el=>el.matches('label,[data-coach-offense],[data-coach-defense]')).forEach(el=>source.append(el));
    form.prepend(source);
    const ui=document.createElement('section'); ui.className='play-wizard-ui'; source.after(ui);
    const field=name=>form.elements.namedItem(name);
    let step=0;
    const state = JSON.parse(root.dataset.afterState || '{}');
    const names = JSON.parse(root.dataset.teamNames || '{}');
    const appearance = JSON.parse(root.dataset.appearance || '{}');
    const lastResult = root.querySelector('.game-last-result')?.textContent.trim() || '';
    let opened = false, idleSeconds = 0;
    const countdown = reviewCountdown();
    dialog.addEventListener('close', () => countdown.cancel());
    dialog.addEventListener('cancel', () => countdown.cancel());
    // Kick formations are offered only when those calls are legal for this phase.
    if (field('offense_formation') && field('call')) {
        [...field('offense_formation').options].filter(option => ['punt','field_goal'].includes(option.value) && (state.phase || 'scrimmage') !== 'scrimmage').forEach(option=>option.remove());
    }
    const steps=()=>wizardSteps(Boolean(field('call')), Boolean(field('defense')), field('call')?.value || (root.dataset.cpuSpecial==='true'?'kickoff':'inside_run'), field('call') ? [...field('call').options].map(option=>option.value) : []);
    const summary=()=>['offense_formation','call','motion','defense_formation','defense'].filter(name=>field(name)).map(name=>'<li>'+escape(field(name).selectedOptions[0]?.textContent || '')+'</li>').join('')+(field('expect')?'<li>'+escape(field('expect').value)+(field('blitz').checked?' + blitz':' · regular')+'</li>':'');
    function render() {
        countdown.cancel();
        ['tempo','clock_strategy'].forEach(name=>{if(field(name))source.append(field(name).parentElement);});
        const list=steps(); step=Math.min(step,list.length-1); const current=list[step];
        const labels = scoreboardText(state, names);
        ui.innerHTML='<p class="wizard-situation">'+escape((names[state.possession] || '')+' · '+labels.compact+' · '+labels.clock)+'</p><div class="wizard-heading"><div><p>Step '+(step+1)+' of '+list.length+'</p><h2>'+current.title+'</h2></div><button type="button" data-wizard-close>Close</button></div><p class="wizard-progress">'+list.map((item,index)=>'<span class="'+(index===step?'current':'')+'">'+escape(item.title)+'</span>').join(' → ')+'</p><div class="wizard-cards"></div><div class="wizard-nav"><button type="button" data-wizard-back '+(step===0?'disabled':'')+'>Back</button><button type="button" data-wizard-coach>Coach pick</button><button type="button" data-wizard-next>'+(current.kind==='review'?'Call play & watch':'Next')+'</button></div>';
        const situation = ui.querySelector('.wizard-situation');
        const logo = appearance[state.possession]?.team_logo;
        if (logo) {
            const image = document.createElement('img');
            image.src = logo;
            image.alt = (names[state.possession] || 'Team')+' logo';
            image.className = 'wizard-team-logo';
            situation.prepend(image);
        }
        if (lastResult) {
            const result = document.createElement('p');
            result.className = 'wizard-last-result';
            result.textContent = lastResult;
            situation.after(result);
        }
        if (field('defense') && (current.kind.startsWith('defense') || current.kind === 'review')) {
            const opponentFormation = field('offense_formation')?.value || JSON.parse(root.dataset.coachOffense || '{}').formation || 'shotgun';
            const labels = { singleback:'Singleback', shotgun:'Shotgun', spread:'Spread', i_form:'I formation', pistol:'Pistol', trips:'Trips' };
            const state = JSON.parse(root.dataset.afterState || '{}');
            const names = JSON.parse(root.dataset.teamNames || '{}');
            const scouting = document.createElement('aside');
            scouting.className='wizard-opponent';
            scouting.innerHTML='<div><p>Offense on the field</p><strong>'+escape(names[state.possession] || 'Offense')+' · '+escape(labels[opponentFormation] || opponentFormation)+'</strong></div>'+playGraphic('formation',opponentFormation,opponentFormation);
            ui.querySelector('.wizard-progress').after(scouting);
        }
        const cards=ui.querySelector('.wizard-cards');
        form.querySelector('[data-snap]').hidden=true;
        if(current.kind==='review') {
            cards.innerHTML='<div class="wizard-review"><h3>Selected calls</h3><ul>'+summary()+'</ul></div>';
            ['tempo','clock_strategy'].forEach(name=>{
                if(field(name)) { const label=field(name).parentElement; label.hidden=false; cards.append(label); }
            });
        } else {
            ['tempo','clock_strategy'].forEach(name=>{if(field(name))source.append(field(name).parentElement);});
            const options=current.kind==='defense-type'
                ? ['balanced','run','pass'].flatMap(expect=>[ {value:expect+':regular',text:expect[0].toUpperCase()+expect.slice(1)+' · Regular'}, {value:expect+':blitz',text:expect[0].toUpperCase()+expect.slice(1)+' + Blitz'} ])
                : [...field(current.name).options].map(option=>({value:option.value,text:option.textContent}));
            const selected=current.kind==='defense-type'?field('expect').value+':'+(field('blitz').checked?'blitz':'regular'):field(current.name).value;
            options.forEach(option=>{
                const button=document.createElement('button');button.type='button';button.className='wizard-card';button.setAttribute('aria-pressed',String(selected===option.value));
                const formation=current.kind.includes('formation')?option.value:field(current.kind.startsWith('defense')?'defense_formation':'offense_formation')?.value;
                button.innerHTML=playGraphic(current.kind==='formation' && ['punt','field_goal'].includes(option.value)?'play':current.kind,option.value,formation)+'<span>'+escape(option.text)+'</span>';
                button.addEventListener('click',()=>{
                    if(current.kind==='defense-type') {field('expect').value=option.value.split(':')[0];field('blitz').checked=option.value.endsWith(':blitz');}
                    else {field(current.name).value=option.value;field(current.name).dispatchEvent(new Event('change',{bubbles:true}));}
                    step++;
                    render();
                    const heading = ui.querySelector('h2');
                    heading.tabIndex = -1;
                    heading.focus({preventScroll:true});
                    ui.scrollIntoView({block:'start'});
                });cards.append(button);
            });
        }
        ui.querySelector('[data-wizard-close]').onclick=()=>dialog.close();
        ui.querySelector('[data-wizard-back]').onclick=()=>{step--;render();};
        ui.querySelector('[data-wizard-next]').onclick=()=>{
            if(current.kind==='review') {if (!form.querySelector('[data-snap]').disabled) { form.requestSubmit();dialog.close(); }}
            else {step++;render();ui.scrollIntoView({block:'start'});}
        };
        ui.querySelector('[data-wizard-coach]').onclick=()=>{
            form.querySelector('[data-coach-offense]')?.click();
            form.querySelector('[data-coach-defense]')?.click();
            step=steps().length-1;
            render();
        };
        if (current.kind === 'review') {
            countdown.start();
            ui.querySelector('[data-wizard-next]').textContent = 'Call play & watch · 5s';
        }
    }
    const openWizard = () => {
        opened = true;
        // Close the compact Call play sheet before opening the centered wizard.
        const parent=launch.closest('dialog'); if(parent?.open)parent.close();
        step=0;render();dialog.showModal();
    };
    open.addEventListener('click', openWizard);
    return ({delta, ready}) => {
        const seconds = countdown.tick(delta, dialog.open && !document.hidden && root.isConnected);
        if (seconds !== null) {
            const button = ui.querySelector('[data-wizard-next]');
            button.textContent = 'Call play & watch · '+seconds+'s';
            if (seconds === 0) button.click();
        }
        if (!canAutoOpenWizard({ready, opened, visible:!document.hidden, dialogOpen:Boolean(root.querySelector('dialog[open]'))})) {
            idleSeconds = 0; return;
        }
        idleSeconds += delta;
        if (idleSeconds >= 1) openWizard();
    };
}

export function canAutoOpenWizard({ready, opened, visible, dialogOpen}) {
    return ready && !opened && visible && !dialogOpen;
}
