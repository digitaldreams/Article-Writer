import { Controller } from '@hotwired/stimulus';

// Sits on the card inside the turbo-frame. The frame reloads with morph, so a done or
// failed card loses data-controller, Stimulus disconnects, and polling stops.
export default class extends Controller {
    static values = { interval: Number };

    connect() {
        this.timer = setInterval(() => this.element.closest('turbo-frame').reload(), this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
    }
}
