// Bind per element so normal loads and Livewire navigation both work without
// accumulating click handlers. These controls do not depend on the 3D renderer.
const bound=new WeakSet();
const bind=(element,handler)=>{
    if(!element || bound.has(element))return;
    bound.add(element);element.addEventListener('click',handler);
};

export function hasOpenGameDialog(root) {
    return Boolean(root?.querySelector('dialog[open]'));
}

export function mountGameSettings(root,confirm=message=>window.confirm(message)) {
    if(!root)return;
    const openSettings=(simulation=false)=>{
        const dialog=root.querySelector('[data-game-settings]');
        if(!dialog)return;
        if(!dialog.open)dialog.showModal();
        if(simulation)dialog.querySelector('#game-settings-simulation')?.scrollIntoView({block:'nearest'});
    };
    bind(root.querySelector('[data-open-game-settings]'),()=>openSettings());
    bind(root.querySelector('[data-open-quick-sim]'),()=>openSettings(true));
    bind(root.querySelector('[data-game-settings] [data-finish-sim-form] button[name="scope"][value="game"]'),event=>{
        if(!confirm('Simulate the rest of this game?'))event.preventDefault();
    });
}
