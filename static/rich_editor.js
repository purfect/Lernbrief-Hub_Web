(function () {
    const ALLOWED_PASTE_TAGS = new Set([
        'p', 'div', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li',
        'h2', 'h3', 'h4', 'blockquote', 'a', 'span'
    ]);

    function cleanStyle(styleValue) {
        const out = [];
        const allowed = new Set(['text-align', 'font-weight', 'font-style', 'text-decoration', 'font-family', 'font-size']);
        String(styleValue || '').split(';').forEach((chunk) => {
            const idx = chunk.indexOf(':');
            if (idx < 0) {
                return;
            }
            const key = chunk.slice(0, idx).trim().toLowerCase();
            const value = chunk.slice(idx + 1).trim();
            if (!allowed.has(key) || !value) {
                return;
            }
            out.push(`${key}:${value}`);
        });
        return out.join(';');
    }

    function cleanHref(value) {
        const href = String(value || '').trim();
        if (!href) {
            return '';
        }
        if (/^(#|\/|\.\/|\.\.\/)/.test(href)) {
            return href;
        }
        if (/^(https?:|mailto:|tel:)/i.test(href)) {
            return href;
        }
        return '';
    }

    function cleanPastedHtml(html) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;

        wrapper.querySelectorAll('script, style, meta, link, iframe, object, embed, form, input, button, textarea, select').forEach((node) => node.remove());

        wrapper.querySelectorAll('*').forEach((node) => {
            const tag = node.tagName.toLowerCase();
            if (!ALLOWED_PASTE_TAGS.has(tag)) {
                const parent = node.parentNode;
                if (parent) {
                    while (node.firstChild) {
                        parent.insertBefore(node.firstChild, node);
                    }
                    parent.removeChild(node);
                }
                return;
            }

            [...node.attributes].forEach((attr) => {
                const name = attr.name.toLowerCase();
                if (name.startsWith('on') || name === 'id') {
                    node.removeAttribute(attr.name);
                    return;
                }
                if (name === 'style') {
                    const cleaned = cleanStyle(attr.value);
                    if (cleaned) {
                        node.setAttribute('style', cleaned);
                    } else {
                        node.removeAttribute(attr.name);
                    }
                    return;
                }
                if (name === 'href' && tag === 'a') {
                    const href = cleanHref(attr.value);
                    if (href) {
                        node.setAttribute('href', href);
                        node.setAttribute('rel', 'noopener noreferrer');
                    } else {
                        node.removeAttribute(attr.name);
                    }
                    return;
                }
                if (name === 'class' && (tag === 'div' || tag === 'span')) {
                    const classes = String(attr.value || '').split(/\s+/).filter(Boolean);
                    const allowedClasses = ['intro-gap', 'sentence-break', 'letter-header', 'letter-footer', 'export-meta'];
                    const filtered = classes.filter((item) => allowedClasses.includes(item));
                    if (filtered.length > 0) {
                        node.setAttribute('class', filtered.join(' '));
                    } else {
                        node.removeAttribute(attr.name);
                    }
                    return;
                }

                node.removeAttribute(attr.name);
            });
        });

        return wrapper.innerHTML;
    }

    function selectionInside(root) {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return false;
        }
        const range = selection.getRangeAt(0);
        return root.contains(range.commonAncestorContainer);
    }

    function insertHtmlAtSelection(root, html) {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0 || !selectionInside(root)) {
            return false;
        }
        const range = selection.getRangeAt(0);
        range.deleteContents();
        const fragment = range.createContextualFragment(html);
        const lastChild = fragment.lastChild;
        range.insertNode(fragment);
        if (lastChild) {
            range.setStartAfter(lastChild);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);
        }
        return true;
    }

    function applyExecCommand(cmd, value) {
        if (typeof document.execCommand !== 'function') {
            return false;
        }
        try {
            return document.execCommand(cmd, false, value || null);
        } catch (e) {
            return false;
        }
    }

    function createLinkAtSelection(root, url) {
        const safeUrl = cleanHref(url);
        if (!safeUrl) {
            return false;
        }

        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0 || !selectionInside(root)) {
            return false;
        }

        const range = selection.getRangeAt(0);
        const anchor = document.createElement('a');
        anchor.href = safeUrl;
        anchor.rel = 'noopener noreferrer';

        if (selection.isCollapsed) {
            anchor.textContent = safeUrl;
            range.insertNode(anchor);
            range.setStartAfter(anchor);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);
            return true;
        }

        const extracted = range.extractContents();
        anchor.appendChild(extracted);
        range.insertNode(anchor);
        range.setStartAfter(anchor);
        range.collapse(true);
        selection.removeAllRanges();
        selection.addRange(range);
        return true;
    }

    function bindToolbar(root, content, hidden) {
        const buttons = root.querySelectorAll('button[data-cmd]');
        const selects = root.querySelectorAll('select[data-cmd]');

        function sync() {
            hidden.value = content.innerHTML.trim();
        }

        function runCommand(cmd, value) {
            if (cmd === 'createLink') {
                const url = window.prompt('Link-Adresse');
                if (!url) {
                    return;
                }
                if (!createLinkAtSelection(content, url)) {
                    applyExecCommand(cmd, url);
                }
            } else if (cmd === 'insertHTML') {
                if (!insertHtmlAtSelection(content, String(value || ''))) {
                    applyExecCommand(cmd, value);
                }
            } else {
                applyExecCommand(cmd, value);
            }
            content.focus();
            sync();
        }

        buttons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const cmd = btn.getAttribute('data-cmd');
                if (!cmd) {
                    return;
                }
                runCommand(cmd, btn.getAttribute('data-value'));
            });
        });

        selects.forEach((select) => {
            select.addEventListener('change', () => {
                const cmd = select.getAttribute('data-cmd');
                if (!cmd) {
                    return;
                }
                runCommand(cmd, select.value);
            });
        });

        content.addEventListener('paste', (event) => {
            event.preventDefault();
            const data = event.clipboardData || window.clipboardData;
            const html = data.getData('text/html');
            const text = data.getData('text/plain');
            const toInsert = html ? cleanPastedHtml(html) : String(text || '').replace(/\n/g, '<br>');
            if (!insertHtmlAtSelection(content, toInsert)) {
                applyExecCommand('insertHTML', toInsert);
            }
            sync();
        });

        content.addEventListener('input', sync);
        content.addEventListener('blur', sync);
        sync();
    }

    function bindEditor(root) {
        const content = root.querySelector('.rich-content');
        const hidden = root.querySelector('input[type="hidden"]');

        if (!content || !hidden) {
            return;
        }

        bindToolbar(root, content, hidden);
    }

    function bindLetterEditor() {
        const wrapper = document.querySelector('[data-letter-editor]');
        if (!wrapper) {
            return;
        }
        const content = wrapper.querySelector('.rich-content');
        const hidden = wrapper.querySelector('input[name="content_html"]');
        if (!content || !hidden) {
            return;
        }

        bindToolbar(wrapper, content, hidden);
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.rich-editor').forEach(bindEditor);
        bindLetterEditor();

        document.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', () => {
                form.querySelectorAll('.rich-editor').forEach((editor) => {
                    const content = editor.querySelector('.rich-content');
                    const hidden = editor.querySelector('input[type="hidden"]');
                    if (content && hidden) {
                        hidden.value = content.innerHTML.trim();
                    }
                });

                const letterContent = form.hasAttribute('data-letter-editor')
                    ? form.querySelector('.rich-content')
                    : null;
                const letterHidden = form.querySelector('input[name="content_html"]');
                if (letterContent && letterHidden) {
                    letterHidden.value = letterContent.innerHTML.trim();
                }
            });
        });
    });
})();
