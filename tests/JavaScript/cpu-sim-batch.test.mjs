import test from 'node:test';
import assert from 'node:assert/strict';
import {runCpuSimBatch} from '../../resources/js/cpu-sim-batch.js';
test('a fifteen game batch awaits every request and reports committed progress', async()=>{
    let calls=0,active=0;const progress=[];
    const result=await runCpuSimBatch(async()=>{
        assert.equal(active,0);active++;
        await Promise.resolve();calls++;active--;
        return {simulated:1,remaining:15-calls,redirect:'/seasons/1?tab=schedule'};
    },state=>progress.push(state));
    assert.equal(calls,15);assert.equal(result.completed,15);
    assert.deepEqual(progress[0],{completed:1,remaining:14});
    assert.deepEqual(progress.at(-1),{completed:15,remaining:0});
});
test('failed requests stop the batch and retry asks the server only for remaining games',async()=>{
    let saved=0,calls=0;
    const request=async()=>{
        calls++;if(calls===3)throw new Error('HTTP 500');
        saved++;return {simulated:1,remaining:5-saved,redirect:'/seasons/1'};
    };
    await assert.rejects(runCpuSimBatch(request),/HTTP 500/);
    assert.equal(saved,2);assert.equal(calls,3);
    const resumed=await runCpuSimBatch(request);
    assert.equal(saved,5);assert.equal(resumed.completed,3);assert.equal(calls,6);
});
test('empty batches stop cleanly and invalid or stalled responses cannot loop forever',async()=>{
    const empty=await runCpuSimBatch(async()=>({simulated:0,remaining:0,redirect:'/seasons/1'}));
    assert.equal(empty.completed,0);
    for(const response of [{simulated:0,remaining:1,redirect:'/'},{simulated:15,remaining:0,redirect:'/'},{simulated:1,remaining:-1,redirect:'/'},{}]) {
        let calls=0;await assert.rejects(runCpuSimBatch(async()=>{calls++;return response;}));assert.equal(calls,1);
    }
});
