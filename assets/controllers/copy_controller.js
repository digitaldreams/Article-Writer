import { Controller } from '@hotwired/stimulus';

// Copies the source text to the clipboard and confirms on the button for a moment.
export default class extends Controller {
    static targets = ['source', 'button'];

    async copy() {
        await navigator.clipboard.writeText(this.sourceTarget.textContent.trim());
        this.buttonTarget.textContent = 'Copied';
        setTimeout(() => (this.buttonTarget.textContent = 'Copy'), 1500);
    }
}
