/**
 * offline-db.js — JOTIFY IndexedDB wrapper (dùng idb library)
 * v4: Production-grade schema migration + self-healing
 *   - IDB is the single source of truth for the UI
 *   - Server is a sync target, not primary data source
 *   - syncStatus on every note: 'synced' | 'pending_create' | 'pending_update'
 *   - Never clear() the store — diff-based merge only
 *   - Sync mutex prevents concurrent sync operations
 *   - Automatic corrupted DB recovery (delete + recreate)
 *   - Schema validation after every open
 */
import { openDB } from 'idb';

const DB_NAME    = 'jotify-offline';
const DB_VERSION = 4; // Bumped to force migration on ALL legacy DBs

const STORE_NOTES    = 'notes';
const STORE_CREATES  = 'pending_creates';   // notes tạo offline, chờ sync
const STORE_UPDATES  = 'pending_updates';   // notes sửa offline, chờ sync
const STORE_LABELS   = 'labels';            // cache labels của user
const STORE_PREFS    = 'preferences';       // cache preferences
const STORE_PROFILE  = 'profile';           // offline profile cache + pending update

// ALL stores the app requires — used for validation after open
const REQUIRED_STORES = [
    STORE_NOTES, STORE_CREATES, STORE_UPDATES,
    STORE_LABELS, STORE_PREFS, STORE_PROFILE,
];

// ══════════════════════════════════════════════════════════════════════════════
// SINGLETON DB PROMISE — prevents race conditions during first load.
// All callers await the same promise; the DB is opened exactly once.
// ══════════════════════════════════════════════════════════════════════════════
let _dbPromise = null;

function getDB() {
    if (!_dbPromise) {
        _dbPromise = _openAndValidate();
    }
    return _dbPromise;
}

async function _openAndValidate() {
    try {
        const db = await openDB(DB_NAME, DB_VERSION, {
            upgrade(db, oldVersion, newVersion) {
                console.log(`[IDB] Upgrading schema: v${oldVersion} → v${newVersion}`);
                console.log('[IDB] Existing stores before migration:', [...db.objectStoreNames]);

                // ── Create ALL stores unconditionally (idempotent via contains() guard) ──
                if (!db.objectStoreNames.contains(STORE_NOTES)) {
                    console.log('[IDB] Creating store:', STORE_NOTES);
                    const store = db.createObjectStore(STORE_NOTES, { keyPath: 'id' });
                    store.createIndex('is_pinned',  'is_pinned',  { unique: false });
                    store.createIndex('updated_at', 'updated_at', { unique: false });
                }

                if (!db.objectStoreNames.contains(STORE_CREATES)) {
                    console.log('[IDB] Creating store:', STORE_CREATES);
                    db.createObjectStore(STORE_CREATES, { keyPath: 'tempId' });
                }

                if (!db.objectStoreNames.contains(STORE_UPDATES)) {
                    console.log('[IDB] Creating store:', STORE_UPDATES);
                    db.createObjectStore(STORE_UPDATES, { keyPath: 'noteId' });
                }

                if (!db.objectStoreNames.contains(STORE_LABELS)) {
                    console.log('[IDB] Creating store:', STORE_LABELS);
                    db.createObjectStore(STORE_LABELS, { keyPath: 'id' });
                }

                if (!db.objectStoreNames.contains(STORE_PREFS)) {
                    console.log('[IDB] Creating store:', STORE_PREFS);
                    db.createObjectStore(STORE_PREFS, { keyPath: 'key' });
                }

                if (!db.objectStoreNames.contains(STORE_PROFILE)) {
                    console.log('[IDB] Creating store:', STORE_PROFILE);
                    db.createObjectStore(STORE_PROFILE, { keyPath: 'key' });
                }

                console.log('[IDB] Stores after migration:', [...db.objectStoreNames]);
            },
        });

        // ── Post-open schema validation ──────────────────────────────────────
        console.log('[IDB] DB opened, version:', db.version, 'stores:', [...db.objectStoreNames]);

        const missing = REQUIRED_STORES.filter(s => !db.objectStoreNames.contains(s));
        if (missing.length > 0) {
            console.error('[IDB] SCHEMA INVALID — missing stores:', missing);
            _triggerRecovery(db);
            // Return a rejected promise so callers get an error instead of a broken db
            throw new Error('[IDB] Schema invalid, recovery triggered');
        }

        return db;

    } catch (err) {
        console.error('[IDB] Failed to open DB:', err);
        // If the error is a VersionError (downgrade attempt) or schema issue,
        // trigger recovery. Otherwise re-throw.
        if (err.name === 'VersionError' || err.message?.includes('Schema invalid')) {
            _triggerRecovery(null);
        }
        throw err;
    }
}

