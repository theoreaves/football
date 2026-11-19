// Presentation only: existing controls/handlers and authoritative snapshots remain owners.
export function compactScore(state, teams={}) {
    const clock=Math.max(0,Math.floor(Number(state.clock)||0));
    const period=state.status==='final'?'FINAL':state.quarter>4?'OT':`Q${state.quarter}`;
    const down=['','1st','2nd','3rd','4th'][state.down] || `Down ${state.down}`;
    const situation=state.phase==='kickoff'?'Kickoff':state.phase==='extra_point'?'Try':`${down} & ${state.distance>=100-state.spot?'Goal':state.distance}`;
    return {score:`${teams.away || 'Away'} ${state.away_score ?? 0} · ${teams.home || 'Home'} ${state.home_score ?? 0}`,
        state:`${period} · ${Math.floor(clock/60)}:${String(clock%60).padStart(2,'0')} · ${situation} · ${state.spot<=50?'Own '+state.spot:'Opp '+(100-state.spot)}`};
}

// Keep routine notifications brief; never expire a recovery warning or decision.
export function createHudNotice({show,hide,setTimer=setTimeout,clearTimer=clearTimeout}) {
    let last='',timer=null,generation=0;
    return {set(text,{persistent=false}={}){const key=`${persistent}:${text}`;if(key===last)return;last=key;const own=++generation;clearTimer(timer);timer=null;
        if(!text){hide();return;}show(text);if(!persistent)timer=setTimer(()=>{if(own===generation)hide();},5500);
    },dispose(){generation++;clearTimer(timer);}};
}

