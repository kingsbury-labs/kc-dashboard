const test = require('node:test');
const assert = require('node:assert');
const { decide } = require('./runner-watchdog.cjs');

const now = Date.parse('2026-10-02T19:00:00Z');
const minsAgo = (m) => new Date(now - m * 60000);
const runner = (over = {}) => ({ name: 'r1', service: 's1', status: 'online', busy: false, ...over });

test('a queued job while the runner is busy is left alone', () => {
    assert.deepStrictEqual(decide([runner({ busy: true })], minsAgo(30), now), []);
});

test('a job queued past the limit while the runner says idle restarts it', () => {
    assert.strictEqual(decide([runner()], minsAgo(11), now).length, 1);
});

test('a job queued under the limit is left alone', () => {
    assert.deepStrictEqual(decide([runner()], minsAgo(5), now), []);
});

test('an offline runner is restarted with nothing queued', () => {
    assert.strictEqual(decide([runner({ status: 'offline' })], null, now).length, 1);
});

test('one busy runner of two means the queue is just waiting', () => {
    assert.deepStrictEqual(decide([runner(), runner({ name: 'r2', service: 's2', busy: true })], minsAgo(30), now), []);
});

test('a runner restarted inside the cooldown is not restarted again', () => {
    assert.deepStrictEqual(decide([runner({ status: 'offline' })], null, now, { s1: now - 5 * 60000 }), []);
});
