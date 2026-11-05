import test from 'node:test';
import assert from 'node:assert/strict';
import {mountGameSettings,hasOpenGameDialog} from '../../resources/js/practice/game-settings.js';
import {canAdvanceCpu} from '../../resources/js/practice/cpu-flow.js';

function fixture(){
    const button=()=>({handlers:[],addEventListener(type,handler){this.handlers.push(handler);},click(event={}){this.handlers.forEach(handler=>handler(event));}});
    const settings=button(),quick=button(),simulate=button();let opened=0,scrolled=0;
    const dialog={open:false,showModal(){this.open=true;opened++;},querySelector:()=>({scrollIntoView(){scrolled++;}})};
    const elements={'[data-game-settings]':dialog,'[data-open-game-settings]':settings,'[data-open-quick-sim]':quick,'[data-game-settings] [data-finish-sim-form] button[name="scope"][value="game"]':simulate};
    const root={querySelector:selector=>selector==='dialog[open]'?(dialog.open?dialog:null):elements[selector]};
    return {root,settings,quick,simulate,dialog,opened:()=>opened,scrolled:()=>scrolled};
}

test('settings work on initial and navigated pages with idempotent binding',()=>{
    for(let navigation=0;navigation<2;navigation++){
        const page=fixture();mountGameSettings(page.root);mountGameSettings(page.root);
        page.settings.click();assert.equal(page.dialog.open,true);assert.equal(page.opened(),1);
        assert.equal(page.settings.handlers.length,1);
        page.dialog.open=false;page.quick.click();assert.equal(page.dialog.open,true);assert.equal(page.scrolled(),1);
    }
});

test('CPU autoplay waits for any open settings dialog and can resume after close',()=>{
    const page=fixture(),ready={enabled:true,visible:true,ready:true,submitting:false,final:false};
    mountGameSettings(page.root);
    assert.equal(canAdvanceCpu({...ready,dialogOpen:hasOpenGameDialog(page.root)}),true);
    page.settings.click();assert.equal(canAdvanceCpu({...ready,dialogOpen:hasOpenGameDialog(page.root)}),false);
    page.dialog.open=false;assert.equal(canAdvanceCpu({...ready,dialogOpen:hasOpenGameDialog(page.root)}),true);
});

test('Quick Sim retains confirmation once per click and handles pages without settings',()=>{
    const page=fixture();let confirmations=0,prevented=0;
    const confirm=()=>{confirmations++;return false;};mountGameSettings(page.root,confirm);mountGameSettings(page.root,confirm);
    page.simulate.click({preventDefault(){prevented++;}});
    assert.equal(confirmations,1);assert.equal(prevented,1);
    mountGameSettings(null);mountGameSettings({querySelector:()=>null});
});
