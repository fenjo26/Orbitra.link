// Node side of tests/mcp_stream_merge_test.php.
//
// argv[2]: a JSON file with cases [[current, incoming, deleteIds], …]. Prints
// the merged stream lists as one JSON array, in the same order, so the test
// can compare them with what core/McpTools.php produces for the same input.
import { readFileSync } from 'node:fs';
import { mergeStreamsForUpdate } from '../../mcp/src/streamMerge.js';

const cases = JSON.parse(readFileSync(process.argv[2], 'utf8'));
process.stdout.write(JSON.stringify(cases.map(([current, incoming, deleteIds]) => mergeStreamsForUpdate(current, incoming, deleteIds))));
