// Run with Node.js: node tests/post_previews_frontend.cjs
// Verify the actual display script with a small in-memory DOM and mocked requests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
  constructor(tag) {
    this.tagName = tag;
    this.children = [];
    this.attributes = {};
    this.dataset = {};
    this.hidden = false;
    this.textContent = '';
  }
  setAttribute(name, value) { this.attributes[name] = value; }
  append(...children) { this.children.push(...children); }
  replaceChildren(...children) { this.children = children; }
}

const root = path.join(__dirname, '..');
const script = fs.readFileSync(path.join(root, 'scripts/post-previews.js'), 'utf8');
const html = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
assert(!html.includes('data-sample-posts') && !html.includes('サンプル記事'));
assert.match(html, /id="recent-post-previews"[^>]*hidden><\/ul>/);

async function run(fetch) {
  const track = new Element('ul');
  // Simulate an older cached HTML document, including its sample card.
  track.append(new Element('article'));
  track.dataset.samplePosts = 'true';
  const status = new Element('p');
  const context = vm.createContext({
    document: {
      getElementById: id => id === 'recent-post-previews' ? track : status,
      createElement: tag => new Element(tag),
    },
    fetch, AbortController,
    window: { setTimeout, clearTimeout },
    console: { error() {} },
  });
  const completion = vm.runInContext(script, context);
  assert.equal(track.children.length, 0, 'Old sample disappears before the request completes');
  assert.equal(track.hidden, true);
  await completion;
  assert.equal(track.attributes['aria-busy'], 'false');
  assert.equal(track.dataset.samplePosts, undefined);
  return { track, status };
}

(async () => {
  const post = { id: 20, title: '<img src=x onerror=alert(1)>', excerpt: 'Actual post', created_at: '2026/09/09 12:00' };
  const success = await run(async () => ({ ok: true, json: async () => ({ previews: [post] }) }));
  assert.equal(success.track.hidden, false);
  assert.equal(success.status.hidden, true);
  assert.equal(success.track.children.length, 1);
  const link = success.track.children[0].children[0];
  assert.equal(link.href, './pages/post.php?id=20');
  assert.equal(link.children[1].textContent, post.title, 'User text remains text');

  const empty = await run(async () => ({ ok: true, json: async () => ({ previews: [] }) }));
  assert.equal(empty.track.children.length, 0);
  assert.equal(empty.track.hidden, true);
  assert.equal(empty.status.textContent, '投稿はまだありません。');

  for (const fetch of [
    async () => { throw new Error('Network unavailable'); },
    async () => ({ ok: false }),
    async () => ({ ok: true, json: async () => { throw new SyntaxError('Invalid JSON'); } }),
    async () => ({ ok: true, json: async () => ({ post }) }),
    async () => ({ ok: true, json: async () => ({ previews: [post, { id: -1 }] }) }),
  ]) {
    const failed = await run(fetch);
    assert.equal(failed.track.children.length, 0);
    assert.equal(failed.track.hidden, true);
    assert.equal(failed.status.hidden, false);
    assert.match(failed.status.textContent, /一覧を見る/);
  }
  console.log('PASS: actual posts, empty response, failed requests, malformed data, and cached sample removal');
})().catch(error => { console.error(error); process.exitCode = 1; });
