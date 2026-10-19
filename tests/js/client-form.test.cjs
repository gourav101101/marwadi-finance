const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function setup(fetch) {
  const element = () => ({ dataset: {}, children: [], attributes: {}, hidden: false,
    append(child) { this.children.push(child); }, replaceChildren() { this.children = []; },
    setAttribute(k, v) { this.attributes[k] = v; }, removeAttribute(k) { delete this.attributes[k]; },
    focus() { this.focused = true; }, closest() { return this; },
  });
  const summary = element(), button = { ...element(), innerHTML: 'Save client' };
  const file = { name: 'aadhaar.pdf' };
  const fields = { ifsc: { ...element(), value: 'INVALID' }, photo: { ...element(), files: [file] } };
  const form = { ...element(), action: '/admin/clients', elements: { namedItem: name => fields[name] },
    querySelector: selector => selector === '[data-client-errors]' ? summary : button,
    querySelectorAll: () => [], reportValidity: () => true,
    addEventListener(event, callback) { this.submit = callback; },
  };
  const redirects = [];
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/js/client-form.js'), 'utf8'), {
    document: { querySelector: () => form, createElement: element, addEventListener: (_, callback) => callback() },
    window: { location: { assign: url => redirects.push(url) } },
    FormData: class { constructor(value) { this.form = value; } }, fetch,
  });
  return { form, fields, file, summary, button, redirects, save: () => form.submit({ preventDefault() {} }) };
}
const response = (status, json) => ({ status, ok: status === 200, headers: { get: () => 'application/json' }, json: async () => json });

test('validation preserves selected files and text, then corrected resubmission navigates', async () => {
  let calls = 0;
  const page = setup(async (_, options) => {
    assert.equal(options.headers.Accept, 'application/json');
    assert.equal(options.body.form, page.form);
    return ++calls === 1 ? response(422, { errors: { ifsc: ['Invalid IFSC'] } }) : response(200, { redirect: '/admin/clients/1' });
  });
  await page.save();
  assert.equal(page.fields.photo.files[0], page.file);
  assert.equal(page.fields.ifsc.value, 'INVALID');
  assert.equal(page.fields.ifsc.attributes['aria-invalid'], 'true');
  assert.equal(page.summary.hidden, false);
  assert.equal(page.summary.focused, true);
  assert.equal(page.button.disabled, false);
  page.fields.ifsc.value = 'ABCD0123456';
  await page.save();
  assert.deepEqual(page.redirects, ['/admin/clients/1']);
});

test('double clicks send only one pending request and network failure keeps files', async () => {
  let reject, calls = 0;
  const page = setup(() => { calls++; return new Promise((_, fail) => { reject = fail; }); });
  const pending = page.save();
  await page.save();
  assert.equal(calls, 1);
  assert.equal(page.button.disabled, true);
  reject(new Error('Offline'));
  await pending;
  assert.equal(page.fields.photo.files[0], page.file);
  assert.equal(page.button.disabled, false);
  assert.equal(page.summary.hidden, false);
  assert.deepEqual(page.redirects, []);
});
