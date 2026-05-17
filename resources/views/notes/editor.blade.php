@extends('layouts.app')
@section('title', ($note->title ?: 'New Note') . ' - JOTIFY')

@section('header')
<div class="flex items-center gap-3 flex-1">
    <a href="/notes" class="p-2 rounded-lg hover:bg-hover transition-colors header-icon-btn">
        <span class="material-icons-outlined" style="color:var(--color-muted);">arrow_back</span>
    </a>
    <span id="save-status" class="text-xs text-muted flex items-center gap-1">
        <span class="material-icons-outlined text-sm text-emerald-500">cloud_done</span>
        Saved
    </span>
    {{-- Live collaboration indicator (shown only when note is shared with edit) --}}
    @if($note->shares->where('permission','edit')->count() > 0)
    <span id="collab-live" class="hidden ml-2 inline-flex items-center gap-1 text-xs text-blue-500">
        <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
        Collaborator editing…
    </span>
    @endif
</div>
<div class="flex items-center gap-1 sm:gap-2">
    {{-- Pin toggle --}}
    <button id="pin-btn" onclick="togglePinEditor()"
            class="hidden sm:flex p-2 rounded-lg hover:bg-hover transition-colors"
            title="{{ $note->is_pinned ? 'Unpin' : 'Pin' }}">
        <span class="material-icons-outlined" style="color:{{ $note->is_pinned ? '#f59e0b' : 'var(--color-muted)' }};" id="pin-icon">push_pin</span>
    </button>

    {{-- Password protection --}}
    <div class="relative hidden sm:flex" x-data="{ open: false }" id="pass-protect-wrap">
        <button id="lock-btn" @click="open=!open"
                class="p-2 rounded-lg hover:bg-hover transition-colors"
                title="Password Protection">
            <span class="material-icons-outlined" style="color:{{ $note->has_password ? '#ef4444' : 'var(--color-muted)' }};">{{ $note->has_password ? 'lock' : 'lock_open' }}</span>
        </button>
        {{-- Dropdown --}}
        <div x-show="open" @click.outside="open=false"
             class="pass-dropdown bg-card rounded-xl shadow-2xl border border-border p-4 z-50"
             style="display:none;">
            @if($note->has_password)
                <p class="text-sm font-medium mb-3">Password Protection is ON</p>
                <button onclick="showChangePasswordForm()" class="btn-secondary w-full text-sm mb-2">Change Password</button>
                <button onclick="showRemovePasswordForm()" class="btn-danger w-full text-sm">Remove Password</button>
            @else
                <p class="text-sm font-medium mb-3">Set Password Protection</p>
                <form id="set-password-form" onsubmit="setNotePassword(event)">
                    <p class="text-xs text-muted mb-1">Min. 4 characters</p>
                    <input type="password" id="new-note-pass" class="form-input w-full text-sm mb-2" placeholder="Enter password" required>
                    <input type="password" id="confirm-note-pass" class="form-input w-full text-sm mb-2" placeholder="Confirm password" required>
                    <p id="set-pass-error" class="text-red-500 text-xs mb-2 hidden"></p>
                    <button type="submit" class="btn-primary w-full text-sm">Enable Protection</button>
                </form>
            @endif
        </div>
    </div>

    {{-- Password protection (mobile modal — same style as Share modal) --}}
    <div id="pass-mobile-overlay"
         style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);z-index:9998;align-items:center;justify-content:center;padding:1rem;box-sizing:border-box;"
         onclick="closePassMobile()">
        <div style="background:var(--color-card);border-radius:1rem;box-shadow:0 25px 50px rgba(0,0,0,0.25);border:1px solid var(--color-border);width:100%;max-width:22rem;overflow:hidden;"
             onclick="event.stopPropagation()">

            {{-- Header --}}
            <div style="padding:1rem 1.25rem 0.875rem;border-bottom:1px solid var(--color-border);display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:0.625rem;">
                    <div style="width:34px;height:34px;border-radius:10px;background:rgba(239,68,68,0.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <span class="material-icons-outlined" style="font-size:1.1rem;color:#ef4444;">lock</span>
                    </div>
                    <div>
                        <h3 style="font-size:0.9rem;font-weight:700;margin:0;color:var(--color-body-text);">Password Protection</h3>
                        <p style="font-size:0.7rem;color:var(--color-muted);margin:0;">{{ $note->has_password ? 'Currently enabled' : 'Protect this note' }}</p>
                    </div>
                </div>
                <button onclick="closePassMobile()" style="width:28px;height:28px;border-radius:8px;border:none;background:var(--color-hover);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--color-muted);">
                    <span class="material-icons-outlined" style="font-size:1rem;">close</span>
                </button>
            </div>

            {{-- Body --}}
            <div style="padding:1rem 1.25rem 1.25rem;">
                @if($note->has_password)
                    <p style="font-size:0.8rem;color:var(--color-muted);margin-bottom:0.875rem;">This note is currently password protected.</p>
                    <button onclick="showChangePasswordForm(); closePassMobile()" class="btn-secondary w-full text-sm mb-2">Change Password</button>
                    <button onclick="showRemovePasswordForm(); closePassMobile()" class="btn-danger w-full text-sm">Remove Password</button>
                @else
                    <form onsubmit="setNotePassword(event)">
                        <p style="font-size:0.7rem;color:var(--color-muted);margin-bottom:0.5rem;">Min. 4 characters</p>
                        <input type="password" id="new-note-pass-m" class="form-input w-full text-sm mb-2" placeholder="Enter password" required>
                        <input type="password" id="confirm-note-pass-m" class="form-input w-full text-sm mb-3" placeholder="Confirm password" required>
                        <p id="set-pass-error-m" class="text-red-500 text-xs mb-2 hidden"></p>
                        <button type="submit" class="btn-primary w-full text-sm">Enable Protection</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <style>
    /* Desktop: absolute dropdown */
    .pass-dropdown {
        position: absolute;
        right: 0;
        top: calc(100% + 8px);
        width: 18rem;
    }
    @media (max-width: 639px) {
        .pass-dropdown { position: fixed; left: 1rem; right: 1rem; top: 64px; width: auto; }
    }

    /* ── Icon button animations ── */
    @keyframes pin-pop {
        0%   { transform: scale(1) rotate(0deg); }
        30%  { transform: scale(1.4) rotate(-20deg); }
        60%  { transform: scale(0.85) rotate(10deg); }
        100% { transform: scale(1) rotate(0deg); }
    }
    @keyframes lock-pulse {
        0%   { filter: drop-shadow(0 0 0px rgba(239,68,68,0)); }
        50%  { filter: drop-shadow(0 0 6px rgba(239,68,68,0.7)); }
        100% { filter: drop-shadow(0 0 0px rgba(239,68,68,0)); }
    }
    @keyframes share-bounce {
        0%   { transform: scale(1) translateY(0); }
        35%  { transform: scale(1.25) translateY(-4px); }
        65%  { transform: scale(0.9) translateY(1px); }
        100% { transform: scale(1) translateY(0); }
    }
    .pin-animate  { animation: pin-pop    0.45s cubic-bezier(0.34,1.56,0.64,1) forwards; }
    .lock-animate { animation: lock-pulse 0.6s ease forwards; }
    .share-animate{ animation: share-bounce 0.4s cubic-bezier(0.34,1.56,0.64,1) forwards; }

    /* Icon buttons: spring scale on active (touch-safe) */
    #pass-protect-wrap button,
    button[onclick*="togglePin"],
    button[onclick*="openShare"] {
        transition: transform 0.25s cubic-bezier(0.34,1.56,0.64,1);
    }
    @media (hover: hover) {
        #pass-protect-wrap button:hover { transform: scale(1.15); }
        button[onclick*="togglePin"]:hover  { transform: scale(1.15) rotate(-12deg); }
        button[onclick*="openShare"]:hover  { transform: scale(1.15) translateY(-2px); }
    }
    button[onclick*="togglePin"]:active,
    button[onclick*="openShare"]:active,
    #pass-protect-wrap button:active { transform: scale(0.88); transition-duration: 0.08s; }

    /* More-actions menu items: slide-in micro animation */
    @keyframes menu-item-in {
        from { opacity: 0; transform: translateX(6px); }
        to   { opacity: 1; transform: translateX(0); }
    }

    /* Mobile menu item click effect — matches the header icon buttons */
    .sm\:hidden button,
    button[onclick*="confirmDelete"] {
        transition: background 0.12s ease, transform 0.15s cubic-bezier(0.34,1.56,0.64,1);
    }
    .sm\:hidden button:active,
    button[onclick*="confirmDelete"]:active {
        transform: scale(0.94);
        transition-duration: 0.06s;
    }
    </style>


    {{-- Share --}}
    <button onclick="openShareModal()" class="hidden sm:flex p-2 rounded-lg hover:bg-hover transition-colors" title="Share">
        <span class="material-icons-outlined {{ $note->shares && $note->shares->count() > 0 ? 'text-blue-500' : 'text-muted' }}">share</span>
    </button>

    {{-- More actions --}}
    <div class="relative" x-data="{ open: false }">
        <button @click="open=!open" class="p-2 rounded-lg hover:bg-hover transition-colors header-icon-btn">
            <span class="material-icons-outlined" style="color:var(--color-muted);">more_vert</span>
        </button>
        <div x-show="open" @click.outside="open=false" class="absolute right-0 mt-2 w-52 bg-card rounded-xl shadow-2xl border border-border overflow-hidden z-50" style="display:none;">
            {{-- Mobile-only actions --}}
            <div class="sm:hidden">
                <button onclick="togglePinEditor(); open=false" class="w-full flex items-center gap-2 px-4 py-2.5 text-sm hover:bg-hover transition-colors">
                    <span class="material-icons-outlined text-lg {{ $note->is_pinned ? 'text-amber-500' : '' }}" id="pin-icon-mobile">push_pin</span>
                    {{ $note->is_pinned ? 'Unpin' : 'Pin' }} Note
                </button>
                <button onclick="openPassMobile(); open=false" class="w-full flex items-center gap-2 px-4 py-2.5 text-sm hover:bg-hover transition-colors">
                    <span class="material-icons-outlined text-lg {{ $note->has_password ? 'text-red-500' : '' }}">{{ $note->has_password ? 'lock' : 'lock_open' }}</span>
                    Password
                </button>
                <button onclick="openShareModal(); open=false" class="w-full flex items-center gap-2 px-4 py-2.5 text-sm hover:bg-hover transition-colors">
                    <span class="material-icons-outlined text-lg {{ $note->shares && $note->shares->count() > 0 ? 'text-blue-500' : '' }}">share</span>
                    Share
                </button>
                <div class="border-t border-border"></div>
            </div>
            <button onclick="confirmDeleteEditor()" class="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-red-500 hover:bg-red-500/10 transition-colors">
                <span class="material-icons-outlined text-lg">delete</span>
                Delete Note
            </button>
        </div>
    </div>
