export function scoreboardText(state, names) {
    const minutes = String(Math.floor(state.clock / 60)).padStart(2, '0');
    const seconds = String(state.clock % 60).padStart(2, '0');
    const situation = state.phase === 'kickoff' ? 'Kickoff' : state.phase === 'extra_point' ? 'Extra point'
        : `Down ${state.down} & ${state.distance} · ${state.spot <= 50 ? 'Own '+state.spot : 'Opponent '+(100-state.spot)} yard line`;
    return { score: `${names.away} ${state.away_score} — ${names.home} ${state.home_score}`,
        clock: state.status === 'final' ? 'FINAL' : `Q${state.quarter} · ${minutes}:${seconds}`,
        situation: `${names[state.possession]} · ${situation}` };
}
