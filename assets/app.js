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

function ensureArchiveUiStyles() {
    if (document.getElementById('archive-ui-styles')) return;

    var style = document.createElement('style');
    style.id = 'archive-ui-styles';
    style.textContent =
        '.archive-backdrop{position:fixed;inset:0;z-index:9999;background:rgba(15,17,23,.48);display:flex;align-items:center;justify-content:center;padding:20px}' +
        '.archive-dialog{width:min(760px,100%);max-height:min(82dvh,760px);overflow:hidden;border-radius:18px;background:#fff;box-shadow:0 28px 80px rgba(0,0,0,.28);display:flex;flex-direction:column}' +
        '.archive-dialog-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;padding:18px 20px 14px;border-bottom:1px solid #eceef2}' +
        '.archive-dialog-title{font-size:18px;font-weight:800;color:#191d26}' +
        '.archive-dialog-sub{margin-top:4px;font-size:11px;color:#7d8492}' +
        '.archive-close{appearance:none;border:0;background:#f1f2f5;color:#555d69;width:34px;height:34px;border-radius:10px;cursor:pointer;font-size:18px}' +
        '.archive-dialog-body{overflow:auto;padding:14px 16px 18px}' +
        '.archive-info{padding:11px 12px;border-radius:11px;background:#f7f8fa;color:#656d7a;font-size:11px;line-height:1.5;margin-bottom:11px}' +
        '.archive-item{border:1px solid #e7e9ee;border-radius:13px;padding:13px 14px;margin-top:10px;background:#fff}' +
        '.archive-item-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}' +
        '.archive-item-name{font-size:13px;font-weight:800;color:#222731}' +
        '.archive-item-date{font-size:9px;color:#959ba6;white-space:nowrap}' +
        '.archive-item-subject{margin-top:6px;font-size:10px;color:#747b88;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
        '.archive-item-text{margin-top:9px;padding:10px 11px;border-radius:10px;background:#f8f9fb;color:#343a44;font-size:11px;line-height:1.45;white-space:pre-wrap;max-height:120px;overflow:auto}' +
        '.archive-item-actions{margin-top:10px;display:flex;justify-content:flex-end}' +
        '.archive-reopen{appearance:none;border:0;border-radius:9px;background:#5b5bd6;color:#fff;min-height:36px;padding:0 12px;font-size:10px;font-weight:800;cursor:pointer}' +
        '.archive-reopen:disabled{opacity:.5;cursor:wait}' +
        '.archive-empty{padding:30px 15px;text-align:center;color:#7b8290;font-size:12px}' +
        '@media(max-width:680px){.archive-backdrop{padding:8px}.archive-dialog{max-height:92dvh;border-radius:15px}.archive-dialog-head{padding:14px}.archive-dialog-body{padding:10px}}';
    document.head.appendChild(style);
}

function closeArchiveDialog() {
    var backdrop = document.getElementById('archive-backdrop');
    if (backdrop && backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
}

function reopenArchiveConversation(key, csrf, button) {
    if (!window.fetch || !window.FormData) return;

    var data = new FormData();
    data.append('conversation_key', key);
    data.append('csrf', csrf);

    var oldText = button.textContent;
    button.disabled = true;
    button.textContent = 'Wird geöffnet …';

    fetch('functions.php?archive_reopen=1&_=' + Date.now(), {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        body: data
    })
        .then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload.ok) {
                    throw new Error(payload && payload.error ? payload.error : 'HTTP ' + response.status);
                }
                return payload;
            });
        })
        .then(function (payload) {
            window.location.href = payload.url || 'index.php';
        })
        .catch(function (error) {
            button.disabled = false;
            button.textContent = oldText;
            window.alert(error.message || 'Das Gespräch konnte nicht wieder geöffnet werden.');
        });
}

