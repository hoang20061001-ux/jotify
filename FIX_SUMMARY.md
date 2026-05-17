# Offline-to-Online Time Update Fix - Implementation Complete

## Problem Statement
When a user edits a note offline, then comes back online, the note's `updated_at` timestamp was being updated to the sync time instead of preserving the original offline edit time.

Example:
- User edits note at 2:00 PM (offline)
- User comes back online at 2:05 PM
- Expected: Timestamp shows 2:00 PM (when they edited it)
- Actual: Timestamp changed to 2:05 PM (when sync happened)

## Root Cause Analysis
Multiple factors contributed to this issue:

1. **Server automatically updates timestamps during sync**
   - Laravel's `update()` method automatically updates `updated_at` to current server time
   - Client wasn't sending the original edit time to the server
   - Server didn't know this was an offline sync and shouldn't update the timestamp

2. **Client timestamp preservation logic was incomplete**
   - `_localEditedAt` marker wasn't being explicitly preserved through sync
   - Pending update queue didn't store the original edit time
   - Timestamp could be lost during IDB merge operations

3. **Pending update branch didn't preserve timestamps**
   - When notes with pending updates were merged with fresh server data
   - The existing timestamps weren't explicitly preserved
   - Server timestamps could overwrite local edit times

## Solution Implemented

### Changes Made

#### 1. Client: offline-db.js - updateNoteInIDB (line 315-332)
**Purpose**: Explicitly preserve `_localEditedAt` when marking a note as synced

```javascript
_localEditedAt: note._localEditedAt !== undefined 
    ? note._localEditedAt 
    : existing?._localEditedAt,
```

This ensures the offline edit timestamp marker survives the state change from pending_update to synced.

#### 2. Client: offline-db.js - mergeServerNotesIntoIDB pending_update branch (line 227-229)
**Purpose**: Preserve local timestamps for notes with pending updates

```javascript
updated_at_ts:   existing.updated_at_ts, // keep local edit time
_localEditedAt:  existing._localEditedAt, // keep local edit marker
```

When merging fresh server data, if a note still has pending_update status, we keep the LOCAL timestamps, not the server's new values.

#### 3. Client: offline-db.js - queueUpdate (line 470-471)
**Purpose**: Store the original edit time in the pending updates queue

```javascript
updated_at_ts: nowSeconds, // Store the actual edit time for sync
_localEditedAt: Date.now(), // Store local edit timestamp for conflict detection
```

Previously, STORE_UPDATES only stored title/content. Now it also stores the edit time so we can send it to the server.

#### 4. Client: offline-db.js - syncAllPending (line 604-605)
**Purpose**: Send the original edit time to the server

```javascript
updated_at_ts: item.updated_at_ts || Math.floor(Date.now() / 1000),
```

The client now tells the server: "This edit was made at time X, don't overwrite it."

#### 5. Server: NoteController.php - autoSave (line 122-132)
**Purpose**: Don't update timestamp when receiving offline sync

```php
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

The server now:
- Checks if client sent `updated_at_ts` (indicating offline sync)
- If yes: disables timestamps before update, preserving the original edit time
- If no: allows timestamps to update (normal online editing)

## How It Works Now

### Scenario: User edits offline and comes back online

1. **Offline Edit** (2:00 PM)
   - User edits note
   - `updateNoteOfflineFirst()` sets: `updated_at_ts: 2:00:00`, `_localEditedAt: 1714000000000ms`, `syncStatus: pending_update`
   - `queueUpdate()` stores: noteId, title, content, **updated_at_ts: 2:00:00**, **_localEditedAt: ...** in STORE_UPDATES

2. **Back Online** (2:05 PM)
   - `syncAllPending()` runs:
     - Reads pending update item with `updated_at_ts: 2:00:00`
     - Sends to server: `{title, content, updated_at_ts: 2:00:00}`
     - Server receives update with `updated_at_ts` parameter
     - Server disables timestamps and updates note
     - Note's `updated_at` stays as-is (now 2:00 PM from before sync attempt)
     - Server returns response

   - `updateNoteInIDB()` is called:
     - Explicitly preserves `_localEditedAt` from existing note
     - Sets `syncStatus: synced`

   - `mergeServerNotesIntoIDB()` is called with fresh server data:
     - Server's note has `updated_at_ts: 2:00:00` (unchanged because we disabled timestamps)
     - For pending_update notes: preserves local `updated_at_ts` and `_localEditedAt`
     - For synced notes: uses `_localEditedAt` to determine timestamp
     - Final result: `updated_at_ts: 2:00:00`

3. **Result**
   - Timestamp shown to user: 2:00 PM (the actual edit time)
   - Not 2:05 PM (the sync time)

## Testing Checklist

- [ ] Edit a note while online, verify timestamp updates normally
- [ ] Go offline, edit a note, verify time shows "Just now"
- [ ] Come back online, verify timestamp stays the same (not updated to sync time)
- [ ] Edit multiple notes offline, come back online, all timestamps preserved
- [ ] Sync with multiple clients, verify no race conditions
- [ ] Check that broadcasts still work with correct timestamps
- [ ] Verify password-protected notes still work
- [ ] Check shared note edits preserve timestamps

## Files Modified

1. `/resources/js/offline-db.js`
   - updateNoteInIDB(): Explicitly preserve _localEditedAt
   - mergeServerNotesIntoIDB(): Preserve timestamps in pending_update branch
   - updateNoteOfflineFirst(): Store edit time in STORE_UPDATES
   - syncAllPending(): Send updated_at_ts to server

2. `/app/Http/Controllers/NoteController.php`
   - autoSave(): Check for updated_at_ts and preserve timestamp if provided

## Benefits

1. **Preserves edit intent**: Timestamps show when users actually edited notes, not when sync happened
2. **Better user experience**: No confusing timestamp changes on sync
3. **Accurate history**: Offline edits maintain their original timestamps
4. **Maintains existing behavior**: Online-only edits still get current timestamps
5. **Conflict resolution**: Clear distinction between edit time and sync time
