import {mountButtonFeedback} from '../button-feedback.js';
import {presentationHandoffNotice} from './presentation-handoff.js';
import {mountGameSettings} from '../practice/game-settings.js';
import {acceptedPlaybackUrl} from './presentation-playback.js';
import {saveExhibitionHighlight, highlightRequiresPresentation} from './highlight-save.js';
import {presentationMetrics} from './presentation-metrics.js';
import {captureSharedPresentation} from './replay-view.js';
import {mountExhibition} from './exhibition.js';
import {createExhibitionSceneHost} from '../practice/exhibition-scene-host.js';
import {mountPractice} from '../practice/field.js';
import {claimPlayOpening, serializePlayForm, watchMayAdvance} from '../practice/play-mode-presentation.js';
import {arcadeCameraFrame, exhibitionCameraX} from './view.js';
import './persistent-exhibition.css';

// Read-only reconciliation never reserves a snap or repeats an accepted outcome.
export function createPresentationReader({request,apply}) {
    let generation=0,disposed=false;
    return {async refresh(url,context={}) {const own=++generation;const data=await request(url);if(disposed || own!==generation)return false;await apply(data,{...context,isCurrent:()=>!disposed && own===generation});return !disposed && own===generation;},dispose(){disposed=true;generation++;}};
}