function renderArchiveDialog(status, data) {
    ensureArchiveUiStyles();
    closeArchiveDialog();

    var backdrop = document.createElement('div');
    backdrop.className = 'archive-backdrop';
    backdrop.id = 'archive-backdrop';

    var dialog = document.createElement('div');
    dialog.className = 'archive-dialog';

    var head = document.createElement('div');
    head.className = 'archive-dialog-head';

    var headText = document.createElement('div');
    var title = document.createElement('div');
    title.className = 'archive-dialog-title';
    title.textContent = status === 'erledigt' ? 'Kein Interesse' : 'Beantwortete Gespräche';

    var sub = document.createElement('div');
    sub.className = 'archive-dialog-sub';
    sub.textContent = String(data.inbox_count || 0) + ' von ' + String(data.total_count || 0) + ' liegen noch im Gmail-Posteingang';

    headText.appendChild(title);
    headText.appendChild(sub);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'archive-close';
    close.textContent = '×';
    close.onclick = closeArchiveDialog;

    head.appendChild(headText);
    head.appendChild(close);

    var body = document.createElement('div');
    body.className = 'archive-dialog-body';

    var info = document.createElement('div');
    info.className = 'archive-info';
    info.textContent = 'Gespräche können hier wieder geöffnet werden, wenn die zugehörige mobile.de-Mail noch im Gmail-Posteingang liegt. Bereits archivierte oder gelöschte Mails bitte in Gmail zuerst zurück in den Posteingang verschieben.';
    body.appendChild(info);

    var items = data.items || [];

    if (!items.length) {
        var empty = document.createElement('div');
        empty.className = 'archive-empty';
        empty.textContent = data.total_count > 0
            ? 'Keines der ausgeblendeten Gespräche liegt aktuell im Gmail-Posteingang.'
            : 'Hier gibt es aktuell keine Gespräche.';
        body.appendChild(empty);
    }

    for (var i = 0; i < items.length; i++) {
        (function (item) {
            var card = document.createElement('div');
            card.className = 'archive-item';

            var itemHead = document.createElement('div');
            itemHead.className = 'archive-item-head';

            var name = document.createElement('div');
            name.className = 'archive-item-name';
            name.textContent = item.name || 'Interessent';

            var date = document.createElement('div');
            date.className = 'archive-item-date';
            date.textContent = item.date || '';

            itemHead.appendChild(name);
            itemHead.appendChild(date);
            card.appendChild(itemHead);

            if (item.subject) {
                var subject = document.createElement('div');
                subject.className = 'archive-item-subject';
                subject.textContent = item.subject;
                card.appendChild(subject);
            }

            var text = document.createElement('div');
            text.className = 'archive-item-text';
            text.textContent = item.text || '(kein Nachrichtentext)';
            card.appendChild(text);

            var actions = document.createElement('div');
            actions.className = 'archive-item-actions';

            var reopen = document.createElement('button');
            reopen.type = 'button';
            reopen.className = 'archive-reopen';
            reopen.textContent = 'Wieder öffnen und beantworten';
            reopen.onclick = function () {
                reopenArchiveConversation(item.key, data.csrf || '', reopen);
            };

            actions.appendChild(reopen);
            card.appendChild(actions);
            body.appendChild(card);
        })(items[i]);
    }

    dialog.appendChild(head);
    dialog.appendChild(body);
    backdrop.appendChild(dialog);

    backdrop.onclick = function (event) {
        if (event.target === backdrop) closeArchiveDialog();
    };

    document.body.appendChild(backdrop);
}

function openArchiveDialog(status) {
    if (!window.fetch) return;

    ensureArchiveUiStyles();
    closeArchiveDialog();

    var loading = document.createElement('div');
    loading.className = 'archive-backdrop';
    loading.id = 'archive-backdrop';

    var loadingDialog = document.createElement('div');
    loadingDialog.className = 'archive-dialog';

    var loadingBody = document.createElement('div');
    loadingBody.className = 'archive-empty';
    loadingBody.textContent = 'Archiv wird geladen …';
    loadingDialog.appendChild(loadingBody);
    loading.appendChild(loadingDialog);
    document.body.appendChild(loading);

    fetch('functions.php?archive_list=' + encodeURIComponent(status) + '&_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store'
    })
        .then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload.ok) {
                    throw new Error(payload && payload.error ? payload.error : 'HTTP ' + response.status);
                }
                return payload;
            });
        })
        .then(function (payload) {
            renderArchiveDialog(status, payload);
        })
        .catch(function (error) {
            closeArchiveDialog();
            window.alert('Archiv konnte nicht geladen werden:\n\n' + (error.message || error));
        });
}