</div>
@endsection

@section('content')
<div class="max-w-4xl mx-auto" x-data="noteEditor()">
    {{-- Title --}}
    <input type="text" x-model="title" @input.debounce.1000ms="autoSave()"
           value="{{ $note->title }}"
           class="w-full font-bold bg-transparent border-none outline-none placeholder:text-muted/50 mb-4"
           style="font-size: {{ ['small'=>'1.25rem','medium'=>'1.5rem','large'=>'1.75rem','x-large'=>'2rem'][$preferences->font_size] }}; line-height:1.3;"
           placeholder="Note title..." id="note-title">

    {{-- Labels --}}
    <div class="flex flex-wrap items-center gap-2 mb-6" id="editor-labels-wrap">
        @foreach($note->labels as $label)
        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium text-white note-label"
              style="background-color: {{ $label->color }}" data-label-id="{{ $label->id }}">
            {{ $label->name }}
            <button onclick="removeLabel({{ $label->id }})" class="hover:opacity-70" type="button">&times;</button>
        </span>
        @endforeach

        {{-- Add Label dropdown — plain JS, không dùng Alpine để tránh init race condition sau AJAX nav --}}
        <div class="relative" id="label-dropdown-wrap">
            <button type="button"
                    onclick="toggleLabelDropdown()"
                    id="add-label-btn"
                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-hover text-muted hover:text-body border border-dashed border-border transition-colors">
                <span class="material-icons-outlined text-sm">add</span>
                Add Label
            </button>
            <div id="label-dropdown"
                 class="absolute left-0 mt-2 w-52 bg-card rounded-xl shadow-2xl border border-border overflow-hidden z-50"
                 style="display:none;">
                <div class="p-2">
                    <input type="text" id="label-search"
                           class="w-full text-xs bg-hover rounded-lg px-3 py-2 border border-border"
                           placeholder="Search labels..."
                           oninput="filterLabels(this.value)">
                </div>
                <div class="max-h-40 overflow-y-auto" id="label-list">
                    @foreach($labels as $label)
                    <button type="button"
                            onclick="toggleLabel({{ $label->id }})"
                            id="label-btn-{{ $label->id }}"
                            class="w-full flex items-center gap-2 px-3 py-2 text-xs hover:bg-hover transition-colors"
                            data-label-name="{{ strtolower($label->name) }}">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color: {{ $label->color }}"></span>
                        <span>{{ $label->name }}</span>
                        <span class="material-icons-outlined text-sm ml-auto label-check {{ $note->labels->contains($label->id) ? '' : 'hidden' }}"
                              style="color:var(--accent-dim)">check</span>
                    </button>
                    @endforeach
                    @if($labels->isEmpty())
                    <p class="text-xs text-muted px-3 py-2">No labels yet. Create labels in Settings.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Content --}}
    <textarea x-model="content" @input.debounce.1500ms="autoSave()"
              class="w-full min-h-[400px] bg-transparent border-none outline-none resize-none text-body placeholder:text-muted/50 leading-relaxed"
              placeholder="Start writing..." id="note-content"
              style="font-size: {{ ['small'=>'14px','medium'=>'16px','large'=>'18px','x-large'=>'20px'][$preferences->font_size] }}">{{ $note->content ?? '' }}</textarea>

    {{-- Image attachments --}}
    <div class="mt-6 border-t border-border pt-6">
        <div class="flex items-center justify-between mb-4">
            <h4 class="text-sm font-semibold flex items-center gap-2">
                <span class="material-icons-outlined text-lg" style="color:var(--color-muted);">image</span>
                Attachments
            </h4>
            <label class="btn-secondary text-sm cursor-pointer">
                <span class="material-icons-outlined text-lg">add_photo_alternate</span>
                Add Image
                <input type="file" accept="image/*" multiple class="hidden" onchange="uploadImages(this.files)" id="image-upload">
            </label>
        </div>
        <div id="images-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
            @foreach($note->images as $image)
            <div class="relative group rounded-xl overflow-hidden bg-hover" id="image-{{ $image->id }}">
                <button type="button" class="block w-full h-full" data-url="{{ asset('storage/' . $image->image_path) }}" data-name="{{ $image->original_name }}" onclick="openImageViewerFromDataset(this); event.stopPropagation();">
                    <img src="{{ asset('storage/' . $image->image_path) }}" class="w-full h-32 object-cover" alt="{{ $image->original_name }}">
                </button>
                <div class="attachment-overlay absolute inset-0 bg-black/50 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity flex items-center justify-center pointer-events-none">
                    <button onclick="deleteImage({{ $image->id }})" class="p-2 rounded-full bg-red-500 text-white hover:bg-red-600 transition-colors pointer-events-auto">
                        <span class="material-icons-outlined text-lg">delete</span>
                    </button>
                </div>
                <p class="absolute bottom-0 left-0 right-0 bg-black/60 text-white text-[10px] px-2 py-1 truncate">{{ $image->original_name }}</p>
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- Share modal --}}
<!-- Image viewer modal (in-page) -->
<div id="image-viewer" class="hidden fixed top-0 left-0 right-0 bottom-0 z-50 overflow-y-auto bg-black/70" aria-hidden="true" onclick="if(event.target.id==='image-viewer') closeImageViewer();">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="max-w-[95vw] w-full">
            <div class="bg-card rounded-xl overflow-hidden shadow-xl flex flex-col max-h-[95vh]">
                <div class="flex items-center justify-between px-4 py-3 border-b border-border flex-shrink-0">
                    <div id="image-viewer-title" class="text-sm font-semibold text-body truncate"></div>
                    <button type="button" onclick="closeImageViewer()" class="p-3 rounded-full hover:bg-muted/10 transition-colors flex-shrink-0" aria-label="Close image viewer">
                        <span class="material-icons-outlined text-lg">close</span>
                    </button>
                </div>
                <div class="flex-1 overflow-hidden p-4 flex items-center justify-center min-h-0">
                    <img id="image-viewer-img" src="" alt="" class="viewer-img max-w-full max-h-full object-contain">
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function openImageViewer(url, name) {
    const modal = document.getElementById('image-viewer');
    const img = document.getElementById('image-viewer-img');
    const title = document.getElementById('image-viewer-title');
    if (!modal || !img) return;
    img.src = url;
    img.alt = name || '';
    title.textContent = name || '';
    // Teleport lên <body> để thoát khỏi bất kỳ containing block nào
    // có will-change:transform (SPA page-content), tránh position:fixed
    // bị giới hạn không phủ hết viewport trên iPad/tablet.
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
}
function openImageViewerFromDataset(el) {
    if (!el) return;
    const url = el.dataset?.url || el.getAttribute('data-url');
    const name = el.dataset?.name || el.getAttribute('data-name');
    openImageViewer(url, name);
}
function closeImageViewer() {
    const modal = document.getElementById('image-viewer');
    const img = document.getElementById('image-viewer-img');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden','true');
    if (img) img.src = '';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeImageViewer(); });