/**
 * Auto-recovery: close the broken DB, delete it, and reload the page.
 * Uses sessionStorage to prevent infinite reload loops.
 */
function _triggerRecovery(db) {
    const RECOVERY_KEY = 'idb-recovery-v4';

    // Guard: prevent infinite reload loop
    if (sessionStorage.getItem(RECOVERY_KEY)) {
        console.error('[IDB] Recovery already attempted this session — aborting to prevent loop.');
        sessionStorage.removeItem(RECOVERY_KEY);
        return;
    }

    console.warn('[IDB] ▸ Triggering automatic DB recovery...');
    sessionStorage.setItem(RECOVERY_KEY, '1');

    // Reset singleton so next call will re-open
    _dbPromise = null;

    try {
        if (db) db.close();
    } catch (_) {}

    // Delete the corrupted database and reload
    const delReq = indexedDB.deleteDatabase(DB_NAME);
    delReq.onsuccess = () => {
        console.log('[IDB] ▸ Corrupted DB deleted — reloading...');
        location.reload();
    };
    delReq.onerror = () => {
        console.error('[IDB] ▸ Failed to delete DB — reloading anyway...');
        location.reload();
    };
    delReq.onblocked = () => {
        console.warn('[IDB] ▸ DB delete blocked (other tabs open) — reloading...');
        location.reload();
    };
}

// Clear recovery flag on successful page load (means recovery worked)
try {
    if (sessionStorage.getItem('idb-recovery-v4')) {
        console.log('[IDB] Recovery successful — clearing flag');
        sessionStorage.removeItem('idb-recovery-v4');
    }
} catch (_) {}

// ──────────────────────────────────────────────────────────────────────────────
// NOTES — offline-first read/write with syncStatus tracking
//
// syncStatus values:
//   'synced'         — note exists on server and IDB is up to date
//   'pending_create' — note created offline, not yet on server
//   'pending_update' — note has local edits not yet synced to server
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Merge server notes into IDB — NEVER clears the store.
 * Server data is authoritative for synced notes.
 * Local-only notes (pending_create) are preserved.
 * Notes with pending_update keep their local title/content.
 *
 * @param {Array} serverNotes — notes from the server API
 */
