// Stream list merge for orbitra_update_campaign — the twin of
// mcpMergeStreamsForUpdate() in core/McpTools.php. tests/mcp_stream_merge_test.php
// runs both against the same fixtures, so the stdio server and the HTTP endpoint
// cannot drift apart.
//
// Why it exists: save_campaign replaces the whole stream list (that is what the
// editor needs). A caller that sends one stream with one field to change a
// weight used to reset every other field of that stream and to drop the streams
// it did not send.
//
//   - a stream carrying the id of a current stream is updated in place: only the
//     fields it carries change, the rest come from the stored stream;
//   - a stream without a known id is added;
//   - a second stream with an id already patched is a copy of it (what the
//     editor's "duplicate" sends);
//   - current streams that were not mentioned stay;
//   - nothing is removed unless its id is in deleteIds.

export function mergeStreamsForUpdate(current, incoming, deleteIds = []) {
  const stored = Array.isArray(current) ? current : [];
  const passed = Array.isArray(incoming) ? incoming : [];
  const byId = new Map();
  for (const s of stored) {
    const id = Number(s?.id ?? 0);
    if (id > 0) byId.set(id, s);
  }
  const remove = new Set(
    (Array.isArray(deleteIds) ? deleteIds : []).map(Number).filter((n) => Number.isInteger(n) && n > 0)
  );

  const patched = new Map(); // id -> merged stream
  const added = [];
  for (const inc of passed) {
    if (!inc || typeof inc !== 'object') continue;
    const id = Number(inc.id ?? 0);
    const base = id > 0 ? byId.get(id) : undefined;
    if (base !== undefined && !patched.has(id)) {
      patched.set(id, { ...base, ...inc });
    } else {
      const copy = { ...(base ?? {}), ...inc };
      if (base !== undefined) delete copy.id;
      added.push(copy);
    }
  }

  const out = [];
  for (const s of stored) {
    const id = Number(s?.id ?? 0);
    if (remove.has(id)) continue;
    out.push(patched.get(id) ?? s);
  }
  out.push(...added);
  return out;
}