</script>
<style>
.viewer-img {
    max-width: 90vw;
    max-height: 85vh;
}
@media (min-width: 1024px) {
    .viewer-img {
        max-width: 70vw;
        max-height: 70vh;
    }
}
@media (min-width: 1280px) {
    .viewer-img {
        max-width: 60vw;
        max-height: 60vh;
    }
}
@media (min-width: 1536px) {
    .viewer-img {
        max-width: 50vw;
        max-height: 50vh;
    }
}

.attachment-overlay {
    opacity: 0;
    transition: opacity 0.18s ease;
}
.group:hover .attachment-overlay {
    opacity: 1;
}
@media (hover: none), (pointer: coarse) {
    .attachment-overlay {
        opacity: 1 !important;
    }
}

/* ─── Custom permission dropdown ────────────────────────────────────────── */
.cpd-wrap {
    position: relative;
    user-select: none;
}
.cpd-trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 0.5rem 0.75rem;
    background: var(--color-input-bg);
    border: 1.5px solid var(--color-input-border);
    border-radius: 0.75rem;
    font-size: 0.875rem;
    color: var(--color-body-text);
    cursor: pointer;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}
.cpd-trigger:hover   { border-color: var(--accent-dim); }
.cpd-trigger.open    { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-subtle); }
.cpd-chevron {
    font-size: 1.1rem;
    color: var(--accent-dim);
    transition: transform 0.22s cubic-bezier(0.34, 1.3, 0.64, 1);
    flex-shrink: 0;
}
.cpd-trigger.open .cpd-chevron { transform: rotate(180deg); }

/* Dropdown list — cuộn ra từ trên xuống */
.cpd-list {
    position: absolute;
    top: calc(100% + 5px);
    left: 0;
    right: 0;
    background: var(--color-card);
    border: 1.5px solid var(--accent-border);
    border-radius: 0.75rem;
    overflow: hidden;
    z-index: 100;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    /* Cuộn ra: clip từ top + max-height */
    max-height: 0;
    clip-path: inset(0 0 100% 0 round 0.75rem);
    opacity: 0;
    transition:
        max-height 0.28s cubic-bezier(0.4, 0, 0.2, 1),
        clip-path  0.26s cubic-bezier(0.4, 0, 0.2, 1),
        opacity    0.18s ease;
    pointer-events: none;
}
.cpd-list.open {
    max-height: 200px;
    clip-path: inset(0 0 0% 0 round 0.75rem);
    opacity: 1;
    pointer-events: auto;
}
.cpd-option {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 0.875rem;
    font-size: 0.875rem;
    color: var(--color-body-text);
    cursor: pointer;
    transition: background 0.12s ease, color 0.12s ease;
}
.cpd-option:not(:last-child) { border-bottom: 1px solid var(--color-border); }
.cpd-option:hover   { background: var(--color-hover); color: var(--accent-dim); }
.cpd-option.selected {
    color: var(--accent-dim);
    font-weight: 600;
}
.cpd-option .cpd-check {
    font-size: 0.95rem;
    color: var(--accent-dim);
    margin-left: auto;
    opacity: 0;
    transition: opacity 0.12s ease;
}
.cpd-option.selected .cpd-check { opacity: 1; }
</style>

<style>
/* ─── Share modal — smooth open/close ──────────────────────────────────── */
#share-modal {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;   /* explicit — không dùng inset để tránh bug mobile */
    padding: 1rem;
    z-index: 9999;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    opacity: 0;
    transition: opacity 0.2s ease;
    box-sizing: border-box;
}
#share-modal.share-modal-visible {
    display: flex;
}
#share-modal.share-modal-open {
    opacity: 1;
    pointer-events: auto;
}
#share-modal .share-modal-box {
    transform: scale(0.96);
    opacity: 0;
    transform-origin: center center;
    will-change: transform, opacity;
    overflow: hidden;
    border-radius: 1rem;
    max-width: 28rem;
    width: 100%;
    max-height: calc(100vh - 2rem);   /* không tràn dọc trên mobile */
    overflow-y: auto;
    transition:
        transform 0.25s cubic-bezier(0.34, 1.3, 0.64, 1),
        opacity   0.2s ease;
}
#share-modal.share-modal-open .share-modal-box {
    transform: scale(1);
    opacity: 1;
}

/* Permission + Send row — responsive */
.share-perm-row {
    display: flex;
    gap: 0.625rem;
    align-items: flex-end;
}
.share-perm-row .cpd-wrap {
    flex: 1;
    min-width: 0;
}
.share-perm-row .share-send-btn {
    flex-shrink: 0;
}

/* On very small screens: stack permission + button vertically */
@media (max-width: 400px) {
    .share-perm-row {
        flex-direction: column;
        align-items: stretch;
    }
    .share-perm-row .share-send-btn {
        width: 100%;
        justify-content: center;
    }
}

/* Modal nội thất padding responsive */
.share-modal-inner-pad {
    padding-left: 1rem;
    padding-right: 1rem;
}
@media (min-width: 420px) {
    .share-modal-inner-pad {
        padding-left: 1.5rem;
        padding-right: 1.5rem;
    }
}
</style>
<div id="share-modal" onclick="closeShareModal()">
    <div class="bg-card shadow-2xl border border-border share-modal-box" onclick="event.stopPropagation()">

        {{-- Modal Header --}}
        <div class="share-modal-inner-pad flex items-center justify-between py-4" style="border-bottom:1px solid var(--color-border);">
            <div class="flex items-center gap-2">
                <div style="width:34px;height:34px;border-radius:10px;background:rgba(59,130,246,0.12);display:flex;align-items:center;justify-content:center;">
                    <span class="material-icons-outlined" style="font-size:1.1rem;color:#3b82f6;">share</span>
                </div>
                <div>
                    <h3 style="font-size:0.95rem;font-weight:700;margin:0;color:var(--color-body-text);">Share Note</h3>
                    <p style="font-size:0.7rem;color:var(--color-muted);margin:0;">Invite others to view or edit</p>
                </div>
            </div>
            <button onclick="closeShareModal()"
                    style="width:28px;height:28px;border-radius:8px;border:none;background:var(--color-hover);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--color-muted);">
                <span class="material-icons-outlined" style="font-size:1rem;">close</span>
            </button>
        </div>

        {{-- Share Form --}}
        <div class="share-modal-inner-pad py-4" style="border-bottom:1px solid var(--color-border);">
            <form onsubmit="shareNote(event)">
                {{-- Email --}}
                <div style="margin-bottom:0.75rem;">
                    <label style="display:block;font-size:0.7rem;font-weight:700;color:var(--color-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.35rem;">
                        Recipient Email
                    </label>
                    <input type="email" id="share-email"
                           class="form-input"
                           style="width:100%;font-size:0.875rem;"
                           placeholder="colleague@example.com" required>
                </div>
                {{-- Permission + Send --}}
                <div>
                    <label style="display:block;font-size:0.7rem;font-weight:700;color:var(--color-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.35rem;">
                        Permission
                    </label>
                    <div class="share-perm-row">
                        {{-- Custom animated dropdown --}}
                        <input type="hidden" id="share-perm" value="read">
                        <div class="cpd-wrap" id="cpd-share-perm">
                            <div class="cpd-trigger" onclick="cpdToggle('cpd-share-perm')">
                                <span class="cpd-label">View only</span>
                                <span class="material-icons-outlined cpd-chevron">expand_more</span>
                            </div>
                            <div class="cpd-list">
                                <div class="cpd-option selected" onclick="cpdSelect('cpd-share-perm','read','View only')">
                                    View only
                                    <span class="material-icons-outlined cpd-check">check</span>
                                </div>
                                <div class="cpd-option" onclick="cpdSelect('cpd-share-perm','edit','Can edit')">
                                    Can edit
                                    <span class="material-icons-outlined cpd-check">check</span>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn-primary share-send-btn" id="share-submit-btn"
                                style="font-size:0.8rem;height:2.375rem;padding:0 1rem;display:flex;align-items:center;gap:0.375rem;white-space:nowrap;">
                            <span class="material-icons-outlined" style="font-size:0.95rem;" id="share-submit-icon">send</span>
                            <span id="share-submit-label">Invite</span>
                        </button>
                    </div>
                </div>
                <div id="share-error"
                     style="display:none;margin-top:0.5rem;padding:0.5rem 0.75rem;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.25);border-radius:0.625rem;">
                    <p style="font-size:0.75rem;color:#f87171;margin:0;display:flex;align-items:flex-start;gap:0.375rem;">
                        <span class="material-icons-outlined" style="font-size:0.9rem;flex-shrink:0;margin-top:1px;">error_outline</span>
                        <span id="share-error-text"></span>
                    </p>
                </div>
                <div id="share-success"
                     style="display:none;margin-top:0.5rem;padding:0.5rem 0.75rem;background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.3);border-radius:0.625rem;transition:opacity 0.4s ease;">
                    <p style="font-size:0.75rem;color:#22c55e;margin:0;display:flex;align-items:center;gap:0.375rem;">
                        <span class="material-icons-outlined" style="font-size:0.9rem;flex-shrink:0;">check_circle</span>
                        <span id="share-success-text"></span>
                    </p>
                </div>
            </form>
        </div>

        {{-- Shared Users List --}}
        <div class="share-modal-inner-pad py-3">
            <p style="font-size:0.7rem;font-weight:700;color:var(--color-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.5rem;">
                Shared with
            </p>
            <div id="shares-list"
                 style="max-height:220px;overflow-y:auto;display:flex;flex-direction:column;gap:0.375rem;
                        scrollbar-width:thin;scrollbar-color:rgba(255,255,255,0.18) transparent;
                        padding-right:2px;">
                <style>
                    #shares-list::-webkit-scrollbar { width: 5px; }
                    #shares-list::-webkit-scrollbar-track { background: transparent; }
                    #shares-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.18); border-radius: 99px; }
                    #shares-list::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.32); }
                </style>
                {{-- Filled dynamically by loadShares() --}}
            </div>
        </div>

    </div>
