import {periodSummary,clockText,completedDrives} from './break-summaries.js';
export function mountGameStats(root){
    const dialog=root.querySelector('[data-stats-dialog]');if(!dialog)return null;
    let history=[];try{history=JSON.parse(root.querySelector('[data-break-history]')?.textContent || '[]');}catch{}
    const choose=key=>{dialog.querySelectorAll('[data-stats-tab]').forEach(button=>{const active=button.dataset.statsTab===key;button.setAttribute('aria-selected',String(active));button.classList.toggle('bg-blue-700',active);button.tabIndex=active?0:-1;});dialog.querySelectorAll('[data-stats-panel]').forEach(panel=>panel.hidden=panel.dataset.statsPanel!==key);};
    const tabs=[...dialog.querySelectorAll('[data-stats-tab]')];tabs.forEach((button,index)=>{button.onclick=()=>choose(button.dataset.statsTab);button.onkeydown=event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();const i=event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;choose(tabs[i].dataset.statsTab);tabs[i].focus();};});
    dialog.querySelectorAll('[data-lineup-tab]').forEach(button=>button.onclick=()=>{dialog.querySelectorAll('[data-lineup-tab]').forEach(tab=>{const active=tab===button;tab.setAttribute('aria-selected',String(active));tab.classList.toggle('bg-blue-700',active);});dialog.querySelectorAll('[data-lineup-group]').forEach(panel=>panel.style.display=panel.dataset.lineupGroup===button.dataset.lineupTab?'grid':'none');});
    const open=(tab='team')=>{
        const hiddenLast=dialog.querySelector('[data-stats-panel=log] [data-hidden-result]')?.hidden;
        const summary=periodSummary(hiddenLast?history.slice(0,-1):history,null),body=dialog.querySelector('[data-game-team-stats]');body.replaceChildren();
        for(const [key,label] of [['plays','Plays'],['yards','Yards'],['first_downs','First downs'],['points','Points'],['turnovers','Turnovers'],['penalties','Penalties'],['penalty_yards','Penalty yards'],['possession','Possession time']]){const row=document.createElement('tr'),th=document.createElement('th');th.textContent=label;th.className='text-left py-1';row.append(th);for(const side of ['away','home']){const td=document.createElement('td');td.className='text-center';td.textContent=key==='possession'?clockText(summary[side][key]):summary[side][key];row.append(td);}body.append(row);}
        const drivesBody=dialog.querySelector('[data-game-drives]');
        if(drivesBody){
            drivesBody.replaceChildren();const names=JSON.parse(drivesBody.dataset.teamNames || '{}');
            const drives=completedDrives(hiddenLast?history.slice(0,-1):history,true);
            drives.forEach((drive,index)=>{const row=document.createElement('tr');for(const value of [index+1,names[drive.side] || drive.side,`${drive.startQuarter>=5?'OT'+(drive.startQuarter-4):'Q'+drive.startQuarter} ${clockText(drive.startClock)}`,drive.plays,drive.yards,drive.result]){const cell=document.createElement('td');cell.textContent=value;cell.className='p-2 border-t border-gray-700';row.append(cell);}drivesBody.append(row);});
            if(!drives.length){const row=document.createElement('tr'),cell=document.createElement('td');cell.colSpan=6;cell.textContent='No offensive drives yet.';cell.className='py-4';row.append(cell);drivesBody.append(row);}
        }
        choose(tab);if(!dialog.open)dialog.showModal();
    };
    root.querySelector('[data-open-stats]')?.addEventListener('click',()=>open());choose('team');return {dialog,open};
}