export async function mergeServerNotesIntoIDB(serverNotes) {
    try {
        const db = await getDB();
        const tx = db.transaction(STORE_NOTES, 'readwrite');

        const serverIds = new Set(serverNotes.map(n => n.id));
        const existingKeys = await tx.store.getAllKeys();

        // 1. Remove notes deleted on server — but NEVER delete local-only notes
        for (const key of existingKeys) {
            if (!serverIds.has(key)) {
                const existing = await tx.store.get(key);
                // Only delete if it was synced (came from server originally)
                if (existing && existing.syncStatus !== 'pending_create') {
                    await tx.store.delete(key);
                }
            }
        }

        // 2. Upsert server notes — but don't overwrite pending local edits
        for (const note of serverNotes) {
            const existing = await tx.store.get(note.id);

            if (existing && existing.syncStatus === 'pending_update') {
                // ── Conflict detection (last-write-wins) ─────────────────
                // Compare server updated_at with local _localEditedAt.
                // Local edits always win for pending notes, but we log the conflict.
                const serverTime = note.updated_at_raw
                    ? new Date(note.updated_at_raw).getTime()
                    : (note.created_at_ts ? note.created_at_ts * 1000 : 0);
                const localTime = existing._localEditedAt || 0;
                if (serverTime > localTime && localTime > 0) {
                    console.warn('[IDB] Conflict detected for note:', note.id,
                        '— server is newer but keeping local edits.',
                        'Server:', new Date(serverTime).toISOString(),
                        'Local:', new Date(localTime).toISOString());
                }
                // Keep local title/content (user edited offline), update metadata
                // ★ Also preserve the local edit timestamp and _localEditedAt marker
                await tx.store.put({
                    ...existing,
                    note_color:      note.note_color      ?? existing.note_color,
                    is_pinned:       note.is_pinned        ?? existing.is_pinned,
                    pinned_at_ts:    note.pinned_at_ts     ?? existing.pinned_at_ts ?? 0,
                    has_password:    note.has_password      ?? existing.has_password,
                    note_password:   note.note_password     ?? existing.note_password,
                    is_shared:       note.is_shared         ?? existing.is_shared,
                    labels:          note.labels            ?? existing.labels,
                    first_image_url: note.first_image_url  ?? existing.first_image_url ?? null,
                    images_urls:     note.images_urls       ?? existing.images_urls     ?? [],
                    // syncStatus stays 'pending_update'
                    // ★ Preserve _localEditedAt and updated_at_ts (don't overwrite with server values)
                    updated_at_ts:   existing.updated_at_ts, // keep local edit time
                    _localEditedAt:  existing._localEditedAt, // keep local edit marker
                });
            } else {
                // Fresh write or update a synced note
                // ★ Preserve offline edit timestamp: if existing has _localEditedAt,
                // don't overwrite updated_at_ts with server value (server time is sync time, not edit time)
                const preservedTs = existing?._localEditedAt
                    ? Math.floor(existing._localEditedAt / 1000)
                    : (note.updated_at_ts ?? note.created_at_ts ?? 0);
                await tx.store.put({
                    id:              note.id,
                    title:           note.title            ?? '',
                    content:         note.content          ?? '',
                    note_color:      note.note_color       ?? '#ffffff',
                    is_pinned:       note.is_pinned        ?? false,
                    pinned_at_ts:    note.pinned_at_ts     ?? 0,
                    has_password:    note.has_password      ?? false,
                    note_password:   note.note_password     ?? null,
                    is_shared:       note.is_shared         ?? false,
                    labels:          note.labels            ?? [],
                    first_image_url: note.first_image_url  ?? null,
                    images_urls:     note.images_urls       ?? [],
                    updated_at:      note.updated_at        ?? '',
                    updated_at_ts:   preservedTs,
                    created_at_ts:   note.created_at_ts     ?? 0,
                    syncStatus:      'synced',
                    _localEditedAt:  existing?._localEditedAt ?? undefined,
                });
            }
        }

        await tx.done;
    } catch (e) {
        console.warn('[IDB] mergeServerNotesIntoIDB failed:', e);
    }
}

/**
 * Backward-compatible wrapper — callers that used saveNotesToIDB
 * now get merge behavior instead of destructive clear.
 */
export async function saveNotesToIDB(notes) {
    return mergeServerNotesIntoIDB(notes);
}

export async function getNotesFromIDB() {
    try {
        const db    = await getDB();
        const notes = await db.getAll(STORE_NOTES);
        notes.sort((a, b) => {
            if (b.is_pinned !== a.is_pinned) return b.is_pinned ? 1 : -1;
            // Sort by most recently updated:
            //   _localEditedAt (ms) — set by offline editor
            //   updated_at_ts (s)   — set by server merge
            //   created_at_ts (s)   — fallback
            const aTime = a._localEditedAt || (a.updated_at_ts || a.created_at_ts || 0) * 1000;
            const bTime = b._localEditedAt || (b.updated_at_ts || b.created_at_ts || 0) * 1000;
            return bTime - aTime;
        });
        return notes;
    } catch (e) {
        console.warn('[IDB] getNotesFromIDB failed:', e);
        return [];
    }
}

/** Alias for getNotesFromIDB — semantic name for the offline-first architecture */
export async function getAllNotes() {
    return getNotesFromIDB();
}