</div>

{{-- Change note password modal --}}
<div id="change-pass-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden">
    <div class="bg-card rounded-2xl shadow-2xl border border-border w-full max-w-sm mx-4 p-6">
        <h3 class="text-lg font-bold mb-4" id="change-pass-title">Change Note Password</h3>
        <form id="change-pass-form" onsubmit="submitPasswordAction(event)">
            <input type="hidden" id="pass-action" value="">
            <div id="current-pass-group" class="mb-3">
                <label class="text-sm font-medium mb-1 block">Current Password</label>
                <input type="password" id="pass-current" class="form-input w-full text-sm" placeholder="Current password">
            </div>
            <div id="new-pass-group" class="mb-3">
                <label class="text-sm font-medium mb-1 block">New Password</label>
                <input type="password" id="pass-new" class="form-input w-full text-sm" placeholder="New password">
            </div>
            <div id="confirm-pass-group" class="mb-3">
                <label class="text-sm font-medium mb-1 block">Confirm New Password</label>
                <input type="password" id="pass-confirm" class="form-input w-full text-sm" placeholder="Confirm password">
            </div>
            <p id="pass-error" class="text-red-500 text-xs mb-2 hidden"></p>
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="document.getElementById('change-pass-modal').classList.add('hidden')" class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-primary">Confirm</button>
            </div>
        </form>
    </div>
</div>



{{-- Delete confirm modal --}}
<div id="editor-delete-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden">
    <div class="bg-card rounded-2xl shadow-2xl border border-border w-full max-w-sm mx-4 p-6">
        <h3 class="text-lg font-bold mb-2 flex items-center gap-2">
            <span class="material-icons-outlined text-red-500">delete</span>
            Delete Note
        </h3>
        <p class="text-muted text-sm mb-6">Are you sure? This action cannot be undone.</p>
        <div class="flex gap-3 justify-end">
            <button onclick="document.getElementById('editor-delete-modal').classList.add('hidden')" class="btn-secondary">Cancel</button>
            <button onclick="deleteNoteEditor()" class="btn-danger">Delete</button>
        </div>
    </div>
</div>

@push('scripts')
<script>
var noteId = {{ $note->id }};
var currentLabels = @json($note->labels->pluck('id'));

// ─── IDB-First Hydration: ALWAYS prefer IDB content over cached HTML ─────────
// The SW may serve stale HTML with old content. IDB has the freshest data
// from auto-save and background sync. Hydrate from IDB on every load.
(async function hydrateFromIDB() {
    if (!window.getNotesFromIDB) {
        // app.js not ready yet — retry after short delay
        setTimeout(hydrateFromIDB, 500);
        return;
    }

    try {
        // 1. Read current note from IDB
        var allNotes = await window.getNotesFromIDB();
        var idbNote = allNotes.find(function(n) { return n.id === noteId; });

        // 2. If IDB has data, use it (fresher than cached HTML)
        if (idbNote && (idbNote.title || idbNote.content)) {
            var titleEl = document.getElementById('note-title');
            var contentEl = document.getElementById('note-content');
            if (titleEl && idbNote.title !== undefined) titleEl.value = idbNote.title;
            if (contentEl && idbNote.content !== undefined) contentEl.value = idbNote.content;

            // Show sync status if pending
            if (idbNote.syncStatus === 'pending_update') {
                var s = document.getElementById('save-status');
                if (s) s.innerHTML = '<span class="material-icons-outlined text-sm text-amber-500">cloud_upload</span> Offline edits — will sync when online';
            }
        }

        // 3. Write current page content to IDB (ensures IDB is populated on first visit)
        var t = document.getElementById('note-title');
        var c = document.getElementById('note-content');
        if (t && c && window.updateNoteInIDB) {
            await window.updateNoteInIDB({
                id:      noteId,
                title:   t.value || '',
                content: c.value || '',
            });
        }
    } catch(e) {}
})();

// ─── Background IDB Sync: merge ALL notes from server for offline access ─────
// Uses mergeServerNotesIntoIDB (non-destructive) instead of per-note upsert.
(async function backgroundIDBSync() {
    if (!navigator.onLine) return;
    if (!window.mergeServerNotesIntoIDB) {
        setTimeout(backgroundIDBSync, 800);
        return;
    }
    try {
        var res = await fetch('/api/notes-offline-data', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) return;
        var data = await res.json();
        if (Array.isArray(data.notes)) {
            await window.mergeServerNotesIntoIDB(data.notes);
        }
        if (Array.isArray(data.labels) && window.saveLabelsToIDB) {
            await window.saveLabelsToIDB(data.labels);
        }
    } catch(e) { /* silent — non-critical background task */ }
})();

// ─── Fallback autosave (hoạt động độc lập với Alpine.js) ─────────────────────
// Đảm bảo autosave luôn chạy dù Alpine có init thành công hay không
(function() {
    var _saveTimer = null;
    function _doSave() {
        var titleEl   = document.getElementById('note-title');
        var contentEl = document.getElementById('note-content');
        var statusEl  = document.getElementById('save-status');
        if (!titleEl || !contentEl) return;
        if (statusEl) statusEl.innerHTML = '<span class="material-icons-outlined text-sm text-amber-500 animate-spin">sync</span> Saving...';
        fetch('/notes/' + noteId + '/auto-save', {
            method: 'PUT',
            credentials: 'include',  // ★ Send auth cookies
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.csrfToken,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ title: titleEl.value, content: contentEl.value })
        })
        .then(r => r.json())
        .then(result => {
            if (statusEl) statusEl.innerHTML = '<span class="material-icons-outlined text-sm text-emerald-500">cloud_done</span> Saved ' + (result.updated_at || '');
            if (window._ajaxPrefetchCache) delete window._ajaxPrefetchCache['/notes'];
            // Keep IDB fresh so offline always has latest title/content (syncStatus = synced)
            if (window.updateNoteInIDB) window.updateNoteInIDB({ id: noteId, title: titleEl.value, content: contentEl.value, syncStatus: 'synced' });
        })
        .catch(() => {
            if (!navigator.onLine && window.queueUpdate) {
                var titleVal   = titleEl ? titleEl.value : '';
                var contentVal = contentEl ? contentEl.value : '';
                window.queueUpdate(noteId, { title: titleVal, content: contentVal });
                // Also update IDB directly with syncStatus = pending_update
                if (window.updateNoteInIDB) {
                    window.updateNoteInIDB({ id: noteId, title: titleVal, content: contentVal, syncStatus: 'pending_update' });
                }
                if (statusEl) statusEl.innerHTML = '<span class="material-icons-outlined text-sm text-amber-500">cloud_upload</span> Saved offline';
            } else {
                if (statusEl) statusEl.innerHTML = '<span class="material-icons-outlined text-sm text-red-500">cloud_off</span> Save failed';
            }
        });
    }
    function _scheduleAutoSave(delay) {
        clearTimeout(_saveTimer);
        _saveTimer = setTimeout(_doSave, delay);
    }
    // Gắn listener sau khi DOM sẵn sàng
    function _attachListeners() {
        var titleEl   = document.getElementById('note-title');
        var contentEl = document.getElementById('note-content');
        if (titleEl && !titleEl._autoSaveBound) {
            titleEl.addEventListener('input', function() { _scheduleAutoSave(1000); });
            titleEl._autoSaveBound = true;
        }
        if (contentEl && !contentEl._autoSaveBound) {
            contentEl.addEventListener('input', function() { _scheduleAutoSave(1500); });
            contentEl._autoSaveBound = true;
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _attachListeners);
    } else {
        _attachListeners();
    }
    // Expose để Alpine's autoSave() vẫn có thể gọi khi cần
    window._editorAutoSave = _doSave;
})();

