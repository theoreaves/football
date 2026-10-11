const files = {entrance:'entrance.mp3',anthem:'anthem.mp3',touchdown:'touchdown.mp3',home_turnover:'turnover.mp3',third_down:'third-down.mp3',drive:'drive-break.mp3',quarter:'quarter-break.mp3',halftime:'halftime-break.mp3'};
export function teamAudioUrls(root) {
    let profile = {};
    try { profile = JSON.parse(root.dataset?.teamAudio || '{}'); } catch { /* Bundled defaults. */ }
    const base = (root.dataset?.stadiumAudioBase || '/audio/stadium/v1').replace(/\/$/,'');
    return Object.fromEntries(Object.entries(files).map(([event,file]) => {
        const fallback = `${base}/${file}`;
        return [event, {url: typeof profile?.[event] === 'string' && profile[event] ? profile[event] : fallback, fallback}];
    }));
}
