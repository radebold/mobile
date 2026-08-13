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

function ensureWhatsAppUiStyles() {
    if (document.getElementById('whatsapp-ui-styles')) return;

    var style = document.createElement('style');
    style.id = 'whatsapp-ui-styles';
    style.textContent =
        '.whatsapp-system-row{gap:7px;min-width:0}' +
        '.whatsapp-system-copy{min-width:0;display:flex;flex:1 1 auto;flex-direction:column;gap:1px}' +
        '.whatsapp-system-name{color:#aeb4bf;font-size:10px;line-height:1.15}' +
        '.whatsapp-system-meta{max-width:128px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#6f7786;font-size:8px;line-height:1.2}' +
        '.whatsapp-test-button{appearance:none;border:1px solid rgba(255,255,255,.10);border-radius:7px;background:rgba(255,255,255,.06);color:#c4c9d2;padding:4px 7px;font-size:8px;font-weight:800;cursor:pointer;transition:.14s ease}' +
        '.whatsapp-test-button:hover:not(:disabled){background:rgba(255,255,255,.11);color:#fff}' +
        '.whatsapp-test-button:disabled{opacity:.55;cursor:wait}';
    document.head.appendChild(style);
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
    el.block.title = '';

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

    var oldText = 'Test';
    el.button.disabled = true;
    el.button.textContent = '…';
    el.button.title = 'Testnachricht wird an ioBroker übergeben';

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
            el.button.title = 'Test wurde erfolgreich an ioBroker übergeben';
            window.setTimeout(function () {
                el.button.textContent = oldText;
                el.button.disabled = false;
                el.button.title = 'Testnachricht an die konfigurierte WhatsApp-Nummer senden';
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

    ensureWhatsAppUiStyles();

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

function sendGmailReplyDirect(button) {
    if (!button || !button.form || !window.fetch || !window.FormData) return;

    var form = button.form;
    var draft = form.querySelector('textarea[name="draft"]');
    var key = form.querySelector('input[name="conversation_key"]');
    var csrf = form.querySelector('input[name="csrf"]');

    if (!draft || !key || !csrf) {
        alert('Die Antwortdaten konnten nicht gelesen werden. Bitte Seite neu laden.');
        return;
    }

    var text = String(draft.value || '').trim();
    if (!text) {
        alert('Der Antworttext ist leer.');
        return;
    }

    var ok = window.confirm(
        'Antwort jetzt direkt senden?\n\n' +
        'Die Nachricht wird sofort über Gmail an den Interessenten verschickt.\n' +
        'Danach wird das Gespräch als beantwortet markiert und archiviert.'
    );

    if (!ok) return;

    var data = new FormData();
    data.append('csrf', csrf.value);
    data.append('conversation_key', key.value);
    data.append('draft', text);

    var oldText = button.textContent;
    button.disabled = true;
    button.textContent = 'Wird gesendet …';

    fetch('lib/gmail.php?send_reply=1&_=' + Date.now(), {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        body: data
    })
        .then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload.ok || !payload.sent) {
                    throw new Error(payload && payload.error ? payload.error : 'HTTP ' + response.status);
                }
                return payload;
            });
        })
        .then(function (payload) {
            button.textContent = 'Gesendet ✓';

            if (payload.warning) {
                window.alert(payload.warning);
            }

            window.setTimeout(function () {
                window.location.href = 'index.php';
            }, 500);
        })
        .catch(function (error) {
            button.disabled = false;
            button.textContent = oldText;
            window.alert('Antwort konnte nicht gesendet werden:\n\n' + (error.message || error));
        });
}

function initDirectGmailSendButtons() {
    var buttons = document.querySelectorAll('button[name="action"][value="create_gmail_draft"]');

    for (var i = 0; i < buttons.length; i++) {
        var button = buttons[i];
        button.type = 'button';
        button.removeAttribute('name');
        button.removeAttribute('value');
        button.removeAttribute('onclick');
        button.textContent = 'Direkt senden';
        button.title = 'Geprüfte Antwort jetzt direkt über Gmail senden';
        button.onclick = (function (currentButton) {
            return function () {
                sendGmailReplyDirect(currentButton);
            };
        })(button);

        var card = button.closest ? button.closest('.draft-card') : null;
        if (card) {
            var state = card.querySelector('.draft-state');
            if (state) state.textContent = 'prüfen · bearbeiten · direkt senden';
        }
    }
}

function initArchiveNavigation() {
    var items = document.querySelectorAll('.side-item');

    for (var i = 0; i < items.length; i++) {
        var item = items[i];

        if (item.tagName && String(item.tagName).toLowerCase() === 'a') {
            continue;
        }

        var label = item.querySelector('span');
        if (!label) continue;

        var text = String(label.textContent || '').replace(/^\s+|\s+$/g, '');
        var target = '';

        if (text === 'Beantwortet') {
            target = 'archive.php?view=beantwortet';
        } else if (text === 'Kein Interesse') {
            target = 'archive.php?view=erledigt';
        }

        if (!target) continue;

        item.style.cursor = 'pointer';
        item.setAttribute('role', 'link');
        item.setAttribute('tabindex', '0');
        item.title = text + ' anzeigen';

        item.onclick = (function (url) {
            return function () {
                window.location.href = url;
            };
        })(target);

        item.onkeydown = (function (url) {
            return function (event) {
                event = event || window.event;
                var key = event.key || event.keyCode;
                if (key === 'Enter' || key === ' ' || key === 13 || key === 32) {
                    if (event.preventDefault) event.preventDefault();
                    window.location.href = url;
                }
            };
        })(target);
    }
}

function initMobileSalesCenter() {
    initWhatsAppSystemStatus();
    initDirectGmailSendButtons();
    initArchiveNavigation();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMobileSalesCenter);
} else {
    initMobileSalesCenter();
}