// ─── Offline sync listener cho Editor ────────────────────────────────────────
// Khi online lại: kiểm tra pending_updates cho note này → sync → reload
(async function initEditorOfflineSync() {
    // Nếu đang online: thử sync pending update cho note này (từ lần offline trước)
    if (navigator.onLine && window.getPendingUpdates) {
        try {
            const pending = await window.getPendingUpdates();
            const thisNote = pending.find(p => String(p.noteId) === String(noteId));
            if (thisNote) {
                // Có dữ liệu chờ sync — gửi lên server
                const statusEl = document.getElementById('save-status');
                if (statusEl) statusEl.innerHTML = '<span class="material-icons-outlined text-sm text-amber-500 animate-spin">sync</span> Syncing offline changes...';
                const res = await fetch('/notes/' + noteId + '/auto-save', {
                    method: 'PUT',
                    credentials: 'include',  // ★ Send auth cookies
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': window.csrfToken,
                    },
                    body: JSON.stringify({ title: thisNote.title, content: thisNote.content }),
                });
                if (res.ok) {
                    await window.removePendingUpdate(noteId);
                    // Cập nhật DOM với nội dung đã sync (không cần reload)
                    const titleEl   = document.getElementById('note-title');
                    const contentEl = document.getElementById('note-content');
                    if (titleEl   && thisNote.title   !== undefined) titleEl.value   = thisNote.title;
                    if (contentEl && thisNote.content !== undefined) contentEl.value = thisNote.content;
                    if (statusEl) statusEl.innerHTML = '<span class="material-icons-outlined text-sm text-emerald-500">cloud_done</span> Offline changes synced';
                }
            }
        } catch(e) { /* silent */ }
    }

    // Khi mất mạng: hiện banner trong editor
    window.addEventListener('offline', function() {
        if (document.getElementById('editor-offline-banner')) return;
        const banner = document.createElement('div');
        banner.id = 'editor-offline-banner';
        banner.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;padding:8px 18px;border-radius:999px;font-size:12px;font-weight:600;z-index:1000;box-shadow:0 4px 16px rgba(0,0,0,0.2);display:flex;align-items:center;gap:6px;white-space:nowrap;';
        banner.innerHTML = '<span class="material-icons-outlined" style="font-size:14px;">wifi_off</span> Offline — edits will sync when back online';
        document.body.appendChild(banner);
    });

    // Khi có mạng lại: sync pending update → cập nhật DOM → xóa banner
    window.addEventListener('online', async function() {
        document.getElementById('editor-offline-banner')?.remove();

        if (!window.getPendingUpdates || !window.removePendingUpdate) return;
        try {
            const pending = await window.getPendingUpdates();
            const thisNote = pending.find(p => String(p.noteId) === String(noteId));
            if (!thisNote) return;

            const statusEl = document.getElementById('save-status');
            if (statusEl) statusEl.innerHTML = '<span class="material-icons-outlined text-sm text-amber-500 animate-spin">sync</span> Syncing...';

            const res = await fetch('/notes/' + noteId + '/auto-save', {
                method: 'PUT',
                credentials: 'include',  // ★ Send auth cookies
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': window.csrfToken,
                },
                body: JSON.stringify({ title: thisNote.title, content: thisNote.content }),
            });

            if (res.ok) {
                const data = await res.json();
                await window.removePendingUpdate(noteId);

                // Cập nhật DOM với nội dung đã sync
                const titleEl   = document.getElementById('note-title');
                const contentEl = document.getElementById('note-content');
                if (titleEl   && thisNote.title   !== undefined) titleEl.value   = thisNote.title;
                if (contentEl && thisNote.content !== undefined) contentEl.value = thisNote.content;

                if (statusEl) statusEl.innerHTML = '<span class="material-icons-outlined text-sm text-emerald-500">cloud_done</span> Synced ' + (data.updated_at || '');
                if (window.showToast) showToast('Offline changes synced successfully', 'success');
            }
        } catch(e) { /* silent */ }
    });
})();



// ─── Real-time collaboration: poll for changes made by recipients ─────────────
@if($note->shares->where('permission','edit')->count() > 0)
var ownerLastTitle   = @json($note->title ?? '');
var ownerLastContent = @json($note->content ?? '');
var ownerIsPolling   = false;
var _collabHideTimer = null;  // track timeout to avoid stacking

function _showCollabIndicator(name) {
    const liveEl = document.getElementById('collab-live');
    if (!liveEl) return;
    liveEl.querySelector('#collab-indicator-text') &&
        (liveEl.querySelector('#collab-indicator-text').textContent = (name || 'Collaborator') + ' editing…');
    liveEl.classList.remove('hidden');
    // Clear any pending hide — reset the 5s timer
    clearTimeout(_collabHideTimer);
    _collabHideTimer = setTimeout(() => {
        liveEl.classList.add('hidden');
    }, 5000);
}

function _hideCollabIndicator() {
    clearTimeout(_collabHideTimer);
    const liveEl = document.getElementById('collab-live');
    if (liveEl) liveEl.classList.add('hidden');
}

async function ownerPollCollabChanges() {
    if (ownerIsPolling) return;
    // Don't poll while owner is actively typing
    const noteEl  = document.getElementById('note-content');
    const titleEl = document.getElementById('note-title');
    if (noteEl  && noteEl  === document.activeElement) return;
    if (titleEl && titleEl === document.activeElement) return;

    ownerIsPolling = true;
    try {
        const data = await apiCall(`/notes/${noteId}/collab-latest`);
        if (!data) { ownerIsPolling = false; return; }

        const titleChanged   = data.title   !== ownerLastTitle;
        const contentChanged = data.content !== ownerLastContent;

        if (titleChanged || contentChanged) {
            // Update DOM directly — do NOT dispatch 'input' to avoid triggering
            // owner autosave unnecessarily; just set value directly
            if (titleChanged && titleEl) {
                titleEl.value  = data.title ?? '';
                ownerLastTitle = data.title;
            }
            if (contentChanged && noteEl) {
                noteEl.value    = data.content ?? '';
                ownerLastContent = data.content;
            }

            // Sync Alpine state if available
            const editorEl = document.querySelector('[x-data="noteEditor()"]') ||
                             (titleEl && titleEl.closest('[x-data]'));
            if (editorEl && window.Alpine) {
                try {
                    const comp = Alpine.$data(editorEl);
                    if (titleChanged   && comp && 'title'   in comp) comp.title   = data.title   ?? '';
                    if (contentChanged && comp && 'content' in comp) comp.content = data.content ?? '';
                } catch(err) { /* Alpine not ready — DOM already updated above */ }
            }

            // Show indicator — auto-hides after 5s of no new changes
            _showCollabIndicator(data.updated_by || '');
        }
        // If no changes this poll cycle, the hide timer continues counting down
        // naturally — indicator will disappear after 5s of inactivity
    } catch(e) { /* silent */ }
    ownerIsPolling = false;
}

setInterval(ownerPollCollabChanges, 3000);
@endif

function noteEditor() {
    // Đọc trực tiếp từ DOM elements đã được server render sẵn.
    // Không phụ thuộc vào script timing hay AJAX execution order.
    var _t = document.getElementById('note-title');
    var _c = document.getElementById('note-content');
    return {
        title:   _t ? (_t.value || _t.getAttribute('value') || '') : '',
        content: _c ? (_c.value || _c.textContent              || '') : '',
        saving: false,
        async autoSave() {
            this.saving = true;
            if (typeof ownerLastTitle   !== 'undefined') ownerLastTitle   = this.title;
            if (typeof ownerLastContent !== 'undefined') ownerLastContent = this.content;
            document.getElementById('save-status').innerHTML = '<span class="material-icons-outlined text-sm text-amber-500 animate-spin">sync</span> Saving...';
            try {
                const result = await apiCall(`/notes/${noteId}/auto-save`, 'PUT', {
                    title: this.title,
                    content: this.content,
                });
                document.getElementById('save-status').innerHTML = '<span class="material-icons-outlined text-sm text-emerald-500">cloud_done</span> Saved ' + result.updated_at;
                // Xóa prefetch cache của /notes để khi quay về luôn thấy nội dung mới nhất
                if (window._ajaxPrefetchCache) {
                    delete window._ajaxPrefetchCache['/notes'];
                    delete window._ajaxPrefetchCache[window.location.origin + '/notes'];
                }
                // Keep IDB fresh so offline always has latest title/content
                if (window.updateNoteInIDB) window.updateNoteInIDB({ id: noteId, title: this.title, content: this.content });
            } catch(e) {
                // If offline, queue the update locally
                if (!navigator.onLine && window.queueUpdate) {
                    await window.queueUpdate(noteId, { title: this.title, content: this.content });
                    document.getElementById('save-status').innerHTML = '<span class="material-icons-outlined text-sm text-amber-500">cloud_upload</span> Saved offline — will sync when back online';
                } else {
                    document.getElementById('save-status').innerHTML = '<span class="material-icons-outlined text-sm text-red-500">cloud_off</span> Save failed — <button onclick="window._editorAutoSave && window._editorAutoSave()" class="underline">retry</button>';
                }
            }
            this.saving = false;
        }
    };
}

