(function () {
    function cleanPastedHtml(html) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        wrapper.querySelectorAll('script, style, meta, link').forEach((node) => node.remove());
        wrapper.querySelectorAll('*').forEach((node) => {
            [...node.attributes].forEach((attr) => {
                const name = attr.name.toLowerCase();
                if (name.startsWith('on') || name === 'class' || name === 'id') {
                    node.removeAttribute(attr.name);
                }
            });
        });
        return wrapper.innerHTML;
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
                document.execCommand(cmd, false, url);
            } else {
                document.execCommand(cmd, false, value || null);
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
            document.execCommand('insertHTML', false, html ? cleanPastedHtml(html) : text.replace(/\n/g, '<br>'));
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
