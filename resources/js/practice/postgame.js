import {entrancePose} from './tunnel-entrance.js';
import {tunnelPath} from './tunnel-layout.js';

export const POSTGAME_END=100;
export function exitPose(side,index,start,seconds,delay=0) {
    const route=[...tunnelPath(side,index%2?-.7:.7,start)].reverse();
    const pose=entrancePose(side,index,route.at(-1),seconds-delay,route);
    return {...pose,visible:!pose.complete};
}
export function handshakePose(side,index,start,seconds,kind='coach') {
    const offset=kind==='coach'?0:7;
    const meet={x:60+offset,z:26.7+(side==='home'?-.65:.65)};
    const begin=kind==='coach'?1:3;
    const arrive=entrancePose(side,0,meet,seconds-begin,[start,meet],false);
    const leave=kind==='coach'?18:23;
    if(seconds>=leave)return {...exitPose(side,index,meet,seconds-leave),shake:false};
    return {...arrive,visible:true,shake:arrive.complete,yaw:arrive.complete?(side==='home'?0:Math.PI):arrive.yaw};
}
export function mascotFarewell(start,seconds) {
    // Tour the home sideline, pausing to wave at each end; leave after everyone else.
    const points=[start,{x:25,z:-17},{x:95,z:-17},{x:95,z:-17}];
    const lap=entrancePose('home',0,points.at(-1),seconds,points,false);
    if(seconds<65)return {...lap,visible:true,wave:true};
    return {...exitPose('home',0,points.at(-1),seconds-65),wave:false};
}
export function postgameStage(seconds) {
    return seconds<13?'Final whistle · Coaches and quarterbacks meet at midfield'
        :seconds<35?'Thank you for watching · Teams leaving the field'
        :seconds<65?'The crowd heads home · One last wave from the mascot'
        :'Good night from the stadium!';
}
