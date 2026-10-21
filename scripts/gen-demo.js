// DEMO ONLY. Writes the example data set (the same one "Load example data" uses) as JSON.
//   TZ=Africa/Johannesburg node scripts/gen-demo.js > /tmp/demo.json
'use strict';
const fs = require('fs');
const path = require('path');

const d = new Date();
const pad = n => String(n).padStart(2, '0');
global.window = { APP: { today: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` } };
const src = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'shared.js'), 'utf8');
// shared.js declares its constants at top level; evaluate it and hand back the generator.
const demoData = new Function(src + '\nreturn demoData;')();
process.stdout.write(JSON.stringify(demoData()));
