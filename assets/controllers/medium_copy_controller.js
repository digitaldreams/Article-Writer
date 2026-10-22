import { Controller } from '@hotwired/stimulus';

// Copies the article as HTML that Medium's editor keeps. Medium has one big heading (h1–h3) and one small (h4),
// and splits a code block at every empty line, so parts and the subtitle become h4 and empty code lines get a space.
export default class extends Controller {
    static targets = ['source', 'button'];

    async copy() {
        const article = this.sourceTarget.cloneNode(true);
        article.querySelector('.article-edit')?.remove();
        const subtitle = article.querySelector(':scope > h1:first-child + h2');
        [subtitle, ...article.querySelectorAll('h3, h5, h6')].forEach((heading) => {
            if (heading) heading.replaceWith(Object.assign(document.createElement('h4'), { innerHTML: heading.innerHTML }));
        });
        article.querySelectorAll('pre code').forEach((code) => (code.textContent = code.textContent.trimEnd().replace(/^$/gm, ' ')));

        await navigator.clipboard.write([new ClipboardItem({ 'text/html': new Blob([article.innerHTML], { type: 'text/html' }) })]);
        this.buttonTarget.textContent = 'Copied';
        setTimeout(() => (this.buttonTarget.textContent = 'Copy for Medium'), 1500);
    }
}
