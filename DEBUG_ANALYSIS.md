# Offline-to-Online Time Update Bug - Root Cause Analysis

## The Problem
When a user:
1. Edits a note while offline
2. Comes back online
3. The note's timestamp gets updated to the sync time instead of preserving the offline edit time

## Key Code Flows

### Scenario: User edits offline then comes back online

#### Step 1: Offline Edit (offline-router.js)
- User edits note
- Calls `updateNoteOfflineFirst(noteId, { title, content })`
- This sets:
  - `updated_at_ts: Math.floor(Date.now() / 1000)` 
  - `_localEditedAt: Date.now()`
  - `syncStatus: 'pending_update'`
- IDB now has the offline edit timestamp

#### Step 2: User Comes Online → syncAllPending() (offline-db.js:504-615)
- Sends note to server via `/notes/{id}/auto-save` PUT
- Server receives update, Laravel's `$note->update()` sets server's `updated_at` to current server time
- Server returns response with new `updated_at` timestamp
- **BUG**: Client code IGNORES this returned timestamp (line 595-601)
- Calls `updateNoteInIDB({ id, syncStatus: 'synced' })` without updating `updated_at_ts`
- After this, IDB has:
  - `syncStatus: 'synced'` (changed)
  - `_localEditedAt` (should still exist from merge via `...existing`)
  - `updated_at_ts` (should be local edit time, NOT server sync time)

#### Step 3: mergeServerNotesIntoIDB() (offline-db.js:176-260)
- Gets fresh server data where `updated_at_ts` = server's current time (set during sync at Step 2)
- For each server note, gets existing from IDB
- Since `syncStatus === 'synced'` (not 'pending_update'), goes to line 227 else branch
- Line 231-233: Decides which timestamp to use:
  ```javascript
  const preservedTs = existing?._localEditedAt
      ? Math.floor(existing._localEditedAt / 1000)
      : (note.updated_at_ts ?? note.created_at_ts ?? 0);
  ```
- **THE BUG**: If `_localEditedAt` was cleared OR not preserved from `updateNoteInIDB`, then it uses `note.updated_at_ts` which is the NEW server sync time!

## Root Cause Candidates

### Candidate 1: `_localEditedAt` not being preserved in `updateNoteInIDB`
- `updateNoteInIDB` does `...existing` then `...note`
- Since `note = { id, syncStatus: 'synced' }`, it shouldn't override `_localEditedAt`
- **SHOULD** preserve it, but let's verify

### Candidate 2: `_localEditedAt` being cleared by `mergeServerNotesIntoIDB` before sync
- When fresh server data is merged on page load, line 251 sets:
  ```javascript
  _localEditedAt: existing?._localEditedAt ?? undefined,
  ```
- This clears `_localEditedAt` on ALL synced notes after fresh merge
- If user then goes offline and edits, `_localEditedAt` is set again
- **SHOULD** work, but possible race condition issue

### Candidate 3: Note not being in IDB when sync happens
- If the note doesn't exist in IDB yet (edge case), `updateNoteInIDB` creates defaults
- New note won't have `_localEditedAt`
- When `mergeServerNotesIntoIDB` runs, `_localEditedAt` doesn't exist, so server timestamp is used

### Candidate 4: **MOST LIKELY** - The note IS still in 'pending_update' status when mergeServerNotesIntoIDB is called
- Line 596 in syncAllPending calls `removePendingUpdate` but this only removes from STORE_UPDATES
- If `updateNoteInIDB` is not called, or fails, or is called but the database write fails
- The note might still have `syncStatus: 'pending_update'`
- Then in mergeServerNotesIntoIDB line 199-226, it goes to the pending_update branch
- Which does `...existing` but DOESN'T explicitly set `updated_at_ts`!
- So it should preserve it... unless there's an issue

### Candidate 5: **CRITICAL** - `updateNoteInIDB` overwrites `updated_at_ts` to defaults!
Looking at line 311-326 in offline-db.js:
```javascript
await db.put(STORE_NOTES, {
    id:           note.id,
    title:        '',
    content:      '',
    note_color:   'none',
    is_pinned:    false,
    has_password: false,
    note_password:null,
    is_shared:    false,
    labels:       [],
    updated_at:   '',
    created_at_ts:0,
    syncStatus:   'synced',
    ...existing,   // override defaults with existing data
    ...note,       // override with new data
});
```

The defaults include `created_at_ts: 0` and `updated_at: ''`!

After `...existing`, these would still be in the object! Then when it gets passed to put(), if `existing` doesn't include these fields, they'll use the defaults!

**WAIT NO** - `...existing` is spread BEFORE these field assignments, so it should override them.

Actually, I'm reading the order wrong. The order is:
1. Object literal starts: `{ id, title: '', ... }`
2. First spread: `...existing` (adds/overrides with existing values)
3. Second spread: `...note` (adds/overrides with new values)

So the final object has:
- Defaults from literal
- Overridden by existing
- Overridden by note

So `updated_at_ts` should come from existing if it exists there, not use the `created_at_ts:0` default.

## Root Cause - FOUND
The issue has multiple contributing factors:

1. **`_localEditedAt` not explicitly preserved in updateNoteInIDB**: 
   - While it should be preserved via `...existing`, it wasn't explicitly guaranteed
   - Solution: Explicitly set `_localEditedAt` in the put() call

2. **pending_update branch doesn't preserve timestamps**: 
   - When a note has pending_update status and server sends fresh data, the pending_update branch (line 199-226) spreads existing but the timestamps weren't explicitly preserved
   - Solution: Explicitly set `updated_at_ts` and `_localEditedAt` in pending_update branch

3. **Pending update queue doesn't store edit time**: 
   - STORE_UPDATES only stored noteId, title, content, queued_at
   - It didn't store the original edit time
   - Solution: Store `updated_at_ts` and `_localEditedAt` in STORE_UPDATES

4. **Server updates timestamp during offline sync**:
   - Most critical: Laravel's `$note->update()` automatically updates the `updated_at` field to now
   - Client only sends title/content, so server doesn't know this is an offline sync
   - Solution: Client sends `updated_at_ts` to server; server doesn't update timestamp if this is provided

## Applied Fixes

### 1. offline-db.js - updateNoteInIDB (line 315-332)
- Explicitly preserve `_localEditedAt` with fallback to existing value
- Ensures offline edit timestamp markers survive the sync state change

### 2. offline-db.js - mergeServerNotesIntoIDB pending_update branch (line 227-229)
- Explicitly preserve `updated_at_ts` and `_localEditedAt` for pending updates
- Prevents server timestamp from overwriting local edit time

### 3. offline-db.js - updateNoteOfflineFirst queue update (line 470-471)
- Store `updated_at_ts` and `_localEditedAt` in STORE_UPDATES
- Makes the edit time available during sync

### 4. offline-db.js - syncAllPending (line 604-605)
- Send `updated_at_ts` to server along with title/content
- Server now knows the original edit time

### 5. NoteController.php - autoSave (line 122-132)
- If `updated_at_ts` is provided (offline sync), disable timestamps before update
- Preserves the original edit time on the server
- Only updates timestamp for real-time online edits