// Pin toggle in editor
async function togglePinEditor() {
    try {
        const r = await apiCall(`/notes/${noteId}/toggle-pin`, 'POST');
        // Desktop icon + button
        const icon    = document.getElementById('pin-icon');
        const btn     = document.getElementById('pin-btn');
        // Mobile icon (in More menu)
        const iconMob = document.getElementById('pin-icon-mobile');

        if (icon) {
            icon.style.color = r.is_pinned ? '#f59e0b' : 'var(--color-muted)';
        }
        if (btn) {
            btn.title = r.is_pinned ? 'Unpin' : 'Pin';
        }
        if (iconMob) {
            iconMob.style.color = r.is_pinned ? '#f59e0b' : '';
        }
        showToast(r.is_pinned ? 'Note pinned' : 'Note unpinned');
    } catch(e) { showToast('Error', 'error'); }
}

// Images
async function uploadImages(files) {
    for (const file of files) {
        const fd = new FormData();
        fd.append('image', file);
        try {
            const r = await apiCall(`/notes/${noteId}/upload-image`, 'POST', fd);
            const grid = document.getElementById('images-grid');
            grid.insertAdjacentHTML('beforeend', `
                <div class="relative group rounded-xl overflow-hidden bg-hover" id="image-${r.image.id}">
                    <button type="button" class="block w-full h-full" data-url="${r.image.url}" data-name="${r.image.original_name}" onclick="openImageViewerFromDataset(this); event.stopPropagation();">
                        <img src="${r.image.url}" class="w-full h-32 object-cover" alt="${r.image.original_name}">
                    </button>
                    <div class="attachment-overlay absolute inset-0 bg-black/50 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity flex items-center justify-center pointer-events-none">
                        <button onclick="deleteImage(${r.image.id})" class="p-2 rounded-full bg-red-500 text-white hover:bg-red-600 pointer-events-auto">
                            <span class="material-icons-outlined text-lg">delete</span>
                        </button>
                    </div>
                    <p class="absolute bottom-0 left-0 right-0 bg-black/60 text-white text-[10px] px-2 py-1 truncate">${r.image.original_name}</p>
                </div>
            `);
            showToast('Image uploaded');
        } catch(e) { showToast('Upload failed', 'error'); }
    }
    document.getElementById('image-upload').value = '';
}

async function deleteImage(imageId) {
    try {
        await apiCall(`/notes/${noteId}/images/${imageId}`, 'DELETE');
        document.getElementById(`image-${imageId}`)?.remove();
        showToast('Image removed');
    } catch(e) { showToast('Error', 'error'); }
}

// ─── Label dropdown (plain JS — không phụ thuộc Alpine) ──────────────────────
function toggleLabelDropdown() {
    const dd = document.getElementById('label-dropdown');
    if (!dd) return;
    const isOpen = dd.style.display !== 'none';
    if (isOpen) {
        closeLabelDropdown();
    } else {
        dd.style.display = 'block';
        // Focus search input
        setTimeout(() => document.getElementById('label-search')?.focus(), 50);
        // Close when clicking outside
        document.addEventListener('click', _labelOutsideHandler);
    }
}
function closeLabelDropdown() {
    const dd = document.getElementById('label-dropdown');
    if (dd) dd.style.display = 'none';
    const si = document.getElementById('label-search');
    if (si) { si.value = ''; filterLabels(''); }
    document.removeEventListener('click', _labelOutsideHandler);
}
function _labelOutsideHandler(e) {
    const wrap = document.getElementById('label-dropdown-wrap');
    if (wrap && !wrap.contains(e.target)) closeLabelDropdown();
}
function filterLabels(q) {
    const lower = q.toLowerCase();
    document.querySelectorAll('#label-list button[data-label-name]').forEach(btn => {
        btn.style.display = !lower || btn.dataset.labelName.includes(lower) ? '' : 'none';
    });
}

