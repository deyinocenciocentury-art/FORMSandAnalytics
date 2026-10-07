(function () {
    'use strict';

    const labels = window.SoulmarkeAdmin || {};
    const form = document.getElementById('sm-settings-form');
    const list = document.getElementById('sm-question-list');
    const template = document.getElementById('sm-question-template');
    let dirty = false;

    function announce(message) {
        const status = form && form.querySelector('.sm-editor-status');
        if (status) status.textContent = message;
    }

    function changed() {
        dirty = true;
        announce('Unsaved changes');
    }

    function refreshQuestion(question) {
        const type = question.querySelector('[data-field="type"]').value;
        question.querySelector('.sm-options-field').hidden = !['radio', 'checkbox', 'select'].includes(type);
        question.querySelector('.sm-max-selections').hidden = type !== 'checkbox';
        question.querySelector('.sm-rating-help').hidden = type !== 'rating';
    }

    function renumber() {
        const questions = Array.from(list.querySelectorAll('[data-question]'));
        questions.forEach(function (question, index) {
            question.querySelector('.sm-question-number').textContent = 'Question ' + (index + 1);
            question.querySelectorAll('[data-field]').forEach(function (field) {
                field.name = 'settings[questions][' + index + '][' + field.dataset.field + ']';
            });
            const up = question.querySelector('.sm-move-up');
            const down = question.querySelector('.sm-move-down');
            up.disabled = index === 0;
            down.disabled = index === questions.length - 1;
            up.setAttribute('aria-label', 'Move question ' + (index + 1) + ' up');
            down.setAttribute('aria-label', 'Move question ' + (index + 1) + ' down');
            question.querySelector('.sm-remove-question').setAttribute('aria-label', 'Remove question ' + (index + 1));
            refreshQuestion(question);
        });
    }

    function questionId() {
        if (window.crypto && window.crypto.getRandomValues) {
            const bytes = new Uint32Array(2);
            window.crypto.getRandomValues(bytes);
            return 'q_' + Array.from(bytes).map(function (value) { return value.toString(16); }).join('');
        }
        return 'q_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    }

    if (form && list && template) {
        renumber();
        form.addEventListener('input', changed);
        form.addEventListener('change', function (event) {
            changed();
            if (event.target.matches('[data-field="type"]')) refreshQuestion(event.target.closest('[data-question]'));
        });
        document.getElementById('sm-add-question').addEventListener('click', function () {
            const fragment = template.content.cloneNode(true);
            const question = fragment.querySelector('[data-question]');
            question.querySelector('[data-field="id"]').value = questionId();
            list.appendChild(fragment);
            renumber();
            changed();
            question.querySelector('[data-field="title"]').focus();
        });
        list.addEventListener('click', function (event) {
            const button = event.target.closest('button');
            if (!button) return;
            const question = button.closest('[data-question]');
            if (!question) return;
            if (button.classList.contains('sm-remove-question')) {
                if (!window.confirm(labels.removeQuestion || 'Remove this question? Existing submissions will be preserved.')) return;
                const next = question.nextElementSibling || question.previousElementSibling;
                question.remove();
                renumber();
                changed();
                if (next) next.querySelector('[data-field="title"]').focus();
                else document.getElementById('sm-add-question').focus();
            } else if (button.classList.contains('sm-move-up') && question.previousElementSibling) {
                list.insertBefore(question, question.previousElementSibling);
                renumber();
                changed();
                button.focus();
            } else if (button.classList.contains('sm-move-down') && question.nextElementSibling) {
                list.insertBefore(question.nextElementSibling, question);
                renumber();
                changed();
                button.focus();
            }
        });
        form.addEventListener('submit', function () {
            renumber();
            dirty = false;
            announce('Saving changes…');
        });
        window.addEventListener('beforeunload', function (event) {
            if (!dirty) return;
            event.preventDefault();
            event.returnValue = '';
        });
    }

    document.querySelectorAll('.sm-comparison-form').forEach(function (comparisonForm) {
        const checkboxes = comparisonForm.querySelectorAll('.sm-compare-check');
        if (!checkboxes.length) return;
        const count = comparisonForm.querySelector('[data-compare-count]');
        const feedback = comparisonForm.querySelector('.sm-form-feedback');
        const submit = comparisonForm.querySelector('button[type="submit"]');
        function update() {
            const selected = comparisonForm.querySelectorAll('.sm-compare-check:checked').length;
            count.textContent = selected + ' of 3 selected';
            submit.disabled = selected === 0;
        }
        comparisonForm.addEventListener('change', function (event) {
            if (!event.target.matches('.sm-compare-check')) return;
            if (comparisonForm.querySelectorAll('.sm-compare-check:checked').length > 3) {
                event.target.checked = false;
                feedback.textContent = labels.comparisonLimit || 'Choose up to three submissions to compare.';
            } else {
                feedback.textContent = '';
            }
            update();
        });
        update();
    });

    document.querySelectorAll('.sm-delete-form').forEach(function (deleteForm) {
        deleteForm.addEventListener('submit', function (event) {
            if (!window.confirm(labels.deleteSubmission || 'Permanently delete this submission? This cannot be undone.')) event.preventDefault();
        });
    });
}());
