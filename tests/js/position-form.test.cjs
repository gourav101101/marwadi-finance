const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

// Run the actual page script with the form's DOM contract, without browser dependencies.
function form() {
  const control = (value, values = []) => ({
    value, options: values.map(value => ({ value, disabled: false })), listeners: {},
    get selectedOptions() { return this.options.filter(option => option.value === this.value); },
    addEventListener(event, callback) { this.listeners[event] = callback; },
    change(value) { this.value = value; this.listeners.change?.(); },
  });
  const elements = {
    category: control('Equity'),
    section: control('Current positions', ['Current positions', 'Last trades', 'Orders', 'Total value of trade']),
    state: control('Active', ['Active', 'Hold', 'Closed']),
    entry_price: control('100'), ltp: control('110'), exit_price: control('125'),
    quantity: control('10'), side: control('Buy'), pnl: control(''), expiry_date: control(''),
  };
  const button = control('');
  const expiry = {};
  const position = { elements, querySelector: () => expiry };
  const document = {
    addEventListener(event, callback) { if (event === 'DOMContentLoaded') callback(); },
    getElementById: () => null,
    querySelectorAll: () => [],
    querySelector: selector => selector === '[data-position-form]' ? position : selector === '[data-calculate-pnl]' ? button : null,
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/js/workspace.js'), 'utf8'), {
    document, window: { matchMedia: () => ({ addEventListener() {} }) },
  });
  return { ...elements, calculate: () => button.listeners.click(), expiry };
}

test('closing a current position requires exit price and calculates final buy/sell P&L', () => {
  const p = form();
  p.calculate();
  assert.equal(p.pnl.value, '100.00');
  assert.equal(p.exit_price.required, false);
  p.state.change('Closed');
  assert.equal(p.exit_price.required, true);
  p.calculate();
  assert.equal(p.pnl.value, '250.00');
  p.side.value = 'Sell';
  p.calculate();
  assert.equal(p.pnl.value, '-250.00');
  p.state.change('Hold');
  assert.equal(p.exit_price.required, false);
  p.calculate();
  assert.equal(p.pnl.value, '-100.00');
});

test('completed trades stay closed while orders cannot carry a closed status', () => {
  const p = form();
  p.section.change('Last trades');
  assert.equal(p.state.value, 'Closed');
  assert.equal(p.state.options.find(option => option.value === 'Active').disabled, true);
  p.calculate();
  assert.equal(p.pnl.value, '250.00');
  p.section.change('Orders');
  assert.equal(p.state.value, 'Active');
  assert.equal(p.state.options.find(option => option.value === 'Closed').disabled, true);
  assert.equal(p.exit_price.required, false);
});

test('Forex switches unsupported sections and derivatives expose expiry', () => {
  const p = form();
  p.category.change('Futures');
  assert.equal(p.expiry.hidden, false);
  p.expiry_date.value = '2026-10-29';
  p.section.change('Orders');
  p.category.change('Forex & Currency');
  assert.equal(p.section.value, 'Current positions');
  assert.equal(p.expiry.hidden, true);
  assert.equal(p.expiry_date.value, '');
});
