<x-layouts.app>
    <div class="min-h-screen bg-gray-950 text-gray-100 p-4">
        <div class="max-w-6xl mx-auto space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm text-gray-400">PDF Library</div>
                    <div class="text-2xl font-bold">Playbooks / Cards</div>
                </div>

                <a href="{{ url()->previous() }}"
                   class="text-blue-400 hover:text-blue-300 text-sm font-medium">
                    ← Back
                </a>
            </div>

            <div class="rounded-lg border border-white/10 bg-gray-900/40 p-3">
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-sm text-gray-300">PDF:</label>

                    <select id="pdfSelect"
                            class="flex-1 min-w-[260px] bg-gray-900 border border-white/10 rounded px-3 py-2 text-sm">
                        <option value="">Loading…</option>
                    </select>

                    <button id="openNewTab"
                            type="button"
                            class="px-3 py-2 rounded bg-gray-800 hover:bg-gray-700 border border-white/10 text-sm font-semibold">
                        Open in new tab
                    </button>
                </div>
            </div>

            <div class="rounded-lg border border-white/10 overflow-hidden bg-black/20"
                 style="height: calc(100vh - 190px);">
                <iframe id="pdfFrame" class="w-full h-full" src="" style="border:0"></iframe>
            </div>

            <div class="text-xs text-gray-500">
                Folder: <code>public/pdfs</code>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const listUrl = @json(route('pdf.library.list'));
            const sel = document.getElementById('pdfSelect');
            const frame = document.getElementById('pdfFrame');
            const openBtn = document.getElementById('openNewTab');

            let currentUrl = '';

            function setOptions(files) {
                sel.innerHTML = '';

                if (!files.length) {
                    sel.innerHTML = '<option value="">No PDFs found in public/pdfs</option>';
                    frame.src = '';
                    currentUrl = '';
                    return;
                }

                for (const f of files) {
                    const opt = document.createElement('option');
                    opt.value = f.url;
                    opt.textContent = f.label;
                    sel.appendChild(opt);
                }

                // default to first
                sel.value = files[0].url;
                currentUrl = files[0].url;
                frame.src = currentUrl;
            }

            sel.addEventListener('change', () => {
                currentUrl = sel.value || '';
                frame.src = currentUrl;
            });

            openBtn.addEventListener('click', () => {
                if (!currentUrl) return;
                window.open(currentUrl, '_blank');
            });

            fetch(listUrl, { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => setOptions(data.files || []))
                .catch(() => {
                    sel.innerHTML = '<option value="">Error loading list</option>';
                });
        })();
    </script>
</x-layouts.app>