export async function deleteNoteFromIDB(id) {
    try {
        const db = await getDB();
        await db.delete(STORE_NOTES, id);
    } catch (e) {
        console.warn('[IDB] deleteNoteFromIDB failed:', e);
    }
}

export async function updateNoteInIDB(note) {
    try {
        const db = await getDB();
        const existing = await db.get(STORE_NOTES, note.id);
        // Upsert: merge with existing or create with defaults
        // Preserve syncStatus unless explicitly provided
        // ★ CRITICAL: Explicitly preserve _localEditedAt so offline edit timestamps aren't lost
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
            // Explicitly preserve _localEditedAt from existing (if present)
            _localEditedAt: note._localEditedAt !== undefined ? note._localEditedAt : existing?._localEditedAt,
        });
    } catch (e) {
        console.warn('[IDB] updateNoteInIDB failed:', e);
    }
}

export async function hasOfflineNotes() {
    try {
        const db    = await getDB();
        const count = await db.count(STORE_NOTES);
        return count > 0;
    } catch (e) {
        return false;
    }
}

/**
 * Get a single note by ID — robust lookup with type fallback.
 * Tries numeric key, then string key, then getAll + filter.
 * @param {number|string} id
 * @returns {object|null}
 */
export async function getNoteById(id) {
    try {
        const db = await getDB();
        // Strategy 1: direct lookup (number)
        let note = await db.get(STORE_NOTES, typeof id === 'string' ? id : Number(id));
        if (note) return note;
        // Strategy 2: try opposite type
        note = await db.get(STORE_NOTES, String(id));
        if (note) return note;
        // Strategy 3: fallback to getAll + find
        const all = await db.getAll(STORE_NOTES);
        return all.find(n => String(n.id) === String(id)) || null;
    } catch (e) {
        console.warn('[IDB] getNoteById failed:', e);
        return null;
    }
}

/**
 * Create a note offline-first.
 * Generates a tempId, saves to IDB immediately, queues for sync.
 * @param {{ title?: string, content?: string }} data
 * @returns {{ tempId: string, note: object }}
 */
export async function createNoteOfflineFirst(data = {}) {
    const tempId = 'temp_' + Date.now();
    const nowSeconds = Math.floor(Date.now() / 1000);
    const note = {
        id:            tempId,
        title:         data.title   || '',
        content:       data.content || '',
        note_color:    'none',
        is_pinned:     false,
        has_password:  false,
        note_password: null,
        is_shared:     false,
        labels:        [],
        updated_at:    'Just now',
        updated_at_ts: nowSeconds,
        created_at_ts: nowSeconds,
        syncStatus:    'pending_create',
        _pending:      true,
        _localEditedAt: Date.now(),
    };
    try {
        const db = await getDB();
        await db.put(STORE_NOTES, note);
        // Also queue in pending_creates for syncAllPending
        await db.put(STORE_CREATES, {
            tempId,
            title:         note.title,
            content:       note.content,
            created_at_ts: note.created_at_ts,
            updated_at_ts: note.updated_at_ts,
            queued_at:     Date.now(),
        });
    } catch (e) {
        console.warn('[IDB] createNoteOfflineFirst failed:', e);
    }
    return { tempId, note };
}

/**
 * Update a note offline-first.
 * Saves to IDB immediately, queues sync if offline.
 * @param {number|string} noteId
 * @param {{ title?: string, content?: string }} data
 */
