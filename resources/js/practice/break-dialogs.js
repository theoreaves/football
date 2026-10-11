import {latestBreakSummary,periodSummary,clockText} from './break-summaries.js';
const stats=[['plays','Plays'],['yards','Yards'],['first_downs','First downs'],['points','Points'],['turnovers','Turnovers'],['penalties','Penalties'],['penalty_yards','Penalty yards'],['possession','Possession time']];
export function mountBreakDialogs(root,audio) {
    const driveDialog=root.querySelector('[data-drive-dialog]'),quarterDialog=root.querySelector('[data-quarter-dialog]');
    let history=[];try{history=JSON.parse(root.querySelector('[data-break-history]')?.textContent || '[]');}catch{/* Older pages have no recap payload. */}
    const drive=latestBreakSummary(history);
    if(driveDialog && drive){
        driveDialog.querySelectorAll('[data-drive-side]').forEach(el=>{el.hidden=el.dataset.driveSide!==drive.side;});
        driveDialog.querySelector('[data-drive-result]').textContent=drive.result;
        const dl=driveDialog.querySelector('[data-drive-stats]');
        for(const [label,value] of [['Plays',drive.plays],['Yards',drive.yards],['Possession time',clockText(drive.seconds)]]){
            const group=document.createElement('div'),dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=label;dd.textContent=value;dd.className='text-xl font-semibold';group.append(dt,dd);dl.append(group);
        }
    }
    const kind=quarterDialog?.dataset.breakKind;
    const heading=quarterDialog?.querySelector('[data-period-heading]');
    if(heading){
        const half=kind==='halftime',quarter=Number(quarterDialog.dataset.breakQuarter);
        heading.textContent=half ? 'First-half team stats':`Quarter ${quarter} team stats`;
        const summary=periodSummary(history,quarter,half),body=quarterDialog.querySelector('tbody');
        for(const [key,label] of stats){const row=document.createElement('tr'),th=document.createElement('th');th.textContent=label;th.className='text-left py-1';row.append(th);for(const side of ['away','home']){const td=document.createElement('td');td.className='text-center';td.textContent=key==='possession'?clockText(summary[side][key]):summary[side][key];row.append(td);}body.append(row);}
    }
    for(const dialog of [driveDialog,quarterDialog].filter(Boolean)){
        let closing=false;
        const close=async()=>{
            if(closing)return;closing=true;
            const button=dialog.querySelector('form[method="dialog"] button');if(button)button.disabled=true;
            await audio.finishBreakMusic();dialog.close();closing=false;if(button)button.disabled=false;
        };
        dialog.querySelector('form[method="dialog"]')?.addEventListener('submit',event=>{event.preventDefault();close();});
        dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
        dialog.addEventListener('close',()=>audio.finishBreakMusic(0));
        dialog.querySelector('[data-break-play]')?.addEventListener('click',()=>audio.unlockBreakMusic());
    }
    return {driveDialog:drive ? driveDialog:null,startDrive(){audio.startBreakMusic('drive');},startQuarter(){if(kind)audio.startBreakMusic(kind);}};
}
