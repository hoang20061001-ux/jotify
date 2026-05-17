<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\NoteImage;
use App\Models\NoteShare;
use App\Models\User;
use App\Models\Notification;
use App\Events\NoteContentUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ShareController extends Controller
{
    // Show shared with me notes
    public function sharedWithMe()
    {
        $user = Auth::user();
        $sharedNotes = NoteShare::where('recipient_id', $user->id)
            ->with(['note.images', 'owner'])
            ->get()
            // Order by note's updated_at so recently-edited notes surface first
            ->sortByDesc(fn($s) => $s->note?->updated_at ?? $s->shared_at)
            ->values();

        $preferences = $user->preferences()->firstOrCreate([], [
            'font_size' => 'medium', 'note_color' => '#ffffff',
            'theme' => 'light', 'view_mode' => 'grid',
        ]);

        return view('notes.shared', compact('sharedNotes', 'preferences'));
    }

    // Share a note
    public function share(Request $request, $noteId)
    {
        // Release the PHP session lock immediately — on XAMPP/mod_php, concurrent AJAX
        // requests (e.g. autosave) hold the session file lock for their entire duration.
        // Without this, the share request queues behind autosave and appears to hang.
        session()->save();

        $request->validate([
            'email' => 'required|email',
            'permission' => 'required|in:read,edit',
        ]);


        $user = Auth::user();
        $note = $user->notes()->findOrFail($noteId);

        // Can't share with self
        if ($request->email === $user->email) {
            return response()->json(['error' => 'You cannot share a note with yourself.'], 422);
        }

        // Find recipient
        $recipient = User::where('email', $request->email)->first();
        if (!$recipient) {
            return response()->json(['error' => 'No account found with this email address.'], 422);
        }

        // No verification requirement — email existence is sufficient

        // Check if already shared
        $existing = NoteShare::where('note_id', $note->id)
            ->where('recipient_id', $recipient->id)
            ->first();

        if ($existing) {
            $existing->update([
                'permission' => $request->permission,
            ]);
            $share = $existing;
        } else {
            $share = NoteShare::create([
                'note_id'      => $note->id,
                'owner_id'     => $user->id,
                'recipient_id' => $recipient->id,
                'permission'   => $request->permission,
                'shared_at'    => now(),
            ]);

            // Create notification only for NEW shares
            Notification::create([
                'user_id' => $recipient->id,
                'type'    => 'note_shared',
                'message' => $user->display_name . ' shared a note "' . ($note->title ?: 'Untitled') . '" with you.',
                'data'    => [                      // ← array trực tiếp, không json_encode thủ công
                    'note_id'    => $note->id,      //   Eloquent cast 'array' tự encode 1 lần
                    'share_id'   => $share->id,
                    'permission' => $request->permission,
                ],
            ]);
        }

        // Email notification is intentionally omitted from this request cycle.
        // On Apache/mod_php (XAMPP), there is no true async — any SMTP call blocks
        // the browser response regardless of register_shutdown_function or afterResponse().
        // The in-app notification (bell icon) already notifies the recipient in real-time.

        return response()->json([
            'success' => true,
            'share' => $share->load('recipient'),
        ]);
    }

    // Get share details for a note
    public function getShares($noteId)
    {
        $user = Auth::user();
        $note = $user->notes()->findOrFail($noteId);

        $shares = $note->shares()->with('recipient')->get();

        return response()->json($shares);
    }

    // Update share permission
    public function updatePermission(Request $request, $shareId)
    {
        $request->validate([
            'permission' => 'required|in:read,edit',
        ]);

        $user = Auth::user();
        $share = NoteShare::where('owner_id', $user->id)->findOrFail($shareId);

        $share->update(['permission' => $request->permission]);

        return response()->json(['success' => true, 'share' => $share]);
    }

    // Revoke share
    public function revoke($shareId)
    {
        $user = Auth::user();
        $share = NoteShare::where('owner_id', $user->id)->findOrFail($shareId);

        $recipientId = $share->recipient_id;
        $share->delete();

        // Xóa notifications liên quan đến share này khỏi inbox của recipient
        // Tránh notification "zombie" dẫn đến trang not-found
        Notification::where('user_id', $recipientId)
            ->where('type', 'note_shared')
            ->get()
            ->each(function ($n) use ($shareId) {
                $d = $n->data;
                if (is_string($d)) $d = json_decode($d, true);
                if (is_string($d)) $d = json_decode($d, true); // double-encoded legacy
                if (($d['share_id'] ?? null) == $shareId) {
                    $n->delete();
                }
            });

        return response()->json(['success' => true]);
    }

    // View a shared note (as recipient)
    public function viewSharedNote($shareId)
    {
        $user = Auth::user();

        // Case 1: share record không tồn tại hoặc bị revoke
        $share = NoteShare::where('recipient_id', $user->id)
            ->with(['note.images', 'note.labels', 'owner'])
            ->find($shareId);

        if (!$share) {
            return redirect('/shared')
                ->with('toast_error', 'You no longer have access to this note. The owner may have revoked your access.');
        }

        // Case 2: note đã bị xóa nhưng share record vẫn còn
        if (!$share->note) {
            return redirect('/shared')
                ->with('toast_error', 'This note has been deleted by its owner.');
        }

        // ─── Password gate: if note is password-protected, require unlock ──────────
        if ($share->note->has_password && !session('shared_note_unlocked_' . $share->id)) {
            // Return to shared index with a flash that opens the password modal
            return redirect('/shared')
                ->with('shared_password_required', $share->id);
        }

        $preferences = $user->preferences()->firstOrCreate([], [
            'font_size' => 'medium', 'note_color' => '#ffffff',
            'theme' => 'light', 'view_mode' => 'grid',
        ]);

        return view('notes.shared-editor', compact('share', 'preferences'));
    }

    // Unlock a shared note that is password-protected
    public function unlockSharedNote(Request $request, $shareId)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user  = Auth::user();
        $share = NoteShare::where('recipient_id', $user->id)
            ->with('note')
            ->findOrFail($shareId);

        if (!$share->note) {
            return response()->json(['error' => 'Note not found.'], 404);
        }

        // Note has no password — nothing to unlock
        if (!$share->note->has_password) {
            return response()->json(['success' => true]);
        }

        if (!Hash::check($request->password, $share->note->note_password)) {
            return response()->json(['error' => 'Incorrect password.'], 422);
        }

        // Mark as unlocked for this session (scoped to the share, not the note)
        session(['shared_note_unlocked_' . $share->id => true]);

        return response()->json(['success' => true]);
    }

    // Auto-save for shared note with edit permission
    public function autoSaveShared(Request $request, $shareId)
    {
        $request->validate([
            'title'   => 'nullable|string|max:255',
            'content' => 'nullable|string',
        ]);

        $user  = Auth::user();
        $share = NoteShare::where('recipient_id', $user->id)
            ->where('permission', 'edit')
            ->with('note')
            ->findOrFail($shareId);

        if (!$share->note) {
            return response()->json(['error' => 'Note not found.'], 404);
        }

        $note = $share->note;

        // Build update payload — only include fields actually sent in the request.
        // Using input('title', '') would overwrite existing content with an empty
        // string if Alpine.js fires autoSave() before it has populated the model.
        $payload = [];
        if ($request->has('title'))   $payload['title']   = $request->input('title', '');
        if ($request->has('content')) $payload['content'] = $request->input('content', '');

        // Nothing to save — skip to avoid a no-op update that resets timestamps
        if (empty($payload)) {
            return response()->json([
                'success'    => true,
                'updated_at' => $note->updated_at->timezone('Asia/Ho_Chi_Minh')->format('M d, Y h:i A'),
            ]);
        }

        // Guard: never overwrite both fields with empty strings simultaneously
        // (happens when Alpine hasn't bound the server data yet)
        $titleEmpty   = isset($payload['title'])   && trim($payload['title'])   === '';
        $contentEmpty = isset($payload['content']) && trim($payload['content']) === '';
        if ($titleEmpty && $contentEmpty && ($note->title || $note->content)) {
            return response()->json([
                'success'    => true,
                'updated_at' => $note->updated_at->timezone('Asia/Ho_Chi_Minh')->format('M d, Y h:i A'),
            ]);
        }

        $note->update($payload);

        // Broadcast to owner (and other collaborators) via Pusher
        try {
            broadcast(new NoteContentUpdated(
                $note->id,
                $note->title ?? '',
                $note->content ?? '',
                $user->display_name,
                $note->fresh()->updated_at->timezone('Asia/Ho_Chi_Minh')->format('M d, Y h:i A')
            ))->toOthers();
        } catch (\Exception $e) {
            \Log::warning('Broadcast failed: ' . $e->getMessage());
        }

        return response()->json([
            'success'    => true,
            'updated_at' => $note->fresh()->updated_at->timezone('Asia/Ho_Chi_Minh')->format('M d, Y h:i A'),
        ]);
    }

    // ─── Image upload for recipient with edit permission ──────────────────────
    public function uploadSharedImage(Request $request, $shareId)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        $user  = Auth::user();
        $share = NoteShare::where('recipient_id', $user->id)
            ->where('permission', 'edit')
            ->with('note')
            ->findOrFail($shareId);

        $note = $share->note;
        $path = $request->file('image')->store('note-images/' . $note->id, 'public');
        $maxSort = $note->images()->max('sort_order') ?? 0;

        $image = NoteImage::create([
            'note_id'       => $note->id,
            'image_path'    => $path,
            'original_name' => $request->file('image')->getClientOriginalName(),
            'sort_order'    => $maxSort + 1,
        ]);

        $note->touch();

        return response()->json([
            'success' => true,
            'image'   => [
                'id'            => $image->id,
                'url'           => asset('storage/' . $image->image_path),
                'original_name' => $image->original_name,
            ],
        ]);
    }

    // ─── Image delete for recipient with edit permission ───────────────────────
    public function deleteSharedImage($shareId, $imageId)
    {
        $user  = Auth::user();
        $share = NoteShare::where('recipient_id', $user->id)
            ->where('permission', 'edit')
            ->with('note')
            ->findOrFail($shareId);

        $image = $share->note->images()->findOrFail($imageId);
        Storage::disk('public')->delete($image->image_path);
        $image->delete();

        return response()->json(['success' => true]);
    }

    // Get latest content for real-time collaboration polling
    public function getLatestShared($shareId)
    {
        $user = Auth::user();

        // Allow both the recipient AND the owner to poll for the latest content
        $share = NoteShare::where(function ($q) use ($user) {
                $q->where('recipient_id', $user->id)
                  ->orWhere('owner_id', $user->id);
            })
            ->with('note')
            ->findOrFail($shareId);

        return response()->json([
            'title'      => $share->note->title,
            'content'    => $share->note->content,
            'updated_at' => $share->note->updated_at->toISOString(),
        ]);
    }

    // Notifications
    public function notifications()
    {
        $user = Auth::user();
        $notifications = $user->appNotifications()
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return response()->json($notifications);
    }

    public function markNotificationRead($id)
    {
        $user = Auth::user();
        $notification = $user->appNotifications()->findOrFail($id);
        $notification->update(['is_read' => true]);

        return response()->json(['success' => true]);
    }

    public function unreadCount()
    {
        $count = Auth::user()->appNotifications()->where('is_read', false)->count();
        return response()->json(['count' => $count]);
    }
}
