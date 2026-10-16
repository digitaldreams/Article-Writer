import { Controller } from '@hotwired/stimulus';
import EasyMDE from 'easymde';
import 'easymde/dist/easymde.min.css';

// Sits on a textarea. Turns it into a Markdown editor; the textarea keeps the value for the form.
export default class extends Controller {
    connect() {
        // EasyMDE hides the textarea, and the browser can't show "required" on a hidden field. The server still checks it.
        this.element.required = false;
        this.editor = new EasyMDE({ element: this.element, forceSync: true, spellChecker: false, status: false });
        // EasyMDE types into CodeMirror, not the textarea; pass changes on so data-action="input->…" still fires.
        this.editor.codemirror.on('change', () => this.element.dispatchEvent(new Event('input', { bubbles: true })));
    }

    disconnect() {
        this.editor.toTextArea();
        this.editor.cleanup();
    }
}
