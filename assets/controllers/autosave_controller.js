import { Controller } from '@hotwired/stimulus';

// Sits on an answer form. Saves the answer a moment after the writer stops typing.
export default class extends Controller {
    static targets = ['status'];

    queue() {
        clearTimeout(this.timer);
        this.statusTarget.textContent = '';
        this.timer = setTimeout(() => this.save(), 800);
    }

    async save(keepalive = false) {
        this.timer = null;
        const response = await fetch(this.element.action, { method: 'POST', body: new FormData(this.element), keepalive });
        this.statusTarget.textContent = response.ok ? 'Saved' : 'Not saved';
    }

    // Leaving right after typing still saves. ponytail: keepalive caps the body at 64 KB, so a very long text may not make it.
    disconnect() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.save(true);
        }
    }
}
