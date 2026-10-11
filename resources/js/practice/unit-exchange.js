import {sampleHuddle} from './huddle.js';
export const playerKey = player => `${player.side}:${player.id ?? player.role}`;
export function sidelineSlot(side, index) {
    return {x: 18 + (index % 36) * 2.3, y: 0,
        z: side === 'home' ? -6 - Math.floor(index / 36) * 2.8 : 59.33 + Math.floor(index / 36) * 2.8};
}
// A comfortable jog with acceleration/deceleration. These seconds never touch the clock.
const speed = 6, ramp = .7;
function travel(start, end, seconds, delay = 0) {
    const distance = Math.hypot(end.x-start.x,end.z-start.z);
    const duration = Math.max(ramp*2, distance/speed + ramp);
    const t = Math.max(0, Math.min(duration, seconds-delay));
    const cruise = distance/(duration-ramp);
    const moved = t < ramp ? cruise*t*t/(2*ramp)
        : t > duration-ramp ? distance-cruise*(duration-t)**2/(2*ramp)
        : cruise*(t-ramp/2);
    const fraction = distance < .001 ? 1 : Math.min(1,moved/distance);
    return {x:start.x+(end.x-start.x)*fraction,y:0,z:start.z+(end.z-start.z)*fraction,
        duration, arrived:seconds-delay >= duration};
}
export function unitExchange(finalFrame, incomingFrame, line, possession, homeDirection, roster) {
    const slots = new Map();
    for (const side of ['home','away']) (roster[side] || []).forEach((player,i) => slots.set(playerKey({...player,side}),sidelineSlot(side,i)));
    const bench = (player,i) => slots.get(playerKey(player)) || sidelineSlot(player.side,i);
    const outgoing = finalFrame.players.map((player,i) => {
        const end = bench(player,i);
        return {player,start:player,end,duration:Math.hypot(player.x-end.x,player.z-end.z) < .01 ? 0 : travel(player,end,0).duration};
    });
    const destinations = incomingFrame ? sampleHuddle(incomingFrame,line,possession,1,homeDirection).players : [];
    const incoming = destinations.map((player,i) => {
        const start = bench(player,i);
        const previous = outgoing.find(entry => playerKey(entry.player) === playerKey(player));
        const delay = previous ? previous.duration + .3 : 1 + (i%4)*.2;
        return {player,start,end:player,delay,duration:travel(start,player,0,delay).duration};
    });
    const duration = Math.max(0,...outgoing.map(e=>e.duration),...incoming.map(e=>e.delay+e.duration));
    const sample = seconds => ({
        outgoing:outgoing.map(e=>({...e.player,...travel(e.start,e.end,seconds),visible:seconds < e.duration})),
        incoming:incoming.map(e=>({...e.player,...travel(e.start,e.end,seconds,e.delay),visible:seconds >= e.delay})),
        complete:seconds >= duration,
    });
    return {duration,sample};
}
