@extends('layouts.app')
@section('title', 'My Notes - JOTIFY')

@section('header')
<div class="flex-1 flex items-center gap-2 sm:gap-4" id="header-toolbar">
    {{-- Search --}}
    <div class="relative flex-1 min-w-0" id="search-wrap">
        <button id="search-icon-btn" type="button"
                onclick="document.getElementById('search-input').focus()"
                style="position:absolute;left:8px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;outline:none;padding:2px;display:flex;align-items:center;justify-content:center;z-index:2;">
            <span id="search-icon" class="material-icons-outlined text-lg" style="color:var(--color-muted);transition:color 0.2s ease,filter 0.2s ease,transform 0.2s cubic-bezier(0.34,1.26,0.64,1);">search</span>
        </button>
        <input type="text" id="search-input"
               value="{{ request('search') }}"
               placeholder="Search notes..."
               autocomplete="off"
               class="w-full h-10 pl-10 pr-8 rounded-xl bg-hover border border-border text-sm text-body placeholder:text-muted transition-all"
               style="outline:none;">
        {{-- Clear button: luôn hiện, click xóa text hoặc blur nếu trống --}}
        <button id="search-clear-btn" type="button"
                style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;outline:none;padding:4px;display:none;align-items:center;justify-content:center;z-index:2;border-radius:50%;transition:background 0.15s ease;"
                onmouseenter="this.style.background='rgba(0,0,0,0.08)'"
                onmouseleave="this.style.background='transparent'"
                onclick="var inp=document.getElementById('search-input'),ht=document.getElementById('header-toolbar');inp.value='';inp.dispatchEvent(new Event('input'));ht&&ht.classList.remove('has-search');inp.blur();">
            <span class="material-icons-outlined" style="font-size:1rem;color:var(--color-muted);">close</span>
        </button>
        <span id="search-spinner" class="absolute right-8 top-1/2 -translate-y-1/2 material-icons-outlined text-muted text-lg animate-spin" style="display:none">sync</span>
    </div>

    <style>
    /* ── Focus state: green ring ───────────────────────────── */
    #search-input:focus {
        border-color: #16a34a !important;
        box-shadow: 0 0 0 3px rgba(22,163,74,0.25) !important;
        background-color: rgba(22,163,74,0.08) !important;
    }

    /* ── Mobile: ẩn thanh search mặc định, chỉ hiện icon ──── */
    @media (max-width: 639px) {
        /* Search wrap mặc định: chỉ rộng bằng icon (40px) */
        #search-wrap {
            flex: 0 0 40px;
            height: 40px;
            overflow: hidden;
            border-radius: 0.75rem;
            transition: flex 0.25s cubic-bezier(0.4,0,0.2,1);
        }
        #search-icon-btn {
            position: static !important;
            transform: none !important;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        /* Input và X ẩn mặc định */
        #search-input    { opacity: 0; pointer-events: none; transition: opacity 0.2s; }
        #search-clear-btn { display: none !important; }

        /* ── Khi input được FOCUS: expand toàn bộ ── */
        #header-toolbar:has(#search-input:focus) #search-wrap {
            flex: 1 1 auto;
            height: auto;
            overflow: visible;
        }
        #header-toolbar:has(#search-input:focus) #search-icon-btn {
            position: absolute !important;
            width: auto;
            height: auto;
            transform: translateY(-50%) !important;
        }
        #header-toolbar:has(#search-input:focus) #search-input {
            opacity: 1;
            pointer-events: auto;
        }
        #header-toolbar:has(#search-input:focus) #search-clear-btn {
            display: flex !important;
        }
        /* Ẩn nút new-note khi search active */
        #header-toolbar:has(#search-input:focus) #new-note-form {
            display: none;
        }

        /* ── Khi có text (class has-search từ JS): giữ expanded sau blur ── */
        #header-toolbar.has-search #search-wrap {
            flex: 1 1 auto;
            height: auto;
            overflow: visible;
        }
        #header-toolbar.has-search #search-icon-btn {
            position: absolute !important;
            width: auto;
            height: auto;
            transform: translateY(-50%) !important;
        }
        #header-toolbar.has-search #search-input {
            opacity: 1;
            pointer-events: auto;
        }
        #header-toolbar.has-search #search-clear-btn {
            display: flex !important;
        }
        #header-toolbar.has-search #new-note-form {
            display: none;
        }

        /* ── Ẩn hamburger + bell/dark khi search active → search che full header ── */
        header:has(#search-input:focus) #btn-sidebar-toggle,
        header:has(#search-input:focus) > div:last-child {
            display: none !important;
        }
        /* header-toolbar chiếm full width khi search focus */
        header:has(#search-input:focus) > div.flex-1 {
            flex: 1 1 100%;
        }
        /* has-search (có text sau blur): giữ full width */
        header:has(#header-toolbar.has-search) #btn-sidebar-toggle,
        header:has(#header-toolbar.has-search) > div:last-child {
            display: none !important;
        }
        header:has(#header-toolbar.has-search) > div.flex-1 {
            flex: 1 1 100%;
        }
    }
    /* ── Desktop (sm+): reset new-note button về full style ── */
    @media (min-width: 640px) {
        #btn-new-note {
            width: auto !important;
            height: 40px !important;
            padding: 0 1rem !important;
            border-radius: 0.75rem !important;
        }
    }
    </style>

    {{-- View toggle: hiện cả trên mobile và desktop --}}
    <div class="view-toggle-wrap flex" id="view-toggle">
        {{-- Sliding pill --}}
        <div class="view-toggle-pill" id="toggle-pill"
             style="transform: translateX({{ $preferences->view_mode === 'list' ? 'calc(100% + 2px)' : '0px' }})"></div>
        <button onclick="switchView('grid')" id="btn-grid"
                class="view-toggle-btn {{ $preferences->view_mode === 'grid' ? 'active' : '' }}"
                aria-label="Grid view">
            <span class="material-icons-outlined" style="font-size:1.125rem;">grid_view</span>
        </button>
        <button onclick="switchView('list')" id="btn-list"
                class="view-toggle-btn {{ $preferences->view_mode === 'list' ? 'active' : '' }}"
                aria-label="List view">
            <span class="material-icons-outlined" style="font-size:1.125rem;">view_list</span>
        </button>
    </div>

    <style>
    /* ── Pill toggle ─────────────────────────────────────────────────────── */
    .view-toggle-wrap {
        position: relative;
        display: flex;
        align-items: center;
        background: var(--color-hover);
        border: 1px solid var(--color-border);
        border-radius: 0.75rem;
        padding: 3px;
        gap: 2px;
    }
    .view-toggle-pill {
        position: absolute;
        top: 3px;
        left: 3px;
        width: var(--pill-w, 34px);
        height: var(--pill-h, 34px);
        background: var(--accent-dim, #16a34a);
        border-radius: 0.5rem;
        box-shadow: 0 1px 4px rgba(0,0,0,0.25);
        transition: transform 0.26s cubic-bezier(0.34, 1.26, 0.64, 1);
        pointer-events: none;
        z-index: 0;
    }
    .view-toggle-btn {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: 0.5rem;
        border: none;
        background: transparent;
        cursor: pointer;
        color: var(--color-muted);
        transition: color 0.22s ease, background-color 0.18s ease;
    }
    .view-toggle-btn:not(.active):hover {
        background-color: rgba(255,255,255,0.07);
        color: var(--color-body-text);
    }
    .view-toggle-btn.active {
        color: #ffffff;
    }
    </style>

    {{-- New note --}}
    <form action="/notes" method="POST" class="inline flex-shrink-0" id="new-note-form"
          onsubmit="if(!navigator.onLine){event.preventDefault();window.openOfflineNewNote&&window.openOfflineNewNote();return false;}">
        @csrf
        {{-- Mobile: icon tròn nhỏ. Desktop sm+: có chữ --}}
        <button type="submit" id="btn-new-note"
                class="btn-primary !py-0 sm:px-4 sm:h-10"
                style="width:40px;height:40px;padding:0;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;"
                onmouseenter="if(window.innerWidth>=640){this.style.borderRadius='0.75rem';this.style.width='auto';this.style.padding='0 1rem';}"
                onmouseleave="if(window.innerWidth>=640){this.style.borderRadius='';this.style.width='';this.style.padding='';}">
            <span class="material-icons-outlined text-lg">add</span>
            <span class="hidden sm:inline ml-1">New Note</span>
        </button>
    </form>
</div>
@endsection

@section('content')
{{-- Label filter chips --}}
@if($labels->count() > 0)
<div class="flex flex-wrap gap-2 mb-6" id="label-chips">
    <a href="/notes" class="label-chip inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-all
        {{ !request('labels') ? 'bg-[#16a34a] text-white' : 'bg-hover text-muted hover:text-body border border-border' }}"
       data-label-id="">
        All Notes
    </a>
    @foreach($labels as $label)
    <a href="/notes?labels={{ $label->id }}"
       class="label-chip inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-all
        {{ request('labels') == $label->id ? 'text-white shadow-md' : 'bg-hover text-muted hover:text-body border border-border' }}"
       data-label-id="{{ $label->id }}"
       style="{{ request('labels') == $label->id ? 'background-color:'.$label->color : '' }}">
        <span class="w-2 h-2 rounded-full" style="background-color: {{ $label->color }}"></span>
        {{ $label->name }}
    </a>
    @endforeach
</div>
@endif

{{-- Notes container --}}
<div id="notes-container" class="{{ $preferences->view_mode === 'grid' ? 'grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-3 gap-4' : 'flex flex-col gap-2' }}">
    @forelse($notes as $note)
    @include('notes.partials.note-card', ['note' => $note, 'viewMode' => $preferences->view_mode])
    @empty
    <div class="col-span-full" id="empty-state">
        <div class="flex flex-col items-center justify-center py-20 text-center">
            <div class="w-24 h-24 rounded-3xl flex items-center justify-center mb-6" style="background:var(--accent-subtle);">
                <span class="material-icons-outlined text-5xl" style="color:var(--accent-dim);opacity:0.6;">note_add</span>
            </div>
            <h3 class="text-lg font-semibold mb-2">No notes yet</h3>
            <p class="text-muted text-sm mb-6">Create your first note to get started</p>
            <form action="/notes" method="POST"
                  onsubmit="if(!navigator.onLine){event.preventDefault();window.openOfflineNewNote&&window.openOfflineNewNote();return false;}">
                @csrf
                <button type="submit" class="btn-primary">
                    <span class="material-icons-outlined">add</span>
                    Create Note
                </button>
            </form>
        </div>
    </div>
    @endforelse
</div>

{{-- No results state (hidden by default, used by AJAX search) --}}
<div id="no-results-state" class="hidden col-span-full">
    <div class="flex flex-col items-center justify-center py-20 text-center">
        <div class="w-24 h-24 rounded-3xl bg-gradient-to-br from-slate-500/10 to-slate-500/10 flex items-center justify-center mb-6">
            <span class="material-icons-outlined text-5xl text-slate-500/50">search_off</span>
        </div>
        <h3 class="text-lg font-semibold mb-2">No notes found</h3>
        <p class="text-muted text-sm">Try a different search term</p>
    </div>
</div>

{{-- Modal + Swipe styles --}}
<style>
    /* Overlay fade */
    .modal-overlay {
        transition: opacity 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .modal-overlay.modal-hidden {
        opacity: 0;
        pointer-events: none;
    }
    /* Card scale+slide */
    .modal-box {
        transition: opacity 0.22s cubic-bezier(0.4, 0, 0.2, 1),
                    transform 0.28s cubic-bezier(0.34, 1.3, 0.64, 1);
    }
    .modal-overlay.modal-hidden .modal-box {
        opacity: 0;
        transform: scale(0.92) translateY(12px);
    }
    .modal-overlay:not(.modal-hidden) .modal-box {
        opacity: 1;
        transform: scale(1) translateY(0);
    }



    /* ─── Note card wrapper ──────────────────────────────────────────── */
    .note-card-wrapper {
        position: relative;
        border-radius: 0.875rem;
        transition: transform 0.22s cubic-bezier(0.34, 1.26, 0.64, 1), box-shadow 0.22s ease;
        transform-origin: center center;
        will-change: transform;
        /* Mobile: overflow:hidden để ẩn swipe-reveal */
        overflow: hidden;
        /* Fix iOS Safari: overflow:hidden + transform clipping */
        -webkit-transform: translateZ(0);
        isolation: isolate;
        /* Background bao phủ để khi card-inner scale down không lộ nội dung phía sau */
        background: var(--color-card);
    }
    /* Desktop: swipe-reveal đã display:none → không cần overflow:hidden.
       Nếu giữ hidden, click animation scale(0.97) trên card-inner sẽ lộ
       background wrapper (hoặc card phía sau) ở 4 biên */
    @media (min-width: 640px) {
        .note-card-wrapper {
            overflow: visible;
        }
    }
    /* Grid hover: zoom + lift — chỉ áp dụng khi có chuột thực (không phải touch) */
    @media (hover: hover) {
        #notes-container.grid .note-card-wrapper:hover {
            transform: scale(1.03) translateY(-2px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.18), 0 3px 10px rgba(0,0,0,0.1);
            z-index: 10;
        }
        /* List hover: zoom nhẹ hơn + lift */
        #notes-container:not(.grid) .note-card-wrapper:hover {
            transform: scale(1.015) translateY(-1px);
            box-shadow: 0 6px 24px rgba(0,0,0,0.14), 0 2px 8px rgba(0,0,0,0.08);
            z-index: 10;
        }
    }

    /* ─── Card inner body (full-clickable) ───────────────────────────── */
    .note-card-inner {
        position: relative;
        z-index: 1;
        width: 100%;
        height: 100%;
        cursor: pointer;
        touch-action: pan-y;
        /* Ngăn text selection toolbar trên mobile khi tap */
        user-select: none;
        -webkit-user-select: none;
        -webkit-tap-highlight-color: transparent;
    }
    .note-card-inner.swiping { touch-action: none; }

    /* Grid card */
    .note-card-grid {
        display: flex;
        flex-direction: column;
        padding: 1rem 1rem 0.875rem;
        min-height: 150px;
        background: var(--color-card);
        border-radius: 0.875rem;
        border: 1px solid var(--color-border);
        transition: border-color 0.2s ease, background 0.2s ease;
    }
    .note-card-grid:focus { outline: none; }
    .note-card-grid:focus-visible {
        outline: 2px solid var(--accent, #22c55e);
        outline-offset: -2px;
    }
    @media (hover: hover) {
        .note-card-wrapper:hover .note-card-grid {
            border-color: rgba(34,197,94,0.35);
            background: var(--color-card);
        }
    }

    /* List card — column layout so full-width image can sit below text row */
    .note-card-list {
        display: flex;
        flex-direction: column;
        padding: 0.875rem 1.125rem;
        min-height: 68px;
        background: var(--color-card);
        border-radius: 0.875rem;
        border: 1px solid var(--color-border);
        transition: border-color 0.2s ease, background 0.2s ease;
        gap: 0.5rem;
    }
    .note-card-list:focus { outline: none; }
    .note-card-list:focus-visible {
        outline: 2px solid var(--accent, #22c55e);
        outline-offset: -2px;
    }
    @media (hover: hover) {
        .note-card-wrapper:hover .note-card-list {
            border-color: rgba(34,197,94,0.35);
            background: var(--color-hover);
        }
    }

    /* ─── Hover action buttons ────────────────────────────────────────── */
    .note-hover-actions {
        position: absolute;
        top: 7px;
        right: 7px;
        display: flex;
        gap: 3px;
        z-index: 5;
        opacity: 0;
        transform: translateY(-3px) scale(0.9);
        transition: opacity 0.18s ease, transform 0.2s cubic-bezier(0.34,1.26,0.64,1);
        pointer-events: none;
    }
    /* Mobile: ẩn hẳn — đã có swipe gesture để pin/delete */
    @media (max-width: 639px) {
        .note-hover-actions {
            display: none !important;
        }
    }
    /* Desktop: hiện khi hover card */
    @media (min-width: 640px) and (hover: hover) {
        .note-card-wrapper:hover .note-hover-actions {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: auto;
        }
    }

    .note-hover-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        border: 1px solid rgba(255,255,255,0.08);
        background: var(--color-card);
        box-shadow: 0 2px 8px rgba(0,0,0,0.2), 0 0 0 1px rgba(0,0,0,0.06);
        cursor: pointer;
        transition: background 0.15s ease, transform 0.15s cubic-bezier(0.34,1.26,0.64,1), box-shadow 0.15s ease;
    }
    .note-hover-btn .material-icons-outlined {
        font-size: 0.875rem;
        color: var(--color-muted);
        transition: color 0.15s ease, transform 0.15s cubic-bezier(0.34,1.26,0.64,1);
        pointer-events: none;
    }
    .pin-hover-btn:hover {
        background: rgba(245,158,11,0.18);
        box-shadow: 0 2px 10px rgba(245,158,11,0.3);
        transform: scale(1.2);
    }
    .pin-hover-btn:hover .material-icons-outlined { color: #f59e0b; transform: rotate(-20deg); }
    .pin-hover-btn.is-pinned .material-icons-outlined { color: #f59e0b; }

    .delete-hover-btn:hover {
        background: rgba(239,68,68,0.15);
        box-shadow: 0 2px 10px rgba(239,68,68,0.25);
        transform: scale(1.2);
    }
    .delete-hover-btn:hover .material-icons-outlined { color: #ef4444; }

    /* ─── Typography ──────────────────────────────────────────────────── */
    .note-title {
        font-weight: 700;
        font-size: 0.875rem;
        line-height: 1.4;
        margin-bottom: 0.375rem;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        padding-right: 3.5rem;
        color: var(--color-body-text);
        letter-spacing: -0.01em;
    }
    @media (min-width:640px) { .note-title { font-size: 0.9375rem; } }
    /* Mobile: hover-actions ẩn → bỏ padding-right dư */
    @media (max-width:639px) { .note-title { padding-right: 0.25rem; } }

    /* ── Pin chip (mobile list card bottom row) ─────────────────────────── */
    .note-pin-chip {
        display: inline-flex;
        align-items: center;
        gap: 2px;
        padding: 1px 6px 1px 3px;
        border-radius: 9999px;
        background: rgba(245,158,11,0.15);
        border: 1px solid rgba(245,158,11,0.35);
        font-size: 9px;
        font-weight: 700;
        color: #f59e0b;
        letter-spacing: 0.03em;
        flex-shrink: 0;
    }
    .note-pin-chip .material-icons-outlined { font-size: 10px; color: #f59e0b; }

    .note-preview {
        font-size: 0.75rem;
        color: var(--color-muted);
        margin-bottom: 0.5rem;
        flex: 1;
        display: block;
        white-space: pre-line;
        overflow: hidden;
        line-height: 1.5;
        max-height: calc(1.5em * 4);
        opacity: 1;
    }
    @media (min-width:640px) { .note-preview { font-size: 0.8125rem; } }

    .note-footer {
        display: flex;
        align-items: center;
        padding-top: 0.4rem;
        border-top: 1px solid rgba(var(--color-border-rgb, 100,100,100), 0.25);
        margin-top: auto;
    }
    .note-time {
        font-size: 0.625rem;
        color: var(--color-muted);
        opacity: 0.7;
        font-variant-numeric: tabular-nums;
    }
    /* Timestamp always visible — no fade on hover */
    .note-time, .note-list-time {
        transition: none;
    }

    /* Label chips */
    .note-label-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 2px 7px;
        border-radius: 9999px;
        font-size: 9px;
        font-weight: 700;
        color: #fff;
        letter-spacing: 0.02em;
        text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        white-space: nowrap;
        max-width: 80px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Grid card labels: inline below title, always visible */
    .note-grid-labels {
        line-height: 1;
    }

    /* ─── Image grid preview (unified for grid + list) ──────────────────── */
    .note-img-grid {
        display: grid;
        gap: 2px;
        width: 100%;
        border-radius: 0.625rem;
        overflow: hidden;
        flex-shrink: 0;
        background: rgba(0,0,0,0.04);
        box-shadow: inset 0 0 0 1px rgba(0,0,0,0.06);
    }
    /* 1 ảnh: aspect-ratio 16/9 như cũ */
    .note-img-grid--1 {
        grid-template-columns: 1fr;
        aspect-ratio: 16 / 9;
    }
    /* 2 ảnh: 2 cột bằng nhau */
    .note-img-grid--2 {
        grid-template-columns: 1fr 1fr;
        aspect-ratio: 16 / 7;
    }
    /* 3 ảnh: ảnh đầu chiếm hàng đầy, 2 ảnh dưới chia đôi */
    .note-img-grid--3 {
        grid-template-columns: 1fr 1fr;
        grid-template-rows: auto auto;
        aspect-ratio: unset;
    }
    .note-img-grid--3 .note-img-cell:first-child {
        grid-column: 1 / -1;
        aspect-ratio: 16 / 7;
    }
    .note-img-grid--3 .note-img-cell:not(:first-child) {
        aspect-ratio: 1 / 1;
    }
    /* 4 ảnh: 2×2 */
    .note-img-grid--4 {
        grid-template-columns: 1fr 1fr;
        grid-template-rows: auto auto;
    }
    .note-img-grid--4 .note-img-cell {
        aspect-ratio: 1 / 1;
    }

    .note-img-cell {
        position: relative;
        overflow: hidden;
        background: rgba(0,0,0,0.06);
    }
    .note-img-thumb {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.35s cubic-bezier(0.25,0.46,0.45,0.94);
    }
    @media (hover: hover) {
        .note-card-wrapper:hover .note-img-thumb {
            transform: scale(1.04);
        }
    }
    /* Overlay "+N more" trên ảnh thứ 4 khi có > 4 ảnh */
    .note-img-cell--more::after {
        content: attr(data-more);
        position: absolute;
        inset: 0;
        background: rgba(0,0,0,0.52);
        color: #fff;
        font-size: 1.1rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        letter-spacing: 0.02em;
        backdrop-filter: blur(2px);
        -webkit-backdrop-filter: blur(2px);
    }

    /* ─── Swipe-to-action (mobile) ───────────────────────────────────── */
    .swipe-row { /* alias for note-card-wrapper */ }
    .swipe-reveal {
        position: absolute;
        inset-block: 0;
        width: 90px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 3px;
        color: #fff;
        opacity: 0;
        transform: scale(0.75);
        transition: opacity 0.12s, transform 0.12s;
        pointer-events: none;
        z-index: 0;
    }
    .swipe-reveal .material-icons-outlined { font-size: 1.6rem; }
    .swipe-reveal .swipe-label { font-size: 9px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
    .swipe-pin-reveal    { left: 0;  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border-radius: 0.75rem 0 0 0.75rem; }
    .swipe-delete-reveal { right: 0; background: linear-gradient(135deg, #f87171 0%, #ef4444 100%); border-radius: 0 0.75rem 0.75rem 0; }
    /* Desktop: ẩn hẳn swipe-reveal chỉ trên máy có chuột/hover; tablet và touch device vẫn dùng được */
    @media (min-width: 640px) and (hover: hover) {
        .swipe-reveal { display: none !important; }
    }
    .swipe-reveal.triggered {
        transform: scale(1.12) !important;
        transition: transform 0.08s cubic-bezier(0.34, 1.8, 0.64, 1);
    }
    .note-swipeable {
        position: relative;
        z-index: 1;
        width: 100%;
        touch-action: pan-y;
    }
    .note-swipeable.swiping { touch-action: none; }
</style>



@push('scripts')
<script>
// â”€â”€ IIFE: prevents let/const top-level re-declaration SyntaxError on AJAX nav re-execution.
// Without wrapping, every navigate-back aborts this script silently â†’ pin/delete stop working.
(function () {
    let currentLabels = '{{ request('labels') }}';
    window._currentLabels = currentLabels; // expose cho collapseSearch
    let searchTimeout = null;
    let deleteNoteId  = null;
    const MODAL_CLOSE_DELAY = 200;
    // Cache notes data hiện tại — dùng để re-render khi switch view mà không cần fetch lại
    window._notesCache = window._notesCache || null;

    // â”€â”€â”€ View Switch â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    window.switchView = function(mode) {
        const btnGrid   = document.getElementById('btn-grid');
        const btnList   = document.getElementById('btn-list');
        const pill      = document.getElementById('toggle-pill');
        const container = document.getElementById('notes-container');
        if (!btnGrid || !btnList || !pill || !container) return;

        if (mode === 'grid') {
            pill.style.transform = 'translateX(0px)';
            btnGrid.classList.add('active'); btnList.classList.remove('active');
            container.className = 'grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-3 gap-4';
        } else {
            pill.style.transform = 'translateX(calc(100% + 2px))';
            btnList.classList.add('active'); btnGrid.classList.remove('active');
            container.className = 'flex flex-col gap-2';
        }
        // Update per-card classes so CSS selectors (note-card-grid / note-card-list) apply correctly
        container.querySelectorAll('.note-card-inner').forEach(card => {
            if (mode === 'grid') {
                card.classList.add('note-card-grid');
                card.classList.remove('note-card-list');
            } else {
                card.classList.add('note-card-list');
                card.classList.remove('note-card-grid');
            }
        });
        fetch('/preferences/view-mode', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrfToken },
            body: JSON.stringify({ view_mode: mode })
        }).catch(() => {});

        // Re-render cards nếu đang search hoặc filter label — để thumbnail layout đúng
        // Dùng window._currentLabels (local var được expose đúng cách)
        const activeLabel = window._currentLabels || '';
        const activeSearch = document.getElementById('search-input')?.value?.trim() || '';
        if (activeSearch || activeLabel) {
            if (window.doSearch) {
                window.doSearch(activeSearch, activeLabel);
            }
        } else if (window._notesCache) {
            // Không có filter: re-render từ cache — thumbnail sẽ dùng isGrid mới
            if (window.renderNotes) window.renderNotes(window._notesCache);
        }
    };

    // Sync pill size via rAF (works on both first load and AJAX nav re-execution)
    requestAnimationFrame(() => {
        const btn  = document.getElementById('btn-grid');
        const pill = document.getElementById('toggle-pill');
        if (!btn || !pill) return;
        const sz = btn.getBoundingClientRect();
        pill.style.setProperty('--pill-w', sz.width  + 'px');
        pill.style.setProperty('--pill-h', sz.height + 'px');
        pill.style.width  = sz.width  + 'px';
        pill.style.height = sz.height + 'px';
    });

    // â”€â”€â”€ Live Search â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    const searchInput = document.getElementById('search-input');
    if (searchInput) {
        // ── Icon đổi màu khi focus / có text ──────────────────────────────
        const _iconEl = document.getElementById('search-icon');
        const _setIconColor = (active) => {
            if (!_iconEl) return;
            _iconEl.style.color     = active ? 'var(--accent-dim,#16a34a)' : 'var(--color-muted)';
            _iconEl.style.filter    = active ? 'drop-shadow(0 0 6px rgba(22,163,74,0.5))' : '';
            _iconEl.style.transform = active ? 'scale(1.15)' : 'scale(1)';
        };
        // Giữ màu green nếu đã có giá trị lúc load
        const _ht = document.getElementById('header-toolbar');
        const _syncHasSearch = () => {
            if (!_ht) return;
            searchInput.value.trim() ? _ht.classList.add('has-search') : _ht.classList.remove('has-search');
        };
        if (searchInput.value.trim()) { _setIconColor(true); _syncHasSearch(); }
        searchInput.addEventListener('focus', () => _setIconColor(true));
        searchInput.addEventListener('blur',  () => {
            _setIconColor(!!searchInput.value.trim());
            _syncHasSearch();
        });
        // Cập nhật màu + has-search mỗi lần gõ
        searchInput.addEventListener('input', () => {
            _setIconColor(!!searchInput.value.trim());
            _syncHasSearch();
        });

        if (searchInput._notesHandler) {
            searchInput.removeEventListener('input', searchInput._notesHandler);
        }
        searchInput._notesHandler = function () {
            clearTimeout(searchTimeout);
            const q = this.value.trim();
            const spinner = document.getElementById('search-spinner');
            // Nếu input trống → ẩn spinner ngay, không cần fetch
            if (!q) {
                if (spinner) spinner.style.display = 'none';
                window.doSearch('', currentLabels);
                return;
            }
            // Chỉ hiện spinner sau khi user nhập xong debounce 300ms
            searchTimeout = setTimeout(() => {
                if (spinner) spinner.style.display = 'inline-block';
                window.doSearch(q, currentLabels);
            }, 300);
        };
        searchInput.addEventListener('input', searchInput._notesHandler);
    }

    window.doSearch = function(query, labelId) {
        const params = new URLSearchParams();
        if (query)   params.set('search', query);
        if (labelId) params.set('labels', labelId);
        fetch('/notes?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(data => {
            const sp = document.getElementById('search-spinner');
            if (sp) sp.style.display = 'none';
            window.renderNotes(data.notes);
        })
        .catch(() => {
            const sp = document.getElementById('search-spinner');
            if (sp) sp.style.display = 'none';
        });
    };

    window.renderNotes = function(notes) {
        const container = document.getElementById('notes-container');
        const noResults = document.getElementById('no-results-state');
        if (!container) return;
        // Cache lại để re-render khi switch view
        window._notesCache = notes;
        if (notes.length === 0) {
            container.innerHTML = '';
            if (noResults) noResults.classList.remove('hidden');
            return;
        }
        if (noResults) noResults.classList.add('hidden');
        container.innerHTML = notes.map(note => window.buildNoteCard(note)).join('');
        // Re-init swipe gestures cho cards mới render
        if (typeof initSwipeGestures === 'function') initSwipeGestures();
    };

    // ── Áp dụng thay đổi label từ editor ngay khi quay về index ─────────────
    // Đọc cờ window._labelsChanged (được set bởi editor sau toggleLabel/removeLabel)
    // và cập nhật chip label trực tiếp trên card — không cần F5.
    function _applyPendingLabelChanges() {
        const change = window._labelsChanged;
        if (!change) return;
        window._labelsChanged = null; // clear cờ

        const card = document.getElementById(`note-card-${change.noteId}`);
        if (!card) return;

        const isGrid = card.classList.contains('note-card-grid');

        if (isGrid) {
            // Xóa label row cũ (nếu có)
            card.querySelector('.note-grid-labels')?.remove();
            if (change.labels.length > 0) {
                const labelsHtml = change.labels.slice(0, 3).map(l =>
                    `<span class="note-label-chip" style="background-color:${l.color}">${l.name}</span>`
                ).join('') + (change.labels.length > 3
                    ? `<span style="font-size:9px" class="text-muted self-center">+${change.labels.length - 3}</span>`
                    : '');
                const div = document.createElement('div');
                div.className = 'note-grid-labels flex flex-wrap gap-1 mt-1';
                div.innerHTML = labelsHtml;
                // Chèn sau pin badge hoặc sau h3
                const titleContainer = card.querySelector('.min-w-0.mb-0\\.5') || card.querySelector('h3')?.parentElement;
                if (titleContainer) titleContainer.appendChild(div);
            }
        } else {
            // List mode: label ở hàng bottom
            const labelsWrap = card.querySelector('.flex.flex-wrap.gap-1.flex-1.min-w-0');
            if (labelsWrap) {
                labelsWrap.innerHTML = change.labels.slice(0, 2).map(l =>
                    `<span class="note-label-chip" style="background-color:${l.color}">${l.name}</span>`
                ).join('') + (change.labels.length > 2
                    ? `<span style="font-size:9px" class="text-muted self-center">+${change.labels.length - 2}</span>`
                    : '');
            }
        }
    }
    // Chạy ngay khi index page được load (cả load đầu lẫn AJAX nav)
    _applyPendingLabelChanges();

    function esc(str) {
        if (!str) return '';
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }
    // Strip HTML but preserve newlines from block-level elements (p, div, br, li…)
    function stripHtml(str) {
        if (!str) return '';
        return str
            .replace(/<\/?(p|div|li|tr|br|h[1-6])[^>]*>/gi, '\n')
            .replace(/<[^>]+>/g, '')
            .replace(/&nbsp;/g, ' ')
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&quot;/g, '"')
            .trim();
    }

    window.buildNoteCard = function(note) {
        const isGrid    = document.getElementById('notes-container')?.classList.contains('grid');
        const borderTop = note.note_color && note.note_color !== 'none' ? `border-top:3px solid ${note.note_color};` : '';
        const hp        = note.has_password ? 'true' : 'false';
        const pinned    = note.is_pinned ? '1' : '0';

        // statusIcons: lock only
        const statusIcons = note.has_password ? `<span class="material-icons-outlined" style="font-size:13px;color:#ef4444;" title="Protected">lock</span>` : '';

        // Combined pinned + shared badges (inline, same row)
        const pinPart = note.is_pinned
            ? `<div class="flex items-center gap-0.5">
                <span class="material-icons-outlined" style="font-size:11px;color:#f59e0b;">push_pin</span>
                <span style="font-size:9px;font-weight:700;color:#f59e0b;letter-spacing:0.04em;">Pinned</span>
               </div>`
            : '';
        const sharePart = note.is_shared
            ? `<div class="flex items-center gap-0.5">
                <span class="material-icons-outlined" style="font-size:11px;color:#3b82f6;">share</span>
                <span style="font-size:9px;font-weight:700;color:#3b82f6;letter-spacing:0.04em;">Shared</span>
               </div>`
            : '';
        const badgesRow = (pinPart || sharePart)
            ? `<div class="flex items-center gap-2" style="margin-bottom:2px;">${pinPart}${sharePart}</div>`
            : '';

        // Labels: horizontal flex-wrap below title (grid), inline list (list view)
        const labelsGridHtml = note.labels.slice(0, 3).map(l =>
            `<span class="note-label-chip" style="background-color:${l.color}">${l.name}</span>`
        ).join('') + (note.labels.length > 3 ? `<span style="font-size:9px" class="text-muted self-center">+${note.labels.length - 3}</span>` : '');
        const labelsList = note.labels.slice(0, 2).map(l =>
            `<span class="note-label-chip" style="background-color:${l.color}">${l.name}</span>`
        ).join('');

        // hoverActions là SIBLING của card trong wrapper → click không bubble lên card
        const hoverActions = `
            <div class="note-hover-actions">
                <button type="button" class="note-hover-btn pin-hover-btn ${note.is_pinned ? 'is-pinned' : ''}"
                        onclick="window.togglePin(${note.id})"
                        title="${note.is_pinned ? 'Unpin' : 'Pin'}" aria-label="Toggle pin">
                    <span class="material-icons-outlined" style="${note.is_pinned ? 'color:#f59e0b' : ''}">push_pin</span>
                </button>
                <button type="button" class="note-hover-btn delete-hover-btn"
                        onclick="window.confirmDelete(${note.id},${hp})"
                        title="Delete note" aria-label="Delete note">
                    <span class="material-icons-outlined">delete</span>
                </button>
            </div>`;

        const swipeReveal = `
            <div class="swipe-reveal swipe-pin-reveal" aria-hidden="true">
                <span class="material-icons-outlined">push_pin</span>
                <span class="swipe-label">${note.is_pinned ? 'Unpin' : 'Pin'}</span>
            </div>
            <div class="swipe-reveal swipe-delete-reveal" aria-hidden="true">
                <span class="material-icons-outlined">delete</span>
                <span class="swipe-label">Delete</span>
            </div>`;

        // Image grid — hiển thị tối đa 4 ảnh, hidden for password-protected notes
        function buildImgGrid(note) {
            if (note.has_password) return '';
            const urls = (note.images_urls && note.images_urls.length > 0)
                ? note.images_urls
                : (note.first_image_url ? [note.first_image_url] : []);
            if (urls.length === 0) return '';
            const shown = urls.slice(0, 4);
            const extra = urls.length > 4 ? urls.length - 4 : 0;
            const cells = shown.map((url, i) => {
                const isMore = (i === 3 && extra > 0);
                return `<div class="note-img-cell${isMore ? ' note-img-cell--more' : ''}" data-more="${isMore ? '+'+extra : ''}">
                    <img src="${url}" alt="Attachment" class="note-img-thumb" loading="lazy">
                </div>`;
            }).join('');
            return `<div class="note-img-grid note-img-grid--${Math.min(shown.length, 4)} mt-2">${cells}</div>`;
        }
        const thumbGrid = buildImgGrid(note);

        if (isGrid) {
            return `
            <div class="note-card-wrapper swipe-row">
                ${swipeReveal}
                ${hoverActions}
                <div id="note-card-${note.id}" class="note-card-inner note-card-grid"
                     data-pinned="${pinned}" data-has-password="${hp}"
                     data-note-ts="${note.updated_at_ts || note.created_at_ts || note.id}"
                     data-pinned-at="${note.pinned_at_ts || 0}"
                     style="${borderTop}"
                     onclick="editNote(${note.id},${hp})" role="button" tabindex="0">
                    ${statusIcons ? `<div class="flex items-center gap-1 mb-1">${statusIcons}</div>` : ''}
                    <div class="min-w-0 mb-0.5">
                        <h3 class="note-title">${esc(note.title) || 'Untitled'}</h3>
                        ${badgesRow}
                        ${labelsGridHtml ? `<div class="note-grid-labels flex flex-wrap gap-1 mt-1">${labelsGridHtml}</div>` : ''}
                    </div>
                    ${note.has_password
                        ? `<p class="note-preview" style="font-style:italic;opacity:0.5;">🔒 Content is protected</p>`
                        : `<p class="note-preview">${esc(note.content)}</p>`}
                    ${thumbGrid}
                    <div class="note-footer"><span class="note-time">${note.updated_at}</span></div>
                </div>
            </div>`;
        } else {
            return `
            <div class="note-card-wrapper swipe-row">
                ${swipeReveal}
                ${hoverActions}
                <div id="note-card-${note.id}" class="note-card-inner note-card-list"
                     data-pinned="${pinned}" data-has-password="${hp}"
                     data-note-ts="${note.updated_at_ts || note.created_at_ts || note.id}"
                     data-pinned-at="${note.pinned_at_ts || 0}"
                     style="${borderTop}"
                     onclick="editNote(${note.id},${hp})" role="button" tabindex="0">
                    <div class="flex items-start gap-2 w-full min-w-0">
                        ${statusIcons ? `<div class="flex items-center gap-0.5 flex-shrink-0 mt-0.5">${statusIcons}</div>` : ''}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 min-w-0">
                                <h3 class="font-semibold text-sm truncate flex-1 min-w-0">${esc(note.title) || 'Untitled'}</h3>
                                <span class="text-xs text-muted whitespace-nowrap note-list-time flex-shrink-0" style="opacity:0.7;">${note.updated_at}</span>
                            </div>
                            ${badgesRow}
                            ${note.has_password
                                ? `<p class="text-xs text-muted" style="font-style:italic;opacity:0.5;">🔒 Content is protected</p>`
                                : `<p class="text-xs text-muted" style="overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;white-space:pre-line;">${esc(stripHtml(note.content))}</p>`}
                            ${labelsList ? `<div class="flex flex-wrap gap-1 mt-0.5">${labelsList}</div>` : ''}
                            ${thumbGrid}
                        </div>
                    </div>
                </div>
            </div>`;

        }

    };


    // â”€â”€â”€ Label Filter Chips â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    document.querySelectorAll('.label-chip').forEach(chip => {
        chip.addEventListener('click', function(e) {
            e.preventDefault();
            currentLabels = this.dataset.labelId;
            window._currentLabels = currentLabels; // sync để switchView đọc đúng
            document.querySelectorAll('.label-chip').forEach(c => {
                c.classList.remove('bg-[#16a34a]', 'text-white');
                c.classList.add('bg-hover', 'text-muted', 'border', 'border-border');
                c.style.backgroundColor = '';
            });
            this.classList.remove('bg-hover', 'text-muted', 'border', 'border-border');
            this.classList.add('bg-[#16a34a]', 'text-white');
            window.doSearch(document.getElementById('search-input')?.value || '', currentLabels);
        });
    });



    // â”€â”€â”€ Swipe-to-action gestures â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    function initSwipeGestures() {
        const container = document.getElementById('notes-container');
        if (!container || container._swipeInit) return;
        container._swipeInit = true; // guard: one handler per container

        const THRESHOLD = 80;
        let startX = 0, startY = 0;
        let activeCard = null, activeRow = null;
        let pinReveal = null, delReveal = null;
        let isHoriz = false, thresholdHit = false;

        container.addEventListener('touchstart', e => {
            const card = e.target.closest('.note-card-inner');
            if (!card) return;
            const row = card.closest('.swipe-row');
            if (!row) return;

            // Nếu đang có card khác trong lúc reset (animation revert), dừng nó lại trước
            if (activeCard && activeCard !== card) {
                resetSwipe(activeCard, activeRow);
            }

            activeCard   = card;
            activeRow    = row;
            pinReveal    = row.querySelector('.swipe-pin-reveal');
            delReveal    = row.querySelector('.swipe-delete-reveal');
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            isHoriz = false;
            thresholdHit = false;

            // Đảm bảo reveal đã về trạng thái ban đầu trước khi bắt đầu gesture mới
            if (pinReveal) { pinReveal.style.opacity = '0'; pinReveal.style.transform = 'scale(0.75)'; }
            if (delReveal) { delReveal.style.opacity = '0'; delReveal.style.transform = 'scale(0.75)'; }

            // Tắt transition sau 1 frame để không cắt ngang animation reset đang chạy
            requestAnimationFrame(() => {
                if (activeCard === card) activeCard.style.transition = 'none';
            });
        }, { passive: true });

        container.addEventListener('touchmove', e => {
            if (!activeCard) return;
            const dx = e.touches[0].clientX - startX;
            const dy = e.touches[0].clientY - startY;

            if (!isHoriz) {
                if (Math.abs(dy) > Math.abs(dx) + 6) { activeCard = null; return; }
                if (Math.abs(dx) < 8) return;
                isHoriz = true;
                activeCard.classList.add('swiping');
            }
            e.preventDefault();

            const max     = THRESHOLD * 1.4;
            const clamped = Math.max(-max, Math.min(max, dx));
            activeCard.style.transform = `translateX(${clamped}px)`;

            const progress = Math.min(Math.abs(clamped) / THRESHOLD, 1);
            const scale    = 0.7 + 0.3 * progress;

            if (dx > 0) {
                if (pinReveal) { pinReveal.style.opacity = progress; pinReveal.style.transform = `scale(${scale})`; }
                if (delReveal) { delReveal.style.opacity = '0'; }
            } else {
                if (delReveal) { delReveal.style.opacity = progress; delReveal.style.transform = `scale(${scale})`; }
                if (pinReveal) { pinReveal.style.opacity = '0'; }
            }

            if (Math.abs(dx) >= THRESHOLD && !thresholdHit) {
                thresholdHit = true;
                (dx > 0 ? pinReveal : delReveal)?.classList.add('triggered');
                if (navigator.vibrate) navigator.vibrate(10);
            } else if (Math.abs(dx) < THRESHOLD && thresholdHit) {
                thresholdHit = false;
                pinReveal?.classList.remove('triggered');
                delReveal?.classList.remove('triggered');
            }
        }, { passive: false });

        const resetSwipe = (card, row) => {
            if (!card) return;
            card.style.transition = 'transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
            card.style.transform  = '';
            card.classList.remove('swiping');
            row?.querySelectorAll('.swipe-reveal').forEach(el => {
                el.style.opacity   = '0';
                el.style.transform = 'scale(0.75)';
                el.classList.remove('triggered');
            });
        };

        container.addEventListener('touchend', e => {
            if (!activeCard || !isHoriz) { activeCard = null; return; }
            const dx          = e.changedTouches[0].clientX - startX;
            const card        = activeCard;
            const row         = activeRow;
            const noteId      = parseInt(card.id.replace('note-card-', ''), 10);
            const hasPassword = card.dataset.hasPassword === 'true';

            resetSwipe(card, row);
            activeCard = null;

            if (dx >= THRESHOLD) {
                setTimeout(() => window.togglePin(noteId), 180);
            } else if (dx <= -THRESHOLD) {
                setTimeout(() => window.confirmDelete(noteId, hasPassword), 180);
            }
        });

        container.addEventListener('touchcancel', () => {
            resetSwipe(activeCard, activeRow);
            activeCard = null;
        });
    }
    initSwipeGestures();

    // â”€â”€â”€ Modal helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    window.openModal = function(id) {
        const el = document.getElementById(id);
        if (!el) return;
        // Teleport to <body> to escape #page-content's will-change:transform
        // which creates a new containing block and breaks position:fixed centering
        if (el.parentElement !== document.body) {
            document.body.appendChild(el);
        }
        el.style.display = 'flex';
        el.getBoundingClientRect();
        el.classList.remove('modal-hidden');
    };
    window.closeModal = function(id, cb) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.add('modal-hidden');
        setTimeout(() => { el.style.display = 'none'; if (cb) cb(); }, MODAL_CLOSE_DELAY);
    };

    // ─── Password modal ───────────────────────────────────────────────────
    window.requirePassword = function(noteId, action) {
        const noteIdEl = document.getElementById('unlock-note-id');
        const actionEl = document.getElementById('unlock-action');
        const pwEl     = document.getElementById('unlock-password');
        const errEl    = document.getElementById('unlock-error');
        // Always reset full state so stale noteId/password/type from previous note are cleared
        if (noteIdEl) noteIdEl.value = noteId;
        if (actionEl) actionEl.value = action;
        if (pwEl) {
            pwEl.value = '';
            pwEl.type  = 'password'; // reset eye-toggle — togglePassVis may have left it as 'text'
        }
        if (errEl)    errEl.classList.add('hidden');
        // Reset eye icon if present
        const eyeIcon = document.getElementById('unlock-pw-eye');
        if (eyeIcon) eyeIcon.textContent = 'visibility';
        window.openModal('password-modal');
        setTimeout(() => { if (pwEl) pwEl.focus(); }, 80);
    };
    window.closePasswordModal = function() {
        // Fully reset form so Cancel leaves no stale state for next open
        const pwEl  = document.getElementById('unlock-password');
        const errEl = document.getElementById('unlock-error');
        if (pwEl)  { pwEl.value = ''; pwEl.type = 'password'; }
        if (errEl) errEl.classList.add('hidden');
        const eyeIcon = document.getElementById('unlock-pw-eye');
        if (eyeIcon) eyeIcon.textContent = 'visibility';
        window.closeModal('password-modal');
    };
    window.unlockNote = async function(e) {
        e.preventDefault();
        const noteIdRaw = document.getElementById('unlock-note-id')?.value;
        const noteId   = /^\d+$/.test(noteIdRaw) ? parseInt(noteIdRaw, 10) : noteIdRaw;
        const password = document.getElementById('unlock-password')?.value;
        const action   = document.getElementById('unlock-action')?.value;
        const errEl    = document.getElementById('unlock-error');
        // Guard: empty password bypasses HTML required because e.preventDefault() is called first
        if (!password || !password.trim()) {
            if (errEl) { errEl.textContent = 'Password is required.'; errEl.classList.remove('hidden'); }
            return;
        }
        // Guard: missing noteId means stale/broken state — do not send request
        if (!noteId) {
            if (errEl) { errEl.textContent = 'Invalid note. Please try again.'; errEl.classList.remove('hidden'); }
            return;
        }
        try {
            if (navigator.onLine) {
                // Online: verify via server API
                await apiCall(`/notes/${noteId}/unlock`, 'POST', { password });
            } else {
                // Offline: verify locally using bcrypt hash from IDB
                const note = window.getNotesFromIDB
                    ? (await window.getNotesFromIDB()).find(n => String(n.id) === String(noteId))
                    : null;
                if (!note || !note.note_password) throw { error: 'Note not available offline.' };
                const match = window.bcryptCompareSync
                    ? window.bcryptCompareSync(password, note.note_password)
                    : false;
                if (!match) throw { error: 'Incorrect password.' };
                // Mark as unlocked for this session (in-memory + sessionStorage for offline shell)
                window._offlineUnlocked = window._offlineUnlocked || new Set();
                window._offlineUnlocked.add(String(noteId));
                try { sessionStorage.setItem('offline_unlocked_' + noteId, '1'); } catch(_) {}
            }
            window.closePasswordModal();
            if (action === 'edit') {
                if (!navigator.onLine) {
                    if (window.offlineRouter) {
                        window.offlineRouter.navigateToNote(noteId);
                    } else {
                        location.href = `/notes/${noteId}/edit`;
                    }
                } else if (window.ajaxNav) {
                    window.ajaxNav(`/notes/${noteId}/edit`);
                } else {
                    location.href = `/notes/${noteId}/edit`;
                }
            } else if (action === 'delete') {
                window.showDeleteModal(noteId);
            }
        } catch (err) {
            if (errEl) { errEl.textContent = err.error || 'Incorrect password'; errEl.classList.remove('hidden'); }
        }
    };

    // ─── Toggle show/hide password ───────────────────────────────────────────────────
    window.togglePassVis = function(inputId, iconId) {
        const inp  = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (!inp) return;
        if (inp.type === 'password') {
            inp.type = 'text';
            if (icon) icon.textContent = 'visibility_off';
        } else {
            inp.type = 'password';
            if (icon) icon.textContent = 'visibility';
        }
    };

    // ─── Delete modal ───────────────────────────────────────────────────────────
    // Dùng position:fixed nhưng clamp vào bounds của <main> để chỉ che vùng note display
    function _teleportDeleteModal() {
        const modal = document.getElementById('delete-modal');
        if (!modal) return;
        const mainEl = document.querySelector('#app-layout main');
        if (!mainEl) return;

        // Tính bounds của <main> để xác định vùng che
        const rect = mainEl.getBoundingClientRect();
        modal.style.position = 'fixed';
        modal.style.top      = '0';
        modal.style.left     = rect.left + 'px';
        modal.style.width    = rect.width + 'px';
        modal.style.right    = '0';
        modal.style.bottom   = '0';
        modal.style.height   = '100vh';

        // Teleport lên body để tránh bị clip bởi overflow/transform cha
        if (!document.body.contains(modal) || modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    }

    window.showDeleteModal = function(noteId) {
        deleteNoteId = noteId;
        _teleportDeleteModal();
        window.openModal('delete-modal');
        // cloneNode removes all old event listeners on the button reliably
        const oldBtn = document.getElementById('confirm-delete-btn');
        if (!oldBtn) return;
        const newBtn = oldBtn.cloneNode(true);
        oldBtn.parentNode.replaceChild(newBtn, oldBtn);
        newBtn.addEventListener('click', async () => {
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
                window.closeModal('delete-modal', () => {
                    showToast('Note deleted successfully');
                    const _cardEl = document.getElementById(`note-card-${noteId}`);
                    const _wrapEl = _cardEl ? (_cardEl.closest('.note-card-wrapper') || _cardEl.closest('.swipe-row')) : null;
                    (_wrapEl || _cardEl)?.remove();
                    const c = document.getElementById('notes-container');
                    if (c && !c.querySelector('[id^="note-card-"]')) {
                        document.getElementById('no-results-state')?.classList.remove('hidden');
                    }
                });
            } catch (err) {
                showToast(err.error || 'Failed to delete', 'error');
            }
        });
    };
    window.closeDeleteModal = function() { window.closeModal('delete-modal'); };
    window.confirmDelete = function(noteId, hasPassword) {
        if (hasPassword) window.requirePassword(noteId, 'delete');
        else             window.showDeleteModal(noteId);
    };

    // --- Edit note ---
    window.editNote = function(noteId, hasPassword) {
        if (hasPassword) { window.requirePassword(noteId, 'edit'); return; }
        const card = document.getElementById('note-card-' + noteId);
        if (card) {
            const target = window.innerWidth >= 640
                ? (card.closest('.note-card-wrapper') || card)
                : card;
            target.style.transition = 'transform 0.12s ease, opacity 0.12s ease';
            target.style.transform  = 'scale(0.97)';
            target.style.opacity    = '0.7';
        }
        const editUrl = '/notes/'+noteId+'/edit';
        setTimeout(() => {
            // When offline, use the client-side router to render the offline editor
            // (fixes "Note not found" bug — no full page reload needed)
            if (!navigator.onLine) {
                if (window.offlineRouter) {
                    window.offlineRouter.navigateToNote(noteId);
                } else {
                    location.href = editUrl;
                }
            } else if (window.ajaxNav) {
                window.ajaxNav(editUrl);
            } else {
                location.href = editUrl;
            }
        }, 100);
    };

    // Helper: cập nhật pin badge bên dưới title sau khi togglePin
    function _updatePinBadge(card, isPinned) {
        let badge = card.querySelector('.pin-badge-below-title');
        if (isPinned) {
            if (!badge) {
                const titleEl = card.querySelector('h3');
                if (!titleEl) return;
                badge = document.createElement('div');
                badge.className = 'pin-badge-below-title flex items-center gap-0.5';
                badge.style.marginBottom = '2px';
                badge.innerHTML =
                    '<span class="material-icons-outlined" style="font-size:11px;color:#f59e0b;">push_pin</span>' +
                    '<span style="font-size:9px;font-weight:700;color:#f59e0b;letter-spacing:0.04em;">Pinned</span>';
                const h3Parent = titleEl.parentElement;
                if (h3Parent && h3Parent.classList.contains('flex-1')) {
                    titleEl.after(badge);
                } else {
                    h3Parent ? h3Parent.after(badge) : titleEl.after(badge);
                }
            }
        } else {
            badge?.remove();
        }
    }

    // --- Toggle Pin -------------------------------------------------------
    if (!window._pinInFlight) window._pinInFlight = new Set();
    window.togglePin = async function(noteId) {
        if (window._pinInFlight.has(noteId)) return;
        window._pinInFlight.add(noteId);

        const card    = document.getElementById(`note-card-${noteId}`);
        if (!card) { window._pinInFlight.delete(noteId); return; }

        const wrapper = card.closest('.note-card-wrapper');
        const pinBtn  = wrapper ? wrapper.querySelector('.pin-hover-btn') : null;
        const pinIcon = pinBtn  ? pinBtn.querySelector('.material-icons-outlined') : null;
        const swipeLabel = wrapper ? wrapper.querySelector('.swipe-pin-reveal .swipe-label') : null;

        const wasPinned = card.dataset.pinned === '1';

        // Optimistic UI
        card.dataset.pinned = wasPinned ? '0' : '1';
        if (pinBtn)  { pinBtn.classList.toggle('is-pinned', !wasPinned); pinBtn.title = wasPinned ? 'Pin' : 'Unpin'; }
        if (pinIcon) { pinIcon.style.color = wasPinned ? '' : '#f59e0b'; }
        if (swipeLabel) swipeLabel.textContent = wasPinned ? 'Pin' : 'Unpin';
        _updatePinBadge(card, !wasPinned);

        try {
            const result   = await apiCall(`/notes/${noteId}/toggle-pin`, 'POST');
            const nowPinned = result.is_pinned === true;

            card.dataset.pinned = nowPinned ? '1' : '0';
            card.dataset.pinnedAt = nowPinned ? (result.pinned_at_ts || Math.floor(Date.now() / 1000)) : '0';
            if (pinBtn)  { pinBtn.classList.toggle('is-pinned', nowPinned); pinBtn.title = nowPinned ? 'Unpin' : 'Pin'; }
            if (pinIcon) { pinIcon.style.color = nowPinned ? '#f59e0b' : ''; }
            if (swipeLabel) swipeLabel.textContent = nowPinned ? 'Unpin' : 'Pin';

            // Sync pin state back into _notesCache so switchView re-renders correctly
            if (window._notesCache) {
                const cached = window._notesCache.find(n => String(n.id) === String(noteId));
                if (cached) {
                    cached.is_pinned = nowPinned;
                    cached.pinned_at_ts = nowPinned ? (result.pinned_at_ts || Math.floor(Date.now() / 1000)) : 0;
                }
                window._notesCache.sort((a, b) => {
                    const aPin = a.is_pinned ? 1 : 0;
                    const bPin = b.is_pinned ? 1 : 0;
                    if (bPin !== aPin) return bPin - aPin;
                    if (aPin === 1) return (b.pinned_at_ts || 0) - (a.pinned_at_ts || 0);
                    return (b.updated_at_ts || 0) - (a.updated_at_ts || 0);
                });
            }

            // Re-render this card fully so pin badge renders correctly in both grid and list views.
            // _updatePinBadge only handles .pin-badge-below-title; cards built by buildNoteCard
            // render the pin badge inside badgesRow — a different DOM structure that _updatePinBadge
            // cannot reach. A full re-render is the only reliable fix.
            if (window.buildNoteCard && window._notesCache) {
                const noteData = window._notesCache.find(n => String(n.id) === String(noteId));
                if (noteData) {
                    const oldWrapper = card.closest('.note-card-wrapper') || card.closest('.swipe-row');
                    if (oldWrapper) {
                        oldWrapper.insertAdjacentHTML('afterend', window.buildNoteCard(noteData));
                        oldWrapper.remove();
                    }
                }
            }

            showToast(nowPinned ? 'Note pinned' : 'Note unpinned');

            const container = document.getElementById('notes-container');
            if (container) {
                const wrappers = Array.from(container.children).filter(el =>
                    el.classList.contains('note-card-wrapper') || el.classList.contains('swipe-row')
                );
                wrappers.sort((a, b) => {
                    const aCard = a.querySelector('[data-pinned]');
                    const bCard = b.querySelector('[data-pinned]');
                    const aPin = Number(aCard?.dataset.pinned ?? 0);
                    const bPin = Number(bCard?.dataset.pinned ?? 0);
                    if (bPin !== aPin) return bPin - aPin;
                    if (aPin === 1) {
                        const aPinnedAt = Number(aCard?.dataset.pinnedAt ?? 0);
                        const bPinnedAt = Number(bCard?.dataset.pinnedAt ?? 0);
                        return bPinnedAt - aPinnedAt;
                    }
                    const aTs = Number(aCard?.dataset.noteTs ?? 0);
                    const bTs = Number(bCard?.dataset.noteTs ?? 0);
                    return bTs - aTs;
                });
                wrappers.forEach(w => container.appendChild(w));
            }
        } catch (err) {
            card.dataset.pinned = wasPinned ? '1' : '0';
            if (pinBtn)  { pinBtn.classList.toggle('is-pinned', wasPinned); pinBtn.title = wasPinned ? 'Unpin' : 'Pin'; }
            if (pinIcon) { pinIcon.style.color = wasPinned ? '#f59e0b' : ''; }
            _updatePinBadge(card, wasPinned);
            showToast((err && err.error) || 'Failed to toggle pin', 'error');
        } finally {
            window._pinInFlight.delete(noteId);
        }
    };

    })();


    // â”€â”€â”€ Session: password_required redirect â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    @if(session('password_required'))
        window.requirePassword({{ session('password_required') }}, 'edit');
    @endif

    // ─── Offline-First Architecture ─────────────────────────────────────────────
    // IDB is the single source of truth for the UI.
    // Server is a sync target, not primary data source.
    // Flow: render IDB → sync pending → fetch server → merge → re-render
    (async function loadNotesOfflineFirst() {
        @php
        $notesOfflineData = $notes->map(function($n) {
            return [
                'id'              => $n->id,
                'title'           => $n->title ?? '',
                'content'         => $n->content ?? '',
                'note_color'      => $n->note_color ?? 'none',
                'is_pinned'       => (bool) $n->is_pinned,
                'pinned_at_ts'    => $n->pinned_at?->timestamp ?? 0,
                'has_password'    => (bool) $n->has_password,
                'note_password'   => $n->note_password ?? null, // bcrypt hash for offline verify
                'is_shared'       => $n->shares->count() > 0,
                'labels'          => $n->labels->map(fn($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values()->toArray(),
                'first_image_url' => $n->images->count() > 0 ? asset('storage/' . $n->images->first()->image_path) : null,
                'images_urls'     => $n->images->map(fn($img) => asset('storage/' . $img->image_path))->values()->toArray(),
                'updated_at'      => $n->updated_at?->diffForHumans() ?? '',
                'updated_at_ts'   => $n->updated_at?->timestamp ?? 0,
                'created_at_ts'   => $n->created_at?->timestamp ?? 0,
            ];
        })->values();
        $labelsOfflineData = $labels->map(fn($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values();
        $prefsOfflineData  = ['font_size' => $preferences->font_size, 'note_color' => $preferences->note_color, 'theme' => $preferences->theme, 'view_mode' => $preferences->view_mode];
        @endphp
        const serverNotesData = @json($notesOfflineData);
        const labelsData      = @json($labelsOfflineData);
        const prefsData       = @json($prefsOfflineData);

        // ── STEP 1: Render from IDB immediately (no waiting for network) ─────
        // CHỈ render từ IDB khi OFFLINE — khi online, server data (serverNotesData)
        // luôn có images_urls đầy đủ và là nguồn chính xác hơn IDB có thể cũ.
        let idbNotes = [];
        if (window.getNotesFromIDB) {
            try { idbNotes = await window.getNotesFromIDB(); } catch(e) {}
        }
        if (!navigator.onLine && idbNotes.length > 0) {
            // Offline: render từ IDB (nguồn duy nhất khi không có mạng)
            window.renderNotes && window.renderNotes(idbNotes);
        } else if (navigator.onLine && serverNotesData.length > 0) {
            // Online: render ngay từ server data (fresh, có images_urls) —
            // tránh flash "1 ảnh" do IDB cũ chưa có images_urls
            window.renderNotes && window.renderNotes(serverNotesData);
        } else if (idbNotes.length > 0) {
            // Fallback: IDB có data, server data rỗng
            window.renderNotes && window.renderNotes(idbNotes);
        }
        // If both were empty, the server-rendered Blade cards are already visible.

        // ── STEP 2: Online → sync pending + merge fresh data ─────────────────
        if (navigator.onLine) {
            // 2a. Sync any pending offline changes to server first
            if (window.syncAllPending) {
                try {
                    const syncResult = await window.syncAllPending(window.csrfToken);
                    if (syncResult.created > 0 || syncResult.updated > 0) {
                        console.log('[Offline-First] Synced:', syncResult);
                    }
                } catch(e) {}
            }

            // 2b. Merge server data into IDB (non-destructive, preserves local-only notes)
            if (window.mergeServerNotesIntoIDB) {
                try { await window.mergeServerNotesIntoIDB(serverNotesData); } catch(e) {}
            }
            if (window.saveLabelsToIDB) {
                try { await window.saveLabelsToIDB(labelsData); } catch(e) {}
            }
            if (window.savePreferencesToIDB) {
                try { await window.savePreferencesToIDB(prefsData); } catch(e) {}
            }

            // 2c. Re-render từ IDB đã merge — chỉ cần nếu có pending_create/update cần hiện
            // (server data đã render ở Step 1, chỉ re-render nếu IDB có thêm local-only notes)
            if (window.getNotesFromIDB) {
                try {
                    const freshNotes = await window.getNotesFromIDB();
                    const hasLocalOnly = freshNotes.some(n => n.syncStatus === 'pending_create');
                    if (hasLocalOnly) {
                        window.renderNotes && window.renderNotes(freshNotes);
                    }
                } catch(e) {}
            }
        } else {
            // ── STEP 3: Offline → show banner, enable offline features ─────────
            let offlineLabels = [];
            if (window.getLabelsFromIDB) {
                try { offlineLabels = await window.getLabelsFromIDB(); } catch(e) {}
            }

            // Count pending items for banner
            let pendingCount = 0;
            if (window.getPendingSyncCount) {
                try { pendingCount = await window.getPendingSyncCount(); } catch(e) {}
            }

            // ── Offline banner ────────────────────────────────────────────────
            const banner = document.createElement('div');
            banner.id = 'offline-banner';
            banner.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;padding:10px 22px;border-radius:999px;font-size:13px;font-weight:600;z-index:1000;box-shadow:0 4px 20px rgba(0,0,0,0.25);white-space:nowrap;display:flex;align-items:center;gap:8px;';
            banner.innerHTML = `<span class="material-icons-outlined" style="font-size:16px;">wifi_off</span>
                Offline${pendingCount > 0 ? ` — ${pendingCount} change${pendingCount > 1 ? 's' : ''} pending sync` : ' — Showing cached notes'}`;
            document.body.appendChild(banner);

            // ── Offline label filter chips ─────────────────────────────────────
            if (offlineLabels.length > 0) {
                const chipsEl = document.getElementById('label-chips');
                if (!chipsEl) {
                    const chipsWrap = document.createElement('div');
                    chipsWrap.className = 'flex flex-wrap gap-2 mb-6';
                    chipsWrap.id = 'label-chips';
                    const all = document.createElement('span');
                    all.className = 'label-chip inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer bg-[#16a34a] text-white';
                    all.textContent = 'All Notes';
                    all.dataset.labelId = '';
                    chipsWrap.appendChild(all);
                    offlineLabels.forEach(label => {
                        const chip = document.createElement('span');
                        chip.className = 'label-chip inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer bg-hover text-muted border border-border';
                        chip.dataset.labelId = label.id;
                        chip.innerHTML = `<span class="w-2 h-2 rounded-full" style="background-color:${label.color}"></span>${label.name}`;
                        chipsWrap.appendChild(chip);
                    });
                    const container = document.getElementById('notes-container');
                    container?.parentElement?.insertBefore(chipsWrap, container);
                }
            }

            // ── Offline search: filter notes from IDB cache client-side ─────
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                if (searchInput._notesHandler) {
                    searchInput.removeEventListener('input', searchInput._notesHandler);
                }
                searchInput._notesHandler = function() {
                    const q = this.value.trim().toLowerCase();
                    const activeLabelId = window._offlineActiveLabelId || '';
                    let filtered = idbNotes;
                    if (q) {
                        filtered = filtered.filter(n =>
                            (n.title || '').toLowerCase().includes(q) ||
                            (n.content || '').toLowerCase().includes(q)
                        );
                    }
                    if (activeLabelId) {
                        filtered = filtered.filter(n =>
                            (n.labels || []).some(l => String(l.id) === String(activeLabelId))
                        );
                    }
                    window.renderNotes && window.renderNotes(filtered);
                };
                searchInput.addEventListener('input', searchInput._notesHandler);
            }

            // ── Offline label filter: click chip → filter IDB cache ──────────
            document.addEventListener('click', function _offlineLabelClick(e) {
                const chip = e.target.closest('.label-chip');
                if (!chip) return;
                e.preventDefault();
                const labelId = chip.dataset.labelId || '';
                window._offlineActiveLabelId = labelId;

                document.querySelectorAll('.label-chip').forEach(c => {
                    c.classList.remove('bg-[#16a34a]', 'text-white', 'shadow-md');
                    c.classList.add('bg-hover', 'text-muted', 'border', 'border-border');
                    c.style.backgroundColor = '';
                });
                chip.classList.remove('bg-hover', 'text-muted', 'border', 'border-border');
                chip.classList.add('bg-[#16a34a]', 'text-white');

                let filtered = idbNotes;
                const searchQ = document.getElementById('search-input')?.value?.trim().toLowerCase() || '';
                if (searchQ) {
                    filtered = filtered.filter(n =>
                        (n.title || '').toLowerCase().includes(searchQ) ||
                        (n.content || '').toLowerCase().includes(searchQ)
                    );
                }
                if (labelId) {
                    filtered = filtered.filter(n =>
                        (n.labels || []).some(l => String(l.id) === String(labelId))
                    );
                }
                window.renderNotes && window.renderNotes(filtered);
            });
        }

        // ── Offline "New Note" — always works, online or offline ──────────────
        window.openOfflineNewNote = async function() {
            if (!window.queueCreate) return;

            const tempId = 'temp_' + Date.now();
            const newNote = {
                title:         '',
                content:       '',
                note_color:    'none',
                labels:        [],
                created_at_ts: Math.floor(Date.now() / 1000),
            };

            await window.queueCreate(tempId, newNote);

            // Navigate to the offline editor for the new note
            if (window.offlineRouter) {
                window.offlineRouter.navigateToNote(tempId);
            } else {
                // Fallback: re-render list from IDB
                if (window.getNotesFromIDB) {
                    const refreshed = await window.getNotesFromIDB();
                    window.renderNotes && window.renderNotes(refreshed);
                }
            }

            showToast('Note created offline — will sync when online', 'info');
        };

        // ── Reconnect handler: sync + reload ─────────────────────────────────
        window.addEventListener('online', async () => {
            showToast('Back online — syncing changes...', 'info');
            document.getElementById('offline-banner')?.remove();
            // Sync pending then reload to get fresh server data
            if (window.syncAllPending) {
                try { await window.syncAllPending(window.csrfToken); } catch(e) {}
            }
            setTimeout(() => location.reload(), 1200);
        });
        window.addEventListener('offline', () => {
            showToast('You are offline — changes will be saved locally', 'error');
        });
    })();

</script>
@endpush
@endsection

