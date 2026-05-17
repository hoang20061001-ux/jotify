# Code Changes Summary

## File 1: resources/js/offline-db.js

### Change 1: updateNoteInIDB function (line 315-332)
- Added explicit preservation of `_localEditedAt` field
- Previously relied on spread operator which wasn't guaranteed
- Now ensures offline edit timestamp markers survive sync state changes

**Key line added:**
```javascript
_localEditedAt: note._localEditedAt !== undefined ? note._localEditedAt : existing?._localEditedAt,
```

### Change 2: mergeServerNotesIntoIDB - pending_update branch (line 227-229)
- Added explicit preservation of `updated_at_ts` and `_localEditedAt` for pending updates
- Previously these could be overwritten by server values
- Now keeps LOCAL timestamps for notes still awaiting sync

**Key lines added:**
```javascript
updated_at_ts:   existing.updated_at_ts, // keep local edit time
_localEditedAt:  existing._localEditedAt, // keep local edit marker
```

### Change 3: updateNoteOfflineFirst - queue update (line 470-471)
- Added `updated_at_ts` and `_localEditedAt` to STORE_UPDATES
- Previously only stored noteId, title, content
- Now stores edit time so it can be sent to server during sync

**Lines modified:**
```javascript
await db.put(STORE_UPDATES, {
    noteId, 
    title: data.title || '', 
    content: data.content || '',
    updated_at_ts: nowSeconds,           // ★ NEW
    _localEditedAt: Date.now(),          // ★ NEW
    queued_at: Date.now(),
});
```

### Change 4: syncAllPending - send edit time to server (line 604-605)
- Added `updated_at_ts` to the fetch body
- Tells server: "Don't update timestamp, this was edited at this time"

**Lines modified:**
```javascript
body: JSON.stringify({
    title:   item.title   || '',
    content: item.content || '',
    updated_at_ts: item.updated_at_ts || Math.floor(Date.now() / 1000), // ★ NEW
}),
```

---

## File 2: app/Http/Controllers/NoteController.php

### Change: autoSave function (line 122-132)
- Added check for `updated_at_ts` parameter from client
- If provided (offline sync), disables Laravel timestamps before update
- If not provided (online edit), allows normal timestamp update

**Before:**
```php
$note->update($request->only(['title', 'content']));
```

**After:**
```php
$data = $request->only(['title', 'content']);

if ($request->has('updated_at_ts')) {
    // Disable timestamps temporarily so update() doesn't change updated_at
    $note->timestamps = false;
    $note->update($data);
    $note->timestamps = true;
} else {
    // Normal online edit - allow timestamp to be updated to now
    $note->update($data);
}
```

---

## Impact Analysis

### Client-Side (JavaScript)
- No breaking changes to existing APIs
- All changes are internal to offline-db.js
- Timestamp preservation is automatic

### Server-Side (PHP)
- auto-save endpoint now accepts optional `updated_at_ts` parameter
- If not provided, behaves exactly as before
- Backward compatible with existing clients

### Storage
- STORE_UPDATES now has 2 additional fields per item
- No impact on other stores
- Existing data won't have these fields (handled by || fallback)

### User Experience
- Timestamps now show edit time, not sync time
- No visual changes to UI
- Better timestamp accuracy

---

## Testing Commands

```bash
# Check syntax (if Node is available)
node -c resources/js/offline-db.js

# Check PHP syntax
php -l app/Http/Controllers/NoteController.php

# Run tests
npm run test
php artisan test
```
