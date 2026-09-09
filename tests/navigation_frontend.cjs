// Run with Node.js: node tests/navigation_frontend.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function eventTarget() {
  const listeners = new Map();
  return {
    addEventListener(type, listener) {
      const group = listeners.get(type) || [];
      group.push(listener);
      listeners.set(type, group);
    },
    dispatch(type, event = {}) {
      for (const listener of listeners.get(type) || []) listener(event);
    },
  };
}

const documentEvents = eventTarget();
const windowEvents = eventTarget();
const mediaEvents = eventTarget();
const menuEvents = eventTarget();
const outside = {};
const link = { focused: false, focus() { this.focused = true; } };
const toggle = { focused: false, focus() { this.focused = true; } };
const mobile = { matches: true, ...mediaEvents };
const menu = {
  open: true,
  ...menuEvents,
  querySelector(selector) { return selector === 'summary' ? toggle : link; },
  contains(target) { return target === this || target === toggle || target === link; },
};
const document = {
  ...documentEvents,
  activeElement: outside,
  querySelectorAll() { return [menu]; },
};
const window = {
  ...windowEvents,
  matchMedia() { return mobile; },
};

const source = fs.readFileSync(path.join(__dirname, '..', 'scripts', 'navigation.js'), 'utf8');
vm.runInNewContext(source, { document, window });

assert.equal(menu.open, true, 'The HTML mobile default remains open');
document.dispatch('pointerdown', { target: link });
assert.equal(menu.open, true, 'Tapping inside the menu keeps it open');
document.dispatch('pointerdown', { target: outside });
assert.equal(menu.open, false, 'Tapping outside closes the menu');

menu.open = true;
window.dispatch('scroll');
assert.equal(menu.open, false, 'Scrolling closes the menu');

menu.open = true;
document.dispatch('keydown', { key: 'Escape' });
assert.equal(menu.open, false, 'Escape closes the menu');
assert.equal(toggle.focused, true, 'Escape returns focus to the menu button');

menu.open = false;
mobile.matches = false;
mobile.dispatch('change');
assert.equal(menu.open, true, 'Desktop navigation stays open');

console.log('PASS: mobile default, outside tap, scroll, Escape, and desktop navigation');
