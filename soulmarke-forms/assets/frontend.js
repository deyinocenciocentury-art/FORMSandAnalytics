(function () {
  'use strict';

  function initialize(wrapper) {
    if (wrapper.dataset.smfReady === 'true') return;
    var configuration = wrapper.querySelector('.smf-config');
    var form = wrapper.querySelector('.smf-question-form');
    if (!configuration || !form) return;
    var config;
    try { config = JSON.parse(configuration.textContent); } catch (error) { return; }
    wrapper.dataset.smfReady = 'true';

    var questions = Array.from(form.querySelectorAll('.smf-question'));
    var back = form.querySelector('.smf-button-back');
    var next = form.querySelector('.smf-button-next');
    var submit = form.querySelector('.smf-button-submit');
    var progressArea = wrapper.querySelector('.smf-progress-area');
    var progress = wrapper.querySelector('.smf-progress');
    var fill = wrapper.querySelector('.smf-progress-fill');
    var progressLabel = wrapper.querySelector('.smf-progress-label');
    var percent = wrapper.querySelector('.smf-progress-percent');
    var status = wrapper.querySelector('.smf-status');
    var success = wrapper.querySelector('.smf-success');
    var position = 0;
    var sending = false;
    var started = Date.now();

    function controls(question) {
      return Array.from(question.querySelectorAll('.smf-answer-control'));
    }

    function otherSelected(question) {
      if (!question.dataset.otherOption) return false;
      var value = answer(question);
      return Array.isArray(value) ? value.includes(question.dataset.otherOption) : value === question.dataset.otherOption;
    }

    function updateOther(question) {
      var detail = question.querySelector('.smf-other');
      if (!detail) return;
      var selected = otherSelected(question);
      var input = detail.querySelector('input');
      detail.hidden = !selected;
      input.disabled = !selected;
      input.required = selected;
      if (!selected) input.value = '';
    }

    function answer(question) {
      var fields = controls(question);
      if (question.dataset.type === 'checkbox') {
        return fields.filter(function (field) { return field.checked; }).map(function (field) { return field.value; });
      }
      if (question.dataset.type === 'radio' || question.dataset.type === 'rating') {
        var selected = fields.find(function (field) { return field.checked; });
        return selected ? selected.value : '';
      }
      return fields[0] ? fields[0].value.trim() : '';
    }

    function clearError(question) {
      var error = question.querySelector('.smf-field-error');
      error.hidden = true;
      error.textContent = '';
      question.classList.remove('smf-invalid');
      controls(question).forEach(function (field) { field.removeAttribute('aria-invalid'); });
      var otherInput = question.querySelector('.smf-other-input');
      if (otherInput) otherInput.removeAttribute('aria-invalid');
    }

    function displayError(question, message) {
      var error = question.querySelector('.smf-field-error');
      error.textContent = message;
      error.hidden = false;
      question.classList.add('smf-invalid');
      controls(question).forEach(function (field) { field.setAttribute('aria-invalid', 'true'); });
      var first = controls(question)[0];
      if (first) first.focus();
      status.textContent = message;
    }

    function validate(question) {
      clearError(question);
      var value = answer(question);
      if (question.dataset.required === 'true' && (!value || (Array.isArray(value) && value.length === 0))) {
        displayError(question, config.messages.required);
        return false;
      }
      var maximum = Number(question.dataset.maxSelections) || 0;
      if (Array.isArray(value) && maximum && value.length > maximum) {
        displayError(question, config.messages.maximum.replace('%s', String(maximum)));
        return false;
      }
      var invalid = controls(question).find(function (field) { return !field.checkValidity(); });
      if (invalid) {
        displayError(question, invalid.validationMessage || config.messages.required);
        invalid.focus();
        return false;
      }
      var otherInput = question.querySelector('.smf-other-input');
      if (otherSelected(question) && (!otherInput.value.trim() || !otherInput.checkValidity())) {
        displayError(question, config.messages.other);
        otherInput.setAttribute('aria-invalid', 'true');
        otherInput.focus();
        return false;
      }
      return true;
    }

    function questionMessage() {
      return config.messages.question.replace('%1$s', String(position + 1)).replace('%2$s', String(questions.length));
    }

    function showQuestion(index, focus) {
      position = index;
      questions.forEach(function (question, questionIndex) { question.hidden = questionIndex !== position; });
      back.hidden = position === 0;
      next.hidden = position === questions.length - 1;
      submit.hidden = position !== questions.length - 1;
      var value = Math.round(((position + 1) / questions.length) * 100);
      fill.style.width = value + '%';
      percent.textContent = value + '%';
      progressLabel.textContent = questionMessage();
      progress.setAttribute('aria-valuenow', String(position + 1));
      progress.setAttribute('aria-valuetext', questionMessage());
      status.textContent = '';
      if (focus) {
        questions[position].querySelector('.smf-question-title').focus();
      }
    }

    function advance() {
      if (!sending && validate(questions[position]) && position < questions.length - 1) {
        showQuestion(position + 1, true);
      }
    }

    function setBusy(busy) {
      sending = busy;
      form.setAttribute('aria-busy', busy ? 'true' : 'false');
      back.disabled = busy;
      next.disabled = busy;
      submit.disabled = busy;
      questions.forEach(function (question) { question.disabled = busy; });
      form.classList.toggle('smf-sending', busy);
    }

    back.addEventListener('click', function () {
      if (!sending && position > 0) showQuestion(position - 1, true);
    });
    next.addEventListener('click', advance);
    form.addEventListener('input', function (event) {
      var question = event.target.closest('.smf-question');
      if (question && event.target.type !== 'checkbox' && event.target.type !== 'radio') {
        clearError(question);
        updateOther(question);
      }
    });
    form.addEventListener('change', function (event) {
      var question = event.target.closest('.smf-question');
      if (question) {
        clearError(question);
        var maximum = Number(question.dataset.maxSelections) || 0;
        if (question.dataset.type === 'checkbox' && maximum && answer(question).length > maximum) {
          event.target.checked = false;
          displayError(question, config.messages.maximum.replace('%s', String(maximum)));
          event.target.focus();
        }
        updateOther(question);
      }
    });
    form.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter' || event.isComposing || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
      if (event.target.tagName === 'TEXTAREA' || event.target.tagName === 'SELECT' || event.target.tagName === 'BUTTON') return;
      event.preventDefault();
      if (position < questions.length - 1) advance();
      else if (!sending) form.requestSubmit();
    });

    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      if (sending) return;
      if (position < questions.length - 1) { advance(); return; }
      for (var index = 0; index < questions.length; index += 1) {
        if (!validate(questions[index])) {
          showQuestion(index, false);
          displayError(questions[index], questions[index].querySelector('.smf-field-error').textContent);
          return;
        }
      }
      var answers = {};
      var otherAnswers = {};
      questions.forEach(function (question) { answers[question.dataset.questionId] = answer(question); });
      questions.forEach(function (question) {
        if (otherSelected(question)) otherAnswers[question.dataset.questionId] = question.querySelector('.smf-other-input').value.trim();
      });
      var payload = new URLSearchParams();
      payload.set('action', 'soulmarke_submit');
      payload.set('nonce', config.nonce);
      payload.set('answers', JSON.stringify(answers));
      payload.set('other_answers', JSON.stringify(otherAnswers));
      payload.set('website', form.querySelector('[name="website"]').value);
      payload.set('elapsed', String(Math.floor((Date.now() - started) / 1000)));
      setBusy(true);
      status.textContent = config.messages.sending;
      try {
        var response = await fetch(config.endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: payload.toString()
        });
        var result = await response.json();
        if (!response.ok || !result.success) {
          setBusy(false);
          var detail = result && result.data ? result.data : {};
          var message = typeof detail.message === 'string' ? detail.message : config.messages.error;
          if (detail.errors && typeof detail.errors === 'object') {
            var failedIndex = questions.findIndex(function (question) {
              return Object.prototype.hasOwnProperty.call(detail.errors, question.dataset.questionId);
            });
            if (failedIndex !== -1) {
              showQuestion(failedIndex, false);
              var fieldMessage = detail.errors[questions[failedIndex].dataset.questionId];
              displayError(questions[failedIndex], typeof fieldMessage === 'string' ? fieldMessage : message);
            }
          }
          status.textContent = message;
          status.classList.add('smf-status-error');
          return;
        }
        form.hidden = true;
        progressArea.hidden = true;
        success.hidden = false;
        wrapper.querySelector('.smf-success-message').textContent = (result.data && typeof result.data.message === 'string') ? result.data.message : config.messages.success;
        status.textContent = '';
        success.querySelector('h3').focus();
      } catch (error) {
        status.textContent = config.messages.error;
        status.classList.add('smf-status-error');
      } finally {
        setBusy(false);
      }
    });

    form.addEventListener('submit', function () { status.classList.remove('smf-status-error'); });
    questions.forEach(updateOther);
    showQuestion(0, false);
    wrapper.classList.add('smf-enhanced');
  }

  function start() { document.querySelectorAll('.smf-form').forEach(initialize); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
