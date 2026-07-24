// Smoke test: spawn the MCP server over stdio and list its tools.
// Does NOT require a live Orbitra instance — it only checks that the server
// boots and registers all tools correctly.
//
//   node src/smoke.js
//
// With a live instance you can also exercise a read call by setting
// ORBITRA_URL and ORBITRA_API_KEY and passing --ping.

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));

async function main() {
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: [join(__dirname, 'index.js')],
    env: { ...process.env },
  });

  const client = new Client({ name: 'orbitra-smoke', version: '1.0.0' });
  await client.connect(transport);

  const { tools } = await client.listTools();
  console.log(`✔ Server booted. ${tools.length} tools registered:\n`);
  for (const t of tools) {
    console.log(`  • ${t.name}`);
  }

  if (process.argv.includes('--ping')) {
    console.log('\nCalling orbitra_ping against the live instance...');
    const res = await client.callTool({ name: 'orbitra_ping', arguments: {} });
    console.log(res.content?.[0]?.text?.slice(0, 500));
  }

  await client.close();
  console.log('\n✔ Smoke test passed.');
  process.exit(0);
}

main().catch((err) => {
  console.error('✗ Smoke test failed:', err);
  process.exit(1);
});
