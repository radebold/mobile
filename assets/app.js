function confirmAppUpdate() {
    return window.confirm(
        'Update aus GitHub installieren?\n\n' +
        'Die aktuellen Programmdateien werden vorher automatisch gesichert.\n' +
        'Konfiguration und Gesprächsdaten bleiben unverändert.'
    );
}

function statusChanged(select) {
    if (!select || !select.form) return false;

    if (select.value === 'beantwortet') {
        var answeredOk = window.confirm(
            'Als beantwortet / gesendet markieren?\n\n' +
            'Das Gespräch wird ausgeblendet und die zugehörigen Gmail-Nachrichten werden archiviert.\n\n' +
            'Wenn der Käufer später erneut schreibt, erscheint das Gespräch automatisch wieder als Neu.'
        );
        if (!answeredOk) {
            var answeredOldIndex = parseInt(select.getAttribute('data-old-index'), 10);
            if (!isNaN(answeredOldIndex)) select.selectedIndex = answeredOldIndex;
            return false;
        }
    }

    if (select.value === 'erledigt') {
        var ok = window.confirm(
            'Kein Interesse setzen?\n\n' +
            'Das Gespräch wird ausgeblendet und die zugehörigen Gmail-Nachrichten werden in den Papierkorb verschoben.\n\n' +
            'Die Nachrichten auf mobile.de selbst bleiben erhalten.'
        );
        if (!ok) {
            var oldIndex = parseInt(select.getAttribute('data-old-index'), 10);
            if (!isNaN(oldIndex)) select.selectedIndex = oldIndex;
            return false;
        }
    }

    select.form.submit();
    return true;
}

function rememberStatusIndex(select) {
    if (select) select.setAttribute('data-old-index', select.selectedIndex);
}

function confirmGmailDraft() {
    return window.confirm('Den aktuellen Text als Gmail-Entwurf im passenden Antwort-Thread speichern?\n\nEs wird nichts automatisch gesendet.');
}

function copyDraft(id, button) {
    var field = document.getElementById(id);
    if (!field) return;
    field.focus();
    field.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    if (ok) {
        var oldText = button.innerHTML;
        button.innerHTML = 'Kopiert';
        window.setTimeout(function () { button.innerHTML = oldText; }, 1400);
    } else {
        alert('Kopieren war nicht automatisch möglich. Bitte Text markieren und manuell kopieren.');
    }
}

function whatsappFormatTime(value) {
    if (!value) return '';

    var parts = String(value).split(' ');
    if (parts.length !== 2) return value;

    var dateParts = parts[0].split('-');
    var timeParts = parts[1].split(':');

    if (dateParts.length !== 3 || timeParts.length < 2) return value;

    return dateParts[2] + '.' + dateParts[1] + '. ' + timeParts[0] + ':' + timeParts[1];
}

function getWhatsAppStatusElements() {
    return {
        block: document.getElementById('whatsapp-system-block'),
        dot: document.getElementById('whatsapp-system-dot'),
        text: document.getElementById('whatsapp-system-text'),
        meta: document.getElementById('whatsapp-system-meta'),
        button: document.getElementById('whatsapp-test-button')
    };
}

