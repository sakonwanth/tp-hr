// Exercise the actual inline UI functions without a session or production writes.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../checkin.php`, 'utf8');
const lifecycle = source.slice(source.indexOf('// Close modal\n'), source.indexOf('// Get location\n'));
const flow = source.slice(source.indexOf('// Confirm check-in'), source.indexOf('/* =====================================================', source.indexOf('// Confirm check-in')));

async function scenario(type) {
    const elements = new Map();
    const element = id => {
        if (!elements.has(id)) elements.set(id, {
            value: '', disabled: false, innerHTML: '', focus() {},
            classList: { add() {}, remove() {} },
        });
        return elements.get(id);
    };
    const bodies = [];
    let reply = { success: false, data: { requires_outside_reason: true }, error: 'reason required' };
    const context = vm.createContext({
        document: { getElementById: element }, console,
        setTimeout() {}, showToast() {},
        fetch: async (_url, request) => {
            bodies.push(JSON.parse(request.body));
            return { json: async () => reply };
        },
    });
    vm.runInContext(`let checkinSubmitting = false; let photoData = 'data:image/jpeg;base64,fixture'; let stream = null;
        let checkinType = '${type}'; let userLatitude = 13.75; let userLongitude = 100.5;
        const CSRF_TOKEN = 'fixture';\n${lifecycle}\n${flow}`, context);
    await vm.runInContext('confirmCheckin()', context);
    assert.equal(vm.runInContext('photoData', context), bodies[0].photo, 'reason challenge must retain selfie');
    assert.equal(element('btn-confirm').disabled, false, 'confirmation must recover after challenge');

    element('offsite-reason').value = 'ประชุมนอกสำนักงาน';
    reply = { success: false, error: 'temporary failure' };
    await vm.runInContext('submitOffsite()', context);
    assert.equal(bodies[1].photo, bodies[0].photo, 'retry sends original selfie');
    assert.equal(bodies[1].outside_reason, element('offsite-reason').value);
    assert.equal(bodies[1].action, type === 'in' ? 'check_in' : 'check_out');
    assert.equal(element('btn-confirm').disabled, false, 'failure permits retry');

    reply = { success: true, data: { pending_approval: true } };
    await Promise.all([vm.runInContext('submitOffsite()', context), vm.runInContext('submitOffsite()', context)]);
    assert.equal(bodies.length, 3, 'double tap produces one request');
    assert.equal(bodies[2].photo, bodies[0].photo);
    assert.equal(vm.runInContext('photoData', context), null, 'success clears capture');
    vm.runInContext("photoData = 'cancelled-photo'; closeCheckinModal()", context);
    assert.equal(vm.runInContext('photoData', context), null, 'ordinary close clears capture');
    console.log(`PASS HR offsite ${type}: retained selfie, retry, double tap and cleanup`);
}
(async () => { await scenario('in'); await scenario('out'); })().catch(error => { console.error(error); process.exitCode = 1; });
