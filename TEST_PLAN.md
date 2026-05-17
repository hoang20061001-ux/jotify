# Test Plan: Offline-to-Online Timestamp Preservation

## Unit Tests

### Test 1: updateNoteOfflineFirst stores edit time
**Setup**: Fresh note in IDB
**Action**: Call updateNoteOfflineFirst with title and content
**Expected**:
- `updated_at_ts` is set to current Unix timestamp (seconds)
- `_localEditedAt` is set to current JS timestamp (milliseconds)
- Both values are stored in STORE_UPDATES queue
**Verification**:
```javascript
const updates = await getPendingUpdates();
const item = updates[0];
assert(item.updated_at_ts > 0, 'Should have updated_at_ts');
assert(item._localEditedAt > 0, 'Should have _localEditedAt');
assert(Math.abs(Date.now() - item._localEditedAt) < 1000, 'Should be recent');
```

### Test 2: updateNoteInIDB preserves _localEditedAt
**Setup**: Note in IDB with `_localEditedAt` set
**Action**: Call updateNoteInIDB with just `{id, syncStatus: 'synced'}`
**Expected**: `_localEditedAt` is preserved, not cleared
**Verification**:
```javascript
const note = await getNoteById(noteId);
assert(note._localEditedAt > 0, '_localEditedAt should be preserved');
```

### Test 3: mergeServerNotesIntoIDB preserves pending_update timestamps
**Setup**: 
- Note in IDB with `syncStatus: 'pending_update'`, `updated_at_ts: 1000`, `_localEditedAt: 1000000`
- Fresh server data has `updated_at_ts: 2000`
**Action**: Call mergeServerNotesIntoIDB with fresh server data
**Expected**: Local timestamps are preserved, not overwritten
**Verification**:
```javascript
const note = await getNoteById(noteId);
assert(note.updated_at_ts === 1000, 'Should preserve local updated_at_ts');
assert(note._localEditedAt === 1000000, 'Should preserve _localEditedAt');
```

### Test 4: mergeServerNotesIntoIDB uses _localEditedAt for synced notes
**Setup**:
- Note in IDB with `syncStatus: 'synced'`, `_localEditedAt: 1000000`
- Fresh server data has `updated_at_ts: 2000`
**Action**: Call mergeServerNotesIntoIDB
**Expected**: Uses `_localEditedAt` for display, not server timestamp
**Verification**:
```javascript
const note = await getNoteById(noteId);
assert(note.updated_at_ts === 1000, 'Should use _localEditedAt converted to seconds');
assert(note._localEditedAt === 1000000, 'Should preserve _localEditedAt');
```

## Integration Tests

### Test 5: Full offline edit → sync → merge flow
**Setup**: User online, has notes loaded
**Steps**:
1. User goes offline
2. User edits note A at time T1 (e.g., 2:00 PM)
3. Check IDB:
   - `syncStatus: 'pending_update'`
   - `updated_at_ts: T1` (seconds)
   - `_localEditedAt: T1` (milliseconds)
   - STORE_UPDATES has entry with `updated_at_ts: T1`
4. User goes back online
5. syncAllPending sends:
   - title, content, `updated_at_ts: T1`
6. Server disables timestamps and updates note
7. mergeServerNotesIntoIDB runs
8. Check IDB again:
   - `syncStatus: 'synced'`
   - `updated_at_ts: T1` (preserved, not changed to sync time)
   - `_localEditedAt: T1` (preserved)
9. UI shows timestamp for time T1 (not current sync time)

### Test 6: Online edit still updates timestamp normally
**Setup**: User is online
**Steps**:
1. User edits note B at time T2 (e.g., 2:05 PM)
2. Note is saved online (auto-save)
3. Check server:
   - `updated_at` timestamp should be T2 (or very close)
4. syncAllPending is NOT called (note was synced immediately)
5. No `updated_at_ts` sent to server
6. Timestamp should be current (T2)

### Test 7: Offline → online → offline → online (multiple cycles)
**Setup**: Normal usage pattern
**Steps**:
1. User edits note online at T1
2. User goes offline
3. User edits note offline at T2
4. User goes online → sync happens
5. User goes offline again
6. User edits note offline at T3
7. User goes online → sync happens again
8. Verify final timestamp is T3 (most recent edit time)

### Test 8: Multiple offline edits to same note
**Setup**: Offline for extended time
**Steps**:
1. Go offline
2. Edit note at T1
3. Edit same note again at T2
4. Edit same note again at T3
5. Go online → sync
6. Verify timestamp is T3 (latest edit)
7. Previous edits should not create new queue entries
8. Only latest content is synced

## Edge Cases

### Test 9: Offline edit with password-protected note
**Setup**: Password-protected note
**Steps**:
1. Go offline
2. Edit password-protected note (already unlocked in session)
3. Go online
4. Sync happens
5. Verify timestamp is preserved
6. Verify password remains intact

### Test 10: Offline edit of shared note
**Setup**: Note shared with edit permission
**Steps**:
1. Go offline
2. Edit shared note
3. Go online
4. Sync happens
5. Verify broadcast is sent with correct timestamp
6. Verify shared users see correct timestamp

### Test 11: Offline delete then re-edit
**Setup**: Normal note
**Steps**:
1. Go offline
2. Delete note locally
3. Later, add new content (restores note)
4. Go online
5. Verify sync works
6. Verify timestamp is correct

### Test 12: Server-side concurrent edits
**Setup**: Same note edited offline by one client, online by another
**Steps**:
1. Client A goes offline, edits note at time TA
2. Client B (online) edits same note at time TB
3. Client A comes back online, syncs
4. Verify no data loss
5. Verify timestamps make sense

## Browser Compatibility Tests

- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)
- [ ] Mobile Chrome
- [ ] Mobile Safari

## Performance Tests

- [ ] Sync with 100+ offline edits doesn't timeout
- [ ] IDB merge performance acceptable
- [ ] No memory leaks in offline → online transitions
- [ ] Battery usage on mobile unaffected

## Regression Tests

Ensure existing functionality still works:
- [ ] Normal note creation
- [ ] Note editing online
- [ ] Note deletion
- [ ] Label management
- [ ] Note sharing
- [ ] Password protection
- [ ] Image uploads
- [ ] Search functionality
- [ ] Collaborative editing
- [ ] Broadcasting to shared users
- [ ] Pinning/unpinning notes

## Acceptance Criteria

✅ All unit tests pass
✅ All integration tests pass
✅ No regressions detected
✅ Timestamps correctly preserved for offline edits
✅ Online edits still update timestamps normally
✅ Multiple sync cycles work correctly
✅ Edge cases handled gracefully
✅ No performance degradation
✅ Code is maintainable and documented
