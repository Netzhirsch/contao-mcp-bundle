/*
 * The "Claude" button of netzhirsch/contao-mcp-bundle — backend header and
 * front-end preview.
 *
 * Everything the page knows arrives in the button's data-mcp-claude
 * attribute (see src/Backend/ClaudeButton.php): an intro naming the
 * installation, the context lines, and the labels in the user's language.
 * This only asks what to do, assembles the request and opens claude.ai with
 * it. Text goes into the dialog through textContent, never as markup.
 *
 * Listens on the document instead of on the button: the backend replaces the
 * page body on navigation (Turbo), and the script must not care.
 */
(function () {
    'use strict';

    if (window.netzhirschMcpClaude) {
        return;
    }
    window.netzhirschMcpClaude = true;

    // claude.ai fills its input from ?q=. Anthropic does not document the
    // parameter, so the request goes to the clipboard as well.
    var CLAUDE_NEW = 'https://claude.ai/new?q=';

    function readConfig(trigger) {
        try {
            return JSON.parse(trigger.getAttribute('data-mcp-claude') || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function fallbackCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '0';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try {
            document.execCommand('copy');
        } catch (e) {
            // Nothing else to try; the request is still in the URL.
        }
        document.body.removeChild(area);
    }

    function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).catch(function () {
                fallbackCopy(text);
            });
        } else {
            fallbackCopy(text);
        }
    }

    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    function openDialog(trigger) {
        var config = readConfig(trigger);
        var labels = config.labels || {};
        var context = Array.isArray(config.context) ? config.context : [];

        var dialog = element('dialog', 'netzhirsch-mcp-claude-dialog');
        var form = element('form');
        form.method = 'dialog';

        var label = element('label', 'netzhirsch-mcp-claude-question', labels.question || 'What should Claude do?');
        label.htmlFor = 'netzhirsch-mcp-claude-request';

        var request = element('textarea');
        request.id = 'netzhirsch-mcp-claude-request';
        request.rows = 4;
        request.required = true;

        var contextBox = element('div', 'netzhirsch-mcp-claude-context');
        if (context.length) {
            contextBox.appendChild(element('strong', '', labels.context || ''));
            contextBox.appendChild(element('span', '', context.join('\n')));
        }

        var hint = element('p', 'netzhirsch-mcp-claude-hint', labels.hint || '');

        var buttons = element('div', 'netzhirsch-mcp-claude-buttons');
        var cancel = element('button', 'netzhirsch-mcp-claude-cancel', labels.cancel || 'Cancel');
        cancel.type = 'button';
        var send = element('button', 'netzhirsch-mcp-claude-send', labels.send || 'Open in Claude');
        send.type = 'submit';
        buttons.appendChild(cancel);
        buttons.appendChild(send);

        form.appendChild(label);
        form.appendChild(request);
        form.appendChild(contextBox);
        form.appendChild(hint);
        form.appendChild(buttons);
        dialog.appendChild(form);

        cancel.addEventListener('click', function () {
            dialog.close();
        });

        request.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                form.requestSubmit ? form.requestSubmit() : send.click();
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var task = request.value.trim();
            if (!task) {
                request.focus();
                return;
            }

            var prompt = [config.intro || ''].concat(context).filter(Boolean).join('\n') + '\n\n' + task;

            copy(prompt);
            // Synchronous within the user's click or keypress, so no popup blocker objects.
            window.open(CLAUDE_NEW + encodeURIComponent(prompt), '_blank', 'noopener');
            dialog.close();
        });

        dialog.addEventListener('close', function () {
            dialog.remove();
            if (typeof trigger.focus === 'function') {
                trigger.focus();
            }
        });

        document.body.appendChild(dialog);
        dialog.showModal();
        request.focus();
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target && event.target.closest ? event.target.closest('[data-mcp-claude]') : null;
        if (!trigger) {
            return;
        }
        event.preventDefault();
        openDialog(trigger);
    });
})();