function initArchiveNavigation() {
    var items = document.querySelectorAll('.side-item');

    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        var label = item.querySelector('span');
        if (!label) continue;

        var text = String(label.textContent || '').replace(/^\s+|\s+$/g, '');
        var status = '';

        if (text === 'Beantwortet') {
            status = 'beantwortet';
        } else if (text === 'Kein Interesse') {
            status = 'erledigt';
        }

        if (!status) continue;

        item.style.cursor = 'pointer';
        item.setAttribute('role', 'button');
        item.setAttribute('tabindex', '0');
        item.title = text + ' anzeigen';

        item.onclick = (function (archiveStatus) {
            return function (event) {
                if (event && event.preventDefault) event.preventDefault();
                openArchiveDialog(archiveStatus);
            };
        })(status);

        item.onkeydown = (function (archiveStatus) {
            return function (event) {
                event = event || window.event;
                var key = event.key || event.keyCode;
                if (key === 'Enter' || key === ' ' || key === 13 || key === 32) {
                    if (event.preventDefault) event.preventDefault();
                    openArchiveDialog(archiveStatus);
                }
            };
        })(status);
    }
}

function getPaymentNoticeMarker() {
    return '[[MOBILE_PAYMENT_NOTICE]]';
}

function getPaymentNoticeText() {
    return 'Für den Fall, dass Sie sich bei der Besichtigung direkt für das Fahrzeug entscheiden, noch ein Hinweis, den wir grundsätzlich jedem Interessenten vorab mitgeben: Wir übergeben das Fahrzeug ausschließlich nach bestätigtem Geldeingang per Echtzeit- bzw. Sofortüberweisung auf unserem Konto. Bitte prüfen Sie deshalb vorab Ihr Überweisungslimit und passen Sie es bei Bedarf an. Bargeld und Teilzahlungen akzeptieren wir nicht. Diesen Tipp haben wir von der Plattform übernommen; der Hinweis ist nicht persönlich gemeint.';
}

function ensurePaymentNoticeUiStyles() {
    if (document.getElementById('payment-notice-ui-styles')) return;

    var style = document.createElement('style');
    style.id = 'payment-notice-ui-styles';
    style.textContent =
        '.payment-notice-option{margin-top:8px;padding:9px 10px;border:1px solid #e8e8f1;border-radius:10px;background:#fff}' +
        '.payment-notice-label{display:flex;align-items:flex-start;gap:9px;cursor:pointer;color:#4a5060}' +
        '.payment-notice-label input{margin:2px 0 0;flex:0 0 auto;width:16px;height:16px;accent-color:#5b5bd6}' +
        '.payment-notice-copy{display:flex;min-width:0;flex-direction:column;gap:2px}' +
        '.payment-notice-title{font-size:10px;font-weight:800;color:#414179}' +
        '.payment-notice-sub{font-size:9px;line-height:1.35;color:#8b919d}' +
        '.payment-notice-option.is-active{border-color:#ccccfa;background:#f9f9ff}';
    document.head.appendChild(style);
}

function stripPaymentNoticeMarker(value) {
    var marker = getPaymentNoticeMarker();
    return String(value || '').split(marker).join('').replace(/^\s+|\s+$/g, '');
}

function insertPaymentNoticeIntoDraft(field) {
    if (!field) return;

    var notice = getPaymentNoticeText();
    var text = String(field.value || '');
    if (text.indexOf(notice) !== -1) return;

    var signature = '\n\nViele Grüße\n';
    var pos = text.lastIndexOf(signature);

    if (pos !== -1) {
        field.value = text.substring(0, pos).replace(/\s+$/g, '') + '\n\n' + notice + text.substring(pos);
    } else {
        field.value = text.replace(/\s+$/g, '') + '\n\n' + notice;
    }
}