function renderWhatsAppStatus(data) {
    var el = getWhatsAppStatusElements();
    if (!el.block || !el.dot || !el.text || !el.meta) return;

    el.dot.className = 'system-dot';

    if (!data || data.ok !== true) {
        el.dot.classList.add('bad');
        el.text.textContent = 'WhatsApp';
        el.meta.textContent = 'Status nicht verfügbar';
        return;
    }

    if (!data.enabled || !data.configured) {
        el.dot.classList.add('bad');
        el.text.textContent = 'WhatsApp';
        el.meta.textContent = 'nicht konfiguriert';
        return;
    }

    if (data.last_error) {
        el.dot.classList.add('bad');
        el.text.textContent = 'WhatsApp';
        el.meta.textContent = 'Fehler beim letzten Check';
        el.block.title = data.last_error;
        return;
    }

    if (data.cron_healthy) {
        el.dot.classList.add('ok');
        el.text.textContent = 'WhatsApp';
        el.meta.textContent = 'Check ' + whatsappFormatTime(data.last_run);
        el.block.title = data.last_success
            ? 'Letzter Versand: ' + whatsappFormatTime(data.last_success)
            : 'Automatische Prüfung aktiv';
        return;
    }

    el.dot.classList.add('warn');
    el.text.textContent = 'WhatsApp';

    if (data.last_run) {
        el.meta.textContent = 'Check zu alt: ' + whatsappFormatTime(data.last_run);
    } else if (data.initialized) {
        el.meta.textContent = 'Automatik noch nicht gestartet';
    } else {
        el.meta.textContent = 'Baseline noch nicht gesetzt';
    }
}

function refreshWhatsAppStatus() {
    if (!window.fetch) return;

    fetch('functions.php?status=1&_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store'
    })
        .then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function (data) {
            renderWhatsAppStatus(data);
        })
        .catch(function () {
            renderWhatsAppStatus(null);
        });
}

function sendWhatsAppUiTest() {
    var el = getWhatsAppStatusElements();
    if (!el.button || !window.fetch) return;

    var oldText = el.button.textContent;
    el.button.disabled = true;
    el.button.textContent = '…';

    fetch('functions.php?ui_test=1&_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store'
    })
        .then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok || !data.ok) {
                    throw new Error(data && data.error ? data.error : 'HTTP ' + response.status);
                }
                return data;
            });
        })
        .then(function () {
            el.button.textContent = 'Gesendet';
            window.setTimeout(function () {
                el.button.textContent = oldText;
                el.button.disabled = false;
            }, 1600);
            refreshWhatsAppStatus();
        })
        .catch(function (error) {
            el.button.textContent = 'Fehler';
            el.button.title = error.message || 'WhatsApp-Test fehlgeschlagen';
            window.setTimeout(function () {
                el.button.textContent = oldText;
                el.button.disabled = false;
            }, 2200);
            refreshWhatsAppStatus();
        });
}

function initWhatsAppSystemStatus() {
    var system = document.querySelector('.system-status');
    if (!system || document.getElementById('whatsapp-system-block')) return;

    var block = document.createElement('div');
    block.className = 'system-row whatsapp-system-row';
    block.id = 'whatsapp-system-block';

    var dot = document.createElement('span');
    dot.className = 'system-dot warn';
    dot.id = 'whatsapp-system-dot';

    var copy = document.createElement('div');
    copy.className = 'whatsapp-system-copy';

    var text = document.createElement('span');
    text.className = 'whatsapp-system-name';
    text.id = 'whatsapp-system-text';
    text.textContent = 'WhatsApp';

    var meta = document.createElement('span');
    meta.className = 'whatsapp-system-meta';
    meta.id = 'whatsapp-system-meta';
    meta.textContent = 'Status wird geprüft';

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'whatsapp-test-button';
    button.id = 'whatsapp-test-button';
    button.textContent = 'Test';
    button.title = 'Testnachricht an die konfigurierte WhatsApp-Nummer senden';
    button.onclick = sendWhatsAppUiTest;

    copy.appendChild(text);
    copy.appendChild(meta);
    block.appendChild(dot);
    block.appendChild(copy);
    block.appendChild(button);

    var rows = system.querySelectorAll('.system-row');
    var versionRow = rows.length ? rows[rows.length - 1] : null;

    if (versionRow) {
        system.insertBefore(block, versionRow);
    } else {
        system.appendChild(block);
    }

    refreshWhatsAppStatus();
    window.setInterval(refreshWhatsAppStatus, 60000);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWhatsAppSystemStatus);
} else {
    initWhatsAppSystemStatus();
}
