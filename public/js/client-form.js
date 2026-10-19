document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-client-form]');
  if (!form) return;
  const summary = form.querySelector('[data-client-errors]');
  const submit = form.querySelector('button[type="submit"]');
  let saving = false;
  const showErrors = errors => {
    summary.replaceChildren();
    const heading = document.createElement('strong');
    heading.textContent = 'Please check the following. Your entries and selected documents are still here.';
    summary.append(heading);
    const list = document.createElement('ul');
    Object.entries(errors).forEach(([name, messages]) => {
      const field = form.elements.namedItem(name);
      if (field) field.setAttribute('aria-invalid', 'true');
      messages.forEach(message => {
        const item = document.createElement('li');
        item.textContent = message;
        list.append(item);
      });
      if (field) {
        const error = document.createElement('small');
        error.className = 'negative';
        error.dataset.clientFieldError = '';
        error.textContent = messages.join(' ');
        field.closest('label')?.append(error);
      }
    });
    summary.append(list);
    summary.hidden = false;
    summary.focus();
  };
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (saving || !form.reportValidity()) return;
    saving = true;
    const originalLabel = submit.innerHTML;
    submit.disabled = true;
    submit.textContent = 'Saving client…';
    form.setAttribute('aria-busy', 'true');
    summary.hidden = true;
    form.querySelectorAll('[aria-invalid]').forEach(field => field.removeAttribute('aria-invalid'));
    form.querySelectorAll('[data-client-field-error], [data-validation-error]').forEach(error => error.remove());
    try {
      const response = await fetch(form.action, {
        method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' },
      });
      const json = response.headers.get('content-type')?.includes('application/json') ? await response.json() : null;
      if (response.status === 422 && json?.errors) showErrors(json.errors);
      else if (response.ok && json?.redirect) window.location.assign(json.redirect);
      else if (response.status === 413) showErrors({ upload: ['The files exceed the server upload limit. Choose smaller files or ask your administrator to increase the hosting limits.'] });
      else if ([401, 419].includes(response.status) || response.redirected) showErrors({ session: ['Your session has expired. Sign in again in another tab, then reload this form before retrying. Reloading requires selecting the files again.'] });
      else showErrors({ save: ['The server could not confirm the save. Check the client list in another tab before retrying, or contact your administrator.'] });
    } catch {
      showErrors({ save: ['The connection was interrupted. Check the client list in another tab before retrying to avoid creating a duplicate.'] });
    } finally {
      saving = false;
      submit.disabled = false;
      submit.innerHTML = originalLabel;
      form.removeAttribute('aria-busy');
    }
  });
});
