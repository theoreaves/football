const percentile=(values,p)=>{const sorted=values.filter(Number.isFinite).sort((a,b)=>a-b);return sorted.length?Number(sorted[Math.min(sorted.length-1,Math.floor((sorted.length-1)*p))].toFixed(2)):null;};
export function presentationMetrics(metrics) {
 const cpu=metrics.cpuFrameMs || [],intervals=(metrics.frameIntervals || []).filter(frame=>frame.visible && !frame.staging).map(frame=>frame.ms);
 return {scene:metrics.sceneId,stadium:metrics.stadiumId,ball:metrics.ballId,canvas:metrics.canvasId,renderer:metrics.rendererId,
  cpuSubmissionMs:{p50:percentile(cpu,.5),p95:percentile(cpu,.95),samples:cpu.length},visibleNonStagingIntervalMs:{p50:percentile(intervals,.5),p95:percentile(intervals,.95),samples:intervals.length},
  frames:metrics.frames,models:metrics.actors,visibleModels:metrics.visibleActors,layers:metrics.layers,resizeListeners:metrics.resizeSubscriptions,rafCount:metrics.rafCount,audioOwners:metrics.audioOwners,activeAdapters:metrics.activeAdapters,
  drawCalls:metrics.drawCalls,geometries:metrics.geometries,textures:metrics.textures};
}

export function createPresentationMetricsSampler(limit=240) {
 let previous=null,previousVisible=false,frames=0;const cpuFrameMs=[],frameIntervals=[];
 return {sample({now,cpuMs,visible=true,staging=false}){frames++;if(Number.isFinite(cpuMs)){cpuFrameMs.push(cpuMs);if(cpuFrameMs.length>limit)cpuFrameMs.shift();}if(previous!==null){frameIntervals.push({ms:Math.max(0,now-previous),visible:visible&&previousVisible,staging});if(frameIntervals.length>limit)frameIntervals.shift();}previous=now;previousVisible=visible;},metrics(){return {frames,cpuFrameMs:[...cpuFrameMs],frameIntervals:frameIntervals.map(value=>({...value}))};}};
}
