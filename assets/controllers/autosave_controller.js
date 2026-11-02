import { Controller } from '@hotwired/stimulus';

// Sits on an answer form. Saves the answer a moment after the writer stops typing.
export default class extends Controller {
    static targets = ['status'];

    queue() {
        clearTimeout(this.timer);
        this.statusTarget.textContent = '';
        this.timer = setTimeout(() => this.save(), 800);
    }

    // Typing your own answer picks the "Write your own" choice of a one-choice question.
    pickOwn() {
        const own = this.element.querySelector('input[value="__own"]');
        if (own) own.checked = true;
    }

    async save(keepalive = false) {
        this.timer = null;
        const response = await fetch(this.element.action, { method: 'POST', body: new FormData(this.element), keepalive });
        // A 422 says what to fix (e.g. a missing heading line); anything else is just "Not saved".
        this.statusTarget.textContent = response.ok ? 'Saved' : 422 === response.status ? `Not saved: ${await response.text()}` : 'Not saved';
        // A renamed article section: the next save must name it by its new heading.
        const heading = response.headers.get('X-Section-Heading');
        if (heading && this.element.elements.heading) this.element.elements.heading.value = heading;
    }

    // Leaving right after typing still saves. ponytail: keepalive caps the body at 64 KB, so a very long text may not make it.
    disconnect() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.save(true);
        }
    }
}
