export function scoreboardText(state, names) {
    const minutes = String(Math.floor(state.clock / 60)).padStart(2, '0');
    const seconds = String(state.clock % 60).padStart(2, '0');
    const situation = state.phase === 'kickoff' ? 'Kickoff' : state.phase === 'extra_point' ? 'Try · 1-point kick or 2-point play'
        : `Down ${state.down} & ${state.distance} · ${state.spot <= 50 ? 'Own '+state.spot : 'Opponent '+(100-state.spot)} yard line`;
    const ordinal = ['','1st','2nd','3rd','4th'][state.down] || `Down ${state.down}`;
    const compact = state.status === 'final' ? 'Game over' : state.phase === 'kickoff' ? 'Kickoff' : state.phase === 'extra_point' ? 'Extra point try' : `${ordinal} & ${state.distance} · ${state.spot <= 50 ? 'Own '+state.spot : 'Opp '+(100-state.spot)}`;
    return { compact, score: `${names.away} ${state.away_score} — ${names.home} ${state.home_score}`,
        clock: state.status === 'final' ? 'FINAL' : `Q${state.quarter} · ${minutes}:${seconds}`,
        situation: `${names[state.possession]} · ${situation}`,
        management: `Timeouts: ${names.home} ${state.timeouts?.home ?? 3} · ${names.away} ${state.timeouts?.away ?? 3} · ${state.clock_running ? 'Clock running' : 'Clock stopped'}${state.untimed_down ? ' · Untimed down' : ''}` };
}
