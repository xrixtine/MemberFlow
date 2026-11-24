/**
 * MemberFlow front-end behaviour (vanilla JS, no framework).
 * Everything here is progressive enhancement: the server still validates
 * and authorizes every request.
 */

import './location.js';
import './weather.js';

// Ask for confirmation before submitting forms marked with data-confirm="...".
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    const message = form.dataset.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
        return;
    }

    // Prevent accidental double submissions and show a loading label.
    // (Idempotency keys protect the server side for payments.)
    if (form.dataset.submitted === 'true') {
        event.preventDefault();
        return;
    }
    form.dataset.submitted = 'true';

    form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = true;
        if (button.dataset.loading) {
            button.dataset.originalText = button.textContent;
            button.textContent = button.dataset.loading;
        }
    });
});

// Re-enable buttons when the page is restored from the back/forward cache.
window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submitted="true"]').forEach((form) => {
        delete form.dataset.submitted;
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = false;
            if (button.dataset.originalText) button.textContent = button.dataset.originalText;
        });
    });
});

// Mobile sidebar drawer.
document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-sidebar-toggle]');
    if (!toggle) return;

    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const open = sidebar?.classList.toggle('-translate-x-full') === false;
    backdrop?.classList.toggle('hidden', !open);
    document.querySelectorAll('[data-sidebar-toggle]').forEach((el) => el.setAttribute('aria-expanded', String(open)));
});

// Generic disclosure menus (e.g. the user menu): data-menu-toggle="id".
document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-menu-toggle]');
    document.querySelectorAll('[data-menu]').forEach((menu) => {
        if (!toggle || menu.id !== toggle.dataset.menuToggle) menu.classList.add('hidden');
    });
    if (toggle) {
        const menu = document.getElementById(toggle.dataset.menuToggle);
        const open = menu?.classList.toggle('hidden') === false;
        toggle.setAttribute('aria-expanded', String(open));
    }
});

// Live membership-level preview: <select data-level-select="preview-id"> whose
// <option>s carry data-name / data-fee / data-validity / data-benefits (JSON).
// The preview is also rendered by the server, so it works without JavaScript.
function updateLevelPreview(select) {
    const preview = document.getElementById(select.dataset.levelSelect);
    if (!preview) return;

    const option = select.selectedOptions[0];
    if (!option || !option.value) {
        preview.hidden = true;
        return;
    }

    preview.hidden = false;
    preview.querySelector('[data-preview="name"]').textContent = option.dataset.name ?? '';
    preview.querySelector('[data-preview="fee"]').textContent = option.dataset.fee ?? '';
    preview.querySelector('[data-preview="validity"]').textContent = option.dataset.validity ?? '';

    const list = preview.querySelector('[data-preview="benefits"]');
    list.replaceChildren();
    let benefits = [];
    try {
        benefits = JSON.parse(option.dataset.benefits || '[]');
    } catch {
        benefits = [];
    }
    benefits.forEach((benefit) => {
        const item = document.createElement('li');
        item.textContent = benefit; // textContent: never interpreted as HTML
        list.appendChild(item);
    });
}

document.addEventListener('change', (event) => {
    if (event.target.matches?.('[data-level-select]')) updateLevelPreview(event.target);
});
document.querySelectorAll('[data-level-select]').forEach(updateLevelPreview);

// Live balance preview for the payment form. Mirrors the server's integer-centavo
// parsing (no floating point). The server re-validates everything under a row lock.
const AMOUNT_PATTERN = /^\d{1,8}(\.\d{1,2})?$/;

function toCents(text) {
    const value = text.trim();
    if (!AMOUNT_PATTERN.test(value)) return null;
    const [whole, fraction = ''] = value.split('.');
    return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
}

function formatPeso(cents) {
    const whole = Math.floor(cents / 100).toLocaleString('en-PH');
    return `₱${whole}.${String(cents % 100).padStart(2, '0')}`;
}

function updateBalancePreview(input) {
    const preview = document.getElementById(input.dataset.balancePreview);
    if (!preview) return;

    const balance = Number(input.dataset.balanceCents);
    const cents = toCents(input.value);
    const remaining = preview.querySelector('[data-preview="remaining"]');
    const status = preview.querySelector('[data-preview="status"]');
    preview.hidden = false;

    if (cents === null || cents <= 0) {
        remaining.textContent = '—';
        status.textContent = 'Enter an amount greater than ₱0.00 (no commas, up to 2 decimals).';
    } else if (cents > balance) {
        remaining.textContent = '—';
        status.textContent = `This exceeds the remaining balance of ${formatPeso(balance)}.`;
    } else {
        remaining.textContent = formatPeso(balance - cents);
        status.textContent = cents === balance
            ? 'Fully paid — the transaction will become "Paid" and can then be approved.'
            : 'Partial payment — the transaction stays "Pending".';
    }
}

document.addEventListener('input', (event) => {
    if (event.target.matches?.('[data-balance-cents]')) updateBalancePreview(event.target);
});
document.querySelectorAll('[data-balance-cents]').forEach(updateBalancePreview);

// Print buttons (reports): <button data-print>.
document.addEventListener('click', (event) => {
    if (event.target.closest('[data-print]')) window.print();
});

// Dismissible flash messages.
document.addEventListener('click', (event) => {
    const close = event.target.closest('[data-dismiss]');
    if (close) close.closest('[data-dismissible]')?.remove();
});
