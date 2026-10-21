// Exercise the actual inline UI functions without a session or production writes.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
// Render the production PHP CTA branch, including pending entry.
const ctaSource = fs.readFileSync(`${__dirname}/../checkin.php`, 'utf8');
const ctaStart = ctaSource.indexOf("<?php if (!empty($today_attendance['check_out_time'])");
assert.ok(ctaStart >= 0, 'CTA fixture anchor exists');
const cta = ctaSource.slice(ctaStart, ctaSource.indexOf('<?php endif; ?>', ctaStart) + '<?php endif; ?>'.length);
for (const state of [
    { in: null, out: null, expected: 'btn-checkin' },
    { in: '2026-10-22 08:30:00', out: null, expected: 'btn-checkout' },
    { in: '2026-10-22 08:30:00', out: '2026-10-22 17:30:00', expected: 'รออนุมัติคำขอนอกสถานที่' },
    { in: null, out: '2026-10-22 17:30:00', expected: 'ติดต่อ HR' },
]) {
    const data = JSON.stringify({check_in_time: state.in, check_out_time: state.out});
    const php = "$today_attendance=json_decode('" + data + "',true); $pending_in=$pending_out=$pending_outside_in=$pending_outside_out=true; eval('?>'.base64_decode('" + Buffer.from(cta).toString('base64') + "'));";
    const html = require('node:child_process').execFileSync(process.env.PHP_BIN || 'php', ['-r', php], {encoding:'utf8'});
    assert.ok(html.includes(state.expected), 'pending CTA renders ' + state.expected);
    if (state.out) assert.ok(!html.includes('id="btn-checkout"'), 'submitted checkout cannot be repeated');
}
console.log('PASS pending-entry checkout CTA, completed pending pair and rejected-entry correction');
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
