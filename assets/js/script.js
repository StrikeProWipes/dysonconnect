// script.js — DysonConnect

document.addEventListener('DOMContentLoaded', function () {

    // Sticky header shadow on scroll
    const header = document.getElementById('site-header');
    if (header) {
        window.addEventListener('scroll', function () {
            header.classList.toggle('scrolled', window.scrollY > 10);
        }, { passive: true });
    }

    // Mobile nav toggle
    const toggle = document.getElementById('nav-toggle');
    const nav    = document.getElementById('site-nav');
    if (toggle && nav) {
        toggle.addEventListener('click', function () {
            nav.classList.toggle('open');
            toggle.setAttribute('aria-expanded', nav.classList.contains('open'));
        });
        // Close on outside click
        document.addEventListener('click', function (e) {
            if (!header.contains(e.target)) nav.classList.remove('open');
        });
    }

    // Search form: origin/destination swap
    const swapBtn   = document.getElementById('swap-btn');
    const originSel = document.getElementById('origin');
    const destSel   = document.getElementById('destination');
    if (swapBtn && originSel && destSel) {
        swapBtn.addEventListener('click', function () {
            const tmp = originSel.value;
            originSel.value = destSel.value;
            destSel.value   = tmp;
        });
    }

    // Set today as minimum on date inputs
    const today = new Date().toISOString().split('T')[0];
    document.querySelectorAll('input[type="date"]').forEach(function (input) {
        if (!input.min) input.min = today;
    });

});