// Labels
var allLabels = @json($labels->map(fn($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values());

async function toggleLabel(labelId) {
    const idx = currentLabels.indexOf(labelId);
    const adding = idx === -1;
    if (adding) currentLabels.push(labelId);
    else currentLabels.splice(idx, 1);

    // Optimistic UI ngay lập tức
    _syncLabelChip(labelId, adding);
    _syncLabelCheck(labelId, adding);

    try {
        await apiCall(`/notes/${noteId}/labels`, 'PUT', { labels: currentLabels });
        showToast(adding ? 'Label added' : 'Label removed');
        // Bust AJAX prefetch cache của /notes
        _bustNotesCache();
        // Lưu cờ để notes index cập nhật card khi quay về
        _flagLabelsChanged();
    } catch(e) {
        // Revert
        if (adding) currentLabels.splice(currentLabels.indexOf(labelId), 1);
        else currentLabels.push(labelId);
        _syncLabelChip(labelId, !adding);
        _syncLabelCheck(labelId, !adding);
        showToast('Error updating labels', 'error');
    }
}

function _syncLabelChip(labelId, add) {
    const wrap = document.getElementById('editor-labels-wrap');
    const addBtnWrap = document.getElementById('label-dropdown-wrap');
    if (!wrap) return;
    if (add) {
        // Không thêm nếu đã có
        if (wrap.querySelector(`.note-label[data-label-id="${labelId}"]`)) return;
        const info = allLabels.find(l => l.id === labelId);
        if (!info) return;
        const chip = document.createElement('span');
        chip.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium text-white note-label';
        chip.style.backgroundColor = info.color;
        chip.dataset.labelId = String(labelId);
        chip.innerHTML = `${info.name} <button onclick="removeLabel(${labelId})" class="hover:opacity-70" type="button">&times;</button>`;
        wrap.insertBefore(chip, addBtnWrap);
    } else {
        wrap.querySelector(`.note-label[data-label-id="${labelId}"]`)?.remove();
    }
}

function _syncLabelCheck(labelId, add) {
    const btn = document.getElementById(`label-btn-${labelId}`);
    const check = btn?.querySelector('.label-check');
    if (!check) return;
    check.classList.toggle('hidden', !add);
}

async function removeLabel(labelId) {
    // Optimistic UI trước khi gọi API
    const prevLabels = [...currentLabels];
    currentLabels = currentLabels.filter(id => id !== labelId);
    document.querySelector(`.note-label[data-label-id="${labelId}"]`)?.remove();
    _syncLabelCheck(labelId, false); // bỏ check trong dropdown

    try {
        await apiCall(`/notes/${noteId}/labels`, 'PUT', { labels: currentLabels });
        showToast('Label removed');
        _bustNotesCache();
        _flagLabelsChanged();
    } catch(e) {
        // Revert
        currentLabels = prevLabels;
        _syncLabelChip(labelId, true);
        _syncLabelCheck(labelId, true);
        showToast('Error removing label', 'error');
    }
}

// Xóa prefetch cache của /notes để lần navigate sau sẽ fetch fresh
function _bustNotesCache() {
    if (window._ajaxPrefetchCache) {
        delete window._ajaxPrefetchCache['/notes'];
        delete window._ajaxPrefetchCache[window.location.origin + '/notes'];
        delete window._ajaxPrefetchCache['/notes?'];
    }
}

// Lưu cờ thay đổi label vào window để notes index đọc khi quay về
function _flagLabelsChanged() {
    const labelInfos = currentLabels
        .map(id => allLabels.find(l => l.id === id))
        .filter(Boolean);
    window._labelsChanged = { noteId, labels: labelInfos };
}

// Mobile password overlay helpers
function openPassMobile() {
    const overlay = document.getElementById('pass-mobile-overlay');
    // Teleport to <body> to escape backdrop-filter containing block in <header>
    if (overlay.parentElement !== document.body) {
        document.body.appendChild(overlay);
    }
    overlay.style.display = 'flex';
}
function closePassMobile() {
    document.getElementById('pass-mobile-overlay').style.display = 'none';
}

// Password
async function setNotePassword(e) {
    e.preventDefault();
    // Support both desktop (#new-note-pass) and mobile (#new-note-pass-m) inputs
    const isMobile = !!document.getElementById('new-note-pass-m') && window.getComputedStyle(document.getElementById('pass-mobile-overlay')).display !== 'none';
    const passId    = isMobile ? 'new-note-pass-m'    : 'new-note-pass';
    const confirmId = isMobile ? 'confirm-note-pass-m' : 'confirm-note-pass';
    const errId     = isMobile ? 'set-pass-error-m'    : 'set-pass-error';

    const pass    = document.getElementById(passId)?.value    || '';
    const confirm = document.getElementById(confirmId)?.value || '';
    const errEl   = document.getElementById(errId);

    const showErr = (msg) => { if(errEl){ errEl.textContent = msg; errEl.classList.remove('hidden'); } };
    if(errEl) errEl.classList.add('hidden');

    if (pass.length < 4) { showErr('Password must be at least 4 characters'); return; }
    if (pass !== confirm) { showErr('Passwords do not match'); return; }

    try {
        await apiCall(`/notes/${noteId}/set-password`, 'POST', { password: pass, password_confirmation: confirm });
        showToast('Password protection enabled');
        _bustNotesCache();
        window.location.reload();
    } catch(e) {
        showErr(e.error || 'Something went wrong, please try again');
    }
}

function showChangePasswordForm() {
    document.getElementById('pass-action').value = 'change';
    document.getElementById('change-pass-title').textContent = 'Change Note Password';
    document.getElementById('current-pass-group').style.display = '';
    document.getElementById('new-pass-group').style.display = '';
    document.getElementById('confirm-pass-group').style.display = '';
    document.getElementById('change-pass-modal').classList.remove('hidden');
}

function showRemovePasswordForm() {
    document.getElementById('pass-action').value = 'remove';
    document.getElementById('change-pass-title').textContent = 'Remove Password Protection';
    document.getElementById('current-pass-group').style.display = '';
    document.getElementById('new-pass-group').style.display = 'none';
    document.getElementById('confirm-pass-group').style.display = 'none';
    document.getElementById('change-pass-modal').classList.remove('hidden');
}

async function submitPasswordAction(e) {
    e.preventDefault();
    const action = document.getElementById('pass-action').value;
    const current = document.getElementById('pass-current').value;
    const errEl = document.getElementById('pass-error');

    try {
        if (action === 'change') {
            const pw = document.getElementById('pass-new').value;
            const confirm = document.getElementById('pass-confirm').value;
            if (pw !== confirm) {
                errEl.textContent = 'Passwords do not match';
                errEl.classList.remove('hidden');
                return;
            }
            await apiCall(`/notes/${noteId}/change-password`, 'PUT', { current_password: current, password: pw, password_confirmation: confirm });
            showToast('Note password changed');
        } else {
            await apiCall(`/notes/${noteId}/remove-password`, 'POST', { password: current });
            showToast('Password protection removed');
        }
        document.getElementById('change-pass-modal').classList.add('hidden');
        _bustNotesCache();
        window.location.reload();
    } catch(err) {
        errEl.textContent = err.error || 'Error';
        errEl.classList.remove('hidden');
    }
}

// ─── Custom Permission Dropdown helpers ──────────────────────────────────────
function cpdToggle(wrapId) {
    const wrap    = document.getElementById(wrapId);
    const trigger = wrap.querySelector('.cpd-trigger');
    const list    = wrap.querySelector('.cpd-list');
    const isOpen  = list.classList.contains('open');

    // Đóng tất cả CPD khác đang mở
    document.querySelectorAll('.cpd-list.open').forEach(l => {
        l.classList.remove('open');
        l.closest('.cpd-wrap').querySelector('.cpd-trigger').classList.remove('open');
    });

    if (!isOpen) {
        list.classList.add('open');
        trigger.classList.add('open');
        // Click ngoài đóng dropdown
        setTimeout(() => {
            function onOutside(e) {
                if (!wrap.contains(e.target)) {
                    list.classList.remove('open');
                    trigger.classList.remove('open');
                    document.removeEventListener('click', onOutside);
                }
            }
            document.addEventListener('click', onOutside);
        }, 0);
    }
}

function cpdSelect(wrapId, value, label) {
    const wrap    = document.getElementById(wrapId);
    if (!wrap) return;
    const trigger = wrap.querySelector('.cpd-trigger');
    const list    = wrap.querySelector('.cpd-list');
    // Update label
    trigger.querySelector('.cpd-label').textContent = label;
    // Update selected state — find option by matching label text (works both from click and programmatic call)
    wrap.querySelectorAll('.cpd-option').forEach(o => {
        const txt = o.textContent.trim().split('\n')[0].trim(); // first text node, ignore icon text
        o.classList.toggle('selected', txt === label);
    });
    // Update hidden input
    const hiddenId = wrapId.replace('cpd-', '');
    const hidden = document.getElementById(hiddenId);
    if (hidden) hidden.value = value;
    // Đóng
    if (list)    list.classList.remove('open');
    if (trigger) trigger.classList.remove('open');
}

// Share
function openShareModal() {
    const modal = document.getElementById('share-modal');
    // Teleport ra <body> để tránh will-change:transform container
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    // Bước 1: đặt display:flex nhưng chưa fade
    modal.classList.add('share-modal-visible');
    // Bước 2: sau 1 frame — trigger transition (fade + scale)
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            modal.classList.add('share-modal-open');
        });
    });
    loadShares();
}
function closeShareModal() {
    const modal = document.getElementById('share-modal');
    modal.classList.remove('share-modal-open');
    // Sau khi transition xong mới ẩn thật
    setTimeout(() => {
        if (!modal.classList.contains('share-modal-open')) {
            modal.classList.remove('share-modal-visible');
        }
    }, 220);
}

async function loadShares() {
    try {
        const shares = await apiCall(`/notes/${noteId}/shares`);
        const list = document.getElementById('shares-list');
        if (!shares.length) {
            list.innerHTML = `<p style="font-size:0.8rem;color:var(--color-muted);text-align:center;padding:0.75rem 0;">No one has access yet</p>`;
            return;
        }
        list.innerHTML = shares.map((s,i) => {
            const initials = (s.recipient.display_name || s.recipient.email).slice(0,2).toUpperCase();
            const colors = ['#3b82f6','#8b5cf6','#f59e0b','#10b981','#ef4444','#ec4899'];
            const color  = colors[s.id % colors.length];
            const uid    = `cpd-sp-${s.id}`;
            const isRead = s.permission === 'read';
            return `
            <div style="display:flex;align-items:center;gap:0.625rem;padding:0.5rem 0.625rem;border-radius:10px;background:var(--color-hover);" id="share-${s.id}">
                <div style="width:32px;height:32px;border-radius:50%;flex-shrink:0;overflow:hidden;">
                    <img src="${s.recipient.avatar_url}" alt="${initials}"
                         style="width:100%;height:100%;object-fit:cover;"
                         onerror="this.style.display='none';this.parentElement.style.background='${color}';this.parentElement.innerHTML='<span style=\'display:flex;align-items:center;justify-content:center;width:100%;height:100%;font-size:0.7rem;font-weight:700;color:#fff;\'>${initials}</span>';">
                </div>
                <div style="flex:1;min-width:0;">
                    <p style="font-size:0.8rem;font-weight:600;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--color-body-text);">${s.recipient.display_name}</p>
                    <p style="font-size:0.7rem;color:var(--color-muted);margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${s.recipient.email}</p>
                </div>
                <button
                    id="share-perm-btn-${s.id}"
                    onclick="toggleSharePermission(${s.id}, this)"
                    title="Click to toggle permission"
                    style="flex-shrink:0;padding:0.2rem 0.625rem;border-radius:6px;border:1.5px solid var(--accent-border);
                           background:var(--color-input-bg);color:var(--color-body-text);font-size:0.72rem;font-weight:600;
                           cursor:pointer;transition:background 0.15s,border-color 0.15s;white-space:nowrap;"
                    data-perm="${s.permission}">
                    ${isRead ? 'View' : 'Edit'}
                </button>
                <button onclick="revokeShare(${s.id})"
                        style="width:26px;height:26px;border-radius:7px;border:none;background:rgba(239,68,68,0.1);color:#f87171;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;"
                        title="Remove access">
                    <span class="material-icons-outlined" style="font-size:0.9rem;">person_remove</span>
                </button>
            </div>`;
        }).join('');
    } catch(e) {}
}

