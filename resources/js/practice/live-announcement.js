// Use animation events, so the banner never reveals the result before it happens.
export function turnoverMoment(animation) {
    for (const [time, label] of animation?.events || []) {
        if (/intercept/i.test(label)) return {time, title:'INTERCEPTION!'};
        if (/fumbl/i.test(label)) return {time, title:'FUMBLE!'};
    }
    return null;
}

export function crossedTurnover(moment, previous, current) {
    return Boolean(moment && current >= previous && moment.time > previous && moment.time <= current);
}