export async function updateNoteOfflineFirst(noteId, data) {
    try {
        const db = await getDB();
        // Robust lookup: try the given key, then numeric, then string
        let existing = await db.get(STORE_NOTES, noteId);
        if (!existing && typeof noteId === 'string' && /^\d+$/.test(noteId)) {
            existing = await db.get(STORE_NOTES, Number(noteId));
            if (existing) noteId = Number(noteId); // use the correct key type
        }
        if (!existing) {
            existing = await db.get(STORE_NOTES, String(noteId));
        }
        if (!existing) {
            // Note not in IDB — create it
            const nowSeconds = Math.floor(Date.now() / 1000);
            await db.put(STORE_NOTES, {
                id: noteId, title: data.title || '', content: data.content || '',
                note_color: 'none', is_pinned: false, has_password: false,
                note_password: null, is_shared: false, labels: [],
                updated_at: 'Just now',
                updated_at_ts: nowSeconds,
                created_at_ts: nowSeconds,
                syncStatus: 'pending_update', _pending: true,
                _localEditedAt: Date.now(),
            });
        } else {
            const nowSeconds = Math.floor(Date.now() / 1000);
            const newStatus = existing.syncStatus === 'pending_create'
                ? 'pending_create'  // keep as pending_create
                : 'pending_update';
            await db.put(STORE_NOTES, {
                ...existing,
                title:      data.title   ?? existing.title,
                content:    data.content ?? existing.content,
                updated_at: 'Just now',
                updated_at_ts: nowSeconds,
                syncStatus: newStatus,
                _pending:   true,
                _localEditedAt: Date.now(),  // for conflict detection
            });
        }
        // Queue update for sync (unless it's a temp note — those use pending_creates)
        if (typeof noteId === 'number' || !String(noteId).startsWith('temp_')) {
            await db.put(STORE_UPDATES, {
                noteId, 
                title: data.title || '', 
                content: data.content || '',
                updated_at_ts: nowSeconds, // ★ Store the actual edit time for sync
                _localEditedAt: Date.now(), // ★ Store local edit timestamp for conflict detection
                queued_at: Date.now(),
            });
        } else {
            // For temp notes: update STORE_CREATES so syncAllPending sends latest content
            const pendingCreate = await db.get(STORE_CREATES, String(noteId));
            if (pendingCreate) {
                await db.put(STORE_CREATES, {
                    ...pendingCreate,
                    title:   data.title   ?? pendingCreate.title   ?? '',
                    content: data.content ?? pendingCreate.content ?? '',
                });
            }
        }
    } catch (e) {
        console.warn('[IDB] updateNoteOfflineFirst failed:', e);
    }
}

/** Get count of notes with pending sync status */
export async function getPendingSyncCount() {
    try {
        const db    = await getDB();
        const notes = await db.getAll(STORE_NOTES);
        return notes.filter(n =>
            n.syncStatus === 'pending_create' || n.syncStatus === 'pending_update'
        ).length;
    } catch (e) {
        return 0;
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// SYNC ENGINE — mutex-protected sync of pending changes to server
// ──────────────────────────────────────────────────────────────────────────────

let _syncLock = false;

/**
 * Sync all pending changes (creates + updates) to the server.
 * Protected by a mutex to prevent concurrent sync operations.
 * @param {string} csrfToken — CSRF token for server requests
 * @returns {{ created: number, updated: number, failed: number }}
 */
export async function syncAllPending(csrfToken) {
    if (_syncLock) return { created: 0, updated: 0, failed: 0 };
    _syncLock = true;
    const result = { created: 0, updated: 0, failed: 0 };

    try {
        // ── Sync pending creates ──────────────────────────────────────────
        const creates = await getPendingCreates();
        for (const item of creates) {
            try {
                const res = await fetch('/notes', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                });
                if (!res.ok) { result.failed++; continue; }

                const data = await res.json().catch(() => ({}));
                const newId = data.id;
                if (newId) {
                    // Read the LATEST content from STORE_NOTES (source of truth)
                    // STORE_CREATES may have stale data if user edited after create
                    const db = await getDB();
                    const latestNote = await db.get(STORE_NOTES, item.tempId);
                    const syncTitle   = latestNote?.title   ?? item.title   ?? '';
                    const syncContent = latestNote?.content ?? item.content ?? '';

                    // Push the offline content to the new server note
                    await fetch(`/notes/${newId}/auto-save`, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            title:   syncTitle,
                            content: syncContent,
                        }),
                    });

                    // ★ ATOMIC: delete tempId + insert realId in ONE transaction
                    // Prevents note loss if a failure occurs mid-operation.
                    const tx = db.transaction(STORE_NOTES, 'readwrite');
                    await tx.store.delete(item.tempId);
                    await tx.store.put({
                        id:            newId,
                        title:         syncTitle,
                        content:       syncContent,
                        note_color:    latestNote?.note_color  ?? 'none',
                        is_pinned:     latestNote?.is_pinned   ?? false,
                        has_password:  false,
                        note_password: null,
                        is_shared:     false,
                        labels:        latestNote?.labels      ?? [],
                        updated_at:    'Just now',
                        updated_at_ts: latestNote?.updated_at_ts
                            ?? (latestNote?._localEditedAt ? Math.floor(latestNote._localEditedAt / 1000) : undefined)
                            ?? item.created_at_ts
                            ?? Math.floor(Date.now() / 1000),
                        created_at_ts: item.created_at_ts ?? Math.floor(Date.now() / 1000),
                        syncStatus:    'synced',
                        _localEditedAt: latestNote?._localEditedAt ?? undefined,
                    });
                    await tx.done;

                    // Remove from pending_creates queue
                    await removePendingCreate(item.tempId);
                    result.created++;
                }
            } catch (e) {
                result.failed++;
            }
        }

        // ── Sync pending updates ──────────────────────────────────────────
        const updates = await getPendingUpdates();
        for (const item of updates) {
            try {
                const res = await fetch(`/notes/${item.noteId}/auto-save`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        title:   item.title   || '',
                        content: item.content || '',
                        // ★ Send the original edit time so server knows not to update timestamp
                        updated_at_ts: item.updated_at_ts || Math.floor(Date.now() / 1000),
                    }),
                });
                if (res.ok) {
                    await removePendingUpdate(item.noteId);
                    // Mark note as synced in IDB
                    await updateNoteInIDB({
                        id:         item.noteId,
                        syncStatus: 'synced',
                    });
                    result.updated++;
                } else {
                    result.failed++;
                }
            } catch (e) {
                result.failed++;
            }
        }
    } finally {
        _syncLock = false;
    }

    return result;
}

