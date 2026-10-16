import { Controller } from '@hotwired/stimulus';

// Sits on an answer form. Saves the answer a moment after the writer stops typing.
export default class extends Controller {
    static targets = ['status'];

    queue() {
        clearTimeout(this.timer);
        this.statusTarget.textContent = '';
        this.timer = setTimeout(() => this.save(), 800);
    }

    async save() {
        const response = await fetch(this.element.action, { method: 'POST', body: new FormData(this.element) });
        this.statusTarget.textContent = response.ok ? 'Saved' : 'Not saved';
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}
