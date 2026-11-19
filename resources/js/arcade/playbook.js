import {FULL_TEAM_PLAYBOOK, fullTeamDiagram} from './full-team-plays.js';

// Diagrams consume the runtime's assignments, not a second set of play definitions.
export function arcadePlayDiagram(call) {
    const diagram=fullTeamDiagram(call), point=([yards,z])=>`${(12+z*4).toFixed(1)},${(147-yards*4).toFixed(1)}`;
    const lines=[];
    for(const [role,path] of Object.entries(diagram.routes || {})) {
        if(path.length && diagram.formation[role])lines.push({kind:'route',points:[diagram.formation[role],...path].map(point).join(' ')});
    }
    for(const [role,path] of Object.entries(diagram.blocking || {})) {
        if(path.length && diagram.formation[role])lines.push({kind:'block',points:[diagram.formation[role],...path].map(point).join(' ')});
    }
    if(diagram.lane?.length)lines.push({kind:'mesh',points:diagram.lane.map(point).join(' ')});
    if(diagram.mesh?.length)lines.push({kind:'mesh',points:diagram.mesh.map(point).join(' ')});
    return {lines,players:Object.entries(diagram.formation).map(([role,location])=>({role,point:point(location)}))};
}

export function mountArcadePlaybook(root,{value,trigger,canOpen=()=>true,onChoose=()=>{},deps={}}) {
    const document=deps.document || root.ownerDocument, abort=new AbortController(),options={signal:abort.signal};
    let disposed=false,dialog=null,dialogAbort=null,returnFocus=null;
    const make=(tag,text,attrs={})=>{const e=document.createElement(tag);if(text)e.textContent=text;for(const [k,v]of Object.entries(attrs))e.setAttribute(k,v);return e;};
    const svg=(tag,attrs)=>{const e=document.createElementNS('http://www.w3.org/2000/svg',tag);for(const[k,v]of Object.entries(attrs))e.setAttribute(k,v);return e;};
    function close({restore=true}={}){if(!dialog)return;const old=dialog;dialog=null;dialogAbort?.abort();dialogAbort=null;old.close();old.remove();if(restore && returnFocus?.isConnected && !returnFocus.disabled)returnFocus.focus();}
    function refresh(){const selected=FULL_TEAM_PLAYBOOK.find(p=>p.id===value.value);trigger.textContent=`Playbook · ${selected?.label || 'Choose a play'}`;trigger.disabled=disposed || !canOpen();if(trigger.disabled)close({restore:false});}
    function open(){
        if(disposed || dialog || !canOpen())return;
        returnFocus=document.activeElement || trigger;
        const options={signal:(dialogAbort=new AbortController()).signal};
        dialog=make('dialog',null,{class:'arcade-playbook','aria-label':'Arcade offensive playbook'});
        const header=make('header'),title=make('h2','Choose your offensive play'),exit=make('button','Close',{type:'button','aria-label':'Close playbook'});
        header.append(title,exit);dialog.append(header,make('p','Choose a call, then Call Play and Start play. Diagrams show intended assignments, not guaranteed movement.'));
        const choices=[];
        for(const group of ['Run','Short Pass','Medium Pass','Deep Pass']){
            const section=make('section'),grid=make('div',null,{class:'arcade-playbook-grid'});section.append(make('h3',group),grid);
            for(const play of FULL_TEAM_PLAYBOOK.filter(p=>p.group===group)){
                const button=make('button',null,{type:'button','data-playbook-call':play.id,'aria-pressed':String(value.value===play.id)});
                const drawing=svg('svg',{viewBox:'0 0 240 180','aria-hidden':'true',focusable:'false'}),d=arcadePlayDiagram(play.id);
                drawing.append(svg('path',{d:'M 0 147 H 240',class:'playbook-los'}));
                for(const line of d.lines)drawing.append(svg('polyline',{points:line.points,class:`playbook-${line.kind}`,fill:'none'}));
                for(const player of d.players){const[x,y]=player.point.split(',');drawing.append(svg('circle',{cx:x,cy:y,r:player.role==='QB'?4:3,class:player.role==='QB'?'playbook-qb':'playbook-player'}));}
                button.append(drawing,make('strong',play.label),make('span',play.description));
                button.addEventListener('click',()=>{if(!canOpen()){close({restore:false});return;}value.value=play.id;onChoose(play);close();refresh();},options);
                choices.push(button);grid.append(button);
            }
            dialog.append(section);
        }
        exit.addEventListener('click',()=>close(),options);
        dialog.addEventListener('cancel',e=>{e.preventDefault();close();},options);
        dialog.addEventListener('keydown',e=>{e.stopPropagation();if(e.key==='Escape'){e.preventDefault();close();return;}if(e.key==='Tab'){const all=[exit,...choices],first=all[0],last=all.at(-1);if(e.shiftKey && document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey && document.activeElement===last){e.preventDefault();first.focus();}}},options);
        dialog.addEventListener('close',()=>close(),options);
        root.append(dialog);dialog.showModal();(choices.find((_,i)=>FULL_TEAM_PLAYBOOK[i]?.id===value.value) || exit).focus();
    }
    trigger.addEventListener('click',open,options);refresh();
    return {open,close,refresh,get active(){return !!dialog;},dispose(){if(disposed)return;disposed=true;close({restore:false});abort.abort();}};
}