// ──────────────────────────────────────────────────────────────────────────────
// OFFLINE QUEUE — pending_creates & pending_updates
// (kept for backward compat; syncAllPending reads from these stores)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Queue a new note to be created when back online.
 * @param {string} tempId  — client-generated temp ID (e.g. "temp_1714000000000")
 * @param {object} data    — { title, content, note_color, labels, created_at_ts }
 */
export async function queueCreate(tempId, data) {
    try {
        const db = await getDB();
        await db.put(STORE_CREATES, { tempId, ...data, queued_at: Date.now() });
        // Also add to notes cache so UI shows it immediately
        await db.put(STORE_NOTES, {
            id:            tempId,
            title:         data.title        ?? '',
            content:       data.content      ?? '',
            note_color:    data.note_color   ?? '#ffffff',
            is_pinned:     false,
            has_password:  false,
            is_shared:     false,
            labels:        data.labels       ?? [],
            updated_at:    'Just now',
            created_at_ts: data.created_at_ts ?? Math.floor(Date.now() / 1000),
            syncStatus:    'pending_create',
            _pending:      true,
        });
    } catch (e) {
        console.warn('[IDB] queueCreate failed:', e);
    }
}

/**
 * Queue a note update to sync when back online.
 * @param {number|string} noteId
 * @param {object} data  — { title, content }
 */
export async function queueUpdate(noteId, data) {
    try {
        const db = await getDB();
        // Overwrite any existing queued update for this note (latest wins)
        await db.put(STORE_UPDATES, { noteId, ...data, queued_at: Date.now() });
        // Keep local cache fresh + mark as pending
        const existing = await db.get(STORE_NOTES, noteId);
        if (existing) {
            const nowSeconds = Math.floor(Date.now() / 1000);
            await db.put(STORE_NOTES, {
                ...existing,
                title:      data.title   ?? existing.title,
                content:    data.content ?? existing.content,
                updated_at: 'Pending sync…',
                updated_at_ts: nowSeconds,
                syncStatus: 'pending_update',
                _pending:   true,
                _localEditedAt: Date.now(),
            });
        }
    } catch (e) {
        console.warn('[IDB] queueUpdate failed:', e);
    }
}

