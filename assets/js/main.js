/*
 * main.js
 * -------
 * Small, plain-JavaScript helpers used across the site.
 * Each block only runs if its elements exist on the current page.
 *
 * IMPORTANT: everything here is only for convenience. All real checks
 * (email domain, password rules...) are done again in PHP on the server.
 */

document.addEventListener('DOMContentLoaded', function () {

    // ---- 1. Mobile menu button ----
    var header = document.querySelector('.site-header');
    var toggle = document.querySelector('.nav-toggle');
    if (header && toggle) {
        toggle.addEventListener('click', function () {
            var open = header.classList.toggle('nav-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    // Close the account dropdown when clicking anywhere else
    document.addEventListener('click', function (event) {
        document.querySelectorAll('details.user-menu[open]').forEach(function (menu) {
            if (!menu.contains(event.target)) menu.removeAttribute('open');
        });
    });

    // ---- 2. Show / hide password (the eye button) ----
    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('data-toggle-password'));
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.querySelector('.eye-open').style.display = show ? 'none' : '';
            button.querySelector('.eye-closed').style.display = show ? '' : 'none';
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    // ---- 3. Password strength bar ----
    document.querySelectorAll('[data-strength-for]').forEach(function (bar) {
        var input = document.getElementById(bar.getAttribute('data-strength-for'));
        var text = bar.nextElementSibling;
        var labels = ['', 'Weak', 'Okay', 'Strong'];

        input.addEventListener('input', function () {
            var p = input.value;
            var level = 0;
            if (p.length > 0) level = 1;
            if (p.length >= 8 && /[A-Za-z]/.test(p) && /\d/.test(p)) level = 2;
            if (level === 2 && p.length >= 10 && /[^A-Za-z0-9]/.test(p)) level = 3;
            bar.setAttribute('data-level', level);
            if (text) text.textContent = labels[level];
        });
    });

    // ---- 4. Registration: live university email check ----
    // Each <option> of the university list has data-domain="lus.ac.bd".
    // Only runs in strict mode (REQUIRE_UNIVERSITY_EMAIL = true), because
    // register.php only prints the #email-status element in that mode.
    var uniSelect = document.getElementById('university_id');
    var emailInput = document.getElementById('email');
    var emailStatus = document.getElementById('email-status');
    if (uniSelect && emailInput && emailStatus) {
        var checkEmail = function () {
            var option = uniSelect.options[uniSelect.selectedIndex];
            var domain = option ? option.getAttribute('data-domain') : '';
            var email = emailInput.value.trim().toLowerCase();
            var emailDomain = email.indexOf('@') > -1 ? email.split('@').pop() : '';

            emailInput.classList.remove('is-valid', 'is-invalid');
            emailStatus.className = 'field-help';

            if (!domain) {
                emailStatus.textContent = 'Choose your university first.';
                return;
            }
            if (!emailDomain) {
                emailStatus.textContent = 'Use your official @' + domain + ' email address.';
                return;
            }
            var ok = emailDomain === domain || emailDomain.endsWith('.' + domain);
            emailInput.classList.add(ok ? 'is-valid' : 'is-invalid');
            emailStatus.className = ok ? 'field-ok' : 'field-error';
            emailStatus.textContent = ok
                ? 'University email accepted'
                : 'This is not a ' + option.textContent.trim() + ' email. Use your @' + domain + ' address.';
        };
        uniSelect.addEventListener('change', checkEmail);
        emailInput.addEventListener('input', checkEmail);
        if (emailInput.value) checkEmail();
    }

    // ---- 5. "Resend available in 00:44" countdown ----
    var countdown = document.querySelector('[data-countdown]');
    if (countdown) {
        var seconds = parseInt(countdown.getAttribute('data-countdown'), 10);
        var resendButton = document.getElementById('resend-button');
        var tick = function () {
            if (seconds <= 0) {
                countdown.style.display = 'none';
                if (resendButton) resendButton.disabled = false;
                return;
            }
            var m = String(Math.floor(seconds / 60)).padStart(2, '0');
            var s = String(seconds % 60).padStart(2, '0');
            countdown.textContent = 'Resend available in ' + m + ':' + s;
            seconds--;
            setTimeout(tick, 1000);
        };
        tick();
    }

    // ---- 7. "Are you sure?" for important buttons (forms with data-confirm) ----
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
        });
    });

    // ---- 8. Start a Group: only show packages of the chosen destination + live price preview ----
    // (The real price is always calculated again in PHP. This is only a preview.)
    var destSelect = document.getElementById('destination_id');
    var packageSelect = document.getElementById('package_id');
    var maxSelect = document.getElementById('max_members');
    var minSelect = document.getElementById('min_members');
    var preview = document.getElementById('price-preview');
    if (document.getElementById('create-group-form') && destSelect && packageSelect) {
        var taka = function (n) { return '৳' + Math.ceil(n).toLocaleString('en-US'); };

        var filterPackages = function () {
            var dest = destSelect.value;
            Array.prototype.forEach.call(packageSelect.options, function (opt) {
                if (!opt.value) return;
                var show = !dest || opt.getAttribute('data-destination') === dest;
                opt.hidden = !show;
                opt.disabled = !show;
                if (!show && opt.selected) packageSelect.value = '';
            });
        };

        var updatePreview = function () {
            var opt = packageSelect.options[packageSelect.selectedIndex];
            if (!opt || !opt.value) { preview.hidden = true; return; }
            var shared = parseFloat(opt.getAttribute('data-shared'));
            var perPerson = parseFloat(opt.getAttribute('data-per-person'));
            var capacity = parseInt(opt.getAttribute('data-max'), 10);
            var minimum = parseInt(opt.getAttribute('data-min'), 10);

            // Limit the size drop-downs to what this package allows
            [maxSelect, minSelect].forEach(function (select) {
                Array.prototype.forEach.call(select.options, function (o) {
                    var n = parseInt(o.value, 10);
                    o.disabled = n > capacity || (select === minSelect && n < minimum);
                });
            });
            if (parseInt(maxSelect.value, 10) > capacity) maxSelect.value = String(capacity);
            if (parseInt(minSelect.value, 10) < minimum) minSelect.value = String(minimum);
            if (parseInt(minSelect.value, 10) > parseInt(maxSelect.value, 10)) minSelect.value = maxSelect.value;

            var size = parseInt(maxSelect.value, 10);
            var minSize = parseInt(minSelect.value, 10);
            document.getElementById('preview-text').textContent =
                taka(shared / size + perPerson) + '/person when all ' + size + ' join';
            document.getElementById('preview-sub').textContent =
                opt.getAttribute('data-type') + ' · ' + taka(shared / minSize + perPerson) + '/person at the minimum of ' + minSize +
                ' · Formula: ' + taka(shared) + ' ÷ members + ' + taka(perPerson);
            preview.hidden = false;
        };

        destSelect.addEventListener('change', function () { filterPackages(); updatePreview(); });
        packageSelect.addEventListener('change', function () {
            var opt = packageSelect.options[packageSelect.selectedIndex];
            if (opt && opt.value) destSelect.value = opt.getAttribute('data-destination');
            updatePreview();
        });
        maxSelect.addEventListener('change', updatePreview);
        minSelect.addEventListener('change', updatePreview);
        filterPackages();
        updatePreview();
    }

    // ---- 9. Add expense: switch between equal and custom split, show the running total ----
    var splitToggle = document.querySelector('[data-split-toggle]');
    if (splitToggle) {
        var amountInput = document.getElementById('amount');
        var showSplit = function () {
            var checked = splitToggle.querySelector('input:checked');
            var mode = checked ? checked.value : 'equal';
            document.querySelectorAll('.split-panel').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-split') !== mode;
            });
        };
        var showTotal = function () {
            var total = 0;
            document.querySelectorAll('[data-share]').forEach(function (input) { total += parseInt(input.value || '0', 10) || 0; });
            var amount = parseInt(amountInput.value || '0', 10) || 0;
            var note = document.querySelector('[data-share-total]');
            note.textContent = 'Shares total ৳' + total.toLocaleString('en-US') + ' of ৳' + amount.toLocaleString('en-US') +
                (total === amount ? '. Exactly right.' : (total < amount ? '. ৳' + (amount - total).toLocaleString('en-US') + ' left to assign.' : '. Too much by ৳' + (total - amount).toLocaleString('en-US') + '.'));
            note.className = 'field-help ' + (total === amount && amount > 0 ? 'text-primary' : 'text-accent');
        };
        splitToggle.addEventListener('change', showSplit);
        document.querySelectorAll('[data-share]').forEach(function (input) { input.addEventListener('input', showTotal); });
        amountInput.addEventListener('input', showTotal);
        showSplit();
    }

    // ---- 10. Group room: refresh member count and price every 15 seconds ----
    var live = document.querySelector('[data-group-live]');
    if (live && window.fetch) {
        var groupId = live.getAttribute('data-group-live');
        var firstCount = null;
        var poll = function () {
            fetch(live.baseURI.split('/group.php')[0] + '/api/group-status.php?id=' + groupId, { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data) return;
                    if (firstCount === null) firstCount = data.members;
                    live.querySelectorAll('[data-live="count"]').forEach(function (el) { el.textContent = data.members; });
                    live.querySelectorAll('[data-live="price"]').forEach(function (el) { el.textContent = data.price_per_person_text; });
                    live.querySelectorAll('[data-live="spots"]').forEach(function (el) { el.textContent = data.spots_left; });
                    live.querySelectorAll('[data-live="fill"]').forEach(function (el) { el.style.width = data.fill_percent + '%'; });
                    if (data.members !== firstCount && !document.getElementById('live-notice')) {
                        var n = document.createElement('div');
                        n.id = 'live-notice';
                        n.className = 'alert alert-info';
                        n.innerHTML = 'The group changed while you were here. <a href="">Refresh</a> to see the new members and cost breakdown.';
                        live.insertBefore(n, live.children[3] || null);
                    }
                })
                .catch(function () { /* offline: try again next time */ });
        };
        setInterval(poll, 15000);
    }

    // ---- 6. Edit profile: preview the chosen photo before saving ----
    var photoInput = document.getElementById('photo');
    var photoPreview = document.getElementById('photo-preview');
    if (photoInput && photoPreview) {
        photoInput.addEventListener('change', function () {
            var file = photoInput.files[0];
            if (!file) return;
            var img = document.createElement('img');
            img.className = 'avatar avatar-lg';
            img.alt = 'New profile photo';
            img.src = URL.createObjectURL(file);
            photoPreview.innerHTML = '';
            photoPreview.appendChild(img);
            var name = document.getElementById('photo-name');
            if (name) name.textContent = file.name;
        });
    }
});