async function shareNote(e) {
    e.preventDefault();
    const email = document.getElementById('share-email').value.trim();
    const permission = document.getElementById('share-perm').value;
    const errBox     = document.getElementById('share-error');
    const errText    = document.getElementById('share-error-text');
    const sucBox     = document.getElementById('share-success');
    const sucText    = document.getElementById('share-success-text');
    const submitBtn  = document.getElementById('share-submit-btn');
    const submitIcon = document.getElementById('share-submit-icon');
    const submitLbl  = document.getElementById('share-submit-label');
    errBox.style.display = 'none';
    sucBox.style.display = 'none';

    // ── Loading state ──
    submitBtn.disabled = true;
    submitIcon.textContent = 'hourglass_top';
    submitIcon.classList.add('animate-spin');
    submitLbl.textContent = 'Sending...';

    try {
        const res = await apiCall(`/notes/${noteId}/share`, 'POST', { email, permission });

        // ── Reset form ──
        document.getElementById('share-email').value = '';
        // Reset permission dropdown to "read" / "View only"
        cpdSelect('cpd-share-perm', 'read', 'View only');

        // ── Inline success banner ──
        const displayName = res.share?.recipient?.display_name || email;
        sucText.textContent = `"${displayName}" has been invited successfully!`;
        sucBox.style.display = 'block';
        sucBox.style.opacity = '1';
        setTimeout(() => {
            sucBox.style.opacity = '0';
            setTimeout(() => { sucBox.style.display = 'none'; sucBox.style.opacity = '1'; }, 450);
        }, 3000);

        // ── Toast ──
        showToast(`Shared with ${displayName}`, 'success');

        // ── Cập nhật danh sách — reload toàn bộ để đảm bảo đồng bộ cả avatar lẫn quyền ──
        _insertShareRow(res.share);
        // Reload toàn bộ list để đảm bảo avatar và permission của tất cả users là mới nhất
        loadShares();

    } catch(err) {
        const msg = err.error || err.message || 'Error sharing note';
        errText.textContent = msg;
        errBox.style.display = 'block';
        console.error('[shareNote] Error:', err);
    } finally {
        // ── Restore button ──
        submitBtn.disabled = false;
        submitIcon.textContent = 'send';
        submitIcon.classList.remove('animate-spin');
        submitLbl.textContent = 'Invite';
    }
}

// Optimistically insert or update a share row without reloading the whole list
function _insertShareRow(s) {
    if (!s || !s.recipient) return;
    const list = document.getElementById('shares-list');
    if (!list) return;

    // Remove "no one" placeholder if present
    const placeholder = list.querySelector('p');
    if (placeholder) placeholder.remove();

    // If already in list (permission update) — rebuild entire row to get latest avatar + permission
    const existing = document.getElementById(`share-${s.id}`);
    if (existing) {
        const initials2 = (s.recipient.display_name || s.recipient.email).slice(0,2).toUpperCase();
        const colors2   = ['#3b82f6','#8b5cf6','#f59e0b','#10b981','#ef4444','#ec4899'];
        const color2    = colors2[s.id % colors2.length];
        const isRead2   = s.permission === 'read';
        existing.innerHTML = `
            <div style="width:32px;height:32px;border-radius:50%;flex-shrink:0;overflow:hidden;">
                <img src="${s.recipient.avatar_url}" alt="${initials2}"
                     style="width:100%;height:100%;object-fit:cover;"
                     onerror="this.style.display='none';this.parentElement.style.background='${color2}';this.parentElement.innerHTML='<span style=\\'display:flex;align-items:center;justify-content:center;width:100%;height:100%;font-size:0.7rem;font-weight:700;color:#fff;\\'>${initials2}</span>';">
            </div>
            <div style="flex:1;min-width:0;">
                <p style="font-size:0.8rem;font-weight:600;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--color-body-text);">${s.recipient.display_name}</p>
                <p style="font-size:0.7rem;color:var(--color-muted);margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${s.recipient.email}</p>
            </div>
            <button
                id="share-perm-btn-${s.id}"
                onclick="toggleSharePermission(${s.id}, this)"
                title="Click to toggle permission"
                style="flex-shrink:0;padding:0.2rem 0.625rem;border-radius:6px;border:1.5px solid var(--accent-border);
                       background:var(--color-input-bg);color:var(--color-body-text);font-size:0.72rem;font-weight:600;
                       cursor:pointer;transition:background 0.15s,border-color 0.15s;white-space:nowrap;"
                data-perm="${s.permission}">
                ${isRead2 ? 'View' : 'Edit'}
            </button>
            <button onclick="revokeShare(${s.id})"
                    style="width:26px;height:26px;border-radius:7px;border:none;background:rgba(239,68,68,0.1);color:#f87171;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;"
                    title="Remove access">
                <span class="material-icons-outlined" style="font-size:0.9rem;">person_remove</span>
            </button>`;
        return;
    }

    const initials = (s.recipient.display_name || s.recipient.email).slice(0,2).toUpperCase();
    const colors   = ['#3b82f6','#8b5cf6','#f59e0b','#10b981','#ef4444','#ec4899'];
    const color    = colors[s.id % colors.length];
    const uid      = `cpd-sp-${s.id}`;
    const isRead   = s.permission === 'read';

    const div = document.createElement('div');
    div.id = `share-${s.id}`;
    div.style.cssText = 'display:flex;align-items:center;gap:0.625rem;padding:0.5rem 0.625rem;border-radius:10px;background:var(--color-hover);opacity:0;transition:opacity 0.3s ease;';
    div.innerHTML = `
        <div style="width:32px;height:32px;border-radius:50%;flex-shrink:0;overflow:hidden;">
            <img src="${s.recipient.avatar_url}" alt="${initials}"
                 style="width:100%;height:100%;object-fit:cover;"
                 onerror="this.style.display='none';this.parentElement.style.background='${color}';this.parentElement.innerHTML='<span style=\'display:flex;align-items:center;justify-content:center;width:100%;height:100%;font-size:0.7rem;font-weight:700;color:#fff;\'>${initials}</span>';">
        </div>
        <div style="flex:1;min-width:0;">
            <p style="font-size:0.8rem;font-weight:600;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--color-body-text);">${s.recipient.display_name}</p>
            <p style="font-size:0.7rem;color:var(--color-muted);margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${s.recipient.email}</p>
        </div>
        <button
            id="share-perm-btn-${s.id}"
            onclick="toggleSharePermission(${s.id}, this)"
            title="Click to toggle permission"
            style="flex-shrink:0;padding:0.2rem 0.625rem;border-radius:6px;border:1.5px solid var(--accent-border);
                   background:var(--color-input-bg);color:var(--color-body-text);font-size:0.72rem;font-weight:600;
                   cursor:pointer;transition:background 0.15s,border-color 0.15s;white-space:nowrap;"
            data-perm="${s.permission}">
            ${isRead ? 'View' : 'Edit'}
        </button>
        <button onclick="revokeShare(${s.id})"
                style="width:26px;height:26px;border-radius:7px;border:none;background:rgba(239,68,68,0.1);color:#f87171;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;"
                title="Remove access">
            <span class="material-icons-outlined" style="font-size:0.9rem;">person_remove</span>
        </button>`;

    list.appendChild(div);
    // Fade-in
    requestAnimationFrame(() => { div.style.opacity = '1'; });
}

async function updateSharePermission(shareId, permission) {
    try {
        await apiCall(`/shares/${shareId}/permission`, 'PUT', { permission });
        showToast('Permission updated');
    } catch(e) { showToast('Error', 'error'); }
}

async function toggleSharePermission(shareId, btn) {
    const current = btn.dataset.perm || 'read';
    const next = current === 'read' ? 'edit' : 'read';
    // Optimistic update
    btn.dataset.perm = next;
    btn.textContent = next === 'read' ? 'View' : 'Edit';
    try {
        await updateSharePermission(shareId, next);
    } catch(e) {
        // Revert on failure
        btn.dataset.perm = current;
        btn.textContent = current === 'read' ? 'View' : 'Edit';
    }
}

async function revokeShare(shareId) {
    try {
        await apiCall(`/shares/${shareId}`, 'DELETE');
        document.getElementById(`share-${shareId}`)?.remove();
        showToast('Share revoked');
    } catch(e) { showToast('Error', 'error'); }
}

// Delete
function confirmDeleteEditor() {
    document.getElementById('editor-delete-modal').classList.remove('hidden');
}
async function deleteNoteEditor() {
    try {
        await apiCall(`/notes/${noteId}`, 'DELETE');
        if (window.deleteNoteFromIDB) {
            try { await window.deleteNoteFromIDB(noteId); } catch (err) { console.warn('[Offline] deleteNoteFromIDB failed:', err); }
        }
        if (window.removePendingUpdate) {
            try { await window.removePendingUpdate(noteId); } catch (err) {}
        }
        if (window.removePendingCreate && String(noteId).startsWith('temp_')) {
            try { await window.removePendingCreate(noteId); } catch (err) {}
        }
        if (window.ajaxNav) window.ajaxNav('/notes');
        else window.location.href = '/notes';
    } catch(e) { showToast('Error deleting', 'error'); }
}
</script>
@endpush
@endsection
