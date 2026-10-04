/**
 * DormEase - Main JavaScript
 * Handles sidebar toggle, UI interactions, and utility functions
 */

document.addEventListener('DOMContentLoaded', function () {

    // ── Sidebar Toggle (Mobile) ──────────────────────────────
    const menuToggle   = document.getElementById('menuToggle');
    const sidebar      = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    if (menuToggle) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', () => {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        });
    }

    // Close sidebar on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar && sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        }
    });

    // ── Active Sidebar Link ──────────────────────────────────
    const currentPage = window.location.pathname.split('/').pop() || 'dashboard.php';
    const sidebarLinks = document.querySelectorAll('.sidebar-link');

    sidebarLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
            link.classList.add('active');
        }
    });

    // ── Auto-dismiss Alerts ──────────────────────────────────
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => alert.remove(), 400);
        }, 4000);
    });

    // ── Greeting Based on Time of Day ────────────────────────
    const greetingEl = document.getElementById('timeGreeting');
    if (greetingEl) {
        const hour = new Date().getHours();
        let greeting = 'Good Evening';
        if (hour >= 5 && hour < 12)      greeting = 'Good Morning';
        else if (hour >= 12 && hour < 17) greeting = 'Good Afternoon';
        greetingEl.textContent = greeting;
    }

    // ── Current Date/Time Display ────────────────────────────
    const dateTimeEl = document.getElementById('currentDateTime');
    if (dateTimeEl) {
        function updateDateTime() {
            const now = new Date();
            const options = {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };
            dateTimeEl.textContent = now.toLocaleDateString('en-US', options);
        }
        updateDateTime();
        setInterval(updateDateTime, 60000);
    }

    // ── Tooltip Initialization ───────────────────────────────
    const tooltipTriggers = document.querySelectorAll('[data-tooltip]');
    tooltipTriggers.forEach(el => {
        el.addEventListener('mouseenter', function () {
            const tip = document.createElement('div');
            tip.className = 'tooltip-popup';
            tip.textContent = this.dataset.tooltip;
            document.body.appendChild(tip);
            const rect = this.getBoundingClientRect();
            tip.style.top = rect.top - tip.offsetHeight - 8 + 'px';
            tip.style.left = rect.left + rect.width / 2 - tip.offsetWidth / 2 + 'px';
            this._tooltip = tip;
        });
        el.addEventListener('mouseleave', function () {
            if (this._tooltip) {
                this._tooltip.remove();
                this._tooltip = null;
            }
        });
    });

    // ── Utility: Format currency ─────────────────────────────
    window.formatCurrency = function (amount) {
        return '₱' + parseFloat(amount).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };

    // ── Utility: Confirm action ──────────────────────────────
    window.confirmAction = function (message, callback) {
        if (confirm(message)) {
            callback();
        }
    };

    console.log('%c🏠 DormEase v1.0.0 loaded', 'color: #1a73e8; font-weight: bold;');
});
