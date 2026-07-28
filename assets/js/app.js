/* =========================================================
   RentSphere — Core App JS (Vanilla, no jQuery)
   ========================================================= */

document.addEventListener('DOMContentLoaded', function () {
    initTheme();
    initSidebarToggle();
    initMobileSidebar();
    initUserMenu();
    autoHideAlerts();
});

/* ---------------- Dark / Light Mode ---------------- */
function initTheme() {
    const stored = localStorage.getItem('rentsphere_theme');
    const theme = stored || 'light';
    document.documentElement.setAttribute('data-theme', theme);

    const toggleBtn = document.getElementById('themeToggle');
    if (!toggleBtn) return;
    updateThemeIcon(theme);

    toggleBtn.addEventListener('click', function () {
        const current = document.documentElement.getAttribute('data-theme');
        const next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('rentsphere_theme', next);
        updateThemeIcon(next);
    });
}

function updateThemeIcon(theme) {
    const icon = document.querySelector('#themeToggle i');
    if (!icon) return;
    icon.className = theme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
}

/* ---------------- Sidebar collapse (desktop) ---------------- */
function initSidebarToggle() {
    const btn = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    if (!btn || !sidebar) return;

    const collapsed = localStorage.getItem('rentsphere_sidebar_collapsed') === '1';
    if (collapsed) sidebar.classList.add('collapsed');

    btn.addEventListener('click', function () {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('rentsphere_sidebar_collapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
    });
}

/* ---------------- Sidebar (mobile) ---------------- */
function initMobileSidebar() {
    const btn = document.getElementById('mobileNavToggle');
    const sidebar = document.getElementById('sidebar');
    if (!btn || !sidebar) return;

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        sidebar.classList.toggle('mobile-open');
    });

    document.addEventListener('click', function (e) {
        if (window.innerWidth <= 900 && sidebar.classList.contains('mobile-open') &&
            !sidebar.contains(e.target) && e.target !== btn) {
            sidebar.classList.remove('mobile-open');
        }
    });
}

/* ---------------- User dropdown menu ---------------- */
function initUserMenu() {
    const trigger = document.getElementById('userMenuTrigger');
    const dropdown = document.getElementById('userMenuDropdown');
    if (!trigger || !dropdown) return;

    trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        dropdown.classList.toggle('open');
    });
    document.addEventListener('click', function () {
        dropdown.classList.remove('open');
    });
}

/* ---------------- Auto-dismiss flash alerts ---------------- */
function autoHideAlerts() {
    document.querySelectorAll('.alert[data-autohide]').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .4s ease';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 400);
        }, 5000);
    });
}

/* ---------------- OTP input auto-advance (used on otp_verify.php) ---------------- */
function initOtpInputs(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const inputs = [...container.querySelectorAll('input')];

    inputs.forEach(function (input, idx) {
        input.addEventListener('input', function () {
            input.value = input.value.replace(/[^0-9]/g, '').slice(0, 1);
            if (input.value && idx < inputs.length - 1) inputs[idx + 1].focus();
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !input.value && idx > 0) inputs[idx - 1].focus();
        });
        input.addEventListener('paste', function (e) {
            e.preventDefault();
            const digits = (e.clipboardData.getData('text') || '').replace(/[^0-9]/g, '').split('');
            digits.forEach((d, i) => { if (inputs[i]) inputs[i].value = d; });
            const last = Math.min(digits.length, inputs.length) - 1;
            if (last >= 0) inputs[last].focus();
        });
    });
}

/* ---------------- Confirm delete (SweetAlert2) ---------------- */
function confirmDelete(formOrUrl, message) {
    message = message || 'This action cannot be undone.';
    Swal.fire({
        title: 'Are you sure?',
        text: message,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#EF4444',
        cancelButtonColor: '#6B7280',
        confirmButtonText: 'Yes, delete it'
    }).then(function (result) {
        if (result.isConfirmed) {
            if (typeof formOrUrl === 'string') {
                window.location.href = formOrUrl;
            } else {
                formOrUrl.submit();
            }
        }
    });
}

/* ---------------- Toast helper (SweetAlert2) ---------------- */
function toast(icon, title) {
    Swal.fire({
        toast: true, position: 'top-end', icon: icon, title: title,
        showConfirmButton: false, timer: 3000, timerProgressBar: true
    });
}
