import test from 'node:test';
import assert from 'node:assert/strict';
import {presentationMetrics,createPresentationMetricsSampler} from '../../resources/js/arcade/presentation-metrics.js';
test('diagnostic sampling is bounded and separates CPU submission from visible frame interval',()=>{
 const sampler=createPresentationMetricsSampler(3);for(let n=0;n<8;n++)sampler.sample({now:n*16,cpuMs:2,visible:true});const raw=sampler.metrics();assert.equal(raw.cpuFrameMs.length,3);assert.equal(raw.frameIntervals.length,3);const formatted=presentationMetrics(raw);assert.equal(formatted.cpuSubmissionMs.p95,2);assert.equal(formatted.visibleNonStagingIntervalMs.p95,16);
 sampler.sample({now:5000,cpuMs:1,visible:false});sampler.sample({now:9000,cpuMs:2,visible:true});assert.equal(presentationMetrics(sampler.metrics()).visibleNonStagingIntervalMs.samples,1);
});