function removePaymentNoticeFromDraft(field) {
    if (!field) return;

    var notice = getPaymentNoticeText();
    var text = String(field.value || '');
    text = text.split('\n\n' + notice).join('');
    text = text.split(notice + '\n\n').join('');
    text = text.split(notice).join('');
    field.value = text.replace(/\n{3,}/g, '\n\n').replace(/^\s+|\s+$/g, '');
}

function preparePaymentNoticeBeforeGenerate(form) {
    if (!form) return;

    var hint = form.querySelector('textarea[name="reply_hint"]');
    var checkbox = form.querySelector('.payment-notice-checkbox');
    if (!hint || !checkbox) return;

    var clean = stripPaymentNoticeMarker(hint.value);
    hint.value = clean;

    if (checkbox.checked) {
        hint.value = (clean ? clean + '\n' : '') + getPaymentNoticeMarker();
    }
}

function initPaymentNoticeFlags() {
    ensurePaymentNoticeUiStyles();

    var forms = document.querySelectorAll('.compose-row form');

    for (var i = 0; i < forms.length; i++) {
        (function (form) {
            if (form.getAttribute('data-payment-notice-ready') === '1') return;

            var hint = form.querySelector('textarea[name="reply_hint"]');
            var hintCard = form.querySelector('.hint-card');
            if (!hint || !hintCard) return;

            form.setAttribute('data-payment-notice-ready', '1');

            var marker = getPaymentNoticeMarker();
            var wasChecked = String(hint.value || '').indexOf(marker) !== -1;
            hint.value = stripPaymentNoticeMarker(hint.value);

            var option = document.createElement('div');
            option.className = 'payment-notice-option' + (wasChecked ? ' is-active' : '');
            option.title = getPaymentNoticeText();

            var label = document.createElement('label');
            label.className = 'payment-notice-label';

            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'payment-notice-checkbox';
            checkbox.checked = wasChecked;

            var copy = document.createElement('span');
            copy.className = 'payment-notice-copy';

            var title = document.createElement('span');
            title.className = 'payment-notice-title';
            title.textContent = 'Zahlungshinweis anhängen';

            var sub = document.createElement('span');
            sub.className = 'payment-notice-sub';
            sub.textContent = 'Echtzeitüberweisung · Banklimit vorab prüfen · kein Bargeld / keine Teilzahlung';

            copy.appendChild(title);
            copy.appendChild(sub);
            label.appendChild(checkbox);
            label.appendChild(copy);
            option.appendChild(label);
            hintCard.appendChild(option);

            var card = form.closest ? form.closest('.thread-card') : null;
            var draft = card ? card.querySelector('textarea[name="draft"]') : null;

            if (wasChecked && draft) {
                insertPaymentNoticeIntoDraft(draft);
            }

            checkbox.onchange = function () {
                option.className = 'payment-notice-option' + (checkbox.checked ? ' is-active' : '');
                if (draft) {
                    if (checkbox.checked) {
                        insertPaymentNoticeIntoDraft(draft);
                    } else {
                        removePaymentNoticeFromDraft(draft);
                    }
                }
            };

            form.addEventListener('submit', function () {
                preparePaymentNoticeBeforeGenerate(form);
            });
        })(forms[i]);
    }

    /*
     * Der vorhandene Button nutzt form.submit(), wodurch das normale submit-
     * Event umgangen wird. Im Capture-Handler wird der Marker deshalb bereits
     * vor dem Inline-Handler in das Feld geschrieben.
     */
    document.addEventListener('click', function (event) {
        var target = event.target || event.srcElement;
        if (!target) return;

        var button = target.closest ? target.closest('.quick-actions .btn') : null;
        if (!button) return;

        var row = button.closest ? button.closest('.compose-row') : null;
        var form = row ? row.querySelector('form') : null;
        if (form) preparePaymentNoticeBeforeGenerate(form);
    }, true);
}

function initMobileSalesCenter() {
    initWhatsAppSystemStatus();
    initDirectGmailSendButtons();
    initArchiveNavigation();
    initPaymentNoticeFlags();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMobileSalesCenter);
} else {
    initMobileSalesCenter();
}
