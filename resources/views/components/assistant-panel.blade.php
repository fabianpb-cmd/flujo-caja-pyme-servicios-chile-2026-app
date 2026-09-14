@php
    $assistantResource = request()->route('resource');
    $assistantRoute = request()->route()?->getName();
    $assistantTitle = $assistantResource ? data_get(config("operational.$assistantResource"), 'title') : null;
@endphp
<button type="button" class="btn btn-primary shadow position-fixed bottom-0 end-0 m-4 d-flex align-items-center gap-2" data-bs-toggle="offcanvas" data-bs-target="#assistantPanel" aria-controls="assistantPanel" style="z-index: 1040">
    <i class="bi bi-chat-dots"></i><span>Ayudante TDAT</span>
</button>

<div class="offcanvas offcanvas-end" tabindex="-1" id="assistantPanel" aria-labelledby="assistantPanelLabel" data-assistant-route="{{ $assistantRoute }}" data-assistant-resource="{{ $assistantResource }}" data-assistant-url="{{ route('assistant.ask') }}">
    <div class="offcanvas-header border-bottom">
        <div>
            <h5 class="offcanvas-title" id="assistantPanelLabel">Ayudante TDAT</h5>
            <div class="small text-muted">Te ayudo a entender y completar esta pantalla.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column gap-3">
        <div class="small text-muted">Contexto: {{ $assistantTitle ? ($assistantTitle.' / '.($assistantRoute ?? '')) : ($assistantRoute ?? 'Pantalla actual') }}</div>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-sm btn-outline-primary" type="button" data-assistant-prompt="¿Qué debo ingresar?">¿Qué debo ingresar?</button>
            <button class="btn btn-sm btn-outline-primary" type="button" data-assistant-prompt="Explícame esta pantalla">Explícame esta pantalla</button>
            <button class="btn btn-sm btn-outline-primary" type="button" data-assistant-prompt="¿Cómo se calcula?">¿Cómo se calcula?</button>
            <button class="btn btn-sm btn-outline-primary" type="button" data-assistant-prompt="¿Qué significa este campo?">¿Qué significa este campo?</button>
        </div>
        <div class="border rounded p-3 bg-light flex-grow-1 overflow-auto" data-assistant-conversation aria-live="polite">
            <p class="mb-0 text-muted small">El ayudante no inventa datos ni realiza operaciones.</p>
        </div>
        <form data-assistant-form class="d-flex gap-2" novalidate>
            <textarea class="form-control" rows="2" maxlength="2000" placeholder="Escribe tu pregunta..." aria-label="Pregunta para Ayudante TDAT" data-assistant-question></textarea>
            <button class="btn btn-primary align-self-end" type="submit" data-assistant-send>Enviar</button>
        </form>
        <div class="small text-muted">El ayudante no inventa datos ni realiza operaciones.</div>
    </div>
</div>

<script nonce="{{ $cspNonce ?? '' }}">
    (() => {
        const panel = document.getElementById('assistantPanel');
        if (!panel) return;
        const form = panel.querySelector('[data-assistant-form]');
        const input = panel.querySelector('[data-assistant-question]');
        const send = panel.querySelector('[data-assistant-send]');
        const conversation = panel.querySelector('[data-assistant-conversation]');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const allowedIds = new Set(['client_id', 'project_id', 'milestone_id', 'cash_account_id']);
        const sensitive = /(password|two_factor|recovery|token|csrf|rut|email|phone|address|bank_account|document_number|notes)/i;
        const history = [];
        let focusedField = '';

        document.addEventListener('focusin', (event) => {
            const field = event.target;
            if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
                focusedField = field.name || '';
            }
        });

        const screenForm = () => {
            const values = {};
            document.querySelectorAll('input[name], select[name], textarea[name]').forEach((field) => {
                if (sensitive.test(field.name)) return;
                if (allowedIds.has(field.name)) values[field.name] = field.value;
                else if (field.name === 'issue_date') values[field.name] = field.value;
                else values[field.name] = field.value === '' ? '' : 'filled';
            });
            return values;
        };

        const append = (label, text, badge = '') => {
            const block = document.createElement('div');
            block.className = 'mb-3';
            const title = document.createElement('div');
            title.className = 'small fw-semibold';
            title.textContent = label;
            block.append(title);
            if (badge) {
                const tag = document.createElement('span');
                tag.className = 'badge text-bg-secondary me-1';
                tag.textContent = badge;
                block.append(tag);
            }
            const body = document.createElement('div');
            body.className = 'small mt-1';
            body.textContent = text;
            block.append(body);
            conversation.append(block);
            conversation.scrollTop = conversation.scrollHeight;
        };

        const ask = async (question) => {
            const text = question.trim();
            if (!text) return;
            append('Tú', text);
            input.value = '';
            send.disabled = true;
            try {
                const response = await fetch(panel.dataset.assistantUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
                    body: JSON.stringify({question: text, route: panel.dataset.assistantRoute, resource: panel.dataset.assistantResource, focused_field: focusedField, form: screenForm(), history}),
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.message || 'No pude consultar el ayudante.');
                append('Ayudante TDAT', data.answer || 'No está definido con la información disponible.', data.status || 'NOT_DEFINED');
                history.push({question: text, answer: data.answer || ''});
                if (history.length > 6) history.shift();
            } catch (error) {
                append('Ayudante TDAT', error.message || 'No pude consultar el ayudante en este momento.', 'NOT_DEFINED');
            } finally {
                send.disabled = false;
                input.focus();
            }
        };

        form.addEventListener('submit', (event) => { event.preventDefault(); ask(input.value); });
        input.addEventListener('keydown', (event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); form.requestSubmit(); } });
        panel.querySelectorAll('[data-assistant-prompt]').forEach((button) => button.addEventListener('click', () => ask(button.dataset.assistantPrompt || '')));
    })();
</script>
