// Shared stadium-local tunnel positions and field-facing world-space entrance paths.
export const TUNNEL_LAYOUT = {
    home:{x:-70,z:-10,direction:1},
    away:{x:70,z:10,direction:-1},
};
export function tunnelPath(side,lane,target){
    const tunnel=TUNNEL_LAYOUT[side],x=tunnel.x+60,z=tunnel.z+26.665+lane;
    const turn=side==='home'?14:106,sideline=side==='home'?-6:59.33;
    return [{x:x-tunnel.direction*3,z},{x:turn,z},{x:turn,z:sideline}, {x:target.x,z:sideline},target];
}
