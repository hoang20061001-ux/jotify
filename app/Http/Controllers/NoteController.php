<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\NoteImage;
use App\Events\NoteContentUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class NoteController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $preferences = $user->preferences()->firstOrCreate([], [
            'font_size' => 'medium', 'note_color' => 'none',
            'theme' => 'light', 'view_mode' => 'grid',
        ]);

        $query = $user->notes()->with(['labels', 'images', 'shares']);

        // Live search
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filter by labels
        if ($request->filled('labels')) {
            $labelIds = is_array($request->labels) ? $request->labels : explode(',', $request->labels);
            $query->forLabel($labelIds);
        }

        $notes = $query->ordered()->get();
        $labels = $user->labels()->orderBy('name')->get();

        // For AJAX requests
        if ($request->ajax()) {
            return response()->json([
                'notes' => $notes->map(function($note) {
                    return [
                        'id'             => $note->id,
                        'title'          => $note->title,
                        'content'        => \Str::limit(strip_tags($note->content), 150),
                        'note_color'     => $note->note_color,
                        'is_pinned'      => $note->is_pinned,
                        'has_password'   => $note->has_password,
                        'is_shared'      => $note->shares->count() > 0,
                        'labels'         => $note->labels,
                        'updated_at'     => $note->updated_at->diffForHumans(),
                        'updated_at_ts'  => $note->updated_at->timestamp,
                        'created_at_ts'  => $note->created_at->timestamp,
                        'pinned_at_ts'   => $note->pinned_at?->timestamp ?? 0,
                        'first_image_url'=> $note->images->first()
                            ? asset('storage/' . $note->images->first()->image_path)
                            : null,
                        'images_urls'    => $note->images->map(fn($img) =>
                            asset('storage/' . $img->image_path)
                        )->values(),
                    ];
                }),
            ]);
        }

        return view('notes.index', compact('notes', 'labels', 'preferences'));
    }

    public function create(Request $request)
    {
        $user = Auth::user();
        $preferences = $user->preferences()->firstOrCreate([], [
            'font_size' => 'medium', 'note_color' => 'none',
            'theme' => 'light', 'view_mode' => 'grid',
        ]);

        $note = $user->notes()->create([
            'title'      => '',
            'content'    => '',
            'note_color' => $preferences->note_color,
        ]);

        // Return JSON for offline sync queue (POST with Accept: application/json)
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['id' => $note->id]);
        }

        return redirect('/notes/' . $note->id . '/edit');
    }

    public function edit($id)
    {
        $user = Auth::user();
        $note = $user->notes()->with(['labels', 'images', 'shares'])->findOrFail($id);

        // Check password protection
        if ($note->has_password && !session('note_unlocked_' . $note->id)) {
            return redirect('/notes')->with('password_required', $note->id);
        }

        $labels = $user->labels()->orderBy('name')->get();
        $preferences = $user->preferences()->firstOrCreate([], [
            'font_size' => 'medium', 'note_color' => 'none',
            'theme' => 'light', 'view_mode' => 'grid',
        ]);

        return view('notes.editor', compact('note', 'labels', 'preferences'));
    }

    public function autoSave(Request $request, $id)
    {
        $user = Auth::user();
        $note = $user->notes()->findOrFail($id);

        if ($note->has_password && !session('note_unlocked_' . $note->id)) {
            return response()->json(['error' => 'Note is locked'], 403);
        }

        $note->update($request->only(['title', 'content']));

        // Broadcast to shared users (only if note has edit-permission shares)
        try {
            if ($note->shares()->where('permission', 'edit')->exists()) {
                broadcast(new NoteContentUpdated(
                    $note->id,
                    $note->title ?? '',
                    $note->content ?? '',
                    $user->display_name,
                    $note->fresh()->updated_at->timezone('Asia/Ho_Chi_Minh')->format('M d, Y h:i A')
                ))->toOthers();
            }
        } catch (\Exception $e) {
            \Log::warning('Broadcast failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'updated_at' => $note->updated_at->timezone('Asia/Ho_Chi_Minh')->format('M d, Y h:i A'),
        ]);
    }

    // Return latest note content for owner's real-time collab polling
    public function collabLatest($id)
    {
        $user = Auth::user();
        $note = $user->notes()->findOrFail($id);

        return response()->json([
            'title'      => $note->title,
            'content'    => $note->content,
            'updated_at' => $note->updated_at->toISOString(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = Auth::user();
        $note = $user->notes()->findOrFail($id);

        if ($note->has_password && !session('note_unlocked_' . $note->id)) {
            return response()->json(['error' => 'Note is locked'], 403);
        }

        // Delete associated images from storage
        foreach ($note->images as $image) {
            Storage::disk('public')->delete($image->image_path);
        }

        $note->delete();

        if ($request->ajax()) {
            return response()->json(['success' => true]);
        }

        return redirect('/notes')->with('success', 'Note deleted successfully.');
    }

    public function togglePin($id)
    {
        $user = Auth::user();
        $note = $user->notes()->findOrFail($id);

        $newPinnedState = !$note->is_pinned;

        // Không cập nhật updated_at khi pin/unpin — chỉ thay đổi content mới tính
        $note->timestamps = false;
        $note->update([
            'is_pinned' => $newPinnedState,
            'pinned_at' => $newPinnedState ? now() : null,
        ]);
        $note->timestamps = true;

        return response()->json([
            'success'      => true,
            'is_pinned'    => (bool) $newPinnedState,
            'pinned_at_ts' => $newPinnedState ? now()->timestamp : 0,
        ]);
    }

    // Image attachment
    public function uploadImage(Request $request, $id)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        $user = Auth::user();
        $note = $user->notes()->findOrFail($id);

        $path = $request->file('image')->store('note-images/' . $note->id, 'public');
        $maxSort = $note->images()->max('sort_order') ?? 0;

        $image = NoteImage::create([
            'note_id' => $note->id,
            'image_path' => $path,
            'original_name' => $request->file('image')->getClientOriginalName(),
            'sort_order' => $maxSort + 1,
        ]);

        return response()->json([
            'success' => true,
            'image' => [
                'id' => $image->id,
                'url' => asset('storage/' . $image->image_path),
                'original_name' => $image->original_name,
            ],
        ]);
    }

    public function deleteImage($noteId, $imageId)
    {
        $user = Auth::user();
        $note = $user->notes()->findOrFail($noteId);
        $image = $note->images()->findOrFail($imageId);

        Storage::disk('public')->delete($image->image_path);
        $image->delete();

        return response()->json(['success' => true]);
    }

    // Label management for notes
    public function updateLabels(Request $request, $id)
    {
        $user = Auth::user();
        $note = $user->notes()->findOrFail($id);

        $labelIds = $request->input('labels', []);
        // Verify labels belong to user
        $validLabels = $user->labels()->whereIn('id', $labelIds)->pluck('id');
        $note->labels()->sync($validLabels);

        return response()->json([
            'success' => true,
            'labels' => $note->labels()->get(),
        ]);
    }

    // Password protection
    public function setPassword(Request $request, $id)
    {
        $request->validate([
            'password' => 'required|string|min:4|confirmed',
        ]);

        $user = Auth::user();
        $note = $user->notes()->findOrFail($id);

        $note->timestamps = false;
        $note->update([
            'has_password' => true,
            'note_password' => Hash::make($request->password),
        ]);
        $note->timestamps = true;

        session(['note_unlocked_' . $note->id => true]);

        return response()->json(['success' => true, 'message' => 'Password protection enabled.']);
    }

    public function unlockNote(Request $request, $id)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = Auth::user();
        $note = $user->notes()->findOrFail($id);

        if (!$note->has_password) {
            return response()->json(['success' => true]);
        }

        if (!Hash::check($request->password, $note->note_password)) {
            return response()->json(['error' => 'Incorrect password.'], 422);
        }

        session(['note_unlocked_' . $note->id => true]);

        return response()->json(['success' => true]);
    }

    public function changeNotePassword(Request $request, $id)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:4|confirmed',
        ]);

        $user = Auth::user();
        $note = $user->notes()->findOrFail($id);

        if (!Hash::check($request->current_password, $note->note_password)) {
            return response()->json(['error' => 'Current password is incorrect.'], 422);
        }

        $note->timestamps = false;
        $note->update([
            'note_password' => Hash::make($request->password),
        ]);
        $note->timestamps = true;

        return response()->json(['success' => true, 'message' => 'Note password changed.']);
    }

    public function removePassword(Request $request, $id)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = Auth::user();
        $note = $user->notes()->findOrFail($id);

        if (!Hash::check($request->password, $note->note_password)) {
            return response()->json(['error' => 'Password is incorrect.'], 422);
        }

        $note->timestamps = false;
        $note->update([
            'has_password' => false,
            'note_password' => null,
        ]);
        $note->timestamps = true;

        session()->forget('note_unlocked_' . $note->id);

        return response()->json(['success' => true, 'message' => 'Password protection removed.']);
    }
}
