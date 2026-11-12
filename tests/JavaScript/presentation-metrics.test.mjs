import test from 'node:test';
import assert from 'node:assert/strict';
import {presentationMetrics,createPresentationMetricsSampler} from '../../resources/js/arcade/presentation-metrics.js';
test('diagnostic sampling is bounded and separates CPU submission from visible frame interval',()=>{
 const sampler=createPresentationMetricsSampler(3);for(let n=0;n<8;n++)sampler.sample({now:n*16,cpuMs:2,visible:true});const raw=sampler.metrics();assert.equal(raw.cpuFrameMs.length,3);assert.equal(raw.frameIntervals.length,3);const formatted=presentationMetrics(raw);assert.equal(formatted.cpuSubmissionMs.p95,2);assert.equal(formatted.visibleNonStagingIntervalMs.p95,16);
 sampler.sample({now:5000,cpuMs:1,visible:false});sampler.sample({now:9000,cpuMs:2,visible:true});assert.equal(presentationMetrics(sampler.metrics()).visibleNonStagingIntervalMs.samples,1);
});

test('optional Chromium heap estimates are labeled approximate shared JS memory, never GPU memory',()=>{
 const heap={usedJSHeapSize:1572864,totalJSHeapSize:4194304,jsHeapSizeLimit:1073741824};
 assert.deepEqual(presentationMetrics({},heap).approximateJsHeapMiB,{used:1.5,allocated:4,limit:1024,note:'Approximate browser JS heap; may be shared across pages. Not GPU memory.'});
 assert.equal(presentationMetrics({},null).approximateJsHeapMiB,null);
 assert.equal(presentationMetrics({},{}).approximateJsHeapMiB,null);
});