/** Get all pending creates */
export async function getPendingCreates() {
    try {
        const db = await getDB();
        return await db.getAll(STORE_CREATES);
    } catch (e) {
        console.warn('[IDB] getPendingCreates failed:', e);
        return [];
    }
}

/** Get all pending updates */
export async function getPendingUpdates() {
    try {
        const db = await getDB();
        return await db.getAll(STORE_UPDATES);
    } catch (e) {
        console.warn('[IDB] getPendingUpdates failed:', e);
        return [];
    }
}

/** Remove a pending create after successful sync */
export async function removePendingCreate(tempId) {
    try {
        const db = await getDB();
        await db.delete(STORE_CREATES, tempId);
    } catch (e) {
        console.warn('[IDB] removePendingCreate failed:', e);
    }
}

/** Remove a pending update after successful sync */
export async function removePendingUpdate(noteId) {
    try {
        const db = await getDB();
        await db.delete(STORE_UPDATES, noteId);
    } catch (e) {
        console.warn('[IDB] removePendingUpdate failed:', e);
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// LABELS — cache labels for offline filtering
// ──────────────────────────────────────────────────────────────────────────────

export async function saveLabelsToIDB(labels) {
    try {
        const db = await getDB();
        const tx = db.transaction(STORE_LABELS, 'readwrite');
        await tx.store.clear();
        for (const label of labels) {
            await tx.store.put({ id: label.id, name: label.name, color: label.color });
        }
        await tx.done;
    } catch (e) {
        console.warn('[IDB] saveLabelsToIDB failed:', e);
    }
}

export async function getLabelsFromIDB() {
    try {
        const db = await getDB();
        return await db.getAll(STORE_LABELS);
    } catch (e) {
        console.warn('[IDB] getLabelsFromIDB failed:', e);
        return [];
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// PREFERENCES — cache user preferences
// ──────────────────────────────────────────────────────────────────────────────

export async function savePreferencesToIDB(prefs) {
    try {
        const db = await getDB();
        await db.put(STORE_PREFS, { key: 'user_prefs', ...prefs });
    } catch (e) {
        console.warn('[IDB] savePreferencesToIDB failed:', e);
    }
}

export async function getPreferencesFromIDB() {
    try {
        const db = await getDB();
        return await db.get(STORE_PREFS, 'user_prefs') ?? null;
    } catch (e) {
        console.warn('[IDB] getPreferencesFromIDB failed:', e);
        return null;
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// PROFILE — offline cache + pending update queue
// ──────────────────────────────────────────────────────────────────────────────

/** Save current profile data locally (called when online, key='current') */
export async function saveProfileToIDB(profile) {
    try {
        const db = await getDB();
        await db.put(STORE_PROFILE, { key: 'current', ...profile, savedAt: Date.now() });
    } catch (e) {
        console.warn('[IDB] saveProfileToIDB failed:', e);
    }
}

/** Get cached profile data */
export async function getProfileFromIDB() {
    try {
        const db = await getDB();
        return await db.get(STORE_PROFILE, 'current') ?? null;
    } catch (e) {
        console.warn('[IDB] getProfileFromIDB failed:', e);
        return null;
    }
}

/**
 * Queue a profile update to sync when back online.
 * @param {{ display_name: string, avatarDataUrl: string|null }} data
 */
export async function queueProfileUpdate(data) {
    try {
        const db = await getDB();
        await db.put(STORE_PROFILE, { key: 'pending', ...data, queuedAt: Date.now() });
    } catch (e) {
        console.warn('[IDB] queueProfileUpdate failed:', e);
    }
}

/** Get pending profile update (null if none) */
export async function getProfileQueue() {
    try {
        const db = await getDB();
        return await db.get(STORE_PROFILE, 'pending') ?? null;
    } catch (e) {
        console.warn('[IDB] getProfileQueue failed:', e);
        return null;
    }
}

/** Remove pending profile update after successful sync */
export async function clearProfileQueue() {
    try {
        const db = await getDB();
        await db.delete(STORE_PROFILE, 'pending');
    } catch (e) {
        console.warn('[IDB] clearProfileQueue failed:', e);
    }
}
