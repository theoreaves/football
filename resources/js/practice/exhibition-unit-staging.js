import {exchangeSpeed, sidelineSlot} from './unit-exchange.js';

// Presentation-only exchange between actual last-drawn positions and the next
// adapter's formation. The live engine/recorded clock is held until it completes.
export function createExhibitionUnitStaging(before,after,roster={}) {
    const old=new Map(before.map(actor=>[actor.key,actor])),next=new Map(after.map(actor=>[actor.key,actor])),bench=new Map();
    for(const side of ['home','away'])(roster[side] || []).forEach((player,i)=>bench.set(`${side}:${player.id ?? player.role}`,sidelineSlot(side,i)));
    const keys=[...new Set([...old.keys(),...next.keys()])];
    const entries=keys.map((key,index)=>{
        const side=key.split(':')[0],slot=bench.get(key) || sidelineSlot(side,index);
        const start=old.get(key) || {...slot,key},end=next.get(key) || {...slot,key};
        const distance=Math.hypot(end.x-start.x,end.z-start.z),ramp=.55;
        const duration=distance<.05?0:Math.max(ramp*2,distance/exchangeSpeed+ramp);
        return {key,start,end,distance,ramp,duration,incoming:next.has(key)};
    });
    const duration=Math.max(0,...entries.map(entry=>entry.duration));
    return {duration,sample(seconds){
        return entries.map(entry=>{
            const {start,end,duration,distance,ramp}=entry,t=Math.max(0,Math.min(duration,seconds));
            const cruise=duration ? distance/(duration-ramp) : 0;
            const moved=t<ramp?cruise*t*t/(2*ramp):t>duration-ramp?distance-cruise*(duration-t)**2/(2*ramp):cruise*(t-ramp/2);
            const fraction=distance<.05?1:Math.min(1,Math.max(0,moved/distance));
            return {key:entry.key,x:start.x+(end.x-start.x)*fraction,y:0,z:start.z+(end.z-start.z)*fraction,
                heading:Math.atan2(end.x-start.x,end.z-start.z),moving:t<duration && distance>=.05,
                visible:entry.incoming || t<duration,finished:seconds>=duration};
        });
    }};
}
