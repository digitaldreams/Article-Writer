import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Sits on a page part that waits for background work. Reloads the page (morphed, scroll kept)
// until the server renders it without this controller.
export default class extends Controller {
    static values = { interval: Number };

    connect() {
        this.timer = setInterval(() => Turbo.visit(window.location.href, { action: 'replace' }), this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
    }
}