export function mountCompactHud(root,{metrics=()=>null,onSpecial=()=>{},document=root.ownerDocument}={}) {
    const find=name=>root.querySelector(`[data-exhibition-${name}]`), abort=new AbortController(),options={signal:abort.signal};
    const make=(tag,cls,text)=>{const e=document.createElement(tag);e.className=cls;if(text)e.textContent=text;return e;};
    const moved=[],hiddenBefore=[];let restoring=false;
    const move=(target,node)=>{if(node){moved.push({node,parent:node.parentNode,next:node.nextSibling});target.append(node);}};
    const menu=(title,cls='')=>{const e=make('details',`compact-menu ${cls}`);e.setAttribute('data-compact-hud-interactive','');e.append(make('summary','',title));const panel=make('div','compact-menu-panel');e.append(panel);return {e,panel};};
    const bar=make('div','compact-hud-bar');bar.setAttribute('data-compact-hud-interactive','');bar.setAttribute('aria-label','Game controls');
    const offense=make('div','compact-offense-controls'), recovery=make('div','compact-recovery-controls'), more=menu('More');
    const dev=make('section','compact-developer');dev.hidden=true;dev.setAttribute('aria-label','Developer HUD');
    const diagnostic=make('pre','compact-trace');dev.append(diagnostic);
    const score=root.querySelector('.arcade-scoreboard');score.classList.add('compact-scoreboard');
    move(dev,find('live'));
    for(const key of ['flow-status','clock','notices','status'])move(dev,find(key));
    for(const key of ['playbook','reserve','start','pause','cancel','submit','replay'])move(offense,find(key));
    move(offense,find('tempo')?.closest('label'));
    const kicks={};for(const [call,label]of [['punt','Punt'],['field_goal','Field Goal']]){const button=make('button','',label);button.type='button';button.hidden=true;button.addEventListener('click',()=>{if(!button.disabled)onSpecial(call);},options);offense.append(button);kicks[call]=button;}
    let specialCalls=[];
    for(const key of ['retry-flow'])move(recovery,find(key));
    const decision=make('span','compact-decision');decision.setAttribute('role','status');recovery.append(decision);
    for(const key of ['timeout','spike','coach'])move(more.panel,find(key));
    const reload=[...root.querySelectorAll('a')].find(e=>e.textContent.includes('Reload saved state'));move(more.panel,reload);
    const preferences=menu('Team / defense');for(const key of ['side','defense'])move(preferences.panel,find(key)?.closest('label'));move(preferences.panel,find('preferences'));more.panel.append(preferences.e);
    // Existing root flow buttons are retained for compatibility but Coach phase controls
    // own visible defensive actions and all required decisions in the shared stadium.
    for(const key of ['advance','fast'])move(dev,find(key));
    const help=menu('Controls');for(const e of [...root.querySelectorAll('.arcade-help')])move(help.panel,e);more.panel.append(help.e);
    const toggle=make('label','compact-developer-toggle',''),checkbox=make('input','');checkbox.type='checkbox';toggle.append(checkbox,document.createTextNode(' Developer HUD'));more.panel.append(toggle);
    checkbox.addEventListener('change',()=>{dev.hidden=!checkbox.checked;root.classList.toggle('compact-debug-on',checkbox.checked);},options);
    const toast=make('div','compact-toast');toast.hidden=true;toast.setAttribute('role','status');toast.setAttribute('aria-live','polite');toast.setAttribute('aria-atomic','true');
    const notice=createHudNotice({show:text=>{toast.textContent=text;toast.hidden=false;},hide:()=>{toast.hidden=true;}});
    const oldSetups=[...root.querySelectorAll('.arcade-setup')];oldSetups.forEach(e=>{hiddenBefore.push([e,e.hidden]);e.hidden=true;});
    const oldToolbar=root.querySelector('.arcade-toolbar');if(oldToolbar){hiddenBefore.push([oldToolbar,oldToolbar.hidden]);oldToolbar.hidden=true;}
    bar.append(offense,recovery,more.e);root.append(bar,toast,dev);root.classList.add('compact-game-hud');
    let teams={},lastStatus='',lastNotices='',sticky='',previousPhase=null,lastTrace=0;
    const interactive=e=>{e.stopPropagation();};
    // A toolbar event never becomes a field throw or gameplay keyboard command.
    for(const e of [bar,dev])for(const type of ['pointerdown','pointerup','keydown'])e.addEventListener(type,interactive,options);
    function update(v) {
        const {official,flow,state,busy,pending,recovered,replaying,flowPending,flowBlocked}=v;
        const labels=compactScore(official,teams);find('score').textContent=labels.score;find('state').textContent=`${replaying || v.coachHistory ? 'Current game · ' : ''}${labels.state}`;
        const coachMode=root.classList.contains('persistent-coach-active');root.classList.toggle('compact-replaying',replaying);
        const live=state?.status==='running',finished=state?.status==='finished',reserved=!!pending;
        offense.hidden=coachMode || replaying;
        const visible={playbook:!reserved,reserve:!reserved,start:reserved&&!recovered&&['ready','paused'].includes(state?.status),pause:live,cancel:reserved,submit:!!pending?.body,replay:!live&&!busy&&!!v.recording};
        for(const [key,value]of Object.entries(visible))find(key).hidden=!value;
        for(const [call,button]of Object.entries(kicks)){button.hidden=official.down!==4 || flow?.phase!=='offense' || !specialCalls.includes(call);button.disabled=find('reserve').disabled || reserved || flowPending || flowBlocked || busy || replaying;}
        const tempo=find('tempo')?.closest('label');if(tempo)tempo.hidden=reserved;
        find('submit').textContent=recovered?'Retry saved result':'Save result';
        find('cancel').textContent='Cancel play';find('replay').textContent='Replay';
        decision.textContent=flowPending?'Decision unconfirmed':flowBlocked?'Reload required':flow?.required_decision?'Decision required':'';
        decision.hidden=!decision.textContent;
        const text=find('status').textContent, messages=find('notices')?.textContent || '';
        const statusKey=`${find('status').dataset.noticePersistent}:${text}`;
        if(statusKey!==lastStatus){lastStatus=statusKey;
            sticky=find('status').dataset.noticePersistent==='true'?text:'';
            if(sticky)notice.set(sticky,{persistent:true});
            else notice.set(/choose a play|play live|reserved with/i.test(text)?'':text);
        }
        if(messages!==lastNotices){lastNotices=messages;if(messages&&!sticky)notice.set(messages);}
        if(previousPhase!==flow?.phase){previousPhase=flow?.phase;if(flow?.phase==='final'&&!sticky)notice.set('Final whistle. Replays remain available.');}
        if(finished&&!pending?.body&&!sticky)notice.set('No saved result. Cancel this play and reserve again.',{persistent:true});
        if(checkbox.checked && Date.now()-lastTrace>500){lastTrace=Date.now();diagnostic.textContent=JSON.stringify({version:official.version,phase:flow?.phase,flowRevision:flow?.revision,session:pending?.session?.envelope,status:state?.status,busy,recovered,pendingResult:!!pending?.body,pendingDecision:flowPending,phaseStatus:root.querySelector('.persistent-phase-controls')?.querySelector('[data-status]')?.textContent || '',metrics:metrics()},null,2);}
        if(live && more.e.open)more.e.open=false;
    }
    function attachPhase(phaseRoot) {
        // Phase roots are replaceable; do not retain previous plays in root restoration bookkeeping.
        const move=(target,node)=>{if(node)target.append(node);};
        const dock=make('div','compact-phase-dock');dock.setAttribute('data-compact-hud-interactive','');
        const call=phaseRoot.querySelector('[data-call-form]');
        if(call?.closest('dialog')){
            // Preserve Coach's modal form; compact only its launcher.
            move(dock,phaseRoot.querySelector('.game-play-panel'));
        }else if(call){const choices=menu('Call play','compact-call-menu');move(choices.panel,call);dock.append(choices.e);}
        const flow=phaseRoot.querySelector('[data-play-flow-panel]');
        if(flow){const defense=menu('Defense / simulation');move(defense.panel,flow);dock.append(defense.e);}
        const actions=phaseRoot.querySelector('.game-actions');if(actions){const tools=menu('Game');move(tools.panel,actions);dock.append(tools.e);}
        const timeouts=phaseRoot.querySelector('.game-timeouts');if(timeouts){const clocks=menu('Timeout');move(clocks.panel,timeouts);dock.append(clocks.e);}
        const camera=phaseRoot.querySelector('.game-camera-controls'),timeline=phaseRoot.querySelector('.game-timeline');
        if(camera || timeline){const replay=menu('Replay / camera');move(replay.panel,camera);move(replay.panel,timeline);dock.append(replay.e);}
        // Keep the original required dialogs and handlers mounted in their owner root.
        phaseRoot.append(dock);for(const type of ['pointerdown','pointerup','keydown'])dock.addEventListener(type,interactive,options);
    }
    return {update,attachPhase,setSpecialCalls:value=>{specialCalls=value || [];},setTeams:value=>{teams=value || {};},dispose(){if(restoring)return;restoring=true;notice.dispose();abort.abort();
        for(const {node,parent,next} of moved.reverse())if(parent)parent.insertBefore(node,next?.parentNode===parent?next:null);
        for(const [node,hidden]of hiddenBefore)node.hidden=hidden;
        root.classList.remove('compact-game-hud','compact-debug-on','compact-replaying');score.classList.remove('compact-scoreboard');
        bar.remove();dev.remove();toast.remove();}};
}
