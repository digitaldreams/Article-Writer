import { Controller } from '@hotwired/stimulus';

// On submit, sends each chosen file's lastModified (ms) by filename: browsers don't put it in the upload.
export default class extends Controller {
    static targets = ['modified'];

    connect() {
        this.element.addEventListener('submit', () => {
            const dates = {};
            for (const input of this.element.querySelectorAll('input[type=file]')) {
                for (const file of input.files) {
                    dates[file.name] = file.lastModified;
                }
            }
            this.modifiedTarget.value = JSON.stringify(dates);
        });
    }
}
