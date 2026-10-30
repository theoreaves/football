export const puntStrategyCalls = ['punt_distance','punt_hang','punt_pooch','punt_coffin'];
export const trickCalls = ['fake_punt_run','fake_punt_pass','fake_field_goal_run','fake_field_goal_pass'];
export const isSpecialTeamCall = call => ['punt',...puntStrategyCalls,'field_goal','kickoff','onside_kick','extra_point',...trickCalls].includes(call);
export const kickFormation = call => call?.startsWith('fake_punt') ? 'punt' : call?.startsWith('fake_field_goal') ? 'field_goal' : ['punt',...puntStrategyCalls].includes(call) ? 'punt' : call === 'field_goal' ? 'field_goal' : null;
