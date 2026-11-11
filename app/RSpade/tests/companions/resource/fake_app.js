/**
 * A stand-in application for rsx-companiond's process tests: the daemon's two verbs and a
 * companion's calls, answered from files the test writes, with every request appended to a
 * log. It verifies nothing; the real verifier is Companion_Realm_Test's subject.
 *
 *     node fake_app.js <directory>
 *
 * <directory>/port      written once listening, whole (renamed into place)
 * <directory>/requests  one JSON line per request: {path, body}
 * <directory>/ready     the ready verb's answer as JSON; absent = ready
 * <directory>/contract_mismatch   present = every companion call answers 409
 */

const fs = require('fs');
const http = require('http');
const path = require('path');

const directory = process.argv[2];

function answer(response, status, body) {
    response.writeHead(status, { 'Content-Type': 'application/json' });
    response.end(body === null ? '' : JSON.stringify(body));
}

const server = http.createServer((request, response) => {
    const chunks = [];
    request.on('data', (chunk) => chunks.push(chunk));
    request.on('end', () => {
        const body = Buffer.concat(chunks).toString('utf8');
        fs.appendFileSync(path.join(directory, 'requests'), JSON.stringify({ path: request.url, body }) + '\n');

        if (request.url.startsWith('/_companion/_daemon/ready/')) {
            const file = path.join(directory, 'ready');
            const ready = fs.existsSync(file)
                ? JSON.parse(fs.readFileSync(file, 'utf8'))
                : { ready: true, scope: 'companion', reason: null };
            answer(response, 200, ready);
        } else if (request.url === '/_companion/_daemon/event') {
            answer(response, 204, null);
        } else if (fs.existsSync(path.join(directory, 'contract_mismatch'))) {
            answer(response, 409, { error: { code: 'contract_mismatch', message: 'stale' } });
        } else {
            answer(response, 404, { error: { code: 'not_found', message: 'no such endpoint' } });
        }
    });
});

// Written whole and renamed into place: the test polls for the file, and a file that exists
// before its content is written reads as an empty port.
server.listen(0, '127.0.0.1', () => {
    const file = path.join(directory, 'port');
    fs.writeFileSync(file + '.partial', String(server.address().port));
    fs.renameSync(file + '.partial', file);
});