export async function mountPersistentExhibition(root) {
    if(!root || root.dataset.persistentMounted)return;
    root.dataset.persistentMounted='true';
    const document=root.ownerDocument,window=document.defaultView;
    const feedback=mountButtonFeedback(document,window);
    const data=JSON.parse(root.dataset.exhibition),url=root.dataset.persistentPresentation;
    const status=root.querySelector('[data-exhibition-status]');
    const canvasHost=root.querySelector('[data-arcade-field]');
    const controls=document.createElement('div');controls.className='persistent-coach-controls';root.append(controls);
    let sharedHost=null,arcade=null,coach=null,snapshot=null,disposed=false,loading=false,readBlocked=false,watchPaused=false,autoPending=false,opening=false;
    let presentation=null,lastRecorded=null,mode=null,coachHistory=false,forcedCoach=false,historyReturn=null,historyGeneration=0,liveSuspendedCoach=null,activityGeneration=0;
    const abort=new AbortController(),options={signal:abort.signal};
    let debug=null,lastDebug=0;if(new URL(window.location.href).searchParams.get('debug_presentation')==='1'){debug=document.createElement('pre');debug.className='persistent-debug';debug.setAttribute('data-presentation-metrics','');root.append(debug);}
    const say=text=>{status.textContent=text;};
    const resetFeedback=()=>feedback.reset();
    const sendFlow=(...args)=>Promise.resolve(arcade.flowAction(...args)).finally(resetFeedback);
    const handoffNotice=()=>presentationHandoffNotice({presentation,opening,staging:sharedHost.staging,transitioning:sharedHost.cameraDirector.transitioning,requiredDecision:snapshot.flow.required_decision});
    async function read(target){const parsed=new URL(target,window.location.origin);if(parsed.origin!==window.location.origin)throw new Error('Invalid game presentation URL.');const response=await fetch(parsed,{credentials:'same-origin',headers:{Accept:'application/json'},signal:abort.signal});if(!response.ok)throw new Error(response.status===401||response.status===419?'Sign-in expired. Reload to sign in; your pending result is retained.':`Presentation unavailable (${response.status}). Retry loading saved state.`);return response.json();}
    function arcadeCamera(){if(!sharedHost)return;const state=snapshot.state;const direction=(state.possession==='home'?1:-1)*(state.quarter%2===0?-1:1);const x=direction===1?10+state.spot:110-state.spot;const frame=arcadeCameraFrame(canvasHost.clientWidth,canvasHost.clientHeight,direction,exhibitionCameraX(x,direction));const camera=sharedHost.cameras.arcade;Object.assign(camera,{left:frame.left,right:frame.right,top:frame.top,bottom:frame.bottom});camera.position.set(...frame.position);camera.lookAt(...frame.target);camera.updateProjectionMatrix();sharedHost.cameraDirector.use(camera);}
    function setMode(){const offense=snapshot?.flow.phase==='offense' && !opening && !forcedCoach && !coachHistory && !presentation?.recorded;root.classList.toggle('persistent-arcade-active',offense);root.classList.toggle('persistent-coach-active',!offense);if(offense && !arcade?.hasLive && mode!=='arcade')arcadeCamera();if(!offense && mode!=='coach' && sharedHost)sharedHost.cameraDirector.use(sharedHost.cameras.broadcast);mode=offense?'arcade':'coach';}
    function onFrame(value){presentation=value;opening=Boolean(value.opening);if(!coachHistory && value.completed && value.ready && !value.dialogOpen) {presentation={...value,recorded:false};}setMode();
        if(watchMayAdvance({flow:snapshot?.flow,...value,visible:!document.hidden,busy:readBlocked||coachHistory||sharedHost?.staging||sharedHost?.cameraDirector.transitioning||loading||autoPending||arcade?.pending||arcade?.replaying,paused:watchPaused}) && !autoPending){autoPending=true;queueMicrotask(async()=>{try{if(!disposed)await sendFlow('advance');}finally{autoPending=false;}});}}
    async function apply(next,{kind='load',result=null,playback:requestedPlayback=null,isCurrent=()=>true}={}) {
        // Build the replacement controls off-document; keep the existing scene coherent while fetching.
        const template=document.createElement('template');template.innerHTML=next.controls_html;
        const phaseRoot=template.content.querySelector('[data-practice]');if(!phaseRoot)throw new Error('Game controls are missing. Reload saved state.');
        phaseRoot.classList.add('persistent-phase-controls');
        // Keep timeout forms in normal flow below the defense choices, not in
        // Coach's independent absolute tray which shares the panel's coordinates.
        const flowPanel=phaseRoot.querySelector('[data-play-flow-panel]'),timeoutTray=phaseRoot.querySelector('.game-timeouts');
        if(flowPanel && timeoutTray)flowPanel.append(timeoutTray);
        const acceptedUrl=acceptedPlaybackUrl(next,result);
        const shouldWatch=Boolean(requestedPlayback || acceptedUrl);
        if(shouldWatch){const playback=requestedPlayback || await read(acceptedUrl);if(disposed || !isCurrent())return;if(playback.animation){phaseRoot.dataset.animation=JSON.stringify(playback.animation);phaseRoot.dataset.beforeState=JSON.stringify(playback.before);phaseRoot.dataset.afterState=JSON.stringify(playback.after);phaseRoot.dataset.autoplay='true';lastRecorded=playback;}else phaseRoot.dataset.autoplay='false';}
        else {phaseRoot.dataset.autoplay='false';if(next.scene?.next_animation)phaseRoot.dataset.animation=JSON.stringify(next.scene.next_animation);phaseRoot.dataset.beforeState=JSON.stringify(next.state);}
        if(disposed || !isCurrent())return;
        let openingClaimed=false;
        if(!sharedHost){let storage;try{storage=window.localStorage;}catch{}openingClaimed=claimPlayOpening({flow:next.flow,version:next.state.version,key:`${phaseRoot.dataset.cameraKey}:pregame-shown:play`,storage});opening=openingClaimed;
            for(const [key,value] of Object.entries(phaseRoot.dataset))if(key.includes('Audio') || key==='cameraKey' || key==='teamAudio')root.dataset[key]=value;
            sharedHost=createExhibitionSceneHost(canvasHost,{appearance:next.appearance,crowd:next.crowd,sidelineRoster:next.scene?.sideline_roster,audioRoot:root});
            root.classList.add('persistent-play');
            root.dataset.exhibition=JSON.stringify({...data,state:next.state,available:next.available,flow:next.flow,pendingSession:next.pending_session});
            arcade=mountExhibition(root,{sharedHost,inputEnabled:()=>!readBlocked && !loading && !opening && !coachHistory && !presentation?.recorded,onCanonical:async(response,context)=>{await refresh({...context,result:response});}});
            sharedHost.activate({tick(now,delta){if(!disposed){if(arcade.hasLive && liveSuspendedCoach!==coach){coach?.suspend?.();liveSuspendedCoach=coach;}else if(!arcade.hasLive && liveSuspendedCoach===coach){coach?.resume?.();liveSuspendedCoach=null;if(mode==='arcade')arcadeCamera();}coach?.setInteractive?.(mode==='coach' && !readBlocked && !loading && !arcade.replaying && !arcade.hasLive && !sharedHost.staging && !sharedHost.cameraDirector.transitioning);if(!arcade.replaying && !arcade.hasLive)coach?.tick(now);else sharedHost.tickIdleAtmosphere?.(delta);arcade.tick(now);if(debug && now-lastDebug>1000){debug.textContent=JSON.stringify(presentationMetrics(sharedHost.metrics()),null,2);lastDebug=now;}}}});
        }
        coach?.dispose();controls.replaceChildren(phaseRoot);snapshot=next;presentation={recorded:shouldWatch};mode=null;forcedCoach=false;
        if(coachHistory){const back=document.createElement('button');back.type='button';back.textContent='Return to game';back.className='persistent-history-return';back.addEventListener('click',()=>{coachHistory=false;refresh();});controls.append(back);}
        arcade.reconcile({state:next.state,available:next.available,flow:next.flow,coach_url:next.flow.coach_url});
        mountGameSettings(phaseRoot);
        coach=mountPractice(phaseRoot,()=>{}, {sharedHost,openingClaimed,onFrame});liveSuspendedCoach=null;
        if(!coachHistory)sharedHost.stageNextFrame?.();
        setMode();
    }
    const reader=createPresentationReader({request:read,apply});
    async function refresh(context={}){if(coachHistory || arcade?.replaying){say('Return from replay before reloading saved state.');return false;}historyGeneration++;const activity=++activityGeneration;loading=true;arcade?.pause();try{const applied=await reader.refresh(url,context);if(applied)readBlocked=false;}catch(error){readBlocked=true;if(!disposed && error.name!=='AbortError')say(error.message);throw error;}finally{if(activity===activityGeneration)loading=false;resetFeedback();}}
    controls.addEventListener('submit',event=>{
        const form=event.target;if(event.defaultPrevented || form.method?.toLowerCase()!=='post')return;
        const target=new URL(form.getAttribute('action') || window.location.href,window.location.origin);const expected=new URL(snapshot.flow.coach_url,window.location.origin).pathname+'/play';
        if(form.matches('[data-save-highlight-form]')){
            event.preventDefault();event.stopImmediatePropagation();
            if(readBlocked||coachHistory||sharedHost.staging||sharedHost.cameraDirector.transitioning||loading||arcade.pending||arcade.replaying)return;
            const activity=++activityGeneration;historyGeneration++;loading=true;const button=form.querySelector('button');if(button)button.disabled=true;
            saveExhibitionHighlight({url:form.getAttribute('action'),gameUrl:snapshot.flow.coach_url,origin:window.location.origin,body:new FormData(form),
                request:(target,settings)=>fetch(target,{...settings,signal:abort.signal}),read:()=>read(url),
                reconcile:async next=>{if(disposed || activity!==activityGeneration)return;if(highlightRequiresPresentation(snapshot,next)){await apply(next,{kind:'highlight-reconcile',isCurrent:()=>!disposed && activity===activityGeneration});say('Highlight saved. The game changed elsewhere; controls now match its saved state.');}else{snapshot=next;arcade.reconcile({state:next.state,available:next.available,flow:next.flow,coach_url:next.flow.coach_url});if(button)button.textContent='Highlight saved';say('Highlight saved. The current presentation is unchanged.');}},
            }).catch(error=>{if(!disposed && activity===activityGeneration){readBlocked=true;say(error.message+' Reload saved state to reconcile before continuing.');}}).finally(()=>{if(activity===activityGeneration)loading=false;});return;
        }
        if(target.origin!==window.location.origin || target.pathname!==expected)return;
        event.preventDefault();event.stopImmediatePropagation();if(readBlocked||coachHistory||sharedHost.staging||sharedHost.cameraDirector.transitioning||loading||arcade.pending||arcade.replaying)return;
        try{const body=new FormData(form);if(event.submitter?.name)body.set(event.submitter.name,event.submitter.value);const fields=serializePlayForm(body.entries());const action=fields.action || 'play';delete fields.action;delete fields.version;sendFlow(action,fields);}catch(error){say(error.message);}
    },options);
    controls.addEventListener('click',event=>{
        const replayButton=event.target.closest('[data-play]');if(replayButton && !coachHistory && snapshot.latest_play){event.preventDefault();event.stopImmediatePropagation();openHistory(String(snapshot.latest_play.number));return;}
        const retry=event.target.closest('[data-play-flow-retry]');if(retry){event.preventDefault();event.stopImmediatePropagation();sendFlow(null);return;}
        const action=event.target.closest('[data-play-flow-action]'),defense=event.target.closest('[data-play-defense]'),pause=event.target.closest('[data-play-watch-pause]');
        if(action||defense||pause){event.preventDefault();event.stopImmediatePropagation();if(pause){watchPaused=!watchPaused;pause.textContent=watchPaused?'Resume Watch':'Pause after this play';return;}if(readBlocked||coachHistory||sharedHost.staging||sharedHost.cameraDirector.transitioning||loading||arcade.pending||arcade.replaying)return;sendFlow(action?.dataset.playFlowAction || 'preferences',defense?{defense_mode:defense.dataset.playDefense}:{});return;}
        const link=event.target.closest('a');if(!link)return;const target=new URL(link.href,window.location.origin);if(target.origin!==window.location.origin)return;if(target.hash && target.pathname===window.location.pathname && target.search===window.location.search)return;
        const game=new URL(snapshot.flow.coach_url,window.location.origin).pathname;
        if(target.pathname===game || target.pathname===game+'/arcade'){event.preventDefault();event.stopImmediatePropagation();resetFeedback();if(target.searchParams.has('play') || target.searchParams.has('replay'))openHistory(target.searchParams.get('play') || target.searchParams.get('replay'));else {if(coachHistory){historyReturn?.();return;}if(arcade.hasLive || arcade.pending || arcade.replaying || loading){say('Finish, submit or cancel the reserved play before changing controls.');return;}const notice=handoffNotice();if(notice){say(notice);return;}forcedCoach=target.pathname===game;setMode();}}
    },{...options,capture:true});
    root.addEventListener('click',event=>{const link=event.target.closest('a');if(!link || controls.contains(link) || !snapshot)return;const target=new URL(link.href,window.location.origin);if(target.hash && target.pathname===window.location.pathname && target.search===window.location.search)return;const game=new URL(snapshot.flow.coach_url,window.location.origin).pathname;if(target.origin!==window.location.origin || ![game,game+'/arcade'].includes(target.pathname))return;event.preventDefault();resetFeedback();if(target.searchParams.has('play') || target.searchParams.has('replay'))openHistory(target.searchParams.get('play') || target.searchParams.get('replay'));else if(link.hasAttribute('data-exhibition-coach')){if(arcade.hasLive || arcade.pending || arcade.replaying || loading){say('Finish, submit or cancel the reserved play before changing controls.');return;}const notice=handoffNotice();if(notice){say(notice);return;}forcedCoach=true;setMode();}else refresh();},options);
    async function openHistory(number){if(readBlocked||coachHistory||arcade.replaying||arcade.pending||arcade.busy||loading||sharedHost.staging||sharedHost.cameraDirector.transitioning||arcade.hasLive)return;const generation=++historyGeneration,activity=++activityGeneration;loading=true;say('Loading recorded play…');try{if(!/^[1-9][0-9]{0,8}$/.test(number))throw new Error('Invalid saved play.');const target=`${snapshot.urls.playback_base}/${number}`;const playback=await read(target);if(disposed || generation!==historyGeneration || arcade.pending || arcade.busy || arcade.hasLive || arcade.replaying || coachHistory)return;if(playback.recording){arcade.openReplay(playback.recording);say('Recorded play loaded. Return to game when finished; the saved game is unchanged.');}else if(playback.animation){if(!playback.controls_html)throw new Error('Saved replay controls are unavailable.');
 const template=document.createElement('template');template.innerHTML=playback.controls_html;const historical=template.content.querySelector('[data-practice]');if(!historical)throw new Error('Saved replay controls are unavailable.');
 const prior={coach,presentation,opening,forcedCoach,mode,nodes:[...controls.childNodes]};const restore=captureSharedPresentation(sharedHost);prior.coach?.suspend?.();prior.coach?.setInteractive?.(false);
 historical.classList.add('persistent-phase-controls');Object.assign(historical.dataset,{replayOnly:'true',autoplay:'true',animation:JSON.stringify(playback.animation),beforeState:JSON.stringify(playback.before),afterState:JSON.stringify(playback.after),penalty:JSON.stringify(playback.penalty),nextAnimation:'null',unitChange:'false',substitutionChange:'false'});
 controls.replaceChildren(historical);coachHistory=true;opening=false;presentation={recorded:true};forcedCoach=true;mode=null;
 try{mountGameSettings(historical);coach=mountPractice(historical,()=>{},{sharedHost,openingClaimed:false,onFrame});}catch(error){controls.replaceChildren(...prior.nodes);coach=prior.coach;presentation=prior.presentation;opening=prior.opening;forcedCoach=prior.forcedCoach;mode=prior.mode;coachHistory=false;prior.coach?.resume?.();restore();setMode();throw error;}
 const back=document.createElement('button');back.type='button';back.textContent='Return to game';back.className='persistent-history-return';
 historyReturn=()=>{coach?.dispose();controls.replaceChildren(...prior.nodes);coach=prior.coach;presentation=prior.presentation;opening=prior.opening;forcedCoach=prior.forcedCoach;mode=prior.mode;coachHistory=false;historyReturn=null;coach?.resume?.();restore();setMode();};back.addEventListener('click',()=>historyReturn?.());controls.append(back);setMode();say('Recorded play loaded. Return to game when finished; the saved game is unchanged.');}}catch(error){if(!disposed)say(error.message);}finally{if(activity===activityGeneration)loading=false;}}
    const dispose=()=>{if(disposed)return;historyReturn?.();historyGeneration++;disposed=true;reader.dispose();abort.abort();coach?.dispose();arcade?.();sharedHost?.dispose();controls.remove();debug?.remove();resetFeedback();delete root.dataset.persistentMounted;};
    document.addEventListener('livewire:navigating',dispose,options);window.addEventListener('pagehide',dispose,options);
    try{await refresh();}catch{if(!sharedHost){abort.abort();controls.remove();debug?.remove();resetFeedback();delete root.dataset.persistentMounted;} }
    return {dispose,refresh,metrics:()=>sharedHost?.metrics(),get savedState(){return snapshot?.state;},get displayState(){return presentation;}};
}

window.addEventListener('pageshow',event=>{if(event.persisted)mountPersistentExhibition(document.querySelector('[data-persistent-presentation]'));});
